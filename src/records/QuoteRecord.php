<?php

declare(strict_types=1);

namespace justinholtweb\forklift\records;

use craft\db\ActiveRecord;
use justinholtweb\forklift\db\Table;

/**
 * @property int $id
 * @property string $number
 * @property int|null $storeId
 * @property int|null $companyId
 * @property int|null $requesterId
 * @property string|null $email
 * @property string $status
 * @property string|null $currency
 * @property string|null $reference
 * @property string|null $message
 * @property string|null $internalNote
 * @property float|string|null $shippingCost
 * @property float|string|null $discount
 * @property string|null $expiryDate
 * @property string|null $sentDate
 * @property string|null $respondedDate
 * @property int|null $orderId
 * @property string|null $cartNumber
 */
class QuoteRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::QUOTES;
    }
}
