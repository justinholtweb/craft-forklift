<?php

declare(strict_types=1);

namespace justinholtweb\forklift\services;

use Craft;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use justinholtweb\forklift\db\Table;
use justinholtweb\forklift\models\Term;
use yii\base\Component;

/**
 * Payment terms.
 *
 * Small, boring and entirely in the database rather than in project config — which is the same
 * call Commerce makes for its own shipping methods and discounts, for the same reason: terms are
 * an operational record that a credit controller edits on a Tuesday afternoon, not schema that
 * should arrive by deployment.
 */
class Terms extends Component
{
    /** @var Term[]|null */
    private ?array $_all = null;

    /** The terms every store gets on install, so a fresh account has something to be put on. */
    public const DEFAULTS = [
        ['name' => 'Due on receipt', 'handle' => 'dueOnReceipt', 'netDays' => 0],
        ['name' => 'Net 14', 'handle' => 'net14', 'netDays' => 14],
        ['name' => 'Net 30', 'handle' => 'net30', 'netDays' => 30],
        ['name' => 'Net 60', 'handle' => 'net60', 'netDays' => 60],
        ['name' => '2/10 Net 30', 'handle' => 'twoTenNet30', 'netDays' => 30, 'discountPercent' => 2.0, 'discountDays' => 10],
    ];

    /** @return Term[] */
    public function getAllTerms(): array
    {
        if ($this->_all === null) {
            $rows = $this->_createQuery()->orderBy(['sortOrder' => SORT_ASC, 'netDays' => SORT_ASC])->all();
            $this->_all = array_map($this->_toModel(...), $rows);
        }

        return $this->_all;
    }

    /** @return Term[] */
    public function getEnabledTerms(): array
    {
        return array_values(array_filter($this->getAllTerms(), static fn(Term $term) => $term->enabled));
    }

    public function getTermById(int $id): ?Term
    {
        foreach ($this->getAllTerms() as $term) {
            if ($term->id === $id) {
                return $term;
            }
        }

        return null;
    }

    public function getTermByHandle(string $handle): ?Term
    {
        foreach ($this->getAllTerms() as $term) {
            if ($term->handle === $handle) {
                return $term;
            }
        }

        return null;
    }

    /** @return array<int, array{label: string, value: string}> */
    public function getTermOptions(bool $includeBlank = true): array
    {
        $options = $includeBlank
            ? [['label' => Craft::t('forklift', 'Prepay — no terms'), 'value' => '']]
            : [];

        foreach ($this->getEnabledTerms() as $term) {
            $options[] = ['label' => $term->name, 'value' => (string)$term->id];
        }

        return $options;
    }

    public function saveTerm(Term $term, bool $runValidation = true): bool
    {
        if ($runValidation && !$term->validate()) {
            return false;
        }

        $now = Db::prepareDateForDb(DateTimeHelper::currentUTCDateTime());
        $db = Craft::$app->getDb();

        $values = [
            'storeId' => $term->storeId,
            'name' => $term->name,
            'handle' => $term->handle,
            'netDays' => $term->netDays,
            'discountPercent' => $term->discountPercent,
            'discountDays' => $term->discountDays,
            'description' => $term->description,
            'enabled' => $term->enabled,
            'sortOrder' => $term->sortOrder,
            'dateUpdated' => $now,
        ];

        if ($term->id) {
            $db->createCommand()->update(Table::TERMS, $values, ['id' => $term->id])->execute();
        } else {
            $values['dateCreated'] = $now;
            $values['uid'] = StringHelper::UUID();
            $db->createCommand()->insert(Table::TERMS, $values)->execute();
            $term->id = (int)$db->getLastInsertID();
        }

        $this->_all = null;

        return true;
    }

    /**
     * Delete a term.
     *
     * The foreign keys null the reference on companies and orders rather than cascading, so an
     * invoice raised on Net 30 keeps its due date after somebody tidies up the terms list. It
     * just no longer says which terms produced it.
     */
    public function deleteTermById(int $id): bool
    {
        Craft::$app->getDb()->createCommand()->delete(Table::TERMS, ['id' => $id])->execute();
        $this->_all = null;

        return true;
    }

    /**
     * Seed the default terms.
     *
     * Called from `afterInstall()` rather than from the migration: a migration's writes run
     * before the plugin's own row is guaranteed to exist, and anything that reads the plugin back
     * during them gets a surprise.
     */
    public function installDefaults(): void
    {
        if ($this->getAllTerms() !== []) {
            return;
        }

        $sortOrder = 0;

        foreach (self::DEFAULTS as $config) {
            $term = new Term($config);
            $term->sortOrder = ++$sortOrder;
            $this->saveTerm($term, false);
        }
    }

    private function _createQuery(): Query
    {
        return (new Query())
            ->select([
                'id', 'storeId', 'name', 'handle', 'netDays', 'discountPercent', 'discountDays',
                'description', 'enabled', 'sortOrder', 'dateCreated', 'dateUpdated', 'uid',
            ])
            ->from([Table::TERMS]);
    }

    private function _toModel(array $row): Term
    {
        $row['id'] = (int)$row['id'];
        $row['netDays'] = (int)$row['netDays'];
        $row['discountPercent'] = $row['discountPercent'] !== null ? (float)$row['discountPercent'] : null;
        $row['discountDays'] = $row['discountDays'] !== null ? (int)$row['discountDays'] : null;
        $row['enabled'] = (bool)$row['enabled'];
        $row['sortOrder'] = $row['sortOrder'] !== null ? (int)$row['sortOrder'] : null;
        $row['dateCreated'] = DateTimeHelper::toDateTime($row['dateCreated']) ?: null;
        $row['dateUpdated'] = DateTimeHelper::toDateTime($row['dateUpdated']) ?: null;

        return new Term($row);
    }
}
