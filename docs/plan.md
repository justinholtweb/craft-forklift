# Forklift — build plan

**B2B and wholesale for Craft Commerce 5.** `justinholtweb/craft-forklift`, handle `forklift`,
namespace `justinholtweb\forklift`. Lite (free) + **Pro $149, $129/year renewal**.

## Why this plugin exists

WooCommerce has B2BKing, Wholesale Suite and WooCommerce B2B. Craft has *Commerce Bulk Pricing*
and core catalog pricing rules, and nothing else. Everything below is uncovered on Craft today:

| Gap | Forklift |
| --- | --- |
| Company accounts with several buyers, roles, spend approval | `Company` element, members with roles and per-order spend limits, an approval queue |
| Net terms / purchase-order payment, credit limits, statements | `PurchaseOrder` gateway, a credit ledger, aged statements |
| Request a quote → merchant prices it → pay by link (craftcms/commerce#1156) | `Quote` element, a CP pricing screen, a tokenised load-cart link |
| Customer-specific price lists / contract pricing | Price lists with SKU / product / product-type / catch-all entries and quantity breaks |
| Quick order pad, CSV upload, reorder at contract prices | One controller over one resolver |
| Tax exemption certificates | Certificates per company, a tax engine that honours them |

Craft's customer base skews harder to manufacturers and distributors than Woo's does, so this is
both the biggest gap and the best fit.

## The two invariants

Everything else is arrangement. These two are the architecture.

1. **`services\Pricing::resolve()` is the only place a B2B price is computed.** The cart, the
   catalog templates, the quick-order pad, the CSV upload, the reorder screen, the quote builder
   and the CP price-list preview all read the `PriceResult` it returns, so a price a buyer is
   shown can never disagree with the price they are charged. Resolution order is quote pin →
   contract price list → Commerce's own price, and the result carries *which* of those won.

2. **`services\Checkout::verdict()` is the only place an order's B2B eligibility is decided.** The
   front-end button state, the `EVENT_BEFORE_COMPLETE_ORDER` gate, the payment-gateway
   availability check and the CP order panel all read the same `CheckoutVerdict` — so what the
   button says is what checkout does. The verdict is a list of *reasons*, never a bare boolean:
   company on hold, buyer over their per-order spend limit, order needs approval, company over its
   credit limit, certificate expired.

A third rule, not an invariant but close: **`services\Credit::balanceFor()` is the only place a
company's balance is derived**, always by summing the ledger, never by keeping a running total in
a column that can drift.

## Data model

Element-backed:

- `forklift_companies` — the account. A `Company` element, so it gets a field layout (agencies
  always want their own fields on an account), search, relations, the trash and an element index.
- `forklift_quotes` + `forklift_quotelines` — a `Quote` element with statuses.

Plain tables:

- `forklift_members` — user ↔ company, role, per-order spend limit, `requiresApproval`
- `forklift_pricelists`, `forklift_pricelist_entries`, `forklift_pricelist_companies`
- `forklift_approvals` — one row per order that entered the approval queue
- `forklift_creditentries` — the ledger: charge, payment, adjustment
- `forklift_certificates` — tax exemption certificates, with jurisdiction and expiry
- `forklift_terms` — named payment terms (Net 30, 2/10 Net 30)
- `forklift_orders` — the B2B facts about a Commerce order: company, member, PO number, terms,
  due date, the approval it went through, and whether an approval was bypassed by a downgrade

Hard deletes, database rather than project config — the same call Commerce makes for its own
shipping methods and discounts, and for the same reason: these are operational records that
differ per environment, not schema.

## Commerce integration points

Five, and no more. Each is the documented extension point for the job:

| Hook | Used for |
| --- | --- |
| `LineItems::EVENT_POPULATE_LINE_ITEM` | contract and quote-pinned prices, applied *after* Commerce has set its own, so the snapshot still records the list price |
| `Order::EVENT_BEFORE_COMPLETE_ORDER` | the checkout gate — a cancelled event is the only thing that reliably stops an order completing |
| `Taxes::EVENT_REGISTER_TAX_ENGINE` | swap in a tax adjuster that honours exemption certificates |
| `Gateways::EVENT_REGISTER_GATEWAY_TYPES` | the purchase-order / net-terms gateway |
| `Elements::EVENT_AFTER_SAVE_ELEMENT` (Order) | persist the `forklift_orders` row |

## Tax exemption, concretely

Commerce's tax adjuster computes `$zoneMatches` from the rate, then removes *included* tax when
the zone does not match. So the exemption is expressed as a rate transformation rather than as a
special case inside the adjuster:

- rates that add tax (`include = false`) are dropped, so no tax is added;
- rates whose tax is *included in the price* are kept, cloned as an `ExemptTaxRate` whose
  `getIsEverywhere()` is false and whose `getTaxZone()` is null, with `removeIncluded` forced on —
  which is precisely the "zone does not match" branch Commerce already has, so included tax is
  stripped from the price by Commerce's own arithmetic and not by ours.

## Editions

**Lite is a working company-accounts plugin, not a demo.** A distributor with one price list in
their head and twelve trade customers should get real value free.

Lite: companies, members and roles, company order history, PO number capture, tax exemption
certificates, the quick-order pad and reorder at list prices.

Pro: contract price lists, spend approvals, net terms with credit limits and statements, quotes
with pay-by-link, CSV order upload.

**Pro configuration that survives a downgrade is ignored, not obeyed** — four `getEffective*()`
gates and a `getHasSuppressedProSettings()` driving CP warnings. Two of those choices are worth
stating because they are not symmetric:

- A lapsed licence stops **price lists** applying, so buyers pay list price. That errs towards the
  merchant not under-charging.
- A lapsed licence stops **approvals blocking checkout**, because a store that cannot check out at
  all is worse than one that lets an order through. The order is still placed, but it is flagged
  `approvalBypassed` and the CP says so on the order and in a site-wide warning. This is the one
  place Forklift fails open, and it is documented as such rather than hidden.
- The **PO gateway becomes unavailable** on a downgrade, which fails closed and is safe: nobody
  can open new credit, and existing invoices are untouched.

## Screens

CP: Companies (element index + edit with members, price lists, credit, certificates), Approvals
queue with a badge, Quotes (element index + a pricing screen), Price lists, Terms, Statements,
Settings.

Front end: quick-order pad partial, CSV upload, the buyer's company portal (users, orders,
quotes, statement, credit), request-a-quote action, approve/decline actions.

## Build order

1. Skeleton, settings, editions, migration, records
2. Companies + members + roles + the portal
3. Pricing: price lists and `Pricing::resolve()`, wired to `EVENT_POPULATE_LINE_ITEM`
4. Checkout: `CheckoutVerdict`, approvals, the before-complete gate
5. Credit: ledger, terms, the PO gateway, statements
6. Quotes: request, price, send, pay-by-link, accept
7. Quick order: lookup, pad, CSV, reorder
8. Tax exemption certificates and the tax engine
9. Console commands, Twig variable, docs
10. `tests/integration/checks.php` against the shared harness
