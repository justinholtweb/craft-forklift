<?php

declare(strict_types=1);

namespace justinholtweb\forklift\records;

use craft\db\ActiveRecord;
use justinholtweb\forklift\db\Table;

/**
 * @property int $id
 * @property int $priceListId
 * @property string $targetType
 * @property int|null $targetId
 * @property string|null $sku
 * @property int $minQty
 * @property string $priceType
 * @property string $amount
 */
class PriceListEntryRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::PRICELIST_ENTRIES;
    }
}
