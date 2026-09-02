<?php

/**
 * Phase 7 local financing attempt + CP order lifecycle checks.
 * Run: php tests/phase7_check.php
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/support/phase2_memory_db.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

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
require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

$failures = array();
$passes = 0;

function mtuc7_assert(bool $condition, string $message): void
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

$required = array(
    'financing_attempt_state.php',
    'financing_attempt_repository.php',
    'control_panel_error_class.php',
    'control_panel_order_payload_builder.php',
    'control_panel_order_submission_result.php',
    'control_panel_order_lifecycle_service.php',
    'checkout_financing_submission_service.php',
    'checkout_submit_token.php',
    'checkout_prepared_view_state.php',
);
foreach ($required as $file) {
    mtuc7_assert(is_file($lib . DIRECTORY_SEPARATOR . $file), 'required file: ' . $file);
}

/**
 * Extract a single method body from PHP class source (best-effort for source guards).
 *
 * @param string $source
 * @param string $methodName
 * @return string
 */
function mtuc7_extract_method($source, $methodName)
{
    $pattern = '/function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)\s*\{/';
    if (!preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE)) {
        return '';
    }
    $start = (int) $m[0][1] + strlen($m[0][0]);
    $depth = 1;
    $len = strlen($source);
    for ($i = $start; $i < $len; $i++) {
        $ch = $source[$i];
        if ($ch === '{') {
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $start, $i - $start);
            }
        }
    }

    return '';
}

$phase7Sql = MtUniCreditPersistenceSchema::createPhase7TableStatements('oc_');
mtuc7_assert(count($phase7Sql) === 1, 'phase 7 schema defines financing_attempt');
mtuc7_assert(strpos($phase7Sql[0], 'uniq_mt_uni_credit_store_order') !== false, 'unique (store_id, order_id)');
mtuc7_assert(strpos($phase7Sql[0], 'request_fingerprint') !== false, 'request_fingerprint column present');

$controllerSource = (string) file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'upload/catalog/controller/extension/payment/mt_uni_credit.php'
);
$modelSource = (string) file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'upload/catalog/model/extension/payment/mt_uni_credit.php'
);
mtuc7_assert(strpos($controllerSource, 'addOrder(') === false, 'controller never calls addOrder');
mtuc7_assert(strpos($modelSource, 'addOrder(') === false, 'model never calls addOrder');
mtuc7_assert(strpos($controllerSource, 'function submit(') !== false, 'controller exposes POST submit action');
mtuc7_assert(strpos($controllerSource, 'submitCheckoutFinancing') !== false, 'submit path delegates financing');
mtuc7_assert(strpos($modelSource, 'createOrder') === false, 'model does not call CP createOrder directly');
mtuc7_assert(
    MtUniCreditConstants::CHECKOUT_SUBMIT_ROUTE === 'extension/payment/mt_uni_credit/submit',
    'submit route constant frozen'
);

$preparedBody = mtuc7_extract_method($controllerSource, 'prepared');
$submitBody = mtuc7_extract_method($controllerSource, 'submit');
mtuc7_assert($preparedBody !== '', 'prepared() method extractable');
mtuc7_assert($submitBody !== '', 'submit() method extractable');
mtuc7_assert(strpos($preparedBody, 'submitCheckoutFinancing(') === false, 'GET prepared does not call submitCheckoutFinancing');
mtuc7_assert(strpos($preparedBody, 'createOrder(') === false, 'GET prepared does not call createOrder');
mtuc7_assert(strpos($preparedBody, 'addOrderHistory(') === false, 'GET prepared does not call addOrderHistory');
mtuc7_assert(strpos($submitBody, 'submitCheckoutFinancing(') !== false, 'POST submit calls submitCheckoutFinancing');
mtuc7_assert(strpos($submitBody, 'REQUEST_METHOD') !== false, 'POST submit guards HTTP method');
mtuc7_assert(strpos($submitBody, 'MtUniCreditCheckoutSubmitToken::verify') !== false, 'POST submit verifies CSRF token');
mtuc7_assert(strpos($submitBody, 'redirect') !== false, 'POST submit uses PRG redirect');

$twigSource = (string) file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'upload/catalog/view/theme/default/template/extension/payment/mt_uni_credit_prepared.twig'
);
mtuc7_assert(strpos($twigSource, 'method="post"') !== false, 'prepared template uses POST form');
mtuc7_assert(strpos($twigSource, 'mt_uni_credit_submit_token') !== false, 'prepared template includes CSRF token field');
mtuc7_assert(strpos($twigSource, 'can_submit') !== false, 'prepared template gates submit button');

$fixture = mtuc_phase0_load_fixture('cp_order_payload.json');
$builder = new MtUniCreditControlPanelOrderPayloadBuilder();
$calc = (new MtUniCreditCalculator())->calculateScheme(
    mtuc4_valid_shop_snapshot(),
    500.0,
    new MtUniCreditAvailableScheme(
        'standard',
        'KOPSTD',
        12,
        0,
        array(),
        array('coeff' => 1.05, 'interestPercent' => 5.5, 'installmentCount' => 12, 'onlineProductCode' => 'KOPSTD')
    ),
    0.0
);
$payload = $builder->build(
    Phase7TestHarness::ORDER_ID,
    Phase7TestHarness::orderRow(),
    Phase7TestHarness::orderProducts(),
    $calc,
    mtuc4_valid_shop_snapshot()
);
foreach ($fixture['ps9_create_field_order'] as $field) {
    mtuc7_assert(array_key_exists($field, $payload), 'payload field present: ' . $field);
}
mtuc7_assert(!isset($payload['status']) && !isset($payload['status_id']), 'create payload omits status/status_id');
mtuc7_assert($payload['version'] === '2.0.2', 'payload version frozen 2.0.2');
mtuc7_assert($payload['products_name'] === 'Example-Product', 'underscore in product name replaced');

// Attempt persistence + reuse
$transport = new Phase4FakeCpHttpTransport();
$payloads = Phase7TestHarness::loginAndOrderSuccessPayloads();
$transport->enqueueJson(200, $payloads['login']);
$transport->enqueueJson(201, $payloads['order']);
$stack = Phase7TestHarness::stack($transport);
$input = array(
    'store_id' => $stack['storeId'],
    'order_id' => Phase7TestHarness::ORDER_ID,
    'order' => Phase7TestHarness::orderRow(),
    'order_products' => Phase7TestHarness::orderProducts(),
    'cart_context' => Phase7TestHarness::cartContext(),
);
$first = $stack['submission']->submit($input);
mtuc7_assert(!empty($first['success']), 'first CP submit succeeds');
mtuc7_assert((int) $first['control_panel_order_id'] === 555001, 'CP order id persisted');
mtuc7_assert(Phase7TestHarness::countOrderPosts($transport) === 1, 'exactly one POST /orders on success');
$attempt = $stack['attempts']->findByStoreOrder($stack['storeId'], Phase7TestHarness::ORDER_ID);
mtuc7_assert($attempt !== null && $attempt['state'] === MtUniCreditFinancingAttemptState::CP_CREATED, 'attempt state cp_created');
mtuc7_assert($attempt['request_fingerprint'] !== '', 'fingerprint persisted');

$second = $stack['submission']->submit($input);
mtuc7_assert(!empty($second['success']) && !empty($second['local_replay']), 'revisit replays locally without new POST');
mtuc7_assert(Phase7TestHarness::countOrderPosts($transport) === 1, 'revisit does not POST /orders again');

$same = $stack['attempts']->findOrCreateCheckoutAttempt(
    $stack['storeId'],
    Phase7TestHarness::ORDER_ID,
    Phase4TestHarness::TEST_UNICID,
    $attempt['operation_key_hash'],
    $attempt['selection_hash'],
    $attempt['request_fingerprint']
);
mtuc7_assert((int) $same['attempt_id'] === (int) $attempt['attempt_id'], 'same order reuses attempt');

// Store isolation
$cross = $stack['attempts']->findByStoreOrder(Phase5TestHarness::STORE_B, Phase7TestHarness::ORDER_ID);
mtuc7_assert($cross === null, 'no cross-store attempt fallback');

// Definitive 4xx rejection
$transportReject = new Phase4FakeCpHttpTransport();
$transportReject->enqueueJson(200, $payloads['login']);
$transportReject->enqueueJson(422, array('success' => false, 'message' => 'invalid'));
$stackReject = Phase7TestHarness::stack($transportReject);
$rejectInput = $input;
$rejectInput['order_id'] = 7002;
$rejectInput['order'] = Phase7TestHarness::orderRow(7002);
$rejected = $stackReject['submission']->submit($rejectInput);
mtuc7_assert(empty($rejected['success']), 'definitive 422 fails');
mtuc7_assert(empty($rejected['ambiguous_blocked']), '422 is not ambiguous');
$rejectAttempt = $stackReject['attempts']->findByStoreOrder($stackReject['storeId'], 7002);
mtuc7_assert(
    $rejectAttempt !== null && $rejectAttempt['state'] === MtUniCreditFinancingAttemptState::CP_FAILED_RETRYABLE,
    'definitive failure state cp_failed_retryable'
);

// Ambiguous timeout STOP GATE
$transportTimeout = new Phase4FakeCpHttpTransport();
$transportTimeout->enqueueJson(200, $payloads['login']);
$transportTimeout->enqueueTimeout();
$stackTimeout = Phase7TestHarness::stack($transportTimeout);
$timeoutInput = $input;
$timeoutInput['order_id'] = 7003;
$timeoutInput['order'] = Phase7TestHarness::orderRow(7003);
$ambiguous = $stackTimeout['submission']->submit($timeoutInput);
mtuc7_assert(empty($ambiguous['success']), 'timeout submit fails');
mtuc7_assert(!empty($ambiguous['ambiguous_blocked']), 'timeout marked ambiguous blocked');
mtuc7_assert(Phase7TestHarness::countOrderPosts($transportTimeout) === 1, 'ambiguous STOP GATE: first POST happened');
$ambAttempt = $stackTimeout['attempts']->findByStoreOrder($stackTimeout['storeId'], 7003);
mtuc7_assert(
    $ambAttempt !== null && $ambAttempt['state'] === MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN,
    'timeout attempt state cp_outcome_unknown'
);
$secondAmbiguous = $stackTimeout['submission']->submit($timeoutInput);
mtuc7_assert(empty($secondAmbiguous['success']), 'second visit after ambiguous still fails');
mtuc7_assert(!empty($secondAmbiguous['ambiguous_blocked']), 'second visit remains ambiguous blocked');
mtuc7_assert(
    Phase7TestHarness::countOrderPosts($transportTimeout) === 1,
    'ambiguous STOP GATE: second submit does not POST /orders again'
);

// Connection loss after possible send
$transportConn = new Phase4FakeCpHttpTransport();
$transportConn->enqueueJson(200, $payloads['login']);
$transportConn->enqueueConnectionFailure();
$stackConn = Phase7TestHarness::stack($transportConn);
$connInput = $input;
$connInput['order_id'] = 7004;
$connInput['order'] = Phase7TestHarness::orderRow(7004);
$connResult = $stackConn['submission']->submit($connInput);
mtuc7_assert(!empty($connResult['ambiguous_blocked']), 'connection failure after send is ambiguous');
mtuc7_assert(Phase7TestHarness::countOrderPosts($transportConn) === 1, 'connection failure recorded one POST attempt');
$stackConn['submission']->submit($connInput);
mtuc7_assert(Phase7TestHarness::countOrderPosts($transportConn) === 1, 'connection ambiguous blocks second POST');

// Concurrent lock: second owner cannot acquire while first holds
$transportLock = new Phase4FakeCpHttpTransport();
$transportLock->enqueueJson(200, $payloads['login']);
$transportLock->enqueueJson(201, $payloads['order']);
$stackLock = Phase7TestHarness::stack($transportLock);
$opHash = hash('sha256', 'checkout|' . $stackLock['storeId'] . '|7010');
$tokenA = MtUniCreditLockOwnerTokenGenerator::generate();
$tokenB = MtUniCreditLockOwnerTokenGenerator::generate();
mtuc7_assert(
    $stackLock['locks']->acquire($stackLock['storeId'], MtUniCreditOperationEntryPoint::CHECKOUT, $opHash, $tokenA),
    'first lock owner acquires'
);
mtuc7_assert(
    !$stackLock['locks']->acquire($stackLock['storeId'], MtUniCreditOperationEntryPoint::CHECKOUT, $opHash, $tokenB),
    'second concurrent lock owner rejected'
);
$stackLock['locks']->release($stackLock['storeId'], MtUniCreditOperationEntryPoint::CHECKOUT, $opHash, $tokenA);

// 401 then success: authenticatedRequest retries once (OC4 parity for POST)
$transport401 = new Phase4FakeCpHttpTransport();
$transport401->enqueueJson(200, $payloads['login']);
$transport401->enqueue(401, '{"success":false}');
$transport401->enqueueJson(200, $payloads['login']);
$transport401->enqueueJson(201, $payloads['order']);
$stack401 = Phase7TestHarness::stack($transport401);
$input401 = $input;
$input401['order_id'] = 7011;
$input401['order'] = Phase7TestHarness::orderRow(7011);
$result401 = $stack401['submission']->submit($input401);
mtuc7_assert(!empty($result401['success']), '401 then re-auth succeeds for POST /orders');
mtuc7_assert(Phase7TestHarness::countOrderPosts($transport401) === 2, '401 interaction: order POST retried once after re-login');

// Revalidation failures
$transportVal = new Phase4FakeCpHttpTransport();
$stackVal = Phase7TestHarness::stack($transportVal);
$missing = $stackVal['submission']->submit(array(
    'store_id' => $stackVal['storeId'],
    'order_id' => 0,
    'order' => null,
    'order_products' => array(),
    'cart_context' => Phase7TestHarness::cartContext(),
));
mtuc7_assert(isset($missing['error']) && $missing['error'] === 'order_missing', 'order missing rejected');

$wrongStore = $stackVal['submission']->submit(array(
    'store_id' => $stackVal['storeId'],
    'order_id' => Phase7TestHarness::ORDER_ID,
    'order' => Phase7TestHarness::orderRow(Phase7TestHarness::ORDER_ID, Phase5TestHarness::STORE_B),
    'order_products' => Phase7TestHarness::orderProducts(),
    'cart_context' => Phase7TestHarness::cartContext(),
));
mtuc7_assert(isset($wrongStore['error']) && $wrongStore['error'] === 'order_store_mismatch', 'wrong store rejected');

$wrongPay = Phase7TestHarness::orderRow(7020);
$wrongPay['payment_code'] = 'cod';
$wrongPay['payment_method'] = 'Cash';
$badPay = $stackVal['submission']->submit(array(
    'store_id' => $stackVal['storeId'],
    'order_id' => 7020,
    'order' => $wrongPay,
    'order_products' => Phase7TestHarness::orderProducts(),
    'cart_context' => Phase7TestHarness::cartContext(),
));
mtuc7_assert(isset($badPay['error']) && $badPay['error'] === 'payment_method_mismatch', 'wrong payment code rejected');

$amountChanged = $stackVal['submission']->submit(array(
    'store_id' => $stackVal['storeId'],
    'order_id' => 7021,
    'order' => Phase7TestHarness::orderRow(7021, Phase5TestHarness::STORE_A, 500.0),
    'order_products' => Phase7TestHarness::orderProducts(),
    'cart_context' => Phase7TestHarness::cartContext(999.0),
));
mtuc7_assert(isset($amountChanged['error']) && $amountChanged['error'] === 'amount_changed', 'amount drift rejected');
mtuc7_assert(Phase7TestHarness::countOrderPosts($transportVal) === 0, 'validation failures never POST /orders');

// States vocabulary
foreach (MtUniCreditFinancingAttemptState::all() as $state) {
    mtuc7_assert(MtUniCreditFinancingAttemptState::isValid($state), 'state valid: ' . $state);
}

// GET prepared is read-only: view-state path never touches CP transport
$transportGet = new Phase4FakeCpHttpTransport();
$stackGet = Phase7TestHarness::stack($transportGet);
$getOrderId = 7100;
$getInput = $input;
$getInput['order_id'] = $getOrderId;
$getInput['order'] = Phase7TestHarness::orderRow($getOrderId);

// Simulate repeated GET prepared with no attempt yet
for ($i = 0; $i < 5; $i++) {
    $attemptGet = $stackGet['attempts']->findByStoreOrder($stackGet['storeId'], $getOrderId);
    $viewGet = MtUniCreditCheckoutPreparedViewState::fromAttempt($attemptGet);
    mtuc7_assert($viewGet['mode'] === MtUniCreditCheckoutPreparedViewState::MODE_READY, 'GET ready mode without attempt #' . ($i + 1));
    mtuc7_assert(!empty($viewGet['can_submit']), 'GET ready shows submit #' . ($i + 1));
}
mtuc7_assert(Phase7TestHarness::countOrderPosts($transportGet) === 0, 'repeated GET ready: CP POST count = 0');

// Explicit POST submit once
$transportGet->enqueueJson(200, $payloads['login']);
$transportGet->enqueueJson(201, $payloads['order']);
$posted = $stackGet['submission']->submit($getInput);
mtuc7_assert(!empty($posted['success']), 'explicit POST submit succeeds');
mtuc7_assert(Phase7TestHarness::countOrderPosts($transportGet) === 1, 'explicit POST: exactly one CP /orders');
$createdAttempt = $stackGet['attempts']->findByStoreOrder($stackGet['storeId'], $getOrderId);
$viewCreated = MtUniCreditCheckoutPreparedViewState::fromAttempt($createdAttempt);
mtuc7_assert($viewCreated['mode'] === MtUniCreditCheckoutPreparedViewState::MODE_SUCCESS, 'GET after success: success mode');
mtuc7_assert(empty($viewCreated['can_submit']), 'GET after success: no submit button');
mtuc7_assert(!empty($posted['apply_native_order_status']), 'first definitive success requests native status once');

// Repeated GET after cp_created
for ($i = 0; $i < 3; $i++) {
    $replayView = MtUniCreditCheckoutPreparedViewState::fromAttempt(
        $stackGet['attempts']->findByStoreOrder($stackGet['storeId'], $getOrderId)
    );
    mtuc7_assert($replayView['mode'] === MtUniCreditCheckoutPreparedViewState::MODE_SUCCESS, 'GET cp_created read-only #' . ($i + 1));
}
mtuc7_assert(Phase7TestHarness::countOrderPosts($transportGet) === 1, 'GET with cp_created: CP POST count remains 1');

// Local replay via submission still does not add POST (controller would not call on GET)
$replaySubmit = $stackGet['submission']->submit($getInput);
mtuc7_assert(!empty($replaySubmit['local_replay']), 'local replay after success');
mtuc7_assert(empty($replaySubmit['apply_native_order_status']), 'local replay does not re-apply native status');
mtuc7_assert(Phase7TestHarness::countOrderPosts($transportGet) === 1, 'local replay: CP POST count remains 1');

// CSRF / intent token
$sessionTok = array();
$tokenA = MtUniCreditCheckoutSubmitToken::issue($sessionTok);
$tokenB = MtUniCreditCheckoutSubmitToken::issue($sessionTok);
mtuc7_assert($tokenA === $tokenB && $tokenA !== '', 'CSRF token stable across GET renders');
mtuc7_assert(MtUniCreditCheckoutSubmitToken::verify($sessionTok, $tokenA), 'valid CSRF token accepted');
mtuc7_assert(!MtUniCreditCheckoutSubmitToken::verify($sessionTok, ''), 'missing CSRF token rejected');
mtuc7_assert(!MtUniCreditCheckoutSubmitToken::verify($sessionTok, 'deadbeef'), 'wrong CSRF token rejected');
mtuc7_assert(!MtUniCreditCheckoutSubmitToken::verify(array(), $tokenA), 'CSRF fails without session token');

// Retryable failure: GET shows retry; POST reuses attempt
$transportRetry = new Phase4FakeCpHttpTransport();
$transportRetry->enqueueJson(200, $payloads['login']);
$transportRetry->enqueueJson(422, array('success' => false, 'message' => 'invalid'));
$stackRetry = Phase7TestHarness::stack($transportRetry);
$retryOrderId = 7110;
$retryInput = $input;
$retryInput['order_id'] = $retryOrderId;
$retryInput['order'] = Phase7TestHarness::orderRow($retryOrderId);
$stackRetry['submission']->submit($retryInput);
$retryAttempt = $stackRetry['attempts']->findByStoreOrder($stackRetry['storeId'], $retryOrderId);
$retryView = MtUniCreditCheckoutPreparedViewState::fromAttempt($retryAttempt);
mtuc7_assert($retryView['mode'] === MtUniCreditCheckoutPreparedViewState::MODE_RETRYABLE, 'GET retryable mode');
mtuc7_assert(!empty($retryView['can_submit']), 'GET retryable shows retry submit');
$retryId = (int) $retryAttempt['attempt_id'];
$fp = (string) $retryAttempt['request_fingerprint'];
// Client already holds auth from first attempt — only enqueue the order response.
$transportRetry->enqueueJson(201, $payloads['order']);
$retryOk = $stackRetry['submission']->submit($retryInput);
mtuc7_assert(!empty($retryOk['success']), 'POST retry succeeds');
$retryAfter = $stackRetry['attempts']->findByStoreOrder($stackRetry['storeId'], $retryOrderId);
mtuc7_assert((int) $retryAfter['attempt_id'] === $retryId, 'retry reuses existing attempt row');
mtuc7_assert((string) $retryAfter['request_fingerprint'] === $fp, 'retry keeps frozen fingerprint');

// Ambiguous: GET blocked; POST must not issue /orders
$transportAmbGet = new Phase4FakeCpHttpTransport();
$transportAmbGet->enqueueJson(200, $payloads['login']);
$transportAmbGet->enqueueTimeout();
$stackAmbGet = Phase7TestHarness::stack($transportAmbGet);
$ambOrderId = 7120;
$ambInput = $input;
$ambInput['order_id'] = $ambOrderId;
$ambInput['order'] = Phase7TestHarness::orderRow($ambOrderId);
$stackAmbGet['submission']->submit($ambInput);
$ambAttempt = $stackAmbGet['attempts']->findByStoreOrder($stackAmbGet['storeId'], $ambOrderId);
$ambView = MtUniCreditCheckoutPreparedViewState::fromAttempt($ambAttempt);
mtuc7_assert($ambView['mode'] === MtUniCreditCheckoutPreparedViewState::MODE_AMBIGUOUS, 'GET ambiguous mode');
mtuc7_assert(empty($ambView['can_submit']), 'GET ambiguous: no submit/retry button');
mtuc7_assert(Phase7TestHarness::countOrderPosts($transportAmbGet) === 1, 'ambiguous baseline POST count = 1');
for ($i = 0; $i < 3; $i++) {
    MtUniCreditCheckoutPreparedViewState::fromAttempt(
        $stackAmbGet['attempts']->findByStoreOrder($stackAmbGet['storeId'], $ambOrderId)
    );
}
mtuc7_assert(Phase7TestHarness::countOrderPosts($transportAmbGet) === 1, 'GET with cp_outcome_unknown: CP POST unchanged');
$ambPost = $stackAmbGet['submission']->submit($ambInput);
mtuc7_assert(!empty($ambPost['ambiguous_blocked']), 'POST after ambiguous → ambiguous_blocked');
mtuc7_assert(Phase7TestHarness::countOrderPosts($transportAmbGet) === 1, 'POST after ambiguous: CP POST count unchanged');

// Double POST: at most one semantic CP create
$transportDouble = new Phase4FakeCpHttpTransport();
$payloadsDouble = Phase7TestHarness::loginAndOrderSuccessPayloads();
$transportDouble->enqueueJson(200, $payloadsDouble['login']);
$transportDouble->enqueueJson(201, $payloadsDouble['order']);
$stackDouble = Phase7TestHarness::stack($transportDouble);
$doubleId = 7130;
$doubleInput = $input;
$doubleInput['order_id'] = $doubleId;
$doubleInput['order'] = Phase7TestHarness::orderRow($doubleId);
$d1 = $stackDouble['submission']->submit($doubleInput);
$d2 = $stackDouble['submission']->submit($doubleInput);
mtuc7_assert(!empty($d1['success']) && !empty($d2['success']), 'double POST both report success');
mtuc7_assert(Phase7TestHarness::countOrderPosts($transportDouble) === 1, 'double POST: at most one CP create');

echo PHP_EOL . 'Phase 7 checks: ' . $passes . ' passed';
if ($failures) {
    echo ', ' . count($failures) . ' failed' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo ', 0 failed' . PHP_EOL;
exit(0);
