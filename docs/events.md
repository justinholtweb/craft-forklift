---
title: Events
slug: events
order: 45
summary: Hooking approvals, quotes, the credit ledger and pricing from your own module — and vetoing them.
---

Forklift moves money — it prices baskets, raises invoices on account, and lets orders through
once somebody signs them off — so each of those has an event a module can listen to. The `before…`
events are **cancelable**: set `$event->isValid = false` and the thing does not happen, and the
caller is told it failed. Added in 5.1.0.

```php
use justinholtweb\forklift\events\ApprovalEvent;
use justinholtweb\forklift\services\Approvals;
use yii\base\Event;

Event::on(Approvals::class, Approvals::EVENT_BEFORE_DECIDE, function(ApprovalEvent $event) {
    // Nobody approves their own order.
    if ($event->status === 'approved' && $event->approver?->id === $event->approval->requesterId) {
        $event->isValid = false;
    }
});
```

## Approvals — `services\Approvals`, `events\ApprovalEvent`

| Event | When | Cancelable |
| --- | --- | --- |
| `EVENT_BEFORE_REQUEST` | A request is about to be saved; `$approval` has no id yet | Yes — no request is created |
| `EVENT_AFTER_REQUEST` | The request was saved and the approvers notified | |
| `EVENT_BEFORE_DECIDE` | An approval is about to be approved, declined or cancelled — `$status` says which | Yes — it stays pending |
| `EVENT_AFTER_DECIDE` | It was decided | |

The event carries `$approval`, `$order`, `$status`, `$approver` (null when a signed approval link was
used without signing in) and `$note`.

## Quotes — `services\Quotes`, `events\QuoteEvent`

| Event | When | Cancelable |
| --- | --- | --- |
| `EVENT_BEFORE_SEND` | A quote is about to be priced, turned into a cart and marked sent | Yes |
| `EVENT_AFTER_SEND` | It was sent | |
| `EVENT_AFTER_ACCEPT` | The quote's order completed — `$order` is it | No: by then the money has moved |
| `EVENT_BEFORE_DECLINE` | A quote is about to be declined and its cart discarded | Yes |
| `EVENT_AFTER_DECLINE` | It was declined | |

## The credit ledger — `services\Credit`, `events\CreditEntryEvent`

| Event | When | Cancelable |
| --- | --- | --- |
| `EVENT_BEFORE_SAVE_ENTRY` | A charge, payment or adjustment is about to be written; `$isNew` says which kind of save | Yes — the balance is unchanged |
| `EVENT_AFTER_SAVE_ENTRY` | It was written | |
| `EVENT_BEFORE_DELETE_ENTRY` | An entry is about to be deleted | Yes |
| `EVENT_AFTER_DELETE_ENTRY` | It was deleted | |

Balances are always the sum of the ledger, so these are the only places a balance can change.

## Pricing — `services\Pricing`, `events\DefinePriceEvent`

`EVENT_DEFINE_PRICE` fires after Forklift has resolved a price — quote pin, contract price list,
or Commerce's own — and before anything uses it. Change or replace `$event->result` (a
`models\PriceResult`) and that is what the buyer pays everywhere Forklift prices: the cart, the
quick-order pad and lookup, quotes and the control panel's preview.

```php
use justinholtweb\forklift\events\DefinePriceEvent;
use justinholtweb\forklift\services\Pricing;

Event::on(Pricing::class, Pricing::EVENT_DEFINE_PRICE, function(DefinePriceEvent $event) {
    // A floor no contract may go below.
    $event->result->price = max($event->result->price, $event->result->listPrice * 0.6);
});
```

It also carries `$purchasable`, `$qty`, `$companyId` and `$order`. It fires on every price
resolution, so keep it cheap.
