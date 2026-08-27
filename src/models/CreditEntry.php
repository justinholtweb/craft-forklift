<?php

declare(strict_types=1);

namespace justinholtweb\forklift\models;

use Craft;
use craft\base\Model;
use DateTime;

/**
 * One line of a company's credit ledger.
 *
 * The ledger is append-only and **signed**: a charge is positive, a payment is negative, and the
 * balance is `SUM(amount)` and nothing else. No running total in a column on the company, because
 * a running total drifts — a refund processed by a webhook while somebody edits the order in the
 * control panel is exactly the kind of thing that leaves one of two numbers wrong, and the wrong
 * one is always the cached one.
 *
 * `orderId` is nulled rather than cascaded when an order is deleted. The payment that settled an
 * invoice is a fact about money that happened; deleting the order does not unhappen it.
 */
class CreditEntry extends Model
{
    /** Goods supplied on terms. Increases what is owed. */
    public const TYPE_CHARGE = 'charge';

    /** Money received. Decreases what is owed. */
    public const TYPE_PAYMENT = 'payment';

    /** A credit note, a write-off, a correction. Either sign. */
    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPES = [self::TYPE_CHARGE, self::TYPE_PAYMENT, self::TYPE_ADJUSTMENT];

    public ?int $id = null;
    public ?int $companyId = null;
    public string $type = self::TYPE_CHARGE;
    public ?int $orderId = null;

    /** Signed. Positive increases the balance owed. */
    public float $amount = 0.0;

    public ?string $currency = null;
    public ?string $reference = null;
    public ?string $note = null;
    public ?DateTime $entryDate = null;
    public ?DateTime $dueDate = null;
    public ?int $createdBy = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /** How much of this charge is still outstanding. Set by the statement builder, not stored. */
    public ?float $openAmount = null;

    public function getIsCharge(): bool
    {
        return $this->amount > 0;
    }

    public function getIsOverdue(?DateTime $on = null): bool
    {
        if ($this->dueDate === null || !$this->getIsCharge()) {
            return false;
        }

        return ($on ?? new DateTime()) > $this->dueDate;
    }

    /** Days past due. Zero when not yet due or not a charge. */
    public function getDaysOverdue(?DateTime $on = null): int
    {
        if (!$this->getIsOverdue($on)) {
            return 0;
        }

        return (int)($on ?? new DateTime())->diff($this->dueDate)->format('%a');
    }

    public function getTypeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_PAYMENT => Craft::t('forklift', 'Payment'),
            self::TYPE_ADJUSTMENT => Craft::t('forklift', 'Adjustment'),
            default => Craft::t('forklift', 'Charge'),
        };
    }

    protected function defineRules(): array
    {
        return [
            [['companyId', 'type', 'amount'], 'required'],
            [['companyId', 'orderId', 'createdBy'], 'integer'],
            [['type'], 'in', 'range' => self::TYPES],
            [['amount'], 'number'],
            [['amount'], 'validateSign', 'skipOnEmpty' => false],
            [['currency'], 'string', 'max' => 3],
            [['reference', 'note', 'entryDate', 'dueDate'], 'safe'],
        ];
    }

    /**
     * A charge that reduces the balance, or a payment that increases it, is a sign error — almost
     * always a caller that passed an already-negative number to `Credit::recordPayment()`.
     * Adjustments may be either way, which is what makes them adjustments.
     */
    public function validateSign(string $attribute): void
    {
        if ($this->type === self::TYPE_CHARGE && $this->amount < 0) {
            $this->addError($attribute, Craft::t('forklift', 'A charge must be a positive amount.'));
        }

        if ($this->type === self::TYPE_PAYMENT && $this->amount > 0) {
            $this->addError($attribute, Craft::t('forklift', 'A payment must reduce the balance.'));
        }
    }
}
