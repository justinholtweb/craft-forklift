<?php

declare(strict_types=1);

namespace justinholtweb\forklift\models;

use Craft;
use craft\base\Model;
use craft\elements\Address;
use craft\elements\Asset;
use craft\helpers\DateTimeHelper;
use DateTime;

/**
 * A tax exemption certificate held on file for a company.
 *
 * The thing a distributor is actually storing is a *scanned document with an expiry date* and a
 * jurisdiction it is good in — a US resale certificate is per state, a charity exemption is per
 * country, and an inter-company arrangement may be everywhere. So the scope is a country code and
 * an optional administrative area rather than a link to a Commerce tax zone: the certificate
 * outlives whatever zones the store happens to have configured this year, and matching an address
 * against a country and a state is something anybody can verify by eye.
 *
 * Nothing here is automatic. A certificate arrives `pending` and only an administrator moves it to
 * `approved`; an unapproved or expired certificate is worth exactly nothing at checkout. That is
 * the whole point of holding them — an audit asks who approved it and when.
 */
class Certificate extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /** Not stored — derived from `expiryDate`, because an expiry is a fact about time. */
    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];

    public ?int $id = null;
    public ?int $companyId = null;
    public ?string $name = null;
    public ?string $certificateNumber = null;

    /** Null means "everywhere", which is what a charity registration usually is. */
    public ?string $countryCode = null;

    /** A state, province or region code. Only meaningful alongside a country. */
    public ?string $administrativeArea = null;

    public ?DateTime $issueDate = null;
    public ?DateTime $expiryDate = null;
    public ?int $assetId = null;
    public string $status = self::STATUS_PENDING;
    public ?string $note = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    private ?Asset $_asset = null;

    public function __toString(): string
    {
        return (string)($this->name ?: $this->certificateNumber ?: Craft::t('forklift', 'Certificate'));
    }

    public function getAsset(): ?Asset
    {
        if ($this->_asset === null && $this->assetId) {
            $this->_asset = Craft::$app->getElements()->getElementById($this->assetId, Asset::class);
        }

        return $this->_asset;
    }

    public function getIsExpired(?DateTime $on = null): bool
    {
        if ($this->expiryDate === null) {
            return false;
        }

        return ($on ?? DateTimeHelper::currentUTCDateTime()) > $this->expiryDate;
    }

    /** Days until expiry. Negative once it has passed, null when it never expires. */
    public function getDaysUntilExpiry(): ?int
    {
        if ($this->expiryDate === null) {
            return null;
        }

        $now = DateTimeHelper::currentUTCDateTime();

        return (int)$now->diff($this->expiryDate)->format('%r%a');
    }

    /**
     * The status to *show*, which folds in the passage of time.
     *
     * `status` is what somebody decided; this is what is true today.
     */
    public function getEffectiveStatus(): string
    {
        if ($this->status === self::STATUS_APPROVED && $this->getIsExpired()) {
            return self::STATUS_EXPIRED;
        }

        return $this->status;
    }

    /** Whether this certificate can exempt anything at all right now. */
    public function getIsUsable(): bool
    {
        return $this->getEffectiveStatus() === self::STATUS_APPROVED;
    }

    /**
     * Whether this certificate covers the given address.
     *
     * A certificate with no country covers everywhere. A certificate with a country but no
     * administrative area covers the whole country. A certificate naming both must match both —
     * a Texas resale certificate does not exempt a delivery to Ohio, and quietly letting it would
     * be the plugin creating a tax liability on the merchant's behalf.
     */
    public function coversAddress(?Address $address): bool
    {
        if ($this->countryCode === null || $this->countryCode === '') {
            return true;
        }

        if ($address === null) {
            return false;
        }

        if (strcasecmp((string)$address->countryCode, $this->countryCode) !== 0) {
            return false;
        }

        if ($this->administrativeArea === null || $this->administrativeArea === '') {
            return true;
        }

        return strcasecmp((string)$address->administrativeArea, $this->administrativeArea) === 0;
    }

    public function getScopeLabel(): string
    {
        if (!$this->countryCode) {
            return Craft::t('forklift', 'All jurisdictions');
        }

        if (!$this->administrativeArea) {
            return $this->countryCode;
        }

        return $this->administrativeArea . ', ' . $this->countryCode;
    }

    public function getStatusLabel(): string
    {
        return match ($this->getEffectiveStatus()) {
            self::STATUS_APPROVED => Craft::t('forklift', 'Approved'),
            self::STATUS_REJECTED => Craft::t('forklift', 'Rejected'),
            self::STATUS_EXPIRED => Craft::t('forklift', 'Expired'),
            default => Craft::t('forklift', 'Pending review'),
        };
    }

    protected function defineRules(): array
    {
        return [
            [['companyId', 'name', 'status'], 'required'],
            [['companyId', 'assetId'], 'integer'],
            [['status'], 'in', 'range' => self::STATUSES],
            [['countryCode'], 'string', 'max' => 10],
            [['administrativeArea'], 'string', 'max' => 64],
            [['expiryDate'], 'validateExpiry', 'skipOnEmpty' => false],
            [['certificateNumber', 'issueDate', 'note'], 'safe'],
        ];
    }

    public function validateExpiry(string $attribute): void
    {
        if ($this->issueDate !== null && $this->expiryDate !== null && $this->expiryDate < $this->issueDate) {
            $this->addError($attribute, Craft::t('forklift', 'The expiry date must be after the issue date.'));
        }
    }
}
