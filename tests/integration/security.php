<?php
/**
 * What an anonymous visitor can read through the quick-order pad, whether a buyer can switch
 * company, and the events integrators hook — checked in the plugin-testing harness, mostly over HTTP.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-forklift/tests/integration/security.php
 *
 * Until 5.1.0 the anonymous SKU lookup, autocomplete and pad read `commerce_purchasables` directly,
 * so any visitor could enumerate disabled, unreleased and trashed SKUs with their descriptions and
 * prices — and `q=%%` matched every SKU in the store. The front-end company switcher sat behind a
 * CP permission, so buyers got a 403. And a plugin that moves money had no events.
 *
 * Self-cleaning: products, companies and users are removed. The edition is switched in memory only.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\forklift\elements\Company;
use justinholtweb\forklift\elements\Quote;
use justinholtweb\forklift\events\ApprovalEvent;
use justinholtweb\forklift\events\CreditEntryEvent;
use justinholtweb\forklift\events\DefinePriceEvent;
use justinholtweb\forklift\events\QuoteEvent;
use justinholtweb\forklift\models\Approval;
use justinholtweb\forklift\models\CreditEntry;
use justinholtweb\forklift\models\Role;
use justinholtweb\forklift\Plugin;
use justinholtweb\forklift\services\Approvals;
use justinholtweb\forklift\services\Credit;
use justinholtweb\forklift\services\Pricing;
use justinholtweb\forklift\services\Quotes;
use yii\base\Event;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

Craft::$app->getPlugins()->loadPlugins();

// craft-penny's broken beforeSaveElement handler (see craft-bird's checks.php) — detached in-process only.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
}

$plugin = Plugin::getInstance();
$run = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
$prefix = "FKSEC$run";
$password = 'Forklift-' . bin2hex(random_bytes(6));
$cleanup = ['products' => [], 'companies' => [], 'users' => []];

register_shutdown_function(function() use (&$cleanup) {
    foreach ([...$cleanup['products'], ...$cleanup['companies'], ...$cleanup['users']] as $element) {
        Craft::$app->getElements()->deleteElement($element, true);
    }
});

$type = Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0];

/** A product with one variant whose SKU, description and price are unique to this run. */
$product = static function(string $label, array $productAttributes = [], array $variantAttributes = []) use ($type, $prefix, &$cleanup): Product {
    $product = new Product();
    $product->typeId = $type->id;
    $product->title = "Forklift security $label $prefix";
    $product->enabled = true;
    Craft::configure($product, $productAttributes);

    $variant = new Variant();
    $variant->sku = "$prefix-$label";
    $variant->basePrice = 7000 + strlen($label);
    $variant->isDefault = true;
    $variant->inventoryTracked = false;
    Craft::configure($variant, $variantAttributes);

    $product->setVariants([$variant]);
    Craft::$app->getElements()->saveElement($product) or throw new RuntimeException(json_encode($product->getErrors()));
    $cleanup['products'][] = $product;

    return $product;
};

$product('LIVE');
$hidden = [
    'disabled product' => 'OFF',
    'disabled variant' => 'VOFF',
    'scheduled product' => 'SOON',
    'expired product' => 'GONE',
    'trashed product' => 'TRASH',
];
$product('OFF', ['enabled' => false]);
$product('VOFF', [], ['enabled' => false]);
$product('SOON', ['postDate' => new DateTime('+30 days')]);
$product('GONE', ['postDate' => new DateTime('-30 days'), 'expiryDate' => new DateTime('-1 day')]);
Craft::$app->getElements()->deleteElement($product('TRASH'));
$product('NA', [], ['availableForPurchase' => false]);

$anon = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
$json = ['Accept' => 'application/json'];
$get = static fn(string $action, array $query) => json_decode((string)$anon->get("index.php?p=actions/forklift/quick-order/$action&" . http_build_query($query), ['headers' => $json])->getBody(), true);

echo "\nThe SKU lookup, as nobody\n";

check('a live SKU is found, with its price', function() use ($get, $prefix) {
    $data = $get('lookup', ['sku' => "$prefix-LIVE"]);

    return ($data['found'] ?? false) === true && (float)($data['price'] ?? 0) > 0 ?: json_encode($data);
});

foreach ($hidden as $what => $label) {
    check("the $what is not found — no description, no price", function() use ($get, $prefix, $label) {
        $data = $get('lookup', ['sku' => "$prefix-$label"]);
        $body = json_encode($data);

        // “Not found”, word for word: “not available” would confirm the SKU exists.
        return ($data['found'] ?? true) === false && !str_contains($body, 'Forklift security') && !array_key_exists('price', $data ?? [])
            && str_contains((string)($data['error'] ?? ''), 'was not found')
            ?: $body;
    });
}

check('one that isn’t for sale says so, without a price', function() use ($get, $prefix) {
    $data = $get('lookup', ['sku' => "$prefix-NA"]);

    return ($data['found'] ?? true) === false && !array_key_exists('price', $data ?? []) && str_contains((string)($data['error'] ?? ''), 'not available') ?: json_encode($data);
});

echo "\nThe autocomplete, as nobody\n";

check('suggests only what can be bought', function() use ($get, $prefix) {
    $skus = array_column($get('suggest', ['q' => $prefix])['results'] ?? [], 'sku');

    return $skus === ["$prefix-LIVE"] ?: json_encode($skus);
});

check('`%` and `_` are literal, not wildcards', function() use ($get, $prefix) {
    $everything = $get('suggest', ['q' => '%%'])['results'] ?? [];
    $underscore = array_column($get('suggest', ['q' => 'FKSEC______-LIVE'])['results'] ?? [], 'sku');

    return $everything === [] && $underscore === [] ?: json_encode(['%%' => count($everything), '_' => $underscore]);
});

echo "\nThe pad, as nobody\n";

check('a hidden SKU typed into the pad is “not found”, priced at nothing', function() use ($anon, $json, $prefix) {
    $session = json_decode((string)$anon->get('index.php?p=actions/users/session-info', ['headers' => $json])->getBody(), true);
    $response = $anon->post('index.php?p=actions/forklift/quick-order/preview', [
        'headers' => $json,
        'form_params' => ['rows' => [['sku' => "$prefix-OFF", 'qty' => 1], ['sku' => "$prefix-LIVE", 'qty' => 1]], 'CRAFT_CSRF_TOKEN' => $session['csrfTokenValue'] ?? ''],
    ]);
    $rows = json_decode((string)$response->getBody(), true)['rows'] ?? [];

    return count($rows) === 2 && $rows[0]['price'] === null && $rows[0]['purchasableId'] === null && $rows[0]['description'] === null
        && str_contains((string)$rows[0]['error'], 'was not found') && $rows[1]['valid'] === true
        ?: 'status ' . $response->getStatusCode() . ' ' . json_encode($rows);
});

echo "\nSwitching company, as a buyer\n";

$buyer = new User(['username' => "forklift-buyer-$run", 'email' => "forklift-buyer-$run@example.com", 'newPassword' => $password]);
Craft::$app->getElements()->saveElement($buyer, false);
Craft::$app->getUsers()->activateUser($buyer);
// Control-panel access but no Forklift permissions, so the switcher's exemption is tested alone.
Craft::$app->getUserPermissions()->saveUserPermissions($buyer->id, ['accesscp', 'accessplugin-forklift']);
$cleanup['users'][] = $buyer;

$companies = [];
foreach (['A', 'B', 'C'] as $letter) {
    $c = new Company(['title' => "Forklift security $letter $run", 'code' => "FKS$letter$run"]);
    $plugin->companies->saveCompany($c, false) or throw new RuntimeException('company');
    $cleanup['companies'][] = $c;
    $companies[$letter] = $c;
}
$plugin->members->addUserToCompany((int)$companies['A']->id, (int)$buyer->id, Role::BUYER);
$plugin->members->addUserToCompany((int)$companies['B']->id, (int)$buyer->id, Role::BUYER);

$buyerHttp = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
$csrf = static fn() => (string)(json_decode((string)$buyerHttp->get('index.php?p=actions/users/session-info', ['headers' => $json])->getBody(), true)['csrfTokenValue'] ?? '');
$login = $buyerHttp->post('index.php?p=actions/users/login', ['headers' => $json, 'form_params' => ['loginName' => $buyer->username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()]]);
$switch = static fn(int $companyId) => $buyerHttp->post('index.php?p=actions/forklift/companies/switch', ['headers' => $json, 'form_params' => ['companyId' => $companyId, 'CRAFT_CSRF_TOKEN' => $csrf()]])->getStatusCode();

check('a buyer can switch to a company they belong to, with no control-panel access', function() use ($login, $switch, $companies) {
    if ($login->getStatusCode() !== 200) {
        return 'could not sign in: ' . $login->getStatusCode();
    }
    $status = $switch((int)$companies['B']->id);

    return $status === 200 ?: "status $status";
});

check('…but not to one they don’t', function() use ($switch, $companies) {
    $status = $switch((int)$companies['C']->id);

    return $status === 403 ?: "status $status";
});

check('…and the rest of the companies controller is still the control panel’s', function() use ($buyerHttp) {
    $status = $buyerHttp->get('index.php?p=admin/forklift/companies')->getStatusCode();

    return $status === 403 ?: "the companies screen answered $status";
});

check('signed out, switching is refused', function() use ($anon, $json, $companies) {
    $session = json_decode((string)$anon->get('index.php?p=actions/users/session-info', ['headers' => $json])->getBody(), true);
    $status = $anon->post('index.php?p=actions/forklift/companies/switch', [
        'headers' => $json,
        'form_params' => ['companyId' => (int)$companies['A']->id, 'CRAFT_CSRF_TOKEN' => $session['csrfTokenValue'] ?? ''],
    ])->getStatusCode();

    return in_array($status, [302, 403], true) ?: "status $status";
});

echo "\nEvents\n";

// Pro in memory only, never persisted — project config is contended on this harness (CLAUDE.md).
$plugin->edition = Plugin::EDITION_PRO;

$live = Variant::find()->sku("$prefix-LIVE")->one();

check('EVENT_DEFINE_PRICE changes the price everywhere Forklift prices', function() use ($plugin, $live) {
    // Replaces the result rather than editing it, so the resolver must use what the event holds.
    $handler = static function(DefinePriceEvent $e) {
        $e->result = new justinholtweb\forklift\models\PriceResult(['price' => 1.23, 'qty' => $e->qty, 'purchasableId' => $e->purchasable?->getId()]);
    };
    Event::on(Pricing::class, Pricing::EVENT_DEFINE_PRICE, $handler);
    try {
        $price = $plugin->pricing->resolve($live, 3)->price;
        $pad = $plugin->quickOrder->resolve([['sku' => $live->sku, 'qty' => 3]])->rows[0]->price?->price;
    } finally {
        Event::off(Pricing::class, Pricing::EVENT_DEFINE_PRICE, $handler);
    }

    return $price === 1.23 && $pad === 1.23 && $plugin->pricing->resolve($live, 3)->price !== 1.23 ?: json_encode([$price, $pad]);
});

check('EVENT_BEFORE_SAVE_ENTRY can stop a credit entry, and AFTER reports the new one', function() use ($plugin, $companies) {
    $after = [];
    $stop = static fn(CreditEntryEvent $e) => $e->isValid = false;
    $record = static function(CreditEntryEvent $e) use (&$after) {
        $after[] = [$e->entry->id, $e->isNew];
    };

    Event::on(Credit::class, Credit::EVENT_BEFORE_SAVE_ENTRY, $stop);
    $refused = $plugin->credit->saveEntry(new CreditEntry(['companyId' => $companies['A']->id, 'type' => CreditEntry::TYPE_ADJUSTMENT, 'amount' => 10.0]));
    Event::off(Credit::class, Credit::EVENT_BEFORE_SAVE_ENTRY, $stop);

    Event::on(Credit::class, Credit::EVENT_AFTER_SAVE_ENTRY, $record);
    $entry = new CreditEntry(['companyId' => $companies['A']->id, 'type' => CreditEntry::TYPE_ADJUSTMENT, 'amount' => 10.0]);
    $saved = $plugin->credit->saveEntry($entry);
    Event::off(Credit::class, Credit::EVENT_AFTER_SAVE_ENTRY, $record);

    $stopDelete = static fn(CreditEntryEvent $e) => $e->isValid = false;
    Event::on(Credit::class, Credit::EVENT_BEFORE_DELETE_ENTRY, $stopDelete);
    $kept = !$plugin->credit->deleteEntryById((int)$entry->id) && $plugin->credit->getEntryById((int)$entry->id) !== null;
    Event::off(Credit::class, Credit::EVENT_BEFORE_DELETE_ENTRY, $stopDelete);
    $plugin->credit->deleteEntryById((int)$entry->id);

    return !$refused && $saved && $after === [[$entry->id, true]] && $kept ?: json_encode(compact('refused', 'saved', 'after', 'kept'));
});

check('EVENT_BEFORE_DECIDE can keep an approval pending', function() use ($plugin) {
    $approval = new Approval(['status' => Approval::STATUS_PENDING, 'orderId' => 0, 'companyId' => 0]);
    $seen = null;
    $stop = static function(ApprovalEvent $e) use (&$seen) {
        $seen = $e->status;
        $e->isValid = false;
    };
    Event::on(Approvals::class, Approvals::EVENT_BEFORE_DECIDE, $stop);
    try {
        $result = $plugin->approvals->approve($approval);
    } finally {
        Event::off(Approvals::class, Approvals::EVENT_BEFORE_DECIDE, $stop);
    }

    return $result === false && $approval->status === Approval::STATUS_PENDING && $seen === Approval::STATUS_APPROVED ?: json_encode([$result, $approval->status, $seen]);
});

check('EVENT_BEFORE_DECLINE can keep a quote open', function() use ($plugin) {
    $quote = new Quote(['quoteStatus' => Quote::STATUS_SENT]);
    $stop = static fn(QuoteEvent $e) => $e->isValid = false;
    Event::on(Quotes::class, Quotes::EVENT_BEFORE_DECLINE, $stop);
    try {
        $result = $plugin->quotes->markDeclined($quote, 'no thanks');
    } finally {
        Event::off(Quotes::class, Quotes::EVENT_BEFORE_DECLINE, $stop);
    }

    return $result === false && $quote->quoteStatus === Quote::STATUS_SENT ?: json_encode([$result, $quote->quoteStatus]);
});

check('EVENT_BEFORE_SEND can stop a quote going out', function() use ($plugin) {
    $quote = new Quote(['quoteStatus' => Quote::STATUS_REQUESTED]);
    $stop = static fn(QuoteEvent $e) => $e->isValid = false;
    Event::on(Quotes::class, Quotes::EVENT_BEFORE_SEND, $stop);
    try {
        $result = $plugin->quotes->send($quote, null, false);
    } finally {
        Event::off(Quotes::class, Quotes::EVENT_BEFORE_SEND, $stop);
    }

    return $result === false && $quote->quoteStatus === Quote::STATUS_REQUESTED ?: json_encode([$result, $quote->quoteStatus]);
});

echo "\nThe checkout gate\n";

$cart = new craft\commerce\elements\Order();
$cart->number = Commerce::getInstance()->getCarts()->generateCartNumber();
$cart->origin = craft\commerce\elements\Order::ORIGIN_CP;
$cart->setCustomer($buyer);
Craft::$app->getElements()->saveElement($cart, false) or throw new RuntimeException('cart');
$cleanup['products'][] = $cart;
$plugin->orders->setCompanyForOrder($cart, (int)$companies['A']->id);
$plugin->companies->setAccountStatus($companies['A'], Company::STATUS_HOLD);
$plugin->orders->clearCaches();
$plugin->checkout->clearCaches();

check('a refused order is stopped before any payment is taken', function() use ($cart) {
    $event = new craft\commerce\events\ProcessPaymentEvent(['order' => $cart]);
    Commerce::getInstance()->getPayments()->trigger(craft\commerce\services\Payments::EVENT_BEFORE_PROCESS_PAYMENT, $event);

    return $event->isValid === false && $cart->getNotices() !== [] ?: 'payment allowed';
});

check('…and completing it without payment is refused with the reason, not a PHP error', function() use ($cart) {
    try {
        $cart->markAsComplete();
    } catch (yii\base\UserException $e) {
        $fresh = craft\commerce\elements\Order::find()->id($cart->id)->one();

        return !$fresh?->isCompleted && str_contains($e->getMessage(), 'hold') ?: 'completed, or message: ' . $e->getMessage();
    } catch (Throwable $e) {
        return get_class($e) . ': ' . $e->getMessage();
    }

    return 'it completed';
});

check('an order that may go ahead is not stopped', function() use ($cart, $plugin, $companies) {
    $plugin->companies->setAccountStatus($companies['A'], Company::STATUS_ACTIVE);
    $plugin->orders->clearCaches();
    $plugin->checkout->clearCaches();
    $event = new craft\commerce\events\ProcessPaymentEvent(['order' => $cart]);
    Commerce::getInstance()->getPayments()->trigger(craft\commerce\services\Payments::EVENT_BEFORE_PROCESS_PAYMENT, $event);

    return $event->isValid === true ?: 'stopped: ' . json_encode($plugin->checkout->verdict($cart)->reasons);
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
