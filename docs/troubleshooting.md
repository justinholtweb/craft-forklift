---
title: Troubleshooting
slug: troubleshooting
order: 50
summary: Wrong prices, blocked checkouts, credit, quotes and tax, and what doctor tells you.
---

Start here:

```sh
php craft forklift/maintenance/doctor
```

Every check in it is something that produces no error and no log line but quietly stops Forklift
doing what you think it is doing.

---

## Prices

### A contract price is not being applied

In order of likelihood:

1. **The licence is Lite.** Price lists are Pro. On Lite they are ignored, not half-applied, and
   the price-lists screen and `doctor` both say so.
2. **The row's SKU does not exist in your store.** An import keeps rows it could not match, and
   they price nothing. `doctor` counts them; `forklift/price-lists/resolve-skus` re-matches after a
   catalogue rebuild.
3. **The list is out of its date range, or disabled.** The index shows *Out of date range* rather
   than *Yes* in the Active column.
4. **The company is not assigned to it.** Either tick *Applies to every company* or add them.
5. **A higher-priority list won.** Remember the rule: the highest-priority list with *any* matching
   entry governs, and then the most specific entry within it. Check the preview.

**Price preview** answers all five at once, and it runs the real resolver:

```sh
php craft forklift/price-lists/price FIX-M8-100 ACME 250
```

### The price is higher than I expected

Look at `suppressedReason` in the preview. Two settings can decide against your list:

- **Allow a contract price above the list price** is off by default, so a row that would charge
  more than the public pays is ignored and the list price used. Almost always this means two
  columns were swapped in the import.
- **Let a promotion beat a contract price** is on by default, so a cheaper public sale wins.

### The price in the basket disagrees with the product page

It cannot, if both go through `craft.forklift.price()`. If they disagree, the product page is
reading `variant.price` or `variant.salePrice` directly — those are Commerce's numbers, not the
buyer's.

---

## Checkout

### The button says one thing and checkout does another

It cannot, if the button reads `craft.forklift.verdict()`. A hard-coded "Place order" will
disagree with the gate; `verdict.actionLabel` will not.

### A buyer cannot check out and there is no message

Render the verdict:

```twig
{% for message in craft.forklift.verdict().allMessages %}
    <p>{{ message }}</p>
{% endfor %}
```

`blockingMessages` is only the sentences actually stopping the order; `allMessages` includes the
ones that are merely true, such as being over a credit limit.

### An order needed approval and went through anyway

The licence was Lite at the time. Forklift fails open there deliberately — a store that cannot
check out is worse than one that lets an order through — and marks every such order. Find them in
**Forklift → Approvals**, or:

```sh
php craft forklift/maintenance/doctor
```

### Nobody is getting approval emails

- Check the company has somebody with an **approver** or **administrator** role. An account with a
  threshold and no approver strands every order over it, and Forklift logs a warning when it
  happens. `doctor` names it.
- Check *Email the approvers* is on.
- Check Craft's own mail settings. Forklift's emails are Craft **system messages** — edit them at
  **Settings → Email → System Messages**.

Sending failures are logged, never thrown: an approval that was granted but whose confirmation
bounced is an inconvenience, and an exception that rolled the decision back would be a support call.

---

## Credit and terms

### The Purchase Order option is not appearing at checkout

It withdraws when any of these is true:

- the licence is Lite (fails closed on purpose — no new credit can be opened);
- the order has no company;
- the company does not have **trades on terms** ticked;
- the account is on hold or closed;
- the order would take the account past its credit limit.

`gateway.getUnavailableReason(order)` gives you a sentence for the last three.

### An order on account has no charge on the ledger

Post-completion bookkeeping is deliberately wrapped so that a failure cannot take down the request
the customer is looking at — which means a failure leaves the invoice unraised. Put it right:

```sh
php craft forklift/credit/repair --dryRun
php craft forklift/credit/repair
```

Idempotent: an order that already has its charge is left alone.

### The aged statement does not match what I expect

Payments are allocated **oldest charge first**, and nothing about the allocation is stored — it is
recomputed every time, so a backdated payment re-ages the account. Money left after every charge is
settled shows as a negative in the current column, because an account in credit should read as
being in credit.

Check the aging buckets ascend and do not repeat. The statement walks them in order.

---

## Quotes

### "The quote was saved but its cart could not be built"

The quote keeps its previous status rather than being marked sent, because a quote marked sent
whose link goes nowhere is worse than one still in the queue. The usual cause is a line whose
purchasable has been deleted since the request. Check the Craft log for
`Quote Q-…: could not add purchasable`.

Free-text lines with no product are skipped when the basket is built — fold them into the delivery
figure.

### The buyer's link says the cart could not be retrieved

Either the link has expired (Commerce's `loadCartUrlExpiry`, a week by default) or the quote has
been re-sent since, which discards the previous basket so an older email cannot buy at an older
price. Re-send it.

### The quoted prices reverted to list

They should not — quote pins outrank everything in the resolver, and the basket carries the quote
on its Forklift row. If it happens, the basket was copied rather than loaded: the copy has no
`quoteId`, so nothing knows it came from a quote.

---

## Tax

### Tax is still being charged for an exempt customer

Work through:

1. Is the certificate **approved**? Uploaded is not approved.
2. Has it **expired**? An expired certificate exempts nothing from the moment it expires, not from
   the next sweep.
3. Does it **cover the delivery address**? A country *and* a state must match both.
4. Is **Honour exemption certificates at checkout** on?
5. Is another plugin providing the tax engine? Forklift only replaces Commerce's own. Check the log
   for "Another plugin owns the tax engine".

### Tax was removed and should not have been

Check the certificate's jurisdiction. A certificate with **no country** covers everywhere, which is
right for a charity registration and wrong for a state resale certificate.

---

## The control panel

### A screen 403s

Pro-only screens — quotes, approvals, credit — refuse a Lite licence by design. Otherwise check the
Forklift permissions on the user's group. Seeing what a customer negotiated, and what they owe, are
separate permissions from maintaining an address book on purpose.

### The Forklift panel is missing from an order

It only renders when there is something to say: a company, a PO number, an approval, a quote, or a
bypassed approval. A retail order shows nothing.

---

## Data

### I deleted a price list and an old order changed

It should not have. Forklift's foreign keys null the reference rather than cascading for anything
that records something that happened — deleting a price list does not erase the invoice it priced,
and deleting an order does not erase the payment that settled it. What *does* cascade is a row that
only exists because of its parent: members with their company, entries with their list, quote lines
with their quote.

### Uninstalling

Drops Forklift's twelve tables. Commerce orders, customers and products are untouched, but the B2B
record is not — which account an order belonged to, its PO number, its approval, the ledger and the
certificates. Export first:

```sh
php craft forklift/price-lists/export --list=acme prices.csv
php craft forklift/credit/statement ACME
```
