<?php

declare(strict_types=1);

namespace justinholtweb\forklift\services;

use Craft;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use justinholtweb\forklift\db\Table;
use justinholtweb\forklift\models\PriceList;
use justinholtweb\forklift\models\PriceListEntry;
use yii\base\Component;
use yii\db\Expression;

/**
 * Price lists and their entries: storage, assignment, and import/export.
 *
 * `Pricing` does the deciding; this does the keeping. The split matters because a price list is
 * a *document* with thousands of rows that somebody maintains in a spreadsheet, and the
 * operations it needs — replace the whole body, add a batch, export what is there — have nothing
 * to do with the question "what does this customer pay for this thing".
 *
 * Entry writes are batched. A ten-thousand-row import that inserted one row at a time would take
 * minutes and leave a half-written list behind if anything went wrong, so a replace runs inside a
 * transaction and uses `batchInsert`.
 */
class PriceLists extends Component
{
    /** @var PriceList[]|null */
    private ?array $_all = null;

    /** @var array<int, PriceListEntry[]> */
    private array $_entriesByList = [];

    /** @var array<int, int[]> */
    private array $_companyIdsByList = [];

    // Lists
    // -------------------------------------------------------------------------

    /** @return PriceList[] */
    public function getAllPriceLists(): array
    {
        if ($this->_all === null) {
            $rows = $this->_createQuery()
                ->orderBy(['priority' => SORT_DESC, 'id' => SORT_ASC])
                ->all();

            $this->_all = array_map($this->_toModel(...), $rows);
        }

        return $this->_all;
    }

    public function getPriceListById(int $id): ?PriceList
    {
        foreach ($this->getAllPriceLists() as $list) {
            if ($list->id === $id) {
                return $list;
            }
        }

        return null;
    }

    public function getPriceListByHandle(string $handle): ?PriceList
    {
        foreach ($this->getAllPriceLists() as $list) {
            if ($list->handle === $handle) {
                return $list;
            }
        }

        return null;
    }

    /**
     * The lists that could price something for this company, most specific first.
     *
     * Sorted by priority descending and then by id ascending, and the tiebreak is not decoration:
     * without it, two lists at the same priority would be applied in whatever order the database
     * felt like returning, and a merchant would see a price change for no reason between two page
     * loads.
     *
     * @return PriceList[]
     */
    public function getActivePriceListsForCompany(?int $companyId): array
    {
        $lists = [];

        foreach ($this->getAllPriceLists() as $list) {
            if (!$list->getIsActive()) {
                continue;
            }

            if ($list->allCompanies) {
                $lists[] = $list;
                continue;
            }

            if ($companyId !== null && $list->appliesToCompany($companyId)) {
                $lists[] = $list;
            }
        }

        return $lists;
    }

    public function savePriceList(PriceList $list, bool $runValidation = true): bool
    {
        if ($runValidation && !$list->validate()) {
            return false;
        }

        $now = Db::prepareDateForDb(DateTimeHelper::currentUTCDateTime());
        $db = Craft::$app->getDb();

        $values = [
            'storeId' => $list->storeId,
            'name' => $list->name,
            'handle' => $list->handle,
            'description' => $list->description,
            'priority' => $list->priority,
            'allCompanies' => $list->allCompanies,
            'dateFrom' => Db::prepareDateForDb($list->dateFrom),
            'dateTo' => Db::prepareDateForDb($list->dateTo),
            'enabled' => $list->enabled,
            'dateUpdated' => $now,
        ];

        if ($list->id) {
            $db->createCommand()->update(Table::PRICELISTS, $values, ['id' => $list->id])->execute();
        } else {
            $values['dateCreated'] = $now;
            $values['uid'] = StringHelper::UUID();
            $db->createCommand()->insert(Table::PRICELISTS, $values)->execute();
            $list->id = (int)$db->getLastInsertID();
        }

        // Null means the caller never touched the assignments. An absent form field must not
        // unassign every customer from a list — that is a very quiet way to put a hundred trade
        // accounts back on retail prices.
        $companyIds = $list->getCompanyIds();

        if ($companyIds !== null) {
            $this->setCompaniesForPriceList((int)$list->id, $companyIds);
        }

        $this->_clearCaches();

        return true;
    }

    public function deletePriceListById(int $id): bool
    {
        Craft::$app->getDb()->createCommand()->delete(Table::PRICELISTS, ['id' => $id])->execute();
        $this->_clearCaches();

        return true;
    }

    public function getTotalPriceLists(): int
    {
        return (int)(new Query())->from([Table::PRICELISTS])->count();
    }

    // Assignment
    // -------------------------------------------------------------------------

    /** @return int[] */
    public function getCompanyIdsByPriceListId(int $priceListId): array
    {
        if (!isset($this->_companyIdsByList[$priceListId])) {
            $this->_companyIdsByList[$priceListId] = array_map('intval', (new Query())
                ->select(['companyId'])
                ->from([Table::PRICELIST_COMPANIES])
                ->where(['priceListId' => $priceListId])
                ->column());
        }

        return $this->_companyIdsByList[$priceListId];
    }

    /** @return int[] */
    public function getPriceListIdsByCompanyId(int $companyId): array
    {
        return array_map('intval', (new Query())
            ->select(['priceListId'])
            ->from([Table::PRICELIST_COMPANIES])
            ->where(['companyId' => $companyId])
            ->column());
    }

    /** @param int[] $companyIds */
    public function setCompaniesForPriceList(int $priceListId, array $companyIds): void
    {
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(DateTimeHelper::currentUTCDateTime());
        $companyIds = array_values(array_unique(array_map('intval', $companyIds)));

        $transaction = $db->beginTransaction();

        try {
            $db->createCommand()->delete(Table::PRICELIST_COMPANIES, ['priceListId' => $priceListId])->execute();

            if ($companyIds !== []) {
                $rows = array_map(
                    static fn(int $companyId) => [$priceListId, $companyId, $now, $now, StringHelper::UUID()],
                    $companyIds,
                );

                $db->createCommand()->batchInsert(
                    Table::PRICELIST_COMPANIES,
                    ['priceListId', 'companyId', 'dateCreated', 'dateUpdated', 'uid'],
                    $rows,
                )->execute();
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        unset($this->_companyIdsByList[$priceListId]);
    }

    // Entries
    // -------------------------------------------------------------------------

    /** @return PriceListEntry[] */
    public function getEntriesByPriceListId(int $priceListId): array
    {
        if (!isset($this->_entriesByList[$priceListId])) {
            $rows = $this->_entryQuery()
                ->where(['priceListId' => $priceListId])
                ->orderBy(['targetType' => SORT_ASC, 'targetId' => SORT_ASC, 'minQty' => SORT_ASC])
                ->all();

            $this->_entriesByList[$priceListId] = array_map($this->_toEntry(...), $rows);
        }

        return $this->_entriesByList[$priceListId];
    }

    /**
     * Every entry from these lists that could price this purchasable, in one query.
     *
     * The whole reason `Pricing` is fast enough to run inside a cart recalculation: one query per
     * cart rather than one per line, filtered in SQL to the four target shapes that could
     * possibly match rather than reading a ten-thousand-row list into memory to throw it away.
     *
     * @param int[] $priceListIds
     * @return PriceListEntry[]
     */
    public function getMatchingEntries(array $priceListIds, int $purchasableId, ?string $sku, ?int $productId, ?int $purchasableTypeId): array
    {
        if ($priceListIds === []) {
            return [];
        }

        $conditions = [
            'or',
            ['and', ['targetType' => PriceListEntry::TARGET_PURCHASABLE], ['targetId' => $purchasableId]],
            ['targetType' => PriceListEntry::TARGET_ALL],
        ];

        if ($sku !== null && $sku !== '') {
            $conditions[] = ['and', ['targetType' => PriceListEntry::TARGET_PURCHASABLE], ['targetId' => null], ['sku' => $sku]];
        }

        if ($productId !== null) {
            $conditions[] = ['and', ['targetType' => PriceListEntry::TARGET_PRODUCT], ['targetId' => $productId]];
        }

        if ($purchasableTypeId !== null) {
            $conditions[] = ['and', ['targetType' => PriceListEntry::TARGET_PURCHASABLE_TYPE], ['targetId' => $purchasableTypeId]];
        }

        $rows = $this->_entryQuery()
            ->where(['priceListId' => $priceListIds])
            ->andWhere($conditions)
            ->orderBy(['minQty' => SORT_ASC])
            ->all();

        return array_map($this->_toEntry(...), $rows);
    }

    public function saveEntry(PriceListEntry $entry, bool $runValidation = true): bool
    {
        if ($runValidation && !$entry->validate()) {
            return false;
        }

        $now = Db::prepareDateForDb(DateTimeHelper::currentUTCDateTime());
        $db = Craft::$app->getDb();

        $values = [
            'priceListId' => $entry->priceListId,
            'targetType' => $entry->targetType,
            'targetId' => $entry->targetId,
            'sku' => $entry->sku,
            'minQty' => $entry->minQty,
            'priceType' => $entry->priceType,
            'amount' => $entry->amount,
            'dateUpdated' => $now,
        ];

        if ($entry->id) {
            $db->createCommand()->update(Table::PRICELIST_ENTRIES, $values, ['id' => $entry->id])->execute();
        } else {
            $values['dateCreated'] = $now;
            $values['uid'] = StringHelper::UUID();
            $db->createCommand()->insert(Table::PRICELIST_ENTRIES, $values)->execute();
            $entry->id = (int)$db->getLastInsertID();
        }

        unset($this->_entriesByList[(int)$entry->priceListId]);

        return true;
    }

    public function deleteEntryById(int $id): bool
    {
        $priceListId = (int)(new Query())
            ->select(['priceListId'])
            ->from([Table::PRICELIST_ENTRIES])
            ->where(['id' => $id])
            ->scalar();

        Craft::$app->getDb()->createCommand()->delete(Table::PRICELIST_ENTRIES, ['id' => $id])->execute();
        unset($this->_entriesByList[$priceListId]);

        return true;
    }

    /**
     * Replace a list's entire body, in one transaction.
     *
     * What an import is: the spreadsheet is the truth and the list should end up matching it. A
     * merge would leave last quarter's discontinued lines behind, priced, and quietly sellable.
     *
     * @param PriceListEntry[] $entries
     */
    public function replaceEntries(int $priceListId, array $entries): int
    {
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(DateTimeHelper::currentUTCDateTime());
        $transaction = $db->beginTransaction();

        try {
            $db->createCommand()->delete(Table::PRICELIST_ENTRIES, ['priceListId' => $priceListId])->execute();

            $rows = [];

            foreach ($entries as $entry) {
                $rows[] = [
                    $priceListId,
                    $entry->targetType,
                    $entry->targetId,
                    $entry->sku,
                    $entry->minQty,
                    $entry->priceType,
                    $entry->amount,
                    $now,
                    $now,
                    StringHelper::UUID(),
                ];
            }

            if ($rows !== []) {
                // Chunked, because a single INSERT with fifty thousand value tuples exceeds
                // MySQL's max_allowed_packet on a default configuration and fails at the end of a
                // long import rather than the start.
                foreach (array_chunk($rows, 500) as $chunk) {
                    $db->createCommand()->batchInsert(
                        Table::PRICELIST_ENTRIES,
                        ['priceListId', 'targetType', 'targetId', 'sku', 'minQty', 'priceType', 'amount', 'dateCreated', 'dateUpdated', 'uid'],
                        $chunk,
                    )->execute();
                }
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        unset($this->_entriesByList[$priceListId]);

        return count($entries);
    }

    public function getEntryCount(int $priceListId): int
    {
        return (int)(new Query())
            ->from([Table::PRICELIST_ENTRIES])
            ->where(['priceListId' => $priceListId])
            ->count();
    }

    /**
     * Fill in `targetId` for entries that were imported by SKU alone.
     *
     * Run after an import, and again after a catalogue rebuild. Resolving on read instead would
     * mean a SKU lookup per line per cart recalculation, which is the difference between a fast
     * store and a slow one.
     *
     * @return int How many rows were resolved.
     */
    public function resolveSkus(int $priceListId): int
    {
        $rows = (new Query())
            ->select(['id', 'sku'])
            ->from([Table::PRICELIST_ENTRIES])
            ->where([
                'and',
                ['priceListId' => $priceListId, 'targetType' => PriceListEntry::TARGET_PURCHASABLE, 'targetId' => null],
                ['not', ['sku' => null]],
            ])
            ->all();

        if ($rows === []) {
            return 0;
        }

        $skus = array_column($rows, 'sku');

        $purchasables = (new Query())
            ->select(['id', 'sku'])
            ->from(['{{%commerce_purchasables}}'])
            ->where(['sku' => $skus])
            ->all();

        $bySku = [];

        foreach ($purchasables as $purchasable) {
            $bySku[(string)$purchasable['sku']] = (int)$purchasable['id'];
        }

        $db = Craft::$app->getDb();
        $resolved = 0;

        foreach ($rows as $row) {
            $id = $bySku[(string)$row['sku']] ?? null;

            if ($id === null) {
                continue;
            }

            $db->createCommand()->update(Table::PRICELIST_ENTRIES, ['targetId' => $id], ['id' => $row['id']])->execute();
            $resolved++;
        }

        unset($this->_entriesByList[$priceListId]);

        return $resolved;
    }

    // Internals
    // -------------------------------------------------------------------------

    private function _createQuery(): Query
    {
        return (new Query())
            ->select([
                'id', 'storeId', 'name', 'handle', 'description', 'priority', 'allCompanies',
                'dateFrom', 'dateTo', 'enabled', 'dateCreated', 'dateUpdated', 'uid',
            ])
            ->from([Table::PRICELISTS]);
    }

    private function _entryQuery(): Query
    {
        return (new Query())
            ->select([
                'id', 'priceListId', 'targetType', 'targetId', 'sku', 'minQty', 'priceType',
                'amount', 'dateCreated', 'dateUpdated', 'uid',
            ])
            ->from([Table::PRICELIST_ENTRIES]);
    }

    private function _toModel(array $row): PriceList
    {
        $row['id'] = (int)$row['id'];
        $row['priority'] = (int)$row['priority'];
        $row['allCompanies'] = (bool)$row['allCompanies'];
        $row['enabled'] = (bool)$row['enabled'];
        $row['dateFrom'] = $row['dateFrom'] ? DateTimeHelper::toDateTime($row['dateFrom'], false, false) ?: null : null;
        $row['dateTo'] = $row['dateTo'] ? DateTimeHelper::toDateTime($row['dateTo'], false, false) ?: null : null;
        $row['dateCreated'] = DateTimeHelper::toDateTime($row['dateCreated']) ?: null;
        $row['dateUpdated'] = DateTimeHelper::toDateTime($row['dateUpdated']) ?: null;

        return new PriceList($row);
    }

    private function _toEntry(array $row): PriceListEntry
    {
        $row['id'] = (int)$row['id'];
        $row['priceListId'] = (int)$row['priceListId'];
        $row['targetId'] = $row['targetId'] !== null ? (int)$row['targetId'] : null;
        $row['minQty'] = (int)$row['minQty'];
        $row['amount'] = (float)$row['amount'];
        $row['dateCreated'] = DateTimeHelper::toDateTime($row['dateCreated']) ?: null;
        $row['dateUpdated'] = DateTimeHelper::toDateTime($row['dateUpdated']) ?: null;

        return new PriceListEntry($row);
    }

    private function _clearCaches(): void
    {
        $this->_all = null;
        $this->_entriesByList = [];
        $this->_companyIdsByList = [];
    }
}
