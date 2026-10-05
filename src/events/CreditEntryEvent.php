<?php

declare(strict_types=1);

namespace justinholtweb\forklift\events;

use craft\events\CancelableEvent;
use justinholtweb\forklift\models\CreditEntry;

/**
 * A credit-ledger entry — a charge, a payment, an adjustment — being saved or deleted.
 *
 * On the `before…` events, setting `$isValid = false` stops it: nothing is written and the
 * balance is unchanged, and the caller is told the save or delete failed.
 */
class CreditEntryEvent extends CancelableEvent
{
    public CreditEntry $entry;

    public bool $isNew = false;
}
