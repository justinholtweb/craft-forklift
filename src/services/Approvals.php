<?php

declare(strict_types=1);

namespace justinholtweb\forklift\services;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use justinholtweb\forklift\db\Table;
use justinholtweb\forklift\events\ApprovalEvent;
use justinholtweb\forklift\models\Approval;
use justinholtweb\forklift\models\Role;
use justinholtweb\forklift\Plugin;
use yii\base\Component;

/**
 * The approval queue: raising a request, deciding it, and letting it lapse.
 *
 * `Checkout` decides *whether* an order needs approval. This owns the record of one having been
 * asked for — which is a different lifetime, and the reason the two are separate services. The
 * question "does this need signing off" is answered afresh on every recalculation; the answer to
 * "who signed it off, when, and why was it asked" must never change once it has been written.
 *
 * ## Why an order and not a cart
 *
 * The approval hangs off the Commerce order id, and the order at that point is an **incomplete
 * cart**. That is deliberate. It means the approver sees the real thing — the real line items,
 * the real shipping, the real total — and it means approval hands back a cart the buyer can
 * simply check out. The alternative, snapshotting the basket into the approval, would produce an
 * approval for a basket that no longer exists.
 *
 * The consequence to watch is that the cart can *change* after approval. So the approved amount
 * is recorded, and {@see isStillValidFor()} refuses an approval whose order has grown — otherwise
 * a buyer could get £400 signed off and then add £4,000 to the same cart.
 */
class Approvals extends Component
{
    /** Cancelable. A request is about to be saved; `$approval` is built but has no id yet. */
    public const EVENT_BEFORE_REQUEST = 'beforeRequest';

    /** A request was saved and the approvers notified. */
    public const EVENT_AFTER_REQUEST = 'afterRequest';

    /** Cancelable. An approval is about to be approved, declined or cancelled — see `$status`. */
    public const EVENT_BEFORE_DECIDE = 'beforeDecide';

    /** An approval was approved, declined or cancelled. */
    public const EVENT_AFTER_DECIDE = 'afterDecide';

    /**
     * How much an approved cart may grow before the approval stops covering it.
     *
     * Not zero, because shipping and tax can move by pennies between the approval and the
     * checkout for entirely innocent reasons, and an approval that fails on a rounding difference
     * would be worse than useless. Anything a buyer could actually exploit is far above this.
     */
    public const TOLERANCE = 0.01;

    public function getApprovalById(int $id): ?Approval
    {
        $row = $this->_createQuery()->where(['id' => $id])->one();

        return $row ? $this->_toModel($row) : null;
    }

    public function getApprovalByToken(string $token): ?Approval
    {
        $row = $this->_createQuery()->where(['token' => $token])->one();

        return $row ? $this->_toModel($row) : null;
    }

    /** The live request for this order, if there is one. */
    public function getPendingForOrder(int $orderId): ?Approval
    {
        $row = $this->_createQuery()
            ->where(['orderId' => $orderId, 'status' => Approval::STATUS_PENDING])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        return $row ? $this->_toModel($row) : null;
    }

    /**
     * The most recent decided request for this order.
     *
     * Separate from the pending one because a declined approval has to keep blocking the cart —
     * "somebody said no" is not the same as "nobody has been asked".
     */
    public function getLatestForOrder(int $orderId): ?Approval
    {
        $row = $this->_createQuery()
            ->where(['orderId' => $orderId])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        return $row ? $this->_toModel($row) : null;
    }

    /** @return Approval[] */
    public function getPendingForCompany(int $companyId): array
    {
        $rows = $this->_createQuery()
            ->where(['companyId' => $companyId, 'status' => Approval::STATUS_PENDING])
            ->orderBy(['requestedDate' => SORT_ASC])
            ->all();

        return array_map($this->_toModel(...), $rows);
    }

    /** @return Approval[] */
    public function getAllPending(): array
    {
        $rows = $this->_createQuery()
            ->where(['status' => Approval::STATUS_PENDING])
            ->orderBy(['requestedDate' => SORT_ASC])
            ->all();

        return array_map($this->_toModel(...), $rows);
    }

    public function getPendingCount(): int
    {
        return (int)(new Query())
            ->from([Table::APPROVALS])
            ->where(['status' => Approval::STATUS_PENDING])
            ->count();
    }

    /**
     * Requests this user is entitled to decide.
     *
     * A person can approve for the companies they hold an approving role on, and — deliberately —
     * **including their own orders**, because in a two-person business the administrator is the
     * only approver there is and a rule forbidding self-approval would simply make the account
     * unusable. What stops it being meaningless is that the decision is recorded with a name
     * against it.
     *
     * @return Approval[]
     */
    public function getPendingForApprover(User $user): array
    {
        $companyIds = [];

        foreach (Plugin::getInstance()->members->getMembersByUserId((int)$user->id) as $member) {
            if (Role::canApprove($member->role)) {
                $companyIds[] = (int)$member->companyId;
            }
        }

        if ($companyIds === []) {
            return [];
        }

        $rows = $this->_createQuery()
            ->where(['status' => Approval::STATUS_PENDING, 'companyId' => $companyIds])
            ->orderBy(['requestedDate' => SORT_ASC])
            ->all();

        return array_map($this->_toModel(...), $rows);
    }

    public function canDecide(Approval $approval, User $user): bool
    {
        $member = Plugin::getInstance()->members->getMember((int)$approval->companyId, (int)$user->id);

        return $member !== null && Role::canApprove($member->role);
    }

    // Lifecycle
    // -------------------------------------------------------------------------

    /**
     * Ask for this order to be signed off.
     *
     * Idempotent: an order that already has a live request gets that request back rather than a
     * second one. A buyer pressing the button twice, or a form re-submitting, must not put two
     * questions in front of an approver.
     */
    public function request(Order $order, string $reason, ?string $note = null): ?Approval
    {
        if (!$order->id) {
            return null;
        }

        $existing = $this->getPendingForOrder((int)$order->id);

        if ($existing !== null) {
            return $existing;
        }

        $companyId = Plugin::getInstance()->orders->getCompanyIdForOrder((int)$order->id);

        if ($companyId === null) {
            return null;
        }

        $settings = Plugin::getInstance()->getSettings();
        $now = DateTimeHelper::currentUTCDateTime();

        $approval = new Approval([
            'orderId' => (int)$order->id,
            'companyId' => $companyId,
            'requesterId' => $order->getCustomer()?->id,
            'status' => Approval::STATUS_PENDING,
            'amount' => round((float)$order->getTotalPrice(), 2),
            'currency' => $order->currency,
            'reason' => $reason,
            'note' => $note,
            // 32 characters from randomString, not a UUID — a UUID is 36 and the column is
            // char(32), which truncates silently on a loose MySQL and refuses on a strict one.
            'token' => StringHelper::randomString(32),
            'requestedDate' => $now,
            'expiryDate' => $settings->approvalExpiryDays > 0
                ? (clone $now)->modify('+' . $settings->approvalExpiryDays . ' days')
                : null,
        ]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_REQUEST)) {
            $event = new ApprovalEvent(['approval' => $approval, 'order' => $order, 'note' => $note]);
            $this->trigger(self::EVENT_BEFORE_REQUEST, $event);

            if (!$event->isValid) {
                return null;
            }
        }

        if (!$this->saveApproval($approval)) {
            return null;
        }

        Plugin::getInstance()->orders->setValuesForOrder((int)$order->id, ['approvalId' => $approval->id]);

        if ($settings->notifyApprovers) {
            Plugin::getInstance()->notifications->approvalRequested($approval);
        }

        if ($this->hasEventHandlers(self::EVENT_AFTER_REQUEST)) {
            $this->trigger(self::EVENT_AFTER_REQUEST, new ApprovalEvent(['approval' => $approval, 'order' => $order, 'note' => $note]));
        }

        return $approval;
    }

    public function approve(Approval $approval, ?User $approver = null, ?string $note = null): bool
    {
        return $this->_decide($approval, Approval::STATUS_APPROVED, $approver, $note);
    }

    public function decline(Approval $approval, ?User $approver = null, ?string $note = null): bool
    {
        return $this->_decide($approval, Approval::STATUS_DECLINED, $approver, $note);
    }

    /**
     * Withdraw a request.
     *
     * What happens when a buyer edits the cart they submitted. The approval is cancelled rather
     * than deleted, so the approver's inbox link explains itself rather than 404ing.
     */
    public function cancel(Approval $approval, ?string $note = null): bool
    {
        return $this->_decide($approval, Approval::STATUS_CANCELLED, null, $note);
    }

    private function _decide(Approval $approval, string $status, ?User $approver, ?string $note): bool
    {
        if (!$approval->getIsPending()) {
            return false;
        }

        if ($this->hasEventHandlers(self::EVENT_BEFORE_DECIDE)) {
            $event = new ApprovalEvent([
                'approval' => $approval,
                'order' => $approval->getOrder(),
                'status' => $status,
                'approver' => $approver,
                'note' => $note,
            ]);
            $this->trigger(self::EVENT_BEFORE_DECIDE, $event);

            if (!$event->isValid) {
                return false;
            }
        }

        $approval->status = $status;
        $approval->approverId = $approver?->id;
        $approval->decisionNote = $note;
        $approval->decisionDate = DateTimeHelper::currentUTCDateTime();

        if (!$this->saveApproval($approval)) {
            return false;
        }

        if (Plugin::getInstance()->getSettings()->notifyRequester
            && in_array($status, [Approval::STATUS_APPROVED, Approval::STATUS_DECLINED], true)
        ) {
            Plugin::getInstance()->notifications->approvalDecided($approval);
        }

        if ($this->hasEventHandlers(self::EVENT_AFTER_DECIDE)) {
            $this->trigger(self::EVENT_AFTER_DECIDE, new ApprovalEvent([
                'approval' => $approval,
                'order' => $approval->getOrder(),
                'status' => $status,
                'approver' => $approver,
                'note' => $note,
            ]));
        }

        return true;
    }

    /**
     * Whether this approval still covers the order it was granted for.
     *
     * The cart is live between approval and checkout, so an approval for £400 must not let £4,400
     * through. A cart that has *shrunk* is fine — nobody needs re-authorising to spend less.
     */
    public function isStillValidFor(Approval $approval, Order $order): bool
    {
        if (!$approval->getIsApproved()) {
            return false;
        }

        return round((float)$order->getTotalPrice(), 2) <= $approval->amount + self::TOLERANCE;
    }

    public function saveApproval(Approval $approval, bool $runValidation = true): bool
    {
        if ($runValidation && !$approval->validate()) {
            return false;
        }

        $now = Db::prepareDateForDb(DateTimeHelper::currentUTCDateTime());
        $db = Craft::$app->getDb();

        $values = [
            'orderId' => $approval->orderId,
            'companyId' => $approval->companyId,
            'requesterId' => $approval->requesterId,
            'approverId' => $approval->approverId,
            'status' => $approval->status,
            'amount' => $approval->amount,
            'currency' => $approval->currency,
            'reason' => $approval->reason,
            'note' => $approval->note,
            'decisionNote' => $approval->decisionNote,
            'token' => $approval->token,
            'requestedDate' => Db::prepareDateForDb($approval->requestedDate ?? DateTimeHelper::currentUTCDateTime()),
            'decisionDate' => Db::prepareDateForDb($approval->decisionDate),
            'expiryDate' => Db::prepareDateForDb($approval->expiryDate),
            'dateUpdated' => $now,
        ];

        if ($approval->id) {
            $db->createCommand()->update(Table::APPROVALS, $values, ['id' => $approval->id])->execute();
        } else {
            $values['dateCreated'] = $now;
            $values['uid'] = StringHelper::UUID();
            $db->createCommand()->insert(Table::APPROVALS, $values)->execute();
            $approval->id = (int)$db->getLastInsertID();
        }

        return true;
    }

    /**
     * Expire requests nobody decided.
     *
     * An expired request is **not** an approval — it releases the cart back to the buyer with the
     * reason attached, and they can ask again. Run from garbage collection.
     *
     * @return int How many expired.
     */
    public function expireStale(): int
    {
        $now = Db::prepareDateForDb(DateTimeHelper::currentUTCDateTime());

        $ids = (new Query())
            ->select(['id'])
            ->from([Table::APPROVALS])
            ->where(['status' => Approval::STATUS_PENDING])
            ->andWhere(['not', ['expiryDate' => null]])
            ->andWhere(['<', 'expiryDate', $now])
            ->column();

        if ($ids === []) {
            return 0;
        }

        Craft::$app->getDb()->createCommand()
            ->update(Table::APPROVALS, [
                'status' => Approval::STATUS_EXPIRED,
                'decisionDate' => $now,
                'dateUpdated' => $now,
            ], ['id' => $ids])
            ->execute();

        return count($ids);
    }

    // Internals
    // -------------------------------------------------------------------------

    private function _createQuery(): Query
    {
        return (new Query())
            ->select([
                'id', 'orderId', 'companyId', 'requesterId', 'approverId', 'status', 'amount',
                'currency', 'reason', 'note', 'decisionNote', 'token', 'requestedDate',
                'decisionDate', 'expiryDate', 'dateCreated', 'dateUpdated', 'uid',
            ])
            ->from([Table::APPROVALS]);
    }

    private function _toModel(array $row): Approval
    {
        $row['id'] = (int)$row['id'];
        $row['orderId'] = (int)$row['orderId'];
        $row['companyId'] = (int)$row['companyId'];
        $row['requesterId'] = $row['requesterId'] !== null ? (int)$row['requesterId'] : null;
        $row['approverId'] = $row['approverId'] !== null ? (int)$row['approverId'] : null;
        $row['amount'] = (float)$row['amount'];
        $row['requestedDate'] = DateTimeHelper::toDateTime($row['requestedDate']) ?: null;
        $row['decisionDate'] = $row['decisionDate'] ? DateTimeHelper::toDateTime($row['decisionDate']) ?: null : null;
        $row['expiryDate'] = $row['expiryDate'] ? DateTimeHelper::toDateTime($row['expiryDate']) ?: null : null;
        $row['dateCreated'] = DateTimeHelper::toDateTime($row['dateCreated']) ?: null;
        $row['dateUpdated'] = DateTimeHelper::toDateTime($row['dateUpdated']) ?: null;

        return new Approval($row);
    }
}
