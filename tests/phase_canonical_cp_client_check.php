<?php

/**
 * Canonical CP client: envelopes, tokens-from-data, no POST /orders 401 replay, error classes.
 * Run: php tests/phase_canonical_cp_client_check.php
 */
require_once __DIR__ . '/bootstrap.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DIR_STORAGE')) {
    mtuc_test_define_dir_storage('mtuc-canonical-cp');
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
}
if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'oc_');
}

require_once $lib . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . '/support/phase2_memory_db.php';
require_once __DIR__ . '/support/phase4_harness.php';
require_once __DIR__ . '/support/phase5_harness.php';
require_once __DIR__ . '/support/phase6_harness.php';
require_once __DIR__ . '/support/phase7_harness.php';
require_once __DIR__ . '/support/canonical_harness.php';
require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

$failures = array();
$passes = 0;

function mtucCanonCp_assert(bool $condition, string $message): void
{
    global $failures, $passes;
    CanonicalTestHarness::assert($condition, $message, $failures, $passes);
}

/**
 * @param Phase4FakeCpHttpTransport $transport
 * @return MtUniCreditControlPanelClient
 */
function mtucCanonCp_client(Phase4FakeCpHttpTransport $transport): MtUniCreditControlPanelClient
{
    $services = Phase4TestHarness::services($transport);

    return $services['client'];
}

/**
 * @return array<string, mixed>
 */
function mtucCanonCp_minimalOrder(): array
{
    return array(
        'order_id' => '94001',
        'unicid' => Phase4TestHarness::TEST_UNICID,
        'shop_id' => 1,
    );
}

// Success login + getShop with canonical envelopes / tokens from data only.
$transportOk = new Phase4FakeCpHttpTransport();
$transportOk->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transportOk->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());
$clientOk = mtucCanonCp_client($transportOk);
$login = $clientOk->login();
mtucCanonCp_assert(isset($login['data']['access_token']), 'login success reads token from data');
$shop = $clientOk->getShop();
mtucCanonCp_assert(isset($shop['data']['uni_status']), 'shop success envelope');

// Legacy top-level tokens without data.access_token rejected.
$transportLegacy = new Phase4FakeCpHttpTransport();
$transportLegacy->enqueueJson(200, array(
    'success' => true,
    'error' => null,
    'message' => 'ok',
    'data' => array('shop' => array('id' => 1, 'name' => Phase4TestHarness::TEST_SHOP_URL, 'unicid' => Phase4TestHarness::TEST_UNICID)),
    'access_token' => str_repeat('b', 64),
    'token_type' => 'Bearer',
    'expires_in' => 86400,
));
$legacyThrown = false;
try {
    mtucCanonCp_client($transportLegacy)->login();
} catch (MtUniCreditCpInvalidPayloadException $exception) {
    $legacyThrown = true;
}
mtucCanonCp_assert($legacyThrown, 'legacy top-level tokens rejected when data.access_token absent');

// Malformed success (missing error:null).
$transportMalformed = new Phase4FakeCpHttpTransport();
$transportMalformed->enqueueJson(200, array(
    'success' => true,
    'message' => 'ok',
    'data' => array(
        'access_token' => str_repeat('c', 64),
        'token_type' => 'Bearer',
        'expires_in' => 86400,
        'shop' => array('id' => 1, 'name' => Phase4TestHarness::TEST_SHOP_URL, 'unicid' => Phase4TestHarness::TEST_UNICID),
    ),
));
$malformedThrown = false;
try {
    mtucCanonCp_client($transportMalformed)->login();
} catch (MtUniCreditCpInvalidPayloadException $exception) {
    $malformedThrown = true;
}
mtucCanonCp_assert($malformedThrown, 'malformed success envelope (missing error) rejected');

// Create 401: exactly one POST /orders (no auto-replay).
$transport401 = new Phase4FakeCpHttpTransport();
$transport401->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport401->enqueueJson(401, CanonicalTestHarness::failureEnvelope('authentication_failed', 'expired'));
$transport401->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$client401 = mtucCanonCp_client($transport401);
$client401->login();
$authThrown = false;
try {
    $client401->createOrder(array('order_id' => '94002'));
} catch (MtUniCreditCpAuthenticationException $exception) {
    $authThrown = true;
}
$orderPosts = 0;
$logins = 0;
foreach ($transport401->requests as $request) {
    if (strtoupper((string) $request['method']) === 'POST' && substr((string) $request['url'], -7) === '/orders') {
        $orderPosts++;
    }
    if (strpos((string) $request['url'], '/auth/login') !== false) {
        $logins++;
    }
}
mtucCanonCp_assert($authThrown, 'createOrder 401 throws auth exception');
mtucCanonCp_assert($orderPosts === 1, 'createOrder 401: exactly one POST /orders (no replay)');
mtucCanonCp_assert($logins === 1, 'createOrder 401: no automatic re-login for unsafe create');

// 429 rate limit → CpHttpException with canonical error.
$transport429 = new Phase4FakeCpHttpTransport();
$transport429->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport429->enqueueJson(429, CanonicalTestHarness::failureEnvelope('rate_limited', 'slow down'));
$client429 = mtucCanonCp_client($transport429);
$client429->login();
$rateThrown = false;
$rateError = '';
try {
    $client429->createOrder(array('order_id' => '94003'));
} catch (MtUniCreditCpHttpException $exception) {
    $rateThrown = true;
    $rateError = method_exists($exception, 'getCanonicalError')
        ? (string) $exception->getCanonicalError()
        : '';
}
mtucCanonCp_assert($rateThrown && $rateError === 'rate_limited', '429 rate_limited classified');

// 5xx internal_error.
$transport5xx = new Phase4FakeCpHttpTransport();
$transport5xx->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport5xx->enqueueJson(500, CanonicalTestHarness::failureEnvelope('internal_error', 'boom'));
$client5xx = mtucCanonCp_client($transport5xx);
$client5xx->login();
$fiveThrown = false;
try {
    $client5xx->createOrder(array('order_id' => '94004'));
} catch (MtUniCreditCpHttpException $exception) {
    $fiveThrown = true;
}
mtucCanonCp_assert($fiveThrown, '5xx internal_error → CpHttpException');

// Malformed 409 body (not canonical failure envelope).
$transport409 = new Phase4FakeCpHttpTransport();
$transport409->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport409->enqueueJson(409, array('message' => 'conflict without envelope'));
$client409 = mtucCanonCp_client($transport409);
$client409->login();
$bad409 = false;
try {
    $client409->createOrder(array('order_id' => '94005'));
} catch (MtUniCreditCpInvalidPayloadException $exception) {
    $bad409 = true;
}
mtucCanonCp_assert($bad409, 'malformed 409 body → invalid payload (not trusted conflict)');

// Unknown 4xx with canonical envelope.
$transport4xx = new Phase4FakeCpHttpTransport();
$transport4xx->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport4xx->enqueueJson(418, CanonicalTestHarness::failureEnvelope('teapot', 'no'));
$client4xx = mtucCanonCp_client($transport4xx);
$client4xx->login();
$unknown4xx = false;
try {
    $client4xx->createOrder(array('order_id' => '94006'));
} catch (MtUniCreditCpHttpException $exception) {
    $unknown4xx = true;
}
mtucCanonCp_assert($unknown4xx, 'unknown 4xx canonical failure → CpHttpException');

// Echo mismatch on create success.
$transportEcho = new Phase4FakeCpHttpTransport();
$transportEcho->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transportEcho->enqueueJson(200, CanonicalTestHarness::successEnvelope(array(
    'id' => 99,
    'order_id' => 'OTHER',
    'unicid' => Phase4TestHarness::TEST_UNICID,
    'shop_id' => 1,
    'created_at' => '2024-01-01 00:00:00',
)));
$clientEcho = mtucCanonCp_client($transportEcho);
$clientEcho->login();
$echoThrown = false;
try {
    $clientEcho->createOrder(array('order_id' => '94007'));
} catch (MtUniCreditCpInvalidPayloadException $exception) {
    $echoThrown = true;
}
mtucCanonCp_assert($echoThrown, 'create order_id echo mismatch rejected');

// Machine error must be lowercase snake_case; malformed cannot be canonical failure.
$badMachineErrors = array(
    'SemanticConflict',
    'semantic-conflict',
    'SEMANTIC_CONFLICT',
    '1invalid',
    'has space',
    'грешка',
    'bad!',
    '',
);
foreach ($badMachineErrors as $badError) {
    $transportBad = new Phase4FakeCpHttpTransport();
    $transportBad->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
    $transportBad->enqueueJson(409, array(
        'success' => false,
        'error' => $badError,
        'message' => 'nope',
        'data' => new stdClass(),
    ));
    $clientBad = mtucCanonCp_client($transportBad);
    $clientBad->login();
    $rejected = false;
    $asHttp = false;
    try {
        $clientBad->createOrder(array('order_id' => '94080'));
    } catch (MtUniCreditCpInvalidPayloadException $exception) {
        $rejected = true;
    } catch (MtUniCreditCpHttpException $exception) {
        $asHttp = $exception->isCanonicalFailure();
    }
    mtucCanonCp_assert(
        $rejected && !$asHttp,
        'noncanonical machine error rejected: ' . var_export($badError, true)
    );
}

$transportGood = new Phase4FakeCpHttpTransport();
$transportGood->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transportGood->enqueueJson(409, CanonicalTestHarness::failureEnvelope('semantic_conflict', 'conflict'));
$clientGood = mtucCanonCp_client($transportGood);
$clientGood->login();
$goodCanonical = false;
try {
    $clientGood->createOrder(array('order_id' => '94081'));
} catch (MtUniCreditCpHttpException $exception) {
    $goodCanonical = $exception->isCanonicalFailure()
        && $exception->getCanonicalError() === 'semantic_conflict';
}
mtucCanonCp_assert($goodCanonical, 'valid snake_case semantic_conflict accepted as canonical failure');

echo PHP_EOL . 'canonical cp client: ' . $passes . ' passed, ' . count($failures) . ' failed' . PHP_EOL;
exit(count($failures) === 0 ? 0 : 1);
