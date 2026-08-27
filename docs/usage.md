# Using Forklift

## Setting up an account

**Forklift → Companies → New company.** Give it a name; the account code is generated from the name
if you leave it blank, and it is what your warehouse will read on a pick note.

Then **Buyers**. Add people by email address — they need an account on your site already, because
Forklift never creates users from a form. The first person added becomes the account's
administrator whatever role you pick.

| Role | Can |
| --- | --- |
| Administrator | Runs the account: adds and removes buyers, sets limits, approves anything, sees the statement. |
| Approver | Approves other people's orders. Does not administer the account. |
| Buyer | Places orders, up to their spend limit. |
| Viewer | Sees orders and quotes, can reorder into a basket, cannot place an order. |

A **spend limit** is per order. Blank means no limit of their own; **zero** means they cannot place
an order unassisted, which is what a new starter looks like.

### Putting an account on hold

An account on hold cannot place new orders. Everything else — existing orders, quotes, invoices,
the statement — is untouched, and taking it off hold is one click. Either from the company screen
or:

```sh
php craft forklift/companies/hold ACME
php craft forklift/companies/release ACME
```

## Contract pricing

### The two-list pattern

The setup that covers most wholesale stores:

1. A **trade list** with *Applies to every company* on, priority `0`, holding one entry:
   *Everything, 15% off*.
2. A **customer list** per negotiated account, priority `10`, holding the handful of lines that
   customer argued over.

The customer list wins for the lines it mentions. Everything else falls through to the trade list.
That is the whole rule.

### Quantity breaks

Several entries on the same product with different *From quantity* values are a break table:

```
sku,price,minQty
FIX-M8-100,3.40,1
FIX-M8-100,3.05,50
FIX-M8-100,2.80,250
```

The resolver takes the highest rung at or below the quantity being bought, and tells your templates
about the next rung up so a product page can print "50 more and they're £3.05 each".

### Fixed against percentage

- **Fixed** is a negotiated number. It does not move when your list price does — which is what a
  contract means, and also what will quietly erode your margin if you forget it exists.
- **Percentage off** and **amount off** track the list price, so a price rise reaches trade
  customers automatically.

### Importing

Control panel: **Price lists → the list → Import**. Command line, which is where a nightly ERP feed
belongs:

```sh
php craft forklift/price-lists/import prices.csv --list=acme --dryRun
php craft forklift/price-lists/import prices.csv --list=acme
```

An import **replaces** the list. The spreadsheet is the contract, and a row you deleted from it
should not survive in the store because nothing mentioned it.

A price ending in `%` is a percentage off list. Rows whose SKU does not exist in your store are
imported and reported — they price nothing until the SKU appears, and the importer says how many
did not match rather than letting a 40% miss rate look like a successful import.

### Checking a price

**Price lists → Price preview**, or:

```sh
php craft forklift/price-lists/price FIX-M8-100 ACME 250
```

Both run the real resolver, so the number is a promise the checkout will keep. Both tell you which
list won, which entry, which break, and — if a contract price was found and *not* used — why.

## Approvals

Set an **approval threshold** on the company, a **spend limit** on a buyer, or tick *every order
needs approval* on a buyer. When a basket trips one of those, the checkout button changes to
*Submit for approval*.

Approvers get an email with a link that works **without signing in**, because the people who sign
off spend very often have no login. They see the basket, approve or decline with a note, and the
buyer is emailed the decision.

An approval records the amount it covered. If the basket grows afterwards it needs approving again;
if it shrinks it does not.

Requests lapse after the configured number of days. **A lapsed request is not an approval** — the
basket comes back to the buyer with the reason attached.

## Net terms

1. Put the account on **payment terms** and tick **trades on terms**.
2. Give it a **credit limit**. Blank means no limit; zero means no orders on account.
3. At checkout the buyer sees a **Purchase Order** payment option, asking for their PO number.

The order completes with an **authorised** transaction, so it shows as unpaid — which is true,
because nobody has paid. A charge goes on the ledger with a due date worked out from the terms.
When the money arrives, capture the transaction in Commerce and record the payment:

```sh
php craft forklift/credit/payments remittances.csv   # accountCode,amount,reference,date
```

or from **Credit → the company → Record a payment**.

Payments are applied to charges oldest first. Nothing about the allocation is stored, so a
backdated payment re-ages the account correctly.

The gateway withdraws itself when the account is over its limit — but the *checkout* does not stop.
A customer over their limit holding out a credit card should be allowed to use it.

## Quotes

1. The buyer asks. Your template posts to `forklift/portal/request-quote` and their basket is
   snapshotted, priced as they saw it.
2. **Forklift → Quotes.** Open it. Change quantities and prices, add lines, add delivery, add a
   whole-quote discount, set an expiry. Every line shows what that customer would otherwise pay, so
   you are discounting against something visible.
3. **Save and send.** Forklift builds a real Commerce basket carrying exactly those prices and
   emails the buyer a signed link.
4. The buyer clicks, their basket loads, and they check out normally — with any gateway you have,
   including paying on terms.

**Re-price** starts from what the customer would pay today, which is what you want on a quote that
has been sitting for a fortnight.

Sending again discards the previous basket, so an older email cannot buy at an older price.

## Quick order, CSV and reorder

The pad is at `/forklift/quick-order`, or build your own against
`craft.forklift` and the `forklift/quick-order/*` actions.

Everything is checked before it is added, and **nothing is silently dropped**. A row that fails
comes back with its line number, the text that was typed, and a sentence saying why. A CSV can have
its columns either way round, may or may not have a header, and survives the byte-order mark Excel
writes.

Reordering re-prices at **today's** rates and flags the lines that changed. A contract that has
been renegotiated should not be quietly ignored.

## Tax exemption certificates

Record the certificate against the company with its jurisdiction and expiry, attach the scan, and
**approve** it. Only an approved, unexpired certificate that covers the delivery address removes
tax.

A certificate with no country covers everywhere. A country with no state covers the country. A
country *and* a state must match both — a Texas resale certificate does not exempt a delivery to
Ohio, and letting it would be Forklift creating a tax liability on your behalf.

```sh
php craft forklift/maintenance/expiring-certificates 60
```

## When something is not working

```sh
php craft forklift/maintenance/doctor
```

Every check in it is something that produces no error and no log line but quietly stops Forklift
doing what you think it is doing.
