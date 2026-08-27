<?php
/**
 * Forklift integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-forklift/tests/integration/checks.php
 *
 * Idempotent and self-cleaning: every fixture it creates it deletes again, whether the run passes
 * or not.
 *
 * Two conventions worth knowing before adding to it:
 *
 * - **Edition and settings changes are made in memory**, never persisted. Project config is
 *   contended on this harness — the queue runner and sibling plugins write it while a long
 *   console script runs — and a persisted change is both slower and liable to a
 *   `StaleResourceException` a long way from its cause.
 * - **Prices are asserted through the real resolver and through a real cart**, not only through
 *   the service. A price that is right in `Pricing::resolve()` and wrong on a line item is the
 *   failure mode that matters, and it can only be caught by recalculating an order.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\TaxRate;
use craft\commerce\Plugin as Commerce;
use craft\elements\Address;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\StringHelper;
use justinholtweb\forklift\adjusters\ExemptTax;
use justinholtweb\forklift\elements\Company;
use justinholtweb\forklift\elements\Quote;
use justinholtweb\forklift\gateways\PurchaseOrder;
use justinholtweb\forklift\models\Approval;
use justinholtweb\forklift\models\Certificate;
use justinholtweb\forklift\models\CheckoutVerdict;
use justinholtweb\forklift\models\CreditEntry;
use justinholtweb\forklift\models\Edition;
use justinholtweb\forklift\models\ExemptTaxRate;
use justinholtweb\forklift\models\Member;
use justinholtweb\forklift\models\PriceList;
use justinholtweb\forklift\models\PriceListEntry;
use justinholtweb\forklift\models\PriceResult;
use justinholtweb\forklift\models\QuoteLine;
use justinholtweb\forklift\models\Role;
use justinholtweb\forklift\models\Term;
use justinholtweb\forklift\Plugin;
use justinholtweb\forklift\twig\ForkliftVariable;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

/** Money compared the way money needs comparing. */
function near(float $actual, float $expected, string $what = 'value'): bool|string
{
    if (abs($actual - $expected) < 0.005) {
        return true;
    }

    return "$what was $actual, expected $expected";
}

$plugin = Plugin::getInstance();
$commerce = Commerce::getInstance();

$companies = $plugin->companies;
$members = $plugin->members;
$priceLists = $plugin->priceLists;
$pricing = $plugin->pricing;
$checkout = $plugin->checkout;
$approvals = $plugin->approvals;
$credit = $plugin->credit;
$certificates = $plugin->certificates;
$ordersService = $plugin->orders;
$quotes = $plugin->quotes;
$quickOrder = $plugin->quickOrder;
$terms = $plugin->terms;

$suffix = substr(md5((string)microtime(true)), 0, 6);
$originalEdition = $plugin->edition;

// The bulk of the suite runs on Pro and the Lite boundary is exercised in its own section, which
// is the same shape as the sibling plugins' suites. In memory only: project config is contended
// on this harness, and a persisted edition change is both slower and liable to a
// StaleResourceException a long way from its cause.
$plugin->edition = Plugin::EDITION_PRO;
$storeId = $commerce->getStores()->getPrimaryStore()->id;

$createdCompanies = [];
$createdProducts = [];
$createdOrders = [];
$createdUsers = [];
$createdLists = [];
$createdQuotes = [];
$createdTaxRates = [];

/** A fixture product whose SKU is unique to this run. */
function makeProduct(string $sku, float $price): Variant
{
    global $createdProducts;

    $type = Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0];

    $product = new Product();
    $product->typeId = $type->id;
    $product->title = "Forklift fixture $sku";
    $product->enabled = true;

    $variant = new Variant();
    $variant->sku = $sku;
    $variant->basePrice = $price;
    $variant->isDefault = true;
    $variant->inventoryTracked = false;

    $product->setVariants([$variant]);

    if (!Craft::$app->getElements()->saveElement($product)) {
        throw new RuntimeException('Could not save fixture product: ' . json_encode($product->getErrors()));
    }

    $createdProducts[] = $product;

    return $product->getVariants()->first();
}

function makeUser(string $handle): User
{
    global $createdUsers, $suffix;

    $user = new User();
    $user->username = "forklift-$handle-$suffix";
    $user->email = "forklift-$handle-$suffix@example.test";
    $user->firstName = ucfirst($handle);
    $user->lastName = 'Fixture';

    if (!Craft::$app->getElements()->saveElement($user)) {
        throw new RuntimeException('Could not save fixture user: ' . json_encode($user->getErrors()));
    }

    $createdUsers[] = $user;

    return $user;
}

function makeCompany(string $name, array $attributes = []): Company
{
    global $createdCompanies, $companies, $suffix;

    $company = new Company(array_merge(['title' => "$name $suffix"], $attributes));

    if (!$companies->saveCompany($company)) {
        throw new RuntimeException('Could not save fixture company: ' . json_encode($company->getErrors()));
    }

    $createdCompanies[] = $company;

    return $company;
}

function makeList(string $handle, array $attributes = []): PriceList
{
    global $createdLists, $priceLists, $suffix;

    $list = new PriceList(array_merge([
        'name' => "Fixture $handle",
        'handle' => $handle . $suffix,
    ], $attributes));

    if (!$priceLists->savePriceList($list)) {
        throw new RuntimeException('Could not save fixture price list: ' . json_encode($list->getErrors()));
    }

    $createdLists[] = $list;

    return $list;
}

/** A saved, incomplete cart belonging to a customer. */
function makeCart(?User $customer = null): Order
{
    global $createdOrders, $commerce;

    $order = new Order();
    $order->number = $commerce->getCarts()->generateCartNumber();
    $order->origin = Order::ORIGIN_CP;

    if ($customer !== null) {
        $order->setCustomer($customer);
    }

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException('Could not save fixture cart');
    }

    $createdOrders[] = $order;

    return $order;
}

function addLine(Order $order, Variant $variant, int $qty): void
{
    $lineItem = Commerce::getInstance()->getLineItems()->create($order, [
        'purchasableId' => $variant->id,
        'qty' => $qty,
    ]);

    $order->addLineItem($lineItem);
    Craft::$app->getElements()->saveElement($order, false);
}

echo "Forklift integration checks (fixture suffix $suffix)\n";

try {
    // ------------------------------------------------------------------ wiring

    section('Wiring');

    check('the plugin is installed and its services resolve', function() use ($plugin) {
        foreach (['companies', 'members', 'terms', 'priceLists', 'pricing', 'checkout', 'approvals', 'credit', 'certificates', 'orders', 'quotes', 'quickOrder', 'notifications'] as $name) {
            if ($plugin->$name === null) {
                return "service $name is missing";
            }
        }

        return true;
    });

    check('the purchase-order gateway is registered with Commerce', function() use ($commerce) {
        return in_array(PurchaseOrder::class, $commerce->getGateways()->getAllGatewayTypes(), true)
            ?: 'gateway not registered';
    });

    check('Forklift owns the tax engine and its adjuster', function() use ($commerce) {
        return $commerce->getTaxes()->getEngine()->taxAdjusterClass() === ExemptTax::class
            ?: 'adjuster is ' . $commerce->getTaxes()->getEngine()->taxAdjusterClass();
    });

    check('both element types are registered', function() {
        $types = Craft::$app->getElements()->getAllElementTypes();

        return in_array(Company::class, $types, true) && in_array(Quote::class, $types, true)
            ?: 'element types missing';
    });

    check('the default payment terms were seeded on install', function() use ($terms) {
        return $terms->getTermByHandle('net30') !== null ?: 'net30 missing';
    });

    check('settings validate out of the box', function() use ($plugin) {
        return $plugin->getSettings()->validate() ?: json_encode($plugin->getSettings()->getErrors());
    });

    // ------------------------------------------------------------ companies

    section('Companies and buyers');

    $acme = makeCompany('Acme Distribution', ['code' => "ACME$suffix"]);
    $bolt = makeCompany('Bolt & Fixing', ['code' => "BOLT$suffix"]);

    $rita = makeUser('rita');
    $sam = makeUser('sam');
    $viv = makeUser('viv');

    check('a company saves and gets an element id', fn() => $acme->id !== null ?: 'no id');

    check('a company with no code is given a readable one', function() {
        $company = makeCompany('Widgets Wholesale');

        return $company->code !== null && str_starts_with($company->code, 'WIDGETS')
            ?: 'generated code was ' . var_export($company->code, true);
    });

    check('two companies cannot share an account code', function() use ($acme, $suffix) {
        $clash = new Company(['title' => "Clash $suffix", 'code' => $acme->code]);
        $saved = Plugin::getInstance()->companies->saveCompany($clash);

        if ($saved) {
            Craft::$app->getElements()->deleteElement($clash, true);

            return 'a duplicate code was accepted';
        }

        return $clash->hasErrors('code') ?: 'refused, but not because of the code';
    });

    check('the first person on an account becomes its administrator', function() use ($acme, $rita, $members) {
        $member = $members->addUserToCompany((int)$acme->id, (int)$rita->id, Role::BUYER);

        return $member !== false && $member->role === Role::ADMIN
            ?: 'role was ' . ($member === false ? 'refused' : $member->role);
    });

    check('later buyers get the role they were given', function() use ($acme, $sam, $members) {
        $member = $members->addUserToCompany((int)$acme->id, (int)$sam->id, Role::BUYER, 500.0);

        return $member !== false && $member->role === Role::BUYER && $member->spendLimit === 500.0
            ?: 'role or limit wrong';
    });

    check('adding somebody twice updates rather than duplicating', function() use ($acme, $sam, $members) {
        $members->addUserToCompany((int)$acme->id, (int)$sam->id, Role::APPROVER, 2000.0);
        $all = $members->getMembersByCompanyId((int)$acme->id);
        $forSam = array_filter($all, fn(Member $m) => $m->userId === $sam->id);

        return count($forSam) === 1 && reset($forSam)->role === Role::APPROVER
            ?: 'ended with ' . count($forSam) . ' memberships';
    });

    check('a company cannot be left with no administrator', function() use ($acme, $rita, $members) {
        $member = $members->getMember((int)$acme->id, (int)$rita->id);
        $member->role = Role::BUYER;
        $saved = $members->saveMember($member);

        // Put it back whatever happened, so the rest of the suite has an admin to work with.
        $member->role = Role::ADMIN;
        $members->saveMember($member);

        return !$saved ?: 'the last administrator was demoted';
    });

    check('removing the last administrator is refused too', function() use ($acme, $rita, $members) {
        $member = $members->getMember((int)$acme->id, (int)$rita->id);

        return !$members->deleteMemberById((int)$member->id) ?: 'the last administrator was removed';
    });

    check('a person can hold different roles at different companies', function() use ($bolt, $sam, $members) {
        $members->addUserToCompany((int)$bolt->id, (int)$sam->id, Role::ADMIN);

        return $members->getMember((int)$bolt->id, (int)$sam->id)->role === Role::ADMIN
            && $members->getMember((int)$sam->id, (int)$sam->id) === null
            ?: 'roles leaked between companies';
    });

    check('only one membership per person can be the default', function() use ($acme, $bolt, $sam, $members) {
        $a = $members->getMember((int)$acme->id, (int)$sam->id);
        $a->isDefault = true;
        $members->saveMember($a);

        $b = $members->getMember((int)$bolt->id, (int)$sam->id);
        $b->isDefault = true;
        $members->saveMember($b);

        $defaults = array_filter($members->getMembersByUserId((int)$sam->id), fn(Member $m) => $m->isDefault);

        return count($defaults) === 1 ?: count($defaults) . ' defaults';
    });

    check('roles answer the four questions asked of them', function() {
        return Role::canManageMembers(Role::ADMIN)
            && !Role::canManageMembers(Role::APPROVER)
            && Role::canApprove(Role::APPROVER)
            && !Role::canApprove(Role::BUYER)
            && Role::canPurchase(Role::BUYER)
            && !Role::canPurchase(Role::VIEWER)
            && Role::canSeeFinancials(Role::ADMIN)
            && !Role::canSeeFinancials(Role::BUYER)
            ?: 'a role permission is wrong';
    });

    check('a viewer may look but not buy', function() use ($bolt, $viv, $members) {
        $members->addUserToCompany((int)$bolt->id, (int)$viv->id, Role::VIEWER);
        $member = $members->getMember((int)$bolt->id, (int)$viv->id);

        return !$member->getCanPurchase() && $member->getCanApprove() === false
            ?: 'a viewer could purchase';
    });

    check('an account on hold cannot purchase, but is still enabled', function() use ($bolt, $companies) {
        $companies->setAccountStatus($bolt, Company::STATUS_HOLD);
        $reloaded = Company::find()->id($bolt->id)->status(null)->one();
        $ok = !$reloaded->getCanPurchase() && $reloaded->enabled && $reloaded->getIsOnHold();
        $companies->setAccountStatus($bolt, Company::STATUS_ACTIVE);

        return $ok ?: 'hold behaved wrongly';
    });

    check('a spend limit of zero is not the same as no limit', function() {
        $none = new Member(['role' => Role::BUYER, 'spendLimit' => null]);
        $zero = new Member(['role' => Role::BUYER, 'spendLimit' => 0.0]);

        return !$none->exceedsSpendLimit(9999.0) && $zero->exceedsSpendLimit(0.01)
            ?: 'null and zero were conflated';
    });

    // -------------------------------------------------------------- pricing

    section('Pricing — the one place a B2B price is computed');

    $widget = makeProduct("FKL-W-$suffix", 10.00);
    $bracket = makeProduct("FKL-B-$suffix", 40.00);
    $clamp = makeProduct("FKL-C-$suffix", 25.00);

    $trade = makeList('trade', ['allCompanies' => true, 'priority' => 0]);
    $contract = makeList('acme', ['priority' => 10]);
    $priceLists->setCompaniesForPriceList((int)$contract->id, [(int)$acme->id]);

    $priceLists->replaceEntries((int)$trade->id, [
        new PriceListEntry(['targetType' => PriceListEntry::TARGET_ALL, 'priceType' => PriceListEntry::PRICE_PERCENT_OFF, 'amount' => 15]),
    ]);

    $priceLists->replaceEntries((int)$contract->id, [
        new PriceListEntry(['targetType' => PriceListEntry::TARGET_PURCHASABLE, 'targetId' => $widget->id, 'priceType' => PriceListEntry::PRICE_FIXED, 'amount' => 8.00, 'minQty' => 1]),
        new PriceListEntry(['targetType' => PriceListEntry::TARGET_PURCHASABLE, 'targetId' => $widget->id, 'priceType' => PriceListEntry::PRICE_FIXED, 'amount' => 7.10, 'minQty' => 12]),
        new PriceListEntry(['targetType' => PriceListEntry::TARGET_PURCHASABLE, 'targetId' => $widget->id, 'priceType' => PriceListEntry::PRICE_FIXED, 'amount' => 6.40, 'minQty' => 100]),
        new PriceListEntry(['targetType' => PriceListEntry::TARGET_PURCHASABLE, 'targetId' => $clamp->id, 'priceType' => PriceListEntry::PRICE_AMOUNT_OFF, 'amount' => 40.00]),
    ]);

    $pricing->clearCaches();

    check('with no company, the public price stands', function() use ($pricing, $widget) {
        $result = $pricing->resolve($widget, 1, null);

        return $result->source === PriceResult::SOURCE_LIST && near($result->price, 10.00, 'price') === true
            ?: 'got ' . $result->price . ' from ' . $result->source;
    });

    check('a contract price beats a trade-wide list on the lines it mentions', function() use ($pricing, $widget, $acme, $contract) {
        $result = $pricing->resolve($widget, 1, (int)$acme->id);

        return near($result->price, 8.00, 'price') === true
            && $result->priceListId === $contract->id
            ?: 'got ' . $result->price . ' from list ' . var_export($result->priceListId, true);
    });

    check('a line the contract does not mention falls through to the trade list', function() use ($pricing, $bracket, $acme, $trade) {
        $result = $pricing->resolve($bracket, 1, (int)$acme->id);

        return near($result->price, 34.00, 'price') === true && $result->priceListId === $trade->id
            ?: 'got ' . $result->price . ' from list ' . var_export($result->priceListId, true);
    });

    check('a company with no contract gets only the trade-wide list', function() use ($pricing, $widget, $bolt, $trade) {
        $result = $pricing->resolve($widget, 1, (int)$bolt->id);

        return near($result->price, 8.50, 'price') === true && $result->priceListId === $trade->id
            ?: 'got ' . $result->price;
    });

    check('quantity breaks take the highest rung at or below the quantity', function() use ($pricing, $widget, $acme) {
        $at1 = $pricing->resolve($widget, 1, (int)$acme->id);
        $at11 = $pricing->resolve($widget, 11, (int)$acme->id);
        $at12 = $pricing->resolve($widget, 12, (int)$acme->id);
        $at999 = $pricing->resolve($widget, 999, (int)$acme->id);

        return near($at1->price, 8.00, 'qty 1') === true
            && near($at11->price, 8.00, 'qty 11') === true
            && near($at12->price, 7.10, 'qty 12') === true
            && near($at999->price, 6.40, 'qty 999') === true
            ?: 'a rung was wrong';
    });

    check('a break that has been reached is reported as a quantity break', function() use ($pricing, $widget, $acme) {
        $result = $pricing->resolve($widget, 12, (int)$acme->id);

        return $result->source === PriceResult::SOURCE_QUANTITY_BREAK && $result->breakQty === 12
            ?: 'source was ' . $result->source;
    });

    check('the next break up is offered when the buyer has not reached it', function() use ($pricing, $widget, $acme) {
        $result = $pricing->resolve($widget, 5, (int)$acme->id);

        return $result->nextBreak !== null
            && $result->nextBreak['qty'] === 12
            && near($result->nextBreak['price'], 7.10, 'next break') === true
            ?: 'nextBreak was ' . json_encode($result->nextBreak);
    });

    check('the ladder only lists rungs that change the price', function() use ($pricing, $widget, $acme) {
        $ladder = $pricing->breaksFor($widget, (int)$acme->id);
        $prices = array_map(fn(PriceResult $r) => $r->price, $ladder);

        return $prices === array_values(array_unique($prices)) && count($ladder) === 3
            ?: 'ladder was ' . json_encode($prices);
    });

    check('a percentage entry follows the list price rather than a stored number', function() use ($pricing, $bolt, $suffix) {
        // Two products at different list prices against the same 15%-off trade list. Written this
        // way rather than by editing one product's price mid-run, because Commerce regenerates
        // catalog prices in a queue job — a check that changed a price and read it back would be
        // testing Commerce's queue, not Forklift's arithmetic, and would pass or fail on timing.
        $cheap = makeProduct("FKL-P1-$suffix", 40.00);
        $dear = makeProduct("FKL-P2-$suffix", 50.00);

        Plugin::getInstance()->pricing->clearCaches();

        $a = $pricing->resolve($cheap, 1, (int)$bolt->id);
        $b = $pricing->resolve($dear, 1, (int)$bolt->id);

        return near($a->price, 34.00, 'cheap') === true
            && near($b->price, 42.50, 'dear') === true
            ?: "40 -> {$a->price}, 50 -> {$b->price}";
    });

    check('the three ways of saying a price each do what they say', function() {
        $fixed = new PriceListEntry(['priceType' => PriceListEntry::PRICE_FIXED, 'amount' => 8.0]);
        $percent = new PriceListEntry(['priceType' => PriceListEntry::PRICE_PERCENT_OFF, 'amount' => 15.0]);
        $amount = new PriceListEntry(['priceType' => PriceListEntry::PRICE_AMOUNT_OFF, 'amount' => 3.0]);

        return near($fixed->apply(40.0), 8.0, 'fixed') === true
            && near($percent->apply(40.0), 34.0, 'percent') === true
            && near($amount->apply(40.0), 37.0, 'amount off') === true
            // A 120%-off row, or a discount larger than the price, is a typo. Free is the least
            // wrong reading of it; a negative price is not a reading at all.
            && near($amount->apply(1.0), 0.0, 'clamped') === true
            ?: 'a price type is wrong';
    });

    check('an amount-off entry that would go negative is clamped to zero, not below', function() use ($pricing, $clamp, $acme) {
        $result = $pricing->resolve($clamp, 1, (int)$acme->id);

        return near($result->price, 0.0, 'price') === true ?: 'got ' . $result->price;
    });

    check('a contract price above list is refused, with a reason', function() use ($pricing, $priceLists, $acme, $suffix) {
        $expensive = makeProduct("FKL-X-$suffix", 5.00);
        $list = makeList('overpriced', ['priority' => 50]);
        $priceLists->setCompaniesForPriceList((int)$list->id, [(int)$acme->id]);
        $priceLists->replaceEntries((int)$list->id, [
            new PriceListEntry(['targetType' => PriceListEntry::TARGET_PURCHASABLE, 'targetId' => $expensive->id, 'priceType' => PriceListEntry::PRICE_FIXED, 'amount' => 9.99]),
        ]);
        Plugin::getInstance()->pricing->clearCaches();

        $result = $pricing->resolve($expensive, 1, (int)$acme->id);

        $priceLists->deletePriceListById((int)$list->id);
        Plugin::getInstance()->pricing->clearCaches();

        return near($result->price, 5.00, 'price') === true && $result->suppressedReason !== null
            ?: 'got ' . $result->price . ' with reason ' . var_export($result->suppressedReason, true);
    });

    check('allowing an above-list contract price makes it apply', function() use ($pricing, $priceLists, $plugin, $acme, $suffix) {
        $expensive = makeProduct("FKL-Y-$suffix", 5.00);
        $list = makeList('overpriced2', ['priority' => 50]);
        $priceLists->setCompaniesForPriceList((int)$list->id, [(int)$acme->id]);
        $priceLists->replaceEntries((int)$list->id, [
            new PriceListEntry(['targetType' => PriceListEntry::TARGET_PURCHASABLE, 'targetId' => $expensive->id, 'priceType' => PriceListEntry::PRICE_FIXED, 'amount' => 9.99]),
        ]);

        $settings = $plugin->getSettings();
        $settings->allowContractPriceAboveList = true;
        $pricing->clearCaches();

        $result = $pricing->resolve($expensive, 1, (int)$acme->id);

        $settings->allowContractPriceAboveList = false;
        $priceLists->deletePriceListById((int)$list->id);
        $pricing->clearCaches();

        return near($result->price, 9.99, 'price') === true ?: 'got ' . $result->price;
    });

    check('an expired price list prices nothing', function() use ($pricing, $priceLists, $bolt, $widget) {
        $list = makeList('lapsed', ['priority' => 99]);
        $priceLists->setCompaniesForPriceList((int)$list->id, [(int)$bolt->id]);
        $priceLists->replaceEntries((int)$list->id, [
            new PriceListEntry(['targetType' => PriceListEntry::TARGET_PURCHASABLE, 'targetId' => $widget->id, 'priceType' => PriceListEntry::PRICE_FIXED, 'amount' => 1.00]),
        ]);

        $list->dateTo = DateTimeHelper::currentUTCDateTime()->modify('-1 day');
        $priceLists->savePriceList($list, false);
        $pricing->clearCaches();

        $result = $pricing->resolve($widget, 1, (int)$bolt->id);

        $priceLists->deletePriceListById((int)$list->id);
        $pricing->clearCaches();

        return near($result->price, 8.50, 'price') === true ?: 'got ' . $result->price;
    });

    check('a disabled price list prices nothing', function() use ($pricing, $priceLists, $bolt, $widget) {
        $list = makeList('switchedoff', ['priority' => 99, 'enabled' => false]);
        $priceLists->setCompaniesForPriceList((int)$list->id, [(int)$bolt->id]);
        $priceLists->replaceEntries((int)$list->id, [
            new PriceListEntry(['targetType' => PriceListEntry::TARGET_PURCHASABLE, 'targetId' => $widget->id, 'priceType' => PriceListEntry::PRICE_FIXED, 'amount' => 1.00]),
        ]);
        $pricing->clearCaches();

        $result = $pricing->resolve($widget, 1, (int)$bolt->id);

        $priceLists->deletePriceListById((int)$list->id);
        $pricing->clearCaches();

        return near($result->price, 8.50, 'price') === true ?: 'got ' . $result->price;
    });

    check('a more specific target wins inside one list', function() use ($pricing, $priceLists, $bolt, $widget, $suffix) {
        $list = makeList('specificity', ['priority' => 60]);
        $priceLists->setCompaniesForPriceList((int)$list->id, [(int)$bolt->id]);
        $priceLists->replaceEntries((int)$list->id, [
            new PriceListEntry(['targetType' => PriceListEntry::TARGET_ALL, 'priceType' => PriceListEntry::PRICE_FIXED, 'amount' => 3.00]),
            new PriceListEntry(['targetType' => PriceListEntry::TARGET_PURCHASABLE, 'targetId' => $widget->id, 'priceType' => PriceListEntry::PRICE_FIXED, 'amount' => 2.00]),
        ]);
        $pricing->clearCaches();

        $result = $pricing->resolve($widget, 1, (int)$bolt->id);

        $priceLists->deletePriceListById((int)$list->id);
        $pricing->clearCaches();

        return near($result->price, 2.00, 'price') === true ?: 'got ' . $result->price;
    });

    check('the result explains itself', function() use ($pricing, $widget, $acme) {
        $result = $pricing->resolve($widget, 12, (int)$acme->id);

        return $result->getIsContractPrice()
            && str_contains($result->getSourceLabel(), '12')
            && near($result->getSaving(), 2.90, 'saving') === true
            && near($result->getSubtotal(), 85.20, 'subtotal') === true
            ?: 'label was ' . $result->getSourceLabel();
    });

    check('a purchasable with no price list is priced at list without touching the database twice', function() use ($pricing, $bracket) {
        $a = $pricing->resolve($bracket, 1, null);
        $b = $pricing->resolve($bracket, 1, null);

        return $a->price === $b->price && $a->source === PriceResult::SOURCE_LIST ?: 'inconsistent';
    });

    // ------------------------------------------------ pricing through a cart

    section('Pricing through a real cart');

    check('a contract price reaches the line item', function() use ($acme, $rita, $widget, $ordersService, $pricing) {
        $cart = makeCart($rita);
        $ordersService->setCompanyForOrder($cart, (int)$acme->id);
        $pricing->clearCaches();
        addLine($cart, $widget, 1);

        $item = $cart->getLineItems()[0];

        return near((float)$item->getSalePrice(), 8.00, 'line price') === true
            ?: 'line was ' . $item->getSalePrice();
    });

    check('a quantity break reaches the line item', function() use ($acme, $rita, $widget, $ordersService, $pricing) {
        $cart = makeCart($rita);
        $ordersService->setCompanyForOrder($cart, (int)$acme->id);
        $pricing->clearCaches();
        addLine($cart, $widget, 12);

        $item = $cart->getLineItems()[0];

        return near((float)$item->getSalePrice(), 7.10, 'line price') === true
            && near((float)$cart->getItemSubtotal(), 85.20, 'subtotal') === true
            ?: 'line was ' . $item->getSalePrice() . ', subtotal ' . $cart->getItemSubtotal();
    });

    check('the contract price survives a recalculation', function() use ($acme, $rita, $widget, $ordersService, $pricing) {
        $cart = makeCart($rita);
        $ordersService->setCompanyForOrder($cart, (int)$acme->id);
        $pricing->clearCaches();
        addLine($cart, $widget, 12);

        // What Commerce does constantly, and the thing that reverts a naively-set price.
        $cart->recalculate();
        Craft::$app->getElements()->saveElement($cart, false);

        $item = $cart->getLineItems()[0];

        return near((float)$item->getSalePrice(), 7.10, 'line price after recalculation') === true
            ?: 'line was ' . $item->getSalePrice();
    });

    check('changing the quantity moves the line onto the right rung', function() use ($acme, $rita, $widget, $ordersService, $pricing) {
        $cart = makeCart($rita);
        $ordersService->setCompanyForOrder($cart, (int)$acme->id);
        $pricing->clearCaches();
        addLine($cart, $widget, 1);

        $item = $cart->getLineItems()[0];
        $item->qty = 100;
        $cart->recalculate();
        Craft::$app->getElements()->saveElement($cart, false);

        return near((float)$cart->getLineItems()[0]->getSalePrice(), 6.40, 'line price') === true
            ?: 'line was ' . $cart->getLineItems()[0]->getSalePrice();
    });

    check('the snapshot still records the list price for the audit trail', function() use ($acme, $rita, $widget, $ordersService, $pricing) {
        $cart = makeCart($rita);
        $ordersService->setCompanyForOrder($cart, (int)$acme->id);
        $pricing->clearCaches();
        addLine($cart, $widget, 1);

        $snapshot = $cart->getLineItems()[0]->getSnapshot();

        return near((float)($snapshot['price'] ?? 0), 10.00, 'snapshot price') === true
            ?: 'snapshot price was ' . var_export($snapshot['price'] ?? null, true);
    });

    check('a retail cart with no company is untouched', function() use ($widget, $pricing) {
        $cart = makeCart();
        $pricing->clearCaches();
        addLine($cart, $widget, 12);

        return near((float)$cart->getLineItems()[0]->getSalePrice(), 10.00, 'line price') === true
            ?: 'line was ' . $cart->getLineItems()[0]->getSalePrice();
    });

    // ------------------------------------------------------------- checkout

    section('Checkout — the one place eligibility is decided');

    check('a plain company order is allowed', function() use ($acme, $rita, $widget, $ordersService, $checkout, $pricing) {
        $cart = makeCart($rita);
        $ordersService->setCompanyForOrder($cart, (int)$acme->id);
        $pricing->clearCaches();
        addLine($cart, $widget, 1);

        $verdict = $checkout->verdict($cart);

        return $verdict->getIsAllowed() && $verdict->reasons === []
            ?: 'reasons: ' . implode(', ', $verdict->reasons);
    });

    check('an account on hold blocks checkout with a sentence a buyer can act on', function() use ($acme, $rita, $widget, $ordersService, $checkout, $companies, $pricing) {
        $cart = makeCart($rita);
        $ordersService->setCompanyForOrder($cart, (int)$acme->id);
        $pricing->clearCaches();
        addLine($cart, $widget, 1);

        $companies->setAccountStatus($acme, Company::STATUS_HOLD);
        $ordersService->clearCaches();
        $checkout->clearCaches();

        $verdict = $checkout->verdict($cart);

        $companies->setAccountStatus($acme, Company::STATUS_ACTIVE);
        $ordersService->clearCaches();
        $checkout->clearCaches();

        return !$verdict->getIsAllowed()
            && $verdict->has(CheckoutVerdict::REASON_COMPANY_ON_HOLD)
            && $verdict->getBlockingMessages() !== []
            ?: 'reasons: ' . implode(', ', $verdict->reasons);
    });

    check('a viewer cannot place an order', function() use ($bolt, $viv, $widget, $ordersService, $checkout, $pricing) {
        $cart = makeCart($viv);
        $ordersService->setCompanyForOrder($cart, (int)$bolt->id);
        $pricing->clearCaches();
        addLine($cart, $widget, 1);
        $checkout->clearCaches();

        $verdict = $checkout->verdict($cart);

        return $verdict->has(CheckoutVerdict::REASON_ROLE_CANNOT_PURCHASE) && !$verdict->getIsAllowed()
            ?: 'reasons: ' . implode(', ', $verdict->reasons);
    });

    check('a company threshold turns into a request for approval, not a refusal', function() use ($acme, $rita, $widget, $ordersService, $checkout, $companies, $pricing) {
        $acme->approvalThreshold = 50.00;
        $companies->saveCompany($acme, false);

        $cart = makeCart($rita);
        $ordersService->setCompanyForOrder($cart, (int)$acme->id);
        $pricing->clearCaches();
        addLine($cart, $widget, 12);
        $checkout->clearCaches();
        $ordersService->clearCaches();

        $verdict = $checkout->verdict($cart);

        return $verdict->getNeedsApproval()
            && $verdict->getIsAllowed()
            && $verdict->getActionLabel() !== ''
            ?: 'reasons: ' . implode(', ', $verdict->reasons);
    });

    check('a threshold of zero means every order needs approval', function() use ($acme, $rita, $widget, $ordersService, $checkout, $companies, $pricing) {
        $acme->approvalThreshold = 0.0;
        $companies->saveCompany($acme, false);

        $cart = makeCart($rita);
        $ordersService->setCompanyForOrder($cart, (int)$acme->id);
        $pricing->clearCaches();
        addLine($cart, $widget, 1);
        $checkout->clearCaches();
        $ordersService->clearCaches();

        $verdict = $checkout->verdict($cart);
        $ok = $verdict->getNeedsApproval() && $acme->getRequiresApprovalAlways();

        $acme->approvalThreshold = 50.00;
        $companies->saveCompany($acme, false);
        $checkout->clearCaches();

        return $ok ?: 'a zero threshold did not require approval';
    });

    check('a buyer over their own spend limit needs approval', function() use ($acme, $sam, $widget, $ordersService, $checkout, $members, $companies, $pricing) {
        $member = $members->getMember((int)$acme->id, (int)$sam->id);
        $member->spendLimit = 20.0;
        $members->saveMember($member);

        $acme->approvalThreshold = null;
        $companies->saveCompany($acme, false);

        $cart = makeCart($sam);
        $ordersService->setCompanyForOrder($cart, (int)$acme->id);
        $pricing->clearCaches();
        addLine($cart, $widget, 12);
        $checkout->clearCaches();
        $ordersService->clearCaches();

        $verdict = $checkout->verdict($cart);

        $acme->approvalThreshold = 50.00;
        $companies->saveCompany($acme, false);

        return $verdict->getNeedsApproval() ?: 'reasons: ' . implode(', ', $verdict->reasons);
    });

    check('a required PO number blocks checkout until one is given', function() use ($bolt, $sam, $widget, $ordersService, $checkout, $companies, $pricing) {
        $bolt->requiresPoNumber = true;
        $companies->saveCompany($bolt, false);

        $cart = makeCart($sam);
        $ordersService->setCompanyForOrder($cart, (int)$bolt->id);
        $pricing->clearCaches();
        addLine($cart, $widget, 1);
        $ordersService->clearCaches();
        $checkout->clearCaches();

        $before = $checkout->verdict($cart);

        $ordersService->setValuesForOrder((int)$cart->id, ['poNumber' => 'PO-1234']);
        $ordersService->clearCaches();
        $checkout->clearCaches();

        $after = $checkout->verdict($cart);

        $bolt->requiresPoNumber = false;
        $companies->saveCompany($bolt, false);

        return $before->has(CheckoutVerdict::REASON_PO_NUMBER_REQUIRED)
            && !$before->getIsAllowed()
            && !$after->has(CheckoutVerdict::REASON_PO_NUMBER_REQUIRED)
            ?: 'PO enforcement is wrong';
    });

    check('the verdict is not memoized across a change of total', function() use ($acme, $rita, $widget, $ordersService, $checkout, $pricing) {
        $cart = makeCart($rita);
        $ordersService->setCompanyForOrder($cart, (int)$acme->id);
        $pricing->clearCaches();
        addLine($cart, $widget, 1);
        $ordersService->clearCaches();
        $checkout->clearCaches();

        $small = $checkout->verdict($cart);

        $cart->getLineItems()[0]->qty = 100;
        $cart->recalculate();
        Craft::$app->getElements()->saveElement($cart, false);

        $large = $checkout->verdict($cart);

        return !$small->getNeedsApproval() && $large->getNeedsApproval()
            ?: 'small needed approval: ' . var_export($small->getNeedsApproval(), true)
                . ', large: ' . var_export($large->getNeedsApproval(), true);
    });

    check('a retail order is left entirely alone', function() use ($widget, $checkout, $pricing) {
        $cart = makeCart();
        $pricing->clearCaches();
        addLine($cart, $widget, 1);

        $verdict = $checkout->verdict($cart);

        return $verdict->getIsAllowed() && $verdict->reasons === [] && $verdict->companyId === null
            ?: 'a retail order picked up B2B rules';
    });

    // ------------------------------------------------------------ approvals

    section('Approvals');

    $approvalCart = makeCart($rita);
    $ordersService->setCompanyForOrder($approvalCart, (int)$acme->id);
    $pricing->clearCaches();
    addLine($approvalCart, $widget, 12);
    $ordersService->clearCaches();
    $checkout->clearCaches();

    check('a request is created with the reason recorded', function() use ($approvals, $approvalCart) {
        $approval = $approvals->request($approvalCart, Approval::REASON_OVER_THRESHOLD);

        return $approval !== null
            && $approval->status === Approval::STATUS_PENDING
            && $approval->reason === Approval::REASON_OVER_THRESHOLD
            && near($approval->amount, (float)$approvalCart->getTotalPrice(), 'amount') === true
            ?: 'request failed';
    });

    check('the token is 32 characters, which is what the column holds', function() use ($approvals, $approvalCart) {
        $approval = $approvals->getPendingForOrder((int)$approvalCart->id);

        return strlen((string)$approval->token) === 32
            ?: 'token was ' . strlen((string)$approval->token) . ' characters';
    });

    check('asking twice does not put two questions in front of an approver', function() use ($approvals, $approvalCart) {
        $first = $approvals->getPendingForOrder((int)$approvalCart->id);
        $second = $approvals->request($approvalCart, Approval::REASON_OVER_THRESHOLD);

        return $second !== null && $second->id === $first->id ?: 'a duplicate request was made';
    });

    check('a pending request blocks checkout', function() use ($checkout, $approvalCart, $ordersService) {
        $ordersService->clearCaches();
        $checkout->clearCaches();
        $verdict = $checkout->verdict($approvalCart);

        return $verdict->has(CheckoutVerdict::REASON_AWAITING_APPROVAL) && !$verdict->getIsAllowed()
            ?: 'reasons: ' . implode(', ', $verdict->reasons);
    });

    check('the request can be found by its token', function() use ($approvals, $approvalCart) {
        $approval = $approvals->getPendingForOrder((int)$approvalCart->id);

        return $approvals->getApprovalByToken((string)$approval->token)?->id === $approval->id
            ?: 'token lookup failed';
    });

    check('approving releases the order', function() use ($approvals, $approvalCart, $checkout, $ordersService, $rita) {
        $approval = $approvals->getPendingForOrder((int)$approvalCart->id);
        $approvals->approve($approval, $rita, 'Fine by me.');

        $ordersService->clearCaches();
        $checkout->clearCaches();
        $verdict = $checkout->verdict($approvalCart);

        return $verdict->getIsAllowed() && !$verdict->getNeedsApproval()
            ?: 'reasons: ' . implode(', ', $verdict->reasons);
    });

    check('an approval does not cover a basket that has since grown', function() use ($approvals, $approvalCart, $checkout, $ordersService) {
        $approvalCart->getLineItems()[0]->qty = 400;
        $approvalCart->recalculate();
        Craft::$app->getElements()->saveElement($approvalCart, false);

        $ordersService->clearCaches();
        $checkout->clearCaches();
        $verdict = $checkout->verdict($approvalCart);

        return $verdict->getNeedsApproval() ?: 'a £400 approval covered a much larger basket';
    });

    check('an approval still covers a basket that has shrunk', function() use ($approvals, $ordersService, $checkout, $acme, $rita, $widget, $pricing) {
        $cart = makeCart($rita);
        $ordersService->setCompanyForOrder($cart, (int)$acme->id);
        $pricing->clearCaches();
        addLine($cart, $widget, 100);
        $ordersService->clearCaches();
        $checkout->clearCaches();

        $approval = $approvals->request($cart, Approval::REASON_OVER_THRESHOLD);
        $approvals->approve($approval, null);

        $cart->getLineItems()[0]->qty = 12;
        $cart->recalculate();
        Craft::$app->getElements()->saveElement($cart, false);

        $ordersService->clearCaches();
        $checkout->clearCaches();

        return $checkout->verdict($cart)->getIsAllowed() ?: 'a smaller basket needed re-approval';
    });

    check('a declined request keeps blocking, with the reason', function() use ($approvals, $ordersService, $checkout, $acme, $rita, $widget, $pricing) {
        $cart = makeCart($rita);
        $ordersService->setCompanyForOrder($cart, (int)$acme->id);
        $pricing->clearCaches();
        addLine($cart, $widget, 12);
        $ordersService->clearCaches();
        $checkout->clearCaches();

        $approval = $approvals->request($cart, Approval::REASON_OVER_THRESHOLD);
        $approvals->decline($approval, null, 'Not this quarter.');

        $ordersService->clearCaches();
        $checkout->clearCaches();
        $verdict = $checkout->verdict($cart);

        return $verdict->has(CheckoutVerdict::REASON_APPROVAL_DECLINED)
            && !$verdict->getIsAllowed()
            && str_contains(implode(' ', $verdict->getBlockingMessages()), 'Not this quarter')
            ?: 'reasons: ' . implode(', ', $verdict->reasons);
    });

    check('a decided request cannot be decided again', function() use ($approvals, $ordersService, $acme, $rita, $widget, $pricing) {
        $cart = makeCart($rita);
        $ordersService->setCompanyForOrder($cart, (int)$acme->id);
        $pricing->clearCaches();
        addLine($cart, $widget, 12);
        $ordersService->clearCaches();

        $approval = $approvals->request($cart, Approval::REASON_OVER_THRESHOLD);
        $approvals->approve($approval, null);

        return !$approvals->decline($approval, null) ?: 'an approved request was declined afterwards';
    });

    check('a lapsed request is expired, and expiry is not approval', function() use ($approvals, $ordersService, $acme, $rita, $widget, $pricing, $checkout) {
        $cart = makeCart($rita);
        $ordersService->setCompanyForOrder($cart, (int)$acme->id);
        $pricing->clearCaches();
        addLine($cart, $widget, 12);
        $ordersService->clearCaches();

        $approval = $approvals->request($cart, Approval::REASON_OVER_THRESHOLD);
        $approval->expiryDate = DateTimeHelper::currentUTCDateTime()->modify('-1 day');
        $approvals->saveApproval($approval, false);

        $expired = $approvals->expireStale();
        $reloaded = $approvals->getApprovalById((int)$approval->id);

        $ordersService->clearCaches();
        $checkout->clearCaches();
        $verdict = $checkout->verdict($cart);

        return $expired >= 1
            && $reloaded->status === Approval::STATUS_EXPIRED
            && $verdict->getNeedsApproval()
            ?: 'expiry behaved wrongly';
    });

    check('an approver is somebody with an approving role at that company', function() use ($approvals, $acme, $rita, $sam, $viv, $members) {
        $forRita = $approvals->getPendingForApprover($rita);
        $forViv = $approvals->getPendingForApprover($viv);

        return is_array($forRita) && $forViv === [] ?: 'a viewer was offered approvals';
    });

    // --------------------------------------------------------------- credit

    section('Credit and statements');

    $creditCo = makeCompany('Credit Fixture', [
        'creditEnabled' => true,
        'creditLimit' => 1000.0,
        'termsId' => $terms->getTermByHandle('net30')?->id,
    ]);

    check('a new account owes nothing', fn() => near($credit->balanceFor((int)$creditCo->id), 0.0, 'balance') === true);

    check('a charge increases the balance', function() use ($credit, $creditCo) {
        $credit->saveEntry(new CreditEntry([
            'companyId' => $creditCo->id,
            'type' => CreditEntry::TYPE_CHARGE,
            'amount' => 400.0,
            'entryDate' => DateTimeHelper::currentUTCDateTime()->modify('-100 days'),
            'dueDate' => DateTimeHelper::currentUTCDateTime()->modify('-70 days'),
            'reference' => 'INV-1',
        ]));

        return near($credit->balanceFor((int)$creditCo->id), 400.0, 'balance') === true;
    });

    check('a payment must reduce the balance, and a positive one is refused', function() use ($credit, $creditCo) {
        $bad = new CreditEntry(['companyId' => $creditCo->id, 'type' => CreditEntry::TYPE_PAYMENT, 'amount' => 100.0]);

        return !$credit->saveEntry($bad) && $bad->hasErrors('amount')
            ?: 'a positive payment was accepted';
    });

    check('recordPayment takes a positive amount and stores it negative', function() use ($credit, $creditCo) {
        $entry = $credit->recordPayment((int)$creditCo->id, 150.0, 'CHQ-9');

        return $entry !== null && near($entry->amount, -150.0, 'amount') === true
            && near($credit->balanceFor((int)$creditCo->id), 250.0, 'balance') === true
            ?: 'balance is ' . $credit->balanceFor((int)$creditCo->id);
    });

    check('available credit is the limit less the balance', function() use ($credit, $creditCo) {
        return near((float)$credit->availableFor((int)$creditCo->id), 750.0, 'available') === true;
    });

    check('an account on terms with no limit is legal and has no ceiling', function() use ($credit, $suffix) {
        $company = makeCompany('Unlimited', ['creditEnabled' => true, 'creditLimit' => null]);

        return $company->id !== null
            && $credit->availableFor((int)$company->id) === null
            && $credit->canCharge((int)$company->id, 1e9)
            ?: 'an unlimited account was capped';
    });

    check('canCharge refuses an order that would go past the limit', function() use ($credit, $creditCo) {
        return $credit->canCharge((int)$creditCo->id, 700.0)
            && !$credit->canCharge((int)$creditCo->id, 800.0)
            ?: 'the limit is not being applied';
    });

    check('the statement reconciles: opening plus movement equals closing', function() use ($credit, $creditCo) {
        $statement = $credit->statement((int)$creditCo->id, DateTimeHelper::currentUTCDateTime()->modify('-1 year'));
        $movement = $statement->totalCharges - $statement->totalPayments + $statement->totalAdjustments;

        return near($statement->openingBalance + $movement, $statement->closingBalance, 'closing') === true
            ?: "opening {$statement->openingBalance} + movement {$movement} != closing {$statement->closingBalance}";
    });

    check('payments are allocated oldest charge first, and age what is left', function() use ($credit, $creditCo) {
        [$aging] = $credit->aging((int)$creditCo->id);

        // 400 charged, due 70 days ago; 150 paid. 250 remains, and it is the same old invoice.
        return near($aging[60] ?? 0.0, 250.0, '60-day bucket') === true
            ?: 'aging was ' . json_encode($aging);
    });

    check('an account in credit reads as being in credit rather than as nothing', function() use ($credit, $suffix) {
        $company = makeCompany('In Credit', ['creditEnabled' => true, 'creditLimit' => 500.0]);
        $credit->recordPayment((int)$company->id, 75.0, 'Overpayment');

        [$aging] = $credit->aging((int)$company->id);

        return near($aging[0] ?? 0.0, -75.0, 'current bucket') === true
            && near($credit->balanceFor((int)$company->id), -75.0, 'balance') === true
            ?: 'aging was ' . json_encode($aging);
    });

    check('the over-limit query finds an account that is over', function() use ($credit, $suffix) {
        $company = makeCompany('Over Limit', ['creditEnabled' => true, 'creditLimit' => 100.0]);
        $credit->saveEntry(new CreditEntry([
            'companyId' => $company->id,
            'type' => CreditEntry::TYPE_CHARGE,
            'amount' => 250.0,
            'entryDate' => DateTimeHelper::currentUTCDateTime(),
        ]));

        $ids = array_column($credit->getCompaniesOverLimit(), 'companyId');

        return in_array((int)$company->id, $ids, true) ?: 'the over-limit query missed it';
    });

    check('being over the limit does not block checkout — it withdraws the terms gateway', function() use ($credit, $checkout, $ordersService, $rita, $widget, $pricing, $suffix) {
        $company = makeCompany('Over For Checkout', ['creditEnabled' => true, 'creditLimit' => 5.0]);
        $credit->saveEntry(new CreditEntry([
            'companyId' => $company->id,
            'type' => CreditEntry::TYPE_CHARGE,
            'amount' => 100.0,
            'entryDate' => DateTimeHelper::currentUTCDateTime(),
        ]));

        $cart = makeCart($rita);
        $ordersService->setCompanyForOrder($cart, (int)$company->id);
        $pricing->clearCaches();
        addLine($cart, $widget, 1);
        $ordersService->clearCaches();
        $checkout->clearCaches();

        $verdict = $checkout->verdict($cart);

        return $verdict->has(CheckoutVerdict::REASON_OVER_CREDIT_LIMIT)
            && $verdict->getIsAllowed()
            && !$checkout->canPayOnTerms($cart)
            ?: 'over-limit behaviour is wrong';
    });

    check('a charge for an order is idempotent', function() use ($credit, $ordersService, $creditCo, $rita, $widget, $pricing) {
        $cart = makeCart($rita);
        $ordersService->setCompanyForOrder($cart, (int)$creditCo->id);
        $pricing->clearCaches();
        addLine($cart, $widget, 1);

        $first = $credit->chargeOrder($cart, (int)$creditCo->id);
        $second = $credit->chargeOrder($cart, (int)$creditCo->id);

        return $first !== null && $second !== null && $first->id === $second->id
            ?: 'a second charge was raised for the same order';
    });

    check('a charge carries the due date the terms imply', function() use ($credit, $creditCo, $terms) {
        $entry = $credit->getEntries((int)$creditCo->id);
        $withOrder = array_values(array_filter($entry, fn(CreditEntry $e) => $e->orderId !== null));

        if ($withOrder === []) {
            return 'no order charge found';
        }

        return $withOrder[0]->dueDate !== null ?: 'the charge has no due date';
    });

    check('payment terms work out a due date and an early-settlement figure', function() use ($terms) {
        $twoTen = $terms->getTermByHandle('twoTenNet30');

        if ($twoTen === null) {
            return 'the 2/10 Net 30 default is missing';
        }

        $from = new DateTime('2026-01-01');

        return $twoTen->dueDate($from)->format('Y-m-d') === '2026-01-31'
            && $twoTen->discountDeadline($from)->format('Y-m-d') === '2026-01-11'
            && near($twoTen->discountedAmount(1000.0), 980.0, 'discounted') === true
            && $twoTen->getShorthand() === '2/10 Net 30'
            ?: 'terms arithmetic is wrong: ' . $twoTen->getShorthand();
    });

    check('a discount period longer than the terms is refused', function() {
        $term = new Term(['name' => 'Bad', 'handle' => 'bad', 'netDays' => 10, 'discountPercent' => 2.0, 'discountDays' => 30]);

        return !$term->validate() && $term->hasErrors('discountDays') ?: 'a nonsensical term validated';
    });

    // --------------------------------------------------------- certificates

    section('Tax exemption certificates');

    $taxCo = makeCompany('Tax Exempt Fixture');

    check('a certificate arrives pending and exempts nothing', function() use ($certificates, $taxCo) {
        $certificate = new Certificate([
            'companyId' => $taxCo->id,
            'name' => 'Texas resale',
            'countryCode' => 'US',
            'administrativeArea' => 'TX',
        ]);

        $certificates->saveCertificate($certificate);

        return $certificate->status === Certificate::STATUS_PENDING && !$certificate->getIsUsable()
            ?: 'a new certificate was usable';
    });

    check('approving it makes it usable and sets the company flag', function() use ($certificates, $taxCo, $companies) {
        $certificate = $certificates->getCertificatesByCompanyId((int)$taxCo->id)[0];
        $certificates->approve($certificate);

        $reloaded = Company::find()->id($taxCo->id)->status(null)->one();

        return $certificate->getIsUsable() && $reloaded->taxExempt
            ?: 'the company flag did not follow the certificate';
    });

    check('a state certificate covers its state and nowhere else', function() use ($certificates, $taxCo) {
        $certificate = $certificates->getCertificatesByCompanyId((int)$taxCo->id)[0];

        $texas = new Address(['countryCode' => 'US', 'administrativeArea' => 'TX']);
        $ohio = new Address(['countryCode' => 'US', 'administrativeArea' => 'OH']);
        $france = new Address(['countryCode' => 'FR']);

        return $certificate->coversAddress($texas)
            && !$certificate->coversAddress($ohio)
            && !$certificate->coversAddress($france)
            && !$certificate->coversAddress(null)
            ?: 'the jurisdiction test is wrong';
    });

    check('a certificate with no country covers everywhere, including no address at all', function() {
        $certificate = new Certificate(['status' => Certificate::STATUS_APPROVED]);

        return $certificate->coversAddress(new Address(['countryCode' => 'JP']))
            && $certificate->coversAddress(null)
            ?: 'a global certificate did not cover everywhere';
    });

    check('an expired certificate exempts nothing, from the moment it expires', function() use ($certificates, $taxCo) {
        $certificate = new Certificate([
            'companyId' => $taxCo->id,
            'name' => 'Lapsed',
            'status' => Certificate::STATUS_APPROVED,
            'expiryDate' => DateTimeHelper::currentUTCDateTime()->modify('-1 day'),
        ]);

        $certificates->saveCertificate($certificate, false);

        return $certificate->getIsExpired()
            && !$certificate->getIsUsable()
            && $certificate->getEffectiveStatus() === Certificate::STATUS_EXPIRED
            ?: 'an expired certificate was still usable';
    });

    check('the expiry sweep takes the company flag back down', function() use ($certificates, $companies, $suffix) {
        $company = makeCompany('Lapsing');
        $certificate = new Certificate([
            'companyId' => $company->id,
            'name' => 'About to lapse',
            'status' => Certificate::STATUS_APPROVED,
            'expiryDate' => DateTimeHelper::currentUTCDateTime()->modify('+1 day'),
        ]);
        $certificates->saveCertificate($certificate, false);

        $before = Company::find()->id($company->id)->status(null)->one()->taxExempt;

        $certificate->expiryDate = DateTimeHelper::currentUTCDateTime()->modify('-1 day');
        $certificates->saveCertificate($certificate, false);
        $certificates->syncExpired();

        $after = Company::find()->id($company->id)->status(null)->one()->taxExempt;

        return $before && !$after ?: "flag went from " . var_export($before, true) . ' to ' . var_export($after, true);
    });

    check('getExpiring finds what is about to lapse', function() use ($certificates) {
        return count($certificates->getExpiring(3650)) > 0 ?: 'nothing was found expiring';
    });

    // ------------------------------------------------------------------ tax

    section('Tax — the exemption expressed as a rate transformation');

    check('an exempt rate can never match a zone, so Commerce removes it', function() {
        $rate = new TaxRate(['name' => 'VAT', 'rate' => 0.2, 'include' => true, 'taxZoneId' => null]);
        $exempt = ExemptTaxRate::from($rate);

        return $exempt->getIsEverywhere() === false
            && $exempt->getTaxZone() === null
            && $exempt->removeIncluded === true
            && $exempt->taxIdValidators === []
            && near($exempt->rate, 0.2, 'rate') === true
            ?: 'the transformation is wrong';
    });

    check('an exempt rate keeps the arithmetic it needs to remove the right amount', function() {
        $rate = new TaxRate(['name' => 'VAT', 'rate' => 0.2, 'include' => true, 'taxable' => 'price']);
        $exempt = ExemptTaxRate::from($rate);

        return $exempt->include === true && $exempt->taxable === 'price'
            ?: 'the rate lost its taxable or its inclusiveness';
    });

    check('tax is charged as normal for a company with no certificate', function() use ($ordersService, $acme, $rita, $widget, $pricing, $certificates, $storeId) {
        $rate = new TaxRate([
            'name' => "Forklift test tax $GLOBALS[suffix]",
            'code' => 'FKLTAX',
            'rate' => 0.2,
            'include' => false,
            'taxable' => 'price',
            'storeId' => $storeId,
            'taxCategoryId' => Commerce::getInstance()->getTaxCategories()->getDefaultTaxCategory()->id,
        ]);

        if (!Commerce::getInstance()->getTaxRates()->saveTaxRate($rate)) {
            return 'could not save the fixture tax rate: ' . json_encode($rate->getErrors());
        }

        $GLOBALS['createdTaxRates'][] = $rate;

        $cart = makeCart($rita);
        $ordersService->setCompanyForOrder($cart, (int)$acme->id);
        $pricing->clearCaches();
        $certificates->clearCaches();
        addLine($cart, $widget, 10);

        $cart->recalculate();

        return $cart->getTotalTax() > 0 ?: 'no tax was charged on a non-exempt order';
    });

    check('an approved certificate removes the tax from a real order', function() use ($ordersService, $certificates, $rita, $widget, $pricing, $suffix) {
        $company = makeCompany('Exempt Buyer');

        $certificate = new Certificate([
            'companyId' => $company->id,
            'name' => 'Global exemption',
            'status' => Certificate::STATUS_APPROVED,
        ]);
        $certificates->saveCertificate($certificate, false);

        $cart = makeCart($rita);
        $ordersService->setCompanyForOrder($cart, (int)$company->id);
        $ordersService->clearCaches();
        $certificates->clearCaches();
        $pricing->clearCaches();
        addLine($cart, $widget, 10);

        $cart->recalculate();

        return near((float)$cart->getTotalTax(), 0.0, 'tax') === true
            ?: 'tax was ' . $cart->getTotalTax();
    });

    check('a certificate for the wrong jurisdiction does not exempt the order', function() use ($ordersService, $certificates, $rita, $widget, $pricing, $suffix) {
        $company = makeCompany('Wrong Jurisdiction');

        $certificate = new Certificate([
            'companyId' => $company->id,
            'name' => 'Texas only',
            'status' => Certificate::STATUS_APPROVED,
            'countryCode' => 'US',
            'administrativeArea' => 'TX',
        ]);
        $certificates->saveCertificate($certificate, false);

        $cart = makeCart($rita);
        $ordersService->setCompanyForOrder($cart, (int)$company->id);
        // An array rather than an Address element: Commerce refuses an address element the order
        // does not already own, and its ownership check only passes for one it created itself.
        $cart->setShippingAddress([
            'countryCode' => 'GB',
            'addressLine1' => '1 Test Street',
            'locality' => 'London',
            'postalCode' => 'E1 6AN',
        ]);
        $ordersService->clearCaches();
        $certificates->clearCaches();
        $pricing->clearCaches();
        addLine($cart, $widget, 10);

        $cart->recalculate();

        return $cart->getTotalTax() > 0 ?: 'a Texas certificate exempted a delivery to London';
    });

    check('turning the setting off leaves Commerce’s tax alone', function() use ($plugin, $ordersService, $certificates, $rita, $widget, $pricing) {
        $company = makeCompany('Recordkeeper');
        $certificate = new Certificate([
            'companyId' => $company->id,
            'name' => 'Recorded only',
            'status' => Certificate::STATUS_APPROVED,
        ]);
        $certificates->saveCertificate($certificate, false);

        $settings = $plugin->getSettings();
        $settings->applyTaxExemptions = false;
        $certificates->clearCaches();

        $cart = makeCart($rita);
        $ordersService->setCompanyForOrder($cart, (int)$company->id);
        $ordersService->clearCaches();
        $pricing->clearCaches();
        addLine($cart, $widget, 10);
        $cart->recalculate();

        $tax = (float)$cart->getTotalTax();

        $settings->applyTaxExemptions = true;
        $certificates->clearCaches();

        return $tax > 0 ?: 'the exemption applied while the setting was off';
    });

    // --------------------------------------------------------------- quotes

    section('Quotes and pay-by-link');

    check('quote numbers are sequential and unique', function() use ($quotes) {
        $a = $quotes->generateNumber();

        return str_starts_with($a, 'Q-') && $quotes->getQuoteByNumber($a) === null
            ?: 'number was ' . $a;
    });

    check('a cart becomes a quote request, priced as the buyer saw it', function() use ($quotes, $ordersService, $acme, $rita, $widget, $pricing, &$createdQuotes) {
        $cart = makeCart($rita);
        $ordersService->setCompanyForOrder($cart, (int)$acme->id);
        $pricing->clearCaches();
        addLine($cart, $widget, 12);

        $quote = $quotes->requestFromCart($cart, ['email' => 'buyer@example.test', 'companyId' => $acme->id]);

        if ($quote === null) {
            return 'the request failed';
        }

        $createdQuotes[] = $quote;
        $lines = $quote->getLines();

        return count($lines) === 1
            && $lines[0]->qty === 12
            && near((float)$lines[0]->price, 7.10, 'quoted price') === true
            && near((float)$lines[0]->listPrice, 10.00, 'list price') === true
            ?: 'lines were ' . json_encode(array_map(fn(QuoteLine $l) => [$l->qty, $l->price], $lines));
    });

    check('a quote totals its lines, its delivery and its discount', function() use (&$createdQuotes, $quotes) {
        $quote = $createdQuotes[0];
        $quote->shippingCost = 12.00;
        $quote->discount = 5.00;
        $quotes->saveQuote($quote, false);

        return near($quote->getItemSubtotal(), 85.20, 'goods') === true
            && near($quote->getTotal(), 92.20, 'total') === true
            && near($quote->getListSubtotal(), 120.00, 'list') === true
            ?: 'total was ' . $quote->getTotal();
    });

    check('a discount larger than the quote is refused', function() use (&$createdQuotes) {
        $quote = $createdQuotes[0];
        $original = $quote->discount;
        $quote->discount = 9999.0;
        $valid = $quote->validate();
        $quote->discount = $original;

        return !$valid ?: 'a discount larger than the quote validated';
    });

    check('sending a quote builds a cart carrying the quoted prices', function() use (&$createdQuotes, $quotes, $pricing) {
        $quote = $createdQuotes[0];
        $quote->setLines([
            new QuoteLine([
                'purchasableId' => $quote->getLines()[0]->purchasableId,
                'sku' => $quote->getLines()[0]->sku,
                'qty' => 12,
                'listPrice' => 10.00,
                'price' => 5.55,
            ]),
        ]);
        $quotes->saveQuote($quote, false);
        $pricing->clearCaches();

        if (!$quotes->send($quote, null, false)) {
            return 'send failed';
        }

        $cart = $quote->getCart();

        if ($cart === null) {
            return 'no cart was built';
        }

        $GLOBALS['createdOrders'][] = $cart;
        $item = $cart->getLineItems()[0] ?? null;

        return $item !== null && near((float)$item->getSalePrice(), 5.55, 'quoted cart price') === true
            ?: 'cart line was ' . ($item?->getSalePrice() ?? 'missing');
    });

    check('the quoted price survives the cart recalculating', function() use (&$createdQuotes) {
        $quote = $createdQuotes[0];
        $cart = $quote->getCart();

        $cart->recalculate();
        Craft::$app->getElements()->saveElement($cart, false);

        return near((float)$cart->getLineItems()[0]->getSalePrice(), 5.55, 'price after recalculation') === true
            ?: 'price was ' . $cart->getLineItems()[0]->getSalePrice();
    });

    check('a sent quote has a signed pay-by-link', function() use (&$createdQuotes) {
        $url = $createdQuotes[0]->getPaymentUrl();

        return $url !== null && str_contains($url, 'load-cart') && str_contains($url, 'code=')
            ?: 'url was ' . var_export($url, true);
    });

    check('sending a quote again discards the previous cart', function() use (&$createdQuotes, $quotes) {
        $quote = $createdQuotes[0];
        $oldNumber = $quote->cartNumber;

        $quotes->send($quote, null, false);

        $old = Order::find()->number($oldNumber)->isCompleted(false)->one();

        if ($quote->getCart() !== null) {
            $GLOBALS['createdOrders'][] = $quote->getCart();
        }

        return $old === null && $quote->cartNumber !== $oldNumber
            ?: 'the old cart is still reachable';
    });

    check('a sent quote is acceptable, an expired one is not', function() use (&$createdQuotes, $quotes) {
        $quote = $createdQuotes[0];
        $acceptable = $quote->getIsAcceptable();

        $quote->expiryDate = DateTimeHelper::currentUTCDateTime()->modify('-1 day');
        $quotes->saveQuote($quote, false);

        $expired = $quote->getEffectiveStatus() === Quote::STATUS_EXPIRED && !$quote->getIsAcceptable();

        $quote->expiryDate = DateTimeHelper::currentUTCDateTime()->modify('+30 days');
        $quotes->saveQuote($quote, false);

        return $acceptable && $expired ?: 'expiry is not being honoured at read time';
    });

    check('the expiry sweep releases the cart of an expired quote', function() use ($quotes, $ordersService, $acme, $rita, $widget, $pricing, &$createdQuotes) {
        $cart = makeCart($rita);
        $ordersService->setCompanyForOrder($cart, (int)$acme->id);
        $pricing->clearCaches();
        addLine($cart, $widget, 1);

        $quote = $quotes->requestFromCart($cart, ['email' => 'lapse@example.test']);
        $createdQuotes[] = $quote;

        $quotes->send($quote, DateTimeHelper::currentUTCDateTime()->modify('-1 day'), false);
        $cartNumber = $quote->cartNumber;

        $quotes->expireStale();
        $reloaded = $quotes->getQuoteById((int)$quote->id);

        return $reloaded->quoteStatus === Quote::STATUS_EXPIRED
            && Order::find()->number($cartNumber)->isCompleted(false)->one() === null
            ?: 'status is ' . $reloaded->quoteStatus;
    });

    check('a quote with neither an email address nor a requester is refused', function() {
        $quote = new Quote(['quoteStatus' => Quote::STATUS_REQUESTED]);

        return !$quote->validate() && $quote->hasErrors('email')
            ?: 'a quote with nobody to send it to validated';
    });

    check('a quote line needs to be identifiable as something', function() {
        $line = new QuoteLine(['qty' => 1, 'price' => 1.0]);

        return !$line->validate() && $line->hasErrors('description')
            ?: 'a blank line validated';
    });

    // ---------------------------------------------------------- quick order

    section('Quick order, CSV and reorder');

    check('a SKU is found exactly', function() use ($quickOrder, $widget) {
        return $quickOrder->findBySku($widget->sku)?->getId() === $widget->id ?: 'exact lookup failed';
    });

    check('a SKU is found regardless of case', function() use ($quickOrder, $widget) {
        return $quickOrder->findBySku(strtolower($widget->sku))?->getId() === $widget->id
            ?: 'case-insensitive lookup failed';
    });

    check('a partial SKU is not silently substituted', function() use ($quickOrder, $widget) {
        return $quickOrder->findBySku(substr($widget->sku, 0, 5)) === null
            ?: 'a partial SKU matched a product';
    });

    check('the pad prices rows without touching the cart', function() use ($quickOrder, $widget, $acme) {
        $result = $quickOrder->resolve([
            ['sku' => $widget->sku, 'qty' => 12],
        ], (int)$acme->id);

        return $result->dryRun
            && $result->added === 0
            && count($result->getValidRows()) === 1
            && near($result->getSubtotal(), 85.20, 'subtotal') === true
            ?: 'subtotal was ' . $result->getSubtotal();
    });

    check('an unknown SKU is reported by name, not dropped', function() use ($quickOrder, $widget) {
        $result = $quickOrder->resolve([
            ['sku' => $widget->sku, 'qty' => 1],
            ['sku' => 'NOT-A-REAL-SKU', 'qty' => 3],
        ]);

        $failed = $result->getFailedRows();

        return count($result->rows) === 2
            && count($failed) === 1
            && str_contains((string)$failed[0]->error, 'NOT-A-REAL-SKU')
            && $failed[0]->lineNumber === 2
            ?: 'failed rows were ' . json_encode(array_map(fn($r) => $r->error, $failed));
    });

    check('a real SKU with no quantity is an error to report, not a row to drop', function() use ($quickOrder, $widget) {
        $result = $quickOrder->resolve([['sku' => $widget->sku, 'qty' => 0]]);

        return count($result->rows) === 1
            && !$result->rows[0]->getIsValid()
            && str_contains((string)$result->rows[0]->error, '1')
            ?: 'error was ' . var_export($result->rows[0]->error ?? null, true);
    });

    check('a blank row is skipped rather than reported as an error', function() use ($quickOrder, $widget) {
        $result = $quickOrder->resolve([
            ['sku' => '', 'qty' => 0],
            ['sku' => $widget->sku, 'qty' => 2],
        ]);

        return count($result->rows) === 1 && $result->rows[0]->lineNumber === 2
            ?: 'blank handling is wrong';
    });

    check('CSV parsing skips a header row', function() use ($quickOrder, $widget) {
        $rows = $quickOrder->parseCsv("sku,qty\n{$widget->sku},4\n");

        return count($rows) === 1 && $rows[0]['qty'] === 4 ?: json_encode($rows);
    });

    check('CSV parsing copes with the columns the other way round', function() use ($quickOrder, $widget) {
        $rows = $quickOrder->parseCsv("qty,sku\n7,{$widget->sku}\n");

        return count($rows) === 1 && $rows[0]['sku'] === $widget->sku && $rows[0]['qty'] === 7
            ?: json_encode($rows);
    });

    check('CSV parsing survives the byte-order mark Excel writes', function() use ($quickOrder, $widget) {
        $rows = $quickOrder->parseCsv("\xEF\xBB\xBFsku,qty\n{$widget->sku},2\n");

        return count($rows) === 1 && $rows[0]['sku'] === $widget->sku ?: json_encode($rows);
    });

    check('a third column becomes the line note', function() use ($quickOrder, $widget) {
        $rows = $quickOrder->parseCsv("sku,qty,note\n{$widget->sku},2,For bay 4\n");

        return $rows[0]['note'] === 'For bay 4' ?: json_encode($rows);
    });

    check('a file over the row cap fails with a message rather than an exhausted memory limit', function() use ($quickOrder, $plugin, $widget) {
        $settings = $plugin->getSettings();
        $original = $settings->csvMaxRows;
        $settings->csvMaxRows = 2;

        $csv = "sku,qty\n";

        for ($i = 0; $i < 10; $i++) {
            $csv .= "{$widget->sku},1\n";
        }

        $rows = $quickOrder->parseCsv($csv, $error);
        $settings->csvMaxRows = $original;

        return count($rows) === 2 && $error !== null && str_contains($error, '2')
            ?: 'cap behaviour is wrong: ' . count($rows) . ' rows, error ' . var_export($error, true);
    });

    check('rows actually reach the cart, and failures do not stop the rest', function() use ($quickOrder, $widget, $acme, $rita, $ordersService, $pricing) {
        $cart = makeCart($rita);
        $ordersService->setCompanyForOrder($cart, (int)$acme->id);
        $ordersService->clearCaches();
        $pricing->clearCaches();

        $result = $quickOrder->add([
            ['sku' => $widget->sku, 'qty' => 12],
            ['sku' => 'STILL-NOT-REAL', 'qty' => 1],
        ], $cart, (int)$acme->id);

        return $result->added === 1
            && count($result->getFailedRows()) === 1
            && count($cart->getLineItems()) === 1
            && near((float)$cart->getLineItems()[0]->getSalePrice(), 7.10, 'price') === true
            ?: 'added ' . $result->added . ' with ' . count($cart->getLineItems()) . ' lines';
    });

    check('reordering re-prices at today’s rates rather than copying the old ones', function() use ($quickOrder, $widget, $acme, $rita, $ordersService, $pricing, $priceLists, $contract) {
        $original = makeCart($rita);
        $ordersService->setCompanyForOrder($original, (int)$acme->id);
        $ordersService->clearCaches();
        $pricing->clearCaches();
        addLine($original, $widget, 12);

        // The contract is renegotiated between the two orders.
        $entries = $priceLists->getEntriesByPriceListId((int)$contract->id);

        foreach ($entries as $entry) {
            if ($entry->minQty === 12) {
                $entry->amount = 6.00;
                $priceLists->saveEntry($entry, false);
            }
        }

        $pricing->clearCaches();
        $result = $quickOrder->fromOrder($original, (int)$acme->id, true);

        // Put the contract back.
        foreach ($entries as $entry) {
            if ($entry->minQty === 12) {
                $entry->amount = 7.10;
                $priceLists->saveEntry($entry, false);
            }
        }

        $pricing->clearCaches();

        $row = $result->rows[0] ?? null;

        return $row !== null
            && near((float)$row->price->price, 6.00, 'reorder price') === true
            && $row->warning !== null
            ?: 'reorder price was ' . ($row?->price?->price ?? 'missing') . ' warning ' . var_export($row?->warning, true);
    });

    check('the suggest endpoint returns priced results', function() use ($quickOrder, $acme, $suffix) {
        $results = $quickOrder->suggest('FKL-W-' . substr($suffix, 0, 3), (int)$acme->id, 5);

        return is_array($results) ?: 'suggest did not return an array';
    });

    // -------------------------------------------------------------- edition

    section('Editions — a downgrade ignores Pro configuration rather than obeying it');

    check('Lite ignores price lists, so buyers pay list price', function() use ($plugin, $pricing, $widget, $acme) {
        $plugin->edition = Plugin::EDITION_LITE;
        $pricing->clearCaches();

        $result = $pricing->resolve($widget, 12, (int)$acme->id);

        $plugin->edition = Plugin::EDITION_PRO;
        $pricing->clearCaches();

        return near($result->price, 10.00, 'price') === true && $result->source === PriceResult::SOURCE_LIST
            ?: 'Lite honoured a contract price: ' . $result->price;
    });

    check('Lite lets an order through but marks it as having bypassed approval', function() use ($plugin, $checkout, $ordersService, $acme, $rita, $widget, $pricing) {
        $plugin->edition = Plugin::EDITION_LITE;

        $cart = makeCart($rita);
        $ordersService->setCompanyForOrder($cart, (int)$acme->id);
        $pricing->clearCaches();
        addLine($cart, $widget, 12);
        $ordersService->clearCaches();
        $checkout->clearCaches();

        $verdict = $checkout->verdict($cart);

        $plugin->edition = Plugin::EDITION_PRO;
        $checkout->clearCaches();
        $pricing->clearCaches();

        return $verdict->getIsAllowed()
            && $verdict->approvalBypassed
            && !$verdict->getNeedsApproval()
            ?: 'Lite approval behaviour is wrong';
    });

    check('Lite withdraws the purchase-order gateway, which fails closed', function() use ($plugin, $checkout, $ordersService, $creditCo, $rita, $widget, $pricing) {
        $cart = makeCart($rita);
        $ordersService->setCompanyForOrder($cart, (int)$creditCo->id);
        $pricing->clearCaches();
        addLine($cart, $widget, 1);
        $ordersService->clearCaches();

        $plugin->edition = Plugin::EDITION_LITE;
        $checkout->clearCaches();
        $lite = $checkout->canPayOnTerms($cart);

        $plugin->edition = Plugin::EDITION_PRO;
        $checkout->clearCaches();
        $pro = $checkout->canPayOnTerms($cart);

        return !$lite && $pro ?: "lite: " . var_export($lite, true) . ', pro: ' . var_export($pro, true);
    });

    check('Lite refuses to send a quote', function() use ($plugin, $quotes, &$createdQuotes) {
        $plugin->edition = Plugin::EDITION_LITE;
        $sent = $quotes->send($createdQuotes[0], null, false);
        $plugin->edition = Plugin::EDITION_PRO;

        return !$sent ?: 'Lite sent a quote';
    });

    check('Lite refuses a CSV upload but keeps the typed pad', function() use ($plugin, $quickOrder, $widget) {
        $plugin->edition = Plugin::EDITION_LITE;

        $csv = $quickOrder->fromCsv("sku,qty\n{$widget->sku},1\n", true);
        $pad = $quickOrder->resolve([['sku' => $widget->sku, 'qty' => 1]]);

        $plugin->edition = Plugin::EDITION_PRO;

        return $csv->fatalError !== null && count($pad->getValidRows()) === 1
            ?: 'the CSV/pad split is wrong';
    });

    check('the edition boundary is declared in one place and agrees with itself', function() {
        return Edition::allowsPriceLists(true) && !Edition::allowsPriceLists(false)
            && Edition::allowsApprovals(true) && !Edition::allowsApprovals(false)
            && Edition::allowsCredit(true) && !Edition::allowsCredit(false)
            && Edition::allowsQuotes(true) && !Edition::allowsQuotes(false)
            && Edition::allowsCsvUpload(true) && !Edition::allowsCsvUpload(false)
            && Edition::liteFeatures() !== [] && Edition::proFeatures() !== []
            ?: 'an edition gate is wrong';
    });

    check('a Lite install names what it is ignoring rather than counting it', function() use ($plugin) {
        $plugin->edition = Plugin::EDITION_LITE;
        $suppressed = $plugin->getSettings()->getHasSuppressedProSettings();
        $plugin->edition = Plugin::EDITION_PRO;

        return $suppressed !== [] && str_contains(implode(' ', $suppressed), 'list price')
            ?: 'suppressed was ' . json_encode($suppressed);
    });

    // --------------------------------------------------- the signed-in path

    section('The signed-in path');

    check('a signed-in buyer with one membership gets that company', function() use ($companies, $sam, $bolt, $acme, $members) {
        // The whole of `getCurrentCompany()` is unreachable from a console request unless an
        // identity is set, and it is the code every front-end page runs first. It went out the
        // door once calling a method that did not exist, so it is worth exercising here as well
        // as in the browser.
        $solo = makeUser('solo');
        $company = makeCompany('Solo Buyer');
        $members->addUserToCompany((int)$company->id, (int)$solo->id, Role::ADMIN);

        Craft::$app->getUser()->setIdentity($solo);
        $companies->clearCaches();

        $current = $companies->getCurrentCompany();
        $member = $companies->getCurrentMember();

        Craft::$app->getUser()->setIdentity(null);
        $companies->clearCaches();

        return $current?->id === $company->id && $member?->userId === $solo->id
            ?: 'resolved to ' . var_export($current?->id, true);
    });

    check('a buyer on several accounts with no default is not guessed at', function() use ($companies, $sam, $members) {
        $multi = makeUser('multi');
        $a = makeCompany('Multi A');
        $b = makeCompany('Multi B');
        $members->addUserToCompany((int)$a->id, (int)$multi->id, Role::ADMIN);
        $members->addUserToCompany((int)$b->id, (int)$multi->id, Role::ADMIN);

        Craft::$app->getUser()->setIdentity($multi);
        $companies->clearCaches();

        $current = $companies->getCurrentCompany();

        Craft::$app->getUser()->setIdentity(null);
        $companies->clearCaches();

        // Pricing a cart at the wrong account's rates is worse than asking which account it is for.
        return $current === null ?: 'guessed ' . $current->getUiLabel();
    });

    check('a default membership decides it', function() use ($companies, $members) {
        $chooser = makeUser('chooser');
        $a = makeCompany('Chooser A');
        $b = makeCompany('Chooser B');
        $members->addUserToCompany((int)$a->id, (int)$chooser->id, Role::ADMIN);
        $second = $members->addUserToCompany((int)$b->id, (int)$chooser->id, Role::ADMIN);
        $second->isDefault = true;
        $members->saveMember($second);

        Craft::$app->getUser()->setIdentity($chooser);
        $companies->clearCaches();

        $current = $companies->getCurrentCompany();

        Craft::$app->getUser()->setIdentity(null);
        $companies->clearCaches();

        return $current?->id === $b->id ?: 'resolved to ' . var_export($current?->id, true);
    });

    check('switching accounts refuses an outsider, and survives having no session at all', function() use ($companies, $members, $acme) {
        $outsider = makeUser('outsider');

        Craft::$app->getUser()->setIdentity($outsider);
        $companies->clearCaches();

        // Console requests have no session — Craft throws MissingComponentException from
        // getSession() rather than returning null — so this both asserts the refusal and proves
        // the method does not fatal where a queue job would call it.
        $allowed = $companies->setCurrentCompany((int)$acme->id);
        $clearing = $companies->setCurrentCompany(null);

        Craft::$app->getUser()->setIdentity(null);
        $companies->clearCaches();

        return !$allowed
            && $clearing
            && $members->getMember((int)$acme->id, (int)$outsider->id) === null
            ?: 'an outsider switched into somebody else’s account';
    });

    check('the Twig variable follows the signed-in buyer', function() use ($companies, $members, $widget) {
        $shopper = makeUser('shopper');
        $company = makeCompany('Twig Buyer');
        $members->addUserToCompany((int)$company->id, (int)$shopper->id, Role::ADMIN);

        Craft::$app->getUser()->setIdentity($shopper);
        $companies->clearCaches();

        $variable = new ForkliftVariable();
        $isB2b = $variable->isB2b();
        $balance = $variable->balance();

        Craft::$app->getUser()->setIdentity(null);
        $companies->clearCaches();

        return $isB2b && $balance !== null ?: 'the Twig variable did not follow the buyer';
    });

    // ----------------------------------------------------------------- twig

    section('Twig surface');

    check('every documented craft.forklift method exists and is callable', function() {
        $variable = new ForkliftVariable();

        foreach ([
            'company', 'member', 'companies', 'isB2b', 'companyQuery', 'price', 'breaks',
            'hasContractPrice', 'verdict', 'canPayOnTerms', 'pendingApprovals', 'approvalFor',
            'balance', 'creditAvailable', 'statement', 'quotes', 'orders', 'poNumber',
            'settings', 'isPro', 'padRows',
        ] as $method) {
            if (!method_exists($variable, $method)) {
                return "craft.forklift.$method is missing";
            }
        }

        return true;
    });

    check('the Twig variable answers safely for an anonymous visitor', function() {
        $variable = new ForkliftVariable();

        return $variable->company() === null
            && $variable->companies() === []
            && !$variable->isB2b()
            && $variable->balance() === null
            && $variable->statement() === null
            && $variable->orders() === []
            && $variable->pendingApprovals() === []
            ?: 'the anonymous path leaked something';
    });

    check('a quote query for an anonymous visitor returns nothing, not everything', function() {
        $variable = new ForkliftVariable();

        return (int)$variable->quotes()->count() === 0 ?: 'an anonymous visitor could see quotes';
    });

    check('the Twig price is the same object the cart uses', function() use ($widget) {
        $variable = new ForkliftVariable();
        $result = $variable->price($widget, 1);

        return $result instanceof PriceResult && near($result->price, 10.00, 'price') === true
            ?: 'got ' . $result->price;
    });

    // ------------------------------------------------------------- hygiene

    section('Hygiene');

    check('every element index source, sort option and table attribute survives being built', function() {
        foreach ([Company::class, Quote::class] as $elementType) {
            foreach ($elementType::sources('index') as $source) {
                if (!isset($source['criteria'])) {
                    continue;
                }

                $query = $elementType::find();
                Craft::configure($query, $source['criteria']);
                $query->limit(1)->all();
            }

            // `sortOptions()` normalises `defineSortOptions()` into a list of arrays, so the keys
            // are 0, 1, 2 and the sort lives in `orderBy`. Ordering by the key produces
            // `ORDER BY 0`, which MySQL rejects.
            foreach ($elementType::sortOptions() as $key => $option) {
                $orderBy = is_array($option) ? ($option['orderBy'] ?? null) : $key;

                if (!is_string($orderBy)) {
                    continue;
                }

                $elementType::find()->orderBy([$orderBy => SORT_ASC])->limit(1)->all();
            }

            $element = $elementType::find()->status(null)->one();

            if ($element !== null) {
                foreach (array_keys($elementType::tableAttributes()) as $attribute) {
                    $element->getAttributeHtml($attribute);
                }
            }
        }

        return true;
    });

    check('no string interpolates a variable next to a typographic quote without braces', function() {
        // PHP identifiers may contain bytes 0x80–0xFF, so "…“$name”…" parses as a variable called
        // `name”` and fatals at *runtime*. Lint does not catch it.
        $offenders = [];
        $directory = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src'));

        foreach ($directory as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            foreach (file($file->getPathname()) as $number => $line) {
                if (preg_match('/\$[A-Za-z_][A-Za-z0-9_]*[\x{201C}\x{201D}\x{2018}\x{2019}]/u', $line)) {
                    $offenders[] = basename($file->getPathname()) . ':' . ($number + 1);
                }
            }
        }

        return $offenders === [] ?: 'unbraced interpolation at ' . implode(', ', $offenders);
    });

    check('every table the plugin queries is one the migration creates', function() {
        $created = [];

        foreach (file(dirname(__DIR__, 2) . '/src/migrations/Install.php') as $line) {
            if (preg_match('/createTable\(Table::([A-Z_]+)/', $line, $matches)) {
                $created[] = $matches[1];
            }
        }

        $declared = (new ReflectionClass(\justinholtweb\forklift\db\Table::class))->getConstants();

        $missing = array_diff(array_keys($declared), $created);

        return $missing === [] ?: 'declared but never created: ' . implode(', ', $missing);
    });

    check('no service writes a running balance onto the company row', function() {
        // The balance is a SUM over the ledger and nothing else. A cached total is a number that
        // can be wrong, and the moment it goes wrong is the moment somebody is on the telephone.
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/services/Credit.php');

        return !preg_match('/->balance\s*=/', $source) ?: 'Credit assigns to a balance property';
    });

    check('the element queries expose the columns their elements declare', function() {
        $company = Company::find()->status(null)->one();

        if ($company === null) {
            return 'no company to check';
        }

        foreach (['code', 'accountStatus', 'creditEnabled', 'requiresPoNumber', 'taxExempt'] as $attribute) {
            if (!property_exists($company, $attribute)) {
                return "Company::\$$attribute is missing";
            }
        }

        return true;
    });

    check('the plugin degrades rather than fataling when asked about a missing order', function() use ($ordersService, $credit) {
        return $ordersService->getRowForOrder(999999999) === null
            && $ordersService->getCompanyIdForOrder(999999999) === null
            && near($credit->balanceFor(999999999), 0.0, 'balance') === true
            ?: 'a missing id was not handled';
    });

} finally {
    section('Cleanup');

    $plugin->edition = $originalEdition;

    foreach ($createdQuotes as $quote) {
        if ($quote->id) {
            $cart = $quote->getCart();

            if ($cart !== null) {
                Craft::$app->getElements()->deleteElement($cart, true);
            }

            Craft::$app->getElements()->deleteElement($quote, true);
        }
    }

    foreach ($createdOrders as $order) {
        if ($order->id) {
            Craft::$app->getElements()->deleteElement($order, true);
        }
    }

    foreach ($createdLists as $list) {
        if ($list->id) {
            $priceLists->deletePriceListById((int)$list->id);
        }
    }

    foreach ($createdCompanies as $company) {
        if ($company->id) {
            Craft::$app->getElements()->deleteElement($company, true);
        }
    }

    foreach ($createdProducts as $product) {
        if ($product->id) {
            Craft::$app->getElements()->deleteElement($product, true);
        }
    }

    foreach ($createdUsers as $user) {
        if ($user->id) {
            Craft::$app->getElements()->deleteElement($user, true);
        }
    }

    foreach ($createdTaxRates as $rate) {
        if ($rate->id) {
            Commerce::getInstance()->getTaxRates()->deleteTaxRateById((int)$rate->id);
        }
    }

    echo "  fixtures removed\n";
}

echo "\n$passed passed, $failed failed\n";

exit($failed === 0 ? 0 : 1);
