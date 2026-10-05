<?php

declare(strict_types=1);

namespace justinholtweb\forklift\events;

use craft\commerce\elements\Order;
use craft\elements\User;
use craft\events\CancelableEvent;
use justinholtweb\forklift\models\Approval;

/**
 * An approval being requested or decided.
 *
 * On the `before…` events, setting `$isValid = false` stops it: no request is created, or the
 * approval stays pending.
 */
class ApprovalEvent extends CancelableEvent
{
    /** The approval. On EVENT_BEFORE_REQUEST it is built but not yet saved. */
    public Approval $approval;

    /** The order it is for. */
    public ?Order $order = null;

    /** The status it is moving to — approved, declined or cancelled — on the decide events. */
    public ?string $status = null;

    /** Who decided, when anyone did. A signed approval link may be used by nobody signed in. */
    public ?User $approver = null;

    public ?string $note = null;
}
