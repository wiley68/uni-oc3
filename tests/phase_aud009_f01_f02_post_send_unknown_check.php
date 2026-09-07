<?php

/**
 * AUD-009 F01/F02 — post-send CP response defects → outcome unknown (not retryable).
 * Run: php tests/phase_aud009_f01_f02_post_send_unknown_check.php
 *
 * PHP 7.3 compatible. Offline.
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
    mtuc_test_define_dir_storage('mtuc-aud009-f01f02');
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
}
if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'oc_');
}

require_once $lib . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . '/support/phase4_harness.php';
require_once __DIR__ . '/support/phase5_harness.php';
require_once __DIR__ . '/support/phase7_harness.php';
require_once __DIR__ . '/support/phase9_harness.php';
require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

$failures = array();
$passes = 0;
$nextOrderId = 901001;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucAud009F01F02_assert($condition, $message)
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
 * @return int
 */
function mtucAud009F01F02_nextOrderId()
{
    global $nextOrderId;

    return $nextOrderId++;
}

/**
 * @param Phase4FakeCpHttpTransport $transport
 * @param callable $enqueueOrderResponse function(Phase4FakeCpHttpTransport $t): void
 * @param string $label
 * @return array<string, mixed>
 */
function mtucAud009F01F02_runCheckoutCase(Phase4FakeCpHttpTransport $transport, $enqueueOrderResponse, $label)
{
    $payloads = Phase7TestHarness::loginAndOrderSuccessPayloads();
    $transport->enqueueJson(200, $payloads['login']);
    $enqueueOrderResponse($transport);

    $stack = Phase7TestHarness::stack($transport);
    $orderId = mtucAud009F01F02_nextOrderId();
    $input = array(
        'store_id' => $stack['storeId'],
        'order_id' => $orderId,
        'order' => Phase7TestHarness::orderRow($orderId),
        'order_products' => Phase7TestHarness::orderProducts(),
        'cart_context' => Phase7TestHarness::cartContext(),
    );

    $first = $stack['submission']->submit($input);
    $postsAfterFirst = Phase7TestHarness::countOrderPosts($transport);
    $attempt = $stack['attempts']->findByStoreOrder($stack['storeId'], $orderId);
    $bankRepo = new MtUniCreditOrderBankStatusRepository($stack['db']);
    $bank = $bankRepo->findByOrderId($stack['storeId'], $orderId);

    mtucAud009F01F02_assert(empty($first['success']), $label . ': submit fails');
    mtucAud009F01F02_assert(!empty($first['ambiguous_blocked']), $label . ': ambiguous_blocked=true');
    mtucAud009F01F02_assert(empty($first['recoverable']), $label . ': recoverable=false');
    mtucAud009F01F02_assert(
        isset($first['error']) && $first['error'] === MtUniCreditControlPanelErrorClass::INVALID_RESPONSE
            || (isset($first['error_class']) && $first['error_class'] === MtUniCreditControlPanelErrorClass::INVALID_RESPONSE)
            || (isset($attempt['last_error_class'])
                && $attempt['last_error_class'] === MtUniCreditControlPanelErrorClass::INVALID_RESPONSE),
        $label . ': diagnostic errorClass remains invalid_response family'
    );
    mtucAud009F01F02_assert(
        $attempt !== null && $attempt['state'] === MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN,
        $label . ': durable attempt state cp_outcome_unknown'
    );
    mtucAud009F01F02_assert(
        ($bank === null || (string) $bank['status_id'] !== MtUniCreditBankStatus::SEND_FAILED_CP)
            && (string) (isset($first['bank_status']) ? $first['bank_status'] : '') !== MtUniCreditBankStatus::SEND_FAILED_CP,
        $label . ': bank_send_failed_cp absent'
    );
    mtucAud009F01F02_assert(
        !MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveCheckoutCpFailureTerminal($first),
        $label . ': Checkout not definitive CP Thank You path'
    );

    $view = MtUniCreditCheckoutPreparedViewState::fromAttempt($attempt);
    mtucAud009F01F02_assert(
        $view['mode'] === MtUniCreditCheckoutPreparedViewState::MODE_AMBIGUOUS,
        $label . ': reload MODE_AMBIGUOUS'
    );
    mtucAud009F01F02_assert(empty($view['can_submit']), $label . ': reload can_submit=false');

    $second = $stack['submission']->submit($input);
    mtucAud009F01F02_assert(!empty($second['ambiguous_blocked']), $label . ': second POST blocked as ambiguous');
    mtucAud009F01F02_assert(
        Phase7TestHarness::countOrderPosts($transport) === $postsAfterFirst,
        $label . ': zero additional CP create calls'
    );

    return array(
        'stack' => $stack,
        'first' => $first,
        'order_id' => $orderId,
        'posts' => $postsAfterFirst,
    );
}

/**
 * @param array<string, mixed> $result
 * @return string|null
 */
function mtucAud009F01F02_errorClass(array $result)
{
    if (isset($result['error']) && is_string($result['error'])) {
        return $result['error'];
    }
    if (isset($result['error_class']) && is_string($result['error_class'])) {
        return $result['error_class'];
    }

    return null;
}

// Inspect submission result shape for error key once
$probeTransport = new Phase4FakeCpHttpTransport();
$probePayloads = Phase7TestHarness::loginAndOrderSuccessPayloads();
$probeTransport->enqueueJson(200, $probePayloads['login']);
$probeTransport->enqueueJson(200, array('success' => true));
$probeStack = Phase7TestHarness::stack($probeTransport);
$probeOid = mtucAud009F01F02_nextOrderId();
$probeResult = $probeStack['submission']->submit(array(
    'store_id' => $probeStack['storeId'],
    'order_id' => $probeOid,
    'order' => Phase7TestHarness::orderRow($probeOid),
    'order_products' => Phase7TestHarness::orderProducts(),
    'cart_context' => Phase7TestHarness::cartContext(),
));
mtucAud009F01F02_assert(!empty($probeResult['ambiguous_blocked']), 'probe missing-data: ambiguous');
mtucAud009F01F02_assert(
    mtucAud009F01F02_errorClass($probeResult) === MtUniCreditControlPanelErrorClass::INVALID_RESPONSE
        || (isset($probeResult['attempt']['last_error_class'])
            && $probeResult['attempt']['last_error_class'] === MtUniCreditControlPanelErrorClass::INVALID_RESPONSE),
    'probe: errorClass diagnostic = cp_invalid_response'
);

// -------------------------------------------------------------------------
// F-009-01 malformed / incomplete 2xx success shapes
// -------------------------------------------------------------------------
$f01Cases = array(
    'missing data' => function (Phase4FakeCpHttpTransport $t) {
        $t->enqueueJson(200, array('success' => true, 'message' => 'ok'));
    },
    'data missing id' => function (Phase4FakeCpHttpTransport $t) {
        $t->enqueueJson(200, array('success' => true, 'data' => array('note' => 'no-id')));
    },
    'id=0' => function (Phase4FakeCpHttpTransport $t) {
        $t->enqueueJson(200, array('success' => true, 'data' => array('id' => 0)));
    },
    'success=false' => function (Phase4FakeCpHttpTransport $t) {
        $t->enqueueJson(200, array('success' => false, 'data' => array('id' => 99)));
    },
    'success missing' => function (Phase4FakeCpHttpTransport $t) {
        $t->enqueueJson(200, array('data' => array('id' => 99)));
    },
    'non-numeric id' => function (Phase4FakeCpHttpTransport $t) {
        $t->enqueueJson(200, array('success' => true, 'data' => array('id' => 'not-a-number')));
    },
);

foreach ($f01Cases as $name => $enqueue) {
    mtucAud009F01F02_runCheckoutCase(new Phase4FakeCpHttpTransport(), $enqueue, 'F01 ' . $name);
}

// -------------------------------------------------------------------------
// F-009-02 oversized post-send response
// -------------------------------------------------------------------------
mtucAud009F01F02_runCheckoutCase(
    new Phase4FakeCpHttpTransport(),
    function (Phase4FakeCpHttpTransport $t) {
        $t->enqueueSizeAbort();
    },
    'F02 oversized response'
);

// Client wrap proof: size abort InvalidPayload → UncertainResponseException on createOrder
$sizeClientTransport = new Phase4FakeCpHttpTransport();
$sizeClientTransport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$sizeClientTransport->enqueueSizeAbort();
$sizeStack = Phase4TestHarness::services($sizeClientTransport);
$sizeEx = null;
try {
    $sizeStack['client']->createOrder(array('order_id' => '1'));
} catch (Exception $exception) {
    $sizeEx = $exception;
}
mtucAud009F01F02_assert(
    $sizeEx instanceof MtUniCreditCpUncertainResponseException,
    'F02 client: oversized createOrder throws UncertainResponseException'
);
mtucAud009F01F02_assert(
    $sizeEx !== null && strpos($sizeEx->getMessage(), 'exceeded the allowed size') !== false,
    'F02 client: size message preserved'
);

// -------------------------------------------------------------------------
// Existing unknown regressions
// -------------------------------------------------------------------------
$unknownCases = array(
    'empty body' => function (Phase4FakeCpHttpTransport $t) {
        $t->enqueue(200, '');
    },
    'malformed JSON' => function (Phase4FakeCpHttpTransport $t) {
        $t->enqueue(200, '{not-json');
    },
    'timeout' => function (Phase4FakeCpHttpTransport $t) {
        $t->enqueueTimeout();
    },
    '5xx' => function (Phase4FakeCpHttpTransport $t) {
        $t->enqueueJson(503, array('error' => 'unavailable'));
    },
    'connection failure' => function (Phase4FakeCpHttpTransport $t) {
        $t->enqueueConnectionFailure();
    },
);

foreach ($unknownCases as $name => $enqueue) {
    $transport = new Phase4FakeCpHttpTransport();
    $payloads = Phase7TestHarness::loginAndOrderSuccessPayloads();
    $transport->enqueueJson(200, $payloads['login']);
    $enqueue($transport);
    $stack = Phase7TestHarness::stack($transport);
    $orderId = mtucAud009F01F02_nextOrderId();
    $result = $stack['submission']->submit(array(
        'store_id' => $stack['storeId'],
        'order_id' => $orderId,
        'order' => Phase7TestHarness::orderRow($orderId),
        'order_products' => Phase7TestHarness::orderProducts(),
        'cart_context' => Phase7TestHarness::cartContext(),
    ));
    $attempt = $stack['attempts']->findByStoreOrder($stack['storeId'], $orderId);
    mtucAud009F01F02_assert(!empty($result['ambiguous_blocked']), 'unknown regression ' . $name . ': ambiguous');
    mtucAud009F01F02_assert(
        $attempt !== null && $attempt['state'] === MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN,
        'unknown regression ' . $name . ': cp_outcome_unknown'
    );
}

// -------------------------------------------------------------------------
// Existing definitive regressions (must NOT become ambiguous)
// -------------------------------------------------------------------------
$transport422 = new Phase4FakeCpHttpTransport();
$payloads422 = Phase7TestHarness::loginAndOrderSuccessPayloads();
$transport422->enqueueJson(200, $payloads422['login']);
$transport422->enqueueJson(422, array('success' => false, 'message' => 'invalid'));
$stack422 = Phase7TestHarness::stack($transport422);
$oid422 = mtucAud009F01F02_nextOrderId();
$result422 = $stack422['submission']->submit(array(
    'store_id' => $stack422['storeId'],
    'order_id' => $oid422,
    'order' => Phase7TestHarness::orderRow($oid422),
    'order_products' => Phase7TestHarness::orderProducts(),
    'cart_context' => Phase7TestHarness::cartContext(),
));
$attempt422 = $stack422['attempts']->findByStoreOrder($stack422['storeId'], $oid422);
mtucAud009F01F02_assert(empty($result422['ambiguous_blocked']), '422 remains non-ambiguous');
mtucAud009F01F02_assert(
    $attempt422 !== null && $attempt422['state'] === MtUniCreditFinancingAttemptState::CP_FAILED_RETRYABLE,
    '422 remains cp_failed_retryable'
);

$transport401 = new Phase4FakeCpHttpTransport();
$payloads401 = Phase7TestHarness::loginAndOrderSuccessPayloads();
$transport401->enqueueJson(200, $payloads401['login']);
$transport401->enqueueJson(401, array('error' => 'expired'));
$transport401->enqueueJson(200, $payloads401['login']);
$transport401->enqueueJson(401, array('error' => 'expired'));
$stack401 = Phase7TestHarness::stack($transport401);
$oid401 = mtucAud009F01F02_nextOrderId();
$result401 = $stack401['submission']->submit(array(
    'store_id' => $stack401['storeId'],
    'order_id' => $oid401,
    'order' => Phase7TestHarness::orderRow($oid401),
    'order_products' => Phase7TestHarness::orderProducts(),
    'cart_context' => Phase7TestHarness::cartContext(),
));
$attempt401 = $stack401['attempts']->findByStoreOrder($stack401['storeId'], $oid401);
mtucAud009F01F02_assert(empty($result401['ambiguous_blocked']), 'final 401 remains non-ambiguous');
mtucAud009F01F02_assert(
    $attempt401 !== null && $attempt401['state'] === MtUniCreditFinancingAttemptState::CP_FAILED_RETRYABLE,
    'final 401 remains cp_failed_retryable'
);

// Local pre-send validation — store mismatch before any CP create
$transportLocal = new Phase4FakeCpHttpTransport();
$stackLocal = Phase7TestHarness::stack($transportLocal);
$oidLocal = mtucAud009F01F02_nextOrderId();
$resultLocal = $stackLocal['submission']->submit(array(
    'store_id' => $stackLocal['storeId'],
    'order_id' => $oidLocal,
    'order' => Phase7TestHarness::orderRow($oidLocal, Phase5TestHarness::STORE_B),
    'order_products' => Phase7TestHarness::orderProducts(),
    'cart_context' => Phase7TestHarness::cartContext(),
));
mtucAud009F01F02_assert(empty($resultLocal['success']), 'local validation fails');
mtucAud009F01F02_assert(empty($resultLocal['ambiguous_blocked']), 'local pre-send validation is not ambiguous');
mtucAud009F01F02_assert(Phase7TestHarness::countOrderPosts($transportLocal) === 0, 'local validation: zero CP create calls');

// -------------------------------------------------------------------------
// Product/Cart parity — oversized → ambiguous stay modal, no resend
// -------------------------------------------------------------------------
$transportSf = new Phase4FakeCpHttpTransport();
$transportSf->enqueueJson(200, Phase7TestHarness::loginAndOrderSuccessPayloads()['login']);
$transportSf->enqueueSizeAbort();
$stackSf = Phase9TestHarness::stack($transportSf);
$oidSf = mtucAud009F01F02_nextOrderId();
$sfResult = $stackSf['storefront']->submit(Phase9TestHarness::productStorefrontInput($stackSf, $oidSf));
mtucAud009F01F02_assert(!empty($sfResult['ambiguous_blocked']), 'Product: oversized → ambiguous');
mtucAud009F01F02_assert(
    MtUniCreditFinancingTerminalNavigationSupport::isCpCreateFailureStayOnPage($sfResult),
    'Product: stay-on-page candidate'
);
mtucAud009F01F02_assert(
    !MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveCheckoutCpFailureTerminal($sfResult),
    'Product: not Checkout Thank You definitive path'
);
$postsSf = Phase7TestHarness::countOrderPosts($transportSf);
$sfSecond = $stackSf['storefront']->submit(Phase9TestHarness::productStorefrontInput($stackSf, $oidSf));
mtucAud009F01F02_assert(empty($sfSecond['success']), 'Product: second submit does not succeed');
mtucAud009F01F02_assert(
    Phase7TestHarness::countOrderPosts($transportSf) === $postsSf,
    'Product: zero additional CP create'
);

// -------------------------------------------------------------------------
if (count($failures) > 0) {
    echo 'AUD-009 F01/F02: FAIL (' . count($failures) . ' failures, ' . $passes . ' passes)' . PHP_EOL;
    exit(1);
}

echo 'AUD-009 F01/F02: PASS (' . $passes . ' passes)' . PHP_EOL;
exit(0);
