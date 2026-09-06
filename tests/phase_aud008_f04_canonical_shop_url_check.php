<?php

/**
 * AUD-008 F04 — CP-compatible validated shop identity (exact Shop.name).
 * Run: php tests/phase_aud008_f04_canonical_shop_url_check.php
 *
 * "Canonical" here means validated CP-compatible configured base identity,
 * not collapsing every equivalent URL spelling.
 *
 * PHP 7.3 compatible. Offline.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/support/phase2_memory_db.php';

$failures = array();
$passes = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucAud008F04_assert($condition, $message)
{
    global $failures, $passes;
    if ($condition) {
        $passes++;
        echo 'PASS  ' . $message . PHP_EOL;

        return;
    }
    $failures[] = $message;
    echo 'FAIL  ' . $message . PHP_EOL;
}

/**
 * @param callable $callback
 * @return Exception|null
 */
function mtucAud008F04_catch(callable $callback)
{
    try {
        $callback();

        return null;
    } catch (Exception $exception) {
        return $exception;
    }
}

/**
 * Mirror of CP ShopModuleEndpointUrl::build for offline callback round-trip checks.
 * (Read-only CP authority — joining trims trailing slash; login identity must not.)
 *
 * @param string $homeUrl
 * @param string $endpointPath
 * @return string
 */
function mtucAud008F04_cpCallbackBuild($homeUrl, $endpointPath)
{
    $base = rtrim(trim($homeUrl), '/');
    $endpoint = ltrim(trim($endpointPath), '/');
    if ($base === '' || $endpoint === '') {
        throw new RuntimeException('missing shop url');
    }
    $url = $base . '/' . $endpoint;
    $parts = parse_url($url);
    if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
        throw new RuntimeException('invalid callback url');
    }
    $scheme = strtolower((string) $parts['scheme']);
    if ($scheme !== 'https' && $scheme !== 'http') {
        throw new RuntimeException('invalid callback scheme');
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        throw new RuntimeException('callback userinfo');
    }
    $host = strtolower((string) $parts['host']);
    $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
    if ($host === '' || (!$isIp && !preg_match('/\A[a-z0-9.-]+\z/i', $host))) {
        throw new RuntimeException('invalid callback host');
    }

    return $url;
}

$root = MTUC_PHASE0_ROOT;
if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
}

require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';
require_once __DIR__ . '/support/phase4_harness.php';

mtucAud008F04_assert(mtuc_phase0_network_isolation_active(), 'isolation: offline guard active');

$provider = new MtUniCreditCanonicalShopUrlProvider();

// ---------------------------------------------------------------------------
// Representation-preserving accepts
// ---------------------------------------------------------------------------
mtucAud008F04_assert(
    MtUniCreditCanonicalShopUrlProvider::normalize('https://shop.example.com') === 'https://shop.example.com',
    'lowercase https root accepted unchanged'
);
mtucAud008F04_assert(
    MtUniCreditCanonicalShopUrlProvider::normalize('https://SHOP.Example.COM/') === 'https://SHOP.Example.COM/',
    'mixed-case host accepted unchanged'
);
mtucAud008F04_assert(
    MtUniCreditCanonicalShopUrlProvider::normalize('https://shop.example.com:443') === 'https://shop.example.com:443',
    ':443 preserved'
);
mtucAud008F04_assert(
    MtUniCreditCanonicalShopUrlProvider::normalize('https://shop.example.com:443/') === 'https://shop.example.com:443/',
    ':443 with trailing slash preserved'
);
mtucAud008F04_assert(
    MtUniCreditCanonicalShopUrlProvider::normalize('https://shop.example.com:8443/store/') === 'https://shop.example.com:8443/store/',
    'non-default port + subdirectory trailing slash preserved'
);
mtucAud008F04_assert(
    MtUniCreditCanonicalShopUrlProvider::normalize('https://shop.example.com/') === 'https://shop.example.com/',
    'root trailing slash preserved'
);
mtucAud008F04_assert(
    MtUniCreditCanonicalShopUrlProvider::normalize('https://shop.example.com/store') === 'https://shop.example.com/store',
    'subdirectory without trailing slash preserved'
);
mtucAud008F04_assert(
    MtUniCreditCanonicalShopUrlProvider::normalize('https://shop.example.com/store/') === 'https://shop.example.com/store/',
    'subdirectory trailing slash preserved'
);
mtucAud008F04_assert(
    MtUniCreditCanonicalShopUrlProvider::normalize('https://shop.example.com/Store-One/') === 'https://shop.example.com/Store-One/',
    'ordinary path spelling preserved'
);
mtucAud008F04_assert(
    MtUniCreditCanonicalShopUrlProvider::normalize('https://127.0.0.1') === 'https://127.0.0.1',
    'IPv4 accepted'
);
mtucAud008F04_assert(
    MtUniCreditCanonicalShopUrlProvider::normalize('https://127.0.0.1:8443/store') === 'https://127.0.0.1:8443/store',
    'IPv4 with port and path accepted'
);
mtucAud008F04_assert(
    MtUniCreditCanonicalShopUrlProvider::normalize('  https://shop.example.com/  ') === 'https://shop.example.com/',
    'outer whitespace trimmed intentionally'
);
mtucAud008F04_assert(
    MtUniCreditCanonicalShopUrlProvider::normalize('') === '',
    'empty semantics preserved'
);
mtucAud008F04_assert(
    MtUniCreditCanonicalShopUrlProvider::normalize(" \t ") === '',
    'whitespace-only treated as empty'
);

// Distinct literal spellings must remain distinct
$a = MtUniCreditCanonicalShopUrlProvider::normalize('https://SHOP.Example.COM/');
$b = MtUniCreditCanonicalShopUrlProvider::normalize('https://shop.example.com');
$c = MtUniCreditCanonicalShopUrlProvider::normalize('https://shop.example.com:443/');
mtucAud008F04_assert($a === 'https://SHOP.Example.COM/', 'exact spelling A preserved');
mtucAud008F04_assert($b === 'https://shop.example.com', 'exact spelling B preserved');
mtucAud008F04_assert($c === 'https://shop.example.com:443/', 'exact spelling C preserved');
mtucAud008F04_assert($a !== $b && $b !== $c && $a !== $c, 'distinct valid spellings stay distinct');

$subA = MtUniCreditCanonicalShopUrlProvider::normalize('https://shop.example.com/store');
$subB = MtUniCreditCanonicalShopUrlProvider::normalize('https://shop.example.com/store/');
mtucAud008F04_assert($subA !== $subB, 'subdirectory slash spellings remain distinct');

// Exact CP identity fixture
$stored = 'https://SHOP.Example.COM:443/store/';
mtucAud008F04_assert(
    MtUniCreditCanonicalShopUrlProvider::normalize($stored) === $stored,
    'exact CP identity fixture preserved byte-for-byte'
);

// Negative demonstration: previous collapsing would have rewritten this value
$previousWouldHaveCollapsed = 'https://shop.example.com/store';
mtucAud008F04_assert(
    $stored !== $previousWouldHaveCollapsed
        && MtUniCreditCanonicalShopUrlProvider::normalize($stored) === $stored,
    'new implementation does not collapse exact CP identity'
);

// ---------------------------------------------------------------------------
// Rejects
// ---------------------------------------------------------------------------
$rejects = array(
    'uppercase HTTPS scheme rejected' => 'HTTPS://shop.example.com',
    'HTTP rejected' => 'http://shop.example.com',
    'userinfo rejected' => 'https://user:pass@shop.example.com/',
    'query rejected' => 'https://shop.example.com/?x=1',
    'fragment rejected' => 'https://shop.example.com/#x',
    'malformed URL rejected' => 'not a url',
    'interior whitespace rejected' => 'https://shop.example.com/path with space',
    'IPv6 rejected' => 'https://[2001:db8::1]',
    'IPv6 with path rejected' => 'https://[2001:db8::1]/store',
    'IPv6 loopback rejected' => 'https://[::1]',
    'IDN rejected' => 'https://пример.bg/',
    'dot segments rejected' => 'https://shop.example.com/store/./x',
    'path traversal rejected' => 'https://shop.example.com/store/../x',
    'duplicate slash segments rejected' => 'https://shop.example.com/store//x',
    'leading double-slash path rejected' => 'https://shop.example.com//store',
    'backslash rejected' => 'https://shop.example.com/store\\foo',
    'encoded traversal rejected' => 'https://shop.example.com/store/%2e%2e/x',
    'encoded separator rejected' => 'https://shop.example.com/store%2Fmore',
);

foreach ($rejects as $label => $url) {
    $exception = mtucAud008F04_catch(function () use ($url) {
        MtUniCreditCanonicalShopUrlProvider::normalize($url);
    });
    mtucAud008F04_assert($exception instanceof InvalidArgumentException, $label);
    if ($label === 'userinfo rejected' && $exception instanceof Exception) {
        $msg = $exception->getMessage();
        mtucAud008F04_assert(
            stripos($msg, 'pass') === false
                && stripos($msg, 'secret') === false
                && stripos($msg, 'bearer') === false,
            'userinfo rejection has no credential material'
        );
    }
}

// config_ssl / config_url preference
mtucAud008F04_assert(
    $provider->resolve('https://shop.example.com/', 'http://other.example.com/') === 'https://shop.example.com/',
    'resolve prefers non-empty config_ssl exactly'
);
$httpOnly = mtucAud008F04_catch(function () use ($provider) {
    $provider->resolve('', 'http://shop.example.com');
});
mtucAud008F04_assert($httpOnly instanceof InvalidArgumentException, 'http-only config_url fails closed');

$malformedSsl = mtucAud008F04_catch(function () use ($provider) {
    $provider->resolve('not-a-url', 'https://shop.example.com/');
});
mtucAud008F04_assert($malformedSsl instanceof InvalidArgumentException, 'malformed config_ssl does not fall back');

// ---------------------------------------------------------------------------
// Login integration — exact identity through client (no rtrim)
// ---------------------------------------------------------------------------
$exactIdentity = 'https://SHOP.Example.COM:443/store/';
$memoryDb = Phase4TestHarness::memoryDb();
$db = new MtUniCreditDbAdapter($memoryDb, 'oc_');
$settings = new MtUniCreditSettingStore($db, MtUniCreditConstants::MODULE_SETTINGS_CODE);
Phase4TestHarness::prepareCredentials($settings, Phase4TestHarness::TEST_STORE_ID);
$credentials = new MtUniCreditCredentialsRepository($settings, Phase4TestHarness::cipher());
$tokens = new MtUniCreditCpTokenRepository($settings, Phase4TestHarness::cipher(), Phase4TestHarness::TEST_STORE_ID);
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, array_merge(Phase4TestHarness::loginSuccessPayload(), array(
    'shop' => array(
        'id' => 1,
        'name' => $exactIdentity,
        'unicid' => Phase4TestHarness::TEST_UNICID,
    ),
)));
$client = new MtUniCreditControlPanelClient(
    $credentials,
    $tokens,
    $transport,
    $exactIdentity,
    Phase4TestHarness::TEST_STORE_ID,
    'https://cp-test.example.com/api/v1',
    null,
    Phase4TestHarness::offlineDestinationPolicy()
);
$client->login();
$loginName = $transport->requests[0]['payload']['name'];
mtucAud008F04_assert($loginName === $exactIdentity, 'login receives exact validated identity');
mtucAud008F04_assert(
    $loginName === MtUniCreditCanonicalShopUrlProvider::normalize($exactIdentity),
    'login name matches provider result with no later rewrite'
);

// Factory / harness path
$memoryDb = Phase4TestHarness::memoryDb();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$stack = Phase4TestHarness::services($transport, $memoryDb);
$stack['client']->login();
mtucAud008F04_assert(
    $transport->requests[0]['payload']['name'] === Phase4TestHarness::TEST_SHOP_URL,
    'harness login uses exact TEST_SHOP_URL identity'
);

// ---------------------------------------------------------------------------
// CP callback round-trip (builder may trim trailing slash for joining)
// ---------------------------------------------------------------------------
$callbackBases = array(
    'https://shop.example.com',
    'https://shop.example.com/',
    'https://SHOP.Example.COM/',
    'https://shop.example.com:443/',
    'https://shop.example.com:8443/store/',
    'https://127.0.0.1/store/',
);
foreach ($callbackBases as $base) {
    $validated = MtUniCreditCanonicalShopUrlProvider::normalize($base);
    mtucAud008F04_assert($validated === $base, 'callback base identity preserved: ' . $base);
    $callbackUrl = mtucAud008F04_cpCallbackBuild($base, 'index.php?route=extension/mt_uni_credit/module');
    mtucAud008F04_assert(
        is_string($callbackUrl) && strpos($callbackUrl, 'index.php') !== false,
        'callback construction works: ' . $base
    );
}

$ipv6LoginBlocked = mtucAud008F04_catch(function () {
    MtUniCreditCanonicalShopUrlProvider::normalize('https://[2001:db8::1]/store');
});
mtucAud008F04_assert($ipv6LoginBlocked instanceof InvalidArgumentException, 'IPv6 rejected before callback/login lifecycle');

echo PHP_EOL;
if ($failures) {
    echo 'AUD-008 F04 CP-COMPATIBLE SHOP IDENTITY: FAIL (' . count($failures) . ' failed, ' . $passes . ' passed)' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'AUD-008 F04 CP-COMPATIBLE SHOP IDENTITY: PASS (' . $passes . ' assertions)' . PHP_EOL;
exit(0);
