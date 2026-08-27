<?php

declare(strict_types=1);

namespace justinholtweb\forklift;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Order;
use craft\commerce\events\LineItemEvent;
use craft\commerce\events\TaxEngineEvent;
use craft\commerce\Plugin as Commerce;
use craft\commerce\services\Gateways;
use craft\commerce\services\LineItems;
use craft\commerce\services\Taxes;
use craft\events\ConfigEvent;
use craft\events\ElementEvent;
use craft\events\RebuildConfigEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterEmailMessagesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\services\Elements;
use craft\services\Gc;
use craft\services\ProjectConfig;
use craft\services\SystemMessages;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\forklift\elements\Company;
use justinholtweb\forklift\elements\Quote;
use justinholtweb\forklift\engines\TaxEngine;
use justinholtweb\forklift\gateways\PurchaseOrder;
use justinholtweb\forklift\models\CheckoutVerdict;
use justinholtweb\forklift\models\Edition;
use justinholtweb\forklift\models\Settings;
use justinholtweb\forklift\services\Approvals;
use justinholtweb\forklift\services\Certificates;
use justinholtweb\forklift\services\Checkout;
use justinholtweb\forklift\services\Companies;
use justinholtweb\forklift\services\Credit;
use justinholtweb\forklift\services\Members;
use justinholtweb\forklift\services\Notifications;
use justinholtweb\forklift\services\Orders;
use justinholtweb\forklift\services\PriceLists;
use justinholtweb\forklift\services\Pricing;
use justinholtweb\forklift\services\QuickOrder;
use justinholtweb\forklift\services\Quotes;
use justinholtweb\forklift\services\Terms;
use justinholtweb\forklift\twig\ForkliftVariable;
use yii\base\Event;

/**
 * Forklift — B2B and wholesale for Craft Commerce.
 *
 * ## Five hooks into Commerce, and no more
 *
 * Everything Forklift does to a store happens through one of five documented extension points.
 * Keeping the list short and writing it down is the difference between a plugin that survives a
 * Commerce upgrade and one that is a maintenance liability:
 *
 * | Hook | What it does |
 * | --- | --- |
 * | `LineItems::EVENT_POPULATE_LINE_ITEM` | applies the contract or quoted price, *after* Commerce has set its own so the snapshot still records the list price |
 * | `Order::EVENT_BEFORE_COMPLETE_ORDER` | the checkout gate — a cancelled event is the only thing that reliably stops an order completing |
 * | `Order::EVENT_AFTER_COMPLETE_ORDER` | raise the invoice, close the quote, stamp the exemption |
 * | `Taxes::EVENT_REGISTER_TAX_ENGINE` | swap in an adjuster that honours exemption certificates — and only over Commerce's own engine |
 * | `Gateways::EVENT_REGISTER_GATEWAY_TYPES` | the purchase-order gateway |
 *
 * Plus one of Craft's: `Elements::EVENT_AFTER_SAVE_ELEMENT`, filtered to orders, to keep the
 * `forklift_orders` row in step with the cart.
 *
 * @property-read Companies $companies
 * @property-read Members $members
 * @property-read Terms $terms
 * @property-read PriceLists $priceLists
 * @property-read Pricing $pricing
 * @property-read Checkout $checkout
 * @property-read Approvals $approvals
 * @property-read Credit $credit
 * @property-read Certificates $certificates
 * @property-read Orders $orders
 * @property-read Quotes $quotes
 * @property-read QuickOrder $quickOrder
 * @property-read Notifications $notifications
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public const PERMISSION_VIEW_COMPANIES = 'forklift:viewCompanies';
    public const PERMISSION_MANAGE_COMPANIES = 'forklift:manageCompanies';
    public const PERMISSION_DELETE_COMPANIES = 'forklift:deleteCompanies';
    public const PERMISSION_MANAGE_MEMBERS = 'forklift:manageMembers';
    public const PERMISSION_MANAGE_PRICE_LISTS = 'forklift:managePriceLists';
    public const PERMISSION_VIEW_QUOTES = 'forklift:viewQuotes';
    public const PERMISSION_MANAGE_QUOTES = 'forklift:manageQuotes';
    public const PERMISSION_MANAGE_APPROVALS = 'forklift:manageApprovals';
    public const PERMISSION_VIEW_CREDIT = 'forklift:viewCredit';
    public const PERMISSION_MANAGE_CREDIT = 'forklift:manageCredit';
    public const PERMISSION_MANAGE_CERTIFICATES = 'forklift:manageCertificates';

    /** Log category used by everything in the plugin. */
    public const LOG_CATEGORY = 'forklift';

    public const CONFIG_COMPANY_FIELD_LAYOUT_KEY = 'forklift.companies.fieldLayouts';
    public const CONFIG_QUOTE_FIELD_LAYOUT_KEY = 'forklift.quotes.fieldLayouts';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function editions(): array
    {
        return [self::EDITION_LITE, self::EDITION_PRO];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'companies' => Companies::class,
                'members' => Members::class,
                'terms' => Terms::class,
                'priceLists' => PriceLists::class,
                'pricing' => Pricing::class,
                'checkout' => Checkout::class,
                'approvals' => Approvals::class,
                'credit' => Credit::class,
                'certificates' => Certificates::class,
                'orders' => Orders::class,
                'quotes' => Quotes::class,
                'quickOrder' => QuickOrder::class,
                'notifications' => Notifications::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerElementTypes();
        $this->registerRoutes();
        $this->registerPermissions();
        $this->registerProjectConfig();
        $this->registerSystemMessages();
        $this->registerTwig();
        $this->registerGarbageCollection();
        $this->registerCpHooks();

        // Everything below needs Commerce. A Craft install that has Forklift enabled and Commerce
        // disabled should degrade to an inert plugin rather than fataling on every request.
        if (Commerce::getInstance() !== null) {
            $this->registerPricing();
            $this->registerCheckoutGate();
            $this->registerOrderTracking();
            $this->registerTaxEngine();
            $this->registerGateways();
        }
    }

    /** Whether the Pro feature set is available. Every edition check goes through here. */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    protected function afterInstall(): void
    {
        parent::afterInstall();

        if (Craft::$app->getProjectConfig()->getIsApplyingExternalChanges()) {
            // The config is arriving from elsewhere and will bring its own terms. Seeding on top
            // would produce duplicates with different UIDs on every environment.
            return;
        }

        $this->terms->installDefaults();
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * Forklift's settings are several screens, not one pane.
     *
     * Never a redirect to `settings/plugins/forklift` — that *is* the URL Craft renders
     * `settingsHtml()` at, so overriding it that way is an infinite redirect.
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('forklift/settings'));
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('forklift', 'Forklift');

        $user = Craft::$app->getUser();

        if ($user->checkPermission(self::PERMISSION_VIEW_COMPANIES)) {
            $item['subnav']['companies'] = [
                'label' => Craft::t('forklift', 'Companies'),
                'url' => 'forklift/companies',
            ];
        }

        // The nav is built on *every* control-panel request, including the ones Craft serves
        // while a migration is part-applied. A query against a table that does not exist yet
        // would take the whole CP down at exactly the moment somebody was trying to fix it.
        $badge = 0;

        if ($this->isPro() && $user->checkPermission(self::PERMISSION_MANAGE_APPROVALS)) {
            $item['subnav']['approvals'] = [
                'label' => Craft::t('forklift', 'Approvals'),
                'url' => 'forklift/approvals',
            ];

            try {
                $badge += $this->approvals->getPendingCount();
            } catch (\Throwable) {
                // No badge. Nothing else is worth breaking for it.
            }
        }

        if ($this->isPro() && $user->checkPermission(self::PERMISSION_VIEW_QUOTES)) {
            $item['subnav']['quotes'] = [
                'label' => Craft::t('forklift', 'Quotes'),
                'url' => 'forklift/quotes',
            ];

            try {
                $badge += $this->quotes->getUnpricedCount();
            } catch (\Throwable) {
                // As above.
            }
        }

        if ($this->isPro() && $user->checkPermission(self::PERMISSION_MANAGE_PRICE_LISTS)) {
            $item['subnav']['price-lists'] = [
                'label' => Craft::t('forklift', 'Price lists'),
                'url' => 'forklift/price-lists',
            ];
        }

        if ($this->isPro() && $user->checkPermission(self::PERMISSION_VIEW_CREDIT)) {
            $item['subnav']['credit'] = [
                'label' => Craft::t('forklift', 'Credit'),
                'url' => 'forklift/credit',
            ];
        }

        if ($user->checkPermission(self::PERMISSION_MANAGE_CERTIFICATES)) {
            $item['subnav']['certificates'] = [
                'label' => Craft::t('forklift', 'Tax certificates'),
                'url' => 'forklift/certificates',
            ];
        }

        if ($user->getIsAdmin()) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('forklift', 'Settings'),
                'url' => 'forklift/settings',
            ];
        }

        if ($badge > 0) {
            $item['badgeCount'] = $badge;
        }

        return $item;
    }

    // Commerce: pricing
    // -------------------------------------------------------------------------

    /**
     * Apply the contract or quoted price to every line item.
     *
     * `EVENT_POPULATE_LINE_ITEM` fires at the *end* of Commerce's own population, after price,
     * promotional price and the snapshot have all been set. That ordering is what Forklift wants:
     * the snapshot keeps the list price for the audit trail, and the line item ends up carrying
     * the negotiated one.
     *
     * It fires again on every recalculation, which is what makes a quoted price stick — Commerce
     * re-derives the line from the purchasable constantly, and each time it does, this puts the
     * right number back.
     */
    private function registerPricing(): void
    {
        Event::on(LineItems::class, LineItems::EVENT_POPULATE_LINE_ITEM, function(LineItemEvent $event) {
            $lineItem = $event->lineItem;

            if (!$lineItem->purchasableId) {
                return;
            }

            try {
                $order = $lineItem->getOrder();
                $companyId = $order?->id ? $this->orders->getCompanyIdForOrder((int)$order->id) : null;

                // A cart that has not been saved yet has no Forklift row, but the buyer browsing
                // it has a company — and a price that appears only after the first save would
                // mean the first thing added to a basket is priced wrong.
                $companyId ??= $this->companies->getCurrentCompany()?->id;

                if ($companyId === null && ($order === null || !$order->id)) {
                    return;
                }

                $result = $this->pricing->resolve($lineItem->getPurchasable(), (int)$lineItem->qty, $companyId, $order);

                if (!$result->getIsContractPrice()) {
                    return;
                }

                $lineItem->setPrice($result->price);

                // The promotional price is cleared rather than left alone. Leaving it would let
                // Commerce compute a "sale" against the contract price and show the customer a
                // discount off a discount that nobody offered.
                $lineItem->setPromotionalPrice(null);
            } catch (\Throwable $e) {
                // A pricing failure must never take a cart down. The line keeps Commerce's price,
                // which is the safe direction — the merchant is not undercharged.
                Craft::error(
                    'Forklift could not price line item ' . $lineItem->purchasableId . ': ' . $e->getMessage(),
                    self::LOG_CATEGORY,
                );
            }
        });
    }

    // Commerce: the checkout gate
    // -------------------------------------------------------------------------

    /**
     * Stop an order completing when the verdict says it may not.
     *
     * `EVENT_BEFORE_COMPLETE_ORDER` is cancellable and is the only reliable place to do this:
     * a controller check can be bypassed by any other code path that completes an order, and a
     * validation rule on the order fires in places that have nothing to do with checkout.
     */
    private function registerCheckoutGate(): void
    {
        Event::on(Order::class, Order::EVENT_BEFORE_COMPLETE_ORDER, function(Event $event) {
            /** @var Order $order */
            $order = $event->sender;

            try {
                $verdict = $this->checkout->verdict($order);
            } catch (\Throwable $e) {
                // A gate that throws must not take checkout down. Failing open is the right
                // direction here: the alternative is a store where nobody can buy anything
                // because one company row is malformed.
                Craft::error('Forklift could not evaluate the checkout gate: ' . $e->getMessage(), self::LOG_CATEGORY);

                return;
            }

            if ($verdict->approvalBypassed && $order->id) {
                $this->orders->setValuesForOrder((int)$order->id, ['approvalBypassed' => true]);
            }

            if ($verdict->getIsAllowed()) {
                return;
            }

            foreach ($verdict->getBlockingMessages() as $message) {
                $order->addNotice(new \craft\commerce\models\OrderNotice([
                    'type' => 'forklift',
                    'attribute' => 'forklift',
                    'message' => $message,
                ]));
            }

            $event->isValid = false;
        });

        Event::on(Order::class, Order::EVENT_AFTER_COMPLETE_ORDER, function(Event $event) {
            /** @var Order $order */
            $order = $event->sender;

            try {
                $this->afterOrderCompleted($order);
            } catch (\Throwable $e) {
                // The sale has happened. Bookkeeping that fails is a job for the console command,
                // not a reason to fail the request the customer is looking at.
                Craft::error(
                    'Forklift post-completion work failed for order ' . $order->id . ': ' . $e->getMessage(),
                    self::LOG_CATEGORY,
                );
            }
        });
    }

    /**
     * The bookkeeping that follows a completed order.
     *
     * Public so the console command that repairs a failed run can call exactly the same code
     * rather than reimplementing three-quarters of it.
     */
    public function afterOrderCompleted(Order $order): void
    {
        if (!$order->id) {
            return;
        }

        $companyId = $this->orders->getCompanyIdForOrder((int)$order->id);

        // Raise the invoice, but only for an order that actually went on account. A company order
        // paid by card is not a debt.
        if ($companyId !== null
            && Edition::allowsCredit($this->isPro())
            && $order->getGateway() instanceof PurchaseOrder
        ) {
            $this->credit->chargeOrder($order, $companyId);
        }

        // Close the quote this came from.
        $quote = $this->quotes->getQuoteForOrder($order);

        if ($quote !== null && $quote->quoteStatus !== Quote::STATUS_ACCEPTED) {
            $this->quotes->markAccepted($quote, $order);
        }

        // Record which certificate justified a zero-rated invoice. Written here rather than in
        // the adjuster, because an adjuster runs many times per save and should not write rows.
        $certificate = $this->certificates->exemptionFor($order);

        if ($certificate !== null) {
            $this->orders->setValuesForOrder((int)$order->id, ['taxExemptCertificateId' => $certificate->id]);
        }
    }

    // Commerce: order tracking
    // -------------------------------------------------------------------------

    /**
     * Keep the Forklift row in step with the cart.
     *
     * Typed `ElementEvent`, which is what Craft actually passes — a handler typed `ModelEvent`
     * here fatals on *every element save in the system*, which is a genuinely difficult failure
     * to diagnose from the other end.
     */
    private function registerOrderTracking(): void
    {
        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, function(ElementEvent $event) {
            if (!$event->element instanceof Order) {
                return;
            }

            try {
                $this->orders->assignCompany($event->element);
            } catch (\Throwable $e) {
                Craft::error('Forklift could not assign a company to the order: ' . $e->getMessage(), self::LOG_CATEGORY);
            }
        });
    }

    // Commerce: tax and gateways
    // -------------------------------------------------------------------------

    /**
     * Swap in the exemption-aware tax adjuster — but only over Commerce's own engine.
     *
     * A store running Avalara or TaxJar has an engine that talks to a service which does its own
     * exemption handling. Replacing it would be wrong, and would be very hard to diagnose from
     * the symptom.
     */
    private function registerTaxEngine(): void
    {
        Event::on(Taxes::class, Taxes::EVENT_REGISTER_TAX_ENGINE, function(TaxEngineEvent $event) {
            if (!$this->getSettings()->applyTaxExemptions) {
                return;
            }

            if (!$event->engine instanceof \craft\commerce\engines\Tax) {
                Craft::info(
                    'Another plugin owns the tax engine, so Forklift left it alone. Exemption certificates are recorded but not applied.',
                    self::LOG_CATEGORY,
                );

                return;
            }

            // An instanceof check passes for a subclass too, so a second run — or another plugin
            // that already extended ours — must not be wrapped again.
            if ($event->engine instanceof TaxEngine) {
                return;
            }

            $event->engine = new TaxEngine();
        });
    }

    private function registerGateways(): void
    {
        Event::on(Gateways::class, Gateways::EVENT_REGISTER_GATEWAY_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = PurchaseOrder::class;
        });
    }

    // Craft wiring
    // -------------------------------------------------------------------------

    private function registerElementTypes(): void
    {
        Event::on(Elements::class, Elements::EVENT_REGISTER_ELEMENT_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = Company::class;
            $event->types[] = Quote::class;
        });
    }

    private function registerRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules += [
                'forklift' => 'forklift/companies/index',
                'forklift/companies' => 'forklift/companies/index',
                'forklift/companies/new' => 'forklift/companies/edit',
                'forklift/companies/<companyId:\d+>' => 'forklift/companies/edit',
                'forklift/companies/<companyId:\d+>/members' => 'forklift/members/index',
                'forklift/companies/<companyId:\d+>/credit' => 'forklift/credit/company',
                'forklift/companies/<companyId:\d+>/statement' => 'forklift/credit/statement',
                'forklift/companies/<companyId:\d+>/certificates' => 'forklift/certificates/index',

                'forklift/price-lists' => 'forklift/price-lists/index',
                'forklift/price-lists/new' => 'forklift/price-lists/edit',
                'forklift/price-lists/<priceListId:\d+>' => 'forklift/price-lists/edit',
                'forklift/price-lists/<priceListId:\d+>/entries' => 'forklift/price-lists/entries',
                'forklift/price-lists/<priceListId:\d+>/import' => 'forklift/price-lists/import',
                'forklift/price-lists/preview' => 'forklift/price-lists/preview',

                'forklift/quotes' => 'forklift/quotes/index',
                'forklift/quotes/new' => 'forklift/quotes/edit',
                'forklift/quotes/<quoteId:\d+>' => 'forklift/quotes/edit',

                'forklift/approvals' => 'forklift/approvals/index',
                'forklift/approvals/<approvalId:\d+>' => 'forklift/approvals/detail',

                'forklift/credit' => 'forklift/credit/index',
                'forklift/certificates' => 'forklift/certificates/index',
                'forklift/certificates/new' => 'forklift/certificates/edit',
                'forklift/certificates/<certificateId:\d+>' => 'forklift/certificates/edit',

                'forklift/settings' => 'forklift/settings/index',
                'forklift/settings/general' => 'forklift/settings/general',
                'forklift/settings/companies' => 'forklift/settings/companies',
                'forklift/settings/quotes' => 'forklift/settings/quotes',
                'forklift/settings/credit' => 'forklift/settings/credit',
                'forklift/settings/fields' => 'forklift/settings/fields',
                'forklift/settings/quote-fields' => 'forklift/settings/quote-fields',
                'forklift/settings/terms' => 'forklift/terms/index',
                'forklift/settings/terms/new' => 'forklift/terms/edit',
                'forklift/settings/terms/<termId:\d+>' => 'forklift/terms/edit',
            ];
        });

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function(RegisterUrlRulesEvent $event) {
            // Stable, documented URLs as well as the action routes, so a store can build its own
            // front end against something that does not contain the word "actions".
            $event->rules['forklift/approvals/<token:[a-zA-Z0-9]{32}>'] = 'forklift/portal/approval';
            $event->rules['forklift/account'] = 'forklift/portal/index';
            $event->rules['forklift/account/orders'] = 'forklift/portal/orders';
            $event->rules['forklift/account/quotes'] = 'forklift/portal/quotes';
            $event->rules['forklift/account/statement'] = 'forklift/portal/statement';
            $event->rules['forklift/account/buyers'] = 'forklift/portal/buyers';
            $event->rules['forklift/quick-order'] = 'forklift/quick-order/index';
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('forklift', 'Forklift'),
                'permissions' => [
                    self::PERMISSION_VIEW_COMPANIES => [
                        'label' => Craft::t('forklift', 'View companies'),
                        'nested' => [
                            self::PERMISSION_MANAGE_COMPANIES => ['label' => Craft::t('forklift', 'Create and edit companies')],
                            self::PERMISSION_MANAGE_MEMBERS => ['label' => Craft::t('forklift', 'Manage buyers and roles')],
                            self::PERMISSION_DELETE_COMPANIES => ['label' => Craft::t('forklift', 'Delete companies')],
                        ],
                    ],
                    // Deliberately not nested under companies. Seeing what a customer negotiated,
                    // and what they owe, is a different kind of trust from maintaining an address
                    // book — plenty of organisations want somebody to have the second and not the
                    // first.
                    self::PERMISSION_MANAGE_PRICE_LISTS => [
                        'label' => Craft::t('forklift', 'Manage price lists'),
                    ],
                    self::PERMISSION_VIEW_CREDIT => [
                        'label' => Craft::t('forklift', 'View credit and statements'),
                        'nested' => [
                            self::PERMISSION_MANAGE_CREDIT => ['label' => Craft::t('forklift', 'Record payments and adjustments')],
                        ],
                    ],
                    self::PERMISSION_VIEW_QUOTES => [
                        'label' => Craft::t('forklift', 'View quotes'),
                        'nested' => [
                            self::PERMISSION_MANAGE_QUOTES => ['label' => Craft::t('forklift', 'Price and send quotes')],
                        ],
                    ],
                    self::PERMISSION_MANAGE_APPROVALS => [
                        'label' => Craft::t('forklift', 'Decide spend approvals'),
                    ],
                    self::PERMISSION_MANAGE_CERTIFICATES => [
                        'label' => Craft::t('forklift', 'Manage tax exemption certificates'),
                    ],
                ],
            ];
        });
    }

    private function registerProjectConfig(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();

        $projectConfig
            ->onAdd(self::CONFIG_COMPANY_FIELD_LAYOUT_KEY, [$this, 'handleChangedCompanyFieldLayout'])
            ->onUpdate(self::CONFIG_COMPANY_FIELD_LAYOUT_KEY, [$this, 'handleChangedCompanyFieldLayout'])
            ->onRemove(self::CONFIG_COMPANY_FIELD_LAYOUT_KEY, [$this, 'handleChangedCompanyFieldLayout'])
            ->onAdd(self::CONFIG_QUOTE_FIELD_LAYOUT_KEY, [$this, 'handleChangedQuoteFieldLayout'])
            ->onUpdate(self::CONFIG_QUOTE_FIELD_LAYOUT_KEY, [$this, 'handleChangedQuoteFieldLayout'])
            ->onRemove(self::CONFIG_QUOTE_FIELD_LAYOUT_KEY, [$this, 'handleChangedQuoteFieldLayout']);

        Event::on(ProjectConfig::class, ProjectConfig::EVENT_REBUILD, function(RebuildConfigEvent $event) {
            foreach ([
                [Company::class, 'companies'],
                [Quote::class, 'quotes'],
            ] as [$class, $key]) {
                $layout = Craft::$app->getFields()->getLayoutByType($class);

                if ($layout->uid !== null) {
                    $event->config['forklift'][$key]['fieldLayouts'] = [$layout->uid => $layout->getConfig()];
                }
            }
        });
    }

    public function saveFieldLayout(FieldLayout $layout, string $elementType, string $configKey): bool
    {
        $layout->type = $elementType;
        $layout->uid ??= StringHelper::UUID();

        Craft::$app->getProjectConfig()->set(
            $configKey,
            [$layout->uid => $layout->getConfig()],
            'Save Forklift’s field layout',
        );

        return true;
    }

    public function handleChangedCompanyFieldLayout(ConfigEvent $event): void
    {
        $this->_applyFieldLayout($event, Company::class);
    }

    public function handleChangedQuoteFieldLayout(ConfigEvent $event): void
    {
        $this->_applyFieldLayout($event, Quote::class);
    }

    private function _applyFieldLayout(ConfigEvent $event, string $elementType): void
    {
        $data = $event->newValue;
        $fields = Craft::$app->getFields();

        if (empty($data) || empty($config = reset($data))) {
            $fields->deleteLayoutsByType($elementType);

            return;
        }

        ProjectConfigHelper::ensureAllFieldsProcessed();

        $layout = FieldLayout::createFromConfig($config);
        $layout->id = $fields->getLayoutByType($elementType)->id;
        $layout->type = $elementType;
        $layout->uid = key($data);
        $fields->saveLayout($layout, false);

        Craft::$app->getElements()->invalidateCachesForElementType($elementType);
    }

    private function registerSystemMessages(): void
    {
        Event::on(SystemMessages::class, SystemMessages::EVENT_REGISTER_MESSAGES, function(RegisterEmailMessagesEvent $event) {
            foreach (Notifications::systemMessages() as $message) {
                $event->messages[] = $message;
            }
        });
    }

    private function registerTwig(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            $event->sender->set('forklift', ForkliftVariable::class);
        });
    }

    /**
     * Two things that happen because time passed rather than because anybody did anything.
     *
     * Hooked to Craft's own garbage collection. All three are cheap, idempotent and bounded, so
     * they run inline rather than being queued.
     */
    private function registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            if ($this->isPro()) {
                $this->approvals->expireStale();
                $this->quotes->expireStale();
            }

            $this->certificates->syncExpired();
        });
    }

    /** The Forklift panel on Commerce's own order edit screen. */
    private function registerCpHooks(): void
    {
        Craft::$app->getView()->hook('cp.commerce.order.edit.details', function(array &$context) {
            $order = $context['order'] ?? null;

            if (!$order instanceof Order || !$order->id) {
                return null;
            }

            try {
                return Craft::$app->getView()->renderTemplate('forklift/_cp/order-panel', [
                    'order' => $order,
                    'company' => $this->orders->getCompanyForOrder($order),
                    'poNumber' => $this->orders->getPoNumberForOrder((int)$order->id),
                    'terms' => $this->orders->getTermsForOrder((int)$order->id),
                    'approval' => $this->approvals->getLatestForOrder((int)$order->id),
                    'quote' => $this->quotes->getQuoteForOrder($order),
                    'row' => $this->orders->getRowForOrder((int)$order->id),
                ]);
            } catch (\Throwable $e) {
                Craft::error('Forklift could not render the order panel: ' . $e->getMessage(), self::LOG_CATEGORY);

                return null;
            }
        });
    }
}
