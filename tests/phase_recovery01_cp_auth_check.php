<?php

/**
 * RECOVERY-01 — outbound CP auth Shop.name trailing-slash identity.
 * Run: php tests/phase_recovery01_cp_auth_check.php
 *
 * Proves production CanonicalShopUrlProvider + CpServiceFactory construct the
 * historical registered identity (rtrim) so OpenCart `config_ssl`/`HTTPS_CATALOG`
 * trailing-slash forms still login, while a different host is rejected.
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
function mtucRecovery01_assert($condition, $message)
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

$root = MTUC_PHASE0_ROOT;
if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
}

require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';
require_once __DIR__ . '/support/phase4_harness.php';

mtucRecovery01_assert(mtuc_phase0_network_isolation_active(), 'isolation: offline guard active');

$registeredName = 'https://shop.example.com';
$configWithSlash = 'https://shop.example.com/';
$wrongHost = 'https://other.example.com/';

mtucRecovery01_assert(
    MtUniCreditCanonicalShopUrlProvider::normalize($configWithSlash) === $registeredName,
    'OpenCart trailing-slash config resolves to registered CP Shop.name'
);
mtucRecovery01_assert(
    MtUniCreditCanonicalShopUrlProvider::normalize($registeredName) === $registeredName,
    'already-canonical registered name unchanged'
);
mtucRecovery01_assert(
    MtUniCreditCanonicalShopUrlProvider::normalize($wrongHost) === 'https://other.example.com',
    'wrong host keeps distinct identity after rtrim'
);

// Exact registered name → factory login PASS → GET /shop succeeds
$memoryDb = Phase4TestHarness::memoryDb();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());

$db = new MtUniCreditDbAdapter($memoryDb, 'oc_');
$settings = new MtUniCreditSettingStore($db, MtUniCreditConstants::MODULE_SETTINGS_CODE);
Phase4TestHarness::prepareCredentials($settings, Phase4TestHarness::TEST_STORE_ID);

$stack = MtUniCreditCpServiceFactory::create(
    $db,
    $settings,
    Phase4TestHarness::TEST_STORE_ID,
    $configWithSlash,
    'http://ignored.example.com/',
    $transport,
    function () {
        return 1700000000;
    },
    Phase4TestHarness::testSecretInput(),
    Phase4TestHarness::environmentConfigPath(),
    Phase4TestHarness::offlineDestinationPolicy()
);

$shop = $stack['shopConfiguration']->refreshRemote();
mtucRecovery01_assert(is_array($shop) && $shop !== array(), 'refreshRemote succeeds with trailing-slash config');
mtucRecovery01_assert(isset($transport->requests[0]), 'login request recorded');
mtucRecovery01_assert(
    isset($transport->requests[0]['url']) && strpos((string) $transport->requests[0]['url'], '/auth/login') !== false,
    'first request is /auth/login'
);
$loginPayload = isset($transport->requests[0]['payload']) && is_array($transport->requests[0]['payload'])
    ? $transport->requests[0]['payload']
    : array();
mtucRecovery01_assert(
    isset($loginPayload['name']) && $loginPayload['name'] === $registeredName,
    'login name equals registered CP Shop.name (no trailing slash)'
);
mtucRecovery01_assert(
    isset($loginPayload['unicid']) && $loginPayload['unicid'] === Phase4TestHarness::TEST_UNICID,
    'login unicid sourced from credentials repository'
);
mtucRecovery01_assert(
    isset($loginPayload['secret']) && $loginPayload['secret'] === Phase4TestHarness::TEST_SECRET,
    'login secret sourced from credentials repository (test harness only)'
);
mtucRecovery01_assert(
    isset($transport->requests[1]['url']) && strpos((string) $transport->requests[1]['url'], '/shop') !== false,
    'GET /shop follows successful login'
);

// Different representation/host → authentication rejection (no shop refresh)
$memoryDbB = Phase4TestHarness::memoryDb();
$transportB = new Phase4FakeCpHttpTransport();
$transportB->enqueueJson(401, array('success' => false, 'message' => 'invalid credentials'));

$dbB = new MtUniCreditDbAdapter($memoryDbB, 'oc_');
$settingsB = new MtUniCreditSettingStore($dbB, MtUniCreditConstants::MODULE_SETTINGS_CODE);
Phase4TestHarness::prepareCredentials($settingsB, Phase4TestHarness::TEST_STORE_ID);

$stackB = MtUniCreditCpServiceFactory::create(
    $dbB,
    $settingsB,
    Phase4TestHarness::TEST_STORE_ID,
    $wrongHost,
    '',
    $transportB,
    function () {
        return 1700000000;
    },
    Phase4TestHarness::testSecretInput(),
    Phase4TestHarness::environmentConfigPath(),
    Phase4TestHarness::offlineDestinationPolicy()
);

$authFailed = false;
try {
    $stackB['shopConfiguration']->refreshRemote();
} catch (MtUniCreditCpAuthenticationException $exception) {
    $authFailed = true;
}
mtucRecovery01_assert($authFailed, 'different shopName host → CpAuthenticationException');
mtucRecovery01_assert(
    isset($transportB->requests[0]['payload']['name'])
        && $transportB->requests[0]['payload']['name'] === 'https://other.example.com',
    'rejected login used distinct wrong-host identity'
);
mtucRecovery01_assert(count($transportB->requests) === 1, 'auth rejection does not proceed to /shop');

echo PHP_EOL;
if ($failures) {
    echo 'RECOVERY-01 CP AUTH: FAIL (' . count($failures) . ' failed, ' . $passes . ' passed)' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'RECOVERY-01 CP AUTH: PASS (' . $passes . ' assertions)' . PHP_EOL;
exit(0);
