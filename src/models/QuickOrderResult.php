<?php

declare(strict_types=1);

namespace justinholtweb\forklift\models;

use craft\base\Model;

/**
 * What a quick-order pad, CSV upload or reorder produced.
 *
 * Deliberately a **preview and an outcome in the same shape**, because a two-hundred-line upload
 * that half-worked is the normal case, not the exception. `rows` carries every row, valid or not,
 * in the order they arrived; `added` counts what reached the cart. A caller running a dry run gets
 * the identical object with `added` at zero, so the confirmation screen and the result screen are
 * one template.
 */
class QuickOrderResult extends Model
{
    /** @var QuickOrderRow[] */
    public array $rows = [];

    /** Rows that reached the cart. Zero on a dry run. */
    public int $added = 0;

    /** True when nothing was written — a preview. */
    public bool $dryRun = false;

    /** Something went wrong before any row could be considered: an unreadable file, no cart. */
    public ?string $fatalError = null;

    /** @return QuickOrderRow[] */
    public function getValidRows(): array
    {
        return array_values(array_filter($this->rows, static fn(QuickOrderRow $row) => $row->getIsValid()));
    }

    /** @return QuickOrderRow[] */
    public function getFailedRows(): array
    {
        return array_values(array_filter($this->rows, static fn(QuickOrderRow $row) => !$row->getIsValid()));
    }

    public function getHasErrors(): bool
    {
        return $this->fatalError !== null || $this->getFailedRows() !== [];
    }

    /** What the valid rows come to. The number a buyer checks before pressing the button. */
    public function getSubtotal(): float
    {
        $total = 0.0;

        foreach ($this->getValidRows() as $row) {
            $total += $row->getSubtotal();
        }

        return round($total, 2);
    }

    public function getTotalQty(): int
    {
        $qty = 0;

        foreach ($this->getValidRows() as $row) {
            $qty += $row->qty;
        }

        return $qty;
    }
}
