<?php

declare(strict_types=1);

namespace justinholtweb\forklift\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\web\Controller;
use craft\web\UploadedFile;
use craft\web\View;
use justinholtweb\forklift\models\QuickOrderResult;
use justinholtweb\forklift\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The quick-order pad, the CSV upload and reorder.
 *
 * Anonymous is allowed on the pad and the lookup, deliberately: a wholesale store that shows list
 * prices to the public still wants a trade visitor to be able to type SKUs before they sign in,
 * and the resolver simply returns list prices when there is no company. What is *not* anonymous
 * is anything that reads another party's order.
 */
class QuickOrderController extends Controller
{
    protected array|bool|int $allowAnonymous = ['index', 'lookup', 'suggest', 'preview', 'add'];

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->_render('pad', [
            'company' => $plugin->companies->getCurrentCompany(),
            'rows' => $plugin->getSettings()->quickOrderRows,
            'allowCsv' => \justinholtweb\forklift\models\Edition::allowsCsvUpload($plugin->isPro()),
            'result' => null,
        ]);
    }

    /**
     * One SKU, one quantity, one answer — for a pad that prices as you type.
     *
     * JSON only. A front end asking "what is this and what does it cost me" should never have to
     * parse HTML for the number.
     */
    public function actionLookup(): Response
    {
        $this->requireAcceptsJson();

        $plugin = Plugin::getInstance();
        $sku = (string)$this->request->getParam('sku', '');
        $qty = max(1, (int)$this->request->getParam('qty', 1));

        $purchasable = $plugin->quickOrder->findBySku($sku);

        if ($purchasable === null) {
            return $this->asJson([
                'found' => false,
                'error' => Craft::t('forklift', '“{sku}” was not found.', ['sku' => $sku]),
            ]);
        }

        $price = $plugin->pricing->resolve($purchasable, $qty, $plugin->companies->getCurrentCompany()?->id);

        return $this->asJson([
            'found' => true,
            'purchasableId' => $purchasable->getId(),
            'sku' => $purchasable->getSku(),
            'description' => $purchasable->getDescription(),
            'price' => $price->price,
            'listPrice' => $price->listPrice,
            'subtotal' => $price->getSubtotal(),
            'source' => $price->source,
            'sourceLabel' => $price->getSourceLabel(),
            'saving' => $price->getSaving(),
            'nextBreak' => $price->nextBreak,
        ]);
    }

    /** SKU autocomplete. */
    public function actionSuggest(): Response
    {
        $this->requireAcceptsJson();

        $plugin = Plugin::getInstance();

        return $this->asJson([
            'results' => $plugin->quickOrder->suggest(
                (string)$this->request->getParam('q', ''),
                $plugin->companies->getCurrentCompany()?->id,
            ),
        ]);
    }

    /** Resolve rows without touching the cart — what the upload screen shows before confirming. */
    public function actionPreview(): Response
    {
        $this->requirePostRequest();

        $result = $this->_resolve(true);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson($this->_json($result));
        }

        return $this->_render('preview', [
            'result' => $result,
            'company' => Plugin::getInstance()->companies->getCurrentCompany(),
            'rows' => Plugin::getInstance()->getSettings()->quickOrderRows,
        ]);
    }

    /** Add the rows to the cart. Failed rows are reported; the rest still go in. */
    public function actionAdd(): Response
    {
        $this->requirePostRequest();

        $result = $this->_resolve(false);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson($this->_json($result));
        }

        if ($result->fatalError !== null) {
            $this->setFailFlash($result->fatalError);
        } elseif ($result->added > 0) {
            $this->setSuccessFlash(Craft::t('forklift', '{count} lines added to your basket.', ['count' => $result->added]));
        }

        if ($result->getHasErrors()) {
            return $this->_render('preview', [
                'result' => $result,
                'company' => Plugin::getInstance()->companies->getCurrentCompany(),
                'rows' => Plugin::getInstance()->getSettings()->quickOrderRows,
            ]);
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Rebuild a previous order, at today's prices.
     *
     * The order has to belong to the visitor's company or to the visitor themselves. Reordering
     * is the one place where "show me a past order" and "let me have its contents" meet, and an
     * order id in a form field is exactly the kind of thing people try incrementing.
     */
    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireLogin();

        $plugin = Plugin::getInstance();
        $orderId = (int)$this->request->getRequiredBodyParam('orderId');
        $order = Commerce::getInstance()?->getOrders()->getOrderById($orderId);

        if ($order === null) {
            throw new NotFoundHttpException('Order not found');
        }

        $user = Craft::$app->getUser()->getIdentity();
        $company = $plugin->companies->getCurrentCompany();
        $orderCompanyId = $plugin->orders->getCompanyIdForOrder($orderId);

        $mine = $order->getCustomer()?->id === $user?->id;
        $ours = $company !== null && $orderCompanyId === (int)$company->id;

        if (!$mine && !$ours) {
            throw new ForbiddenHttpException(Craft::t('forklift', 'That order is not yours to reorder.'));
        }

        $dryRun = (bool)$this->request->getBodyParam('preview', false);
        $result = $plugin->quickOrder->fromOrder($order, $company?->id, $dryRun);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson($this->_json($result));
        }

        if (!$dryRun && $result->added > 0) {
            $this->setSuccessFlash(Craft::t('forklift', '{count} lines added to your basket.', ['count' => $result->added]));
        }

        if ($dryRun || $result->getHasErrors()) {
            return $this->_render('preview', [
                'result' => $result,
                'company' => $company,
                'order' => $order,
                'rows' => $plugin->getSettings()->quickOrderRows,
            ]);
        }

        return $this->redirectToPostedUrl();
    }

    // Internals
    // -------------------------------------------------------------------------

    /**
     * Rows from either an upload or the typed pad.
     *
     * An uploaded file always wins when both are present, because a visitor who chose a file
     * meant the file.
     */
    private function _resolve(bool $dryRun): QuickOrderResult
    {
        $plugin = Plugin::getInstance();
        $companyId = $plugin->companies->getCurrentCompany()?->id;

        $file = UploadedFile::getInstanceByName('file');

        if ($file !== null) {
            $contents = file_get_contents($file->tempName);

            if ($contents === false) {
                $result = new QuickOrderResult(['dryRun' => $dryRun]);
                $result->fatalError = Craft::t('forklift', 'The file could not be read.');

                return $result;
            }

            return $plugin->quickOrder->fromCsv($contents, $dryRun, null, $companyId);
        }

        $pasted = $this->request->getBodyParam('csv');

        if (is_string($pasted) && trim($pasted) !== '') {
            return $plugin->quickOrder->fromCsv($pasted, $dryRun, null, $companyId);
        }

        $rows = $this->request->getBodyParam('rows', []);

        if (!is_array($rows)) {
            $rows = [];
        }

        return $dryRun
            ? $plugin->quickOrder->resolve($rows, $companyId)
            : $plugin->quickOrder->add($rows, null, $companyId);
    }

    /** @return array<string, mixed> */
    private function _json(QuickOrderResult $result): array
    {
        return [
            'added' => $result->added,
            'dryRun' => $result->dryRun,
            'fatalError' => $result->fatalError,
            'subtotal' => $result->getSubtotal(),
            'totalQty' => $result->getTotalQty(),
            'rows' => array_map(static fn($row) => [
                'lineNumber' => $row->lineNumber,
                'sku' => $row->sku,
                'qty' => $row->qty,
                'description' => $row->description,
                'purchasableId' => $row->purchasableId,
                'price' => $row->price?->price,
                'listPrice' => $row->price?->listPrice,
                'subtotal' => $row->getSubtotal(),
                'sourceLabel' => $row->price?->getSourceLabel(),
                'error' => $row->error,
                'warning' => $row->warning,
                'valid' => $row->getIsValid(),
            ], $result->rows),
        ];
    }

    /** Site template first, Forklift's fallback second — same rule as the portal. */
    private function _render(string $name, array $variables): Response
    {
        $siteTemplate = '_forklift/quick-order/' . $name;

        if (Craft::$app->getView()->doesTemplateExist($siteTemplate)) {
            return $this->renderTemplate($siteTemplate, $variables);
        }

        return $this->renderTemplate('forklift/_portal/' . $name, $variables, View::TEMPLATE_MODE_CP);
    }
}
