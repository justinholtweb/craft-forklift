<?php

declare(strict_types=1);

namespace justinholtweb\forklift\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\web\Controller;
use justinholtweb\forklift\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The actions behind Forklift's panel on Commerce's own order edit screen.
 *
 * Deliberately small. Commerce owns the order screen and Forklift is a guest on it — three fields
 * that Commerce has nowhere to put, and nothing that duplicates what Commerce already does well.
 */
class OrderController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('commerce-editOrders');

        return true;
    }

    /** Move an order onto a different account, or off one. */
    public function actionSetCompany(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_COMPANIES);

        $orderId = (int)$this->request->getRequiredBodyParam('orderId');
        $companyId = $this->request->getBodyParam('companyId');
        $companyId = ($companyId === null || $companyId === '') ? null : (int)$companyId;

        $order = Commerce::getInstance()?->getOrders()->getOrderById($orderId);

        if ($order === null) {
            throw new NotFoundHttpException('Order not found');
        }

        Plugin::getInstance()->orders->setCompanyForOrder($order, $companyId);

        // An incomplete order is re-saved so its line items re-price against the new account. A
        // *completed* one is not: re-pricing an invoice that has been sent would be a very
        // surprising side effect of tidying up an account assignment.
        if (!$order->isCompleted) {
            Plugin::getInstance()->pricing->clearCaches();
            Craft::$app->getElements()->saveElement($order, false);
        }

        return $this->asSuccess(Craft::t('forklift', 'Company updated.'));
    }

    public function actionSetPoNumber(): Response
    {
        $this->requirePostRequest();

        $orderId = (int)$this->request->getRequiredBodyParam('orderId');
        $poNumber = $this->request->getBodyParam('poNumber');

        Plugin::getInstance()->orders->setValuesForOrder($orderId, [
            'poNumber' => ($poNumber === null || $poNumber === '') ? null : (string)$poNumber,
        ]);

        return $this->asSuccess(Craft::t('forklift', 'Purchase order number updated.'));
    }

    /**
     * Raise the invoice for an order that completed while something was broken.
     *
     * The repair path for the one thing that can silently go missing: post-completion bookkeeping
     * runs inside a `try` so that a failure cannot take down the request the customer is looking
     * at, which means a failure leaves no charge on the ledger. This puts it right, and it is
     * idempotent — running it on an order that already has its charge does nothing.
     */
    public function actionRaiseCharge(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_CREDIT);

        $orderId = (int)$this->request->getRequiredBodyParam('orderId');
        $order = Commerce::getInstance()?->getOrders()->getOrderById($orderId);

        if ($order === null) {
            throw new NotFoundHttpException('Order not found');
        }

        $entry = Plugin::getInstance()->credit->chargeOrder($order);

        if ($entry === null) {
            return $this->asFailure(Craft::t('forklift', 'This order is not on a company account.'));
        }

        return $this->asSuccess(Craft::t('forklift', 'Charge on the ledger.'));
    }
}
