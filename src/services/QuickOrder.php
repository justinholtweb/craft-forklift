<?php

declare(strict_types=1);

namespace justinholtweb\forklift\services;

use Craft;
use craft\commerce\base\PurchasableInterface;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use justinholtweb\forklift\models\Edition;
use justinholtweb\forklift\models\QuickOrderResult;
use justinholtweb\forklift\models\QuickOrderRow;
use justinholtweb\forklift\Plugin;
use yii\base\Component;

/**
 * The quick-order pad, CSV upload and reorder — three doors into one room.
 *
 * A wholesale buyer does not browse. They have a list of part numbers on a sheet of paper, in a
 * spreadsheet, or in last month's order, and what they want is to turn that list into a basket
 * without visiting sixty product pages. All three routes produce the same
 * {@see QuickOrderResult}, so a SKU that cannot be found reads identically however it arrived.
 *
 * ## Nothing is ever silently dropped
 *
 * A two-hundred-line upload that half worked is the normal case, not the exception. Every row
 * comes back — valid or not — carrying the text the buyer typed and, when it failed, a sentence
 * saying why and the line number it was on. A pad that quietly adds a hundred and eighty-three of
 * two hundred lines is worse than one that refuses the file, because the buyer finds out at the
 * loading bay.
 *
 * ## Dry runs
 *
 * `resolve()` produces the preview and `add()` produces the outcome, in the same shape, so the
 * confirmation screen and the result screen are one template. The upload path always previews
 * first: pasting a column of quantities into the wrong column is a mistake worth catching before
 * it becomes a purchase order.
 */
class QuickOrder extends Component
{
    /**
     * Resolve rows without touching the cart.
     *
     * @param array<int, array{sku?: string, qty?: int|string, note?: string}> $rows
     */
    public function resolve(array $rows, ?int $companyId = null): QuickOrderResult
    {
        $result = new QuickOrderResult(['dryRun' => true]);
        $companyId ??= Plugin::getInstance()->companies->getCurrentCompany()?->id;

        $lineNumber = 0;

        foreach ($rows as $raw) {
            $lineNumber++;
            $row = $this->_row($raw, $lineNumber);

            if ($row === null) {
                // A wholly blank row is somebody's spare pad line, not an error.
                continue;
            }

            $result->rows[] = $this->_resolveRow($row, $companyId);
        }

        return $result;
    }

    /**
     * Resolve and add to a cart.
     *
     * Rows that fail are reported and the rest still go in. Refusing the whole basket because one
     * part number was discontinued would make a buyer re-key the other hundred and ninety-nine.
     *
     * @param array<int, array{sku?: string, qty?: int|string, note?: string}> $rows
     */
    public function add(array $rows, ?Order $cart = null, ?int $companyId = null): QuickOrderResult
    {
        $commerce = Commerce::getInstance();
        $cart ??= $commerce?->getCarts()->getCart();

        if ($commerce === null || $cart === null) {
            $result = new QuickOrderResult();
            $result->fatalError = Craft::t('forklift', 'No cart is available.');

            return $result;
        }

        $result = $this->resolve($rows, $companyId);
        $result->dryRun = false;

        foreach ($result->getValidRows() as $row) {
            try {
                $lineItem = $commerce->getLineItems()->create($cart, [
                    'purchasableId' => $row->purchasableId,
                    'qty' => $row->qty,
                    'note' => (string)$row->note,
                ]);

                $cart->addLineItem($lineItem);
                $result->added++;
            } catch (\Throwable $e) {
                $row->error = Craft::t('forklift', 'Could not be added: {reason}', ['reason' => $e->getMessage()]);
                $result->added = max(0, $result->added);
            }
        }

        if ($result->added > 0 && !Craft::$app->getElements()->saveElement($cart, false)) {
            $result->fatalError = Craft::t('forklift', 'The cart could not be saved.');
            $result->added = 0;
        }

        return $result;
    }

    /**
     * Parse a CSV of SKUs and quantities.
     *
     * Deliberately forgiving about the *shape* and strict about the *size*. A header row is
     * detected and skipped, columns may be `sku,qty` or `qty,sku` — a spreadsheet exported by
     * somebody's ERP will be one or the other and neither is wrong — and a third column becomes
     * the line note. What it will not do is accept an unbounded file: `csvMaxRows` exists because
     * a mis-selected export can be a million rows, and the failure mode should be a message
     * rather than an exhausted memory limit.
     *
     * @return array<int, array{sku: string, qty: int, note: string|null}>
     */
    public function parseCsv(string $contents, ?string &$error = null): array
    {
        $error = null;
        $max = Plugin::getInstance()->getSettings()->csvMaxRows;

        // Strip a UTF-8 byte-order mark. Excel writes one, and it turns the first header cell into
        // something that matches nothing, which sends the whole file down the "no header" path.
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        $handle = fopen('php://memory', 'r+');

        if ($handle === false) {
            $error = Craft::t('forklift', 'The file could not be read.');

            return [];
        }

        fwrite($handle, $contents);
        rewind($handle);

        $rows = [];
        $lineNumber = 0;
        $skuFirst = true;
        $headerChecked = false;

        while (($cells = fgetcsv($handle, 4096)) !== false) {
            $lineNumber++;

            if ($cells === [null] || $cells === false) {
                continue;
            }

            $cells = array_map(static fn($cell) => trim((string)$cell), $cells);

            if (implode('', $cells) === '') {
                continue;
            }

            if (!$headerChecked) {
                $headerChecked = true;
                $first = strtolower($cells[0] ?? '');
                $second = strtolower($cells[1] ?? '');

                if (in_array($first, ['sku', 'code', 'part', 'part number', 'item'], true)) {
                    continue;
                }

                if (in_array($first, ['qty', 'quantity', 'amount'], true)) {
                    $skuFirst = false;
                    continue;
                }

                // No header. If the first cell is numeric and the second is not, the file is
                // quantity-first — a guess, but a well-founded one, and the preview shows the
                // buyer what it decided before anything is added.
                if (is_numeric($first) && $second !== '' && !is_numeric($second)) {
                    $skuFirst = false;
                }
            }

            if (count($rows) >= $max) {
                $error = Craft::t('forklift', 'The file has more than {max} rows. Split it and upload again.', ['max' => $max]);
                break;
            }

            $sku = $skuFirst ? ($cells[0] ?? '') : ($cells[1] ?? '');
            $qty = $skuFirst ? ($cells[1] ?? '1') : ($cells[0] ?? '1');

            $rows[] = [
                'sku' => $sku,
                'qty' => (int)round((float)str_replace([',', ' '], '', $qty)),
                'note' => ($cells[2] ?? '') !== '' ? $cells[2] : null,
            ];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Upload a CSV. Pro only — the typed pad is in every edition.
     *
     * @return QuickOrderResult A preview when `$dryRun`, an outcome otherwise.
     */
    public function fromCsv(string $contents, bool $dryRun = true, ?Order $cart = null, ?int $companyId = null): QuickOrderResult
    {
        if (!Edition::allowsCsvUpload(Plugin::getInstance()->isPro())) {
            $result = new QuickOrderResult(['dryRun' => $dryRun]);
            $result->fatalError = Craft::t('forklift', 'CSV upload is not available on this edition.');

            return $result;
        }

        $rows = $this->parseCsv($contents, $error);

        $result = $dryRun
            ? $this->resolve($rows, $companyId)
            : $this->add($rows, $cart, $companyId);

        if ($error !== null) {
            $result->fatalError = $error;
        }

        return $result;
    }

    /**
     * Rebuild a previous order as a pad, **at today's prices**.
     *
     * The prices are re-resolved rather than copied, which is the only honest thing to do: a
     * contract that has been renegotiated, a quantity break that has moved, or a list price rise
     * would otherwise be quietly ignored and the buyer would meet the real number at checkout.
     * Rows whose price has changed come back with a warning saying so.
     */
    public function fromOrder(Order $order, ?int $companyId = null, bool $dryRun = true, ?Order $cart = null): QuickOrderResult
    {
        $rows = [];

        foreach ($order->getLineItems() as $item) {
            if (!$item->purchasableId) {
                continue;
            }

            $rows[] = [
                'sku' => $item->getSku(),
                'qty' => $item->qty,
                'note' => null,
                'previousPrice' => (float)$item->getSalePrice(),
            ];
        }

        $result = $dryRun
            ? $this->resolve($rows, $companyId)
            : $this->add($rows, $cart, $companyId);

        // Flag the lines whose price has moved since. Matched by index because `resolve()` keeps
        // the input order and skips only wholly blank rows, of which there are none here.
        foreach ($result->rows as $i => $row) {
            $previous = $rows[$i]['previousPrice'] ?? null;

            if ($previous === null || $row->price === null || !$row->getIsValid()) {
                continue;
            }

            if (abs($previous - $row->price->price) > 0.0049) {
                $row->warning = $row->price->price > $previous
                    ? Craft::t('forklift', 'The price has risen since your last order.')
                    : Craft::t('forklift', 'The price has fallen since your last order.');
            }
        }

        return $result;
    }

    // Lookup
    // -------------------------------------------------------------------------

    /**
     * Find a purchasable by SKU.
     *
     * Exact match first, then case-insensitive, and nothing else. Deliberately **not** a fuzzy or
     * prefix search: a buyer typing a part number wants that part, and quietly supplying the
     * nearest thing to it is how the wrong item ends up on a pallet. The autocomplete endpoint is
     * where prefix matching belongs, because there a human picks from the results.
     */
    public function findBySku(string $sku): ?PurchasableInterface
    {
        $sku = trim($sku);

        if ($sku === '') {
            return null;
        }

        $id = (new Query())
            ->select(['id'])
            ->from(['{{%commerce_purchasables}}'])
            ->where(['sku' => $sku])
            ->scalar();

        if (!$id) {
            $id = (new Query())
                ->select(['id'])
                ->from(['{{%commerce_purchasables}}'])
                ->where(['like', 'sku', $sku, false])
                ->andWhere(['=', 'LOWER([[sku]])', mb_strtolower($sku)])
                ->scalar();
        }

        if (!$id) {
            return null;
        }

        return Commerce::getInstance()?->getPurchasables()->getPurchasableById((int)$id);
    }

    /**
     * SKUs starting with this text, for an autocomplete.
     *
     * @return array<int, array{sku: string, description: string, purchasableId: int, price: float}>
     */
    public function suggest(string $term, ?int $companyId = null, int $limit = 10): array
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return [];
        }

        $rows = (new Query())
            ->select(['id', 'sku', 'description'])
            ->from(['{{%commerce_purchasables}}'])
            ->where(['like', 'sku', $term . '%', false])
            ->orderBy(['sku' => SORT_ASC])
            ->limit($limit)
            ->all();

        $out = [];

        foreach ($rows as $row) {
            $price = Plugin::getInstance()->pricing->resolve((int)$row['id'], 1, $companyId);

            $out[] = [
                'purchasableId' => (int)$row['id'],
                'sku' => (string)$row['sku'],
                'description' => (string)$row['description'],
                'price' => $price->price,
            ];
        }

        return $out;
    }

    // Internals
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $raw */
    private function _row(array $raw, int $lineNumber): ?QuickOrderRow
    {
        $sku = trim((string)($raw['sku'] ?? ''));
        $qty = (int)($raw['qty'] ?? 0);

        if ($sku === '' && $qty <= 0) {
            return null;
        }

        return new QuickOrderRow([
            'sku' => $sku,
            'qty' => $qty,
            'note' => isset($raw['note']) && $raw['note'] !== '' ? (string)$raw['note'] : null,
            'lineNumber' => $lineNumber,
        ]);
    }

    private function _resolveRow(QuickOrderRow $row, ?int $companyId): QuickOrderRow
    {
        if ($row->sku === null || $row->sku === '') {
            $row->error = Craft::t('forklift', 'No SKU given.');

            return $row;
        }

        if ($row->qty < 1) {
            $row->error = Craft::t('forklift', 'Quantity must be at least 1.');

            return $row;
        }

        $purchasable = $this->findBySku($row->sku);

        if ($purchasable === null) {
            $row->error = Craft::t('forklift', '“{sku}” was not found.', ['sku' => $row->sku]);

            return $row;
        }

        if (method_exists($purchasable, 'getIsAvailable') && !$purchasable->getIsAvailable()) {
            $row->error = Craft::t('forklift', '“{sku}” is not available to order.', ['sku' => $row->sku]);

            return $row;
        }

        $row->purchasableId = $purchasable->getId();
        $row->description = $purchasable->getDescription();
        $row->price = Plugin::getInstance()->pricing->resolve($purchasable, $row->qty, $companyId);

        // Stock is a warning rather than a refusal: Commerce decides what it will sell, and its
        // back-order settings are the store's to make. Telling the buyer early is still worth
        // doing — it is the difference between a phone call now and one after delivery.
        $stock = $this->_availableStock($purchasable);

        if ($stock !== null && $stock < $row->qty) {
            $row->warning = Craft::t('forklift', 'Only {stock} in stock.', ['stock' => $stock]);
        }

        return $row;
    }

    /** Available stock, or null when the purchasable is not tracked or cannot say. */
    private function _availableStock(PurchasableInterface $purchasable): ?int
    {
        try {
            // `inventoryTracked` is a public property on Commerce's base purchasable rather than
            // a getter, and a plugin's own purchasable type need not have it at all.
            if (property_exists($purchasable, 'inventoryTracked') && !$purchasable->inventoryTracked) {
                return null;
            }

            if (method_exists($purchasable, 'getStock')) {
                return (int)$purchasable->getStock();
            }
        } catch (\Throwable) {
            // A purchasable type with no inventory concept at all. Not an error.
        }

        return null;
    }
}
