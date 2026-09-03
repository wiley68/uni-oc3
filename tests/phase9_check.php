<?php

/**
 * Phase 9 Process 1 / SmartUCF lifecycle checks.
 * Run: php tests/phase9_check.php
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/support/phase2_memory_db.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
}

require_once $lib . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . '/support/phase4_harness.php';
require_once __DIR__ . '/support/phase5_harness.php';
require_once __DIR__ . '/support/phase7_harness.php';
require_once __DIR__ . '/support/phase9_harness.php';
require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

$failures = array();
$passes = 0;

function mtuc9_assert(bool $condition, string $message): void
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
 * @param string $path
 * @return string
 */
function mtuc9_read_source($path)
{
    $lines = @file($path);
    if ($lines === false) {
        return '';
    }

    return implode('', $lines);
}

$required = array(
    'smart_ucf_endpoint_policy.php',
    'smart_ucf_session_exception.php',
    'smart_ucf_lifecycle_states.php',
    'smart_ucf_failure_classification.php',
    'smart_ucf_failure_classifier.php',
    'smart_ucf_payload_builder.php',
    'smart_ucf_session_client.php',
    'smart_ucf_coordination_result.php',
    'smart_ucf_lifecycle_repository.php',
    'smart_ucf_session_coordinator.php',
    'certificate_local_paths.php',
    'certificate_pair_validator.php',
    'mtls_private_key_passphrase_provider.php',
    'process1_service_factory.php',
    'bank_status.php',
    'shop_configuration_flags.php',
);
foreach ($required as $file) {
    mtuc9_assert(is_file($lib . DIRECTORY_SEPARATOR . $file), 'required file: ' . $file);
}

$phase9Sql = MtUniCreditPersistenceSchema::createPhase9AlterStatements('oc_');
mtuc9_assert(count($phase9Sql) >= 1, 'phase 9 schema alter statements present');
mtuc9_assert(strpos($phase9Sql[0], 'smartucf_state') !== false, 'phase 9 adds smartucf_state');

// ---------------------------------------------------------------------------
// Process 2 selection: uni_proces===1 → zero SmartUCF HTTP; no Process1 bank statuses
// ---------------------------------------------------------------------------
$transportP2 = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportP2);
$stackP2 = Phase9TestHarness::stack($transportP2, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$orderP2 = 9101;
Phase9TestHarness::seedBankOrder($stackP2['memoryDb'], $orderP2, $stackP2['storeId']);
$resultP2 = $stackP2['submission']->submit(Phase9TestHarness::submitInput($orderP2, $stackP2['storeId']));
mtuc9_assert(!empty($resultP2['success']), 'Process2: CP submit succeeds');
mtuc9_assert((int) $resultP2['control_panel_order_id'] === 555001, 'Process2: CP order id persisted');
mtuc9_assert(Phase9TestHarness::smartUcfCallCount($stackP2['smartUcfProbe']) === 0, 'Process2: 0 SmartUCF HTTP calls');
$bankP2 = Phase9TestHarness::bankStatusId($stackP2, $orderP2);
mtuc9_assert($bankP2 !== MtUniCreditBankStatus::SENT_PROCESS1, 'Process2: bank status NOT bank_sent_process1');
mtuc9_assert($bankP2 !== MtUniCreditBankStatus::SEND_FAILED_SMARTUCF, 'Process2: bank status NOT bank_send_failed_smartucf');

// ---------------------------------------------------------------------------
// Process1 success: CP + SmartUCF → bank_sent_process1, single call, trusted redirect
// ---------------------------------------------------------------------------
$transportOk = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportOk);
$stackOk = Phase9TestHarness::stack($transportOk);
$orderOk = Phase9TestHarness::ORDER_ID;
Phase9TestHarness::seedBankOrder($stackOk['memoryDb'], $orderOk, $stackOk['storeId']);
$resultOk = $stackOk['submission']->submit(Phase9TestHarness::submitInput($orderOk, $stackOk['storeId']));
mtuc9_assert(!empty($resultOk['success']), 'Process1 success: overall success');
mtuc9_assert((int) $resultOk['control_panel_order_id'] === 555001, 'Process1 success: CP order id');
mtuc9_assert(Phase9TestHarness::smartUcfCallCount($stackOk['smartUcfProbe']) === 1, 'Process1 success: single SmartUCF call');
mtuc9_assert(
    Phase9TestHarness::bankStatusId($stackOk, $orderOk) === MtUniCreditBankStatus::SENT_PROCESS1,
    'Process1 success: bank_sent_process1'
);
mtuc9_assert(!empty($resultOk['redirect']), 'Process1 success: redirect present');
mtuc9_assert(
    (new MtUniCreditSmartUcfEndpointPolicy())->isTrustedApplicationRedirect((string) $resultOk['redirect']),
    'Process1 success: redirect URL trusted'
);
mtuc9_assert(
    strpos((string) $resultOk['redirect'], Phase9TestHarness::SESSION_ID) !== false,
    'Process1 success: redirect contains session id'
);
$attemptOk = $stackOk['attempts']->findByStoreOrder($stackOk['storeId'], $orderOk);
$smartOk = $attemptOk !== null
    ? $stackOk['smartUcfLifecycle']->findByAttempt((int) $attemptOk['attempt_id'])
    : null;
mtuc9_assert(
    $smartOk !== null && (string) $smartOk['smartucf_state'] === MtUniCreditSmartUcfLifecycleStates::CREATED,
    'Process1 success: smartucf_state created'
);
mtuc9_assert(Phase7TestHarness::countOrderPosts($transportOk) === 1, 'Process1 success: CP POST /orders exactly once');

// ---------------------------------------------------------------------------
// Product Step 2 equivalent wiring: storefront submit -> CP -> Process1 SmartUCF
// ---------------------------------------------------------------------------
$transportStorefrontP1 = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportStorefrontP1);
$stackStorefrontP1 = Phase9TestHarness::stack($transportStorefrontP1, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 0));
$storefrontOrderId = 9701;
$storefrontInput = Phase9TestHarness::productStorefrontInput($stackStorefrontP1, $storefrontOrderId);
$storefrontResult = $stackStorefrontP1['storefront']->submit($storefrontInput);
mtuc9_assert(!empty($storefrontResult['success']), 'storefront P1: submit success');
mtuc9_assert((int) $storefrontResult['order_id'] === $storefrontOrderId, 'storefront P1: one local order');
mtuc9_assert((int) $storefrontResult['control_panel_order_id'] === 555001, 'storefront P1: cp_created');
mtuc9_assert(Phase7TestHarness::countOrderPosts($transportStorefrontP1) === 1, 'storefront P1: CP calls = 1');
mtuc9_assert(Phase9TestHarness::smartUcfCallCount($stackStorefrontP1['smartUcfProbe']) === 1, 'storefront P1: SmartUCF calls = 1');
mtuc9_assert(
    Phase9TestHarness::bankStatusId($stackStorefrontP1, $storefrontOrderId) === MtUniCreditBankStatus::SENT_PROCESS1,
    'storefront P1: bank_sent_process1'
);
mtuc9_assert(!empty($storefrontResult['redirect']), 'storefront P1: redirect present after SmartUCF');
$storefrontInputReplay = $storefrontInput;
$storefrontInputReplay['session'] = isset($storefrontResult['session']) && is_array($storefrontResult['session'])
    ? $storefrontResult['session']
    : array();
$storefrontReplay = $stackStorefrontP1['storefront']->submit($storefrontInputReplay);
mtuc9_assert(!empty($storefrontReplay['success']) && !empty($storefrontReplay['local_replay']), 'storefront P1 replay: local replay success');
mtuc9_assert(Phase7TestHarness::countOrderPosts($transportStorefrontP1) === 1, 'storefront P1 replay: CP additional calls = 0');
mtuc9_assert(Phase9TestHarness::smartUcfCallCount($stackStorefrontP1['smartUcfProbe']) === 1, 'storefront P1 replay: SmartUCF additional calls = 0');

// ---------------------------------------------------------------------------
// Product Step 2 equivalent wiring: Process 2 skip (no SmartUCF in Phase 9)
// ---------------------------------------------------------------------------
$transportStorefrontP2 = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportStorefrontP2);
$stackStorefrontP2 = Phase9TestHarness::stack($transportStorefrontP2, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$storefrontP2OrderId = 9702;
$storefrontP2Result = $stackStorefrontP2['storefront']->submit(
    Phase9TestHarness::productStorefrontInput($stackStorefrontP2, $storefrontP2OrderId)
);
mtuc9_assert(!empty($storefrontP2Result['success']), 'storefront P2: submit success');
mtuc9_assert(Phase7TestHarness::countOrderPosts($transportStorefrontP2) === 1, 'storefront P2: CP calls = 1');
mtuc9_assert(Phase9TestHarness::smartUcfCallCount($stackStorefrontP2['smartUcfProbe']) === 0, 'storefront P2: SmartUCF calls = 0');
mtuc9_assert(
    Phase9TestHarness::bankStatusId($stackStorefrontP2, $storefrontP2OrderId) !== MtUniCreditBankStatus::SENT_PROCESS1,
    'storefront P2: no bank_sent_process1'
);

// ---------------------------------------------------------------------------
// Definitive SmartUCF reject (errorCode JSON, HTTP 400)
// ---------------------------------------------------------------------------
$transportReject = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportReject);
$stackReject = Phase9TestHarness::stack(
    $transportReject,
    function (array $options): array {
        return array(
            'body' => Phase9TestHarness::rejectBody(),
            'error' => '',
            'http_code' => 400,
        );
    }
);
$orderReject = 9201;
Phase9TestHarness::seedBankOrder($stackReject['memoryDb'], $orderReject, $stackReject['storeId']);
$resultReject = $stackReject['submission']->submit(Phase9TestHarness::submitInput($orderReject, $stackReject['storeId']));
mtuc9_assert(empty($resultReject['success']), 'SmartUCF reject: overall failure');
mtuc9_assert((int) $resultReject['control_panel_order_id'] === 555001, 'SmartUCF reject: CP id preserved');
mtuc9_assert(
    Phase9TestHarness::bankStatusId($stackReject, $orderReject) === MtUniCreditBankStatus::SEND_FAILED_SMARTUCF,
    'SmartUCF reject: bank_send_failed_smartucf'
);
$attemptReject = $stackReject['attempts']->findByStoreOrder($stackReject['storeId'], $orderReject);
$smartReject = $attemptReject !== null
    ? $stackReject['smartUcfLifecycle']->findByAttempt((int) $attemptReject['attempt_id'])
    : null;
mtuc9_assert(
    $smartReject !== null
        && (string) $smartReject['smartucf_state'] === MtUniCreditSmartUcfLifecycleStates::FAILED
        && (string) $smartReject['smartucf_error_class'] === MtUniCreditSmartUcfFailureClassification::CLASS_REMOTE_REJECT,
    'SmartUCF reject: failed remote_reject'
);
mtuc9_assert(Phase9TestHarness::smartUcfCallCount($stackReject['smartUcfProbe']) === 1, 'SmartUCF reject: one HTTP call');

// ---------------------------------------------------------------------------
// Timeout / transport ambiguous → outcome_unknown; second submit does not resend
// ---------------------------------------------------------------------------
$transportTimeout = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportTimeout);
$stackTimeout = Phase9TestHarness::stack(
    $transportTimeout,
    function (array $options): array {
        return array(
            'body' => '',
            'error' => 'Operation timed out after 10000 milliseconds',
            'http_code' => 0,
        );
    }
);
$orderTimeout = 9301;
Phase9TestHarness::seedBankOrder($stackTimeout['memoryDb'], $orderTimeout, $stackTimeout['storeId']);
$inputTimeout = Phase9TestHarness::submitInput($orderTimeout, $stackTimeout['storeId']);
$resultTimeout = $stackTimeout['submission']->submit($inputTimeout);
mtuc9_assert(empty($resultTimeout['success']), 'timeout: submit fails');
mtuc9_assert(!empty($resultTimeout['ambiguous_blocked']), 'timeout: ambiguous_blocked');
mtuc9_assert(
    isset($resultTimeout['error']) && $resultTimeout['error'] === 'smartucf_outcome_unknown',
    'timeout: error smartucf_outcome_unknown'
);
mtuc9_assert(
    Phase9TestHarness::bankStatusId($stackTimeout, $orderTimeout) !== MtUniCreditBankStatus::SENT_PROCESS1,
    'timeout: no bank_sent_process1'
);
mtuc9_assert(
    Phase9TestHarness::bankStatusId($stackTimeout, $orderTimeout) !== MtUniCreditBankStatus::SEND_FAILED_SMARTUCF,
    'timeout: no bank_send_failed_smartucf'
);
$attemptTimeout = $stackTimeout['attempts']->findByStoreOrder($stackTimeout['storeId'], $orderTimeout);
$smartTimeout = $attemptTimeout !== null
    ? $stackTimeout['smartUcfLifecycle']->findByAttempt((int) $attemptTimeout['attempt_id'])
    : null;
mtuc9_assert(
    $smartTimeout !== null
        && (string) $smartTimeout['smartucf_state'] === MtUniCreditSmartUcfLifecycleStates::OUTCOME_UNKNOWN,
    'timeout: smartucf_state outcome_unknown'
);
mtuc9_assert(Phase9TestHarness::smartUcfCallCount($stackTimeout['smartUcfProbe']) === 1, 'timeout: one SmartUCF call');
$secondTimeout = $stackTimeout['submission']->submit($inputTimeout);
mtuc9_assert(empty($secondTimeout['success']), 'timeout replay: still fails');
mtuc9_assert(!empty($secondTimeout['ambiguous_blocked']), 'timeout replay: still ambiguous');
mtuc9_assert(
    Phase9TestHarness::smartUcfCallCount($stackTimeout['smartUcfProbe']) === 1,
    'timeout replay: no fresh SmartUCF resend'
);

// ---------------------------------------------------------------------------
// Idempotent replay after success → 0 additional SmartUCF calls
// ---------------------------------------------------------------------------
$secondOk = $stackOk['submission']->submit(Phase9TestHarness::submitInput($orderOk, $stackOk['storeId']));
mtuc9_assert(!empty($secondOk['success']) && !empty($secondOk['local_replay']), 'idempotent replay: local success');
mtuc9_assert(Phase9TestHarness::smartUcfCallCount($stackOk['smartUcfProbe']) === 1, 'idempotent replay: 0 additional SmartUCF calls');
mtuc9_assert(
    !empty($secondOk['redirect'])
        && (new MtUniCreditSmartUcfEndpointPolicy())->isTrustedApplicationRedirect((string) $secondOk['redirect']),
    'idempotent replay: trusted redirect reused'
);

// ---------------------------------------------------------------------------
// Concurrent claim: second claimForSubmitting returns null
// ---------------------------------------------------------------------------
$transportClaim = new Phase4FakeCpHttpTransport();
$stackClaim = Phase9TestHarness::stack($transportClaim);
$claimAttempt = $stackClaim['attempts']->findOrCreateCheckoutAttempt(
    $stackClaim['storeId'],
    9401,
    Phase4TestHarness::TEST_UNICID,
    hash('sha256', 'checkout|' . $stackClaim['storeId'] . '|9401'),
    hash('sha256', 'selection'),
    hash('sha256', 'fingerprint')
);
$claimId = (int) $claimAttempt['attempt_id'];
$firstClaim = $stackClaim['smartUcfLifecycle']->claimForSubmitting($claimId);
$secondClaim = $stackClaim['smartUcfLifecycle']->claimForSubmitting($claimId);
mtuc9_assert($firstClaim !== null, 'concurrent claim: first claim succeeds');
mtuc9_assert($secondClaim === null, 'concurrent claim: second claim returns null');
mtuc9_assert(
    (string) $firstClaim['smartucf_state'] === MtUniCreditSmartUcfLifecycleStates::SUBMITTING,
    'concurrent claim: state submitting'
);

// ---------------------------------------------------------------------------
// Payload shape: no egn/phone2; product/months/amounts from calculation; user/pass from shop
// ---------------------------------------------------------------------------
$shopPayload = mtuc4_valid_shop_snapshot();
$calcPayload = Phase9TestHarness::calculation($shopPayload);
$builtPayload = (new MtUniCreditSmartUcfPayloadBuilder())->build(
    $shopPayload,
    Phase7TestHarness::orderRow(9501),
    Phase7TestHarness::orderProducts(),
    $calcPayload,
    9501
);
$payloadKeyLeak = false;
foreach (array_keys($builtPayload) as $payloadKey) {
    if (preg_match('/egn|phone2/i', (string) $payloadKey)) {
        $payloadKeyLeak = true;
        break;
    }
}
mtuc9_assert(!$payloadKeyLeak && !array_key_exists('egn', $builtPayload) && !array_key_exists('phone2', $builtPayload), 'payload: no egn/phone2 keys');
mtuc9_assert($builtPayload['onlineProductCode'] === 'KOPSTD', 'payload: onlineProductCode from calculation');
mtuc9_assert((int) $builtPayload['installmentCount'] === 12, 'payload: months from calculation');
mtuc9_assert(
    $builtPayload['totalPrice'] === number_format(abs((float) $calcPayload->price), 2, '.', ''),
    'payload: totalPrice from calculation'
);
mtuc9_assert(
    $builtPayload['monthlyPayment'] === number_format(abs((float) $calcPayload->monthlyInstallment), 2, '.', ''),
    'payload: monthlyPayment from calculation'
);
mtuc9_assert(
    $builtPayload['initialPayment'] === number_format(abs((float) $calcPayload->firstInstallment->amount), 2, '.', ''),
    'payload: initialPayment from calculation'
);
mtuc9_assert($builtPayload['user'] === (string) $shopPayload['uni_user'], 'payload: user from shop');
mtuc9_assert($builtPayload['pass'] === (string) $shopPayload['uni_password'], 'payload: pass from shop');

$livePayload = Phase9TestHarness::smartUcfPayloadAt($stackOk['smartUcfProbe'], 0);
mtuc9_assert(is_array($livePayload), 'live SmartUCF POST payload captured');
if (is_array($livePayload)) {
    mtuc9_assert($livePayload['onlineProductCode'] === 'KOPSTD', 'live payload: onlineProductCode');
    mtuc9_assert((int) $livePayload['installmentCount'] === 12, 'live payload: installmentCount');
    mtuc9_assert($livePayload['user'] === (string) $shopPayload['uni_user'], 'live payload: user from shop cache');
    mtuc9_assert($livePayload['pass'] === (string) $shopPayload['uni_password'], 'live payload: pass from shop cache');
}

// ---------------------------------------------------------------------------
// Endpoint policy: trusted hosts only
// ---------------------------------------------------------------------------
$policy = new MtUniCreditSmartUcfEndpointPolicy();
mtuc9_assert(
    $policy->buildSessionStartUrl('https://onlinetest.ucfin.bg/suos/api/otp/')
        === 'https://onlinetest.ucfin.bg/suos/api/otp/sucfOnlineSessionStart',
    'endpoint: trusted test session start URL'
);
mtuc9_assert(
    $policy->buildSessionStartUrl('https://online.ucfin.bg/suos/api/otp/')
        === 'https://online.ucfin.bg/suos/api/otp/sucfOnlineSessionStart',
    'endpoint: trusted production session start URL'
);
$untrustedCaught = false;
try {
    $policy->buildSessionStartUrl('https://evil.example/suos/api/otp/');
} catch (InvalidArgumentException $exception) {
    $untrustedCaught = true;
}
mtuc9_assert($untrustedCaught, 'endpoint: untrusted host rejected');
$httpCaught = false;
try {
    $policy->assertTrustedApplicationBase('http://onlinetest.ucfin.bg/sucf-online/Request/Start');
} catch (InvalidArgumentException $exception) {
    $httpCaught = true;
}
mtuc9_assert($httpCaught, 'endpoint: non-HTTPS rejected');
mtuc9_assert(
    $policy->isTrustedApplicationRedirect(
        'https://onlinetest.ucfin.bg/sucf-online/Request/Start/' . Phase9TestHarness::SESSION_ID
    ),
    'endpoint: trusted application redirect accepted'
);
mtuc9_assert(
    !$policy->isTrustedApplicationRedirect('https://evil.example/sucf-online/Request/Start/abc'),
    'endpoint: untrusted redirect rejected'
);

// ---------------------------------------------------------------------------
// Certificate missing when uni_sertificat=1 → no SmartUCF HTTP; no bank_sent_process1
// ---------------------------------------------------------------------------
$transportCert = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportCert);
$stackCert = Phase9TestHarness::stack(
    $transportCert,
    null,
    null,
    Phase5TestHarness::STORE_A,
    array('uni_sertificat' => 1)
);
$orderCert = 9601;
Phase9TestHarness::seedBankOrder($stackCert['memoryDb'], $orderCert, $stackCert['storeId']);
$resultCert = $stackCert['submission']->submit(Phase9TestHarness::submitInput($orderCert, $stackCert['storeId']));
mtuc9_assert(empty($resultCert['success']), 'missing cert: overall failure');
mtuc9_assert((int) $resultCert['control_panel_order_id'] === 555001, 'missing cert: CP id preserved');
mtuc9_assert(Phase9TestHarness::smartUcfCallCount($stackCert['smartUcfProbe']) === 0, 'missing cert: no SmartUCF HTTP call');
mtuc9_assert(
    Phase9TestHarness::bankStatusId($stackCert, $orderCert) !== MtUniCreditBankStatus::SENT_PROCESS1,
    'missing cert: no bank_sent_process1'
);
mtuc9_assert(
    Phase9TestHarness::bankStatusId($stackCert, $orderCert) !== MtUniCreditBankStatus::SEND_FAILED_SMARTUCF,
    'missing cert: no bank_send_failed_smartucf (pre-send)'
);
$attemptCert = $stackCert['attempts']->findByStoreOrder($stackCert['storeId'], $orderCert);
$smartCert = $attemptCert !== null
    ? $stackCert['smartUcfLifecycle']->findByAttempt((int) $attemptCert['attempt_id'])
    : null;
mtuc9_assert(
    $smartCert !== null
        && (string) $smartCert['smartucf_error_class'] === MtUniCreditSmartUcfSessionCoordinator::ERROR_CERTIFICATE_INVALID,
    'missing cert: smartucf_certificate_invalid'
);

// ---------------------------------------------------------------------------
// Privacy: no SSLKEYPASSWD logging; missing-cert exceptions do not embed PEM
// ---------------------------------------------------------------------------
$clientSource = mtuc9_read_source($lib . DIRECTORY_SEPARATOR . 'smart_ucf_session_client.php');
$coordSource = mtuc9_read_source($lib . DIRECTORY_SEPARATOR . 'smart_ucf_session_coordinator.php');
mtuc9_assert(strpos($clientSource, 'SSLKEYPASSWD') !== false, 'privacy: client sets CURLOPT_SSLKEYPASSWD');
mtuc9_assert(strpos($coordSource, 'SSLKEYPASSWD') === false, 'privacy: coordinator has no SSLKEYPASSWD');
mtuc9_assert(
    !preg_match('/error_log\s*\(.*SSLKEYPASSWD|SSLKEYPASSWD.*error_log|log\(.*SSLKEYPASSWD/i', $clientSource . $coordSource),
    'privacy: SSLKEYPASSWD not logged'
);
mtuc9_assert(strpos($clientSource . $coordSource, 'BEGIN PRIVATE KEY') === false, 'privacy: no PEM private key literal in client/coordinator');
mtuc9_assert(strpos($clientSource . $coordSource, 'BEGIN RSA PRIVATE KEY') === false, 'privacy: no RSA PEM literal in client/coordinator');
mtuc9_assert(
    strpos($clientSource, 'raw_request') !== false,
    'privacy: raw_request may exist on return value but is not a required log path'
);
mtuc9_assert(
    strpos($coordSource, 'raw_request') === false,
    'privacy: coordinator does not persist/log raw_request'
);

// ---------------------------------------------------------------------------
// Wiring sources: Product/Cart/Checkout share the same post-CP lifecycle service
// ---------------------------------------------------------------------------
$storefrontRuntimeSource = mtuc9_read_source($lib . DIRECTORY_SEPARATOR . 'storefront_runtime.php');
$productControllerSource = mtuc9_read_source(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'product.php'
);
$cartControllerSource = mtuc9_read_source(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'cart.php'
);
$checkoutModelSource = mtuc9_read_source(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'model' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'payment'
        . DIRECTORY_SEPARATOR . 'mt_uni_credit.php'
);
$lifecycleSource = mtuc9_read_source($lib . DIRECTORY_SEPARATOR . 'control_panel_order_lifecycle_service.php');
mtuc9_assert(
    strpos($productControllerSource, 'MtUniCreditStorefrontRuntime::submissionService($this)') !== false,
    'wiring source: Product submit uses storefront submissionService'
);
mtuc9_assert(
    strpos($cartControllerSource, 'MtUniCreditStorefrontRuntime::submissionService($this)') !== false,
    'wiring source: Cart submit uses storefront submissionService'
);
mtuc9_assert(
    strpos($storefrontRuntimeSource, 'new MtUniCreditControlPanelOrderLifecycleService(') !== false,
    'wiring source: storefront runtime builds shared lifecycle service'
);
mtuc9_assert(
    strpos($checkoutModelSource, 'new MtUniCreditControlPanelOrderLifecycleService(') !== false,
    'wiring source: checkout model builds shared lifecycle service'
);
mtuc9_assert(
    strpos($lifecycleSource, 'MtUniCreditPhase9LifecycleLog::EVENT_PROCESS_RAW') !== false
        && strpos($lifecycleSource, '$coordinator->run(') !== false,
    'wiring source: cp_created path logs process and runs Process1 coordinator'
);

// ---------------------------------------------------------------------------
// PHP 7.3: forbidden modern syntax tokens in Phase 9 library files
// ---------------------------------------------------------------------------
$phase9Files = array(
    'smart_ucf_endpoint_policy.php',
    'smart_ucf_session_exception.php',
    'smart_ucf_lifecycle_states.php',
    'smart_ucf_failure_classification.php',
    'smart_ucf_failure_classifier.php',
    'smart_ucf_payload_builder.php',
    'smart_ucf_session_client.php',
    'smart_ucf_coordination_result.php',
    'smart_ucf_lifecycle_repository.php',
    'smart_ucf_session_coordinator.php',
    'certificate_local_paths.php',
    'certificate_pair_validator.php',
    'mtls_private_key_passphrase_provider.php',
    'process1_service_factory.php',
    'bank_status.php',
);
$forbiddenTokens = array(
    'str_contains',
    'str_starts_with',
    '?' . '->',
    '#' . '[',
    'public ' . 'string $',
    'fn' . '(',
);
foreach ($phase9Files as $phase9File) {
    $source = mtuc9_read_source($lib . DIRECTORY_SEPARATOR . $phase9File);
    foreach ($forbiddenTokens as $token) {
        mtuc9_assert(
            strpos($source, $token) === false,
            'PHP 7.3: ' . $phase9File . ' free of ' . $token
        );
    }
    // match expression (not preg_match / array_match helpers)
    mtuc9_assert(
        !preg_match('/(?<![\w$])match\s*\(/', $source),
        'PHP 7.3: ' . $phase9File . ' free of match expression'
    );
}

echo PHP_EOL . 'Phase 9 checks: ' . $passes . ' passed';
if ($failures) {
    echo ', ' . count($failures) . ' failed' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo ', 0 failed' . PHP_EOL;
exit(0);
