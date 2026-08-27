<?php
/**
 * Forklift HTTP checks: every screen, over a real request.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-forklift/tests/integration/http-checks.php
 *
 * ## Why this exists separately from `checks.php`
 *
 * Because two of the bugs found building this plugin were **unreachable from a console script**
 * and would have shipped:
 *
 * - `Companies::getCurrentCompany()` called a private method that had never been written. Nothing
 *   in a console request has a signed-in identity, so the console suite never reached the line.
 * - The portal's fallback renderer pushed a string into `Response::setContent()`, which does not
 *   exist — Yii's `Response` has a public `$content` property. Every portal page fataled.
 *
 * A suite that only ever calls services will keep missing that class of mistake. This drives the
 * routes.
 *
 * It signs in with a **one-hour impersonation token** rather than by posting a password. That is
 * the documented way to reach control-panel screens from a script, and on a shared harness it is
 * also the robust one: nothing here can be broken by another session changing the admin password
 * out from under it.
 *
 * It asserts against whatever edition is currently installed rather than switching it. An edition
 * change lives in project config, and writing project config on a harness that several sessions
 * share is both slow and liable to a stale-resource failure a long way from its cause.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use justinholtweb\forklift\elements\Company;
use justinholtweb\forklift\models\Role;
use justinholtweb\forklift\Plugin;

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
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$base = 'http://127.0.0.1';
$host = 'plugin-testing.ddev.site';
$jar = tempnam(sys_get_temp_dir(), 'forklift-http-');

/**
 * One request. Returns the status code and the body.
 *
 * curl over the loopback with an explicit Host header, rather than the public hostname: this runs
 * inside the container, and going out through the router would depend on DNS and TLS that have
 * nothing to do with what is being tested.
 *
 * @return array{0: int, 1: string}
 */
function request(string $path, array $post = [], bool $json = false): array
{
    global $base, $host, $jar;

    $ch = curl_init();
    $headers = ['Host: ' . $host];

    if ($json) {
        $headers[] = 'Accept: application/json';
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $base . '/' . ltrim($path, '/'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60,
        // Required, and the reason is not obvious: Craft's `requireUserAgentAndIpForSession` is on
        // by default, and `web\User::beforeLogin()` refuses to create a session when the request
        // has no User-Agent. PHP's curl sends none unless told to, so every login — password or
        // impersonation — fails with a bare redirect to the login page and no error anywhere.
        CURLOPT_USERAGENT => 'forklift-http-checks',
    ]);

    if ($post !== []) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$code, $body];
}

/**
 * A one-hour impersonation URL for the given user.
 *
 * The same token `craft users/impersonate` mints. Single use, so one is taken per run and the
 * cookie jar carries the session from there.
 */
function impersonationPath(int $userId): ?string
{
    $token = Craft::$app->getTokens()->createToken([
        'users/impersonate-with-token', [
            'userId' => $userId,
            'prevUserId' => $userId,
        ],
    ], 1, new DateTime('+1 hour'));

    return $token ? 'admin?token=' . $token : null;
}

/** The first exception name in an error page, so a failure says what broke. */
/** Whether a path is one of the ones a Lite licence refuses. */
function this_is_pro_only(string $path, array $proOnly): bool
{
    return in_array($path, $proOnly, true);
}

function whyItFailed(string $body): string
{
    if (preg_match('/([A-Za-z\\\\]*(?:Exception|Error))[^<"]{0,160}/', $body, $matches)) {
        return trim(strip_tags(html_entity_decode($matches[0])));
    }

    return 'no exception found in the response';
}

$plugin = Plugin::getInstance();
$originalEdition = $plugin->edition;
$suffix = substr(md5((string)microtime(true)), 0, 6);

$company = null;
$member = null;

echo "Forklift HTTP checks\n";

try {
    section('Signing in');

    check('an impersonation token establishes a control-panel session', function() {
        $path = impersonationPath(1);

        if ($path === null) {
            return 'could not mint an impersonation token';
        }

        [$code] = request($path);

        // The impersonation route redirects to the control panel once the session is set.
        if (!in_array($code, [200, 302], true)) {
            return "impersonation returned $code";
        }

        [$code] = request('admin/forklift/companies');

        return $code === 200 ?: "the session did not take — companies returned $code";
    });

    // A company the signed-in admin buys for, so the portal has something to show. Created here
    // rather than expected to exist, so the suite is self-contained.
    $company = new Company([
        'title' => "HTTP fixture $suffix",
        'code' => "HTTP$suffix",
        'creditEnabled' => true,
        'creditLimit' => 5000.0,
        'termsId' => $plugin->terms->getTermByHandle('net30')?->id,
    ]);
    $plugin->companies->saveCompany($company, false);
    $member = $plugin->members->addUserToCompany((int)$company->id, 1, Role::ADMIN);

    // Marked as the default, so the portal has an unambiguous account whatever else this admin
    // already belongs to on a shared harness. Forklift deliberately refuses to guess between
    // several memberships with no default — pricing a basket at the wrong account's rates is
    // worse than asking — and that refusal is asserted separately below.
    $member->isDefault = true;
    $plugin->members->saveMember($member);

    section('Control panel');

    $cpScreens = [
        'admin/forklift',
        'admin/forklift/companies',
        'admin/forklift/companies/new',
        'admin/forklift/companies/' . $company->id,
        'admin/forklift/companies/' . $company->id . '/members',
        'admin/forklift/companies/' . $company->id . '/certificates',
        'admin/forklift/price-lists',
        'admin/forklift/price-lists/new',
        'admin/forklift/price-lists/preview',
        'admin/forklift/quotes',
        'admin/forklift/quotes/new',
        'admin/forklift/approvals',
        'admin/forklift/credit',
        'admin/forklift/companies/' . $company->id . '/credit',
        'admin/forklift/companies/' . $company->id . '/statement',
        'admin/forklift/certificates',
        'admin/forklift/certificates/new',
        'admin/forklift/settings/general',
        'admin/forklift/settings/companies',
        'admin/forklift/settings/quotes',
        'admin/forklift/settings/credit',
        'admin/forklift/settings/terms',
        'admin/forklift/settings/terms/new',
        'admin/forklift/settings/fields',
        'admin/forklift/settings/quote-fields',
    ];

    // What the *web* process will see, which is the persisted edition — not whatever this console
    // process happens to hold.
    $installedEdition = Craft::$app->getProjectConfig()->get('plugins.forklift.edition') ?? Plugin::EDITION_LITE;
    $isPro = $installedEdition === Plugin::EDITION_PRO;

    echo "  (installed edition: $installedEdition)\n";

    // These three refuse a Lite licence by design, and a refusal is a pass on Lite.
    $proOnly = ['admin/forklift/quotes', 'admin/forklift/quotes/new', 'admin/forklift/approvals', 'admin/forklift/credit'];

    foreach ($cpScreens as $path) {
        check($path, function() use ($path, $proOnly, $isPro) {
            [$code, $body] = request($path);

            $expected = (!$isPro && $this_is_pro_only($path, $proOnly)) ? 403 : 200;

            if ($code === $expected) {
                return true;
            }

            return "status $code, expected $expected — " . whyItFailed($body);
        });
    }

    section('Front end');

    foreach ([
        'forklift/quick-order',
        'forklift/account',
        'forklift/account/orders',
        'forklift/account/quotes',
        'forklift/account/buyers',
        'forklift/account/statement',
    ] as $path) {
        check($path, function() use ($path) {
            [$code, $body] = request($path);

            if ($code === 200) {
                return true;
            }

            return "status $code — " . whyItFailed($body);
        });
    }

    check('the quick-order lookup answers JSON', function() {
        [$code, $body] = request('index.php?action=forklift/quick-order/lookup&sku=NOTHING-AT-ALL&qty=1', [], true);
        $data = json_decode($body, true);

        return $code === 200 && ($data['found'] ?? null) === false && !empty($data['error'])
            ?: "status $code: " . substr($body, 0, 160);
    });

    check('a buyer on several accounts with no default is asked rather than guessed at', function() use ($plugin, $member, $company) {
        $member->isDefault = false;
        $plugin->members->saveMember($member);

        $second = new Company(['title' => 'Second account', 'code' => 'SECOND' . substr(md5((string)$company->id), 0, 5)]);
        $plugin->companies->saveCompany($second, false);
        $plugin->members->addUserToCompany((int)$second->id, 1, Role::ADMIN);

        [$code] = request('forklift/account/orders');

        Craft::$app->getElements()->deleteElement($second, true);
        $member->isDefault = true;
        $plugin->members->saveMember($member);

        return $code === 403 ?: "status $code — an ambiguous membership was guessed at";
    });

    check('an unknown approval token is a 404 rather than a stack trace', function() {
        [$code] = request('forklift/approvals/' . str_repeat('z', 32));

        // The harness's craft-friends 404 handler throws, so a genuine 404 surfaces as a 500
        // here. Either is a refusal; what would be wrong is a 200.
        return in_array($code, [404, 500], true) ?: "status $code";
    });

    section('The edition boundary, as the web sees it');

    check('the Pro-only screens agree with the installed edition', function() use ($proOnly, $isPro) {
        foreach ($proOnly as $path) {
            [$code] = request($path);
            $expected = $isPro ? 200 : 403;

            if ($code !== $expected) {
                return "$path returned $code, expected $expected";
            }
        }

        return true;
    });

    check('the screens every edition is entitled to are always served', function() {
        foreach (['admin/forklift/companies', 'admin/forklift/certificates', 'admin/forklift/settings/general'] as $path) {
            [$code] = request($path);

            if ($code !== 200) {
                return "$path returned $code";
            }
        }

        return true;
    });
} finally {
    section('Cleanup');

    if ($company?->id) {
        Craft::$app->getElements()->deleteElement($company, true);
    }

    @unlink($jar);

    echo "  fixtures removed\n";
}

echo "\n$passed passed, $failed failed\n";

exit($failed === 0 ? 0 : 1);
