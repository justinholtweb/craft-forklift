<?php

declare(strict_types=1);

namespace justinholtweb\forklift\services;

use Craft;
use craft\commerce\base\PurchasableInterface;
use craft\commerce\elements\Order;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use justinholtweb\forklift\models\PriceListEntry;
use justinholtweb\forklift\models\PriceResult;
use justinholtweb\forklift\Plugin;
use yii\base\Component;

/**
 * **Invariant 1: this is the only place a B2B price is computed.**
 *
 * The cart, the catalog templates, the quick-order pad, the CSV upload, the reorder screen, the
 * quote builder and the control panel's price-list preview all call {@see resolve()} and read the
 * {@see PriceResult} it returns. A price a buyer is shown therefore cannot disagree with the price
 * they are charged, because there is no second implementation to disagree with.
 *
 * ## Resolution order
 *
 * 1. **A quote pin.** The order was materialised from a quote and the quote named a price for
 *    this line. Nothing outranks a number the merchant sent the customer in writing.
 * 2. **A contract price list.** See below.
 * 3. **Commerce's own price**, including its catalog pricing rules and promotions.
 *
 * ## Which entry wins
 *
 * Two questions, answered in this order, and the order is the design:
 *
 * - **Which list?** The highest `priority` that has *any* entry matching this purchasable; ties
 *   break on id, so the answer is stable rather than "whichever the database returned first".
 *   The list is the contract, and priority is the merchant's explicit statement of which contract
 *   governs. A customer-specific list at priority 10 therefore beats a trade-wide list at 0 — but
 *   only for the lines it actually mentions. Everything else falls through to the trade list,
 *   which is exactly how "everyone gets 15% off, Acme gets a sheet of negotiated lines" is meant
 *   to behave.
 * - **Which entry within it?** Most specific target first (this variant, then this product, then
 *   this product type, then everything), and within that, the highest quantity break at or below
 *   the quantity being priced.
 *
 * ## Two safety rails, both settings
 *
 * `allowContractPriceAboveList` is **off** by default. A price list imported with two columns
 * swapped otherwise charges a trade customer more than a member of the public, silently, on every
 * line. When it is off the resolver keeps the lower number and records why in
 * `suppressedReason` so the control panel can say so.
 *
 * `promotionsBeatContractPrices` is **on** by default. A customer on a negotiated rate who is
 * shown a public sale at a better number and then charged their contract rate has, as far as they
 * are concerned, been overcharged.
 *
 * ## Cost
 *
 * `resolve()` is called once per line item per cart recalculation, and Commerce recalculates
 * constantly. So the entries for a company are read **once per request** and matched in PHP:
 * `_entriesFor()` memoizes on the company, and the query it runs is filtered in SQL to the four
 * target shapes that could possibly match rather than reading a ten-thousand-row list into memory.
 */
class Pricing extends Component
{
    /** @var array<string, PriceListEntry[]> Company + purchasable => matching entries. */
    private array $_entryCache = [];

    /** @var array<int, int[]> Company => active price list ids, highest priority first. */
    private array $_listCache = [];

    /** @var array<int, array<int, float>> Quote id => purchasable id => pinned price. */
    private array $_quotePins = [];

    /**
     * What this company pays for this purchasable at this quantity.
     *
     * `$order` is optional and only used to find a quote pin — the price of a thing does not
     * otherwise depend on what else is in the basket, and taking an order here would invite
     * callers to think it does.
     */
    public function resolve(
        PurchasableInterface|int $purchasable,
        int $qty = 1,
        ?int $companyId = null,
        ?Order $order = null,
    ): PriceResult {
        $purchasable = $this->_purchasable($purchasable);

        $result = new PriceResult([
            'qty' => max(1, $qty),
        ]);

        if ($purchasable === null) {
            return $result;
        }

        $listPrice = (float)($purchasable->getPrice() ?? 0);
        $promotionalPrice = $purchasable->getPromotionalPrice();

        $result->purchasableId = $purchasable->getId();
        $result->sku = $purchasable->getSku();
        $result->listPrice = $listPrice;
        $result->promotionalPrice = $promotionalPrice !== null ? (float)$promotionalPrice : null;
        $result->price = $result->promotionalPrice ?? $listPrice;
        $result->source = $result->promotionalPrice !== null ? PriceResult::SOURCE_PROMOTION : PriceResult::SOURCE_LIST;

        // 1. A quote pin outranks everything.
        $pinned = $this->_quotePrice($order, (int)$purchasable->getId());

        if ($pinned !== null) {
            $result->price = $pinned['price'];
            $result->source = PriceResult::SOURCE_QUOTE;
            $result->quoteId = $pinned['quoteId'];

            return $result;
        }

        // 2. Contract price lists — Pro only. On a lapsed licence this is skipped entirely, so
        //    buyers pay list price rather than a half-applied contract.
        if (!Plugin::getInstance()->getSettings()->getEffectivePriceListsEnabled() || $companyId === null) {
            return $result;
        }

        $entry = $this->_bestEntry($purchasable, $companyId, $result->qty, $nextBreak);

        if ($entry === null) {
            return $result;
        }

        $contractPrice = $entry->apply($listPrice);
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->allowContractPriceAboveList && $contractPrice > $listPrice) {
            $result->suppressedReason = Craft::t('forklift', 'The contract price ({contract}) is higher than the list price, so the list price was used.', [
                'contract' => number_format($contractPrice, 2),
            ]);

            return $result;
        }

        if ($settings->promotionsBeatContractPrices
            && $result->promotionalPrice !== null
            && $result->promotionalPrice < $contractPrice
        ) {
            $result->suppressedReason = Craft::t('forklift', 'A promotion beat the contract price of {contract}.', [
                'contract' => number_format($contractPrice, 2),
            ]);

            return $result;
        }

        $result->price = $contractPrice;
        $result->source = $entry->minQty > 1 ? PriceResult::SOURCE_QUANTITY_BREAK : PriceResult::SOURCE_PRICE_LIST;
        $result->priceListId = $entry->priceListId;
        $result->priceListName = $entry->priceListName;
        $result->entryId = $entry->id;
        $result->breakQty = $entry->minQty;

        if ($nextBreak !== null) {
            $result->nextBreak = [
                'qty' => $nextBreak->minQty,
                'price' => $nextBreak->apply($listPrice),
            ];
        }

        return $result;
    }

    /**
     * Price several purchasables for one company.
     *
     * @param array<int, int> $quantities Purchasable id => quantity.
     * @return array<int, PriceResult> Keyed by purchasable id.
     */
    public function resolveMany(array $quantities, ?int $companyId = null, ?Order $order = null): array
    {
        $out = [];

        foreach ($quantities as $purchasableId => $qty) {
            $out[(int)$purchasableId] = $this->resolve((int)$purchasableId, (int)$qty, $companyId, $order);
        }

        return $out;
    }

    /**
     * The whole quantity-break ladder for one purchasable, cheapest quantity first.
     *
     * What a wholesale product page prints as a table. Built from the same entries the resolver
     * uses, so the table cannot advertise a break the cart will not honour.
     *
     * @return PriceResult[]
     */
    public function breaksFor(PurchasableInterface|int $purchasable, ?int $companyId = null): array
    {
        $purchasable = $this->_purchasable($purchasable);

        if ($purchasable === null || $companyId === null) {
            return [];
        }

        if (!Plugin::getInstance()->getSettings()->getEffectivePriceListsEnabled()) {
            return [];
        }

        $entries = $this->_entriesFor($purchasable, $companyId);
        $quantities = [1];

        foreach ($entries as $entry) {
            $quantities[] = $entry->minQty;
        }

        $quantities = array_values(array_unique($quantities));
        sort($quantities);

        $results = [];

        foreach ($quantities as $qty) {
            $result = $this->resolve($purchasable, $qty, $companyId);

            // Only rungs that actually change the price. A ladder that repeats the same number
            // four times is noise on a product page.
            $previous = end($results);

            if ($previous === false || abs($previous->price - $result->price) > 0.0001) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /** Commerce's price with no B2B pricing at all — what the public pays. */
    public function listPriceFor(PurchasableInterface|int $purchasable): float
    {
        return (float)($this->_purchasable($purchasable)?->getPrice() ?? 0);
    }

    /** Forget everything memoized. Called when a price list is saved, and between test cases. */
    public function clearCaches(): void
    {
        $this->_entryCache = [];
        $this->_listCache = [];
        $this->_quotePins = [];
    }

    // Internals
    // -------------------------------------------------------------------------

    /**
     * The winning entry, and the next break above it.
     *
     * @param PriceListEntry|null $nextBreak Set to the cheapest larger break, or null.
     */
    private function _bestEntry(
        PurchasableInterface $purchasable,
        int $companyId,
        int $qty,
        ?PriceListEntry &$nextBreak = null,
    ): ?PriceListEntry {
        $nextBreak = null;
        $entries = $this->_entriesFor($purchasable, $companyId);

        if ($entries === []) {
            return null;
        }

        $listOrder = $this->_listCache[$companyId] ?? [];

        // Which list governs: the highest-priority one with anything to say about this
        // purchasable. Not the highest-priority one overall — a customer contract that does not
        // mention a product should not stop the trade-wide list pricing it.
        $winningListId = null;

        foreach ($listOrder as $listId) {
            foreach ($entries as $entry) {
                if ($entry->priceListId === $listId) {
                    $winningListId = $listId;
                    break 2;
                }
            }
        }

        if ($winningListId === null) {
            return null;
        }

        $candidates = array_values(array_filter(
            $entries,
            static fn(PriceListEntry $entry) => $entry->priceListId === $winningListId,
        ));

        $best = null;

        foreach ($candidates as $entry) {
            if ($entry->minQty > $qty) {
                // A break the buyer has not reached. The cheapest such one is worth telling them
                // about; anything above that is noise.
                if ($nextBreak === null || $entry->minQty < $nextBreak->minQty) {
                    $nextBreak = $entry;
                }

                continue;
            }

            if ($best === null) {
                $best = $entry;
                continue;
            }

            // More specific target wins; then the higher quantity break.
            if ($entry->getSpecificity() > $best->getSpecificity()) {
                $best = $entry;
            } elseif ($entry->getSpecificity() === $best->getSpecificity() && $entry->minQty > $best->minQty) {
                $best = $entry;
            }
        }

        // The next break only makes sense if it is on the same target as the one that won —
        // otherwise "buy 12 more for a better price" could be advertising a product-wide rung
        // that a more specific line will override anyway.
        if ($best !== null && $nextBreak !== null && $nextBreak->getSpecificity() !== $best->getSpecificity()) {
            $nextBreak = null;
        }

        return $best;
    }

    /**
     * Every entry that could price this purchasable for this company.
     *
     * Memoized per request on company + purchasable, because a cart recalculation asks about the
     * same handful of purchasables several times over.
     *
     * @return PriceListEntry[]
     */
    private function _entriesFor(PurchasableInterface $purchasable, int $companyId): array
    {
        $key = $companyId . ':' . $purchasable->getId();

        if (isset($this->_entryCache[$key])) {
            return $this->_entryCache[$key];
        }

        $priceLists = Plugin::getInstance()->priceLists;

        if (!isset($this->_listCache[$companyId])) {
            $lists = $priceLists->getActivePriceListsForCompany($companyId);
            $this->_listCache[$companyId] = array_map(static fn($list) => (int)$list->id, $lists);
        }

        $listIds = $this->_listCache[$companyId];

        if ($listIds === []) {
            return $this->_entryCache[$key] = [];
        }

        [$productId, $purchasableTypeId] = $this->_targets($purchasable);

        $entries = $priceLists->getMatchingEntries(
            $listIds,
            (int)$purchasable->getId(),
            $purchasable->getSku(),
            $productId,
            $purchasableTypeId,
        );

        // Carry the list's name onto each entry so a PriceResult can explain itself without the
        // template loading a list per line.
        foreach ($entries as $entry) {
            $entry->priceListName = $priceLists->getPriceListById((int)$entry->priceListId)?->name;
        }

        return $this->_entryCache[$key] = $entries;
    }

    /**
     * The product and product-type ids behind a purchasable, when it has them.
     *
     * Commerce lets a plugin register purchasables of its own that belong to no product at all,
     * so both are nullable and the caller must cope. A subscription plan or a donation is a
     * perfectly ordinary thing to have in a B2B catalogue.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function _targets(PurchasableInterface $purchasable): array
    {
        if (!$purchasable instanceof Variant) {
            return [null, null];
        }

        $productId = $purchasable->getProductId();
        $typeId = null;

        try {
            $typeId = $purchasable->getProduct()?->typeId;
        } catch (\Throwable) {
            // A variant whose product has been deleted out from under it. No type, not a crash.
        }

        return [$productId, $typeId !== null ? (int)$typeId : null];
    }

    /**
     * The price a quote pinned for this purchasable, if this order came from one.
     *
     * @return array{price: float, quoteId: int}|null
     */
    private function _quotePrice(?Order $order, int $purchasableId): ?array
    {
        if ($order === null || !$order->id) {
            return null;
        }

        $quoteId = Plugin::getInstance()->orders->getQuoteIdForOrder((int)$order->id);

        if ($quoteId === null) {
            return null;
        }

        if (!isset($this->_quotePins[$quoteId])) {
            $pins = [];

            foreach (Plugin::getInstance()->quotes->getLinesByQuoteId($quoteId) as $line) {
                if ($line->purchasableId) {
                    $pins[(int)$line->purchasableId] = (float)$line->price;
                }
            }

            $this->_quotePins[$quoteId] = $pins;
        }

        $price = $this->_quotePins[$quoteId][$purchasableId] ?? null;

        return $price === null ? null : ['price' => $price, 'quoteId' => $quoteId];
    }

    private function _purchasable(PurchasableInterface|int $purchasable): ?PurchasableInterface
    {
        if ($purchasable instanceof PurchasableInterface) {
            return $purchasable;
        }

        /** @var PurchasableInterface|null */
        return Commerce::getInstance()?->getPurchasables()->getPurchasableById($purchasable);
    }
}
