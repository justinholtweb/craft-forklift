<?php

declare(strict_types=1);

namespace justinholtweb\forklift\gateways;

use Craft;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\elements\Order;
use craft\commerce\gateways\Manual;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\responses\Manual as ManualResponse;
use craft\commerce\models\Transaction;
use justinholtweb\forklift\models\CheckoutVerdict;
use justinholtweb\forklift\models\payments\PurchaseOrderPaymentForm;
use justinholtweb\forklift\Plugin;

/**
 * Pay on account: net terms against a credit limit, with a purchase order number.
 *
 * ## Why it extends Commerce's Manual gateway
 *
 * Because paying on terms *is* an offline payment, and Commerce already models one correctly. The
 * gateway is configured with `paymentType: authorize`, so completing checkout records an
 * **authorised** transaction rather than a captured one: the order completes, the customer gets
 * their confirmation and their goods are picked, and `totalPaid` stays at zero — which is exactly
 * true, because nobody has paid yet. When the cheque arrives, the merchant captures the
 * transaction and Commerce marks the order paid using its own machinery.
 *
 * Building this on a bespoke "invoice" concept instead would mean every report, every paid-status
 * filter and every third-party integration in the store would have to learn about it.
 *
 * ## When it offers itself
 *
 * `availableForUseWithOrder()` asks {@see \justinholtweb\forklift\services\Checkout::canPayOnTerms()},
 * which is the same verdict the checkout gate reads. So a gateway that offers itself and a
 * checkout that then refuses cannot happen. It withdraws when:
 *
 * - the licence has lapsed (fails closed — no new credit can be opened);
 * - the order has no company, or the company is not set up for terms;
 * - the account is on hold or closed;
 * - the order would take the account past its credit limit.
 *
 * That last one is a withdrawal of *this* gateway, not a refusal of the order. A customer over
 * their limit holding out a credit card should be allowed to pay with it.
 */
class PurchaseOrder extends Manual
{
    public static function displayName(): string
    {
        return Craft::t('forklift', 'Purchase Order (Forklift)');
    }

    public function init(): void
    {
        // Authorise rather than purchase, so the order completes unpaid and the merchant captures
        // when the money arrives. A store can still change it, but this is the only setting that
        // makes the paid status tell the truth.
        $this->paymentType = $this->paymentType ?: 'authorize';

        parent::init();
    }

    public function supportsAuthorize(): bool
    {
        return true;
    }

    public function supportsCapture(): bool
    {
        return true;
    }

    /**
     * Purchase is refused deliberately.
     *
     * A "purchase" marks the order paid, and an order on thirty-day terms is not paid. A store
     * that switched this gateway to `purchase` would produce a ledger where every invoice is
     * settled the moment it is raised.
     */
    public function supportsPurchase(): bool
    {
        return false;
    }

    public function getPaymentFormModel(): BasePaymentForm
    {
        return new PurchaseOrderPaymentForm();
    }

    public function getPaymentFormHtml(array $params): ?string
    {
        return Craft::$app->getView()->renderTemplate('forklift/_gateway/payment-form', array_merge([
            'gateway' => $this,
            'paymentForm' => $params['paymentForm'] ?? new PurchaseOrderPaymentForm(),
        ], $params));
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('forklift/_gateway/settings', [
            'gateway' => $this,
        ]);
    }

    public function availableForUseWithOrder(Order $order): bool
    {
        if (!Plugin::getInstance()->checkout->canPayOnTerms($order)) {
            return false;
        }

        return parent::availableForUseWithOrder($order);
    }

    /**
     * Record the purchase order number, then authorise.
     *
     * The number is written to Forklift's order row rather than to the transaction, because it is
     * a fact about the *order* — it goes on the packing note and the invoice, and it has to
     * survive a payment being voided and retried.
     */
    public function authorize(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        if ($form instanceof PurchaseOrderPaymentForm) {
            $order = $transaction->getOrder();

            if ($order->id) {
                $values = ['poNumber' => $form->poNumber ?: null];

                // The due date is fixed at authorisation, from the terms on the order, so a later
                // change to the company's default terms does not silently re-date an invoice that
                // has already been sent.
                $terms = Plugin::getInstance()->orders->getTermsForOrder((int)$order->id);

                if ($terms !== null) {
                    $values['dueDate'] = $terms->dueDate(new \DateTime());
                }

                Plugin::getInstance()->orders->setValuesForOrder((int)$order->id, $values);
            }
        }

        return new ManualResponse();
    }

    /** The words a buyer sees on the checkout, so they know what they are agreeing to. */
    public function getTermsDescription(Order $order): ?string
    {
        if (!$order->id) {
            return null;
        }

        $plugin = Plugin::getInstance();
        $terms = $plugin->orders->getTermsForOrder((int)$order->id);
        $companyId = $plugin->orders->getCompanyIdForOrder((int)$order->id);

        if ($terms === null || $companyId === null) {
            return null;
        }

        $available = $plugin->credit->availableFor($companyId);

        if ($available === null) {
            return Craft::t('forklift', 'Invoiced on {terms}.', ['terms' => $terms->getShorthand()]);
        }

        return Craft::t('forklift', 'Invoiced on {terms}. {available} of credit available.', [
            'terms' => $terms->getShorthand(),
            'available' => Craft::$app->getFormatter()->asDecimal(max(0, $available), 2),
        ]);
    }

    /** Whether this order's company insists on a purchase order number. */
    public function getRequiresPoNumber(Order $order): bool
    {
        if (!$order->id) {
            return false;
        }

        $company = Plugin::getInstance()->orders->getCompanyForOrder($order);

        return (bool)$company?->requiresPoNumber
            && Plugin::getInstance()->getSettings()->enforcePoNumber;
    }

    /** Why the gateway is unavailable, for a template that wants to explain rather than hide. */
    public function getUnavailableReason(Order $order): ?string
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->getSettings()->getEffectiveCreditEnabled()) {
            return Craft::t('forklift', 'Paying on account is not available.');
        }

        $company = $plugin->orders->getCompanyForOrder($order);

        if ($company === null || !$company->getHasCredit()) {
            return Craft::t('forklift', 'This account is not set up to pay on terms.');
        }

        $verdict = $plugin->checkout->verdict($order);

        return $verdict->messages[CheckoutVerdict::REASON_OVER_CREDIT_LIMIT]
            ?? $verdict->messages[CheckoutVerdict::REASON_COMPANY_ON_HOLD]
            ?? null;
    }
}
