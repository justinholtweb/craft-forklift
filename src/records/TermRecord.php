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
 * @property int $netDays
 * @property string|null $discountPercent
 * @property int|null $discountDays
 * @property string|null $description
 * @property bool $enabled
 * @property int|null $sortOrder
 */
class TermRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::TERMS;
    }
}
