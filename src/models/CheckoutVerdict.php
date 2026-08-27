<?php

declare(strict_types=1);

namespace justinholtweb\forklift\models;

use Craft;
use craft\base\Model;

/**
 * The one answer to "may this order be placed, and if not, why not".
 *
 * `services\Checkout::verdict()` builds it; the front end, the before-complete gate, the gateway
 * availability check and the control panel all read it. That is the whole point — a button that
 * says "Submit for approval" and a checkout that silently completes the order are two different
 * pieces of code disagreeing, and this makes them one piece of code agreeing with itself.
 *
 * A verdict is a **list of reasons**, never a boolean. "Blocked" and "needs approval" and "over
 * the credit limit" want different words in front of a buyer, and an order can be more than one
 * of them at once — the buyer who is over their own spend limit *and* whose company is on hold
 * should not fix the first and be surprised by the second.
 */
class CheckoutVerdict extends Model
{
    /** The company's account is on hold or closed. */
    public const REASON_COMPANY_ON_HOLD = 'companyOnHold';

    /** This buyer's role does not allow placing orders. */
    public const REASON_ROLE_CANNOT_PURCHASE = 'roleCannotPurchase';

    /** Over the buyer's per-order spend limit. */
    public const REASON_OVER_SPEND_LIMIT = 'overSpendLimit';

    /** Over the company's threshold, so somebody has to sign it off. */
    public const REASON_NEEDS_APPROVAL = 'needsApproval';

    /** An approval exists and has not been decided yet. */
    public const REASON_AWAITING_APPROVAL = 'awaitingApproval';

    /** An approval exists and was declined. */
    public const REASON_APPROVAL_DECLINED = 'approvalDeclined';

    /** Paying on terms would take the company past its credit limit. */
    public const REASON_OVER_CREDIT_LIMIT = 'overCreditLimit';

    /** The company insists on a purchase-order number and none was given. */
    public const REASON_PO_NUMBER_REQUIRED = 'poNumberRequired';

    /**
     * Reasons that stop the order dead.
     *
     * `REASON_NEEDS_APPROVAL` is deliberately not here: needing approval is what the *buyer* does
     * next, not a refusal — they submit, and the order then carries `AWAITING_APPROVAL`, which is.
     * `REASON_OVER_CREDIT_LIMIT` is not here either, because it only blocks the purchase-order
     * gateway; the buyer can still pay by card. The gateway asks about that reason by name.
     */
    public const BLOCKING = [
        self::REASON_COMPANY_ON_HOLD,
        self::REASON_ROLE_CANNOT_PURCHASE,
        self::REASON_OVER_SPEND_LIMIT,
        self::REASON_AWAITING_APPROVAL,
        self::REASON_APPROVAL_DECLINED,
        self::REASON_PO_NUMBER_REQUIRED,
    ];

    public ?int $companyId = null;
    public ?int $memberId = null;

    /** @var string[] */
    public array $reasons = [];

    /** @var array<string, string> Reason => the sentence a buyer should read. */
    public array $messages = [];

    /**
     * True when the licence has lapsed and an approval rule was therefore not enforced.
     *
     * The order is allowed through — a store that cannot check out is worse — but the flag is
     * written onto `forklift_orders` so the merchant can find every order this happened to.
     */
    public bool $approvalBypassed = false;

    /** The credit that would remain after this order, when the company is on terms. */
    public ?float $creditRemaining = null;

    /** Amount by which the order exceeds a limit, when one is exceeded. */
    public ?float $excessAmount = null;

    public function add(string $reason, string $message): static
    {
        if (!in_array($reason, $this->reasons, true)) {
            $this->reasons[] = $reason;
        }

        $this->messages[$reason] = $message;

        return $this;
    }

    public function has(string $reason): bool
    {
        return in_array($reason, $this->reasons, true);
    }

    /** Whether the order may complete right now. */
    public function getIsAllowed(): bool
    {
        return array_intersect($this->reasons, self::BLOCKING) === [];
    }

    /**
     * Whether the buyer's next action is to ask somebody, rather than to fix something.
     *
     * The distinction the checkout button needs: "Place order" against "Submit for approval".
     */
    public function getNeedsApproval(): bool
    {
        return $this->has(self::REASON_NEEDS_APPROVAL) && !$this->has(self::REASON_AWAITING_APPROVAL);
    }

    public function getIsAwaitingApproval(): bool
    {
        return $this->has(self::REASON_AWAITING_APPROVAL);
    }

    /** @return string[] Only the sentences that are actually stopping the order. */
    public function getBlockingMessages(): array
    {
        $out = [];

        foreach ($this->reasons as $reason) {
            if (in_array($reason, self::BLOCKING, true)) {
                $out[] = $this->messages[$reason];
            }
        }

        return $out;
    }

    /** @return string[] Every sentence, blocking or not. */
    public function getAllMessages(): array
    {
        return array_values($this->messages);
    }

    /** The label the checkout button should carry. */
    public function getActionLabel(): string
    {
        if ($this->getIsAwaitingApproval()) {
            return Craft::t('forklift', 'Awaiting approval');
        }

        if ($this->getNeedsApproval()) {
            return Craft::t('forklift', 'Submit for approval');
        }

        return Craft::t('forklift', 'Place order');
    }
}
