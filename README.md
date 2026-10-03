# Forklift

**B2B and wholesale for Craft Commerce 5.** Company accounts with buyers and roles, spend
approvals, contract price lists, request-a-quote with pay-by-link, net terms and credit limits,
quick order and CSV upload, and tax exemption certificates.

WooCommerce has B2BKing, Wholesale Suite and WooCommerce B2B. Craft has *Commerce Bulk Pricing*
and core catalog pricing rules — and nothing that covers the rest. Craft's customer base skews
harder to manufacturers and distributors than Woo's does, so this is both the biggest gap in the
ecosystem and the best fit for it.

- **Lite — free.** Company accounts, buyers, roles and spend limits, company order history, the
  buyer portal, purchase-order numbers at checkout, tax exemption certificates, the quick-order
  pad and reorder.
- **Pro — $149, $129/year renewal.** Contract price lists with quantity breaks, spend approval
  workflows, net terms with credit limits and statements, quotes with pay-by-link, CSV order
  upload.

Requires Craft CMS 5.3+, Craft Commerce 5.3+, PHP 8.2+.

---

## What it does

### Company accounts, buyers and roles

A **Company** is a Craft element, so it gets a field layout of your own — rep, region, delivery
instructions, whatever your accounts actually differ by — plus search, relations, the trash and an
element index with custom sources.

People are attached to a company as **members**, and the role is a property of the *membership*,
not the person: the same buyer can be an administrator of one account and a viewer on another,
which is what happens when a buying group and one of its members both trade with you.

Four roles, deliberately not a permission matrix:

| Role | Can |
| --- | --- |
| **Administrator** | Runs the account. Adds and removes buyers, sets their limits, approves anything, sees the statement. |
| **Approver** | Approves other people's orders. Does not administer the account. |
| **Buyer** | Places orders, up to their spend limit. The default. |
| **Viewer** | Sees the company's orders and quotes and can reorder into a basket, but cannot place an order. |

A **spend limit** is per order and belongs to the member, so "Rita can spend £20,000 and Sam £500"
needs no new role. Blank means no limit of their own; **zero** means they cannot place an order
unassisted.

An account can be **on hold** — which stops new orders and nothing else. Existing orders, quotes,
invoices and statements are untouched. That is what a credit controller does at four o'clock on a
Friday, and undoes on Monday.

### Contract price lists

Craft's catalog pricing rules price *by condition*. What they cannot express is the thing
wholesale actually runs on: a negotiated sheet of prices belonging to one named customer, with
quantity breaks, that somebody imported from a spreadsheet and will export back to one.

So a price list is a **document**. It has a name, an owner (some companies, or every company),
dates it is good between, and a body of entries. Four targets, from most specific to least — this
variant, this product, this product type, everything — and three ways to say a price:

- **Fixed** — a negotiated number that does not move when the list price does.
- **Percentage off** and **amount off** — these track the list price, so a price rise reaches trade
  customers automatically instead of quietly eroding your margin on eleven thousand rows nobody
  remembered to re-import.

`minQty` turns any of them into a quantity break. Several entries on the same product with
different quantities are a break table.

Where two lists both price something, the higher **priority** wins — but only for the lines it
actually mentions. A customer contract at priority 10 does not stop a trade-wide list at 0 pricing
everything else, which is exactly how "everyone gets 15% off, Acme gets a sheet of negotiated
lines" is meant to behave.

Two safety rails, both settings, both defaulting to the safe direction:

- A contract price **above** the list price is refused, because a spreadsheet imported with two
  columns swapped otherwise charges a trade customer more than the public pays, silently, on every
  line. The control panel says which entry it ignored and why.
- A public **promotion beats** a contract price when it is lower, because a customer shown a sale
  at a better number and then charged their contract rate has been overcharged as far as they are
  concerned.

### Spend approvals

Three ways for an order to need signing off: the company's threshold, the buyer's own spend limit,
or a flag on the buyer. The *reason* is recorded when the request is raised and never recomputed —
your threshold can change the next morning, and an audit trail that changes with the settings is
not an audit trail.

Approvers decide from a link in an email **without signing in**, because the people who sign off
spend in a purchasing department very often have no login at all, and a workflow that requires one
is a workflow that gets bypassed by forwarding the email.

The basket stays live between the request and the decision, so an approval records the amount it
covered. An approval for £400 does not authorise a £4,400 basket; a basket that has *shrunk* needs
no re-approval.

### Net terms, credit limits and statements

A **Purchase Order** gateway that extends Commerce's own Manual gateway and authorises rather than
purchases — so the order completes, the customer gets their confirmation, and `totalPaid` stays at
zero, which is true, because nobody has paid yet. Capture the transaction when the cheque arrives
and Commerce marks it paid with its own machinery. Every report, paid-status filter and
integration in your store already understands that.

The gateway offers itself only when the account is set up for terms, is not on hold, and would not
go past its credit limit. Being over the limit withdraws *the gateway*, not the checkout — a
customer holding out a credit card should be allowed to use it.

The ledger is append-only and signed: a charge is positive, a payment negative, and the balance is
a `SUM` and nothing else. No running total on the company row, because a cached balance is a number
that can be wrong and the moment it goes wrong is the moment somebody is on the telephone.

Statements age the account into the buckets every accounts-receivable ledger uses, allocating
payments oldest-charge-first. Nothing about the allocation is stored, so a backdated payment
re-ages the account correctly instead of leaving fossilised allocations behind.

### Request a quote, then pay by link

This is [craftcms/commerce#1156](https://github.com/craftcms/commerce/issues/1156) — "merchant
edits an order, customer pays a link" — which Commerce has open and no plugin closes.

1. The buyer asks. Their basket is snapshotted onto a **Quote** element, priced as they saw it.
2. A merchant opens it, changes quantities and prices, adds lines, adds delivery, sets an expiry.
3. **Send.** Forklift builds a real, incomplete Commerce cart carrying exactly those prices, and
   the buyer gets a signed link that loads it into their own session.
4. From that click it is an ordinary Commerce checkout — every gateway you have, including paying
   on terms.

The prices survive because Commerce recalculates that cart constantly and every recalculation
re-derives the quoted numbers rather than reverting them.

### Quick order, CSV upload and reorder

A wholesale buyer does not browse. They have a list of part numbers on a sheet of paper, in a
spreadsheet, or in last month's order.

All three routes produce the same result object, so a SKU that cannot be found reads identically
however it arrived — and **nothing is ever silently dropped**. Every row comes back carrying the
text the buyer typed and, when it failed, a sentence saying why and the line number it was on. A
pad that quietly adds a hundred and eighty-three of two hundred lines is worse than one that
refuses the file, because the buyer finds out at the loading bay.

Reordering **re-prices at today's rates** rather than copying the old numbers, and says which lines
changed. A contract that has been renegotiated should not be quietly ignored.

### Tax exemption certificates

Certificates are held per company, with a jurisdiction and an expiry, and tax is only removed for
one that is **approved by a human**, unexpired, and **covers the delivery address**. A Texas resale
certificate does not exempt a delivery to Ohio.

The mechanism is a subclass of Commerce's own tax adjuster, not a replacement: rates that add tax
are dropped, and rates whose tax is included in the price are transformed so that Commerce's own
out-of-zone branch strips them. Every line of tax arithmetic stays Commerce's, so a fix there
reaches exempt orders the same day it reaches everybody else's.

---

## Installing

```sh
composer require justinholtweb/craft-forklift
php craft plugin/install forklift
```

Five default payment terms are created on install (Due on receipt, Net 14, Net 30, Net 60,
2/10 Net 30). Nothing else changes until you create a company.

---

## Templating

Everything a front end needs is on `craft.forklift`, and nothing on it writes — a template asks
questions, a controller makes changes.

```twig
{% set price = craft.forklift.price(variant, 12) %}

<p class="price">{{ price.price|commerceCurrency(cart.currency) }}</p>

{% if price.isContractPrice %}
    <p class="was">
        {{ 'List {price}'|t(app, { price: price.listPrice|commerceCurrency(cart.currency) }) }}
        — {{ price.sourceLabel }}
    </p>
{% endif %}

{% if price.nextBreak %}
    <p class="nudge">
        Buy {{ price.nextBreak.qty }} or more and they're
        {{ price.nextBreak.price|commerceCurrency(cart.currency) }} each.
    </p>
{% endif %}
```

The checkout button asks the same verdict the gate reads, so what it says is what pressing it does:

```twig
{% set verdict = craft.forklift.verdict() %}

{% for message in verdict.blockingMessages %}
    <p class="error">{{ message }}</p>
{% endfor %}

{% if verdict.needsApproval %}
    <form method="post">
        {{ csrfInput() }}
        <input type="hidden" name="action" value="forklift/portal/submit-for-approval">
        <button>{{ verdict.actionLabel }}</button>
    </form>
{% else %}
    <button {{ not verdict.isAllowed ? 'disabled' }}>{{ verdict.actionLabel }}</button>
{% endif %}
```

The full surface is in [docs/templating.md](docs/templating.md).

### The portal is yours

Every portal screen renders `_forklift/portal/<name>.twig` from **your** templates if it exists,
and falls back to a plain one Forklift ships. A B2B portal has to look like the shop it is part of.

---

## Console

```sh
php craft forklift/companies                       # the accounts, their status and buyer counts
php craft forklift/companies/create "Acme Ltd" ACME
php craft forklift/companies/add-buyer ACME rita@acme.test --role=admin
php craft forklift/companies/hold ACME             # and /release

php craft forklift/price-lists                     # lists, priorities and row counts
php craft forklift/price-lists/import prices.csv --list=acme --dryRun
php craft forklift/price-lists/resolve-skus        # after a catalogue rebuild
php craft forklift/price-lists/price FIX-M8-100 ACME 250

php craft forklift/credit                          # balances, available credit, overdue
php craft forklift/credit/statement ACME
php craft forklift/credit/payments remittances.csv
php craft forklift/credit/repair                   # raise any charge that went missing

php craft forklift/maintenance/run                 # expire approvals and quotes, re-age certificates
php craft forklift/maintenance/doctor              # what is quietly not working
```

`doctor` is the one to reach for first. Every check in it is something that produces no error and
no log line but silently stops Forklift doing what you think it is doing: an account with an
approval threshold and nobody able to approve, a price list whose SKUs do not exist in your store,
an order that completed without the approval it needed because a licence had lapsed.

---

## Editions and downgrades

**A lapsed licence never breaks a store.** Pro configuration that survives a downgrade is
*ignored, not obeyed*, and three of those choices are worth stating because they are not
symmetric:

- **Price lists stop applying**, so buyers pay list price. That errs towards not under-charging.
- **The purchase-order gateway withdraws**, so no new credit can be opened. Existing invoices are
  untouched. Fails closed.
- **Approvals stop blocking checkout.** A store that cannot check out at all is worse than one that
  lets an order through, so the order is placed and flagged `approvalBypassed`. This is the one
  place Forklift fails open, and it says so on the order, in the approvals screen and in `doctor`
  rather than hiding it.

---

## Architecture, briefly

Two invariants, and everything else is arrangement:

1. **`services\Pricing::resolve()` is the only place a B2B price is computed.** The cart, the
   catalog templates, the quick-order pad, the CSV upload, the reorder screen, the quote builder
   and the control panel's price preview all read the `PriceResult` it returns, so a price a buyer
   is shown cannot disagree with the price they are charged.
2. **`services\Checkout::verdict()` is the only place an order's B2B eligibility is decided.** The
   front-end button, the before-complete gate, the gateway's availability check and the control
   panel all read the same verdict, so what the button says is what checkout does.

Five hooks into Commerce and no more: `LineItems::EVENT_POPULATE_LINE_ITEM`,
`Order::EVENT_BEFORE_COMPLETE_ORDER`, `Order::EVENT_AFTER_COMPLETE_ORDER`,
`Taxes::EVENT_REGISTER_TAX_ENGINE` and `Gateways::EVENT_REGISTER_GATEWAY_TYPES`. The tax engine is
only swapped when Commerce's own is in place — a store running Avalara or TaxJar keeps theirs.

---

## Testing

Two suites, both run inside the plugin's own test site:

```sh
php /path/to/craft-forklift/tests/integration/checks.php       # 143 checks
php /path/to/craft-forklift/tests/integration/http-checks.php  #  37 checks
```

The second exists because two of the bugs found building this were unreachable from a console
script and would have shipped: a service calling a method that had never been written, and a
controller pushing a string into a `Response::setContent()` that does not exist. A suite that only
calls services keeps missing that class of mistake.

Both are idempotent and self-cleaning.

---

## Documentation

- [Installation](docs/installation.md)
- [Configuration](docs/configuration.md)
- [Usage](docs/usage.md)
- [Templating](docs/templating.md)
- [FAQ](docs/faq.md)
- [Troubleshooting](docs/troubleshooting.md)

## Licence

The Craft License. See [LICENSE.md](LICENSE.md). Lite is free; Pro is licensed per production
install through the Craft Plugin Store.
