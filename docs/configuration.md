# Configuring Forklift

Settings live under **Forklift → Settings**, split across several screens rather than one long
pane. Nothing in Forklift's settings is marked `required`, so a fresh install can always save.

## General

**Capture a purchase order number at checkout** — company orders get a field for the reference
their accounts department will match the invoice against. On.

**Enforce it when the account demands one** — refuse to complete an order with no PO number, for
accounts marked as requiring one. On. Off makes the field a suggestion.

**Honour exemption certificates at checkout** — on. Off keeps the certificates on file and charges
tax as normal, which is what you want while you are still collecting documents.

**Warn this many days before a certificate expires** — 30. Zero turns the warning off.

**Rows on the pad** — 10.

**Most rows in one CSV upload** — 500. A ceiling, so a mis-selected export fails with a message
rather than an exhausted memory limit.

## Companies and buyers

**Put a buyer's orders on their company automatically** — on. A signed-in buyer's basket is
attached to their account from the first line item, which it has to be, because the price of that
line item depends on it. Off means orders are assigned by hand in the control panel.

**Let a buyer switch between their accounts** — on. Only affects people who belong to more than
one company.

> When somebody belongs to several accounts and none is marked as their default, Forklift will not
> guess. Pricing a basket at the wrong account's rates is worse than asking which account it is
> for, so the portal asks.

**Let company administrators manage their own buyers** — on. Adds and removes buyers from the
front-end portal, for people already registered on your site.

### Contract pricing

**Allow a contract price above the list price** — **off**, and the default matters. A price list
imported with two columns swapped otherwise charges a trade customer more than the public pays,
silently, on every line. When it is off the resolver keeps the lower number and records why, and
the price preview says so.

**Let a promotion beat a contract price** — **on**. A customer on a negotiated rate who is shown a
public sale at a better number and then charged their contract rate has, as far as they are
concerned, been overcharged.

### Approvals

**Email the approvers** / **Email the buyer when their order is decided** — both on.

**Days before an approval request lapses** — 14. Zero means never. A lapsed request is *not* an
approval: it releases the basket back to the buyer with the reason attached, and they can ask
again.

## Quotes

**Days a sent quote stays valid** — 30, used when you do not set a date of your own.

**Empty the basket once a quote has been requested** — on, so the customer does not check out at
list price the thing they just asked you to price.

**Email the store when a quote is requested** — on.

**Where the pay-by-link sends the customer** — a path on your site, your basket or checkout page.
Blank uses Commerce's own load-cart redirect.

## Credit and terms

**Count an order against the credit limit the moment it is placed** — on. The alternative, counting
only invoices that have been raised, lets a buyer place six orders in an afternoon that each pass
the check on their own.

**Aging buckets** — `30, 60, 90`. Days overdue, ascending, comma separated; the last is open-ended,
so those three give you current, 30–59, 60–89 and 90+. They must ascend and not repeat, because
the statement walks them in order and an out-of-order bucket swallows the one before it.

### Payment terms

Named terms on their own screen. Each has a number of net days and, optionally, an early-settlement
discount expressed as the pair of numbers it really is — `2%` within `10` days on `Net 30` days
gives you `2/10 Net 30`. Modelled that way rather than as free text because the whole reason to
offer it is to be able to work out later who took it.

The discount period cannot be longer than the terms themselves.

## Company fields and quote fields

Two field layouts. **Company fields** appear on every account — a sales rep, a region, delivery
instructions, whatever your accounts actually differ by. **Quote fields** appear on every quote — a
lead source, a delivery week, whoever is handling it.

Forklift keeps as *columns* only the things it has to reason about: account codes, credit limits,
thresholds, statuses. Everything else is yours.

## Per-company settings

These are on the company, not in settings, because they differ per account:

| Setting | Meaning |
| --- | --- |
| **Account status** | Active, on hold, or closed. On hold stops new orders and nothing else. |
| **Payment terms** | Which terms this account is invoiced on. Blank means prepay. |
| **Trades on terms** | Makes the purchase-order gateway available at checkout. |
| **Credit limit** | Blank means no limit. **Zero means no orders on account.** |
| **Approval needed at or above** | Blank means no threshold. **Zero means every order needs approval.** |
| **Requires a PO number** | Refuse checkout without one, when enforcement is on. |
