<?php

declare(strict_types=1);

namespace justinholtweb\forklift\services;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\forklift\db\Table;
use justinholtweb\forklift\events\CreditEntryEvent;
use justinholtweb\forklift\models\CreditEntry;
use justinholtweb\forklift\models\Statement;
use justinholtweb\forklift\Plugin;
use yii\base\Component;

/**
 * The credit ledger: what a company owes, what is overdue, and by how long.
 *
 * **`balanceFor()` is the only place a balance is derived, and it derives it by summing the
 * ledger.** No running total on the company row. A cached balance is a number that can be wrong,
 * and the moment it goes wrong — a refund landing from a webhook while somebody edits the same
 * order in the control panel — is exactly the moment somebody is on the phone about it.
 *
 * Charges are positive, payments negative, and the sign lives in the row rather than being
 * inferred from the type. That is what lets the balance be a `SUM` and the aging be one pass.
 *
 * ## Allocation
 *
 * Payments are applied to charges **oldest first**, which is what every accounts-receivable
 * ledger does in the absence of an instruction to the contrary. Nothing is stored about the
 * allocation: it is recomputed for each statement, so a payment entered late, or backdated,
 * re-ages the account correctly instead of leaving a decade of fossilised allocations behind.
 */
class Credit extends Component
{
    /** Cancelable. A ledger entry — charge, payment or adjustment — is about to be written. */
    public const EVENT_BEFORE_SAVE_ENTRY = 'beforeSaveEntry';

    /** A ledger entry was written; the company's balance has changed. */
    public const EVENT_AFTER_SAVE_ENTRY = 'afterSaveEntry';

    /** Cancelable. A ledger entry is about to be deleted. */
    public const EVENT_BEFORE_DELETE_ENTRY = 'beforeDeleteEntry';

    /** A ledger entry was deleted. */
    public const EVENT_AFTER_DELETE_ENTRY = 'afterDeleteEntry';

    /** @var array<int, float> */
    private array $_balances = [];

    // Reading
    // -------------------------------------------------------------------------

    /** What this company owes right now. Positive means they owe money. */
    public function balanceFor(int $companyId): float
    {
        if (!isset($this->_balances[$companyId])) {
            $sum = (new Query())
                ->from([Table::CREDIT_ENTRIES])
                ->where(['companyId' => $companyId])
                ->sum('[[amount]]');

            $this->_balances[$companyId] = round((float)$sum, 2);
        }

        return $this->_balances[$companyId];
    }

    /**
     * Credit still available to spend, or null when the account has no limit set.
     *
     * Null is "unlimited" and is deliberately different from a limit of zero, which means "may
     * not order on account at all".
     */
    public function availableFor(int $companyId): ?float
    {
        $company = Plugin::getInstance()->companies->getCompanyById($companyId);

        if ($company === null || !$company->creditEnabled || $company->creditLimit === null) {
            return null;
        }

        return round($company->creditLimit - $this->balanceFor($companyId), 2);
    }

    /**
     * Whether an order of this size can go on account.
     *
     * Asked by `Checkout` and by the purchase-order gateway, so a gateway that offers itself and
     * a checkout that refuses cannot disagree.
     */
    public function canCharge(int $companyId, float $amount): bool
    {
        $available = $this->availableFor($companyId);

        return $available === null || $amount <= $available;
    }

    /** @return CreditEntry[] Oldest first. */
    public function getEntries(int $companyId, ?DateTime $from = null, ?DateTime $to = null): array
    {
        $query = $this->_createQuery()
            ->where(['companyId' => $companyId])
            ->orderBy(['entryDate' => SORT_ASC, 'id' => SORT_ASC]);

        if ($from !== null) {
            $query->andWhere(['>=', 'entryDate', Db::prepareDateForDb($from)]);
        }

        if ($to !== null) {
            $query->andWhere(['<=', 'entryDate', Db::prepareDateForDb($to)]);
        }

        return array_map($this->_toModel(...), $query->all());
    }

    public function getEntryById(int $id): ?CreditEntry
    {
        $row = $this->_createQuery()->where(['id' => $id])->one();

        return $row ? $this->_toModel($row) : null;
    }

    /** The ledger entry raised for an order, if there is one. */
    public function getChargeForOrder(int $orderId): ?CreditEntry
    {
        $row = $this->_createQuery()
            ->where(['orderId' => $orderId, 'type' => CreditEntry::TYPE_CHARGE])
            ->one();

        return $row ? $this->_toModel($row) : null;
    }

    // Writing
    // -------------------------------------------------------------------------

    public function saveEntry(CreditEntry $entry, bool $runValidation = true): bool
    {
        if ($runValidation && !$entry->validate()) {
            return false;
        }

        $isNew = !$entry->id;

        if ($this->hasEventHandlers(self::EVENT_BEFORE_SAVE_ENTRY)) {
            $event = new CreditEntryEvent(['entry' => $entry, 'isNew' => $isNew]);
            $this->trigger(self::EVENT_BEFORE_SAVE_ENTRY, $event);

            if (!$event->isValid) {
                return false;
            }
        }

        $entry->entryDate ??= DateTimeHelper::currentUTCDateTime();

        $now = Db::prepareDateForDb(DateTimeHelper::currentUTCDateTime());
        $db = Craft::$app->getDb();

        $values = [
            'companyId' => $entry->companyId,
            'type' => $entry->type,
            'orderId' => $entry->orderId,
            'amount' => $entry->amount,
            'currency' => $entry->currency,
            'reference' => $entry->reference,
            'note' => $entry->note,
            'entryDate' => Db::prepareDateForDb($entry->entryDate),
            'dueDate' => Db::prepareDateForDb($entry->dueDate),
            'createdBy' => $entry->createdBy,
            'dateUpdated' => $now,
        ];

        if ($entry->id) {
            $db->createCommand()->update(Table::CREDIT_ENTRIES, $values, ['id' => $entry->id])->execute();
        } else {
            $values['dateCreated'] = $now;
            $values['uid'] = StringHelper::UUID();
            $db->createCommand()->insert(Table::CREDIT_ENTRIES, $values)->execute();
            $entry->id = (int)$db->getLastInsertID();
        }

        unset($this->_balances[(int)$entry->companyId]);

        if ($this->hasEventHandlers(self::EVENT_AFTER_SAVE_ENTRY)) {
            $this->trigger(self::EVENT_AFTER_SAVE_ENTRY, new CreditEntryEvent(['entry' => $entry, 'isNew' => $isNew]));
        }

        return true;
    }

    public function deleteEntryById(int $id): bool
    {
        $entry = $this->getEntryById($id);

        if ($entry === null) {
            return false;
        }

        if ($this->hasEventHandlers(self::EVENT_BEFORE_DELETE_ENTRY)) {
            $event = new CreditEntryEvent(['entry' => $entry]);
            $this->trigger(self::EVENT_BEFORE_DELETE_ENTRY, $event);

            if (!$event->isValid) {
                return false;
            }
        }

        Craft::$app->getDb()->createCommand()->delete(Table::CREDIT_ENTRIES, ['id' => $id])->execute();
        unset($this->_balances[(int)$entry->companyId]);

        if ($this->hasEventHandlers(self::EVENT_AFTER_DELETE_ENTRY)) {
            $this->trigger(self::EVENT_AFTER_DELETE_ENTRY, new CreditEntryEvent(['entry' => $entry]));
        }

        return true;
    }

    /**
     * Raise a charge for an order placed on terms.
     *
     * **Idempotent.** Order completion can fire more than once — a retried webhook, a status
     * change that re-saves the order, an administrator marking it complete by hand — and charging
     * the same invoice twice would be a genuine financial error rather than an untidy row. The
     * order's existing charge is the key.
     */
    public function chargeOrder(Order $order, ?int $companyId = null): ?CreditEntry
    {
        $companyId ??= Plugin::getInstance()->orders->getCompanyIdForOrder((int)$order->id);

        if ($companyId === null || !$order->id) {
            return null;
        }

        $existing = $this->getChargeForOrder((int)$order->id);

        if ($existing !== null) {
            return $existing;
        }

        $terms = Plugin::getInstance()->orders->getTermsForOrder((int)$order->id);
        $entryDate = $order->dateOrdered ?? DateTimeHelper::currentUTCDateTime();

        $entry = new CreditEntry([
            'companyId' => $companyId,
            'type' => CreditEntry::TYPE_CHARGE,
            'orderId' => (int)$order->id,
            'amount' => round((float)$order->getTotalPrice(), 2),
            'currency' => $order->currency,
            'reference' => $order->reference ?: $order->number,
            'entryDate' => $entryDate,
            'dueDate' => $terms?->dueDate($entryDate),
        ]);

        return $this->saveEntry($entry) ? $entry : null;
    }

    /**
     * Record money received.
     *
     * Takes a positive amount and stores it negative, because every caller — a CP form, a console
     * command, a bank import — thinks in "we received £4,000", and asking each of them to
     * remember the sign is asking for one of them to forget.
     */
    public function recordPayment(int $companyId, float $amount, ?string $reference = null, ?DateTime $date = null, ?int $orderId = null): ?CreditEntry
    {
        $entry = new CreditEntry([
            'companyId' => $companyId,
            'type' => CreditEntry::TYPE_PAYMENT,
            'orderId' => $orderId,
            'amount' => -abs(round($amount, 2)),
            'reference' => $reference,
            'entryDate' => $date ?? DateTimeHelper::currentUTCDateTime(),
            'createdBy' => Craft::$app->getUser()->getId(),
        ]);

        return $this->saveEntry($entry) ? $entry : null;
    }

    /** A credit note, a write-off, a correction. Sign is the caller's to choose. */
    public function recordAdjustment(int $companyId, float $amount, ?string $note = null, ?DateTime $date = null): ?CreditEntry
    {
        $entry = new CreditEntry([
            'companyId' => $companyId,
            'type' => CreditEntry::TYPE_ADJUSTMENT,
            'amount' => round($amount, 2),
            'note' => $note,
            'entryDate' => $date ?? DateTimeHelper::currentUTCDateTime(),
            'createdBy' => Craft::$app->getUser()->getId(),
        ]);

        return $this->saveEntry($entry) ? $entry : null;
    }

    // Statements
    // -------------------------------------------------------------------------

    /**
     * Build a statement for a period.
     *
     * The opening balance is everything before `$from`, so a statement for one month is a
     * self-contained document that reconciles: opening, movements, closing.
     */
    public function statement(int $companyId, ?DateTime $from = null, ?DateTime $to = null): Statement
    {
        $company = Plugin::getInstance()->companies->getCompanyById($companyId);
        $to ??= DateTimeHelper::currentUTCDateTime();
        $from ??= (clone $to)->modify('-3 months');

        $opening = (float)(new Query())
            ->from([Table::CREDIT_ENTRIES])
            ->where(['companyId' => $companyId])
            ->andWhere(['<', 'entryDate', Db::prepareDateForDb($from)])
            ->sum('[[amount]]');

        $entries = $this->getEntries($companyId, $from, $to);

        $statement = new Statement([
            'company' => $company,
            'from' => $from,
            'to' => $to,
            'openingBalance' => round($opening, 2),
            'creditLimit' => $company?->creditEnabled ? $company->creditLimit : null,
        ]);

        $movement = 0.0;

        foreach ($entries as $entry) {
            $movement += $entry->amount;

            if ($entry->type === CreditEntry::TYPE_CHARGE) {
                $statement->totalCharges += $entry->amount;
            } elseif ($entry->type === CreditEntry::TYPE_PAYMENT) {
                $statement->totalPayments += abs($entry->amount);
            } else {
                $statement->totalAdjustments += $entry->amount;
            }
        }

        $statement->entries = $entries;
        $statement->closingBalance = round($statement->openingBalance + $movement, 2);
        $statement->totalCharges = round($statement->totalCharges, 2);
        $statement->totalPayments = round($statement->totalPayments, 2);
        $statement->totalAdjustments = round($statement->totalAdjustments, 2);
        $statement->currency = $entries[0]->currency ?? null;

        [$statement->aging, $statement->agingLabels] = $this->aging($companyId, $to);

        return $statement;
    }

    /**
     * Age the account: how much of the outstanding balance is how overdue.
     *
     * Reads the **whole** ledger regardless of the statement period, because an invoice from
     * eighteen months ago is still ninety-plus days overdue whichever month somebody happens to
     * be printing.
     *
     * Payments are allocated oldest-charge-first. Anything left over after every charge is
     * settled is a credit on the account, and it reduces the current bucket rather than being
     * dropped — an account in credit should read as being in credit.
     *
     * @return array{0: array<int, float>, 1: array<int, string>}
     */
    public function aging(int $companyId, ?DateTime $on = null): array
    {
        $on ??= DateTimeHelper::currentUTCDateTime();
        $buckets = Plugin::getInstance()->getSettings()->agingBuckets;

        $totals = [0 => 0.0];
        $labels = [0 => Craft::t('forklift', 'Current')];

        foreach ($buckets as $i => $days) {
            $totals[$days] = 0.0;
            $next = $buckets[$i + 1] ?? null;

            $labels[$days] = $next === null
                ? Craft::t('forklift', '{days}+ days', ['days' => $days])
                : Craft::t('forklift', '{from}–{to} days', ['from' => $days, 'to' => $next - 1]);
        }

        $entries = $this->getEntries($companyId);

        /** @var CreditEntry[] $charges */
        $charges = [];
        $credit = 0.0;

        foreach ($entries as $entry) {
            if ($entry->amount > 0) {
                $entry->openAmount = $entry->amount;
                $charges[] = $entry;
            } else {
                $credit += abs($entry->amount);
            }
        }

        foreach ($charges as $charge) {
            if ($credit <= 0) {
                break;
            }

            $applied = min($credit, (float)$charge->openAmount);
            $charge->openAmount -= $applied;
            $credit -= $applied;
        }

        foreach ($charges as $charge) {
            if ($charge->openAmount === null || $charge->openAmount <= 0.004) {
                continue;
            }

            $overdue = $charge->getDaysOverdue($on);
            $bucket = 0;

            foreach ($buckets as $days) {
                if ($overdue >= $days) {
                    $bucket = $days;
                }
            }

            $totals[$bucket] += $charge->openAmount;
        }

        // Money left after every charge is settled is a credit balance, which belongs in the
        // current column as a negative rather than disappearing.
        if ($credit > 0.004) {
            $totals[0] -= $credit;
        }

        foreach ($totals as $key => $value) {
            $totals[$key] = round($value, 2);
        }

        return [$totals, $labels];
    }

    /**
     * Companies over their credit limit right now.
     *
     * One grouped query rather than a balance lookup per company, because this feeds a control
     * panel screen that a credit controller refreshes all morning.
     *
     * @return array<int, array{companyId: int, balance: float, creditLimit: float}>
     */
    public function getCompaniesOverLimit(): array
    {
        $ledger = (new Query())
            ->select(['companyId', 'balance' => 'SUM([[amount]])'])
            ->from([Table::CREDIT_ENTRIES])
            ->groupBy(['companyId']);

        $rows = (new Query())
            ->select([
                'companyId' => 'c.id',
                'balance' => 'COALESCE(l.balance, 0)',
                'creditLimit' => 'c.creditLimit',
            ])
            ->from(['c' => Table::COMPANIES])
            ->leftJoin(['l' => $ledger], '[[l.companyId]] = [[c.id]]')
            ->where(['c.creditEnabled' => true])
            ->andWhere(['not', ['c.creditLimit' => null]])
            ->andWhere(['>', 'COALESCE(l.balance, 0)', new \yii\db\Expression('[[c.creditLimit]]')])
            ->all();

        return array_map(static fn(array $row) => [
            'companyId' => (int)$row['companyId'],
            'balance' => round((float)$row['balance'], 2),
            'creditLimit' => round((float)$row['creditLimit'], 2),
        ], $rows);
    }

    public function getTotalCompaniesWithCreditLimits(): int
    {
        return (int)(new Query())
            ->from([Table::COMPANIES])
            ->where(['creditEnabled' => true])
            ->count();
    }

    public function clearCaches(): void
    {
        $this->_balances = [];
    }

    // Internals
    // -------------------------------------------------------------------------

    private function _createQuery(): Query
    {
        return (new Query())
            ->select([
                'id', 'companyId', 'type', 'orderId', 'amount', 'currency', 'reference', 'note',
                'entryDate', 'dueDate', 'createdBy', 'dateCreated', 'dateUpdated', 'uid',
            ])
            ->from([Table::CREDIT_ENTRIES]);
    }

    private function _toModel(array $row): CreditEntry
    {
        $row['id'] = (int)$row['id'];
        $row['companyId'] = (int)$row['companyId'];
        $row['orderId'] = $row['orderId'] !== null ? (int)$row['orderId'] : null;
        $row['createdBy'] = $row['createdBy'] !== null ? (int)$row['createdBy'] : null;
        $row['amount'] = (float)$row['amount'];
        $row['entryDate'] = DateTimeHelper::toDateTime($row['entryDate']) ?: null;
        $row['dueDate'] = $row['dueDate'] ? DateTimeHelper::toDateTime($row['dueDate']) ?: null : null;
        $row['dateCreated'] = DateTimeHelper::toDateTime($row['dateCreated']) ?: null;
        $row['dateUpdated'] = DateTimeHelper::toDateTime($row['dateUpdated']) ?: null;

        return new CreditEntry($row);
    }
}
