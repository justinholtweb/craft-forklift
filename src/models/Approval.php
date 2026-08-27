<?php

declare(strict_types=1);

namespace justinholtweb\forklift\models;

use Craft;
use craft\base\Model;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\UrlHelper;
use DateTime;
use justinholtweb\forklift\elements\Company;
use justinholtweb\forklift\Plugin;

/**
 * One request for somebody to sign off one order.
 *
 * The *reason* is recorded at request time and never recomputed, because the company's threshold
 * can be changed the next morning and an audit trail that changes with the settings is not an
 * audit trail. Same for `amount`: what the order was worth when it was submitted.
 *
 * The token exists so an approver can decide from their inbox without signing in. It is 32
 * characters from `randomString()` — deliberately not a UUID, which is 36 and would be silently
 * truncated by the `char(32)` column on a loose MySQL and refused on a strict one.
 *
 * @property-read Order|null $order
 * @property-read Company|null $company
 */
class Approval extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_DECLINED,
        self::STATUS_CANCELLED,
        self::STATUS_EXPIRED,
    ];

    /** Why this order needed a signature. Recorded, never recomputed. */
    public const REASON_OVER_THRESHOLD = 'overThreshold';
    public const REASON_OVER_SPEND_LIMIT = 'overSpendLimit';
    public const REASON_MEMBER_ALWAYS = 'memberAlways';

    public ?int $id = null;
    public ?int $orderId = null;
    public ?int $companyId = null;
    public ?int $requesterId = null;
    public ?int $approverId = null;
    public string $status = self::STATUS_PENDING;
    public float $amount = 0.0;
    public ?string $currency = null;
    public ?string $reason = null;
    public ?string $note = null;
    public ?string $decisionNote = null;
    public ?string $token = null;
    public ?DateTime $requestedDate = null;
    public ?DateTime $decisionDate = null;
    public ?DateTime $expiryDate = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    private ?Order $_order = null;
    private ?Company $_company = null;

    public function getOrder(): ?Order
    {
        if ($this->_order === null && $this->orderId) {
            $this->_order = Commerce::getInstance()?->getOrders()->getOrderById($this->orderId);
        }

        return $this->_order;
    }

    public function getCompany(): ?Company
    {
        if ($this->_company === null && $this->companyId) {
            $this->_company = Plugin::getInstance()->companies->getCompanyById($this->companyId);
        }

        return $this->_company;
    }

    public function getRequester(): ?User
    {
        return $this->requesterId ? Craft::$app->getUsers()->getUserById($this->requesterId) : null;
    }

    public function getApprover(): ?User
    {
        return $this->approverId ? Craft::$app->getUsers()->getUserById($this->approverId) : null;
    }

    /** The status to show, folding in an expiry that has quietly passed. */
    public function getEffectiveStatus(): string
    {
        if ($this->status === self::STATUS_PENDING && $this->getIsExpired()) {
            return self::STATUS_EXPIRED;
        }

        return $this->status;
    }

    public function getIsExpired(?DateTime $on = null): bool
    {
        if ($this->expiryDate === null) {
            return false;
        }

        return ($on ?? DateTimeHelper::currentUTCDateTime()) > $this->expiryDate;
    }

    public function getIsPending(): bool
    {
        return $this->getEffectiveStatus() === self::STATUS_PENDING;
    }

    public function getIsApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function getReasonLabel(): string
    {
        return match ($this->reason) {
            self::REASON_OVER_THRESHOLD => Craft::t('forklift', 'Over the company approval threshold'),
            self::REASON_OVER_SPEND_LIMIT => Craft::t('forklift', 'Over the buyer’s spend limit'),
            self::REASON_MEMBER_ALWAYS => Craft::t('forklift', 'This buyer’s orders always need approval'),
            default => Craft::t('forklift', 'Approval required'),
        };
    }

    public function getStatusLabel(): string
    {
        return match ($this->getEffectiveStatus()) {
            self::STATUS_APPROVED => Craft::t('forklift', 'Approved'),
            self::STATUS_DECLINED => Craft::t('forklift', 'Declined'),
            self::STATUS_CANCELLED => Craft::t('forklift', 'Cancelled'),
            self::STATUS_EXPIRED => Craft::t('forklift', 'Expired'),
            default => Craft::t('forklift', 'Awaiting decision'),
        };
    }

    /** The link that goes in the approver's email. Site URL, not CP — approvers rarely have CP accounts. */
    public function getDecisionUrl(): ?string
    {
        if (!$this->token) {
            return null;
        }

        return UrlHelper::siteUrl('forklift/approvals/' . $this->token);
    }

    public function getCpEditUrl(): string
    {
        return UrlHelper::cpUrl('forklift/approvals/' . $this->id);
    }

    protected function defineRules(): array
    {
        return [
            [['orderId', 'companyId', 'status'], 'required'],
            [['orderId', 'companyId', 'requesterId', 'approverId'], 'integer'],
            [['status'], 'in', 'range' => self::STATUSES],
            [['amount'], 'number', 'min' => 0],
            [['reason', 'note', 'decisionNote', 'token', 'currency'], 'safe'],
            [['requestedDate', 'decisionDate', 'expiryDate'], 'safe'],
        ];
    }
}
