# Changelog

All notable changes to Forklift are documented here.

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
