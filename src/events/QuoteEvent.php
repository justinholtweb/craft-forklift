<?php

declare(strict_types=1);

namespace justinholtweb\forklift\events;

use craft\commerce\elements\Order;
use craft\events\CancelableEvent;
use justinholtweb\forklift\elements\Quote;

/**
 * A quote being sent, accepted or declined.
 *
 * On the `before…` events, setting `$isValid = false` stops it and leaves the quote as it was.
 * Acceptance has no `before`: it is recorded when the order completes, and the money has moved.
 */
class QuoteEvent extends CancelableEvent
{
    public Quote $quote;

    /** The order that accepted it, on EVENT_AFTER_ACCEPT. */
    public ?Order $order = null;

    /** The decline note, on the decline events. */
    public ?string $note = null;
}
