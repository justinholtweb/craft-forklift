<?php

declare(strict_types=1);

namespace justinholtweb\forklift\services;

use Craft;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use justinholtweb\forklift\db\Table;
use justinholtweb\forklift\models\Member;
use justinholtweb\forklift\models\Role;
use justinholtweb\forklift\Plugin;
use yii\base\Component;

/**
 * Memberships: who buys for which company, in what role, up to what.
 *
 * The one rule worth stating in code rather than in documentation is that **a company must never
 * be left with no administrator**. An account whose last admin was demoted or removed cannot add
 * anybody, cannot approve anything, and needs a support call to fix — so the removal is refused
 * here rather than being discovered later. It is the sort of thing that only ever happens by
 * accident, which is exactly why it needs a guard.
 */
class Members extends Component
{
    /** @var array<string, Member|null> */
    private array $_byPair = [];

    /** @var array<int, Member[]> */
    private array $_byCompany = [];

    public function getMemberById(int $id): ?Member
    {
        $row = $this->_createQuery()->where(['id' => $id])->one();

        return $row ? $this->_toModel($row) : null;
    }

    public function getMember(int $companyId, int $userId): ?Member
    {
        $key = $companyId . ':' . $userId;

        if (!array_key_exists($key, $this->_byPair)) {
            $row = $this->_createQuery()
                ->where(['companyId' => $companyId, 'userId' => $userId])
                ->one();

            $this->_byPair[$key] = $row ? $this->_toModel($row) : null;
        }

        return $this->_byPair[$key];
    }

    /** @return Member[] */
    public function getMembersByCompanyId(int $companyId): array
    {
        if (!isset($this->_byCompany[$companyId])) {
            $rows = $this->_createQuery()
                ->where(['companyId' => $companyId])
                ->orderBy(['role' => SORT_ASC, 'id' => SORT_ASC])
                ->all();

            $this->_byCompany[$companyId] = array_map($this->_toModel(...), $rows);
        }

        return $this->_byCompany[$companyId];
    }

    /** @return Member[] */
    public function getMembersByUserId(int $userId): array
    {
        $rows = $this->_createQuery()
            ->where(['userId' => $userId])
            ->orderBy(['isDefault' => SORT_DESC, 'id' => SORT_ASC])
            ->all();

        return array_map($this->_toModel(...), $rows);
    }

    /**
     * Everyone who can decide an approval for this company.
     *
     * @return Member[]
     */
    public function getApproversForCompany(int $companyId): array
    {
        return array_values(array_filter(
            $this->getMembersByCompanyId($companyId),
            static fn(Member $member) => Role::canApprove($member->role),
        ));
    }

    public function saveMember(Member $member, bool $runValidation = true): bool
    {
        if ($runValidation && !$member->validate()) {
            return false;
        }

        if (!$this->_wouldLeaveAnAdmin($member)) {
            $member->addError('role', Craft::t('forklift', 'A company needs at least one administrator.'));

            return false;
        }

        $now = Db::prepareDateForDb(DateTimeHelper::currentUTCDateTime());
        $db = Craft::$app->getDb();

        $values = [
            'companyId' => $member->companyId,
            'userId' => $member->userId,
            'role' => $member->role,
            'spendLimit' => $member->spendLimit,
            'requiresApproval' => $member->requiresApproval,
            'isDefault' => $member->isDefault,
            'dateUpdated' => $now,
        ];

        if ($member->id) {
            $db->createCommand()->update(Table::MEMBERS, $values, ['id' => $member->id])->execute();
        } else {
            $values['dateCreated'] = $now;
            $values['uid'] = \craft\helpers\StringHelper::UUID();
            $db->createCommand()->insert(Table::MEMBERS, $values)->execute();
            $member->id = (int)$db->getLastInsertID();
        }

        // Only one default per *person*, not per company: the flag answers "which of my accounts
        // does my cart belong to", so two of them would be two answers to one question.
        if ($member->isDefault) {
            $db->createCommand()
                ->update(Table::MEMBERS, ['isDefault' => false], [
                    'and',
                    ['userId' => $member->userId],
                    ['not', ['id' => $member->id]],
                ])
                ->execute();
        }

        $this->_clearCaches();

        return true;
    }

    public function deleteMemberById(int $id): bool
    {
        $member = $this->getMemberById($id);

        if ($member === null) {
            return false;
        }

        if (Role::canManageMembers($member->role) && $this->_countAdmins((int)$member->companyId, $id) === 0) {
            return false;
        }

        Craft::$app->getDb()->createCommand()->delete(Table::MEMBERS, ['id' => $id])->execute();
        $this->_clearCaches();

        return true;
    }

    /**
     * Add somebody to a company, or update them if they are already on it.
     *
     * The common path from every direction — the CP screen, the front-end invite, a console
     * command and an import — so "already a member" is an update rather than a duplicate-key
     * error a caller has to catch.
     */
    public function addUserToCompany(int $companyId, int $userId, string $role = Role::BUYER, ?float $spendLimit = null): Member|false
    {
        $member = $this->getMember($companyId, $userId) ?? new Member([
            'companyId' => $companyId,
            'userId' => $userId,
        ]);

        $member->role = $role;
        $member->spendLimit = $spendLimit;

        // The first person on an account is its administrator whatever the caller asked for.
        // An account created with a single buyer on it and nobody able to administer it is the
        // most common way to end up needing support.
        if ($this->getMembersByCompanyId($companyId) === []) {
            $member->role = Role::ADMIN;
        }

        return $this->saveMember($member) ? $member : false;
    }

    public function getTotalMembers(): int
    {
        return (int)(new Query())->from([Table::MEMBERS])->count();
    }

    /**
     * Whether saving this member would still leave the company an administrator.
     *
     * True for a brand-new company with no members at all — the first save is what creates the
     * administrator, and refusing it would make companies impossible to staff.
     */
    private function _wouldLeaveAnAdmin(Member $member): bool
    {
        if (Role::canManageMembers($member->role)) {
            return true;
        }

        $existing = $member->id ? $this->getMemberById($member->id) : null;

        // Not currently an admin, so this save cannot be removing the last one.
        if ($existing === null || !Role::canManageMembers($existing->role)) {
            return true;
        }

        return $this->_countAdmins((int)$member->companyId, (int)$member->id) > 0;
    }

    private function _countAdmins(int $companyId, ?int $excludeId = null): int
    {
        $query = (new Query())
            ->from([Table::MEMBERS])
            ->where(['companyId' => $companyId, 'role' => Role::ADMIN]);

        if ($excludeId !== null) {
            $query->andWhere(['not', ['id' => $excludeId]]);
        }

        return (int)$query->count();
    }

    private function _createQuery(): Query
    {
        return (new Query())
            ->select([
                'id', 'companyId', 'userId', 'role', 'spendLimit', 'requiresApproval',
                'isDefault', 'dateCreated', 'dateUpdated', 'uid',
            ])
            ->from([Table::MEMBERS]);
    }

    private function _toModel(array $row): Member
    {
        // A blank decimal column is "no limit", which is not the same as zero — casting the null
        // straight to float would turn "no limit" into "may spend nothing" for every buyer.
        $row['spendLimit'] = $row['spendLimit'] !== null ? (float)$row['spendLimit'] : null;
        $row['requiresApproval'] = (bool)$row['requiresApproval'];
        $row['isDefault'] = (bool)$row['isDefault'];
        $row['companyId'] = (int)$row['companyId'];
        $row['userId'] = (int)$row['userId'];
        $row['id'] = (int)$row['id'];
        $row['dateCreated'] = DateTimeHelper::toDateTime($row['dateCreated']) ?: null;
        $row['dateUpdated'] = DateTimeHelper::toDateTime($row['dateUpdated']) ?: null;

        return new Member($row);
    }

    private function _clearCaches(): void
    {
        $this->_byPair = [];
        $this->_byCompany = [];
    }
}
