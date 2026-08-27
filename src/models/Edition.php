<?php

declare(strict_types=1);

namespace justinholtweb\forklift\models;

/**
 * What each edition allows.
 *
 * Pure and static, taking `$isPro` rather than reaching for the plugin, so the boundary can be
 * tested without an application and read in one place as the answer to "what exactly does Pro
 * buy".
 *
 * The rule the split follows: **Lite is a working company-accounts plugin, not a demo.** A
 * distributor with twelve trade customers and one price in their head should get real value for
 * nothing — company accounts, named buyers with roles, a purchase-order number on the order, a
 * quick-order pad, reordering, and tax exemption certificates. There is deliberately **no cap on
 * companies, buyers or orders**: charging for the thing that grows with a customer's success is
 * the wrong shape of pricing for an account tool.
 *
 * Pro is the *money* half — the parts that only start to matter once purchasing is somebody's
 * job: prices negotiated per customer, spend that somebody has to sign off, invoices on terms
 * against a credit limit, and quotes.
 *
 * ## Downgrades
 *
 * A lapsed licence never breaks a store. Pro configuration that survives a downgrade is
 * **ignored, not obeyed**, and the two asymmetries are deliberate:
 *
 * - Price lists stop applying, so buyers pay list price. Erring towards the merchant not
 *   under-charging.
 * - Approvals stop *blocking* checkout. A store that cannot check out at all is worse than one
 *   that lets an order through, so the order is placed and flagged `approvalBypassed`. This is
 *   the one place Forklift fails open, and it says so in the CP rather than hiding it.
 * - The purchase-order gateway becomes unavailable, which fails closed and is safe: no new credit
 *   can be opened and existing invoices are untouched.
 */
abstract class Edition
{
    /** Customer-specific price lists and contract pricing. */
    public static function allowsPriceLists(bool $isPro): bool
    {
        return $isPro;
    }

    /** Spend approval workflows — thresholds, per-buyer limits, the approval queue. */
    public static function allowsApprovals(bool $isPro): bool
    {
        return $isPro;
    }

    /** Net terms, credit limits, the ledger and statements. */
    public static function allowsCredit(bool $isPro): bool
    {
        return $isPro;
    }

    /** Request-a-quote, the CP pricing screen and pay-by-link. */
    public static function allowsQuotes(bool $isPro): bool
    {
        return $isPro;
    }

    /** Uploading a CSV of SKUs and quantities. The typed pad itself is in Lite. */
    public static function allowsCsvUpload(bool $isPro): bool
    {
        return $isPro;
    }

    /**
     * Everything in Lite, listed so the boundary reads in one direction as well as the other.
     *
     * @return string[]
     */
    public static function liteFeatures(): array
    {
        return [
            'Company accounts with a field layout of your own',
            'Buyers, roles and per-buyer spend limits',
            'Company order history and the buyer portal',
            'Purchase-order numbers captured at checkout',
            'Tax exemption certificates',
            'Quick-order pad and reorder, at list prices',
        ];
    }

    /** @return string[] */
    public static function proFeatures(): array
    {
        return [
            'Contract price lists with quantity breaks',
            'Spend approval workflows',
            'Net terms, credit limits and statements',
            'Request a quote, price it, send a pay-by-link',
            'CSV order upload',
        ];
    }
}
