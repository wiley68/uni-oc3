<?php

/**
 * AUD-008 F02 — Strict CP HTTPS destination policy.
 * Run: php tests/phase_aud008_f02_cp_destination_policy_check.php
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
function mtucAud008F02_assert($condition, $message)
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
function mtucAud008F02_catch(callable $callback)
{
    try {
        $callback();

        return null;
    } catch (Exception $exception) {
        return $exception;
    }
}

/**
 * @param string $controlPanelUrl
 * @return string
 */
function mtucAud008F02_tempEnv($controlPanelUrl)
{
    $path = tempnam(sys_get_temp_dir(), 'mtuc_aud008_f02_');
    if ($path === false) {
        throw new RuntimeException('Unable to create temporary environment fixture.');
    }
    $php = "<?php\nreturn array('control_panel_url' => " . var_export($controlPanelUrl, true) . ");\n";
    file_put_contents($path, $php);

    return $path;
}

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
}

require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';
require_once __DIR__ . '/support/phase4_harness.php';

mtucAud008F02_assert(mtuc_phase0_network_isolation_active(), 'isolation: offline guard active');
mtucAud008F02_assert(is_file($lib . DIRECTORY_SEPARATOR . 'cp_destination_policy.php'), 'policy helper file present');

$policy = new MtUniCreditCpDestinationPolicy();

// ---------------------------------------------------------------------------
// Policy unit: accept / canonicalize
// ---------------------------------------------------------------------------
mtucAud008F02_assert(
    $policy->assertTrustedOrigin('https://uni.avalonbg.com') === 'https://uni.avalonbg.com',
    'valid production origin accepted'
);
mtucAud008F02_assert(
    $policy->assertTrustedApiBase('https://uni.avalonbg.com/api/v1') === 'https://uni.avalonbg.com/api/v1',
    'valid production HTTPS API base accepted'
);
mtucAud008F02_assert(
    $policy->assertTrustedApiBase('https://uni.avalonbg.com/api/v1/') === 'https://uni.avalonbg.com/api/v1',
    'trailing-slash API base canonicalized'
);
mtucAud008F02_assert(
    $policy->assertTrustedOrigin('https://UNI.AVALONBG.COM/') === 'https://uni.avalonbg.com',
    'mixed-case host canonicalized on origin'
);
mtucAud008F02_assert(
    $policy->assertTrustedApiBase('https://UNI.AVALONBG.COM/api/v1') === 'https://uni.avalonbg.com/api/v1',
    'mixed-case host canonicalized on API base'
);
mtucAud008F02_assert(
    $policy->assertTrustedApiBase('https://uni.avalonbg.com:443/api/v1') === 'https://uni.avalonbg.com/api/v1',
    'explicit default HTTPS port accepted and canonicalized'
);
mtucAud008F02_assert(
    $policy->assertTrustedApiBase('https://cp-test.example.com/api/v1') === 'https://cp-test.example.com/api/v1',
    'offline fixture host accepted'
);

// ---------------------------------------------------------------------------
// Policy unit: reject
// ---------------------------------------------------------------------------
$rejectCases = array(
    'HTTP rejected' => 'http://uni.avalonbg.com/api/v1',
    'userinfo rejected' => 'https://user@uni.avalonbg.com/api/v1',
    'userinfo with password rejected' => 'https://user:pass@uni.avalonbg.com/api/v1',
    'query rejected' => 'https://uni.avalonbg.com/api/v1?x=1',
    'fragment rejected' => 'https://uni.avalonbg.com/api/v1#fragment',
    'unexpected path rejected' => 'https://uni.avalonbg.com/foo',
    'extra path segment rejected' => 'https://uni.avalonbg.com/api/v1/foo',
    'path traversal-style input rejected' => 'https://uni.avalonbg.com/api/v1/../evil',
    'malformed URL rejected' => 'not-a-url',
    'empty URL rejected' => '',
    'production wrong host rejected' => 'https://evil.example.com/api/v1',
    'non-default port rejected' => 'https://uni.avalonbg.com:8443/api/v1',
    'IP literal rejected' => 'https://127.0.0.1/api/v1',
    'origin with API path rejected as origin' => null,
);

foreach ($rejectCases as $label => $url) {
    if ($label === 'origin with API path rejected as origin') {
        $exception = mtucAud008F02_catch(function () use ($policy) {
            $policy->assertTrustedOrigin('https://uni.avalonbg.com/api/v1');
        });
        mtucAud008F02_assert($exception instanceof InvalidArgumentException, $label);
        continue;
    }
    $exception = mtucAud008F02_catch(function () use ($policy, $url) {
        $policy->assertTrustedApiBase($url);
    });
    mtucAud008F02_assert($exception instanceof InvalidArgumentException, $label);
}

// ---------------------------------------------------------------------------
// Deployment environment
// ---------------------------------------------------------------------------
$packaged = new MtUniCreditDeploymentEnvironment();
mtucAud008F02_assert(
    $packaged->controlPanelUrl() === 'https://uni.avalonbg.com',
    'packaged deployment origin preserved'
);
mtucAud008F02_assert(
    $packaged->controlPanelApiBaseUrl() === 'https://uni.avalonbg.com/api/v1',
    'packaged deployment API base preserved'
);

$fixtureEnv = new MtUniCreditDeploymentEnvironment(Phase4TestHarness::environmentConfigPath());
mtucAud008F02_assert(
    $fixtureEnv->controlPanelApiBaseUrl() === 'https://cp-test.example.com/api/v1',
    'offline fixture deployment API base accepted'
);

$httpEnvPath = mtucAud008F02_tempEnv('http://uni.avalonbg.com');
$httpException = mtucAud008F02_catch(function () use ($httpEnvPath) {
    (new MtUniCreditDeploymentEnvironment($httpEnvPath))->controlPanelApiBaseUrl();
});
mtucAud008F02_assert($httpException instanceof RuntimeException, 'deployment HTTP origin fails closed');
@unlink($httpEnvPath);

$wrongHostEnvPath = mtucAud008F02_tempEnv('https://attacker.example/api/v1');
$wrongHostException = mtucAud008F02_catch(function () use ($wrongHostEnvPath) {
    // Origin must not include path; this also fails closed.
    (new MtUniCreditDeploymentEnvironment($wrongHostEnvPath))->controlPanelUrl();
});
mtucAud008F02_assert($wrongHostException instanceof RuntimeException, 'deployment wrong-host/path fails closed');
@unlink($wrongHostEnvPath);

// ---------------------------------------------------------------------------
// Client defense-in-depth + zero transport / zero credential exposure
// ---------------------------------------------------------------------------
$memoryDb = Phase4TestHarness::memoryDb();
$db = new MtUniCreditDbAdapter($memoryDb, 'oc_');
$settings = new MtUniCreditSettingStore($db, MtUniCreditConstants::MODULE_SETTINGS_CODE);
Phase4TestHarness::prepareCredentials($settings, Phase4TestHarness::TEST_STORE_ID);
$credentials = new MtUniCreditCredentialsRepository($settings, Phase4TestHarness::cipher());
$tokens = new MtUniCreditCpTokenRepository($settings, Phase4TestHarness::cipher(), Phase4TestHarness::TEST_STORE_ID);
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());

$constructException = mtucAud008F02_catch(function () use ($credentials, $tokens, $transport) {
    new MtUniCreditControlPanelClient(
        $credentials,
        $tokens,
        $transport,
        Phase4TestHarness::TEST_SHOP_URL,
        Phase4TestHarness::TEST_STORE_ID,
        'http://uni.avalonbg.com/api/v1'
    );
});
mtucAud008F02_assert($constructException instanceof InvalidArgumentException, 'client constructor rejects HTTP override');
mtucAud008F02_assert(count($transport->requests) === 0, 'invalid destination performs zero transport calls');

$userinfoException = mtucAud008F02_catch(function () use ($credentials, $tokens, $transport) {
    new MtUniCreditControlPanelClient(
        $credentials,
        $tokens,
        $transport,
        Phase4TestHarness::TEST_SHOP_URL,
        Phase4TestHarness::TEST_STORE_ID,
        'https://user:secret@uni.avalonbg.com/api/v1'
    );
});
mtucAud008F02_assert($userinfoException instanceof InvalidArgumentException, 'client constructor rejects userinfo override');
mtucAud008F02_assert(count($transport->requests) === 0, 'userinfo override still zero transport calls');
if ($userinfoException instanceof Exception) {
    $msg = $userinfoException->getMessage();
    mtucAud008F02_assert(
        stripos($msg, 'secret') === false
            && stripos($msg, 'bearer') === false
            && stripos($msg, 'password') === false
            && stripos($msg, 'Authorization') === false,
        'invalid destination error does not leak credentials'
    );
}

// Valid client routes (regression)
$routeDb = Phase4TestHarness::memoryDb();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport->enqueueJson(200, array('success' => true));
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());
$transport->enqueueJson(200, array('success' => true, 'data' => array('id' => 1)));

$stack = Phase4TestHarness::services($transport, $routeDb);
$client = $stack['client'];
$client->login();
$client->refreshToken();
$client->logout();
$client->login();
$client->getShop();
$client->createOrder(array('order_id' => '1'));
$client->updateOrderStatus('1', 'Approved', 'bank_sent_process1');

$expectedRoutes = array(
    array('POST', 'https://cp-test.example.com/api/v1/auth/login'),
    array('POST', 'https://cp-test.example.com/api/v1/auth/refresh'),
    array('POST', 'https://cp-test.example.com/api/v1/auth/logout'),
    array('POST', 'https://cp-test.example.com/api/v1/auth/login'),
    array('GET', 'https://cp-test.example.com/api/v1/shop'),
    array('POST', 'https://cp-test.example.com/api/v1/orders'),
    array('PATCH', 'https://cp-test.example.com/api/v1/orders/status'),
);

mtucAud008F02_assert(count($transport->requests) === count($expectedRoutes), 'route composition request count');
foreach ($expectedRoutes as $index => $expected) {
    $actual = $transport->requests[$index];
    mtucAud008F02_assert(
        isset($actual['method'], $actual['url'])
            && $actual['method'] === $expected[0]
            && $actual['url'] === $expected[1],
        'route ' . $expected[0] . ' ' . $expected[1]
    );
}

// Redirect / TLS regression (source contract unchanged)
$curlSource = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'curl_cp_http_transport.php');
mtucAud008F02_assert(strpos($curlSource, 'CURLOPT_FOLLOWLOCATION => false') !== false, 'redirects remain disabled');
mtucAud008F02_assert(strpos($curlSource, 'CURLOPT_SSL_VERIFYPEER => true') !== false, 'TLS peer verify remains enabled');
mtucAud008F02_assert(strpos($curlSource, 'CURLOPT_SSL_VERIFYHOST => 2') !== false, 'TLS host verify remains enabled');

echo PHP_EOL;
if ($failures) {
    echo 'AUD-008 F02 CP DESTINATION POLICY: FAIL (' . count($failures) . ' failed, ' . $passes . ' passed)' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'AUD-008 F02 CP DESTINATION POLICY: PASS (' . $passes . ' assertions)' . PHP_EOL;
exit(0);
