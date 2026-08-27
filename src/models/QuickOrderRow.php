<?php

declare(strict_types=1);

namespace justinholtweb\forklift\models;

use craft\base\Model;

/**
 * One row of a quick-order pad, a CSV upload or a reorder — before it becomes a line item.
 *
 * The pad, the upload and the reorder screen all produce these and all render them, so a SKU that
 * cannot be found looks and reads the same however it arrived. A row is never silently dropped:
 * it comes back with `error` set and its original text intact, because the one thing a buyer
 * pasting two hundred lines needs is to be told *which* line was wrong.
 */
class QuickOrderRow extends Model
{
    /** As typed or as it appeared in the file. Echoed back so the buyer can see their own input. */
    public ?string $sku = null;
    public int $qty = 1;

    /** Free text from the file's third column, if there was one. Becomes a line-item note. */
    public ?string $note = null;

    /** 1-based, so an error message can name the line of the file a person is looking at. */
    public ?int $lineNumber = null;

    public ?int $purchasableId = null;
    public ?string $description = null;
    public ?PriceResult $price = null;

    /** Null when the row is fine. */
    public ?string $error = null;

    /** A row that resolved but is worth a word of warning — low stock, a changed price. */
    public ?string $warning = null;

    public function getIsValid(): bool
    {
        return $this->error === null && $this->purchasableId !== null;
    }

    public function getSubtotal(): float
    {
        return $this->price?->getSubtotal() ?? 0.0;
    }
}
