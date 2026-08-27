<?php

declare(strict_types=1);

namespace justinholtweb\forklift\services;

use Craft;
use craft\commerce\elements\Order;
use justinholtweb\forklift\elements\Company;
use justinholtweb\forklift\models\Approval;
use justinholtweb\forklift\models\CheckoutVerdict;
use justinholtweb\forklift\models\Edition;
use justinholtweb\forklift\models\Member;
use justinholtweb\forklift\models\Role;
use justinholtweb\forklift\Plugin;
use yii\base\Component;

/**
 * **Invariant 2: this is the only place an order's B2B eligibility is decided.**
 *
 * The front-end button, the `EVENT_BEFORE_COMPLETE_ORDER` gate, the purchase-order gateway's
 * availability check and the control panel's order panel all call {@see verdict()} and read the
 * same {@see CheckoutVerdict}. A button that says "Submit for approval" and a checkout that
 * silently completes the order are two pieces of code disagreeing; this makes them one piece of
 * code agreeing with itself.
 *
 * ## The order the reasons are gathered in
 *
 * All of them, always — never short-circuiting on the first refusal. A buyer who is over their
 * spend limit *and* whose company is on hold should be told both, rather than fixing one and
 * meeting the other.
 *
 * ## Where an existing approval fits
 *
 * The interesting case is an order that has been approved and then *edited*. The verdict asks
 * `Approvals::isStillValidFor()`, which compares the order's current total against the amount
 * that was signed off — so a £400 approval does not authorise a £4,400 cart. When it no longer
 * covers the order, the order needs approval again rather than being blocked outright: the buyer
 * can resubmit, which is the useful behaviour.
 *
 * ## Fail-open, once, on purpose
 *
 * On a lapsed licence, approval rules stop **blocking** checkout. A store that cannot check out
 * at all is worse than one that lets an order through. The verdict records `approvalBypassed`,
 * the order row is stamped with it, and the control panel says so — the failure is open but it is
 * never silent. Everything else on a lapsed licence fails closed: price lists stop applying, so
 * buyers pay list price, and the purchase-order gateway withdraws itself.
 */
class Checkout extends Component
{
    /** @var array<string, CheckoutVerdict> */
    private array $_cache = [];

    /**
     * May this order be placed, and if not, why not.
     *
     * Memoized on the order's number *and* its total, so a recalculated cart is never answered
     * from a stale verdict — memoizing on the number alone is the mistake Commerce's own
     * shipping-rule cache makes, and it produces a verdict for a basket that no longer exists.
     */
    public function verdict(Order $order): CheckoutVerdict
    {
        $key = ($order->number ?? spl_object_hash($order)) . ':' . number_format((float)$order->getTotalPrice(), 4, '.', '');

        return $this->_cache[$key] ??= $this->_build($order);
    }

    private function _build(Order $order): CheckoutVerdict
    {
        $verdict = new CheckoutVerdict();
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $company = $plugin->orders->getCompanyForOrder($order);

        // No company means no B2B rules at all. A retail order through the same store is not
        // Forklift's business, and inventing rules for it would be the fastest way to break a
        // store that installed this plugin for one customer in twelve.
        if ($company === null) {
            return $verdict;
        }

        $verdict->companyId = $company->id;
        $total = round((float)$order->getTotalPrice(), 2);

        $this->_checkCompany($verdict, $company);

        $member = $plugin->orders->getMemberForOrder($order);
        $verdict->memberId = $member?->id;

        $this->_checkMember($verdict, $member, $total);
        $this->_checkPoNumber($verdict, $order, $company, $settings);
        $this->_checkApproval($verdict, $order, $company, $member, $total);
        $this->_checkCredit($verdict, $order, $company, $total);

        return $verdict;
    }

    private function _checkCompany(CheckoutVerdict $verdict, Company $company): void
    {
        if ($company->getCanPurchase()) {
            return;
        }

        $verdict->add(
            CheckoutVerdict::REASON_COMPANY_ON_HOLD,
            $company->getIsOnHold()
                ? Craft::t('forklift', 'This account is on hold. Please contact us to place an order.')
                : Craft::t('forklift', 'This account is closed and cannot place orders.'),
        );
    }

    private function _checkMember(CheckoutVerdict $verdict, ?Member $member, float $total): void
    {
        // No membership row means the customer is buying against a company they are not on — an
        // administrator placing an order on somebody's behalf in the control panel, most often.
        // That is legitimate and carries no personal spend limit.
        if ($member === null) {
            return;
        }

        if (!Role::canPurchase($member->role)) {
            $verdict->add(
                CheckoutVerdict::REASON_ROLE_CANNOT_PURCHASE,
                Craft::t('forklift', 'Your account can view orders but not place them. Ask an administrator to change your role.'),
            );

            return;
        }

        // A limit of exactly zero means "may not order unassisted" — the new starter. Handled by
        // the approval check rather than as a refusal, because there is somebody who *can* say
        // yes and the buyer should be pointed at them.
        if ($member->spendLimit !== null && $member->spendLimit > 0 && $total > $member->spendLimit) {
            $verdict->excessAmount = round($total - $member->spendLimit, 2);
        }
    }

    private function _checkPoNumber(CheckoutVerdict $verdict, Order $order, Company $company, $settings): void
    {
        if (!$company->requiresPoNumber || !$settings->enforcePoNumber) {
            return;
        }

        $poNumber = $order->id ? Plugin::getInstance()->orders->getPoNumberForOrder((int)$order->id) : null;

        if ($poNumber !== null && trim($poNumber) !== '') {
            return;
        }

        $verdict->add(
            CheckoutVerdict::REASON_PO_NUMBER_REQUIRED,
            Craft::t('forklift', 'This account requires a purchase order number on every order.'),
        );
    }

    /**
     * Whether somebody has to sign this off, and whether they already have.
     *
     * Three ways to need approval, and the *reason* matters because it is written onto the
     * request and read back by an auditor: the company's threshold, the buyer's own limit, or a
     * flag on the buyer saying they always need one.
     */
    private function _checkApproval(CheckoutVerdict $verdict, Order $order, Company $company, ?Member $member, float $total): void
    {
        $plugin = Plugin::getInstance();
        $reason = null;

        if ($member !== null && $member->requiresApproval) {
            $reason = Approval::REASON_MEMBER_ALWAYS;
        } elseif ($member !== null && $member->spendLimit !== null && $total > $member->spendLimit) {
            $reason = Approval::REASON_OVER_SPEND_LIMIT;
        } elseif ($company->approvalThreshold !== null && $total >= $company->approvalThreshold) {
            $reason = Approval::REASON_OVER_THRESHOLD;
        }

        if ($reason === null) {
            return;
        }

        // The one place Forklift fails open, and it leaves a mark. See the class docblock.
        if (!Edition::allowsApprovals($plugin->isPro())) {
            $verdict->approvalBypassed = true;

            return;
        }

        $approval = $order->id ? $plugin->approvals->getLatestForOrder((int)$order->id) : null;

        if ($approval !== null && $approval->getIsPending()) {
            $verdict->add(
                CheckoutVerdict::REASON_AWAITING_APPROVAL,
                Craft::t('forklift', 'This order is waiting for approval. You will be emailed when it has been decided.'),
            );

            return;
        }

        if ($approval !== null && $approval->status === Approval::STATUS_DECLINED) {
            $verdict->add(
                CheckoutVerdict::REASON_APPROVAL_DECLINED,
                $approval->decisionNote
                    ? Craft::t('forklift', 'This order was declined: {note}', ['note' => $approval->decisionNote])
                    : Craft::t('forklift', 'This order was declined by an approver.'),
            );

            return;
        }

        // An approval that has been granted still has to cover what is in the cart *now*.
        if ($approval !== null && $plugin->approvals->isStillValidFor($approval, $order)) {
            return;
        }

        $verdict->add(
            CheckoutVerdict::REASON_NEEDS_APPROVAL,
            $this->_approvalMessage($reason, $approval !== null),
        );
    }

    private function _approvalMessage(string $reason, bool $wasApprovedForLess): string
    {
        if ($wasApprovedForLess) {
            return Craft::t('forklift', 'This order has grown since it was approved, so it needs approving again.');
        }

        return match ($reason) {
            Approval::REASON_MEMBER_ALWAYS => Craft::t('forklift', 'Your orders need approval before they can be placed.'),
            Approval::REASON_OVER_SPEND_LIMIT => Craft::t('forklift', 'This order is over your spend limit and needs approval.'),
            default => Craft::t('forklift', 'This order is over your company’s approval threshold and needs approval.'),
        };
    }

    /**
     * Whether the account has the credit for this.
     *
     * **Not blocking.** Being over the credit limit stops the order going *on account*; it does
     * not stop the buyer paying by card. Adding it to `CheckoutVerdict::BLOCKING` would refuse
     * checkout entirely to a customer holding out a credit card, which is a way to lose money
     * rather than to protect it. The purchase-order gateway asks for this reason by name and
     * withdraws itself when it is present.
     */
    private function _checkCredit(CheckoutVerdict $verdict, Order $order, Company $company, float $total): void
    {
        if (!$company->getHasCredit()) {
            return;
        }

        $plugin = Plugin::getInstance();
        $available = $plugin->credit->availableFor((int)$company->id);

        if ($available === null) {
            return;
        }

        $verdict->creditRemaining = round($available - $total, 2);

        if (!$plugin->getSettings()->reserveCreditOnOrder) {
            // Counting only invoices that have been raised lets a buyer place six orders in an
            // afternoon that each pass on their own, so this is the non-default path and it is
            // documented as such.
            if ($plugin->credit->balanceFor((int)$company->id) <= ($company->creditLimit ?? 0)) {
                return;
            }
        }

        if ($total <= $available) {
            return;
        }

        $verdict->add(
            CheckoutVerdict::REASON_OVER_CREDIT_LIMIT,
            Craft::t('forklift', 'This order would take the account past its credit limit. {available} of credit is available.', [
                'available' => Craft::$app->getFormatter()->asDecimal(max(0, $available), 2),
            ]),
        );
    }

    /**
     * Whether this order may pay on terms.
     *
     * Asked by the purchase-order gateway. Separate from `getIsAllowed()` because the two answer
     * different questions: "may this order be placed at all" and "may it be placed on account".
     */
    public function canPayOnTerms(Order $order): bool
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->getSettings()->getEffectiveCreditEnabled()) {
            return false;
        }

        $company = $plugin->orders->getCompanyForOrder($order);

        if ($company === null || !$company->getHasCredit() || !$company->getCanPurchase()) {
            return false;
        }

        return !$this->verdict($order)->has(CheckoutVerdict::REASON_OVER_CREDIT_LIMIT);
    }

    public function clearCaches(): void
    {
        $this->_cache = [];
    }
}
