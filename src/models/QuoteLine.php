<?php

declare(strict_types=1);

namespace justinholtweb\forklift\models;

use Craft;
use craft\base\Model;
use craft\commerce\base\PurchasableInterface;
use craft\commerce\Plugin as Commerce;
use DateTime;

/**
 * One line of a quote.
 *
 * A quote line keeps its own `sku` and `description` alongside `purchasableId`, and the reason is
 * that a quote is a *document sent to a customer*. It has to still read correctly when the variant
 * behind it has been renamed, retired or deleted — a purchase order arriving against a two-month-old
 * quotation is normal in wholesale, and "Purchasable #4021, deleted" is not something anybody can
 * reconcile against their paperwork.
 *
 * `listPrice` is kept next to `price` so the saving is visible on the quote and so a merchant
 * reviewing an old quotation can tell a negotiated discount from a mistyped number.
 */
class QuoteLine extends Model
{
    public ?int $id = null;
    public ?int $quoteId = null;
    public ?int $purchasableId = null;
    public ?string $sku = null;
    public ?string $description = null;
    public int $qty = 1;
    public ?float $listPrice = null;
    public float $price = 0.0;
    public ?string $note = null;
    public ?int $sortOrder = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    private ?PurchasableInterface $_purchasable = null;
    private bool $_purchasableLoaded = false;

    public function getSubtotal(): float
    {
        return round($this->price * $this->qty, 2);
    }

    public function getListSubtotal(): float
    {
        return round(($this->listPrice ?? $this->price) * $this->qty, 2);
    }

    public function getSaving(): float
    {
        return max(0.0, round($this->getListSubtotal() - $this->getSubtotal(), 2));
    }

    /**
     * The purchasable, if it is still there.
     *
     * Memoized with a separate flag rather than by a null check, so a deleted purchasable is
     * looked up once rather than on every call from inside a template loop.
     */
    public function getPurchasable(): ?PurchasableInterface
    {
        if (!$this->_purchasableLoaded) {
            $this->_purchasableLoaded = true;

            if ($this->purchasableId) {
                $this->_purchasable = Commerce::getInstance()?->getPurchasables()->getPurchasableById($this->purchasableId);
            }
        }

        return $this->_purchasable;
    }

    public function setPurchasable(?PurchasableInterface $purchasable): void
    {
        $this->_purchasable = $purchasable;
        $this->_purchasableLoaded = true;
        $this->purchasableId = $purchasable?->getId();

        if ($purchasable) {
            $this->sku ??= $purchasable->getSku();
            $this->description ??= $purchasable->getDescription();
        }
    }

    /** Whether this line can still become a cart line item. */
    public function getIsOrderable(): bool
    {
        return $this->getPurchasable() !== null;
    }

    public function getLabel(): string
    {
        return $this->description ?: ($this->sku ?: Craft::t('forklift', 'Line'));
    }

    protected function defineRules(): array
    {
        return [
            [['qty', 'price'], 'required'],
            [['qty'], 'integer', 'min' => 1],
            [['price', 'listPrice'], 'number', 'min' => 0],
            [['purchasableId', 'sortOrder'], 'integer'],
            [['sku', 'description', 'note', 'quoteId'], 'safe'],
            [['description'], 'validateIdentity', 'skipOnEmpty' => false],
        ];
    }

    /**
     * A line has to be identifiable as *something*. A row with no purchasable, no SKU and no
     * description is a blank the merchant left behind, and sending it to a customer is worse than
     * refusing to save it.
     */
    public function validateIdentity(string $attribute): void
    {
        if (!$this->purchasableId && !$this->sku && !$this->description) {
            $this->addError($attribute, Craft::t('forklift', 'A quote line needs a product, a SKU or a description.'));
        }
    }
}
