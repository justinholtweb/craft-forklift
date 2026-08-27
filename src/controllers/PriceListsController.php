<?php

declare(strict_types=1);

namespace justinholtweb\forklift\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\helpers\DateTimeHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\UploadedFile;
use justinholtweb\forklift\elements\Company;
use justinholtweb\forklift\models\PriceList;
use justinholtweb\forklift\models\PriceListEntry;
use justinholtweb\forklift\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Contract price lists in the control panel.
 *
 * The screen that earns its keep is {@see actionPreview()} — "what does this customer pay for
 * this SKU at this quantity, and why". A price list with ten thousand rows and two overlapping
 * lists is genuinely hard to reason about by reading, and a merchant looking at an unexpected
 * number needs the *reason* rather than the number. It runs the real resolver, so a preview that
 * says £8.40 is a promise the cart will keep.
 */
class PriceListsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_MANAGE_PRICE_LISTS);

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $lists = $plugin->priceLists->getAllPriceLists();
        $counts = [];

        foreach ($lists as $list) {
            $counts[(int)$list->id] = $plugin->priceLists->getEntryCount((int)$list->id);
        }

        return $this->renderTemplate('forklift/price-lists/_index', [
            'priceLists' => $lists,
            'counts' => $counts,
            'title' => Craft::t('forklift', 'Price lists'),
            'suppressed' => !$plugin->getSettings()->getEffectivePriceListsEnabled(),
        ]);
    }

    public function actionEdit(?int $priceListId = null, ?PriceList $priceList = null): Response
    {
        $plugin = Plugin::getInstance();

        if ($priceList === null) {
            $priceList = $priceListId !== null
                ? $plugin->priceLists->getPriceListById($priceListId)
                : new PriceList();

            if ($priceList === null) {
                throw new NotFoundHttpException('Price list not found');
            }
        }

        return $this->renderTemplate('forklift/price-lists/_edit', [
            'priceList' => $priceList,
            'isNew' => !$priceList->id,
            'title' => $priceList->id ? (string)$priceList->name : Craft::t('forklift', 'New price list'),
            'entryCount' => $priceList->id ? $plugin->priceLists->getEntryCount((int)$priceList->id) : 0,
            'companies' => $priceList->id
                ? Company::find()->id($priceList->getCompanyIds() ?: [0])->status(null)->all()
                : [],
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = $this->request;
        $plugin = Plugin::getInstance();
        $priceListId = $request->getBodyParam('priceListId');

        $priceList = $priceListId
            ? $plugin->priceLists->getPriceListById((int)$priceListId)
            : new PriceList();

        if ($priceList === null) {
            throw new NotFoundHttpException('Price list not found');
        }

        $priceList->name = $request->getBodyParam('name');
        $priceList->handle = $request->getBodyParam('handle');
        $priceList->description = $request->getBodyParam('description') ?: null;
        $priceList->priority = (int)$request->getBodyParam('priority', 0);
        $priceList->allCompanies = (bool)$request->getBodyParam('allCompanies');
        $priceList->enabled = (bool)$request->getBodyParam('enabled', true);
        $priceList->dateFrom = $this->_date($request->getBodyParam('dateFrom'));
        $priceList->dateTo = $this->_date($request->getBodyParam('dateTo'));

        // Null when the field was not posted at all, which leaves the assignments alone. An
        // absent form field must never unassign every customer from a contract.
        $companyIds = $request->getBodyParam('companyIds');
        $priceList->setCompanyIds($companyIds === null ? null : array_filter((array)$companyIds));

        if (!$plugin->priceLists->savePriceList($priceList)) {
            $this->setFailFlash(Craft::t('forklift', 'Couldn’t save price list.'));
            Craft::$app->getUrlManager()->setRouteParams(['priceList' => $priceList]);

            return null;
        }

        $plugin->pricing->clearCaches();
        $this->setSuccessFlash(Craft::t('forklift', 'Price list saved.'));

        return $this->redirectToPostedUrl($priceList);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $priceListId = (int)$this->request->getRequiredBodyParam('priceListId');
        Plugin::getInstance()->priceLists->deletePriceListById($priceListId);
        Plugin::getInstance()->pricing->clearCaches();

        return $this->asSuccess(Craft::t('forklift', 'Price list deleted.'));
    }

    // Entries
    // -------------------------------------------------------------------------

    public function actionEntries(int $priceListId): Response
    {
        $plugin = Plugin::getInstance();
        $priceList = $plugin->priceLists->getPriceListById($priceListId);

        if ($priceList === null) {
            throw new NotFoundHttpException('Price list not found');
        }

        return $this->renderTemplate('forklift/price-lists/_entries', [
            'priceList' => $priceList,
            'entries' => $priceList->getEntries(),
            'title' => Craft::t('forklift', '{name} — prices', ['name' => $priceList->name]),
            'targetOptions' => [
                ['label' => Craft::t('forklift', 'A single product variant'), 'value' => PriceListEntry::TARGET_PURCHASABLE],
                ['label' => Craft::t('forklift', 'Every variant of a product'), 'value' => PriceListEntry::TARGET_PRODUCT],
                ['label' => Craft::t('forklift', 'Every product of a type'), 'value' => PriceListEntry::TARGET_PURCHASABLE_TYPE],
                ['label' => Craft::t('forklift', 'Everything'), 'value' => PriceListEntry::TARGET_ALL],
            ],
            'priceTypeOptions' => [
                ['label' => Craft::t('forklift', 'Fixed price'), 'value' => PriceListEntry::PRICE_FIXED],
                ['label' => Craft::t('forklift', 'Percentage off list'), 'value' => PriceListEntry::PRICE_PERCENT_OFF],
                ['label' => Craft::t('forklift', 'Amount off list'), 'value' => PriceListEntry::PRICE_AMOUNT_OFF],
            ],
            'productTypes' => $this->_productTypeOptions(),
        ]);
    }

    public function actionSaveEntry(): Response
    {
        $this->requirePostRequest();

        $request = $this->request;
        $plugin = Plugin::getInstance();
        $entryId = $request->getBodyParam('entryId');

        $entry = new PriceListEntry([
            'id' => $entryId ? (int)$entryId : null,
            'priceListId' => (int)$request->getRequiredBodyParam('priceListId'),
            'targetType' => (string)$request->getBodyParam('targetType', PriceListEntry::TARGET_PURCHASABLE),
            'targetId' => $this->_firstId($request->getBodyParam('targetId')),
            'sku' => $request->getBodyParam('sku') ?: null,
            'minQty' => max(1, (int)$request->getBodyParam('minQty', 1)),
            'priceType' => (string)$request->getBodyParam('priceType', PriceListEntry::PRICE_FIXED),
            'amount' => (float)$request->getBodyParam('amount', 0),
        ]);

        // A row entered by SKU should be resolved to an id straight away, so the hot path never
        // has to do a SKU lookup per line per recalculation.
        if ($entry->targetType === PriceListEntry::TARGET_PURCHASABLE && !$entry->targetId && $entry->sku) {
            $entry->targetId = $plugin->quickOrder->findBySku($entry->sku)?->getId();
        }

        if (!$plugin->priceLists->saveEntry($entry)) {
            return $this->asModelFailure($entry, Craft::t('forklift', 'Couldn’t save price.'), 'entry');
        }

        $plugin->pricing->clearCaches();

        return $this->asModelSuccess($entry, Craft::t('forklift', 'Price saved.'), 'entry');
    }

    public function actionDeleteEntry(): Response
    {
        $this->requirePostRequest();

        $entryId = (int)$this->request->getRequiredBodyParam('entryId');
        Plugin::getInstance()->priceLists->deleteEntryById($entryId);
        Plugin::getInstance()->pricing->clearCaches();

        return $this->asSuccess(Craft::t('forklift', 'Price deleted.'));
    }

    // Import and export
    // -------------------------------------------------------------------------

    public function actionImport(int $priceListId): Response
    {
        $priceList = Plugin::getInstance()->priceLists->getPriceListById($priceListId);

        if ($priceList === null) {
            throw new NotFoundHttpException('Price list not found');
        }

        return $this->renderTemplate('forklift/price-lists/_import', [
            'priceList' => $priceList,
            'title' => Craft::t('forklift', '{name} — import', ['name' => $priceList->name]),
        ]);
    }

    /**
     * Import a CSV of prices.
     *
     * **A replace, not a merge**, and the screen says so before the button is pressed. The
     * spreadsheet is the contract; a row the merchant deleted from it should not survive in the
     * store because nothing mentioned it.
     *
     * Columns: `sku, price, minQty` — with `minQty` optional and defaulting to 1, and `price`
     * accepting a bare number (a fixed price) or a trailing `%` (a percentage off list).
     */
    public function actionRunImport(): ?Response
    {
        $this->requirePostRequest();

        $priceListId = (int)$this->request->getRequiredBodyParam('priceListId');
        $plugin = Plugin::getInstance();
        $priceList = $plugin->priceLists->getPriceListById($priceListId);

        if ($priceList === null) {
            throw new NotFoundHttpException('Price list not found');
        }

        $file = UploadedFile::getInstanceByName('file');
        $contents = $file ? file_get_contents($file->tempName) : (string)$this->request->getBodyParam('csv');

        if (!$contents) {
            $this->setFailFlash(Craft::t('forklift', 'No file or text was given.'));

            return null;
        }

        [$entries, $errors] = $this->_parseImport($contents);

        if ($entries === []) {
            $this->setFailFlash(Craft::t('forklift', 'Nothing could be read from that file.'));

            return null;
        }

        $count = $plugin->priceLists->replaceEntries($priceListId, $entries);
        $resolved = $plugin->priceLists->resolveSkus($priceListId);
        $plugin->pricing->clearCaches();

        $message = Craft::t('forklift', '{count} prices imported, {resolved} matched to products.', [
            'count' => $count,
            'resolved' => $resolved,
        ]);

        if ($errors !== []) {
            // Skipped rows are named rather than counted. "14 rows were skipped" tells a merchant
            // nothing they can act on.
            $message .= ' ' . Craft::t('forklift', 'Skipped: {rows}', [
                'rows' => implode(', ', array_slice($errors, 0, 10)) . (count($errors) > 10 ? '…' : ''),
            ]);
        }

        $this->setSuccessFlash($message);

        return $this->redirect(UrlHelper::cpUrl('forklift/price-lists/' . $priceListId . '/entries'));
    }

    /** Export a price list as the CSV the import reads back. */
    public function actionExport(int $priceListId): Response
    {
        $plugin = Plugin::getInstance();
        $priceList = $plugin->priceLists->getPriceListById($priceListId);

        if ($priceList === null) {
            throw new NotFoundHttpException('Price list not found');
        }

        $rows = "sku,price,minQty\n";

        foreach ($priceList->getEntries() as $entry) {
            $price = $entry->priceType === PriceListEntry::PRICE_PERCENT_OFF
                ? rtrim(rtrim(number_format($entry->amount, 4, '.', ''), '0'), '.') . '%'
                : number_format($entry->amount, 4, '.', '');

            $rows .= sprintf(
                "%s,%s,%d\n",
                '"' . str_replace('"', '""', (string)($entry->sku ?? $entry->getTargetLabel())) . '"',
                $price,
                $entry->minQty,
            );
        }

        return $this->response->sendContentAsFile(
            $rows,
            ($priceList->handle ?: 'price-list') . '.csv',
            ['mimeType' => 'text/csv'],
        );
    }

    // Preview
    // -------------------------------------------------------------------------

    /**
     * "What does this customer pay for this SKU, and why."
     *
     * Runs the real resolver rather than a control-panel approximation of it, which is the only
     * way a preview can be trusted. The result carries the winning list, the entry, the break and
     * — when a contract price was found and *not* used — the reason.
     */
    public function actionPreview(): Response
    {
        $request = $this->request;
        $plugin = Plugin::getInstance();

        $sku = $request->getParam('sku');
        $qty = max(1, (int)$request->getParam('qty', 1));
        $companyId = $request->getParam('companyId') ? (int)$request->getParam('companyId') : null;

        $result = null;
        $breaks = [];
        $error = null;
        $purchasable = null;

        if ($sku) {
            $purchasable = $plugin->quickOrder->findBySku((string)$sku);

            if ($purchasable === null) {
                $error = Craft::t('forklift', 'No product has the SKU “{sku}”.', ['sku' => $sku]);
            } else {
                $result = $plugin->pricing->resolve($purchasable, $qty, $companyId);
                $breaks = $plugin->pricing->breaksFor($purchasable, $companyId);
            }
        }

        if ($request->getAcceptsJson()) {
            return $this->asJson([
                'error' => $error,
                'result' => $result?->toArray(),
                'sourceLabel' => $result?->getSourceLabel(),
                'saving' => $result?->getSaving(),
            ]);
        }

        return $this->renderTemplate('forklift/price-lists/_preview', [
            'title' => Craft::t('forklift', 'Price preview'),
            'sku' => $sku,
            'qty' => $qty,
            'companyId' => $companyId,
            'company' => $companyId ? $plugin->companies->getCompanyById($companyId) : null,
            'purchasable' => $purchasable,
            'result' => $result,
            'breaks' => $breaks,
            'error' => $error,
        ]);
    }

    // Internals
    // -------------------------------------------------------------------------

    /**
     * @return array{0: PriceListEntry[], 1: string[]} The entries, and the rows that were skipped.
     */
    private function _parseImport(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        $entries = [];
        $errors = [];
        $line = 0;

        while (($cells = fgetcsv($handle, 4096)) !== false) {
            $line++;

            if ($cells === [null] || $cells === false) {
                continue;
            }

            $cells = array_map(static fn($cell) => trim((string)$cell), $cells);

            if (implode('', $cells) === '') {
                continue;
            }

            if ($line === 1 && in_array(strtolower($cells[0]), ['sku', 'code', 'part', 'item'], true)) {
                continue;
            }

            $sku = $cells[0] ?? '';
            $price = $cells[1] ?? '';

            if ($sku === '' || $price === '') {
                $errors[] = Craft::t('forklift', 'line {line}', ['line' => $line]);
                continue;
            }

            $isPercent = str_ends_with($price, '%');
            $amount = (float)str_replace(['%', ',', ' '], '', $price);

            if ($amount < 0) {
                $errors[] = Craft::t('forklift', 'line {line}', ['line' => $line]);
                continue;
            }

            $entries[] = new PriceListEntry([
                'targetType' => PriceListEntry::TARGET_PURCHASABLE,
                'sku' => $sku,
                'minQty' => max(1, (int)($cells[2] ?? 1)),
                'priceType' => $isPercent ? PriceListEntry::PRICE_PERCENT_OFF : PriceListEntry::PRICE_FIXED,
                'amount' => $amount,
            ]);
        }

        fclose($handle);

        return [$entries, $errors];
    }

    /** @return array<int, array{label: string, value: string}> */
    private function _productTypeOptions(): array
    {
        $options = [];

        try {
            foreach (Commerce::getInstance()?->getProductTypes()->getAllProductTypes() ?? [] as $type) {
                $options[] = ['label' => $type->name, 'value' => (string)$type->id];
            }
        } catch (\Throwable) {
            // A store with no product types is a store that has not been set up yet.
        }

        return $options;
    }

    private function _firstId(mixed $value): ?int
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        return $value ? (int)$value : null;
    }

    private function _date(mixed $value): ?\DateTime
    {
        if (!$value) {
            return null;
        }

        return DateTimeHelper::toDateTime($value) ?: null;
    }
}
