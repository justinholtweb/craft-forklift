<?php

declare(strict_types=1);

namespace justinholtweb\forklift\models;

use Craft;
use craft\base\Model;
use craft\commerce\base\PurchasableInterface;
use craft\commerce\Plugin as Commerce;
use DateTime;

/**
 * One line of a price list.
 *
 * Four targets, deliberately ordered from most to least specific, because that order *is* the
 * resolution rule:
 *
 * 1. `purchasable` — this exact variant. The negotiated line on the contract.
 * 2. `product` — every variant of this product.
 * 3. `purchasableType` — every purchasable of a product type. "All fixings at 22% off."
 * 4. `all` — the catch-all. "Everything, less 15%."
 *
 * And three ways to say a price, which are not interchangeable in practice. `fixed` is what a
 * negotiated contract looks like: a number, agreed, that does not move when the list price does.
 * `percentOff` and `amountOff` are what a *trade tier* looks like: they track the list price, so
 * a price rise reaches trade customers automatically instead of quietly eroding the margin on
 * eleven thousand rows nobody remembered to re-import.
 *
 * `minQty` turns any of them into a quantity break. Several entries with the same target and
 * different `minQty` values are a break table; the resolver takes the highest `minQty` at or below
 * the quantity being priced.
 */
class PriceListEntry extends Model
{
    public const TARGET_PURCHASABLE = 'purchasable';
    public const TARGET_PRODUCT = 'product';
    public const TARGET_PURCHASABLE_TYPE = 'purchasableType';
    public const TARGET_ALL = 'all';

    public const TARGET_TYPES = [
        self::TARGET_PURCHASABLE,
        self::TARGET_PRODUCT,
        self::TARGET_PURCHASABLE_TYPE,
        self::TARGET_ALL,
    ];

    /**
     * How specific each target is. Higher wins.
     *
     * Read by the resolver rather than re-derived from a `match` in three places.
     */
    public const TARGET_SPECIFICITY = [
        self::TARGET_PURCHASABLE => 40,
        self::TARGET_PRODUCT => 30,
        self::TARGET_PURCHASABLE_TYPE => 20,
        self::TARGET_ALL => 10,
    ];

    public const PRICE_FIXED = 'fixed';
    public const PRICE_PERCENT_OFF = 'percentOff';
    public const PRICE_AMOUNT_OFF = 'amountOff';

    public const PRICE_TYPES = [self::PRICE_FIXED, self::PRICE_PERCENT_OFF, self::PRICE_AMOUNT_OFF];

    public ?int $id = null;
    public ?int $priceListId = null;
    public string $targetType = self::TARGET_PURCHASABLE;
    public ?int $targetId = null;
    public ?string $sku = null;
    public int $minQty = 1;
    public string $priceType = self::PRICE_FIXED;
    public float $amount = 0.0;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /** Set by the resolver when it wants to say which list an entry came from. */
    public ?string $priceListName = null;

    /**
     * Apply this entry to a list price.
     *
     * Never returns a negative number: a 120%-off row, or a £30 discount on an £8 item, is a typo
     * and free is the least wrong reading of it. The resolver notices the clamp and can say so.
     */
    public function apply(float $listPrice): float
    {
        $price = match ($this->priceType) {
            self::PRICE_PERCENT_OFF => $listPrice * (1 - ($this->amount / 100)),
            self::PRICE_AMOUNT_OFF => $listPrice - $this->amount,
            default => $this->amount,
        };

        return max(0.0, round($price, 4));
    }

    public function getSpecificity(): int
    {
        return self::TARGET_SPECIFICITY[$this->targetType] ?? 0;
    }

    /** Whether this entry could price the given purchasable at all. */
    public function matches(PurchasableInterface $purchasable, ?int $productId, ?string $purchasableType): bool
    {
        return match ($this->targetType) {
            self::TARGET_PURCHASABLE => $this->targetId === $purchasable->getId()
                || ($this->targetId === null && $this->sku !== null && $this->sku === $purchasable->getSku()),
            self::TARGET_PRODUCT => $productId !== null && $this->targetId === $productId,
            self::TARGET_PURCHASABLE_TYPE => $purchasableType !== null && (string)$this->targetId === (string)$purchasableType,
            self::TARGET_ALL => true,
            default => false,
        };
    }

    public function getTargetLabel(): string
    {
        return match ($this->targetType) {
            self::TARGET_PURCHASABLE => $this->sku ?? Craft::t('forklift', 'Purchasable #{id}', ['id' => $this->targetId]),
            self::TARGET_PRODUCT => $this->_elementTitle() ?? Craft::t('forklift', 'Product #{id}', ['id' => $this->targetId]),
            self::TARGET_PURCHASABLE_TYPE => $this->_productTypeName() ?? Craft::t('forklift', 'Product type #{id}', ['id' => $this->targetId]),
            self::TARGET_ALL => Craft::t('forklift', 'Everything'),
            default => (string)$this->targetType,
        };
    }

    public function getPriceLabel(): string
    {
        return match ($this->priceType) {
            self::PRICE_PERCENT_OFF => Craft::t('forklift', '{amount}% off', ['amount' => rtrim(rtrim(number_format($this->amount, 2, '.', ''), '0'), '.')]),
            self::PRICE_AMOUNT_OFF => Craft::t('forklift', '{amount} off', ['amount' => number_format($this->amount, 2)]),
            default => number_format($this->amount, 2),
        };
    }

    private function _elementTitle(): ?string
    {
        if (!$this->targetId) {
            return null;
        }

        return Craft::$app->getElements()->getElementById($this->targetId)?->title;
    }

    private function _productTypeName(): ?string
    {
        if (!$this->targetId) {
            return null;
        }

        try {
            return Commerce::getInstance()?->getProductTypes()->getProductTypeById((int)$this->targetId)?->name;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function defineRules(): array
    {
        return [
            [['targetType', 'priceType', 'minQty'], 'required'],
            [['targetType'], 'in', 'range' => self::TARGET_TYPES],
            [['priceType'], 'in', 'range' => self::PRICE_TYPES],
            [['minQty'], 'integer', 'min' => 1],
            [['amount'], 'number', 'min' => 0],
            [['amount'], 'validateAmount', 'skipOnEmpty' => false],
            [['targetId'], 'validateTarget', 'skipOnEmpty' => false],
            [['sku', 'priceListId'], 'safe'],
        ];
    }

    public function validateAmount(string $attribute): void
    {
        if ($this->priceType === self::PRICE_PERCENT_OFF && $this->amount > 100) {
            $this->addError($attribute, Craft::t('forklift', 'A percentage discount cannot be more than 100%.'));
        }
    }

    public function validateTarget(string $attribute): void
    {
        if ($this->targetType === self::TARGET_ALL) {
            return;
        }

        // A purchasable row may name its target by SKU instead, which is what a spreadsheet
        // import has to hand before anything has been resolved.
        if ($this->targetType === self::TARGET_PURCHASABLE && $this->sku) {
            return;
        }

        if (!$this->targetId) {
            $this->addError($attribute, Craft::t('forklift', 'Choose what this price applies to.'));
        }
    }
}
