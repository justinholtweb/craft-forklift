<?php

declare(strict_types=1);

namespace justinholtweb\forklift\models;

use Craft;
use craft\base\Model;
use craft\helpers\DateTimeHelper;
use DateTime;
use DateTimeInterface;

/**
 * Named payment terms — "Net 30", "2/10 Net 30", "Due on receipt".
 *
 * The early-settlement discount is modelled as the pair of numbers it really is
 * (`discountPercent` within `discountDays`) rather than as free text, because the whole reason a
 * distributor offers 2/10 Net 30 is to be able to work out, later, who took it.
 */
class Term extends Model
{
    public ?int $id = null;
    public ?int $storeId = null;
    public ?string $name = null;
    public ?string $handle = null;
    public int $netDays = 30;
    public ?float $discountPercent = null;
    public ?int $discountDays = null;
    public ?string $description = null;
    public bool $enabled = true;
    public ?int $sortOrder = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    public function __toString(): string
    {
        return (string)$this->name;
    }

    /** When an invoice raised on `$from` falls due. */
    public function dueDate(DateTimeInterface $from): DateTime
    {
        $due = DateTimeHelper::toDateTime($from, false, false) ?: new DateTime();
        $due = (clone $due);

        return $due->modify('+' . max(0, $this->netDays) . ' days');
    }

    /** The last day the early-settlement discount can be taken, if there is one. */
    public function discountDeadline(DateTimeInterface $from): ?DateTime
    {
        if (!$this->getHasEarlyDiscount()) {
            return null;
        }

        $date = DateTimeHelper::toDateTime($from, false, false) ?: new DateTime();

        return (clone $date)->modify('+' . $this->discountDays . ' days');
    }

    public function getHasEarlyDiscount(): bool
    {
        return $this->discountPercent !== null
            && $this->discountPercent > 0
            && $this->discountDays !== null
            && $this->discountDays > 0;
    }

    /** What the buyer would pay by settling early. */
    public function discountedAmount(float $amount): float
    {
        if (!$this->getHasEarlyDiscount()) {
            return $amount;
        }

        return round($amount * (1 - ($this->discountPercent / 100)), 2);
    }

    /** "2/10 Net 30" — the form a purchasing department will recognise on sight. */
    public function getShorthand(): string
    {
        if (!$this->getHasEarlyDiscount()) {
            return $this->netDays === 0
                ? Craft::t('forklift', 'Due on receipt')
                : Craft::t('forklift', 'Net {days}', ['days' => $this->netDays]);
        }

        return sprintf(
            '%s/%d Net %d',
            rtrim(rtrim(number_format($this->discountPercent, 2, '.', ''), '0'), '.'),
            $this->discountDays,
            $this->netDays,
        );
    }

    protected function defineRules(): array
    {
        return [
            [['name', 'handle', 'netDays'], 'required'],
            [['netDays'], 'integer', 'min' => 0, 'max' => 3650],
            [['discountDays'], 'integer', 'min' => 0, 'max' => 3650],
            [['discountPercent'], 'number', 'min' => 0, 'max' => 100],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_]*$/', 'message' => Craft::t('forklift', 'Handles may only contain letters, numbers and underscores, and must start with a letter.')],
            // skipOnEmpty off: an empty discountDays alongside a discountPercent is exactly the
            // case this has something to say about, and Yii skips inline validators on empty
            // attributes unless told not to.
            [['discountDays'], 'validateDiscountPair', 'skipOnEmpty' => false],
            [['enabled'], 'boolean'],
            [['description', 'sortOrder', 'storeId'], 'safe'],
        ];
    }

    public function validateDiscountPair(string $attribute): void
    {
        $hasPercent = $this->discountPercent !== null && $this->discountPercent > 0;
        $hasDays = $this->discountDays !== null && $this->discountDays > 0;

        if ($hasPercent && !$hasDays) {
            $this->addError($attribute, Craft::t('forklift', 'An early-settlement discount needs a number of days to be taken within.'));
        }

        if ($hasDays && $this->discountDays > $this->netDays) {
            $this->addError($attribute, Craft::t('forklift', 'The discount period cannot be longer than the terms themselves.'));
        }
    }
}
