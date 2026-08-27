<?php

declare(strict_types=1);

namespace justinholtweb\forklift\records;

use craft\db\ActiveRecord;
use justinholtweb\forklift\db\Table;

/**
 * @property int $id
 * @property int $companyId
 * @property string $type
 * @property int|null $orderId
 * @property string $amount
 * @property string|null $currency
 * @property string|null $reference
 * @property string|null $note
 * @property string $entryDate
 * @property string|null $dueDate
 * @property int|null $createdBy
 */
class CreditEntryRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::CREDIT_ENTRIES;
    }
}
