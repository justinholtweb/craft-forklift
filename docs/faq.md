# Forklift — frequently asked questions

## How is this different from Commerce's catalog pricing rules?

Catalog pricing rules price *by condition* — this product type, that user group, over that
quantity. They are excellent at that.

What they cannot express is the thing wholesale runs on: **a negotiated sheet of prices belonging
to one named customer**, with quantity breaks, that somebody imported from a spreadsheet and will
export back to one. A price list in Forklift is a document with a name, an owner and a body of
rows, not a rule.

The two coexist. Commerce's rules produce the list price; Forklift's lists price the contract on
top of it, and Forklift will let a public promotion win when it is cheaper.

## And Commerce Bulk Pricing?

Bulk Pricing does quantity breaks. Forklift does quantity breaks *inside* customer-specific price
lists, which is a different problem — and the rest of the suite besides.

## Does it work with an existing Commerce store?

Yes. Forklift never writes to Commerce's tables. Retail orders are untouched: an order with no
company on it picks up no B2B rules at all, which matters because most stores that install this do
it for one customer in twelve.

## Can somebody buy for more than one company?

Yes, and their role can differ per account — administrator of one, viewer on another. That is what
happens when a buying group and one of its members both trade with you.

When somebody belongs to several accounts and none is marked as their default, **Forklift will not
guess**. Pricing a basket at the wrong account's rates is worse than asking which account it is
for, so the portal asks.

## What happens to an order when the account goes on hold?

Nothing. On hold stops *new* orders. Existing orders, quotes, invoices and the statement are
untouched, and taking it off hold is one click. That is deliberately different from "closed", which
says the same thing to Forklift and a different thing to the person reading the account.

## Do approvers need a login?

No. The approval email carries a single-purpose tokenised link, and they decide from their inbox.
The people who sign off spend in a purchasing department very often have no account on your site,
and a workflow that requires one is a workflow that gets bypassed by forwarding the email to
somebody who does.

## Can somebody approve their own order?

Yes, if they hold an approving role. In a two-person business the administrator is the only
approver there is, and forbidding it would make the account unusable. What stops it being
meaningless is that the decision is recorded with a name and a timestamp against it.

## What if the basket changes after it has been approved?

An approval records the amount it covered. A basket that has **grown** past it needs approving
again; one that has **shrunk** does not. Nobody needs re-authorising to spend less.

## Does paying on terms mark the order as paid?

No, and that is the point. The purchase-order gateway **authorises** rather than purchases, so the
order completes, the customer gets their confirmation, the goods are picked, and `totalPaid` stays
at zero — which is true, because nobody has paid. Capture the transaction when the money arrives
and Commerce marks it paid with its own machinery.

Every report, paid-status filter and integration in your store already understands that. A bespoke
"invoice" state would have to be taught to all of them.

## What happens when a customer goes over their credit limit?

The purchase-order gateway withdraws itself. **Checkout does not stop.** A customer over their
limit holding out a credit card should be allowed to use it, and refusing them is a way to lose
money rather than to protect it.

## Where does the balance come from?

`SUM` over the ledger, every time. There is no running total on the company row, because a cached
balance is a number that can be wrong — and the moment it goes wrong, a refund landing from a
webhook while somebody edits the same order, is exactly the moment somebody is on the telephone
about it.

## Can I get a PDF statement?

Forklift gives you a printable HTML statement and a CSV. Commerce already ships a PDF pipeline of
its own, and pointing that at a statement is a better answer than Forklift growing a second one
with its own template quirks and its own security advisories to track.

## Will an exemption certificate zero-rate everything?

Only if you tell it to. Tax is removed for a certificate that is **approved by a human**,
unexpired, and **covers the delivery address**. A certificate with no country covers everywhere; a
country with no state covers that country; a country and a state must match both.

A Texas resale certificate does not exempt a delivery to Ohio, and letting it would be Forklift
creating a tax liability on your behalf.

## Does it work with Avalara or TaxJar?

Forklift replaces the tax engine **only when Commerce's own is in place**. A store running Avalara
or TaxJar keeps theirs — those services do their own exemption handling, and quietly replacing them
would be both wrong and very hard to diagnose. Certificates are still recorded; they just are not
applied, and the log says so.

## What happens when my licence lapses?

Nothing breaks. Pro configuration is *ignored, not obeyed*:

- **Price lists stop applying**, so buyers pay list price. That errs towards not under-charging.
- **The purchase-order gateway withdraws**, so no new credit can be opened. Existing invoices are
  untouched.
- **Approvals stop blocking checkout.** A store that cannot check out at all is worse than one that
  lets an order through, so the order is placed and flagged. This is the one place Forklift fails
  open, and it says so on the order, in the approvals screen and in `forklift/maintenance/doctor`
  rather than hiding it.

## Can I import ten thousand prices?

Yes. Use the console:

```sh
php craft forklift/price-lists/import prices.csv --list=acme
```

Writes are batched and chunked, and run in a transaction. An import **replaces** the list — the
spreadsheet is the contract.

## Does the quick-order pad guess at SKUs?

Exact match, then case-insensitive, and nothing else. A buyer typing a part number wants that part,
and quietly supplying the nearest thing to it is how the wrong item ends up on a pallet. Prefix
matching belongs in the autocomplete, where a human picks from the results.

## Does reordering copy the old prices?

No — it re-prices at today's rates and tells the buyer which lines changed. A contract that has
been renegotiated, a break that has moved, or a list-price rise would otherwise be quietly ignored,
and the buyer would meet the real number at checkout.

## Can I style the portal?

Create `_forklift/portal/<name>.twig` in your own templates and it wins outright. Forklift ships
plain fallbacks so the portal works the moment you install it, and so you can see what the
variables are before writing your own.

## Something is not working and there is no error anywhere.

```sh
php craft forklift/maintenance/doctor
```

That is exactly what it is for.
