<?php

declare(strict_types=1);

namespace justinholtweb\forklift\records;

use craft\db\ActiveRecord;
use justinholtweb\forklift\db\Table;

/**
 * @property int $id
 * @property string|null $code
 * @property string $accountStatus
 * @property int|null $ownerId
 * @property int|null $termsId
 * @property string|null $creditLimit
 * @property bool $creditEnabled
 * @property bool $requiresPoNumber
 * @property string|null $approvalThreshold
 * @property bool $taxExempt
 * @property string|null $phone
 * @property string|null $website
 * @property string|null $taxId
 * @property string|null $notes
 */
class CompanyRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::COMPANIES;
    }
}
