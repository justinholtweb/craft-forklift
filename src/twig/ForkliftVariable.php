<?php

declare(strict_types=1);

namespace justinholtweb\forklift\twig;

use Craft;
use craft\commerce\base\PurchasableInterface;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use justinholtweb\forklift\elements\Company;
use justinholtweb\forklift\elements\db\CompanyQuery;
use justinholtweb\forklift\elements\db\QuoteQuery;
use justinholtweb\forklift\elements\Quote;
use justinholtweb\forklift\models\Approval;
use justinholtweb\forklift\models\CheckoutVerdict;
use justinholtweb\forklift\models\Member;
use justinholtweb\forklift\models\PriceResult;
use justinholtweb\forklift\models\Statement;
use justinholtweb\forklift\Plugin;

/**
 * `craft.forklift` — everything a front end needs, and nothing that writes.
 *
 * The rule this follows: **a template asks questions, a controller makes changes.** There is no
 * `addToCart()` here and no `approve()`, because a Twig template that mutates state is a template
 * that mutates state on a bot's page view.
 *
 * The two that matter most are {@see price()} and {@see verdict()}, which are the same two
 * invariants the rest of the plugin reads — so what a product page prints and what a checkout
 * charges come from one implementation, and what a button says is what the gate will do.
 */
class ForkliftVariable
{
    // Who is buying
    // -------------------------------------------------------------------------

    /** The company this visitor is buying for, or null. */
    public function company(): ?Company
    {
        return Plugin::getInstance()->companies->getCurrentCompany();
    }

    /** Their membership of it, which carries the role and the spend limit. */
    public function member(): ?Member
    {
        return Plugin::getInstance()->companies->getCurrentMember();
    }

    /**
     * Every company this visitor buys for.
     *
     * More than one means the front end should offer a switcher.
     *
     * @return Company[]
     */
    public function companies(): array
    {
        $user = Craft::$app->getUser()->getIdentity();

        return $user ? Plugin::getInstance()->companies->getCompaniesForUser($user) : [];
    }

    public function isB2b(): bool
    {
        return $this->company() !== null;
    }

    /** A company element query, for a template that wants to list accounts. */
    public function companyQuery(array $criteria = []): CompanyQuery
    {
        /** @var CompanyQuery $query */
        $query = Company::find();

        if ($criteria !== []) {
            Craft::configure($query, $criteria);
        }

        return $query;
    }

    // Pricing
    // -------------------------------------------------------------------------

    /**
     * What this visitor pays for a purchasable.
     *
     * The result carries the list price, the saving, the reason and the next quantity break, so a
     * product page can print "Your price £8.40 — 12+ £7.10" without a second lookup and without a
     * second opinion about what the price is.
     */
    public function price(PurchasableInterface|int $purchasable, int $qty = 1): PriceResult
    {
        return Plugin::getInstance()->pricing->resolve($purchasable, $qty, $this->company()?->id);
    }

    /**
     * The whole quantity-break ladder, cheapest quantity first.
     *
     * Empty when this visitor has no breaks on this product, so `{% if breaks %}` is the right
     * test in a template.
     *
     * @return PriceResult[]
     */
    public function breaks(PurchasableInterface|int $purchasable): array
    {
        return Plugin::getInstance()->pricing->breaksFor($purchasable, $this->company()?->id);
    }

    /** Whether this visitor gets a price of their own on this purchasable. */
    public function hasContractPrice(PurchasableInterface|int $purchasable, int $qty = 1): bool
    {
        return $this->price($purchasable, $qty)->getIsContractPrice();
    }

    // Checkout
    // -------------------------------------------------------------------------

    /**
     * May this order be placed, and if not, why not.
     *
     * The same verdict the checkout gate reads, so `verdict.actionLabel` on the button is what
     * pressing it will actually do.
     */
    public function verdict(?Order $order = null): CheckoutVerdict
    {
        $order ??= Commerce::getInstance()?->getCarts()->getCart();

        if ($order === null) {
            return new CheckoutVerdict();
        }

        return Plugin::getInstance()->checkout->verdict($order);
    }

    /** Whether the cart may go on account. Asks the same question the gateway asks. */
    public function canPayOnTerms(?Order $order = null): bool
    {
        $order ??= Commerce::getInstance()?->getCarts()->getCart();

        return $order !== null && Plugin::getInstance()->checkout->canPayOnTerms($order);
    }

    // Approvals
    // -------------------------------------------------------------------------

    /**
     * Requests this visitor can decide.
     *
     * @return Approval[]
     */
    public function pendingApprovals(): array
    {
        $user = Craft::$app->getUser()->getIdentity();

        return $user ? Plugin::getInstance()->approvals->getPendingForApprover($user) : [];
    }

    /** The live or most recent approval for an order. */
    public function approvalFor(Order $order): ?Approval
    {
        return $order->id ? Plugin::getInstance()->approvals->getLatestForOrder((int)$order->id) : null;
    }

    // Credit
    // -------------------------------------------------------------------------

    /** What this visitor's company owes. Null when they have no account or may not see it. */
    public function balance(): ?float
    {
        return $this->_financialsVisible()
            ? Plugin::getInstance()->credit->balanceFor((int)$this->company()->id)
            : null;
    }

    /** Credit still available, or null for no limit — and null when they may not see it. */
    public function creditAvailable(): ?float
    {
        return $this->_financialsVisible()
            ? Plugin::getInstance()->credit->availableFor((int)$this->company()->id)
            : null;
    }

    /**
     * The company's statement.
     *
     * Returns null rather than an empty statement when the visitor's role does not include seeing
     * the finances — a buyer is not entitled to the account's balance, and an empty statement
     * would read like a settled one.
     */
    public function statement(?\DateTime $from = null, ?\DateTime $to = null): ?Statement
    {
        if (!$this->_financialsVisible()) {
            return null;
        }

        return Plugin::getInstance()->credit->statement((int)$this->company()->id, $from, $to);
    }

    // Quotes
    // -------------------------------------------------------------------------

    /** A quote element query scoped to this visitor's company. */
    public function quotes(array $criteria = []): QuoteQuery
    {
        /** @var QuoteQuery $query */
        $query = Quote::find();
        $company = $this->company();

        if ($company !== null) {
            $query->companyId($company->id);
        } else {
            $user = Craft::$app->getUser()->getIdentity();
            // A guest quote belongs to nobody, so an anonymous visitor gets nothing rather than
            // everything — the difference between an empty list and a data leak.
            $query->requesterId($user->id ?? 0);
        }

        if ($criteria !== []) {
            Craft::configure($query, $criteria);
        }

        return $query;
    }

    // Orders
    // -------------------------------------------------------------------------

    /**
     * The company's orders, not just this person's.
     *
     * The thing a buyer actually wants from a B2B portal: what did *we* order, so they can
     * reorder it. A viewer sees them too — that is the role's entire purpose.
     */
    public function orders(int $limit = 25): array
    {
        $company = $this->company();

        if ($company === null) {
            return [];
        }

        $ids = Plugin::getInstance()->orders->getOrderIdsForCompany((int)$company->id, $limit);

        if ($ids === []) {
            return [];
        }

        return Order::find()->id($ids)->isCompleted(true)->fixedOrder(true)->all();
    }

    /** The purchase order number recorded against an order. */
    public function poNumber(Order $order): ?string
    {
        return $order->id ? Plugin::getInstance()->orders->getPoNumberForOrder((int)$order->id) : null;
    }

    // Settings and edition
    // -------------------------------------------------------------------------

    public function settings(): \justinholtweb\forklift\models\Settings
    {
        return Plugin::getInstance()->getSettings();
    }

    public function isPro(): bool
    {
        return Plugin::getInstance()->isPro();
    }

    /** How many rows the quick-order pad should render. */
    public function padRows(): int
    {
        return Plugin::getInstance()->getSettings()->quickOrderRows;
    }

    private function _financialsVisible(): bool
    {
        $company = $this->company();
        $member = $this->member();

        return $company !== null && $member !== null && $member->getCanSeeFinancials();
    }
}
