<?php

declare(strict_types=1);

namespace justinholtweb\forklift\models;

use craft\base\Model;
use DateTime;
use justinholtweb\forklift\elements\Company;

/**
 * A company's account, as at a date: an opening balance, the movements since, and what is now
 * overdue by how long.
 *
 * Built by `services\Credit::statement()` and rendered by both the control panel and the buyer's
 * portal, so a customer disputing a figure and the person answering the phone are looking at
 * arithmetic that came from the same place.
 *
 * The aging buckets are configurable but their *shape* is not: each bucket is "at least this many
 * days overdue, and less than the next", the last one is open-ended, and `current` is everything
 * not yet due. That is the form every accounts-receivable ledger in the world uses, and a
 * statement that invented its own would be unreadable to the only people who read statements.
 */
class Statement extends Model
{
    public ?Company $company = null;
    public ?DateTime $from = null;
    public ?DateTime $to = null;
    public ?string $currency = null;

    /** What was owed at the start of the period. */
    public float $openingBalance = 0.0;

    /** What is owed at the end of it. */
    public float $closingBalance = 0.0;

    public float $totalCharges = 0.0;
    public float $totalPayments = 0.0;
    public float $totalAdjustments = 0.0;

    /** @var CreditEntry[] In date order, oldest first. */
    public array $entries = [];

    /**
     * Overdue amounts by bucket, keyed by the bucket's lower bound in days.
     *
     * `0` is the "current" bucket — not yet due. A key of `90` with buckets `[30, 60, 90]` means
     * ninety days and over.
     *
     * @var array<int, float>
     */
    public array $aging = [];

    /** @var array<int, string> Human labels for the same keys, so a template need not build them. */
    public array $agingLabels = [];

    public ?float $creditLimit = null;

    public function getCreditRemaining(): ?float
    {
        if ($this->creditLimit === null) {
            return null;
        }

        return round($this->creditLimit - $this->closingBalance, 2);
    }

    public function getIsOverLimit(): bool
    {
        $remaining = $this->getCreditRemaining();

        return $remaining !== null && $remaining < 0;
    }

    /** Everything past its due date, whatever the bucket. */
    public function getTotalOverdue(): float
    {
        $total = 0.0;

        foreach ($this->aging as $days => $amount) {
            if ($days > 0) {
                $total += $amount;
            }
        }

        return round($total, 2);
    }

    /** The number of days the oldest overdue charge has been outstanding. */
    public function getOldestOverdueDays(): int
    {
        $oldest = 0;

        foreach ($this->entries as $entry) {
            $oldest = max($oldest, $entry->getDaysOverdue($this->to));
        }

        return $oldest;
    }
}
