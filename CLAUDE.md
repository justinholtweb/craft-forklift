# Forklift — Craft CMS 5 Plugin

## Project Overview

Forklift is B2B and wholesale for Craft Commerce 5: company accounts with buyers and roles, spend
approvals, contract price lists, request-a-quote with pay-by-link, net terms and credit limits,
quick order and CSV upload, and tax exemption certificates. Distributed as
`justinholtweb/craft-forklift`. **Lite (free) + Pro $149, $129/year renewal.**

The gap it fills: WooCommerce has B2BKing, Wholesale Suite and WooCommerce B2B; Craft has
*Commerce Bulk Pricing* and core catalog pricing rules and nothing else. Craft's customer base
skews harder to manufacturers and distributors than Woo's does.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, **Craft Commerce 5.3+**, Yii2, Twig
- No build step: no asset bundles, no JavaScript beyond Craft's own

## Architecture

### Namespace & package

- Namespace: `justinholtweb\forklift`
- Package: `justinholtweb/craft-forklift`
- Handle: `forklift`

### The two invariants

1. **`services\Pricing::resolve()` is the only place a B2B price is computed.** The cart, the
   catalog templates, the quick-order pad, the CSV upload, the reorder screen, the quote builder
   and the CP price preview all read the `PriceResult` it returns. Resolution order is quote pin →
   contract price list → Commerce's own price, and the result carries *which* of those won plus
   the next quantity break and any reason a contract price was suppressed.

2. **`services\Checkout::verdict()` is the only place an order's B2B eligibility is decided.** The
   front-end button, the `EVENT_BEFORE_COMPLETE_ORDER` gate, the gateway availability check and
   the CP order panel all read the same `CheckoutVerdict`. It is a list of *reasons*, never a
   bare boolean, and it memoizes on the order number **and its total** — memoizing on the number
   alone is the mistake Commerce's own shipping-rule cache makes.

A third rule, not an invariant but close: **`services\Credit::balanceFor()` derives a balance by
summing the ledger, always.** There is a hygiene check in the suite asserting no service ever
assigns to a balance property.

### Which price-list entry wins

Two questions in this order, and the order is the design:

- **Which list?** The highest `priority` that has *any* entry matching this purchasable; ties break
  on id so the answer is stable. The list is the contract. So a customer list at priority 10 beats
  a trade-wide list at 0 — but only for the lines it mentions; everything else falls through.
- **Which entry within it?** Most specific target first (variant → product → product type →
  everything), then the highest quantity break at or below the quantity.

### Tax exemption, concretely

Commerce's `adjusters\Tax` computes `$zoneMatches` and, when a rate's zone does *not* match, takes
an existing branch that strips included tax. That branch *is* an exemption, so Forklift expresses
the exemption as a rate transformation rather than as a special case in a forked adjuster:

- rates that add tax (`include = false`) are filtered out, so none is added;
- rates whose tax is included are cloned as `ExemptTaxRate`, whose `getIsEverywhere()` is false and
  whose `getTaxZone()` is null, with `removeIncluded` forced on.

`adjusters\ExemptTax` extends Commerce's adjuster and overrides only the `protected getTaxRates()`.
Roughly twenty lines instead of a copy of Commerce's four hundred.

### Data model

Element-backed: `forklift_companies`, `forklift_quotes` (+ `forklift_quotelines`).

Plain tables: `forklift_members`, `forklift_pricelists`, `forklift_pricelist_entries`,
`forklift_pricelist_companies`, `forklift_approvals`, `forklift_creditentries`,
`forklift_certificates`, `forklift_terms`, `forklift_orders`.

Hard deletes, database rather than project config — the same call Commerce makes for its own
shipping methods and discounts. FKs cascade for rows that only exist because of a parent, and
**null the reference** for rows that record something that happened: deleting a price list does
not erase the invoice it priced, and deleting an order does not erase the payment that settled it.

### The five hooks into Commerce, and no more

| Hook | Used for |
| --- | --- |
| `LineItems::EVENT_POPULATE_LINE_ITEM` | contract and quoted prices, applied *after* Commerce sets its own so the snapshot keeps the list price |
| `Order::EVENT_BEFORE_COMPLETE_ORDER` | the checkout gate |
| `Order::EVENT_AFTER_COMPLETE_ORDER` | raise the invoice, close the quote, stamp the exemption |
| `Taxes::EVENT_REGISTER_TAX_ENGINE` | swap the adjuster — **only over Commerce's own engine**, so Avalara/TaxJar stores keep theirs |
| `Gateways::EVENT_REGISTER_GATEWAY_TYPES` | the purchase-order gateway |

Plus `Elements::EVENT_AFTER_SAVE_ELEMENT` filtered to orders, typed `ElementEvent` — a handler
typed `ModelEvent` there fatals on *every element save in the system*.

### Pay-by-link

`Quotes::materialise()` builds a real incomplete Commerce cart and hands the buyer Commerce's own
signed `commerce/cart/load-cart` URL. The ordering is load-bearing: the cart is **saved empty
first**, its Forklift row written with `quoteId`, and only then are line items added — adding them
first would populate them before anything knew the cart came from a quote, and the first save
would write list prices.

### Editions

`models\Edition` is pure and static, taking `$isPro`. Lite is a working company-accounts plugin,
not a demo: companies, members and roles, order history, PO numbers, tax certificates, the pad and
reorder — **uncapped**. Pro is the money half.

**A downgrade ignores Pro configuration rather than obeying it**, with one deliberate asymmetry:
approvals stop *blocking* checkout, because a store that cannot check out is worse than one that
lets an order through. Those orders are flagged `approvalBypassed` and named in the CP and in
`doctor`. Price lists and the PO gateway both fail closed.

## Traps found while building this

- **Craft refuses to create a session when the request has no `User-Agent`.**
  `web\User::beforeLogin()` → `_validateUserAgentAndIp()`, and `requireUserAgentAndIpForSession`
  is on by default. PHP's curl sends no UA unless told to, so *every* login — password or
  impersonation — fails with a bare redirect to the login page and nothing in the log. Set
  `CURLOPT_USERAGENT`. Command-line curl sends one, which is why the same flow works in bash and
  not in PHP.
- **Craft resolves `/actions/foo/bar` in the path before it looks at the `action` body param**
  (`Request::_checkIfActionRequestInternal()`), so `formaction="{{ actionUrl('…') }}"` on a submit
  button reliably overrides a form's hidden `action` input. That is the right way to give a
  full-page CP form a second verb — a nested `<form>` there is invalid HTML whose children survive,
  which is how Save ends up running Delete.
- **`Response::setContent()` does not exist.** Yii's `Response` has a public `$content` property.
  Render a template in another mode with `Controller::renderTemplate($template, $vars, $mode)`
  rather than swapping the view mode by hand and pushing a string — the by-hand version also skips
  the response formatter, so nothing sets a content type.
- **`Craft::$app->getSession()` throws in a console request** (`MissingComponentException`), it does
  not return null. Any service that reads or writes the session and is also reachable from a queue
  job or a console command needs a guard.
- **A memoized element must be refreshed on save, not merely dropped.** `Checkout` asks
  `Companies::getCompanyById()` on every recalculation; a credit controller putting an account on
  hold would otherwise be read from a copy loaded earlier in the same request, and the gate would
  use the old answer for the rest of it.
- **`Element::sortOptions()` normalises `defineSortOptions()` into a list of arrays**, so its keys
  are `0, 1, 2` and the sort lives in `orderBy`. Iterating `array_keys()` produces `ORDER BY 0`,
  which MySQL rejects.
- **`hasContent()` is not a Craft 5 element method.** Declaring it is harmless and useless.
- **`Order::setShippingAddress()` refuses an `Address` element the order does not own**, but takes
  an array happily. Its ownership check compares two nulls, which is what makes a transient order
  carrying only a destination possible.
- **`LineItem::getPrice()` is not the list price** once Forklift has replaced it — that is the whole
  point. A quote recording `listPrice` from it would report every saving as zero. Ask
  `Pricing::listPriceFor()`.
- **`Purchasable::$inventoryTracked` is a public property, not a getter**, and a plugin's own
  purchasable type need not have it at all.
- **Commerce regenerates catalog prices in a queue job**, so a test that changes a base price and
  reads it straight back is testing the queue, not your arithmetic. Use two fixtures at different
  prices instead.
- **`StringHelper::UUID()` is 36 characters**, not 32 — `randomString(32)` for a `char(32)` token
  column.
- **PHP identifiers may contain bytes 0x80–0xFF**, so `"“$name”"` parses as a variable called
  `name”` and fatals at *runtime*. Brace interpolation next to typographic quotes; there is a check
  in the suite for it.
- **Yii skips an inline validator when the attribute is empty** (`skipOnEmpty` defaults true), which
  is exactly when "these two fields have to agree" has something to say. Pass
  `'skipOnEmpty' => false`.
- **Project config writes are buffered** and a bare console script never reaches Craft's
  after-request flush. `ProjectConfig::flush()` is the pair; `saveModifiedConfigData()` alone
  writes the table and not the YAML.

See also `[[craft-plugin-gotchas]]` and `[[craft-commerce-shipping-gotchas]]` in the shared memory
for family-wide traps.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container, and `ddev exec` is
unreliable there — use `docker exec`:

```sh
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-forklift/tests/integration/checks.php
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-forklift/tests/integration/http-checks.php
docker run --rm -v "$PWD:/app" -w /app php:8.2-cli bash -c 'find src tests -name "*.php" -print0 | xargs -0 -n1 php -l'
```

`checks.php` (143) exercises services, and switches to Pro in memory for the bulk while testing
the Lite boundary in its own section — **never persisted**, because project config is contended on
that harness. `http-checks.php` (37) drives the real routes, signing in with a one-hour
impersonation token rather than a password so nothing here can be broken by another session
changing it. It asserts against whatever edition is installed rather than switching it.

Both are idempotent and self-cleaning.

## Coding conventions

- `Craft::t('forklift', '…')` for user-facing strings; `src/translations/en/forklift.php` lists them
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template
- Never mark plugin settings `required`
- A blank number field means "not set", which is different from zero — a spend limit of zero means
  "may not order unassisted", an approval threshold of zero means "every order", and a credit limit
  of zero means "no orders on account" while null means no limit at all
- A null relation param means "nobody touched this" and must leave the existing set alone; an
  absent form field must never unassign every customer from a price list
- Anything that can throw during a checkout or a cart recalculation is caught and logged, and fails
  in the direction that does not lose the merchant money
