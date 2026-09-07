<?php

/**
 * AUD-009 F01-R1 — re-login after order 401 must stay definitive (not order-unknown).
 * Run: php tests/phase_aud009_f01_r1_relogin_boundary_check.php
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
    mtuc_test_define_dir_storage('mtuc-aud009-f01-r1');
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
require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

$failures = array();
$passes = 0;
$nextOrderId = 902001;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucAud009R1_assert($condition, $message)
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
function mtucAud009R1_nextOrderId()
{
    global $nextOrderId;

    return $nextOrderId++;
}

/**
 * @param Phase4FakeCpHttpTransport $transport
 * @param callable $enqueueAfterFirst401 function(Phase4FakeCpHttpTransport $t): void
 * @param string $label
 * @param string $expectedState
 * @param string|null $expectedError
 * @return array<string, mixed>
 */
function mtucAud009R1_run401Then(Phase4FakeCpHttpTransport $transport, $enqueueAfterFirst401, $label, $expectedState, $expectedError = null)
{
    $payloads = Phase7TestHarness::loginAndOrderSuccessPayloads();
    $transport->enqueueJson(200, $payloads['login']);
    $transport->enqueueJson(401, array('error' => 'expired'));
    $enqueueAfterFirst401($transport);

    $stack = Phase7TestHarness::stack($transport);
    $orderId = mtucAud009R1_nextOrderId();
    $result = $stack['submission']->submit(array(
        'store_id' => $stack['storeId'],
        'order_id' => $orderId,
        'order' => Phase7TestHarness::orderRow($orderId),
        'order_products' => Phase7TestHarness::orderProducts(),
        'cart_context' => Phase7TestHarness::cartContext(),
    ));
    $attempt = $stack['attempts']->findByStoreOrder($stack['storeId'], $orderId);
    $posts = Phase7TestHarness::countOrderPosts($transport);

    mtucAud009R1_assert(empty($result['success']), $label . ': submit fails');
    mtucAud009R1_assert(empty($result['ambiguous_blocked']), $label . ': ambiguousBlocked=false');
    mtucAud009R1_assert(
        $attempt !== null && $attempt['state'] === $expectedState,
        $label . ': attempt state ' . $expectedState
    );
    mtucAud009R1_assert($attempt['state'] !== MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN, $label . ': not cp_outcome_unknown');
    mtucAud009R1_assert($posts === 1, $label . ': order POST count = 1 (no retry POST)');
    if ($expectedError !== null) {
        mtucAud009R1_assert(
            isset($result['error']) && $result['error'] === $expectedError,
            $label . ': error=' . $expectedError
        );
    }

    return array('result' => $result, 'attempt' => $attempt, 'posts' => $posts, 'stack' => $stack, 'order_id' => $orderId);
}

// -------------------------------------------------------------------------
// R1: order 401 → invalid re-login success payload → definitive
// -------------------------------------------------------------------------
mtucAud009R1_run401Then(
    new Phase4FakeCpHttpTransport(),
    function (Phase4FakeCpHttpTransport $t) {
        $t->enqueueJson(200, array('success' => false, 'message' => 'bad login'));
    },
    'R1 invalid re-login success=false',
    MtUniCreditFinancingAttemptState::CP_FAILED_RETRYABLE,
    MtUniCreditControlPanelErrorClass::INVALID_RESPONSE
);

mtucAud009R1_run401Then(
    new Phase4FakeCpHttpTransport(),
    function (Phase4FakeCpHttpTransport $t) {
        // success confirmed but token/shop contract broken
        $t->enqueueJson(200, array(
            'success' => true,
            'access_token' => 'x',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ));
    },
    'R1 invalid re-login missing shop',
    MtUniCreditFinancingAttemptState::CP_FAILED_RETRYABLE,
    MtUniCreditControlPanelErrorClass::INVALID_RESPONSE
);

// -------------------------------------------------------------------------
// R1: order 401 → malformed JSON re-login → definitive (not unknown)
// -------------------------------------------------------------------------
mtucAud009R1_run401Then(
    new Phase4FakeCpHttpTransport(),
    function (Phase4FakeCpHttpTransport $t) {
        $t->enqueue(200, '{not-json');
    },
    'R1 malformed JSON re-login',
    MtUniCreditFinancingAttemptState::CP_FAILED_RETRYABLE,
    MtUniCreditControlPanelErrorClass::INVALID_RESPONSE
);

// -------------------------------------------------------------------------
// R1: order 401 → re-login timeout → definitive auth failure
// -------------------------------------------------------------------------
mtucAud009R1_run401Then(
    new Phase4FakeCpHttpTransport(),
    function (Phase4FakeCpHttpTransport $t) {
        $t->enqueueTimeout();
    },
    'R1 re-login timeout',
    MtUniCreditFinancingAttemptState::CP_FAILED_RETRYABLE,
    MtUniCreditControlPanelErrorClass::AUTH_FAILED
);

mtucAud009R1_run401Then(
    new Phase4FakeCpHttpTransport(),
    function (Phase4FakeCpHttpTransport $t) {
        $t->enqueueConnectionFailure();
    },
    'R1 re-login connection failure',
    MtUniCreditFinancingAttemptState::CP_FAILED_RETRYABLE,
    MtUniCreditControlPanelErrorClass::AUTH_FAILED
);

// -------------------------------------------------------------------------
// Successful re-login + uncertain second order POST → still unknown
// -------------------------------------------------------------------------
$transportUnk = new Phase4FakeCpHttpTransport();
$payloadsUnk = Phase7TestHarness::loginAndOrderSuccessPayloads();
$transportUnk->enqueueJson(200, $payloadsUnk['login']);
$transportUnk->enqueueJson(401, array('error' => 'expired'));
$transportUnk->enqueueJson(200, $payloadsUnk['login']);
$transportUnk->enqueueJson(200, array('success' => true, 'message' => 'ok')); // missing data
$stackUnk = Phase7TestHarness::stack($transportUnk);
$oidUnk = mtucAud009R1_nextOrderId();
$resultUnk = $stackUnk['submission']->submit(array(
    'store_id' => $stackUnk['storeId'],
    'order_id' => $oidUnk,
    'order' => Phase7TestHarness::orderRow($oidUnk),
    'order_products' => Phase7TestHarness::orderProducts(),
    'cart_context' => Phase7TestHarness::cartContext(),
));
$attemptUnk = $stackUnk['attempts']->findByStoreOrder($stackUnk['storeId'], $oidUnk);
mtucAud009R1_assert(!empty($resultUnk['ambiguous_blocked']), 'relogin+missing data: ambiguous');
mtucAud009R1_assert(
    $attemptUnk !== null && $attemptUnk['state'] === MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN,
    'relogin+missing data: cp_outcome_unknown'
);
mtucAud009R1_assert(Phase7TestHarness::countOrderPosts($transportUnk) === 2, 'relogin+missing data: two order POSTs');

$transportSize = new Phase4FakeCpHttpTransport();
$payloadsSize = Phase7TestHarness::loginAndOrderSuccessPayloads();
$transportSize->enqueueJson(200, $payloadsSize['login']);
$transportSize->enqueueJson(401, array('error' => 'expired'));
$transportSize->enqueueJson(200, $payloadsSize['login']);
$transportSize->enqueueSizeAbort();
$stackSize = Phase7TestHarness::stack($transportSize);
$oidSize = mtucAud009R1_nextOrderId();
$resultSize = $stackSize['submission']->submit(array(
    'store_id' => $stackSize['storeId'],
    'order_id' => $oidSize,
    'order' => Phase7TestHarness::orderRow($oidSize),
    'order_products' => Phase7TestHarness::orderProducts(),
    'cart_context' => Phase7TestHarness::cartContext(),
));
$attemptSize = $stackSize['attempts']->findByStoreOrder($stackSize['storeId'], $oidSize);
mtucAud009R1_assert(!empty($resultSize['ambiguous_blocked']), 'relogin+oversized: ambiguous');
mtucAud009R1_assert(
    $attemptSize !== null && $attemptSize['state'] === MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN,
    'relogin+oversized: cp_outcome_unknown'
);

$transportTo = new Phase4FakeCpHttpTransport();
$payloadsTo = Phase7TestHarness::loginAndOrderSuccessPayloads();
$transportTo->enqueueJson(200, $payloadsTo['login']);
$transportTo->enqueueJson(401, array('error' => 'expired'));
$transportTo->enqueueJson(200, $payloadsTo['login']);
$transportTo->enqueueTimeout();
$stackTo = Phase7TestHarness::stack($transportTo);
$oidTo = mtucAud009R1_nextOrderId();
$resultTo = $stackTo['submission']->submit(array(
    'store_id' => $stackTo['storeId'],
    'order_id' => $oidTo,
    'order' => Phase7TestHarness::orderRow($oidTo),
    'order_products' => Phase7TestHarness::orderProducts(),
    'cart_context' => Phase7TestHarness::cartContext(),
));
$attemptTo = $stackTo['attempts']->findByStoreOrder($stackTo['storeId'], $oidTo);
mtucAud009R1_assert(!empty($resultTo['ambiguous_blocked']), 'relogin+timeout: ambiguous');
mtucAud009R1_assert(
    $attemptTo !== null && $attemptTo['state'] === MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN,
    'relogin+timeout: cp_outcome_unknown'
);

// -------------------------------------------------------------------------
// Successful re-login + second 401 → auth failed retryable
// -------------------------------------------------------------------------
$transport401 = new Phase4FakeCpHttpTransport();
$payloads401 = Phase7TestHarness::loginAndOrderSuccessPayloads();
$transport401->enqueueJson(200, $payloads401['login']);
$transport401->enqueueJson(401, array('error' => 'expired'));
$transport401->enqueueJson(200, $payloads401['login']);
$transport401->enqueueJson(401, array('error' => 'expired'));
$stack401 = Phase7TestHarness::stack($transport401);
$oid401 = mtucAud009R1_nextOrderId();
$result401 = $stack401['submission']->submit(array(
    'store_id' => $stack401['storeId'],
    'order_id' => $oid401,
    'order' => Phase7TestHarness::orderRow($oid401),
    'order_products' => Phase7TestHarness::orderProducts(),
    'cart_context' => Phase7TestHarness::cartContext(),
));
$attempt401 = $stack401['attempts']->findByStoreOrder($stack401['storeId'], $oid401);
mtucAud009R1_assert(empty($result401['ambiguous_blocked']), 'second 401: not ambiguous');
mtucAud009R1_assert(
    isset($result401['error']) && $result401['error'] === MtUniCreditControlPanelErrorClass::AUTH_FAILED,
    'second 401: cp_auth_failed'
);
mtucAud009R1_assert(
    $attempt401 !== null && $attempt401['state'] === MtUniCreditFinancingAttemptState::CP_FAILED_RETRYABLE,
    'second 401: cp_failed_retryable'
);
mtucAud009R1_assert(Phase7TestHarness::countOrderPosts($transport401) === 2, 'second 401: exactly two order POSTs');

// -------------------------------------------------------------------------
if (count($failures) > 0) {
    echo 'AUD-009 F01-R1: FAIL (' . count($failures) . ' failures, ' . $passes . ' passes)' . PHP_EOL;
    exit(1);
}

echo 'AUD-009 F01-R1: PASS (' . $passes . ' passes)' . PHP_EOL;
exit(0);
