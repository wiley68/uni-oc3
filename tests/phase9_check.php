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
if (!defined('DIR_STORAGE')) {
    $storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mtuc-phase9-storage';
    if (!is_dir($storage)) {
        @mkdir($storage, 0770, true);
    }
    if (!is_dir($storage . DIRECTORY_SEPARATOR . 'mt_uni_credit')) {
        @mkdir($storage . DIRECTORY_SEPARATOR . 'mt_uni_credit', 0770, true);
    }
    define('DIR_STORAGE', rtrim($storage, '/\\') . DIRECTORY_SEPARATOR);
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

function mtuc9_cert_fixtures()
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'certificates';

    return array(
        'cert' => mtuc9_read_source($dir . DIRECTORY_SEPARATOR . 'matching_cert.pem'),
        'key' => mtuc9_read_source($dir . DIRECTORY_SEPARATOR . 'matching_key.pem'),
        'other_key' => mtuc9_read_source($dir . DIRECTORY_SEPARATOR . 'other_key.pem'),
        'invalid_cert' => mtuc9_read_source($dir . DIRECTORY_SEPARATOR . 'invalid_cert.pem'),
    );
}

function mtuc9_queue_ssl_metadata(Phase4FakeCpHttpTransport $transport, string $certPem, string $keyPem): void
{
    $transport->enqueueJson(200, array(
        'success' => true,
        'data' => array(
            'available' => true,
            'ssl_revision' => 'revision-1',
            'certificate_sha256' => hash('sha256', $certPem),
            'private_key_sha256' => hash('sha256', $keyPem),
        ),
    ));
}

function mtuc9_queue_ssl_bundle(Phase4FakeCpHttpTransport $transport, string $certPem, string $keyPem): void
{
    $transport->enqueueJson(200, array(
        'success' => true,
        'data' => array(
            'available' => true,
            'ssl_revision' => 'revision-1',
            'certificate_sha256' => hash('sha256', $certPem),
            'private_key_sha256' => hash('sha256', $keyPem),
            'certificate_pem' => $certPem,
            'private_key_pem' => $keyPem,
        ),
    ));
}

function mtuc9_count_ssl_bundle_gets(Phase4FakeCpHttpTransport $transport)
{
    $count = 0;
    foreach ($transport->requests as $request) {
        if (
            strtoupper((string) $request['method']) === 'GET'
            && substr((string) $request['url'], -23) === '/ssl/certificate/bundle'
        ) {
            $count++;
        }
    }

    return $count;
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
    'certificate_sync_exception.php',
    'certificate_consumer_lease.php',
    'certificate_local_store.php',
    'certificate_synchronizer.php',
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
mtuc9_assert(!empty($resultP2['apply_native_order_status']), 'Process2: apply_native after CP (no SmartUCF)');
mtuc9_assert(empty($resultP2['bank_redirect']), 'Process2: no bank_redirect');
mtuc9_assert(Phase9TestHarness::countStatusPatches($transportP2) === 0, 'Process2: no Process1 bank status PATCH');

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
mtuc9_assert(Phase9TestHarness::countStatusPatches($transportOk) === 1, 'Process1 success: CP status PATCH calls = 1');
$patchOk = Phase9TestHarness::lastStatusPatchPayload($transportOk);
mtuc9_assert(is_array($patchOk), 'Process1 success: CP PATCH payload present');
mtuc9_assert(
    is_array($patchOk) && (string) $patchOk['status_id'] === MtUniCreditBankStatus::SENT_PROCESS1,
    'Process1 success: CP PATCH payload status = bank_sent_process1'
);
mtuc9_assert(
    is_array($patchOk) && (string) $patchOk['status'] === MtUniCreditBankStatus::LABEL_SENT_PROCESS1,
    'Process1 success: CP PATCH payload label = Изпратен Банка - Процес 1'
);
mtuc9_assert(
    is_array($patchOk) && (string) $patchOk['order_id'] === substr((string) $orderOk, 0, 13),
    'Process1 success: CP PATCH uses shop order_id not CP PK'
);
mtuc9_assert(!empty($resultOk['bank_redirect']), 'Process1 success: bank_redirect flag');
mtuc9_assert(!empty($resultOk['apply_native_order_status']), 'Process1 success: apply_native_order_status once-authorised');
mtuc9_assert(!empty($resultOk['cp_succeeded']), 'Process1 success: cp_succeeded true');

// Replay after Process1 success: no duplicate native-status authorization
$resultOkReplay = $stackOk['submission']->submit(Phase9TestHarness::submitInput($orderOk, $stackOk['storeId']));
mtuc9_assert(!empty($resultOkReplay['success']), 'Process1 replay: still success');
mtuc9_assert(empty($resultOkReplay['apply_native_order_status']), 'Process1 replay: no duplicate apply_native_order_status');
mtuc9_assert(Phase9TestHarness::smartUcfCallCount($stackOk['smartUcfProbe']) === 1, 'Process1 replay: still one SmartUCF call');
mtuc9_assert(Phase7TestHarness::countOrderPosts($transportOk) === 1, 'Process1 replay: still one CP POST');
mtuc9_assert(
    (string) $resultOkReplay['redirect'] === (string) $resultOk['redirect'],
    'Process1 replay: stored bank redirect reused'
);
mtuc9_assert(
    Phase9TestHarness::countStatusPatches($transportOk) >= 1,
    'Process1 replay: CP status sync safely converges (may retry PATCH)'
);
mtuc9_assert(
    Phase9TestHarness::bankStatusId($stackOk, $orderOk) === MtUniCreditBankStatus::SENT_PROCESS1,
    'Process1 replay: local bank_sent_process1 preserved'
);

// ---------------------------------------------------------------------------
// CP PATCH failure after SmartUCF success: durable created; recoverable sync; no second SmartUCF/CP create
// ---------------------------------------------------------------------------
$transportPatchFail = new Phase4FakeCpHttpTransport();
$transportPatchFail->failStatusPatch = true;
Phase9TestHarness::enqueueCpCreateSuccess($transportPatchFail);
$stackPatchFail = Phase9TestHarness::stack($transportPatchFail);
$orderPatchFail = 9102;
Phase9TestHarness::seedBankOrder($stackPatchFail['memoryDb'], $orderPatchFail, $stackPatchFail['storeId']);
$resultPatchFail = $stackPatchFail['submission']->submit(
    Phase9TestHarness::submitInput($orderPatchFail, $stackPatchFail['storeId'])
);
mtuc9_assert(!empty($resultPatchFail['success']), 'CP PATCH fail: overall success still true (SmartUCF durable)');
mtuc9_assert(!empty($resultPatchFail['redirect']), 'CP PATCH fail: bank redirect preserved');
mtuc9_assert(
    Phase9TestHarness::bankStatusId($stackPatchFail, $orderPatchFail) === MtUniCreditBankStatus::SENT_PROCESS1,
    'CP PATCH fail: local bank_sent_process1 remains'
);
$attemptPatchFail = $stackPatchFail['attempts']->findByStoreOrder($stackPatchFail['storeId'], $orderPatchFail);
$smartPatchFail = $attemptPatchFail !== null
    ? $stackPatchFail['smartUcfLifecycle']->findByAttempt((int) $attemptPatchFail['attempt_id'])
    : null;
mtuc9_assert(
    $smartPatchFail !== null
        && (string) $smartPatchFail['smartucf_state'] === MtUniCreditSmartUcfLifecycleStates::CREATED,
    'CP PATCH fail: smartucf_state remains created'
);
mtuc9_assert(Phase9TestHarness::smartUcfCallCount($stackPatchFail['smartUcfProbe']) === 1, 'CP PATCH fail: one SmartUCF call');
mtuc9_assert(Phase7TestHarness::countOrderPosts($transportPatchFail) === 1, 'CP PATCH fail: one CP create');
mtuc9_assert(Phase9TestHarness::countStatusPatches($transportPatchFail) === 1, 'CP PATCH fail: one failed PATCH attempt');
$transportPatchFail->failStatusPatch = false;
$resultPatchRecover = $stackPatchFail['submission']->submit(
    Phase9TestHarness::submitInput($orderPatchFail, $stackPatchFail['storeId'])
);
mtuc9_assert(!empty($resultPatchRecover['success']), 'CP PATCH recover: replay success');
mtuc9_assert(
    (string) $resultPatchRecover['redirect'] === (string) $resultPatchFail['redirect'],
    'CP PATCH recover: stored redirect reused'
);
mtuc9_assert(Phase9TestHarness::smartUcfCallCount($stackPatchFail['smartUcfProbe']) === 1, 'CP PATCH recover: no second SmartUCF');
mtuc9_assert(Phase7TestHarness::countOrderPosts($transportPatchFail) === 1, 'CP PATCH recover: no second CP create');
mtuc9_assert(
    Phase9TestHarness::countStatusPatches($transportPatchFail) >= 2,
    'CP PATCH recover: status sync retried'
);
$patchRecover = Phase9TestHarness::lastStatusPatchPayload($transportPatchFail);
mtuc9_assert(
    is_array($patchRecover) && (string) $patchRecover['status_id'] === MtUniCreditBankStatus::SENT_PROCESS1,
    'CP PATCH recover: final payload bank_sent_process1'
);

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
mtuc9_assert(Phase9TestHarness::countStatusPatches($transportStorefrontP2) === 0, 'storefront P2: no Process1 bank status PATCH');

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
mtuc9_assert(Phase9TestHarness::countStatusPatches($transportReject) === 1, 'SmartUCF reject: CP status PATCH = 1');
$patchReject = Phase9TestHarness::lastStatusPatchPayload($transportReject);
mtuc9_assert(
    is_array($patchReject) && (string) $patchReject['status_id'] === MtUniCreditBankStatus::SEND_FAILED_SMARTUCF,
    'SmartUCF reject: CP PATCH status_id = bank_send_failed_smartucf'
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
mtuc9_assert(!empty($resultReject['cp_succeeded']), 'SmartUCF reject: cp_succeeded still true');
mtuc9_assert(
    !empty($resultReject['apply_native_order_status']),
    'SmartUCF reject: apply_native authorised (OC4 terminal remote_reject)'
);
mtuc9_assert(empty($resultReject['bank_redirect']), 'SmartUCF reject: no bank_redirect');

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
mtuc9_assert(!empty($resultTimeout['cp_succeeded']), 'timeout: cp_succeeded true');
mtuc9_assert(empty($resultTimeout['apply_native_order_status']), 'timeout: no premature apply_native_order_status');
mtuc9_assert(empty($secondTimeout['apply_native_order_status']), 'timeout replay: still no apply_native');
mtuc9_assert(Phase9TestHarness::countStatusPatches($transportTimeout) === 0, 'timeout: no bank_sent_process1 CP PATCH');
mtuc9_assert(
    Phase9TestHarness::countStatusPatches($transportTimeout) === 0,
    'timeout: no bank_send_failed_smartucf CP PATCH'
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
// Certificate synchronization contract (OC4 parity adapted for OC3)
// ---------------------------------------------------------------------------
$fixtures = mtuc9_cert_fixtures();

// A. Empty keys directory => metadata + bundle => pair downloaded, SmartUCF proceeds.
$transportCertA = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportCertA);
mtuc9_queue_ssl_metadata($transportCertA, $fixtures['cert'], $fixtures['key']);
mtuc9_queue_ssl_bundle($transportCertA, $fixtures['cert'], $fixtures['key']);
$stackCertA = Phase9TestHarness::stack(
    $transportCertA,
    null,
    null,
    Phase5TestHarness::STORE_A,
    array('uni_sertificat' => 1)
);
$protectedRootA = MtUniCreditBootstrap::resolveProtectedRoot();
$protectedRootA = is_string($protectedRootA) && $protectedRootA !== ''
    ? $protectedRootA
    : (rtrim(DIR_STORAGE, '/\\') . DIRECTORY_SEPARATOR . 'mt_uni_credit');
$keysRootA = $protectedRootA . DIRECTORY_SEPARATOR . 'keys';
if (!is_dir($keysRootA)) {
    @mkdir($keysRootA, 0770, true);
}
$pathsA = new MtUniCreditCertificateLocalPaths(function () use ($protectedRootA) {
    return $protectedRootA;
});
@unlink($pathsA->certificatePath());
@unlink($pathsA->privateKeyPath());
$secretsDirA = dirname($pathsA->passphrasePath());
if (!is_dir($secretsDirA)) {
    @mkdir($secretsDirA, 0770, true);
}
@file_put_contents($pathsA->passphrasePath(), "<?php\nreturn array('passphrase' => 'phase2-fixture-secret');\n");
@chmod($pathsA->passphrasePath(), 0600);
$orderCertA = 9601;
Phase9TestHarness::seedBankOrder($stackCertA['memoryDb'], $orderCertA, $stackCertA['storeId']);
$resultCertA = $stackCertA['submission']->submit(Phase9TestHarness::submitInput($orderCertA, $stackCertA['storeId']));
mtuc9_assert(!empty($resultCertA['success']), 'cert sync A: submit succeeds with empty keys');
mtuc9_assert((int) $resultCertA['control_panel_order_id'] === 555001, 'cert sync A: CP id preserved');
mtuc9_assert(Phase9TestHarness::smartUcfCallCount($stackCertA['smartUcfProbe']) === 1, 'cert sync A: SmartUCF called');
mtuc9_assert(is_file($pathsA->certificatePath()) && is_file($pathsA->privateKeyPath()), 'cert sync A: files downloaded');
mtuc9_assert(
    hash_equals(hash('sha256', mtuc9_read_source($pathsA->certificatePath())), hash('sha256', $fixtures['cert'])),
    'cert sync A: certificate checksum matches metadata'
);
mtuc9_assert(
    hash_equals(hash('sha256', mtuc9_read_source($pathsA->privateKeyPath())), hash('sha256', $fixtures['key'])),
    'cert sync A: private-key checksum matches metadata'
);

// B. Cert exists, key missing => download full pair.
$transportCertB = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportCertB);
mtuc9_queue_ssl_metadata($transportCertB, $fixtures['cert'], $fixtures['key']);
mtuc9_queue_ssl_bundle($transportCertB, $fixtures['cert'], $fixtures['key']);
$stackCertB = Phase9TestHarness::stack($transportCertB, null, null, Phase5TestHarness::STORE_A, array('uni_sertificat' => 1));
@file_put_contents($pathsA->certificatePath(), $fixtures['cert']);
@unlink($pathsA->privateKeyPath());
Phase9TestHarness::seedBankOrder($stackCertB['memoryDb'], 9602, $stackCertB['storeId']);
$resultCertB = $stackCertB['submission']->submit(Phase9TestHarness::submitInput(9602, $stackCertB['storeId']));
mtuc9_assert(!empty($resultCertB['success']), 'cert sync B: succeeds when key missing');
mtuc9_assert(mtuc9_count_ssl_bundle_gets($transportCertB) === 1, 'cert sync B: full bundle downloaded once');

// C. Current pair => metadata only, no bundle download.
$transportCertC = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportCertC);
mtuc9_queue_ssl_metadata($transportCertC, $fixtures['cert'], $fixtures['key']);
$stackCertC = Phase9TestHarness::stack($transportCertC, null, null, Phase5TestHarness::STORE_A, array('uni_sertificat' => 1));
@file_put_contents($pathsA->certificatePath(), $fixtures['cert']);
@file_put_contents($pathsA->privateKeyPath(), $fixtures['key']);
Phase9TestHarness::seedBankOrder($stackCertC['memoryDb'], 9603, $stackCertC['storeId']);
$resultCertC = $stackCertC['submission']->submit(Phase9TestHarness::submitInput(9603, $stackCertC['storeId']));
mtuc9_assert(!empty($resultCertC['success']), 'cert sync C: succeeds with current pair');
mtuc9_assert(mtuc9_count_ssl_bundle_gets($transportCertC) === 0, 'cert sync C: no bundle download');

// D/E. Certificate or key checksum changed => bundle download and replace pair.
$transportCertD = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportCertD);
mtuc9_queue_ssl_metadata($transportCertD, $fixtures['cert'], $fixtures['key']);
mtuc9_queue_ssl_bundle($transportCertD, $fixtures['cert'], $fixtures['key']);
$stackCertD = Phase9TestHarness::stack($transportCertD, null, null, Phase5TestHarness::STORE_A, array('uni_sertificat' => 1));
@file_put_contents($pathsA->certificatePath(), $fixtures['cert'] . "\n");
@file_put_contents($pathsA->privateKeyPath(), $fixtures['key']);
Phase9TestHarness::seedBankOrder($stackCertD['memoryDb'], 9604, $stackCertD['storeId']);
$resultCertD = $stackCertD['submission']->submit(Phase9TestHarness::submitInput(9604, $stackCertD['storeId']));
mtuc9_assert(!empty($resultCertD['success']), 'cert sync D: cert checksum drift refreshes');
mtuc9_assert(mtuc9_count_ssl_bundle_gets($transportCertD) === 1, 'cert sync D: bundle download = 1');

$transportCertE = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportCertE);
mtuc9_queue_ssl_metadata($transportCertE, $fixtures['cert'], $fixtures['key']);
mtuc9_queue_ssl_bundle($transportCertE, $fixtures['cert'], $fixtures['key']);
$stackCertE = Phase9TestHarness::stack($transportCertE, null, null, Phase5TestHarness::STORE_A, array('uni_sertificat' => 1));
@file_put_contents($pathsA->certificatePath(), $fixtures['cert']);
@file_put_contents($pathsA->privateKeyPath(), $fixtures['key'] . "\n");
Phase9TestHarness::seedBankOrder($stackCertE['memoryDb'], 9605, $stackCertE['storeId']);
$resultCertE = $stackCertE['submission']->submit(Phase9TestHarness::submitInput(9605, $stackCertE['storeId']));
mtuc9_assert(!empty($resultCertE['success']), 'cert sync E: key checksum drift refreshes');
mtuc9_assert(mtuc9_count_ssl_bundle_gets($transportCertE) === 1, 'cert sync E: bundle download = 1');

// F/G/H/J: invalid bundle, mismatch, missing passphrase, metadata transient without pair => no SmartUCF call.
$transportCertF = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportCertF);
mtuc9_queue_ssl_metadata($transportCertF, $fixtures['cert'], $fixtures['key']);
mtuc9_queue_ssl_bundle($transportCertF, $fixtures['invalid_cert'], $fixtures['key']);
$stackCertF = Phase9TestHarness::stack($transportCertF, null, null, Phase5TestHarness::STORE_A, array('uni_sertificat' => 1));
@unlink($pathsA->certificatePath());
@unlink($pathsA->privateKeyPath());
Phase9TestHarness::seedBankOrder($stackCertF['memoryDb'], 9606, $stackCertF['storeId']);
$resultCertF = $stackCertF['submission']->submit(Phase9TestHarness::submitInput(9606, $stackCertF['storeId']));
mtuc9_assert(empty($resultCertF['success']), 'cert sync F: invalid downloaded cert fails');
mtuc9_assert(empty($resultCertF['apply_native_order_status']), 'cert sync F: no premature apply_native');
mtuc9_assert(!empty($resultCertF['cp_succeeded']), 'cert sync F: cp_succeeded does not imply apply_native');
mtuc9_assert(Phase9TestHarness::smartUcfCallCount($stackCertF['smartUcfProbe']) === 0, 'cert sync F: no SmartUCF call');

$transportCertG = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportCertG);
mtuc9_queue_ssl_metadata($transportCertG, $fixtures['cert'], $fixtures['key']);
mtuc9_queue_ssl_bundle($transportCertG, $fixtures['cert'], $fixtures['other_key']);
$stackCertG = Phase9TestHarness::stack($transportCertG, null, null, Phase5TestHarness::STORE_A, array('uni_sertificat' => 1));
Phase9TestHarness::seedBankOrder($stackCertG['memoryDb'], 9607, $stackCertG['storeId']);
$resultCertG = $stackCertG['submission']->submit(Phase9TestHarness::submitInput(9607, $stackCertG['storeId']));
mtuc9_assert(empty($resultCertG['success']), 'cert sync G: mismatched cert/key fails');
mtuc9_assert(Phase9TestHarness::smartUcfCallCount($stackCertG['smartUcfProbe']) === 0, 'cert sync G: no SmartUCF call');

@unlink($pathsA->passphrasePath());
$transportCertH = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportCertH);
$stackCertH = Phase9TestHarness::stack($transportCertH, null, null, Phase5TestHarness::STORE_A, array('uni_sertificat' => 1));
Phase9TestHarness::seedBankOrder($stackCertH['memoryDb'], 9608, $stackCertH['storeId']);
$resultCertH = $stackCertH['submission']->submit(Phase9TestHarness::submitInput(9608, $stackCertH['storeId']));
mtuc9_assert(empty($resultCertH['success']), 'cert sync H: missing passphrase fails deterministically');
mtuc9_assert(empty($resultCertH['apply_native_order_status']), 'cert sync H: no premature apply_native');
mtuc9_assert(Phase9TestHarness::smartUcfCallCount($stackCertH['smartUcfProbe']) === 0, 'cert sync H: no SmartUCF call');
@file_put_contents($pathsA->passphrasePath(), "<?php\nreturn array('passphrase' => 'phase2-fixture-secret');\n");
@chmod($pathsA->passphrasePath(), 0600);

$transportCertJ = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportCertJ);
$transportCertJ->enqueueJson(503, array('success' => false, 'error' => 'temporarily_unavailable'));
$stackCertJ = Phase9TestHarness::stack($transportCertJ, null, null, Phase5TestHarness::STORE_A, array('uni_sertificat' => 1));
@unlink($pathsA->certificatePath());
@unlink($pathsA->privateKeyPath());
Phase9TestHarness::seedBankOrder($stackCertJ['memoryDb'], 9609, $stackCertJ['storeId']);
$resultCertJ = $stackCertJ['submission']->submit(Phase9TestHarness::submitInput(9609, $stackCertJ['storeId']));
mtuc9_assert(empty($resultCertJ['success']), 'cert sync J: metadata transient + no local pair fails');
mtuc9_assert(Phase9TestHarness::smartUcfCallCount($stackCertJ['smartUcfProbe']) === 0, 'cert sync J: no SmartUCF call');

// I. Metadata transient + valid local pair => fail-open use local pair.
$transportCertI = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportCertI);
$transportCertI->enqueueJson(503, array('success' => false, 'error' => 'temporarily_unavailable'));
$stackCertI = Phase9TestHarness::stack($transportCertI, null, null, Phase5TestHarness::STORE_A, array('uni_sertificat' => 1));
@file_put_contents($pathsA->certificatePath(), $fixtures['cert']);
@file_put_contents($pathsA->privateKeyPath(), $fixtures['key']);
Phase9TestHarness::seedBankOrder($stackCertI['memoryDb'], 9610, $stackCertI['storeId']);
$resultCertI = $stackCertI['submission']->submit(Phase9TestHarness::submitInput(9610, $stackCertI['storeId']));
mtuc9_assert(!empty($resultCertI['success']), 'cert sync I: metadata transient + valid local pair proceeds');
mtuc9_assert(Phase9TestHarness::smartUcfCallCount($stackCertI['smartUcfProbe']) === 1, 'cert sync I: SmartUCF proceeds with local pair');

// No bank_sent_process1 on sync/pre-call failure paths.
mtuc9_assert(
    Phase9TestHarness::bankStatusId($stackCertF, 9606) !== MtUniCreditBankStatus::SENT_PROCESS1
        && Phase9TestHarness::bankStatusId($stackCertG, 9607) !== MtUniCreditBankStatus::SENT_PROCESS1
        && Phase9TestHarness::bankStatusId($stackCertH, 9608) !== MtUniCreditBankStatus::SENT_PROCESS1
        && Phase9TestHarness::bankStatusId($stackCertJ, 9609) !== MtUniCreditBankStatus::SENT_PROCESS1,
    'cert sync failures: no bank_sent_process1 before SmartUCF'
);
mtuc9_assert(
    Phase9TestHarness::countStatusPatches($transportCertF) === 0
        && Phase9TestHarness::countStatusPatches($transportCertG) === 0
        && Phase9TestHarness::countStatusPatches($transportCertH) === 0
        && Phase9TestHarness::countStatusPatches($transportCertJ) === 0,
    'cert/pre-send failure: CP bank-status PATCH = 0'
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
$cpClientSource = mtuc9_read_source($lib . DIRECTORY_SEPARATOR . 'control_panel_client.php');
mtuc9_assert(
    strpos($cpClientSource, 'function updateOrderStatus') !== false
        && strpos($cpClientSource, "'PATCH', '/orders/status'") !== false,
    'wiring source: ControlPanelClient PATCH /orders/status'
);
mtuc9_assert(
    strpos($coordSource, 'updateOrderStatus(') !== false
        && strpos($coordSource, 'ERROR_CP_BANK_STATUS_SYNC_PENDING') !== false,
    'wiring source: coordinator propagates CP bank status after SmartUCF'
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
    'certificate_sync_exception.php',
    'certificate_consumer_lease.php',
    'certificate_local_store.php',
    'certificate_synchronizer.php',
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

// Controllers must not apply native status from cp_succeeded alone.
$storefrontSrc = mtuc9_read_source($lib . DIRECTORY_SEPARATOR . 'storefront_financing_submission_service.php');
$checkoutSrc = mtuc9_read_source($lib . DIRECTORY_SEPARATOR . 'checkout_financing_submission_service.php');
mtuc9_assert(
    strpos($storefrontSrc, 'apply_native_order_status\' => $result->applyNativeOrderStatus') !== false
        || strpos($storefrontSrc, "'apply_native_order_status' => \$result->applyNativeOrderStatus") !== false,
    'storefront: apply_native from result.applyNativeOrderStatus'
);
mtuc9_assert(
    strpos($storefrontSrc, 'cpSucceeded && !$result->localReplay') === false,
    'storefront: does not map apply_native from cpSucceeded'
);
mtuc9_assert(
    strpos($checkoutSrc, 'cpSucceeded && !$result->localReplay') === false,
    'checkout: does not map apply_native from cpSucceeded'
);
$jsSrc = mtuc9_read_source(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'view' . DIRECTORY_SEPARATOR . 'theme' . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR
        . 'template' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'storefront.js'
);
mtuc9_assert(strpos($jsSrc, 'response.redirect') !== false, 'storefront.js navigates via response.redirect');
mtuc9_assert(
    preg_match('/if\s*\(\s*response\.redirect\s*\)/', $jsSrc) === 1,
    'storefront.js prioritises redirect before generic success close'
);
mtuc9_assert(strpos($jsSrc, 'terminalSubmitInFlight') !== false, 'storefront.js terminalSubmitInFlight lock');
mtuc9_assert(strpos($jsSrc, 'isTerminalSubmitLocked') !== false, 'storefront.js isTerminalSubmitLocked');
mtuc9_assert(strpos($jsSrc, 'window.location.assign') !== false, 'storefront.js uses location.assign for bank redirect');

// Redirect response: processing stays locked until navigation (no setProcessing(false) before assign).
$submitCbMatch = preg_match(
    '/submit:\s*function\s*\(\s*\)\s*\{(.*?)scheduleCalculate:\s*scheduleCalculate/s',
    $jsSrc,
    $submitCbParts
);
mtuc9_assert($submitCbMatch === 1, 'storefront.js submit handler extractable');
$submitBody = $submitCbMatch === 1 ? $submitCbParts[1] : '';
mtuc9_assert(
    strpos($submitBody, 'terminalSubmitInFlight = true') !== false
        && strpos($submitBody, 'setProcessing(true)') !== false,
    'redirect path: processing starts on terminal submit'
);
mtuc9_assert(
    preg_match(
        '/if\s*\(\s*response\.redirect\s*\)\s*\{[^}]*window\.location\.assign\s*\(\s*String\s*\(\s*response\.redirect\s*\)\s*\)/s',
        $submitBody
    ) === 1,
    'redirect path: assign uses response.redirect URL'
);
mtuc9_assert(
    preg_match(
        '/setProcessing\(false\);\s*if\s*\(\s*response\.redirect\s*\)/s',
        $submitBody
    ) !== 1,
    'redirect path: processing is NOT cleared before navigation'
);
mtuc9_assert(
    strpos($submitBody, 'if (isTerminalSubmitLocked())') !== false,
    'duplicate submit: early return while locked'
);
mtuc9_assert(
    preg_match(
        '/if\s*\(\s*err\s*\|\|\s*!response\s*\)\s*\{\s*terminalSubmitInFlight\s*=\s*false;\s*setProcessing\(false\);/s',
        $submitBody
    ) === 1,
    'error without redirect: processing clears on transport failure'
);
mtuc9_assert(
    preg_match(
        '/terminalSubmitInFlight\s*=\s*false;\s*setProcessing\(false\);\s*if\s*\(\s*response\.success\s*\)/s',
        $submitBody
    ) === 1,
    'error without redirect: unlock before success/error branch'
);
mtuc9_assert(
    strpos($jsSrc, 'Keep modal locked while Process 1 bank redirect') !== false
        || strpos($jsSrc, 'isTerminalSubmitLocked()') !== false,
    'dismiss while pending: closeModal ignores lock'
);
mtuc9_assert(
    preg_match(
        '/data-mtuc-dismiss[\s\S]*?isTerminalSubmitLocked\(\)/s',
        $jsSrc
    ) === 1,
    'dismiss while pending: overlay/X checks lock'
);
mtuc9_assert(
    preg_match(
        '/keydown\.mtuc[\s\S]*?isTerminalSubmitLocked\(\)/s',
        $jsSrc
    ) === 1,
    'dismiss while pending: Escape checks lock'
);
// Desired Phase 7/9 behaviour: existing local order + cp_created recover to SmartUCF —
// do not treat reuse as a bug or invent a second OC/CP order.
mtuc9_assert(
    strpos($jsSrc, 'Idempotent recovery (Phase 7/9)') !== false,
    'idempotent recovery: storefront comments preserve local/CP reuse'
);
mtuc9_assert(
    !empty($secondOk['success']) && !empty($secondOk['local_replay']) && !empty($secondOk['redirect']),
    'successful idempotent replay: no new local/CP path — redirect continues'
);

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
