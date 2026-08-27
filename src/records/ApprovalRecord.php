<?php

declare(strict_types=1);

namespace justinholtweb\forklift\records;

use craft\db\ActiveRecord;
use justinholtweb\forklift\db\Table;

/**
 * @property int $id
 * @property int $orderId
 * @property int $companyId
 * @property int|null $requesterId
 * @property int|null $approverId
 * @property string $status
 * @property string $amount
 * @property string|null $currency
 * @property string|null $reason
 * @property string|null $note
 * @property string|null $decisionNote
 * @property string|null $token
 * @property string $requestedDate
 * @property string|null $decisionDate
 * @property string|null $expiryDate
 */
class ApprovalRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::APPROVALS;
    }
}
