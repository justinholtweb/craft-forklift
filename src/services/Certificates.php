<?php

declare(strict_types=1);

namespace justinholtweb\forklift\services;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\elements\Address;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use justinholtweb\forklift\db\Table;
use justinholtweb\forklift\models\Certificate;
use justinholtweb\forklift\Plugin;
use yii\base\Component;

/**
 * Tax exemption certificates.
 *
 * **`exemptionFor()` is the only place the question "is this order tax exempt" is answered.** The
 * tax adjuster asks it, the control panel asks it, the buyer's portal asks it, and the order
 * record stores whichever certificate said yes — so an audit can be shown the exact document that
 * justified a zero-rated invoice.
 *
 * The rules are deliberately strict, because every one of them is a liability if it is loose:
 *
 * - the certificate must be **approved** by a human, not merely uploaded;
 * - it must not have **expired**;
 * - it must **cover the address** tax would otherwise be charged on — a Texas resale certificate
 *   does not exempt a delivery to Ohio;
 * - and the whole mechanism can be turned off (`applyTaxExemptions`), which turns Forklift into a
 *   record-keeper and leaves Commerce's tax alone.
 *
 * Nothing here ever *creates* an exemption automatically. A certificate arrives `pending`.
 */
class Certificates extends Component
{
    /** @var array<int, Certificate[]> */
    private array $_byCompany = [];

    /** @var array<string, Certificate|null> */
    private array $_resolved = [];

    /** @return Certificate[] */
    public function getCertificatesByCompanyId(int $companyId): array
    {
        if (!isset($this->_byCompany[$companyId])) {
            $rows = $this->_createQuery()
                ->where(['companyId' => $companyId])
                ->orderBy(['status' => SORT_ASC, 'expiryDate' => SORT_DESC, 'id' => SORT_DESC])
                ->all();

            $this->_byCompany[$companyId] = array_map($this->_toModel(...), $rows);
        }

        return $this->_byCompany[$companyId];
    }

    public function getCertificateById(int $id): ?Certificate
    {
        $row = $this->_createQuery()->where(['id' => $id])->one();

        return $row ? $this->_toModel($row) : null;
    }

    /**
     * The certificate that exempts this order, or null.
     *
     * Memoized per order for the request, because Commerce recalculates an order several times
     * within one save and the tax adjuster asks on each pass.
     */
    public function exemptionFor(Order $order): ?Certificate
    {
        if (!Plugin::getInstance()->getSettings()->applyTaxExemptions) {
            return null;
        }

        $key = ($order->id ?? 0) . ':' . ($order->number ?? '');

        if (array_key_exists($key, $this->_resolved)) {
            return $this->_resolved[$key];
        }

        return $this->_resolved[$key] = $this->_resolve($order);
    }

    private function _resolve(Order $order): ?Certificate
    {
        $companyId = $order->id ? Plugin::getInstance()->orders->getCompanyIdForOrder((int)$order->id) : null;

        // A cart that has not been saved yet has no Forklift row, but it does have a customer —
        // and a buyer part-way through checkout should see their exempt total, not a taxed one
        // that drops when they press the button.
        if ($companyId === null) {
            $companyId = Plugin::getInstance()->companies->getCurrentCompany()?->id;
        }

        if ($companyId === null) {
            return null;
        }

        $address = $this->_taxAddress($order);

        foreach ($this->getCertificatesByCompanyId($companyId) as $certificate) {
            if (!$certificate->getIsUsable()) {
                continue;
            }

            if ($certificate->coversAddress($address)) {
                return $certificate;
            }
        }

        return null;
    }

    /**
     * The address tax would be charged on.
     *
     * Shipping first, then billing, which is the order Commerce's own tax adjuster uses when the
     * store is configured for destination-based tax. A certificate is checked against the same
     * address the tax would have been charged against, or it would be exempting the wrong place.
     */
    private function _taxAddress(Order $order): ?Address
    {
        return $order->getShippingAddress() ?? $order->getBillingAddress();
    }

    // Writing
    // -------------------------------------------------------------------------

    public function saveCertificate(Certificate $certificate, bool $runValidation = true): bool
    {
        if ($runValidation && !$certificate->validate()) {
            return false;
        }

        $now = Db::prepareDateForDb(DateTimeHelper::currentUTCDateTime());
        $db = Craft::$app->getDb();

        $values = [
            'companyId' => $certificate->companyId,
            'name' => $certificate->name,
            'certificateNumber' => $certificate->certificateNumber,
            'countryCode' => $certificate->countryCode ?: null,
            'administrativeArea' => $certificate->administrativeArea ?: null,
            'issueDate' => Db::prepareDateForDb($certificate->issueDate),
            'expiryDate' => Db::prepareDateForDb($certificate->expiryDate),
            'assetId' => $certificate->assetId,
            'status' => $certificate->status,
            'note' => $certificate->note,
            'dateUpdated' => $now,
        ];

        if ($certificate->id) {
            $db->createCommand()->update(Table::CERTIFICATES, $values, ['id' => $certificate->id])->execute();
        } else {
            $values['dateCreated'] = $now;
            $values['uid'] = StringHelper::UUID();
            $db->createCommand()->insert(Table::CERTIFICATES, $values)->execute();
            $certificate->id = (int)$db->getLastInsertID();
        }

        $this->_clearCaches();
        $this->syncCompanyFlag((int)$certificate->companyId);

        return true;
    }

    public function deleteCertificateById(int $id): bool
    {
        $certificate = $this->getCertificateById($id);

        if ($certificate === null) {
            return false;
        }

        Craft::$app->getDb()->createCommand()->delete(Table::CERTIFICATES, ['id' => $id])->execute();
        $this->_clearCaches();
        $this->syncCompanyFlag((int)$certificate->companyId);

        return true;
    }

    public function approve(Certificate $certificate): bool
    {
        $certificate->status = Certificate::STATUS_APPROVED;

        return $this->saveCertificate($certificate, false);
    }

    public function reject(Certificate $certificate, ?string $note = null): bool
    {
        $certificate->status = Certificate::STATUS_REJECTED;

        if ($note !== null) {
            $certificate->note = $note;
        }

        return $this->saveCertificate($certificate, false);
    }

    /**
     * Keep the company's `taxExempt` flag honest.
     *
     * The flag exists so an element index can be filtered and a badge shown without loading every
     * certificate for every row. It is *derived*, never typed in — which is why it is written
     * here, on every certificate change, rather than being a field on the company edit screen
     * that could disagree with the documents on file.
     */
    public function syncCompanyFlag(int $companyId): void
    {
        $company = Plugin::getInstance()->companies->getCompanyById($companyId);

        if ($company === null) {
            return;
        }

        $exempt = false;

        foreach ($this->getCertificatesByCompanyId($companyId) as $certificate) {
            if ($certificate->getIsUsable()) {
                $exempt = true;
                break;
            }
        }

        if ($company->taxExempt !== $exempt) {
            $company->taxExempt = $exempt;
            Plugin::getInstance()->companies->saveCompany($company, false);
        }
    }

    // Housekeeping
    // -------------------------------------------------------------------------

    /**
     * Certificates that have expired, or are about to.
     *
     * Feeds the control-panel warning and the console command. `$withinDays` of zero means "only
     * the ones already expired".
     *
     * @return Certificate[]
     */
    public function getExpiring(int $withinDays = 30): array
    {
        $until = DateTimeHelper::currentUTCDateTime()->modify('+' . max(0, $withinDays) . ' days');

        $rows = $this->_createQuery()
            ->where(['status' => Certificate::STATUS_APPROVED])
            ->andWhere(['not', ['expiryDate' => null]])
            ->andWhere(['<', 'expiryDate', Db::prepareDateForDb($until)])
            ->orderBy(['expiryDate' => SORT_ASC])
            ->all();

        return array_map($this->_toModel(...), $rows);
    }

    /**
     * Re-derive every company's exemption flag.
     *
     * Run from garbage collection, because a certificate expiring is the passage of time rather
     * than an event anybody fires. Cheap: one query for the companies that could possibly have
     * changed, rather than a pass over every account.
     *
     * @return int How many companies changed.
     */
    public function syncExpired(): int
    {
        $now = Db::prepareDateForDb(DateTimeHelper::currentUTCDateTime());

        $companyIds = (new Query())
            ->select(['companyId'])
            ->distinct()
            ->from([Table::CERTIFICATES])
            ->where(['status' => Certificate::STATUS_APPROVED])
            ->andWhere(['not', ['expiryDate' => null]])
            ->andWhere(['<', 'expiryDate', $now])
            ->column();

        $changed = 0;

        foreach ($companyIds as $companyId) {
            $company = Plugin::getInstance()->companies->getCompanyById((int)$companyId);

            if ($company === null || !$company->taxExempt) {
                continue;
            }

            $before = $company->taxExempt;
            $this->syncCompanyFlag((int)$companyId);

            $after = Plugin::getInstance()->companies->getCompanyById((int)$companyId)?->taxExempt;

            if ($before !== $after) {
                $changed++;
            }
        }

        return $changed;
    }

    public function getTotalPending(): int
    {
        return (int)(new Query())
            ->from([Table::CERTIFICATES])
            ->where(['status' => Certificate::STATUS_PENDING])
            ->count();
    }

    public function clearCaches(): void
    {
        $this->_clearCaches();
    }

    // Internals
    // -------------------------------------------------------------------------

    private function _createQuery(): Query
    {
        return (new Query())
            ->select([
                'id', 'companyId', 'name', 'certificateNumber', 'countryCode', 'administrativeArea',
                'issueDate', 'expiryDate', 'assetId', 'status', 'note', 'dateCreated', 'dateUpdated', 'uid',
            ])
            ->from([Table::CERTIFICATES]);
    }

    private function _toModel(array $row): Certificate
    {
        $row['id'] = (int)$row['id'];
        $row['companyId'] = (int)$row['companyId'];
        $row['assetId'] = $row['assetId'] !== null ? (int)$row['assetId'] : null;
        $row['issueDate'] = $row['issueDate'] ? DateTimeHelper::toDateTime($row['issueDate']) ?: null : null;
        $row['expiryDate'] = $row['expiryDate'] ? DateTimeHelper::toDateTime($row['expiryDate']) ?: null : null;
        $row['dateCreated'] = DateTimeHelper::toDateTime($row['dateCreated']) ?: null;
        $row['dateUpdated'] = DateTimeHelper::toDateTime($row['dateUpdated']) ?: null;

        return new Certificate($row);
    }

    private function _clearCaches(): void
    {
        $this->_byCompany = [];
        $this->_resolved = [];
    }
}
