<?php

declare(strict_types=1);

namespace justinholtweb\forklift\adjusters;

use craft\commerce\adjusters\Tax as CommerceTax;
use craft\commerce\elements\Order;
use craft\commerce\models\TaxRate;
use Illuminate\Support\Collection;
use justinholtweb\forklift\models\Certificate;
use justinholtweb\forklift\models\ExemptTaxRate;
use justinholtweb\forklift\Plugin;

/**
 * Commerce's tax adjuster, with exemption certificates honoured.
 *
 * A subclass and not a replacement. Every line of tax arithmetic — inclusive and exclusive rates,
 * order-level and line-level taxables, the interaction with discounts, the rounding — stays
 * Commerce's, and a fix there reaches exempt orders the same day it reaches everybody else's.
 *
 * The whole intervention is {@see getTaxRates()}, which is `protected` in Commerce and exists for
 * precisely this. When the order carries a usable certificate:
 *
 * - rates that *add* tax are dropped, so none is added;
 * - rates whose tax is *included in the price* are converted to {@see ExemptTaxRate}, which
 *   Commerce then removes through its own out-of-zone branch.
 *
 * When it does not, this class is Commerce's adjuster and nothing else.
 */
class ExemptTax extends CommerceTax
{
    private ?Certificate $_exemption = null;

    /**
     * Resolve the exemption *before* handing over.
     *
     * The parent sets its own private order and address and then asks for rates, so the answer
     * has to be in hand before `parent::adjust()` is called — there is no way to reach the order
     * from inside `getTaxRates()`.
     */
    public function adjust(Order $order): array
    {
        try {
            $this->_exemption = Plugin::getInstance()->certificates->exemptionFor($order);
        } catch (\Throwable $e) {
            // A checkout that cannot compute tax is a checkout that cannot happen. Failing back
            // to ordinary taxed behaviour is the safe direction: the merchant collects tax they
            // may have to refund, rather than not collecting tax they are liable for.
            $this->_exemption = null;

            \Craft::error(
                'Forklift could not resolve a tax exemption, so tax was charged as normal: ' . $e->getMessage(),
                Plugin::LOG_CATEGORY,
            );
        }

        return parent::adjust($order);
    }

    /** The certificate that exempted the last order adjusted, if any. */
    public function getExemption(): ?Certificate
    {
        return $this->_exemption;
    }

    protected function getTaxRates(?int $storeId = null): Collection
    {
        $rates = parent::getTaxRates($storeId);

        if ($this->_exemption === null) {
            return $rates;
        }

        return $rates
            ->filter(static fn(TaxRate $rate) => $rate->include)
            ->map(static fn(TaxRate $rate) => ExemptTaxRate::from($rate))
            ->values();
    }
}
