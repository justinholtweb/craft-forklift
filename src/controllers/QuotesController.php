<?php

declare(strict_types=1);

namespace justinholtweb\forklift\controllers;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use justinholtweb\forklift\elements\Quote;
use justinholtweb\forklift\models\QuoteLine;
use justinholtweb\forklift\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Quotes in the control panel: the pricing screen and the send.
 *
 * The edit screen is a line editor, not an element editor with a field layout stuck on the side,
 * because the job it exists for is "change these numbers and press send". Every line shows what
 * the customer would otherwise pay, so the merchant is discounting against something visible
 * rather than typing into a void.
 */
class QuotesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW_QUOTES);

        if (!Plugin::getInstance()->getSettings()->getEffectiveQuotesEnabled()) {
            throw new ForbiddenHttpException(Craft::t('forklift', 'Quotes are not available on this edition.'));
        }

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('forklift/quotes/_index', [
            'title' => Craft::t('forklift', 'Quotes'),
            'elementType' => Quote::class,
        ]);
    }

    public function actionEdit(?int $quoteId = null, ?Quote $quote = null): Response
    {
        $plugin = Plugin::getInstance();

        if ($quote === null) {
            $quote = $quoteId !== null
                ? $plugin->quotes->getQuoteById($quoteId)
                : new Quote();

            if ($quote === null) {
                throw new NotFoundHttpException('Quote not found');
            }
        }

        // Opening a request marks it as being worked on, so two salespeople do not both price the
        // same one. Deliberately not a lock — a lock somebody forgot to release is worse than two
        // people who can see each other's edits.
        if ($quote->quoteStatus === Quote::STATUS_REQUESTED && $quote->id) {
            $quote->quoteStatus = Quote::STATUS_PRICING;
            $plugin->quotes->saveQuote($quote, false);
        }

        return $this->renderTemplate('forklift/quotes/_edit', [
            'quote' => $quote,
            'isNew' => !$quote->id,
            'title' => $quote->id
                ? Craft::t('forklift', 'Quote {number}', ['number' => $quote->number])
                : Craft::t('forklift', 'New quote'),
            'lines' => $quote->getLines(),
            'paymentUrl' => $quote->id ? $quote->getPaymentUrl() : null,
            'canEdit' => $quote->getIsEditable(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_QUOTES);

        $quote = $this->_quoteFromRequest();

        if (!Plugin::getInstance()->quotes->saveQuote($quote)) {
            $this->setFailFlash(Craft::t('forklift', 'Couldn’t save quote.'));
            Craft::$app->getUrlManager()->setRouteParams(['quote' => $quote]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('forklift', 'Quote saved.'));

        return $this->redirectToPostedUrl($quote);
    }

    /**
     * Save, build the cart, and email the customer the link.
     *
     * One action rather than three, because a quote saved but not materialised, or materialised
     * but not sent, is a state nobody wants to be in and every state is a support call.
     */
    public function actionSend(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_QUOTES);

        $plugin = Plugin::getInstance();
        $quote = $this->_quoteFromRequest();

        if (!$plugin->quotes->saveQuote($quote)) {
            $this->setFailFlash(Craft::t('forklift', 'Couldn’t save quote.'));
            Craft::$app->getUrlManager()->setRouteParams(['quote' => $quote]);

            return null;
        }

        $expiry = $this->_date($this->request->getBodyParam('expiryDate'));
        $notify = (bool)$this->request->getBodyParam('notify', true);

        if (!$plugin->quotes->send($quote, $expiry, $notify)) {
            // The quote keeps its previous status. A quote marked "sent" whose link goes nowhere
            // is worse than one still in the queue.
            $this->setFailFlash(Craft::t('forklift', 'The quote was saved but its cart could not be built, so it has not been sent.'));

            return $this->redirect($quote->getCpEditUrl());
        }

        $this->setSuccessFlash($notify
            ? Craft::t('forklift', 'Quote sent.')
            : Craft::t('forklift', 'Quote marked as sent. Copy the link to send it yourself.'));

        return $this->redirect($quote->getCpEditUrl());
    }

    public function actionDecline(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_QUOTES);

        $quoteId = (int)$this->request->getRequiredBodyParam('quoteId');
        $plugin = Plugin::getInstance();
        $quote = $plugin->quotes->getQuoteById($quoteId);

        if ($quote === null) {
            throw new NotFoundHttpException('Quote not found');
        }

        $plugin->quotes->markDeclined($quote, $this->request->getBodyParam('note'));

        return $this->asSuccess(Craft::t('forklift', 'Quote closed.'), [], $quote->getCpEditUrl());
    }

    /**
     * Re-price every line at the customer's current contract prices.
     *
     * What a salesperson wants when a quote has been sitting for a fortnight: start from what the
     * customer would pay today, then discount from there.
     */
    public function actionReprice(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_QUOTES);

        $quoteId = (int)$this->request->getRequiredBodyParam('quoteId');
        $plugin = Plugin::getInstance();
        $quote = $plugin->quotes->getQuoteById($quoteId);

        if ($quote === null) {
            throw new NotFoundHttpException('Quote not found');
        }

        $lines = [];

        foreach ($quote->getLines() as $line) {
            if ($line->purchasableId) {
                $result = $plugin->pricing->resolve((int)$line->purchasableId, $line->qty, $quote->companyId);
                $line->listPrice = $result->listPrice;
                $line->price = $result->price;
            }

            $lines[] = $line;
        }

        $quote->setLines($lines);
        $plugin->quotes->saveQuote($quote, false);

        return $this->asSuccess(Craft::t('forklift', 'Lines re-priced.'), [], $quote->getCpEditUrl());
    }

    // Internals
    // -------------------------------------------------------------------------

    private function _quoteFromRequest(): Quote
    {
        $request = $this->request;
        $plugin = Plugin::getInstance();
        $quoteId = $request->getBodyParam('quoteId');

        $quote = $quoteId ? $plugin->quotes->getQuoteById((int)$quoteId) : new Quote();

        if ($quote === null) {
            throw new NotFoundHttpException('Quote not found');
        }

        $quote->title = $request->getBodyParam('title', $quote->title);
        $quote->companyId = $this->_firstId($request->getBodyParam('companyId'));
        $quote->email = $request->getBodyParam('email', $quote->email);
        $quote->reference = $request->getBodyParam('reference') ?: null;
        $quote->internalNote = $request->getBodyParam('internalNote') ?: null;
        $quote->shippingCost = $this->_nullableFloat($request->getBodyParam('shippingCost'));
        $quote->discount = $this->_nullableFloat($request->getBodyParam('discount'));

        $expiry = $this->_date($request->getBodyParam('expiryDate'));

        if ($expiry !== null) {
            $quote->expiryDate = $expiry;
        }

        $quote->setFieldValuesFromRequest('fields');

        // Null means the line editor was not on the screen at all — a status change, say. An
        // absent field must not empty a quotation.
        $posted = $request->getBodyParam('lines');

        if ($posted !== null) {
            $quote->setLines($this->_linesFromPost((array)$posted, $quote));
        }

        return $quote;
    }

    /** @return QuoteLine[] */
    private function _linesFromPost(array $posted, Quote $quote): array
    {
        $plugin = Plugin::getInstance();
        $lines = [];
        $sortOrder = 0;

        foreach ($posted as $row) {
            if (!is_array($row)) {
                continue;
            }

            $qty = max(0, (int)($row['qty'] ?? 0));

            // A quantity of zero is how a merchant deletes a line from a table editor, and it is
            // the gesture people reach for. Treating it as "order none of these" would send the
            // customer a quotation with a blank row on it.
            if ($qty === 0) {
                continue;
            }

            $purchasableId = isset($row['purchasableId']) && $row['purchasableId'] !== ''
                ? (int)$row['purchasableId']
                : null;

            $sku = isset($row['sku']) && $row['sku'] !== '' ? (string)$row['sku'] : null;

            if ($purchasableId === null && $sku !== null) {
                $purchasableId = $plugin->quickOrder->findBySku($sku)?->getId();
            }

            $listPrice = isset($row['listPrice']) && $row['listPrice'] !== ''
                ? (float)$row['listPrice']
                : ($purchasableId ? $plugin->pricing->listPriceFor($purchasableId) : null);

            $lines[] = new QuoteLine([
                'id' => isset($row['id']) && $row['id'] !== '' ? (int)$row['id'] : null,
                'quoteId' => $quote->id,
                'purchasableId' => $purchasableId,
                'sku' => $sku,
                'description' => isset($row['description']) && $row['description'] !== '' ? (string)$row['description'] : null,
                'qty' => $qty,
                'listPrice' => $listPrice,
                'price' => (float)($row['price'] ?? 0),
                'note' => isset($row['note']) && $row['note'] !== '' ? (string)$row['note'] : null,
                'sortOrder' => ++$sortOrder,
            ]);
        }

        return $lines;
    }

    private function _firstId(mixed $value): ?int
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        return $value ? (int)$value : null;
    }

    private function _nullableFloat(mixed $value): ?float
    {
        return ($value === null || $value === '') ? null : (float)$value;
    }

    private function _date(mixed $value): ?\DateTime
    {
        return $value ? (DateTimeHelper::toDateTime($value) ?: null) : null;
    }
}
