# Changelog

All notable changes to Forklift are documented here.

## 5.1.0 — 2026-10-05

> {warning} This release fixes the checkout gate. Until now an order Forklift should have refused — an account on hold, an approval outstanding, over a spend limit, a missing PO number — was **charged and then left uncompleted**: the refusal ran after the payment and failed with a PHP error rather than stopping it. If your store has seen "Completing order failed" or 500s at checkout, look for carts with a successful payment that never became orders. Orders are now refused before any payment is taken.

### Fixed

- **The checkout gate charged orders it should have refused.** It was hooked to `Order::EVENT_BEFORE_COMPLETE_ORDER`, which Commerce does not let a plugin cancel — it is triggered with a plain event, so setting `isValid` threw `UnknownPropertyException` — and order completion runs after the payment. The gate now vetoes `Payments::EVENT_BEFORE_PROCESS_PAYMENT`, before anything is charged, with the reasons on the order as notices. An order completed without a payment is still refused at completion, now with the reasons rather than a PHP error.
- **Buyers couldn't switch account.** The front-end account switcher (`forklift/companies/switch`, used by the bundled portal) sat behind the control panel's "View companies" permission, so every buyer who belonged to more than one company got a 403. It now needs only a signed-in member of the company being switched to.

### Security

- **The quick-order pad, SKU lookup and autocomplete exposed products a visitor shouldn't see.** They are open to visitors who have not signed in, on purpose, but read `commerce_purchasables` directly — so anyone could enumerate disabled, unreleased, expired and trashed SKUs with their descriptions and prices, and `suggest?q=%%` returned every SKU in the store. They now find only what the storefront would show on the current site — an enabled variant of a live product, no drafts — and anything else is "not found", worded exactly like a SKU that does not exist. `lookup` no longer quotes a price for something that is not for sale, and `suggest` leaves it out. A `%`, `_` or `\` in the search is matched literally.

### Added

- **Events**, so a module can react to or veto what Forklift does with money: `Approvals::EVENT_BEFORE_REQUEST`/`AFTER_REQUEST` and `EVENT_BEFORE_DECIDE`/`AFTER_DECIDE`; `Quotes::EVENT_BEFORE_SEND`/`AFTER_SEND`, `EVENT_AFTER_ACCEPT` and `EVENT_BEFORE_DECLINE`/`AFTER_DECLINE`; `Credit::EVENT_BEFORE_SAVE_ENTRY`/`AFTER_SAVE_ENTRY` and `EVENT_BEFORE_DELETE_ENTRY`/`AFTER_DELETE_ENTRY`; and `Pricing::EVENT_DEFINE_PRICE` to change any price Forklift resolves. Every `before…` event is cancelable. See the new Events page.

### Changed

- PHPStan and ECS configuration, and the findings they raised tidied.

## 5.0.0 — 2026-08-27

Initial release.

### Added

- **Company accounts** as a Craft element, with a field layout of your own, an element index with
  status and credit sources, account codes, and an on-hold state that stops new orders without
  touching anything else.
- **Buyers, roles and spend limits.** Four roles — administrator, approver, buyer, viewer — held
  per membership rather than per person, with per-order spend limits and an always-needs-approval
  flag. A company can never be left with no administrator.
- **Contract price lists** with fixed, percentage-off and amount-off entries; four target levels
  (variant, product, product type, everything); quantity breaks; date ranges; per-company or
  trade-wide assignment; priority with a stable tiebreak. CSV import and export, both in the
  control panel and on the command line.
- **A price preview** in the control panel that runs the real resolver and explains which list,
  which entry and which break produced the number — and why a contract price was ignored when it
  was.
- **Spend approvals**: company thresholds, per-buyer limits, an approval queue with a control-panel
  badge, decisions by tokenised email link with no sign-in, expiry, and re-approval when an
  approved basket grows.
- **Net terms** as named payment terms including early-settlement discounts (`2/10 Net 30`), a
  **Purchase Order gateway** that authorises rather than purchases, **credit limits** enforced at
  checkout, an append-only signed **credit ledger**, and **aged statements** in the control panel,
  the buyer portal and CSV.
- **Request a quote** as a Quote element: request from a basket, price it in the control panel,
  send it, and give the buyer a signed pay-by-link that loads the quoted basket into their own
  session for an ordinary Commerce checkout. Quoted prices survive every recalculation.
- **Quick order pad, CSV upload and reorder**, all producing the same result shape, with per-row
  errors and line numbers, a dry-run preview, and reordering that re-prices at today's rates and
  says what changed.
- **Tax exemption certificates** per company with jurisdiction and expiry, human approval, and a
  tax adjuster that subclasses Commerce's own so the arithmetic stays Commerce's.
- **A buyer portal** — account, orders, quotes, statement, buyers — where every screen can be
  replaced by a template of your own.
- **Console commands** for companies, price lists, credit and maintenance, including
  `forklift/maintenance/doctor`, which names the configuration problems that produce no error
  anywhere.
- **143 integration checks and 37 HTTP checks**, both idempotent and self-cleaning.

### Notes

- Requires Craft CMS 5.3+, Craft Commerce 5.3+ and PHP 8.2+.
- Five default payment terms are created on install.
- Forklift touches Commerce through five documented extension points and replaces the tax engine
  only when Commerce's own is in place.
