<?php

declare(strict_types=1);

namespace justinholtweb\forklift\records;

use craft\db\ActiveRecord;
use justinholtweb\forklift\db\Table;

/**
 * @property int $id
 * @property int $orderId
 * @property int|null $companyId
 * @property int|null $memberId
 * @property string|null $poNumber
 * @property int|null $termsId
 * @property string|null $dueDate
 * @property int|null $approvalId
 * @property bool $approvalBypassed
 * @property int|null $quoteId
 * @property string|null $priceListIds
 * @property int|null $taxExemptCertificateId
 */
class OrderRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::ORDERS;
    }
}
