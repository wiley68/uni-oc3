<?php

/**
 * AUD-009 F03 — HTTP 409 is existing-order conflict, not no-CP failure.
 * Run: php tests/phase_aud009_f03_cp_conflict_semantics_check.php
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
    mtuc_test_define_dir_storage('mtuc-aud009-f03');
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
$nextOrderId = 903001;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucAud009F03_assert($condition, $message)
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
function mtucAud009F03_nextOrderId()
{
    global $nextOrderId;

    return $nextOrderId++;
}

/**
 * @param Phase4FakeCpHttpTransport $transport
 * @return array<string, mixed>
 */
function mtucAud009F03_checkout409(Phase4FakeCpHttpTransport $transport)
{
    $payloads = Phase7TestHarness::loginAndOrderSuccessPayloads();
    $transport->enqueueJson(200, $payloads['login']);
    $transport->enqueueJson(409, array('success' => false, 'message' => 'conflict'));

    $stack = Phase7TestHarness::stack($transport);
    $orderId = mtucAud009F03_nextOrderId();
    $input = array(
        'store_id' => $stack['storeId'],
        'order_id' => $orderId,
        'order' => Phase7TestHarness::orderRow($orderId),
        'order_products' => Phase7TestHarness::orderProducts(),
        'cart_context' => Phase7TestHarness::cartContext(),
    );
    $first = $stack['submission']->submit($input);
    $attempt = $stack['attempts']->findByStoreOrder($stack['storeId'], $orderId);
    $bank = (new MtUniCreditOrderBankStatusRepository($stack['db']))->findByOrderId($stack['storeId'], $orderId);

    return array(
        'stack' => $stack,
        'transport' => $transport,
        'order_id' => $orderId,
        'input' => $input,
        'first' => $first,
        'attempt' => $attempt,
        'bank' => $bank,
        'posts' => Phase7TestHarness::countOrderPosts($transport),
    );
}

// -------------------------------------------------------------------------
// Initial 409
// -------------------------------------------------------------------------
$case = mtucAud009F03_checkout409(new Phase4FakeCpHttpTransport());
mtucAud009F03_assert(empty($case['first']['success']), '409: submit fails');
mtucAud009F03_assert(
    isset($case['first']['error']) && $case['first']['error'] === MtUniCreditControlPanelErrorClass::CONFLICT,
    '409: errorClass=cp_conflict'
);
mtucAud009F03_assert(
    $case['attempt'] !== null
        && $case['attempt']['state'] === MtUniCreditFinancingAttemptState::CP_EXISTING_CONFLICT,
    '409: durable state cp_existing_conflict'
);
mtucAud009F03_assert(empty($case['first']['recoverable']), '409: recoverable=false');
mtucAud009F03_assert(empty($case['first']['ambiguous_blocked']), '409: ambiguousBlocked=false');
mtucAud009F03_assert(
    ($case['bank'] === null || (string) $case['bank']['status_id'] !== MtUniCreditBankStatus::SEND_FAILED_CP)
        && (string) (isset($case['first']['bank_status']) ? $case['first']['bank_status'] : '')
        !== MtUniCreditBankStatus::SEND_FAILED_CP,
    '409: bank_send_failed_cp absent'
);
mtucAud009F03_assert(
    !MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveCheckoutCpFailureTerminal($case['first']),
    '409: not definitive Checkout CP Thank You path'
);
mtucAud009F03_assert(
    strpos((string) $case['first']['message'], 'не беше успешно') === false
        && strpos((string) $case['first']['message'], 'вече съществува') !== false,
    '409: customer message does not claim CP order missing'
);
mtucAud009F03_assert($case['posts'] === 1, '409: one CP create POST');

$view = MtUniCreditCheckoutPreparedViewState::fromAttempt($case['attempt']);
mtucAud009F03_assert(
    $view['mode'] === MtUniCreditCheckoutPreparedViewState::MODE_CONFLICT,
    'reload: MODE_CONFLICT'
);
mtucAud009F03_assert(empty($view['can_submit']), 'reload: can_submit=false');

// -------------------------------------------------------------------------
// Second submit — no additional CP create
// -------------------------------------------------------------------------
$second = $case['stack']['submission']->submit($case['input']);
mtucAud009F03_assert(empty($second['success']), 'second submit fails');
mtucAud009F03_assert(
    isset($second['error']) && $second['error'] === MtUniCreditControlPanelErrorClass::CONFLICT,
    'second submit: still cp_conflict'
);
mtucAud009F03_assert(
    Phase7TestHarness::countOrderPosts($case['transport']) === 1,
    'second submit: CP POST count unchanged'
);
$attempt2 = $case['stack']['attempts']->findByStoreOrder($case['stack']['storeId'], $case['order_id']);
mtucAud009F03_assert(
    $attempt2 !== null && $attempt2['state'] === MtUniCreditFinancingAttemptState::CP_EXISTING_CONFLICT,
    'second submit: durable conflict retained'
);

// -------------------------------------------------------------------------
// Product / Cart — stay modal, no resend
// -------------------------------------------------------------------------
foreach (array('product', 'cart') as $entry) {
    $transport = new Phase4FakeCpHttpTransport();
    $payloads = Phase7TestHarness::loginAndOrderSuccessPayloads();
    $transport->enqueueJson(200, $payloads['login']);
    $transport->enqueueJson(409, array('success' => false, 'message' => 'conflict'));
    $stack = Phase9TestHarness::stack($transport);
    $oid = mtucAud009F03_nextOrderId();
    $input = $entry === 'product'
        ? Phase9TestHarness::productStorefrontInput($stack, $oid)
        : Phase9TestHarness::cartStorefrontInput($stack, $oid);
    $result = $stack['storefront']->submit($input);
    $posts = Phase7TestHarness::countOrderPosts($transport);
    mtucAud009F03_assert(empty($result['success']), $entry . ': fails');
    mtucAud009F03_assert(
        isset($result['error']) && $result['error'] === MtUniCreditControlPanelErrorClass::CONFLICT,
        $entry . ': cp_conflict'
    );
    mtucAud009F03_assert(
        MtUniCreditFinancingTerminalNavigationSupport::isCpCreateFailureStayOnPage($result),
        $entry . ': stay-on-page'
    );
    mtucAud009F03_assert(
        !MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveCheckoutCpFailureTerminal($result),
        $entry . ': not Checkout Thank You'
    );
    $sess = array();
    $modal = MtUniCreditFinancingTerminalNavigationSupport::enrichCpCreateFailureModal($result, $sess);
    mtucAud009F03_assert(
        isset($modal['terminal_ui']) && $modal['terminal_ui'] === MtUniCreditFinancingTerminalNavigationSupport::UI_ERROR_MODAL,
        $entry . ': error modal'
    );
    mtucAud009F03_assert(
        strpos((string) $modal['message'], 'вече съществува') !== false,
        $entry . ': conflict message'
    );

    $input2 = $entry === 'product'
        ? Phase9TestHarness::productStorefrontInput($stack, $oid)
        : Phase9TestHarness::cartStorefrontInput($stack, $oid);
    $secondSf = $stack['storefront']->submit($input2);
    mtucAud009F03_assert(empty($secondSf['success']), $entry . ': second submit fails');
    mtucAud009F03_assert(
        Phase7TestHarness::countOrderPosts($transport) === $posts,
        $entry . ': zero additional CP create'
    );
}

// -------------------------------------------------------------------------
// Alternate presentation token cannot bypass conflict
// -------------------------------------------------------------------------
$transportTok = new Phase4FakeCpHttpTransport();
$payloadsTok = Phase7TestHarness::loginAndOrderSuccessPayloads();
$transportTok->enqueueJson(200, $payloadsTok['login']);
$transportTok->enqueueJson(409, array('success' => false, 'message' => 'conflict'));
$stackTok = Phase9TestHarness::stack($transportTok);
$oidTok = mtucAud009F03_nextOrderId();
$firstTok = $stackTok['storefront']->submit(Phase9TestHarness::productStorefrontInput($stackTok, $oidTok));
$postsTok = Phase7TestHarness::countOrderPosts($transportTok);
mtucAud009F03_assert(
    isset($firstTok['attempt']['state'])
        && $firstTok['attempt']['state'] === MtUniCreditFinancingAttemptState::CP_EXISTING_CONFLICT,
    'token bypass baseline: conflict state'
);
$altInput = Phase9TestHarness::productStorefrontInput($stackTok, $oidTok);
$secondTok = $stackTok['storefront']->submit($altInput);
mtucAud009F03_assert(empty($secondTok['success']), 'new presentation token: submit fails');
mtucAud009F03_assert(
    Phase7TestHarness::countOrderPosts($transportTok) === $postsTok,
    'new presentation token: no additional CP create'
);

// -------------------------------------------------------------------------
// Regressions: 422 / final 401 / unknown remain distinct from conflict
// -------------------------------------------------------------------------
$transport422 = new Phase4FakeCpHttpTransport();
$transport422->enqueueJson(200, Phase7TestHarness::loginAndOrderSuccessPayloads()['login']);
$transport422->enqueueJson(422, array('success' => false, 'message' => 'invalid'));
$stack422 = Phase7TestHarness::stack($transport422);
$oid422 = mtucAud009F03_nextOrderId();
$r422 = $stack422['submission']->submit(array(
    'store_id' => $stack422['storeId'],
    'order_id' => $oid422,
    'order' => Phase7TestHarness::orderRow($oid422),
    'order_products' => Phase7TestHarness::orderProducts(),
    'cart_context' => Phase7TestHarness::cartContext(),
));
$a422 = $stack422['attempts']->findByStoreOrder($stack422['storeId'], $oid422);
mtucAud009F03_assert(
    $a422 !== null && $a422['state'] === MtUniCreditFinancingAttemptState::CP_FAILED_RETRYABLE,
    '422 remains cp_failed_retryable'
);
mtucAud009F03_assert(
    MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveCheckoutCpFailureTerminal($r422),
    '422 remains definitive Checkout CP failure'
);

$transportTo = new Phase4FakeCpHttpTransport();
$transportTo->enqueueJson(200, Phase7TestHarness::loginAndOrderSuccessPayloads()['login']);
$transportTo->enqueueTimeout();
$stackTo = Phase7TestHarness::stack($transportTo);
$oidTo = mtucAud009F03_nextOrderId();
$rTo = $stackTo['submission']->submit(array(
    'store_id' => $stackTo['storeId'],
    'order_id' => $oidTo,
    'order' => Phase7TestHarness::orderRow($oidTo),
    'order_products' => Phase7TestHarness::orderProducts(),
    'cart_context' => Phase7TestHarness::cartContext(),
));
$aTo = $stackTo['attempts']->findByStoreOrder($stackTo['storeId'], $oidTo);
mtucAud009F03_assert(
    $aTo !== null && $aTo['state'] === MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN,
    'timeout remains cp_outcome_unknown'
);
mtucAud009F03_assert(!empty($rTo['ambiguous_blocked']), 'timeout remains ambiguous');

// -------------------------------------------------------------------------
if (count($failures) > 0) {
    echo 'AUD-009 F03: FAIL (' . count($failures) . ' failures, ' . $passes . ' passes)' . PHP_EOL;
    exit(1);
}

echo 'AUD-009 F03: PASS (' . $passes . ' passes)' . PHP_EOL;
exit(0);
