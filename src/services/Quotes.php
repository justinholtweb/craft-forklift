<?php

declare(strict_types=1);

namespace justinholtweb\forklift\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use DateTime;
use justinholtweb\forklift\db\Table;
use justinholtweb\forklift\elements\Quote;
use justinholtweb\forklift\models\QuoteLine;
use justinholtweb\forklift\Plugin;
use yii\base\Component;

/**
 * Quotes: request, price, send, accept.
 *
 * The part worth reading carefully is {@see materialise()}, which is how Forklift closes
 * `craftcms/commerce#1156` without patching Commerce.
 *
 * ## How pay-by-link works
 *
 * When a merchant sends a quote, Forklift builds a real, incomplete Commerce **cart** from the
 * quote's lines and gives the buyer a tokenised link to it. From that click onwards it is an
 * entirely ordinary Commerce checkout — every gateway the store has, every shipping method, the
 * store's own templates — because it *is* an ordinary cart.
 *
 * The link is Commerce's own `commerce/cart/load-cart` action with a signed token, which loads
 * the cart into the visitor's session. That matters for three reasons: the token expires, it is
 * verified against the cart number so a guessed one is refused, and it is a mechanism Commerce
 * maintains rather than one Forklift invented for handling other people's baskets.
 *
 * The quoted prices survive because `Pricing::resolve()` treats a quote pin as its highest
 * priority source, and the cart carries `quoteId` on its Forklift row. Commerce recalculates that
 * cart constantly — on every save, every address change, every shipping selection — and every one
 * of those recalculations re-derives the quoted numbers rather than reverting to list.
 *
 * ## Ordering, and why it is not the obvious one
 *
 * The cart is saved **empty first**, its Forklift row written with `quoteId`, and only then are
 * the line items added. Adding the lines first would populate them before anything knew the cart
 * came from a quote, so the pin would not be found and the first save would write list prices.
 */
class Quotes extends Component
{
    /** @var array<int, QuoteLine[]> */
    private array $_lines = [];

    // Reading
    // -------------------------------------------------------------------------

    public function getQuoteById(int $id): ?Quote
    {
        /** @var Quote|null */
        return Quote::find()->id($id)->status(null)->one();
    }

    public function getQuoteByNumber(string $number): ?Quote
    {
        /** @var Quote|null */
        return Quote::find()->number($number)->status(null)->one();
    }

    /** @return QuoteLine[] */
    public function getLinesByQuoteId(int $quoteId): array
    {
        if (!isset($this->_lines[$quoteId])) {
            $rows = (new Query())
                ->select([
                    'id', 'quoteId', 'purchasableId', 'sku', 'description', 'qty', 'listPrice',
                    'price', 'note', 'sortOrder', 'dateCreated', 'dateUpdated', 'uid',
                ])
                ->from([Table::QUOTELINES])
                ->where(['quoteId' => $quoteId])
                ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
                ->all();

            $this->_lines[$quoteId] = array_map($this->_toLine(...), $rows);
        }

        return $this->_lines[$quoteId];
    }

    public function getTotalOpenQuotes(): int
    {
        return (int)(new Query())
            ->from([Table::QUOTES])
            ->where(['status' => [Quote::STATUS_REQUESTED, Quote::STATUS_PRICING, Quote::STATUS_SENT]])
            ->count();
    }

    /** Quotes still waiting for somebody to price them — the control-panel badge. */
    public function getUnpricedCount(): int
    {
        return (int)(new Query())
            ->from([Table::QUOTES])
            ->where(['status' => [Quote::STATUS_REQUESTED, Quote::STATUS_PRICING]])
            ->count();
    }

    /**
     * A quote number.
     *
     * Sequential rather than random, because a customer reads it out over the telephone. The
     * suffix comes from the highest number already issued, so a restored database or an imported
     * history does not start again at one and collide.
     */
    public function generateNumber(): string
    {
        $prefix = 'Q-';

        $last = (new Query())
            ->select(['number'])
            ->from([Table::QUOTES])
            ->where(['like', 'number', $prefix . '%', false])
            ->orderBy(['id' => SORT_DESC])
            ->limit(1)
            ->scalar();

        $next = 1000;

        if ($last && preg_match('/(\d+)$/', (string)$last, $matches)) {
            $next = ((int)$matches[1]) + 1;
        }

        // Guard against a collision from a concurrent request rather than assuming the max is
        // still the max by the time this inserts.
        while ($this->getQuoteByNumber($prefix . $next) !== null) {
            $next++;
        }

        return $prefix . $next;
    }

    // Writing
    // -------------------------------------------------------------------------

    public function saveQuote(Quote $quote, bool $runValidation = true): bool
    {
        $saved = Craft::$app->getElements()->saveElement($quote, $runValidation);

        if ($saved) {
            unset($this->_lines[(int)$quote->id]);
        }

        return $saved;
    }

    /**
     * Replace a quote's lines.
     *
     * A replace rather than a merge, for the same reason a price-list import is a replace: the
     * form is the truth, and a line the merchant deleted should not survive because nothing
     * mentioned it.
     *
     * @param QuoteLine[] $lines
     */
    public function saveLines(int $quoteId, array $lines): void
    {
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(DateTimeHelper::currentUTCDateTime());
        $transaction = $db->beginTransaction();

        try {
            $db->createCommand()->delete(Table::QUOTELINES, ['quoteId' => $quoteId])->execute();

            $rows = [];
            $sortOrder = 0;

            foreach ($lines as $line) {
                $rows[] = [
                    $quoteId,
                    $line->purchasableId,
                    $line->sku,
                    $line->description,
                    max(1, $line->qty),
                    $line->listPrice,
                    $line->price,
                    $line->note,
                    $line->sortOrder ?? ++$sortOrder,
                    $now,
                    $now,
                    StringHelper::UUID(),
                ];
            }

            if ($rows !== []) {
                $db->createCommand()->batchInsert(
                    Table::QUOTELINES,
                    ['quoteId', 'purchasableId', 'sku', 'description', 'qty', 'listPrice', 'price', 'note', 'sortOrder', 'dateCreated', 'dateUpdated', 'uid'],
                    $rows,
                )->execute();
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        unset($this->_lines[$quoteId]);
    }

    // The lifecycle
    // -------------------------------------------------------------------------

    /**
     * Turn a cart into a request for a price.
     *
     * The lines are a snapshot of what the buyer had in front of them, priced as they were priced
     * — including any contract price they already get — so the merchant can see what they would
     * have paid and quote against it rather than against the public price.
     */
    public function requestFromCart(Order $cart, array $attributes = []): ?Quote
    {
        $plugin = Plugin::getInstance();
        $user = Craft::$app->getUser()->getIdentity();
        $company = $plugin->companies->getCurrentCompany();

        $quote = new Quote(array_merge([
            'companyId' => $company?->id,
            'requesterId' => $user?->id,
            'email' => $cart->getEmail() ?: $user?->email,
            'quoteStatus' => Quote::STATUS_REQUESTED,
            'currency' => $cart->currency,
            'storeId' => $cart->storeId ?? null,
        ], $attributes));

        $lines = [];
        $sortOrder = 0;

        foreach ($cart->getLineItems() as $item) {
            // `LineItem::getPrice()` is not the list price here — Forklift has already replaced it
            // with the buyer's contract price, which is the whole point. The list price has to
            // come from the resolver, or every quote would record the discount as if it were the
            // public price and the saving would read as zero.
            $listPrice = $item->purchasableId
                ? $plugin->pricing->listPriceFor((int)$item->purchasableId)
                : (float)$item->getPrice();

            $lines[] = new QuoteLine([
                'purchasableId' => $item->purchasableId,
                'sku' => $item->getSku(),
                'description' => $item->getDescription(),
                'qty' => $item->qty,
                'listPrice' => $listPrice,
                'price' => (float)$item->getSalePrice(),
                'note' => $item->note ?: null,
                'sortOrder' => ++$sortOrder,
            ]);
        }

        $quote->setLines($lines);

        if (!$this->saveQuote($quote)) {
            return null;
        }

        if ($plugin->getSettings()->notifyOnQuoteRequest) {
            $plugin->notifications->quoteRequested($quote);
        }

        return $quote;
    }

    /**
     * Build the cart a sent quote's link loads.
     *
     * Rebuilt from scratch each time the quote is sent, and the previous cart is discarded — a
     * quote that is re-priced and re-sent must not leave an older cart at an older price
     * reachable by an older email.
     */
    public function materialise(Quote $quote): ?Order
    {
        $commerce = Commerce::getInstance();

        if ($commerce === null) {
            return null;
        }

        $this->_discardCart($quote);

        $cart = new Order();
        $cart->number = $commerce->getCarts()->generateCartNumber();
        $cart->origin = Order::ORIGIN_CP;

        if ($quote->storeId) {
            $cart->storeId = $quote->storeId;
        }

        $requester = $quote->getRequester();

        if ($requester instanceof User) {
            $cart->setCustomer($requester);
        }

        if ($quote->getContactEmail()) {
            $cart->setEmail($quote->getContactEmail());
        }

        // Saved empty first so the Forklift row — and with it the quote pin — exists before any
        // line item is populated. Populating first would write list prices and the first
        // recalculation would keep them.
        if (!Craft::$app->getElements()->saveElement($cart, false)) {
            return null;
        }

        $plugin = Plugin::getInstance();

        $plugin->orders->setValuesForOrder((int)$cart->id, [
            'quoteId' => $quote->id,
            'companyId' => $quote->companyId,
            'termsId' => $quote->getCompany()?->termsId,
        ]);

        // The pin cache was populated before the row existed, so it has to be dropped or the
        // first line item is priced from a lookup that answered "no quote".
        $plugin->pricing->clearCaches();

        foreach ($quote->getLines() as $line) {
            if (!$line->purchasableId) {
                // A free-text line — a delivery surcharge, a bespoke item — has no purchasable to
                // add. Folded into the quote's shipping figure instead of being dropped silently.
                continue;
            }

            try {
                $lineItem = $commerce->getLineItems()->create($cart, [
                    'purchasableId' => $line->purchasableId,
                    'qty' => $line->qty,
                    'note' => (string)$line->note,
                ]);

                $cart->addLineItem($lineItem);
            } catch (\Throwable $e) {
                Craft::warning(
                    "Quote {$quote->number}: could not add purchasable {$line->purchasableId} — " . $e->getMessage(),
                    Plugin::LOG_CATEGORY,
                );
            }
        }

        if (!Craft::$app->getElements()->saveElement($cart, false)) {
            return null;
        }

        $quote->cartNumber = $cart->number;

        return $cart;
    }

    /**
     * Price it, materialise it, and mark it sent.
     *
     * Returns false without changing anything if the cart could not be built, because a quote
     * marked "sent" whose link goes nowhere is worse than one still sitting in the queue.
     */
    public function send(Quote $quote, ?DateTime $expiryDate = null, bool $notify = true): bool
    {
        if (!Plugin::getInstance()->getSettings()->getEffectiveQuotesEnabled()) {
            return false;
        }

        $cart = $this->materialise($quote);

        if ($cart === null) {
            return false;
        }

        $settings = Plugin::getInstance()->getSettings();

        $quote->quoteStatus = Quote::STATUS_SENT;
        $quote->sentDate = DateTimeHelper::currentUTCDateTime();
        $quote->expiryDate = $expiryDate
            ?? $quote->expiryDate
            ?? DateTimeHelper::currentUTCDateTime()->modify('+' . max(1, $settings->quoteValidityDays) . ' days');

        if (!$this->saveQuote($quote, false)) {
            return false;
        }

        if ($notify) {
            Plugin::getInstance()->notifications->quoteSent($quote);
        }

        return true;
    }

    /**
     * The link that lets the buyer pay.
     *
     * Commerce's own signed load-cart URL, so the token expires and is checked against the cart
     * number. Null when there is nothing to pay for yet.
     */
    public function getPaymentUrl(Quote $quote): ?string
    {
        $cart = $quote->getCart();

        if ($cart === null) {
            return null;
        }

        $commerce = Commerce::getInstance();

        if ($commerce === null) {
            return null;
        }

        $url = $commerce->getCarts()->getLoadCartUrl($cart);
        $redirect = Plugin::getInstance()->getSettings()->quoteRedirectUrl;

        if ($redirect) {
            $url = UrlHelper::urlWithParams($url, ['redirect' => $redirect]);
        }

        return $url;
    }

    /**
     * Record that the quote became an order.
     *
     * Called from order completion rather than from the click, because a buyer who follows the
     * link and then abandons the cart has not accepted anything.
     */
    public function markAccepted(Quote $quote, Order $order): bool
    {
        $quote->quoteStatus = Quote::STATUS_ACCEPTED;
        $quote->orderId = (int)$order->id;
        $quote->respondedDate = DateTimeHelper::currentUTCDateTime();

        return $this->saveQuote($quote, false);
    }

    public function markDeclined(Quote $quote, ?string $note = null): bool
    {
        $quote->quoteStatus = Quote::STATUS_DECLINED;
        $quote->respondedDate = DateTimeHelper::currentUTCDateTime();

        if ($note) {
            $quote->internalNote = trim(($quote->internalNote ? $quote->internalNote . "\n\n" : '') . $note);
        }

        $this->_discardCart($quote);

        return $this->saveQuote($quote, false);
    }

    /**
     * The quote a cart came from, if any.
     *
     * Used at order completion to close the loop, and by the price resolver's pin lookup.
     */
    public function getQuoteForOrder(Order $order): ?Quote
    {
        if (!$order->id) {
            return null;
        }

        $quoteId = Plugin::getInstance()->orders->getQuoteIdForOrder((int)$order->id);

        if ($quoteId !== null) {
            return $this->getQuoteById($quoteId);
        }

        // A cart that was never saved with a Forklift row — a quote sent, then the store's own
        // code copying the cart — can still be matched by number.
        /** @var Quote|null */
        return Quote::find()->cartNumber($order->number)->status(null)->one();
    }

    /**
     * Expire sent quotes whose date has gone by, and clear their carts.
     *
     * The status is *also* derived at read time by {@see Quote::getEffectiveStatus()}, so a site
     * with a broken cron still refuses an expired quote. This exists to tidy the stored status
     * and to release the carts, which would otherwise sit in the database forever holding stock
     * reservations open on stores that use them.
     *
     * @return int How many expired.
     */
    public function expireStale(): int
    {
        $now = Db::prepareDateForDb(DateTimeHelper::currentUTCDateTime());

        $ids = (new Query())
            ->select(['id'])
            ->from([Table::QUOTES])
            ->where(['status' => Quote::STATUS_SENT])
            ->andWhere(['not', ['expiryDate' => null]])
            ->andWhere(['<', 'expiryDate', $now])
            ->column();

        $expired = 0;

        foreach ($ids as $id) {
            $quote = $this->getQuoteById((int)$id);

            if ($quote === null) {
                continue;
            }

            $this->_discardCart($quote);
            $quote->quoteStatus = Quote::STATUS_EXPIRED;

            if ($this->saveQuote($quote, false)) {
                $expired++;
            }
        }

        return $expired;
    }

    // Internals
    // -------------------------------------------------------------------------

    /**
     * Delete the cart behind a quote, if it is still an unfinished cart.
     *
     * Guarded on `isCompleted` — once the buyer has paid, that order is the record of a sale and
     * deleting it because a quote expired would be catastrophic.
     */
    private function _discardCart(Quote $quote): void
    {
        if (!$quote->cartNumber) {
            return;
        }

        $cart = Order::find()->number($quote->cartNumber)->isCompleted(false)->one();

        if ($cart !== null) {
            Craft::$app->getElements()->deleteElement($cart, true);
        }

        $quote->cartNumber = null;
    }

    private function _toLine(array $row): QuoteLine
    {
        $row['id'] = (int)$row['id'];
        $row['quoteId'] = (int)$row['quoteId'];
        $row['purchasableId'] = $row['purchasableId'] !== null ? (int)$row['purchasableId'] : null;
        $row['qty'] = (int)$row['qty'];
        $row['listPrice'] = $row['listPrice'] !== null ? (float)$row['listPrice'] : null;
        $row['price'] = (float)$row['price'];
        $row['sortOrder'] = $row['sortOrder'] !== null ? (int)$row['sortOrder'] : null;
        $row['dateCreated'] = DateTimeHelper::toDateTime($row['dateCreated']) ?: null;
        $row['dateUpdated'] = DateTimeHelper::toDateTime($row['dateUpdated']) ?: null;

        return new QuoteLine($row);
    }

    public function clearCaches(): void
    {
        $this->_lines = [];
    }
}
