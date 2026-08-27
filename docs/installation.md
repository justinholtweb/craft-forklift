# Installing Forklift

## Requirements

- Craft CMS 5.3 or later
- Craft Commerce 5.3 or later
- PHP 8.2 or later

## Install

```sh
composer require justinholtweb/craft-forklift
php craft plugin/install forklift
```

## What the install does

One migration, twelve tables, and five default payment terms — Due on receipt, Net 14, Net 30,
Net 60 and 2/10 Net 30. Nothing else changes: no orders are touched, no prices move, and Commerce
behaves exactly as it did until you create your first company.

The terms are seeded from `afterInstall()` rather than from the migration, because project-config
writes from a migration are buffered and can land before the plugin's own row exists — the change
then vanishes while the call still returns `true`.

## Editions

Forklift installs as **Lite**, which is free and is a working company-accounts plugin rather than
a demo:

- Company accounts with a field layout of your own
- Buyers, roles and per-buyer spend limits
- Company order history and the buyer portal
- Purchase-order numbers captured at checkout
- Tax exemption certificates
- The quick-order pad and reorder, at list prices

There is deliberately **no cap** on companies, buyers or orders.

**Pro** adds contract price lists, spend approvals, net terms with credit limits and statements,
quotes with pay-by-link, and CSV order upload. Switch editions from *Settings → Plugins* in the
control panel.

## First run

1. **Settings → Forklift → Payment terms** — check the defaults suit you, or add your own.
2. **Forklift → Companies → New company** — name it, and give it an account code if your warehouse
   uses one. Leave the code blank and one is generated from the name.
3. **Buyers** — add the people who order for that account by email address. They need an account on
   your site already; Forklift never creates users. The first person added becomes the account's
   administrator whatever role you choose, because an account nobody can administer is the most
   common way to end up needing support.
4. Sign in as one of them and put something in a basket. If you have a price list, the price
   changes from the first line item onwards.

## Uninstalling

```sh
php craft plugin/uninstall forklift
```

Drops Forklift's twelve tables and removes its field layouts from project config. Commerce orders,
customers and products are untouched — Forklift never writes to Commerce's own tables. What is
lost is the B2B record: which account an order belonged to, its PO number, its approval, the credit
ledger and the certificates. **Export anything you need first** —
`forklift/price-lists/export` and `forklift/credit/statement` both write CSV.
