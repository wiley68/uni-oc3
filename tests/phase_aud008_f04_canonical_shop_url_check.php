<?php

/**
 * AUD-008 F04 — Canonical shop URL identity for CP login `name`.
 * Run: php tests/phase_aud008_f04_canonical_shop_url_check.php
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
// Accept / canonicalize
// ---------------------------------------------------------------------------
mtucAud008F04_assert(
    MtUniCreditCanonicalShopUrlProvider::normalize('https://shop.example.com/') === 'https://shop.example.com',
    'valid https root'
);
mtucAud008F04_assert(
    MtUniCreditCanonicalShopUrlProvider::normalize('HTTPS://SHOP.EXAMPLE.COM/') === 'https://shop.example.com',
    'mixed-case scheme + host'
);
mtucAud008F04_assert(
    MtUniCreditCanonicalShopUrlProvider::normalize('https://shop.example.com:443/') === 'https://shop.example.com',
    'default 443 removal'
);
mtucAud008F04_assert(
    MtUniCreditCanonicalShopUrlProvider::normalize('http://shop.example.com:80/') === 'http://shop.example.com',
    'default 80 removal (http preserved, no upgrade)'
);
mtucAud008F04_assert(
    MtUniCreditCanonicalShopUrlProvider::normalize('https://shop.example.com:8443/') === 'https://shop.example.com:8443',
    'non-default port retained'
);
mtucAud008F04_assert(
    MtUniCreditCanonicalShopUrlProvider::normalize('  https://shop.example.com/store  ') === 'https://shop.example.com/store',
    'leading/trailing whitespace trimmed then path canonicalized'
);
mtucAud008F04_assert(
    MtUniCreditCanonicalShopUrlProvider::normalize('  https://shop.example.com/  ') === 'https://shop.example.com',
    'outer whitespace trim + trailing slash removal'
);
mtucAud008F04_assert(
    MtUniCreditCanonicalShopUrlProvider::normalize('https://shop.example.com/store/') === 'https://shop.example.com/store',
    'subdirectory path allowed (OC install path / CP callback base)'
);
mtucAud008F04_assert(
    MtUniCreditCanonicalShopUrlProvider::normalize('') === '',
    'empty URL returns empty (not configured)'
);

$sameOrigin = array(
    'HTTPS://SHOP.EXAMPLE.COM/',
    'https://shop.example.com',
    'https://shop.example.com:443/',
    'https://Shop.Example.Com',
);
$canonicalIds = array();
foreach ($sameOrigin as $variant) {
    $canonicalIds[] = MtUniCreditCanonicalShopUrlProvider::normalize($variant);
}
mtucAud008F04_assert(
    count(array_unique($canonicalIds)) === 1 && $canonicalIds[0] === 'https://shop.example.com',
    'same-origin variants produce identical canonical identity'
);

// Existing fixture identity (phase4 / aud003)
mtucAud008F04_assert(
    $provider->resolve('https://shop.example/', 'http://shop.example/') === 'https://shop.example',
    'existing fixture: prefer config_ssl → https://shop.example'
);
mtucAud008F04_assert(
    $provider->resolve(Phase4TestHarness::TEST_SHOP_URL, Phase4TestHarness::TEST_SHOP_URL) === Phase4TestHarness::TEST_SHOP_URL,
    'phase4 TEST_SHOP_URL remains stable'
);

// ---------------------------------------------------------------------------
// Reject
// ---------------------------------------------------------------------------
$reject = array(
    'query rejection' => 'https://shop.example.com/?a=1',
    'fragment rejection' => 'https://shop.example.com/#x',
    'userinfo rejection' => 'https://user:pass@shop.example.com/',
    'malformed URL rejection' => 'not a url',
    'interior whitespace rejection' => 'https://shop.example.com/path with space',
    'path traversal rejection' => 'https://shop.example.com/store/../other',
    'dot segment rejection' => 'https://shop.example.com/./store',
    'double-slash path rejection' => 'https://shop.example.com//store',
    'invalid port rejection' => 'https://shop.example.com:99999/',
);

foreach ($reject as $label => $url) {
    $exception = mtucAud008F04_catch(function () use ($url) {
        MtUniCreditCanonicalShopUrlProvider::normalize($url);
    });
    mtucAud008F04_assert($exception instanceof InvalidArgumentException, $label);
    if ($exception instanceof Exception && strpos($label, 'userinfo') !== false) {
        $msg = $exception->getMessage();
        mtucAud008F04_assert(
            stripos($msg, 'pass') === false
                && stripos($msg, 'secret') === false
                && stripos($msg, 'bearer') === false,
            'userinfo rejection error has no credential material'
        );
    }
}

$emptyHost = mtucAud008F04_catch(function () {
    MtUniCreditCanonicalShopUrlProvider::normalize('https://');
});
mtucAud008F04_assert($emptyHost instanceof InvalidArgumentException, 'missing host rejection');


// Explicit empty-string path already tested; also reject whitespace-only as empty after trim
mtucAud008F04_assert(
    MtUniCreditCanonicalShopUrlProvider::normalize("  \t  ") === '',
    'whitespace-only treated as empty'
);

// ---------------------------------------------------------------------------
// Multistore / store-scoped resolve independence
// ---------------------------------------------------------------------------
$urlA = $provider->resolve('https://store-a.example.com/', '');
$urlB = $provider->resolve('https://store-b.example.com/shop/', '');
mtucAud008F04_assert($urlA === 'https://store-a.example.com', 'store A canonical URL');
mtucAud008F04_assert($urlB === 'https://store-b.example.com/shop', 'store B canonical URL');
mtucAud008F04_assert($urlA !== $urlB, 'multistore identities remain independent');

// Prefer SSL over plain without inventing upgrade of plain when SSL present
mtucAud008F04_assert(
    $provider->resolve('https://shop.example.com/', 'http://other.example.com/') === 'https://shop.example.com',
    'resolve prefers ssl candidate when present'
);
mtucAud008F04_assert(
    $provider->resolve('', 'http://plain-only.example.com/') === 'http://plain-only.example.com',
    'plain-only http preserved (no silent https upgrade)'
);

// ---------------------------------------------------------------------------
// Login integration: factory passes provider result as CP login name unchanged
// ---------------------------------------------------------------------------
$memoryDb = Phase4TestHarness::memoryDb();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$stack = Phase4TestHarness::services($transport, $memoryDb);
$stack['client']->login();
$loginName = isset($transport->requests[0]['payload']['name']) ? $transport->requests[0]['payload']['name'] : null;
mtucAud008F04_assert($loginName === Phase4TestHarness::TEST_SHOP_URL, 'login integration uses canonical provider result');
mtucAud008F04_assert(
    $loginName === MtUniCreditCanonicalShopUrlProvider::normalize(Phase4TestHarness::TEST_SHOP_URL),
    'login name matches normalize(TEST_SHOP_URL) with no later divergence'
);

echo PHP_EOL;
if ($failures) {
    echo 'AUD-008 F04 CANONICAL SHOP URL: FAIL (' . count($failures) . ' failed, ' . $passes . ' passed)' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'AUD-008 F04 CANONICAL SHOP URL: PASS (' . $passes . ' assertions)' . PHP_EOL;
exit(0);
