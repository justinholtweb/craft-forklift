<?php

declare(strict_types=1);

namespace justinholtweb\forklift\models;

use craft\commerce\models\TaxAddressZone;
use craft\commerce\models\TaxRate;

/**
 * A tax rate, rewritten so that Commerce's own adjuster removes it.
 *
 * Commerce's `adjusters\Tax` computes `$zoneMatches` and, when a rate's zone does *not* match,
 * takes an existing branch that strips tax included in the price and posts an adjustment saying
 * it did. That branch is exactly what a tax exemption is, so Forklift expresses the exemption as
 * a rate transformation rather than as a special case inside a forked adjuster:
 *
 * - `getIsEverywhere()` returns false, and
 * - `getTaxZone()` returns null,
 *
 * which together make `$zoneMatches` false. With `removeIncluded` forced on, Commerce's own
 * arithmetic removes the included tax — the same arithmetic, to the penny, that it uses for an
 * out-of-zone customer.
 *
 * Rates that *add* tax are not transformed at all: they are simply filtered out before they reach
 * the adjuster, which is what "no tax is charged" means.
 *
 * Doing it this way means Forklift owns roughly twenty lines of tax code instead of a copy of
 * Commerce's four hundred, and a fix or a rounding change in Commerce reaches exempt orders on the
 * same day it reaches everybody else's.
 */
class ExemptTaxRate extends TaxRate
{
    /** Build an exempt clone of a rate. */
    public static function from(TaxRate $rate): self
    {
        $clone = new self($rate->toArray());

        $clone->id = $rate->id;
        $clone->name = $rate->name;
        $clone->rate = $rate->rate;
        $clone->include = $rate->include;
        $clone->taxable = $rate->taxable;
        $clone->taxCategoryId = $rate->taxCategoryId;
        $clone->enabled = true;

        // The two that do the work.
        $clone->removeIncluded = true;
        $clone->taxZoneId = null;

        // No tax-ID validators: a valid VAT number is a *different* route to zero rating with its
        // own adjustment name, and running both would remove the same tax twice.
        $clone->taxIdValidators = [];

        return $clone;
    }

    /**
     * Never "everywhere", whatever `taxZoneId` says.
     *
     * Commerce derives this from the zone, so returning false here — rather than pointing the
     * rate at some real zone that happens not to match — is what makes the behaviour independent
     * of the zones a particular store has configured.
     */
    public function getIsEverywhere(): bool
    {
        return false;
    }

    /** No zone, so nothing can match it. */
    public function getTaxZone(): ?TaxAddressZone
    {
        return null;
    }
}
