<?php

declare(strict_types=1);

namespace justinholtweb\forklift\records;

use craft\db\ActiveRecord;
use justinholtweb\forklift\db\Table;

/**
 * @property int $id
 * @property int $companyId
 * @property string $name
 * @property string|null $certificateNumber
 * @property string|null $countryCode
 * @property string|null $administrativeArea
 * @property string|null $issueDate
 * @property string|null $expiryDate
 * @property int|null $assetId
 * @property string $status
 * @property string|null $note
 */
class CertificateRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::CERTIFICATES;
    }
}
