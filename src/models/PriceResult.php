<?php

declare(strict_types=1);

namespace justinholtweb\forklift\models;

use Craft;
use craft\base\Model;

/**
 * What `services\Pricing::resolve()` returns: one purchasable, one quantity, one answer, and the
 * reason for it.
 *
 * The reason is not decoration. A wholesale buyer looking at £8.40 wants to know whether that is
 * their contract rate, a quantity break they have just earned, the quote they agreed last week or
 * simply the public price — and a merchant looking at an unexpected number needs to know which of
 * four mechanisms produced it before they can fix anything. Returning a bare float means writing
 * that explanation a second time somewhere else, where it can be wrong.
 *
 * `price` is always the number to charge. `listPrice` is what the same purchasable would have
 * cost with no B2B pricing at all, so `getSaving()` is honest even when the source is `list`.
 */
class PriceResult extends Model
{
    /** No B2B pricing applied — this is Commerce's own price. */
    public const SOURCE_LIST = 'list';

    /** A contract price list entry matched. */
    public const SOURCE_PRICE_LIST = 'priceList';

    /** A quantity break inside a price list matched. */
    public const SOURCE_QUANTITY_BREAK = 'quantityBreak';

    /** The order was materialised from a quote, and the quote pinned this line. */
    public const SOURCE_QUOTE = 'quote';

    /** A catalog promotion beat the contract price and was allowed to. */
    public const SOURCE_PROMOTION = 'promotion';

    public ?int $purchasableId = null;
    public ?string $sku = null;
    public int $qty = 1;

    /** The price to charge, per unit. */
    public float $price = 0.0;

    /** What Commerce would have charged. Never null once the resolver has run. */
    public float $listPrice = 0.0;

    /** Commerce's own promotional price, if it has one. Kept so a template can strike it out. */
    public ?float $promotionalPrice = null;

    public string $source = self::SOURCE_LIST;

    public ?int $priceListId = null;
    public ?string $priceListName = null;
    public ?int $entryId = null;
    public ?int $quoteId = null;

    /** The quantity at which this entry started applying, when a break matched. */
    public ?int $breakQty = null;

    /**
     * The next quantity break above this one, if the buyer is close to earning it.
     *
     * This is the single most effective thing a wholesale front end can show — "12 more and
     * they're £7.10 each" — and it is computed here because the resolver has already read every
     * entry that could produce it.
     *
     * @var array{qty: int, price: float}|null
     */
    public ?array $nextBreak = null;

    /**
     * Why a contract price was *not* used, when one existed.
     *
     * Set when the price list wanted to charge more than list and `allowContractPriceAboveList`
     * is off, or when a promotion beat it. Otherwise null. Surfaced in the CP preview so a
     * merchant staring at a price list that "isn't working" is told why in one sentence.
     */
    public ?string $suppressedReason = null;

    public function getSaving(): float
    {
        return max(0.0, round($this->listPrice - $this->price, 4));
    }

    public function getSavingPercent(): float
    {
        if ($this->listPrice <= 0) {
            return 0.0;
        }

        return round(($this->getSaving() / $this->listPrice) * 100, 2);
    }

    public function getIsContractPrice(): bool
    {
        return in_array($this->source, [self::SOURCE_PRICE_LIST, self::SOURCE_QUANTITY_BREAK, self::SOURCE_QUOTE], true);
    }

    /** The whole line, for a pad or a CSV preview that shows a row total. */
    public function getSubtotal(): float
    {
        return round($this->price * $this->qty, 4);
    }

    public function getSourceLabel(): string
    {
        return match ($this->source) {
            self::SOURCE_PRICE_LIST => $this->priceListName
                ? Craft::t('forklift', 'Contract price ({list})', ['list' => $this->priceListName])
                : Craft::t('forklift', 'Contract price'),
            self::SOURCE_QUANTITY_BREAK => Craft::t('forklift', 'Quantity break at {qty}+', ['qty' => $this->breakQty]),
            self::SOURCE_QUOTE => Craft::t('forklift', 'Quoted price'),
            self::SOURCE_PROMOTION => Craft::t('forklift', 'Promotion'),
            default => Craft::t('forklift', 'List price'),
        };
    }
}
