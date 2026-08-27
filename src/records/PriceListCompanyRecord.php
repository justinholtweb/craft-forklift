<?php

declare(strict_types=1);

namespace justinholtweb\forklift\records;

use craft\db\ActiveRecord;
use justinholtweb\forklift\db\Table;

/**
 * @property int $id
 * @property int $priceListId
 * @property int $companyId
 */
class PriceListCompanyRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::PRICELIST_COMPANIES;
    }
}
