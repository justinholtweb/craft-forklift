<?php

declare(strict_types=1);

namespace justinholtweb\forklift\records;

use craft\db\ActiveRecord;
use justinholtweb\forklift\db\Table;

/**
 * @property int $id
 * @property int $quoteId
 * @property int|null $purchasableId
 * @property string|null $sku
 * @property string|null $description
 * @property int $qty
 * @property string|null $listPrice
 * @property string $price
 * @property string|null $note
 * @property int|null $sortOrder
 */
class QuoteLineRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::QUOTELINES;
    }
}
