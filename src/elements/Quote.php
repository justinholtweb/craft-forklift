<?php

declare(strict_types=1);

namespace justinholtweb\forklift\elements;

use Craft;
use craft\base\Element;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\enums\Color;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\DateTimeHelper;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use DateTime;
use justinholtweb\forklift\elements\db\QuoteQuery;
use justinholtweb\forklift\models\QuoteLine;
use justinholtweb\forklift\Plugin;
use justinholtweb\forklift\records\QuoteRecord;

/**
 * A request for a price, and the priced answer to it.
 *
 * The gap this fills is `craftcms/commerce#1156` — "merchant edits an order, customer pays a
 * link" — which Commerce has open and no plugin closes. The awkward part of that request is that
 * a *cart* is a poor place to keep a negotiation: it belongs to a session, it recalculates itself
 * whenever anything touches it, and it has nowhere to record that a customer asked for 400 and
 * was offered 250 at a better rate.
 *
 * So a quote is its own element with its own lines, and the Commerce cart is **materialised from
 * it at the moment it is sent**. That ordering is the design:
 *
 * 1. `requested` — the buyer asked. Lines are a snapshot of what they had in front of them.
 * 2. `pricing` — a merchant has it open. They can change quantities, prices, add lines, add a
 *    delivery charge, write a note, and set how long the offer stands.
 * 3. `sent` — a cart now exists carrying exactly those prices, and the buyer has a tokenised link
 *    that loads it into their own session. From there it is an ordinary Commerce checkout, with
 *    every gateway the store has, including purchase-order terms.
 * 4. `accepted` — the cart completed. The order is linked back here.
 *
 * The prices survive checkout because `services\Pricing::resolve()` treats a quote pin as the
 * highest-priority source, so Commerce recalculating the cart — which it does constantly —
 * re-derives the same numbers rather than reverting them.
 *
 * @property-read QuoteLine[] $lines
 * @property-read Company|null $company
 * @property-read Order|null $order
 */
class Quote extends Element
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_PRICING = 'pricing';
    public const STATUS_SENT = 'sent';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    public const QUOTE_STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_PRICING,
        self::STATUS_SENT,
        self::STATUS_ACCEPTED,
        self::STATUS_DECLINED,
        self::STATUS_EXPIRED,
        self::STATUS_CANCELLED,
    ];

    /** Statuses a merchant can still edit the prices on. */
    public const EDITABLE_STATUSES = [self::STATUS_REQUESTED, self::STATUS_PRICING];

    public ?string $number = null;
    public ?int $storeId = null;
    public ?int $companyId = null;
    public ?int $requesterId = null;
    public ?string $email = null;
    public string $quoteStatus = self::STATUS_REQUESTED;
    public ?string $currency = null;

    /** The buyer's own reference — their RFQ number, which is what they will quote back. */
    public ?string $reference = null;

    /** What the buyer wrote when they asked. Shown to the merchant, never edited by them. */
    public ?string $message = null;

    /** The merchant's working notes. Never shown to the buyer. */
    public ?string $internalNote = null;

    public ?float $shippingCost = null;

    /** A whole-quote discount, on top of whatever the lines say. */
    public ?float $discount = null;

    public ?DateTime $expiryDate = null;
    public ?DateTime $sentDate = null;
    public ?DateTime $respondedDate = null;
    public ?int $orderId = null;

    /** The incomplete Commerce cart this quote was materialised into, once it has been sent. */
    public ?string $cartNumber = null;

    /** @var QuoteLine[]|null */
    private ?array $_lines = null;

    private ?Company $_company = null;
    private bool $_companyLoaded = false;

    // Identity
    // -------------------------------------------------------------------------

    public static function displayName(): string
    {
        return Craft::t('forklift', 'Quote');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('forklift', 'quote');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('forklift', 'Quotes');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('forklift', 'quotes');
    }

    public static function refHandle(): ?string
    {
        return 'quote';
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasUris(): bool
    {
        return false;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function trackChanges(): bool
    {
        return true;
    }

    public static function find(): ElementQueryInterface
    {
        return new QuoteQuery(static::class);
    }

    /**
     * The statuses, in the order a quote moves through them.
     *
     * Craft's enabled/disabled is deliberately *not* one of them. Disabling a quote element means
     * nothing to anybody — a quote is either live, agreed, refused or out of date, and those are
     * the four things a salesperson filters by.
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_REQUESTED => ['label' => Craft::t('forklift', 'Requested'), 'color' => Color::Blue],
            self::STATUS_PRICING => ['label' => Craft::t('forklift', 'Being priced'), 'color' => Color::Violet],
            self::STATUS_SENT => ['label' => Craft::t('forklift', 'Sent'), 'color' => Color::Amber],
            self::STATUS_ACCEPTED => ['label' => Craft::t('forklift', 'Accepted'), 'color' => Color::Green],
            self::STATUS_DECLINED => ['label' => Craft::t('forklift', 'Declined'), 'color' => Color::Red],
            self::STATUS_EXPIRED => ['label' => Craft::t('forklift', 'Expired'), 'color' => Color::Gray],
            self::STATUS_CANCELLED => ['label' => Craft::t('forklift', 'Cancelled'), 'color' => Color::Gray],
        ];
    }

    public function getStatus(): ?string
    {
        return $this->getEffectiveStatus();
    }

    /**
     * The status folding in the passage of time.
     *
     * A sent quote whose expiry has gone by is *expired* from the moment it goes by, not from the
     * moment the nightly sweep next runs — otherwise a buyer on a site with a broken cron can
     * accept a price the merchant withdrew a fortnight ago.
     */
    public function getEffectiveStatus(): string
    {
        if ($this->quoteStatus === self::STATUS_SENT && $this->getIsExpired()) {
            return self::STATUS_EXPIRED;
        }

        return $this->quoteStatus;
    }

    public function getIsExpired(?DateTime $on = null): bool
    {
        if ($this->expiryDate === null) {
            return false;
        }

        return ($on ?? DateTimeHelper::currentUTCDateTime()) > $this->expiryDate;
    }

    /** Whether the buyer could still turn this into an order right now. */
    public function getIsAcceptable(): bool
    {
        return $this->getEffectiveStatus() === self::STATUS_SENT && $this->cartNumber !== null;
    }

    public function getIsEditable(): bool
    {
        return in_array($this->quoteStatus, self::EDITABLE_STATUSES, true);
    }

    public function getUiLabel(): string
    {
        return $this->title ?: ($this->number ?: Craft::t('forklift', 'Quote'));
    }

    public function getFieldLayout(): ?FieldLayout
    {
        return Craft::$app->getFields()->getLayoutByType(self::class);
    }

    protected function cpEditUrl(): ?string
    {
        return UrlHelper::cpUrl('forklift/quotes/' . $this->getCanonicalId());
    }

    public function getPostEditUrl(): ?string
    {
        return UrlHelper::cpUrl('forklift/quotes');
    }

    public function datetimeAttributes(): array
    {
        return array_merge(parent::datetimeAttributes(), ['expiryDate', 'sentDate', 'respondedDate']);
    }

    // Lines and totals
    // -------------------------------------------------------------------------

    /** @return QuoteLine[] */
    public function getLines(): array
    {
        if ($this->_lines === null) {
            $this->_lines = $this->id
                ? Plugin::getInstance()->quotes->getLinesByQuoteId($this->id)
                : [];
        }

        return $this->_lines;
    }

    /**
     * @param QuoteLine[]|null $lines Null means "nobody touched the lines". An absent form field
     *                                must never empty a quote.
     */
    public function setLines(?array $lines): void
    {
        $this->_lines = $lines === null ? null : array_values($lines);
    }

    public function getItemSubtotal(): float
    {
        $total = 0.0;

        foreach ($this->getLines() as $line) {
            $total += $line->getSubtotal();
        }

        return round($total, 2);
    }

    public function getListSubtotal(): float
    {
        $total = 0.0;

        foreach ($this->getLines() as $line) {
            $total += $line->getListSubtotal();
        }

        return round($total, 2);
    }

    /**
     * The number on the bottom of the quotation.
     *
     * Tax is deliberately absent. A quote is priced before there is a confirmed delivery address,
     * and inventing a tax figure against an address nobody has given is how a quotation ends up
     * disagreeing with the invoice. Commerce works the tax out at checkout, against the address
     * the buyer actually enters.
     */
    public function getTotal(): float
    {
        return round($this->getItemSubtotal() + (float)$this->shippingCost - (float)$this->discount, 2);
    }

    public function getTotalSaving(): float
    {
        return max(0.0, round($this->getListSubtotal() - $this->getItemSubtotal() + (float)$this->discount, 2));
    }

    public function getTotalQty(): int
    {
        $qty = 0;

        foreach ($this->getLines() as $line) {
            $qty += $line->qty;
        }

        return $qty;
    }

    // Relations
    // -------------------------------------------------------------------------

    public function getCompany(): ?Company
    {
        if (!$this->_companyLoaded) {
            $this->_companyLoaded = true;
            $this->_company = $this->companyId ? Plugin::getInstance()->companies->getCompanyById($this->companyId) : null;
        }

        return $this->_company;
    }

    public function getRequester(): ?User
    {
        return $this->requesterId ? Craft::$app->getUsers()->getUserById($this->requesterId) : null;
    }

    public function getOrder(): ?Order
    {
        return $this->orderId ? Commerce::getInstance()?->getOrders()->getOrderById($this->orderId) : null;
    }

    /** The cart the pay-by-link loads. Null until the quote has been sent. */
    public function getCart(): ?Order
    {
        if (!$this->cartNumber) {
            return null;
        }

        return Order::find()->number($this->cartNumber)->isCompleted(false)->one();
    }

    /** Where the buyer goes to accept. Null until there is something to accept. */
    public function getPaymentUrl(): ?string
    {
        return $this->id ? Plugin::getInstance()->quotes->getPaymentUrl($this) : null;
    }

    public function getContactEmail(): ?string
    {
        return $this->email ?: $this->getRequester()?->email;
    }

    // Persistence
    // -------------------------------------------------------------------------

    public function beforeSave(bool $isNew): bool
    {
        if ($isNew && !$this->number) {
            $this->number = Plugin::getInstance()->quotes->generateNumber();
        }

        // A quote with no subject is titled by its number, so an element index and a relation
        // field both read as something rather than as a blank row.
        if (!$this->title) {
            $this->title = $this->number;
        }

        return parent::beforeSave($isNew);
    }

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $record = $isNew ? new QuoteRecord() : QuoteRecord::findOne($this->id);

            if ($record === null) {
                $record = new QuoteRecord();
                $isNew = true;
            }

            if ($isNew) {
                $record->id = $this->id;
            }

            $record->number = $this->number;
            $record->storeId = $this->storeId;
            $record->companyId = $this->companyId;
            $record->requesterId = $this->requesterId;
            $record->email = $this->email;
            $record->status = $this->quoteStatus;
            $record->currency = $this->currency;
            $record->reference = $this->reference;
            $record->message = $this->message;
            $record->internalNote = $this->internalNote;
            $record->shippingCost = $this->shippingCost;
            $record->discount = $this->discount;
            $record->expiryDate = Db::prepareDateForDb($this->expiryDate);
            $record->sentDate = Db::prepareDateForDb($this->sentDate);
            $record->respondedDate = Db::prepareDateForDb($this->respondedDate);
            $record->orderId = $this->orderId;
            $record->cartNumber = $this->cartNumber;
            $record->save(false);

            if ($this->_lines !== null) {
                Plugin::getInstance()->quotes->saveLines((int)$this->id, $this->_lines);
            }
        }

        parent::afterSave($isNew);
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['quoteStatus'], 'in', 'range' => self::QUOTE_STATUSES];
        $rules[] = [['email'], 'email'];
        $rules[] = [['shippingCost', 'discount'], 'number', 'min' => 0];
        $rules[] = [['email'], 'validateContactable', 'skipOnEmpty' => false];
        $rules[] = [['discount'], 'validateDiscount', 'skipOnEmpty' => false];
        $rules[] = [['companyId', 'requesterId', 'reference', 'message', 'internalNote', 'storeId', 'currency'], 'safe'];
        $rules[] = [['expiryDate', 'sentDate', 'respondedDate', 'orderId', 'cartNumber', 'number'], 'safe'];

        return $rules;
    }

    /**
     * There has to be somewhere to send the answer.
     *
     * A quote with neither a signed-in requester nor an email address is a request nobody can
     * reply to, which is worse than a refused form.
     */
    public function validateContactable(string $attribute): void
    {
        if (!$this->email && !$this->requesterId) {
            $this->addError($attribute, Craft::t('forklift', 'An email address is needed so the quote can be sent back.'));
        }
    }

    /** A discount larger than the goods produces a negative quotation, which is never intended. */
    public function validateDiscount(string $attribute): void
    {
        if ($this->discount !== null && $this->discount > $this->getItemSubtotal() + (float)$this->shippingCost) {
            $this->addError($attribute, Craft::t('forklift', 'The discount is more than the quote is worth.'));
        }
    }

    // Element index
    // -------------------------------------------------------------------------

    protected static function defineSearchableAttributes(): array
    {
        return ['title', 'number', 'reference', 'email', 'message'];
    }

    protected static function defineSources(string $context): array
    {
        return [
            [
                'key' => '*',
                'label' => Craft::t('forklift', 'All quotes'),
                'defaultSort' => ['dateCreated', 'desc'],
            ],
            ['heading' => Craft::t('forklift', 'To do')],
            [
                'key' => 'status:requested',
                'label' => Craft::t('forklift', 'Awaiting a price'),
                'criteria' => ['quoteStatus' => [self::STATUS_REQUESTED, self::STATUS_PRICING]],
                'defaultSort' => ['dateCreated', 'asc'],
            ],
            [
                'key' => 'status:sent',
                'label' => Craft::t('forklift', 'Sent, awaiting a decision'),
                'criteria' => ['quoteStatus' => self::STATUS_SENT, 'expired' => false],
                'defaultSort' => ['expiryDate', 'asc'],
            ],
            [
                'key' => 'expiring',
                'label' => Craft::t('forklift', 'Expiring within a week'),
                'criteria' => ['quoteStatus' => self::STATUS_SENT, 'expiringWithinDays' => 7],
                'defaultSort' => ['expiryDate', 'asc'],
            ],
            ['heading' => Craft::t('forklift', 'Closed')],
            [
                'key' => 'status:accepted',
                'label' => Craft::t('forklift', 'Accepted'),
                'criteria' => ['quoteStatus' => self::STATUS_ACCEPTED],
            ],
            [
                'key' => 'status:declined',
                'label' => Craft::t('forklift', 'Declined'),
                'criteria' => ['quoteStatus' => [self::STATUS_DECLINED, self::STATUS_CANCELLED]],
            ],
            [
                'key' => 'status:expired',
                'label' => Craft::t('forklift', 'Expired'),
                'criteria' => ['quoteStatus' => self::STATUS_SENT, 'expired' => true],
            ],
        ];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'number' => ['label' => Craft::t('forklift', 'Quote')],
            'quoteStatus' => ['label' => Craft::t('app', 'Status')],
            'company' => ['label' => Craft::t('forklift', 'Company')],
            'requester' => ['label' => Craft::t('forklift', 'Requested by')],
            'reference' => ['label' => Craft::t('forklift', 'Their reference')],
            'lineCount' => ['label' => Craft::t('forklift', 'Lines')],
            'total' => ['label' => Craft::t('forklift', 'Total')],
            'expiryDate' => ['label' => Craft::t('forklift', 'Valid until')],
            'sentDate' => ['label' => Craft::t('forklift', 'Sent')],
            'order' => ['label' => Craft::t('forklift', 'Order')],
            'dateCreated' => ['label' => Craft::t('forklift', 'Requested')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['number', 'quoteStatus', 'company', 'total', 'expiryDate', 'dateCreated'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'forklift_quotes.number' => Craft::t('forklift', 'Quote number'),
            'dateCreated' => Craft::t('forklift', 'Requested'),
            'forklift_quotes.expiryDate' => Craft::t('forklift', 'Valid until'),
            'forklift_quotes.sentDate' => Craft::t('forklift', 'Sent'),
        ];
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'number' => Html::tag('code', Html::encode((string)$this->number), ['class' => 'small']),
            'quoteStatus' => Cp::statusLabelHtml(self::statuses()[$this->getEffectiveStatus()] ?? []),
            'company' => $this->_companyHtml(),
            'requester' => Html::encode((string)($this->getRequester()?->getName() ?? $this->email ?? '')),
            'reference' => Html::encode((string)$this->reference),
            'lineCount' => (string)count($this->getLines()),
            'total' => Html::encode(number_format($this->getTotal(), 2)),
            'order' => $this->_orderHtml(),
            'expiryDate' => $this->_expiryHtml(),
            default => parent::attributeHtml($attribute),
        };
    }

    private function _companyHtml(): string
    {
        $company = $this->getCompany();

        if ($company === null) {
            return Html::tag('span', Craft::t('forklift', 'No account'), ['class' => 'light']);
        }

        return Html::a(Html::encode($company->getUiLabel()), (string)$company->getCpEditUrl());
    }

    private function _orderHtml(): string
    {
        $order = $this->getOrder();

        if ($order === null) {
            return '';
        }

        return Html::a(Html::encode((string)$order->reference), (string)$order->getCpEditUrl());
    }

    private function _expiryHtml(): string
    {
        if ($this->expiryDate === null) {
            return Html::tag('span', Craft::t('forklift', 'No expiry'), ['class' => 'light']);
        }

        $html = Html::encode(Craft::$app->getFormatter()->asDate($this->expiryDate, 'short'));

        if ($this->getIsExpired()) {
            return Html::tag('span', $html, ['class' => 'error']);
        }

        return $html;
    }

    // Permissions
    // -------------------------------------------------------------------------

    public function canView(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_VIEW_QUOTES);
    }

    public function canSave(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE_QUOTES);
    }

    public function canDelete(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE_QUOTES);
    }

    public function canCreateDrafts(User $user): bool
    {
        return false;
    }
}
