<?php

declare(strict_types=1);

namespace justinholtweb\forklift\engines;

use craft\commerce\engines\Tax as CommerceTaxEngine;
use justinholtweb\forklift\adjusters\ExemptTax;

/**
 * Commerce's tax engine with Forklift's adjuster in place of the stock one.
 *
 * Everything else about the engine — whether the control panel shows tax categories, zones and
 * rates, and whether they can be edited — is inherited unchanged, because Forklift has no opinion
 * about any of it. It only wants a different adjuster.
 *
 * Registered from `Plugin::init()` and **only when the engine currently in place is Commerce's
 * own**. A store running Avalara or TaxJar has an engine that talks to a service which does its
 * own exemption handling, and quietly replacing it would be both wrong and very hard to diagnose.
 */
class TaxEngine extends CommerceTaxEngine
{
    public static function displayName(): string
    {
        return 'Forklift Tax Engine';
    }

    public function taxAdjusterClass(): string
    {
        return ExemptTax::class;
    }
}
