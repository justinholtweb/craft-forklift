<?php

declare(strict_types=1);

namespace justinholtweb\forklift\records;

use craft\db\ActiveRecord;
use justinholtweb\forklift\db\Table;

/**
 * @property int $id
 * @property int|null $storeId
 * @property string $name
 * @property string $handle
 * @property string|null $description
 * @property int $priority
 * @property bool $allCompanies
 * @property string|null $dateFrom
 * @property string|null $dateTo
 * @property bool $enabled
 */
class PriceListRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::PRICELISTS;
    }
}
