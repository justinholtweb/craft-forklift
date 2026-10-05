<?php

declare(strict_types=1);

namespace justinholtweb\forklift\elements;

use Craft;
use craft\base\Element;
use craft\commerce\elements\db\OrderQuery;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\elements\Address;
use craft\elements\User;
use craft\enums\Color;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use justinholtweb\forklift\db\Table;
use justinholtweb\forklift\elements\db\CompanyQuery;
use justinholtweb\forklift\models\Certificate;
use justinholtweb\forklift\models\Member;
use justinholtweb\forklift\models\Term;
use justinholtweb\forklift\Plugin;
use justinholtweb\forklift\records\CompanyRecord;

/**
 * A trading account: the customer, as opposed to the person.
 *
 * An element and not a settings row, because everything a B2B account needs is element plumbing
 * Craft already has: a field layout (every agency wants their own fields on an account — rep,
 * region, warehouse, delivery instructions), search, relations to other content, the trash, an
 * element index with sources and bulk actions, and custom sources built from conditions.
 *
 * ## What is a column and what is a field
 *
 * A column is something **Forklift itself has to reason about**: the account code, whether the
 * account is on hold, its credit limit, its payment terms, its approval threshold. Those decide
 * whether an order can be placed, so they cannot live in a layout the site is free to delete.
 * Everything else is the field layout's, and Forklift has no opinion about it at all.
 *
 * ## Statuses
 *
 * Four, and the distinction between the middle two is the whole reason a B2B account is not just
 * a user group:
 *
 * - **Active** — trading normally.
 * - **On hold** — cannot place orders, but everything else still works. What a credit controller
 *   does at 4pm on a Friday, and what they undo on Monday. Existing orders, quotes, invoices and
 *   statements are untouched.
 * - **Closed** — the relationship has ended. Same refusal, different word, and the word matters
 *   to the person reading the account.
 * - **Disabled** — the element is switched off. Craft's own status, kept separate so an
 *   accidental disable is not confused with a credit decision.
 *
 * @property-read Member[] $members
 * @property-read Certificate[] $certificates
 * @property-read Term|null $terms
 * @property-read User|null $owner
 */
class Company extends Element
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_HOLD = 'hold';
    public const STATUS_CLOSED = 'closed';

    /** The values `accountStatus` may hold. Element-disabled is Craft's, not ours. */
    public const ACCOUNT_STATUSES = [self::STATUS_ACTIVE, self::STATUS_HOLD, self::STATUS_CLOSED];

    public ?string $code = null;
    public string $accountStatus = self::STATUS_ACTIVE;
    public ?int $ownerId = null;
    public ?int $termsId = null;
    public bool $creditEnabled = false;
    public bool $requiresPoNumber = false;

    /**
     * Orders at or above this need somebody to sign them off.
     *
     * Null and zero are different and both are used. **Null** is "no threshold — the buyers' own
     * limits are the only control". **Zero** is "every order needs approval", which is what a new
     * account on probation looks like.
     */
    public ?float $approvalThreshold = null;

    /**
     * The most this account may owe at once. **Null means no limit at all.**
     *
     * Deliberately allowed, and deliberately not validated into existence. A subsidiary, an
     * internal account or a government customer legitimately trades without a ceiling, and a rule
     * forbidding it would simply make those accounts impossible to model. What stops it happening
     * by accident is that `forklift/maintenance/doctor` names every account on terms with no
     * limit, and the control panel says in as many words that blank means no limit and zero means
     * no orders on account.
     */
    public ?float $creditLimit = null;

    /**
     * A convenience flag, not the answer.
     *
     * Whether tax is actually removed is decided by an approved, unexpired certificate that covers
     * the delivery address — see {@see \justinholtweb\forklift\services\Certificates}. This is
     * here so an index can be filtered and a badge shown without loading certificates for every
     * row, and it is written by the certificate service rather than by hand.
     */
    public bool $taxExempt = false;

    public ?string $phone = null;
    public ?string $website = null;

    /** The customer's VAT / EIN / ABN. Free text — every jurisdiction formats it differently. */
    public ?string $taxId = null;

    public ?string $notes = null;

    /** @var Member[]|null */
    private ?array $_members = null;

    /** @var Certificate[]|null */
    private ?array $_certificates = null;

    private ?Term $_terms = null;
    private bool $_termsLoaded = false;

    // Identity
    // -------------------------------------------------------------------------

    public static function displayName(): string
    {
        return Craft::t('forklift', 'Company');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('forklift', 'company');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('forklift', 'Companies');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('forklift', 'companies');
    }

    public static function refHandle(): ?string
    {
        return 'company';
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

    public static function find(): CompanyQuery
    {
        return new CompanyQuery(static::class);
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_ACTIVE => ['label' => Craft::t('forklift', 'Active'), 'color' => Color::Green],
            self::STATUS_HOLD => ['label' => Craft::t('forklift', 'On hold'), 'color' => Color::Orange],
            self::STATUS_CLOSED => ['label' => Craft::t('forklift', 'Closed'), 'color' => Color::Red],
            self::STATUS_DISABLED => ['label' => Craft::t('app', 'Disabled'), 'color' => Color::Gray],
        ];
    }

    public function getStatus(): ?string
    {
        $status = parent::getStatus();

        if ($status !== self::STATUS_ENABLED) {
            return $status;
        }

        return $this->accountStatus;
    }

    public function getUiLabel(): string
    {
        return $this->title ?: Craft::t('forklift', 'Untitled company');
    }

    public function getFieldLayout(): ?FieldLayout
    {
        return Craft::$app->getFields()->getLayoutByType(self::class);
    }

    protected function cpEditUrl(): ?string
    {
        return UrlHelper::cpUrl('forklift/companies/' . $this->getCanonicalId());
    }

    public function getPostEditUrl(): ?string
    {
        return UrlHelper::cpUrl('forklift/companies');
    }

    // Trading state
    // -------------------------------------------------------------------------

    /**
     * Whether this account may place an order right now.
     *
     * The one question `services\Checkout` asks about the company itself. Credit, approval and the
     * buyer's own limits are separate questions with separate answers — an account can be
     * perfectly able to trade and still have this particular order stopped.
     */
    public function getCanPurchase(): bool
    {
        return $this->enabled && $this->accountStatus === self::STATUS_ACTIVE;
    }

    public function getIsOnHold(): bool
    {
        return $this->accountStatus === self::STATUS_HOLD;
    }

    /** Whether this account is set up to buy on terms at all. */
    public function getHasCredit(): bool
    {
        return $this->creditEnabled && Plugin::getInstance()->getSettings()->getEffectiveCreditEnabled();
    }

    /** What is owed right now. Always summed from the ledger, never cached on the element. */
    public function getBalance(): float
    {
        return $this->id ? Plugin::getInstance()->credit->balanceFor($this->id) : 0.0;
    }

    /** Credit still available, or null when the account has no limit. */
    public function getCreditRemaining(): ?float
    {
        if (!$this->getHasCredit() || $this->creditLimit === null) {
            return null;
        }

        return round($this->creditLimit - $this->getBalance(), 2);
    }

    /**
     * Whether every order from this account needs approval, whatever it is worth.
     *
     * Expressed as its own question because a threshold of zero reads, at a glance, like "no
     * threshold" — and a company whose orders all needed signing off but silently stopped would
     * be a very expensive misreading.
     */
    public function getRequiresApprovalAlways(): bool
    {
        return $this->approvalThreshold !== null && $this->approvalThreshold <= 0;
    }

    // Relations
    // -------------------------------------------------------------------------

    /** @return Member[] */
    public function getMembers(): array
    {
        if ($this->_members === null) {
            $this->_members = $this->id
                ? Plugin::getInstance()->members->getMembersByCompanyId($this->id)
                : [];
        }

        return $this->_members;
    }

    public function getMemberCount(): int
    {
        return count($this->getMembers());
    }

    /** @return Certificate[] */
    public function getCertificates(): array
    {
        if ($this->_certificates === null) {
            $this->_certificates = $this->id
                ? Plugin::getInstance()->certificates->getCertificatesByCompanyId($this->id)
                : [];
        }

        return $this->_certificates;
    }

    public function getTerms(): ?Term
    {
        if (!$this->_termsLoaded) {
            $this->_termsLoaded = true;
            $this->_terms = $this->termsId ? Plugin::getInstance()->terms->getTermById($this->termsId) : null;
        }

        return $this->_terms;
    }

    public function getOwner(): ?User
    {
        return $this->ownerId ? Craft::$app->getUsers()->getUserById($this->ownerId) : null;
    }

    /**
     * Every order placed against this account.
     *
     * A sub-select rather than a custom query param, because the join Forklift needs lives in its
     * own table and an element query that has been taught a new `andWhere()` is a great deal
     * easier to reason about than one that has been taught a new behaviour.
     */
    public function getOrders(): OrderQuery
    {
        /** @var OrderQuery $query */
        $query = Order::find();

        return $query->andWhere([
            'commerce_orders.id' => (new Query())
                ->select(['orderId'])
                ->from([Table::ORDERS])
                ->where(['companyId' => $this->id ?? 0]),
        ]);
    }

    /** @return Address[] The addresses owned by this company element. */
    public function getAddresses(): array
    {
        if (!$this->id) {
            return [];
        }

        return Address::find()->owner($this)->all();
    }

    // Persistence
    // -------------------------------------------------------------------------

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $record = $isNew ? new CompanyRecord() : CompanyRecord::findOne($this->id);

            if ($record === null) {
                // A restored element, or a row that went missing. Written back rather than
                // failing the save and leaving an account with no status and no terms.
                $record = new CompanyRecord();
                $isNew = true;
            }

            if ($isNew) {
                $record->id = $this->id;
            }

            $record->code = $this->code ?: Plugin::getInstance()->companies->generateCode($this);
            $record->accountStatus = $this->accountStatus;
            $record->ownerId = $this->ownerId;
            $record->termsId = $this->termsId;
            $record->creditLimit = $this->creditLimit;
            $record->creditEnabled = $this->creditEnabled;
            $record->requiresPoNumber = $this->requiresPoNumber;
            $record->approvalThreshold = $this->approvalThreshold;
            $record->taxExempt = $this->taxExempt;
            $record->phone = $this->phone;
            $record->website = $this->website;
            $record->taxId = $this->taxId;
            $record->notes = $this->notes;
            $record->save(false);

            $this->code = $record->code;
        }

        parent::afterSave($isNew);
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['accountStatus'], 'in', 'range' => self::ACCOUNT_STATUSES];
        $rules[] = [['creditLimit', 'approvalThreshold'], 'number', 'min' => 0];
        $rules[] = [['code'], 'string', 'max' => 64];
        $rules[] = [['code'], 'validateCodeIsUnique'];
        $rules[] = [['website'], 'url', 'defaultScheme' => 'https'];
        $rules[] = [['ownerId', 'termsId', 'phone', 'taxId', 'notes'], 'safe'];

        return $rules;
    }

    /**
     * Account codes are what the warehouse writes on a pick note, so two accounts sharing one is
     * a real operational problem. Not a database unique index, because a code is optional and
     * MySQL and Postgres disagree about how many nulls a unique index tolerates.
     */
    public function validateCodeIsUnique(string $attribute): void
    {
        if (!$this->code) {
            return;
        }

        $exists = Plugin::getInstance()->companies->getCompanyByCode($this->code);

        if ($exists !== null && $exists->id !== $this->getCanonicalId()) {
            $this->addError($attribute, Craft::t('forklift', 'Another company already uses the code “{code}”.', ['code' => $this->code]));
        }
    }

    // Element index
    // -------------------------------------------------------------------------

    protected static function defineSearchableAttributes(): array
    {
        return ['title', 'code', 'taxId', 'phone', 'memberText'];
    }

    /**
     * The buyers' names and email addresses, so searching the index for a person finds the
     * account they buy for — which is how a support call always starts.
     */
    protected function searchKeywords(string $attribute): string
    {
        if ($attribute === 'memberText') {
            $parts = [];

            foreach ($this->getMembers() as $member) {
                $parts[] = $member->getName();
                $parts[] = (string)$member->getEmail();
            }

            return implode(' ', $parts);
        }

        return parent::searchKeywords($attribute);
    }

    protected static function defineSources(string $context): array
    {
        return [
            [
                'key' => '*',
                'label' => Craft::t('forklift', 'All companies'),
                'defaultSort' => ['title', 'asc'],
            ],
            ['heading' => Craft::t('forklift', 'Account')],
            [
                'key' => 'status:active',
                'label' => Craft::t('forklift', 'Active'),
                'criteria' => ['accountStatus' => self::STATUS_ACTIVE],
            ],
            [
                'key' => 'status:hold',
                'label' => Craft::t('forklift', 'On hold'),
                'criteria' => ['accountStatus' => self::STATUS_HOLD],
            ],
            [
                'key' => 'status:closed',
                'label' => Craft::t('forklift', 'Closed'),
                'criteria' => ['accountStatus' => self::STATUS_CLOSED],
            ],
            ['heading' => Craft::t('forklift', 'Credit')],
            [
                'key' => 'credit:enabled',
                'label' => Craft::t('forklift', 'Trading on terms'),
                'criteria' => ['creditEnabled' => true],
            ],
            [
                'key' => 'credit:over',
                'label' => Craft::t('forklift', 'Over credit limit'),
                'criteria' => ['overCreditLimit' => true],
            ],
            ['heading' => Craft::t('forklift', 'Tax')],
            [
                'key' => 'tax:exempt',
                'label' => Craft::t('forklift', 'Tax exempt'),
                'criteria' => ['taxExempt' => true],
            ],
        ];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'code' => ['label' => Craft::t('forklift', 'Account code')],
            'accountStatus' => ['label' => Craft::t('forklift', 'Account status')],
            'members' => ['label' => Craft::t('forklift', 'Buyers')],
            'terms' => ['label' => Craft::t('forklift', 'Terms')],
            'creditLimit' => ['label' => Craft::t('forklift', 'Credit limit')],
            'balance' => ['label' => Craft::t('forklift', 'Balance')],
            'approvalThreshold' => ['label' => Craft::t('forklift', 'Approval over')],
            'taxExempt' => ['label' => Craft::t('forklift', 'Tax exempt')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
            'dateUpdated' => ['label' => Craft::t('app', 'Last Updated')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['code', 'accountStatus', 'members', 'terms', 'balance'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('app', 'Name'),
            'forklift_companies.code' => Craft::t('forklift', 'Account code'),
            'forklift_companies.creditLimit' => Craft::t('forklift', 'Credit limit'),
            'dateCreated' => Craft::t('app', 'Date Created'),
            'dateUpdated' => Craft::t('app', 'Last Updated'),
        ];
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'code' => $this->code ? Html::tag('code', Html::encode($this->code), ['class' => 'small light']) : '',
            'accountStatus' => Cp::statusLabelHtml(self::statuses()[$this->accountStatus] ?? []),
            'members' => Html::encode((string)$this->getMemberCount()),
            'terms' => Html::encode((string)($this->getTerms()?->getShorthand() ?? Craft::t('forklift', 'Prepay'))),
            'creditLimit' => $this->creditLimit === null ? '' : $this->_money($this->creditLimit),
            'balance' => $this->_balanceHtml(),
            'approvalThreshold' => $this->_thresholdHtml(),
            'taxExempt' => $this->taxExempt ? Html::tag('span', '', ['class' => 'checkbox-icon', 'title' => Craft::t('forklift', 'Tax exempt')]) . Craft::t('app', 'Yes') : '',
            default => parent::attributeHtml($attribute),
        };
    }

    private function _thresholdHtml(): string
    {
        if ($this->approvalThreshold === null) {
            return Html::tag('span', Craft::t('forklift', 'None'), ['class' => 'light']);
        }

        if ($this->approvalThreshold <= 0) {
            return Html::tag('span', Craft::t('forklift', 'Every order'), ['class' => 'warning']);
        }

        return $this->_money($this->approvalThreshold);
    }

    private function _balanceHtml(): string
    {
        if (!$this->creditEnabled) {
            return '';
        }

        $balance = $this->getBalance();
        $remaining = $this->getCreditRemaining();
        $html = $this->_money($balance);

        if ($remaining !== null && $remaining < 0) {
            $html .= ' ' . Html::tag('span', Craft::t('forklift', 'over limit'), ['class' => 'error']);
        }

        return $html;
    }

    private function _money(float $amount): string
    {
        try {
            $store = \craft\commerce\Plugin::getInstance()?->getStores()->getCurrentStore();
            $currency = $store?->getCurrency();
            $code = is_object($currency) ? $currency->getCode() : (string)$currency;

            if ($code !== '') {
                return Html::encode(Craft::$app->getFormatter()->asCurrency($amount, $code));
            }
        } catch (\Throwable) {
            // Fall through to a plain number rather than taking the index down.
        }

        return Html::encode(number_format($amount, 2));
    }

    // Permissions
    // -------------------------------------------------------------------------

    public function canView(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_VIEW_COMPANIES);
    }

    public function canSave(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE_COMPANIES);
    }

    public function canDelete(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_DELETE_COMPANIES);
    }

    public function canCreateDrafts(User $user): bool
    {
        return false;
    }
}
