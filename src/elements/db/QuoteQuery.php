<?php

declare(strict_types=1);

namespace justinholtweb\forklift\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use justinholtweb\forklift\elements\Quote;

/**
 * Element query for quotes.
 *
 * Parameters are typed `mixed` for the same reason as {@see CompanyQuery} — Craft configures an
 * index source's criteria straight onto public properties, and a narrowly-typed one takes the
 * source down.
 *
 * @method Quote[] all($db = null)
 * @method Quote|null one($db = null)
 * @method Quote|null nth(int $n, ?\yii\db\Connection $db = null)
 */
class QuoteQuery extends ElementQuery
{
    public mixed $number = null;
    public mixed $quoteStatus = null;
    public mixed $companyId = null;
    public mixed $requesterId = null;
    public mixed $email = null;
    public mixed $reference = null;
    public mixed $orderId = null;
    public mixed $cartNumber = null;

    /** Quotes whose expiry has passed, whatever their stored status says. */
    public ?bool $expired = null;

    /** Quotes expiring within this many days — the "chase these" list. */
    public mixed $expiringWithinDays = null;

    protected array $defaultOrderBy = ['forklift_quotes.dateCreated' => SORT_DESC];

    public function number(mixed $value): static
    {
        $this->number = $value;

        return $this;
    }

    public function quoteStatus(mixed $value): static
    {
        $this->quoteStatus = $value;

        return $this;
    }

    public function companyId(mixed $value): static
    {
        $this->companyId = $value;

        return $this;
    }

    public function requesterId(mixed $value): static
    {
        $this->requesterId = $value;

        return $this;
    }

    public function email(mixed $value): static
    {
        $this->email = $value;

        return $this;
    }

    public function reference(mixed $value): static
    {
        $this->reference = $value;

        return $this;
    }

    public function orderId(mixed $value): static
    {
        $this->orderId = $value;

        return $this;
    }

    public function cartNumber(mixed $value): static
    {
        $this->cartNumber = $value;

        return $this;
    }

    public function expired(?bool $value = true): static
    {
        $this->expired = $value;

        return $this;
    }

    public function expiringWithinDays(mixed $value): static
    {
        $this->expiringWithinDays = $value;

        return $this;
    }

    protected function beforePrepare(): bool
    {
        if (!parent::beforePrepare()) {
            return false;
        }

        $this->joinElementTable('forklift_quotes');

        $this->query->addSelect([
            'forklift_quotes.number',
            'forklift_quotes.storeId',
            'forklift_quotes.companyId',
            'forklift_quotes.requesterId',
            'forklift_quotes.email',
            'forklift_quotes.status as quoteStatus',
            'forklift_quotes.currency',
            'forklift_quotes.reference',
            'forklift_quotes.message',
            'forklift_quotes.internalNote',
            'forklift_quotes.shippingCost',
            'forklift_quotes.discount',
            'forklift_quotes.expiryDate',
            'forklift_quotes.sentDate',
            'forklift_quotes.respondedDate',
            'forklift_quotes.orderId',
            'forklift_quotes.cartNumber',
        ]);

        if ($this->number !== null) {
            $this->subQuery->andWhere(Db::parseParam('forklift_quotes.number', $this->number));
        }

        if ($this->quoteStatus !== null) {
            $this->subQuery->andWhere(Db::parseParam('forklift_quotes.status', $this->quoteStatus));
        }

        if ($this->companyId !== null) {
            $this->subQuery->andWhere(Db::parseParam('forklift_quotes.companyId', $this->companyId));
        }

        if ($this->requesterId !== null) {
            $this->subQuery->andWhere(Db::parseParam('forklift_quotes.requesterId', $this->requesterId));
        }

        if ($this->email !== null) {
            $this->subQuery->andWhere(Db::parseParam('forklift_quotes.email', $this->email));
        }

        if ($this->reference !== null) {
            $this->subQuery->andWhere(Db::parseParam('forklift_quotes.reference', $this->reference));
        }

        if ($this->orderId !== null) {
            $this->subQuery->andWhere(Db::parseParam('forklift_quotes.orderId', $this->orderId));
        }

        if ($this->cartNumber !== null) {
            $this->subQuery->andWhere(Db::parseParam('forklift_quotes.cartNumber', $this->cartNumber));
        }

        if ($this->expired !== null) {
            $now = Db::prepareDateForDb(DateTimeHelper::currentUTCDateTime());

            // A quote with no expiry date never expires, so it belongs on the *not expired* side
            // of this test rather than being silently excluded from both.
            $this->subQuery->andWhere($this->expired
                ? ['<', 'forklift_quotes.expiryDate', $now]
                : ['or', ['forklift_quotes.expiryDate' => null], ['>=', 'forklift_quotes.expiryDate', $now]]);
        }

        if ($this->expiringWithinDays !== null) {
            $days = max(0, (int)$this->expiringWithinDays);
            $now = DateTimeHelper::currentUTCDateTime();
            $until = (clone $now)->modify("+{$days} days");

            $this->subQuery->andWhere([
                'and',
                ['>=', 'forklift_quotes.expiryDate', Db::prepareDateForDb($now)],
                ['<', 'forklift_quotes.expiryDate', Db::prepareDateForDb($until)],
            ]);
        }

        return true;
    }

    /**
     * A quote's status is its own column, not Craft's enabled flag.
     *
     * `expired` is not stored — it is a fact about the clock — so it is expressed here as "sent,
     * and the expiry has gone by", which is exactly what {@see Quote::getEffectiveStatus()} says.
     * The two have to agree or an index would list a quote as live that the element itself
     * considers dead.
     */
    protected function statusCondition(string $status): mixed
    {
        $now = Db::prepareDateForDb(DateTimeHelper::currentUTCDateTime());

        return match ($status) {
            Quote::STATUS_EXPIRED => [
                'and',
                ['forklift_quotes.status' => [Quote::STATUS_SENT, Quote::STATUS_EXPIRED]],
                ['not', ['forklift_quotes.expiryDate' => null]],
                ['<', 'forklift_quotes.expiryDate', $now],
            ],
            Quote::STATUS_SENT => [
                'and',
                ['forklift_quotes.status' => Quote::STATUS_SENT],
                ['or', ['forklift_quotes.expiryDate' => null], ['>=', 'forklift_quotes.expiryDate', $now]],
            ],
            Quote::STATUS_REQUESTED,
            Quote::STATUS_PRICING,
            Quote::STATUS_ACCEPTED,
            Quote::STATUS_DECLINED,
            Quote::STATUS_CANCELLED => ['forklift_quotes.status' => $status],
            default => parent::statusCondition($status),
        };
    }
}
