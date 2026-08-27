<?php

declare(strict_types=1);

namespace justinholtweb\forklift\records;

use craft\db\ActiveRecord;
use justinholtweb\forklift\db\Table;

/**
 * @property int $id
 * @property int $companyId
 * @property int $userId
 * @property string $role
 * @property string|null $spendLimit
 * @property bool $requiresApproval
 * @property bool $isDefault
 */
class MemberRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::MEMBERS;
    }
}
