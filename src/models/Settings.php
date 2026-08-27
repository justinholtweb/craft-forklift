<?php

declare(strict_types=1);

namespace justinholtweb\forklift\models;

use Craft;
use craft\base\Model;
use justinholtweb\forklift\Plugin;

/**
 * Forklift's settings.
 *
 * Nothing here is `required`. A settings model with a required attribute cannot save *any* of its
 * settings until that one is filled in, which makes a fresh install feel broken; correctness is
 * checked when a value is present instead.
 *
 * The `getEffective*()` methods are the downgrade gates. Every consumer asks those rather than
 * reading the raw property, so a Pro configuration left behind by a lapsed licence is ignored
 * rather than obeyed. `getHasSuppressedProSettings()` is what the control panel warns about.
 */
class Settings extends Model
{
    // Companies
    // -------------------------------------------------------------------------

    /** Whether a signed-in buyer's company is applied to their cart automatically. */
    public bool $autoAssignCompany = true;

    /**
     * Whether a buyer who belongs to more than one company may switch between them.
     *
     * Off by default: most people belong to one. When it is on, the choice lives in the session
     * and is written onto the order, so a switch mid-cart cannot leave the order attributed to the
     * company whose prices it was built with.
     */
    public bool $allowCompanySwitching = true;

    /** Whether a company's own administrators may add and remove buyers from the front end. */
    public bool $allowSelfServiceMembers = true;

    // Purchase orders
    // -------------------------------------------------------------------------

    /** Show a PO number field at checkout for company orders. */
    public bool $capturePoNumber = true;

    /** Refuse to complete a company order with no PO number, when the company demands one. */
    public bool $enforcePoNumber = true;

    // Pricing
    // -------------------------------------------------------------------------

    /**
     * Whether a contract price may ever be *higher* than the list price.
     *
     * Off by default, and the default matters: a price list written in a hurry, or imported with a
     * column out of order, otherwise charges a trade customer more than the public pays. When it
     * is off the resolver keeps the lower of the two and records that it did.
     */
    public bool $allowContractPriceAboveList = false;

    /**
     * Whether a promotional (sale) price beats a contract price when it is lower.
     *
     * On by default. A customer on a negotiated price who is shown a public sale at a better
     * number and then charged their contract rate has, correctly, been overcharged in their eyes.
     */
    public bool $promotionsBeatContractPrices = true;

    // Approvals
    // -------------------------------------------------------------------------

    /** Email the company's approvers when an order needs a decision. */
    public bool $notifyApprovers = true;

    /** Email the buyer when their order is approved or declined. */
    public bool $notifyRequester = true;

    /**
     * Days an approval request stays open before it expires. Zero means never.
     *
     * An expired request is *not* an approval. It releases the cart back to the buyer with the
     * reason attached.
     */
    public int $approvalExpiryDays = 14;

    // Quotes
    // -------------------------------------------------------------------------

    /** Days a sent quote stays valid, when the merchant does not set a date of their own. */
    public int $quoteValidityDays = 30;

    /** Empty the buyer's cart once their quote request has been recorded. */
    public bool $clearCartOnQuoteRequest = true;

    /** Email the store when a quote is requested. */
    public bool $notifyOnQuoteRequest = true;

    /** Where a quote's pay-by-link sends the buyer once the cart has loaded. */
    public ?string $quoteRedirectUrl = null;

    // Credit
    // -------------------------------------------------------------------------

    /**
     * Whether an order on terms counts against the company's credit limit the moment it is placed.
     *
     * On. The alternative — counting only invoices that have been raised — lets a buyer place
     * six orders in an afternoon that each pass the check on their own.
     */
    public bool $reserveCreditOnOrder = true;

    /** Aging buckets, in days, for the statement. The last bucket is open-ended. */
    public array $agingBuckets = [30, 60, 90];

    // Quick order
    // -------------------------------------------------------------------------

    /** Rows the quick-order pad renders, and the most a CSV may add in one go. */
    public int $quickOrderRows = 10;

    /** The most rows a single CSV upload may contain. */
    public int $csvMaxRows = 500;

    // Tax
    // -------------------------------------------------------------------------

    /** Honour exemption certificates at checkout. Off makes Forklift a record-keeper only. */
    public bool $applyTaxExemptions = true;

    /** Warn in the CP this many days before a certificate expires. Zero disables the warning. */
    public int $certificateExpiryWarningDays = 30;

    // Effective values — the downgrade gates
    // -------------------------------------------------------------------------

    private function isPro(): bool
    {
        return Plugin::getInstance()?->isPro() ?? false;
    }

    public function getEffectivePriceListsEnabled(): bool
    {
        return Edition::allowsPriceLists($this->isPro());
    }

    public function getEffectiveApprovalsEnabled(): bool
    {
        return Edition::allowsApprovals($this->isPro());
    }

    public function getEffectiveCreditEnabled(): bool
    {
        return Edition::allowsCredit($this->isPro());
    }

    public function getEffectiveQuotesEnabled(): bool
    {
        return Edition::allowsQuotes($this->isPro());
    }

    /**
     * Whether the store has Pro configuration that this edition is ignoring.
     *
     * Asked by the CP so the warning names the thing that is being ignored rather than saying
     * "some settings are unavailable". Cheap counts only — this runs on control-panel requests.
     */
    public function getHasSuppressedProSettings(): array
    {
        if ($this->isPro()) {
            return [];
        }

        $plugin = Plugin::getInstance();
        $suppressed = [];

        try {
            if ($plugin->priceLists->getTotalPriceLists() > 0) {
                $suppressed[] = Craft::t('forklift', 'Price lists exist but are not being applied — buyers are paying list price.');
            }

            if ($plugin->companies->getTotalCompaniesRequiringApproval() > 0) {
                $suppressed[] = Craft::t('forklift', 'Companies are configured to require spend approval, but orders are completing without it.');
            }

            if ($plugin->credit->getTotalCompaniesWithCreditLimits() > 0) {
                $suppressed[] = Craft::t('forklift', 'Credit limits are set but not enforced, and the purchase-order gateway is unavailable.');
            }

            if ($plugin->quotes->getTotalOpenQuotes() > 0) {
                $suppressed[] = Craft::t('forklift', 'Open quotes cannot be priced or sent.');
            }
        } catch (\Throwable) {
            // Asked during a part-applied migration. No warning is better than a broken CP.
            return [];
        }

        return $suppressed;
    }

    protected function defineRules(): array
    {
        return [
            [['approvalExpiryDays', 'quoteValidityDays', 'quickOrderRows', 'csvMaxRows', 'certificateExpiryWarningDays'], 'integer', 'min' => 0],
            [['quickOrderRows'], 'integer', 'min' => 1, 'max' => 200],
            [['csvMaxRows'], 'integer', 'min' => 1, 'max' => 10000],
            [['agingBuckets'], 'validateAgingBuckets'],
            [
                [
                    'autoAssignCompany', 'allowCompanySwitching', 'allowSelfServiceMembers',
                    'capturePoNumber', 'enforcePoNumber', 'allowContractPriceAboveList',
                    'promotionsBeatContractPrices', 'notifyApprovers', 'notifyRequester',
                    'clearCartOnQuoteRequest', 'notifyOnQuoteRequest', 'reserveCreditOnOrder',
                    'applyTaxExemptions', 'quoteRedirectUrl',
                ],
                'safe',
            ],
        ];
    }

    /**
     * Aging buckets have to be ascending positive integers, because the statement walks them in
     * order and an out-of-order bucket silently swallows the one before it.
     */
    public function validateAgingBuckets(string $attribute): void
    {
        $buckets = array_values(array_filter(array_map('intval', (array)$this->$attribute)));

        if ($buckets === []) {
            $this->addError($attribute, Craft::t('forklift', 'At least one aging bucket is needed.'));
            return;
        }

        $sorted = $buckets;
        sort($sorted);

        if ($sorted !== $buckets || count(array_unique($buckets)) !== count($buckets)) {
            $this->addError($attribute, Craft::t('forklift', 'Aging buckets must be in ascending order, with no repeats.'));
        }

        $this->$attribute = $buckets;
    }
}
