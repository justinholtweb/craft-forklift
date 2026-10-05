<?php

declare(strict_types=1);

namespace justinholtweb\forklift\services;

use Craft;
use craft\helpers\App;
use craft\models\SystemMessage;
use justinholtweb\forklift\elements\Quote;
use justinholtweb\forklift\models\Approval;
use justinholtweb\forklift\Plugin;
use yii\base\Component;

/**
 * The four emails Forklift sends.
 *
 * Registered as Craft **system messages**, which means the store edits their subject and body in
 * the control panel, in every language it has, without touching a template — and it means a store
 * that wants to send something entirely different can, because the message body is theirs.
 *
 * Every send is wrapped and logged rather than thrown. An approval that was granted but whose
 * confirmation email bounced is an inconvenience; an exception on the way out of `approve()` that
 * rolls the decision back is a support call. The decision is the important half.
 */
class Notifications extends Component
{
    public const MESSAGE_APPROVAL_REQUESTED = 'forklift_approval_requested';
    public const MESSAGE_APPROVAL_DECIDED = 'forklift_approval_decided';
    public const MESSAGE_QUOTE_REQUESTED = 'forklift_quote_requested';
    public const MESSAGE_QUOTE_SENT = 'forklift_quote_sent';

    /** @return SystemMessage[] */
    public static function systemMessages(): array
    {
        return [
            new SystemMessage([
                'key' => self::MESSAGE_APPROVAL_REQUESTED,
                'heading' => Craft::t('forklift', 'When an order needs approval:'),
                'subject' => Craft::t('forklift', 'An order needs your approval'),
                'body' => Craft::t('forklift', "{requester} has submitted an order for {amount} that needs your approval.\n\nReason: {reason}\n\nReview it here: {link}"),
            ]),
            new SystemMessage([
                'key' => self::MESSAGE_APPROVAL_DECIDED,
                'heading' => Craft::t('forklift', 'When an order has been approved or declined:'),
                'subject' => Craft::t('forklift', 'Your order has been {status}'),
                'body' => Craft::t('forklift', "Your order for {amount} has been {status}{note}.\n\nYou can continue here: {link}"),
            ]),
            new SystemMessage([
                'key' => self::MESSAGE_QUOTE_REQUESTED,
                'heading' => Craft::t('forklift', 'When a customer asks for a quote:'),
                'subject' => Craft::t('forklift', 'New quote request {number}'),
                'body' => Craft::t('forklift', "{requester} has asked for a quote.\n\n{lines} lines, {qty} items.\n\nPrice it here: {link}"),
            ]),
            new SystemMessage([
                'key' => self::MESSAGE_QUOTE_SENT,
                'heading' => Craft::t('forklift', 'When a quote is sent to a customer:'),
                'subject' => Craft::t('forklift', 'Your quote {number}'),
                'body' => Craft::t('forklift', "Here is your quote, {number}, valid until {expiry}.\n\nTotal: {total}\n\nTo accept it and check out: {link}"),
            ]),
        ];
    }

    /** Everybody at the company who can decide this, by email. */
    public function approvalRequested(Approval $approval): void
    {
        $members = Plugin::getInstance()->members->getApproversForCompany((int)$approval->companyId);

        if ($members === []) {
            // An account with a threshold and nobody able to approve is a configuration mistake
            // worth finding in the log rather than one that silently strands every order.
            Craft::warning(
                "Approval #{$approval->id} has no approvers at company #{$approval->companyId}.",
                Plugin::LOG_CATEGORY,
            );

            return;
        }

        $order = $approval->getOrder();

        $variables = [
            'requester' => $approval->getRequester()?->getName() ?? Craft::t('forklift', 'A buyer'),
            'amount' => $this->_money($approval->amount, $approval->currency),
            'reason' => $approval->getReasonLabel(),
            'link' => $approval->getDecisionUrl() ?? '',
            'approval' => $approval,
            'order' => $order,
        ];

        foreach ($members as $member) {
            $user = $member->getUser();

            if ($user === null || !$user->email) {
                continue;
            }

            $this->_send(self::MESSAGE_APPROVAL_REQUESTED, $user->email, $variables + ['approver' => $user]);
        }
    }

    public function approvalDecided(Approval $approval): void
    {
        $requester = $approval->getRequester();

        if ($requester === null || !$requester->email) {
            return;
        }

        $note = $approval->decisionNote
            ? Craft::t('forklift', ' — {note}', ['note' => $approval->decisionNote])
            : '';

        $this->_send(self::MESSAGE_APPROVAL_DECIDED, $requester->email, [
            'status' => $approval->getIsApproved()
                ? Craft::t('forklift', 'approved')
                : Craft::t('forklift', 'declined'),
            'amount' => $this->_money($approval->amount, $approval->currency),
            'note' => $note,
            'link' => $approval->getOrder()?->getLoadCartUrl() ?? '',
            'approval' => $approval,
            'order' => $approval->getOrder(),
        ]);
    }

    /** The store's own address, not the customer's — this one goes to whoever prices quotes. */
    public function quoteRequested(Quote $quote): void
    {
        $to = App::mailSettings()->fromEmail;

        if (!$to) {
            return;
        }

        $this->_send(self::MESSAGE_QUOTE_REQUESTED, $to, [
            'number' => (string)$quote->number,
            'requester' => $quote->getRequester()?->getName() ?? ($quote->email ?? Craft::t('forklift', 'A visitor')),
            'lines' => count($quote->getLines()),
            'qty' => $quote->getTotalQty(),
            'link' => (string)$quote->getCpEditUrl(),
            'quote' => $quote,
        ]);
    }

    public function quoteSent(Quote $quote): void
    {
        $to = $quote->getContactEmail();

        if (!$to) {
            return;
        }

        $this->_send(self::MESSAGE_QUOTE_SENT, $to, [
            'number' => (string)$quote->number,
            'expiry' => $quote->expiryDate
                ? Craft::$app->getFormatter()->asDate($quote->expiryDate, 'long')
                : Craft::t('forklift', 'further notice'),
            'total' => $this->_money($quote->getTotal(), $quote->currency),
            'link' => (string)$quote->getPaymentUrl(),
            'quote' => $quote,
        ]);
    }

    /**
     * Send one system message.
     *
     * Failures are logged, never thrown — see the class docblock.
     */
    private function _send(string $key, string $to, array $variables = []): bool
    {
        try {
            $message = Craft::$app->getMailer()
                ->composeFromKey($key, $variables)
                ->setTo($to);

            return $message->send();
        } catch (\Throwable $e) {
            Craft::error(
                "Forklift could not send “{$key}” to {$to}: " . $e->getMessage(),
                Plugin::LOG_CATEGORY,
            );

            return false;
        }
    }

    private function _money(float $amount, ?string $currency): string
    {
        if ($currency) {
            try {
                return Craft::$app->getFormatter()->asCurrency($amount, $currency);
            } catch (\Throwable) {
                // An unknown or malformed currency code. A plain number beats an exception in an
                // email nobody can now send.
            }
        }

        return Craft::$app->getFormatter()->asDecimal($amount, 2);
    }
}
