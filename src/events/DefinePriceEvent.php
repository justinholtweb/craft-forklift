<?php

declare(strict_types=1);

namespace justinholtweb\forklift\events;

use craft\commerce\base\PurchasableInterface;
use craft\commerce\elements\Order;
use justinholtweb\forklift\models\PriceResult;
use yii\base\Event;

/**
 * The price Forklift resolved for a purchasable, quantity and company, before anything uses it.
 *
 * Change `$result` — its `price`, `source` and the rest — to change what the buyer pays
 * everywhere Forklift prices: the cart, the quick-order pad, quotes and the lookup endpoint.
 */
class DefinePriceEvent extends Event
{
    public ?PurchasableInterface $purchasable = null;

    public int $qty = 1;

    public ?int $companyId = null;

    public ?Order $order = null;

    public PriceResult $result;
}
