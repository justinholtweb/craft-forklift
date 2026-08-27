<?php

declare(strict_types=1);

namespace justinholtweb\forklift\elements\db;

use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use justinholtweb\forklift\db\Table;
use justinholtweb\forklift\elements\Company;

/**
 * Element query for companies.
 *
 * Every parameter is typed `mixed` rather than narrowly, because Craft builds an element-index
 * source from its `criteria` array with `Craft::configure()`, which assigns **straight to a public
 * property** when one exists instead of calling the setter of the same name. A source declaring
 * `['accountStatus' => 'hold']` against a `?array` property throws "Cannot assign string to
 * property … of type ?array" and takes the whole index down — but only for the one source nobody
 * happened to click. Normalising happens in {@see beforePrepare()}.
 *
 * @method Company[] all($db = null)
 * @method Company|null one($db = null)
 * @method Company|null nth(int $n, ?\yii\db\Connection $db = null)
 */
class CompanyQuery extends ElementQuery
{
    public mixed $code = null;
    public mixed $accountStatus = null;
    public mixed $termsId = null;
    public mixed $ownerId = null;
    public ?bool $creditEnabled = null;
    public ?bool $taxExempt = null;
    public ?bool $requiresPoNumber = null;

    /** Accounts whose ledger balance is past their credit limit. */
    public ?bool $overCreditLimit = null;

    /** Companies this user is a member of. */
    public mixed $memberUserId = null;

    /** Companies holding a certificate that is approved and has not expired. */
    public ?bool $hasUsableCertificate = null;

    protected array $defaultOrderBy = ['forklift_companies.code' => SORT_ASC];

    public function code(mixed $value): static
    {
        $this->code = $value;

        return $this;
    }

    public function accountStatus(mixed $value): static
    {
        $this->accountStatus = $value;

        return $this;
    }

    public function termsId(mixed $value): static
    {
        $this->termsId = $value;

        return $this;
    }

    public function ownerId(mixed $value): static
    {
        $this->ownerId = $value;

        return $this;
    }

    public function creditEnabled(?bool $value = true): static
    {
        $this->creditEnabled = $value;

        return $this;
    }

    public function taxExempt(?bool $value = true): static
    {
        $this->taxExempt = $value;

        return $this;
    }

    public function requiresPoNumber(?bool $value = true): static
    {
        $this->requiresPoNumber = $value;

        return $this;
    }

    public function overCreditLimit(?bool $value = true): static
    {
        $this->overCreditLimit = $value;

        return $this;
    }

    public function memberUserId(mixed $value): static
    {
        $this->memberUserId = $value;

        return $this;
    }

    public function hasUsableCertificate(?bool $value = true): static
    {
        $this->hasUsableCertificate = $value;

        return $this;
    }

    protected function beforePrepare(): bool
    {
        if (!parent::beforePrepare()) {
            return false;
        }

        $this->joinElementTable('forklift_companies');

        $this->query->addSelect([
            'forklift_companies.code',
            'forklift_companies.accountStatus',
            'forklift_companies.ownerId',
            'forklift_companies.termsId',
            'forklift_companies.creditLimit',
            'forklift_companies.creditEnabled',
            'forklift_companies.requiresPoNumber',
            'forklift_companies.approvalThreshold',
            'forklift_companies.taxExempt',
            'forklift_companies.phone',
            'forklift_companies.website',
            'forklift_companies.taxId',
            'forklift_companies.notes',
        ]);

        if ($this->code !== null) {
            $this->subQuery->andWhere(Db::parseParam('forklift_companies.code', $this->code));
        }

        if ($this->accountStatus !== null) {
            $this->subQuery->andWhere(Db::parseParam('forklift_companies.accountStatus', $this->accountStatus));
        }

        if ($this->termsId !== null) {
            $this->subQuery->andWhere(Db::parseParam('forklift_companies.termsId', $this->termsId));
        }

        if ($this->ownerId !== null) {
            $this->subQuery->andWhere(Db::parseParam('forklift_companies.ownerId', $this->ownerId));
        }

        if ($this->creditEnabled !== null) {
            $this->subQuery->andWhere(['forklift_companies.creditEnabled' => $this->creditEnabled]);
        }

        if ($this->taxExempt !== null) {
            $this->subQuery->andWhere(['forklift_companies.taxExempt' => $this->taxExempt]);
        }

        if ($this->requiresPoNumber !== null) {
            $this->subQuery->andWhere(['forklift_companies.requiresPoNumber' => $this->requiresPoNumber]);
        }

        if ($this->memberUserId !== null) {
            $this->subQuery->andWhere([
                'forklift_companies.id' => (new Query())
                    ->select(['companyId'])
                    ->from([Table::MEMBERS])
                    ->where(Db::parseParam('userId', $this->memberUserId)),
            ]);
        }

        if ($this->hasUsableCertificate !== null) {
            $sub = (new Query())
                ->select(['companyId'])
                ->from([Table::CERTIFICATES])
                ->where(['status' => 'approved'])
                ->andWhere([
                    'or',
                    ['expiryDate' => null],
                    ['>=', 'expiryDate', Db::prepareDateForDb(new \DateTime('now', new \DateTimeZone('UTC')))],
                ]);

            $this->subQuery->andWhere([
                $this->hasUsableCertificate ? 'in' : 'not in',
                'forklift_companies.id',
                $sub,
            ]);
        }

        if ($this->overCreditLimit !== null) {
            // The balance is a SUM over the ledger and is never cached on the company, so this is
            // a grouped sub-select rather than a column comparison. COALESCE because an account
            // that has never traded has no rows at all, and NULL > limit is not false, it is null.
            $ledger = (new Query())
                ->select(['companyId', 'balance' => 'SUM([[amount]])'])
                ->from([Table::CREDIT_ENTRIES])
                ->groupBy(['companyId']);

            $this->subQuery
                ->leftJoin(['forklift_ledger' => $ledger], '[[forklift_ledger.companyId]] = [[forklift_companies.id]]')
                ->andWhere([
                    $this->overCreditLimit ? '>' : '<=',
                    new \yii\db\Expression('COALESCE([[forklift_ledger.balance]], 0)'),
                    new \yii\db\Expression('COALESCE([[forklift_companies.creditLimit]], 0)'),
                ])
                ->andWhere(['forklift_companies.creditEnabled' => true]);
        }

        return true;
    }

    /**
     * The account statuses, which sit *inside* Craft's enabled status rather than beside it.
     *
     * An account that is on hold is still an enabled element — hold is a credit decision, not a
     * publishing one — so each of these adds the account status to the enabled condition rather
     * than replacing it.
     */
    protected function statusCondition(string $status): mixed
    {
        $enabled = parent::statusCondition(Company::STATUS_ENABLED);

        return match ($status) {
            Company::STATUS_ACTIVE => array_merge($enabled, ['forklift_companies.accountStatus' => Company::STATUS_ACTIVE]),
            Company::STATUS_HOLD => array_merge($enabled, ['forklift_companies.accountStatus' => Company::STATUS_HOLD]),
            Company::STATUS_CLOSED => array_merge($enabled, ['forklift_companies.accountStatus' => Company::STATUS_CLOSED]),
            default => parent::statusCondition($status),
        };
    }
}
