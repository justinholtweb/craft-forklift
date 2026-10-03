---
title: Templating
slug: templating
order: 40
summary: Everything on craft.forklift, with front-end forms for the pad, the upload and quotes.
---

Everything is on `craft.forklift`. **Nothing on it writes** — a template asks questions, a
controller makes changes. There is no `addToCart()` here and no `approve()`, because a template
that mutates state mutates it on a bot's page view.

## Who is buying

```twig
{% set company = craft.forklift.company %}
{% set member = craft.forklift.member %}

{% if craft.forklift.isB2b %}
    <p>Buying for {{ company.uiLabel }} ({{ member.roleLabel }})</p>
{% endif %}
```

| | |
| --- | --- |
| `craft.forklift.company` | The company this visitor is buying for, or `null`. |
| `craft.forklift.member` | Their membership of it — role, spend limit. |
| `craft.forklift.companies` | Every company they buy for. More than one means offer a switcher. |
| `craft.forklift.isB2b` | Shorthand for "there is a company". |
| `craft.forklift.companyQuery(criteria)` | A `CompanyQuery`, for listing accounts. |

### Switching accounts

```twig
{% if craft.forklift.companies|length > 1 %}
    <form method="post">
        {{ csrfInput() }}
        <input type="hidden" name="action" value="forklift/companies/switch">
        {{ redirectInput('shop/cart') }}
        <select name="companyId">
            {% for option in craft.forklift.companies %}
                <option value="{{ option.id }}" {{ option.id == craft.forklift.company.id ? 'selected' }}>
                    {{ option.uiLabel }}
                </option>
            {% endfor %}
        </select>
        <button>Switch</button>
    </form>
{% endif %}
```

Forklift refuses to guess when somebody belongs to several accounts and none is their default, so
`craft.forklift.company` is `null` until they choose.

## Prices

`craft.forklift.price(purchasable, qty)` returns a `PriceResult`, which is the same object the
cart uses. The reason is not decoration: a wholesale buyer looking at £8.40 wants to know whether
that is their contract rate, a break they have just earned, or the public price.

```twig
{% set price = craft.forklift.price(variant, 12) %}
```

| Property | |
| --- | --- |
| `price` | What to charge, per unit. |
| `listPrice` | What the public pays. |
| `promotionalPrice` | Commerce's own sale price, if it has one. |
| `saving` / `savingPercent` | Against the list price. |
| `subtotal` | `price × qty`. |
| `isContractPrice` | Whether B2B pricing applied at all. |
| `source` | `list`, `promotion`, `priceList`, `quantityBreak`, `quote`. |
| `sourceLabel` | The same, as a sentence: "Quantity break at 12+". |
| `breakQty` | The rung that matched. |
| `nextBreak` | `{qty, price}` for the next rung up, or `null`. |
| `suppressedReason` | Why a contract price was found and *not* used. |

```twig
<p class="price">{{ price.price|commerceCurrency(cart.currency) }}</p>

{% if price.isContractPrice %}
    <p class="was">
        <s>{{ price.listPrice|commerceCurrency(cart.currency) }}</s>
        {{ price.sourceLabel }} — you save {{ price.saving|commerceCurrency(cart.currency) }}
    </p>
{% endif %}

{% if price.nextBreak %}
    <p class="nudge">
        {{ price.nextBreak.qty }}+ at {{ price.nextBreak.price|commerceCurrency(cart.currency) }} each
    </p>
{% endif %}
```

### The break table

```twig
{% set breaks = craft.forklift.breaks(variant) %}

{% if breaks|length > 1 %}
    <table class="breaks">
        {% for rung in breaks %}
            <tr>
                <th>{{ rung.qty }}+</th>
                <td>{{ rung.price|commerceCurrency(cart.currency) }}</td>
            </tr>
        {% endfor %}
    </table>
{% endif %}
```

Only rungs that actually change the price are returned, and the list is empty when this visitor has
no breaks — so `{% if breaks %}` is the right test.

## Checkout

`craft.forklift.verdict()` is the same verdict the checkout gate reads, so what your button says is
what pressing it does.

```twig
{% set verdict = craft.forklift.verdict() %}

{% for message in verdict.blockingMessages %}
    <p class="error">{{ message }}</p>
{% endfor %}

{% if verdict.isAwaitingApproval %}
    <p>{{ verdict.actionLabel }}</p>

{% elseif verdict.needsApproval %}
    <form method="post">
        {{ csrfInput() }}
        <input type="hidden" name="action" value="forklift/portal/submit-for-approval">
        <textarea name="note" placeholder="Anything your approver should know"></textarea>
        <button>{{ verdict.actionLabel }}</button>
    </form>

{% else %}
    <button {{ not verdict.isAllowed ? 'disabled' }}>{{ verdict.actionLabel }}</button>
{% endif %}
```

| | |
| --- | --- |
| `isAllowed` | Whether the order may complete right now. |
| `needsApproval` | The buyer's next action is to ask somebody. |
| `isAwaitingApproval` | Somebody has been asked and has not answered. |
| `actionLabel` | "Place order", "Submit for approval", "Awaiting approval". |
| `blockingMessages` | Only the sentences actually stopping the order. |
| `allMessages` | Every sentence, blocking or not. |
| `reasons` | The machine-readable list. |
| `creditRemaining` | What would be left after this order, on terms. |

Being over a credit limit is deliberately **not** blocking — it withdraws the purchase-order
gateway, and the buyer can still pay by card.

```twig
{% if craft.forklift.canPayOnTerms() %}
    <p>You can put this on account.</p>
{% endif %}
```

## Orders, quotes and the statement

```twig
{% for order in craft.forklift.orders(25) %}
    <tr>
        <td>{{ order.reference }}</td>
        <td>{{ craft.forklift.poNumber(order) }}</td>
        <td>{{ order.totalPrice|commerceCurrency(order.currency) }}</td>
    </tr>
{% endfor %}
```

`orders()` returns the **company's** orders, not just this person's — which is the thing a buyer
actually wants from a B2B portal.

```twig
{% for quote in craft.forklift.quotes({ quoteStatus: 'sent' }).all() %}
    <a href="{{ quote.paymentUrl }}">Accept quote {{ quote.number }}</a>
{% endfor %}
```

```twig
{% set statement = craft.forklift.statement() %}

{% if statement %}
    <p>Balance {{ statement.closingBalance }}, {{ statement.totalOverdue }} overdue</p>
{% endif %}
```

`statement()`, `balance()` and `creditAvailable()` all return `null` when the visitor's role does
not include the account's finances — a buyer is not entitled to the balance, and an empty statement
would read like a settled one.

## Approvals

```twig
{% for approval in craft.forklift.pendingApprovals %}
    <li>
        {{ approval.requester.name }} — {{ approval.amount }}
        <a href="{{ approval.decisionUrl }}">Review</a>
    </li>
{% endfor %}
```

## Replacing the built-in screens

Every portal and quick-order screen renders `_forklift/portal/<name>.twig` from **your** templates
if it exists, and falls back to a plain one Forklift ships:

```
_forklift/portal/index.twig
_forklift/portal/orders.twig
_forklift/portal/quotes.twig
_forklift/portal/statement.twig
_forklift/portal/buyers.twig
_forklift/portal/approval.twig
_forklift/quick-order/pad.twig
_forklift/quick-order/preview.twig
```

Create the file and it wins outright. A B2B portal has to look like the shop it is part of, and a
plugin that insists on its own markup gets replaced by a fortnight of overrides.

## Actions

| Action | Does |
| --- | --- |
| `forklift/companies/switch` | Choose which account this session buys for. |
| `forklift/portal/request-quote` | Turn the basket into a quote request. |
| `forklift/portal/submit-for-approval` | Submit the basket for sign-off. |
| `forklift/portal/save-buyer` / `remove-buyer` | Self-service buyer management. |
| `forklift/quick-order/lookup` | One SKU and quantity → JSON price. |
| `forklift/quick-order/suggest` | SKU autocomplete. |
| `forklift/quick-order/preview` | Resolve rows without touching the basket. |
| `forklift/quick-order/add` | Add rows to the basket. |
| `forklift/quick-order/reorder` | Rebuild a past order at today's prices. |

`lookup` and `suggest` answer JSON, so a pad can price as the buyer types:

```js
const res = await fetch(`/index.php?action=forklift/quick-order/lookup&sku=${sku}&qty=${qty}`, {
    headers: { Accept: 'application/json' },
});
const { found, price, sourceLabel, nextBreak } = await res.json();
```
