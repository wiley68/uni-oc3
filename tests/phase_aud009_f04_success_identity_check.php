<?php

/**
 * AUD-009 F04 Phase B — strict CP POST /orders success identity validation.
 * Run: php tests/phase_aud009_f04_success_identity_check.php
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
    mtuc_test_define_dir_storage('mtuc-aud009-f04');
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
$nextOrderId = 904001;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucAud009F04_assert($condition, $message)
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
function mtucAud009F04_nextOrderId()
{
    global $nextOrderId;

    return $nextOrderId++;
}

/**
 * @param int $localOrderId
 * @return string
 */
function mtucAud009F04_payloadOrderId($localOrderId)
{
    return substr((string) (int) $localOrderId, 0, 13);
}

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function mtucAud009F04_successBody(array $data)
{
    return array(
        'success' => true,
        'message' => 'created',
        'data' => $data,
    );
}

/**
 * @param string $orderId
 * @param string $unicid
 * @param int $cpId
 * @param int $shopId
 * @return array<string, mixed>
 */
function mtucAud009F04_validData($orderId, $unicid, $cpId = 555001, $shopId = 1)
{
    return array(
        'id' => (int) $cpId,
        'shop_id' => (int) $shopId,
        'order_id' => (string) $orderId,
        'unicid' => (string) $unicid,
        'created_at' => '2024-01-01 00:00:00',
    );
}

/**
 * @param Phase4FakeCpHttpTransport $transport
 * @param int $httpStatus
 * @param array<string, mixed>|list<mixed> $dataOrList
 * @param int|null $orderId
 * @param bool $dataIsList
 * @return array<string, mixed>
 */
function mtucAud009F04_runCheckout(
    Phase4FakeCpHttpTransport $transport,
    $httpStatus,
    $dataOrList,
    $orderId = null,
    $dataIsList = false
) {
    $orderId = $orderId !== null ? (int) $orderId : mtucAud009F04_nextOrderId();
    $payloadOrderId = mtucAud009F04_payloadOrderId($orderId);
    $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
    if ($dataIsList) {
        $transport->enqueueJson((int) $httpStatus, array(
            'success' => true,
            'message' => 'created',
            'data' => $dataOrList,
        ));
    } else {
        $transport->enqueueJson((int) $httpStatus, mtucAud009F04_successBody($dataOrList));
    }

    $stack = Phase7TestHarness::stack($transport);
    $input = array(
        'store_id' => $stack['storeId'],
        'order_id' => $orderId,
        'order' => Phase7TestHarness::orderRow($orderId),
        'order_products' => Phase7TestHarness::orderProducts(),
        'cart_context' => Phase7TestHarness::cartContext(),
    );
    $result = $stack['submission']->submit($input);
    $attempt = $stack['attempts']->findByStoreOrder($stack['storeId'], $orderId);
    $bank = (new MtUniCreditOrderBankStatusRepository($stack['db']))->findByOrderId($stack['storeId'], $orderId);

    return array(
        'stack' => $stack,
        'transport' => $transport,
        'order_id' => $orderId,
        'payload_order_id' => $payloadOrderId,
        'input' => $input,
        'result' => $result,
        'attempt' => $attempt,
        'bank' => $bank,
        'posts' => Phase7TestHarness::countOrderPosts($transport),
    );
}

/**
 * @param array<string, mixed> $case
 * @param string $label
 * @return void
 */
function mtucAud009F04_assertUnknown(array $case, $label)
{
    mtucAud009F04_assert(empty($case['result']['success']), $label . ': fails');
    mtucAud009F04_assert(
        isset($case['result']['error'])
            && $case['result']['error'] === MtUniCreditControlPanelErrorClass::INVALID_RESPONSE,
        $label . ': errorClass=cp_invalid_response'
    );
    mtucAud009F04_assert(
        $case['attempt'] !== null
            && $case['attempt']['state'] === MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN,
        $label . ': cp_outcome_unknown'
    );
    mtucAud009F04_assert(!empty($case['result']['ambiguous_blocked']), $label . ': ambiguous=true');
    mtucAud009F04_assert(empty($case['result']['recoverable']), $label . ': recoverable=false');
    mtucAud009F04_assert(
        ($case['bank'] === null || (string) $case['bank']['status_id'] !== MtUniCreditBankStatus::SEND_FAILED_CP)
            && (string) (isset($case['result']['bank_status']) ? $case['result']['bank_status'] : '')
            !== MtUniCreditBankStatus::SEND_FAILED_CP,
        $label . ': bank_send_failed_cp absent'
    );
    mtucAud009F04_assert(
        !MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveCheckoutCpFailureTerminal($case['result']),
        $label . ': not definitive Thank You'
    );
}

// -------------------------------------------------------------------------
// Valid 201 / 200
// -------------------------------------------------------------------------
$oid201 = mtucAud009F04_nextOrderId();
$po201 = mtucAud009F04_payloadOrderId($oid201);
$case201 = mtucAud009F04_runCheckout(
    new Phase4FakeCpHttpTransport(),
    201,
    mtucAud009F04_validData($po201, Phase4TestHarness::TEST_UNICID, 777201, 45),
    $oid201
);
mtucAud009F04_assert(!empty($case201['result']['success']), '201: success');
mtucAud009F04_assert(
    $case201['attempt'] !== null
        && $case201['attempt']['state'] === MtUniCreditFinancingAttemptState::CP_CREATED,
    '201: cp_created'
);
mtucAud009F04_assert(
    $case201['attempt'] !== null && (int) $case201['attempt']['control_panel_order_id'] === 777201,
    '201: exact CP id persisted'
);

$oid200 = mtucAud009F04_nextOrderId();
$po200 = mtucAud009F04_payloadOrderId($oid200);
$case200 = mtucAud009F04_runCheckout(
    new Phase4FakeCpHttpTransport(),
    200,
    mtucAud009F04_validData($po200, Phase4TestHarness::TEST_UNICID, 777200, 45),
    $oid200
);
mtucAud009F04_assert(!empty($case200['result']['success']), '200 replay: success');
mtucAud009F04_assert(
    $case200['attempt'] !== null
        && $case200['attempt']['state'] === MtUniCreditFinancingAttemptState::CP_CREATED,
    '200 replay: cp_created'
);
mtucAud009F04_assert(
    $case200['attempt'] !== null && (int) $case200['attempt']['control_panel_order_id'] === 777200,
    '200 replay: exact CP id persisted'
);

// -------------------------------------------------------------------------
// Invalid identity matrix
// -------------------------------------------------------------------------
$matrix = array();

$oid = mtucAud009F04_nextOrderId();
$po = mtucAud009F04_payloadOrderId($oid);
$matrix['missing id'] = mtucAud009F04_runCheckout(
    new Phase4FakeCpHttpTransport(),
    201,
    array('shop_id' => 1, 'order_id' => $po, 'unicid' => Phase4TestHarness::TEST_UNICID),
    $oid
);

$oid = mtucAud009F04_nextOrderId();
$po = mtucAud009F04_payloadOrderId($oid);
$matrix['id=0'] = mtucAud009F04_runCheckout(
    new Phase4FakeCpHttpTransport(),
    201,
    mtucAud009F04_validData($po, Phase4TestHarness::TEST_UNICID, 0, 1),
    $oid
);

$oid = mtucAud009F04_nextOrderId();
$po = mtucAud009F04_payloadOrderId($oid);
$d = mtucAud009F04_validData($po, Phase4TestHarness::TEST_UNICID);
$d['id'] = '123';
$matrix['id numeric string'] = mtucAud009F04_runCheckout(new Phase4FakeCpHttpTransport(), 201, $d, $oid);

$oid = mtucAud009F04_nextOrderId();
$po = mtucAud009F04_payloadOrderId($oid);
$d = mtucAud009F04_validData($po, Phase4TestHarness::TEST_UNICID);
$d['id'] = 123.5;
$matrix['id float'] = mtucAud009F04_runCheckout(new Phase4FakeCpHttpTransport(), 201, $d, $oid);

$oid = mtucAud009F04_nextOrderId();
$po = mtucAud009F04_payloadOrderId($oid);
$d = mtucAud009F04_validData($po, Phase4TestHarness::TEST_UNICID);
$d['id'] = true;
$matrix['id boolean'] = mtucAud009F04_runCheckout(new Phase4FakeCpHttpTransport(), 201, $d, $oid);

$oid = mtucAud009F04_nextOrderId();
$po = mtucAud009F04_payloadOrderId($oid);
$matrix['missing shop_id'] = mtucAud009F04_runCheckout(
    new Phase4FakeCpHttpTransport(),
    201,
    array('id' => 10, 'order_id' => $po, 'unicid' => Phase4TestHarness::TEST_UNICID),
    $oid
);

$oid = mtucAud009F04_nextOrderId();
$po = mtucAud009F04_payloadOrderId($oid);
$d = mtucAud009F04_validData($po, Phase4TestHarness::TEST_UNICID);
$d['shop_id'] = '45';
$matrix['shop_id numeric string'] = mtucAud009F04_runCheckout(new Phase4FakeCpHttpTransport(), 201, $d, $oid);

$oid = mtucAud009F04_nextOrderId();
$po = mtucAud009F04_payloadOrderId($oid);
$d = mtucAud009F04_validData($po, Phase4TestHarness::TEST_UNICID);
$d['shop_id'] = 0;
$matrix['shop_id<=0'] = mtucAud009F04_runCheckout(new Phase4FakeCpHttpTransport(), 201, $d, $oid);

$oid = mtucAud009F04_nextOrderId();
$po = mtucAud009F04_payloadOrderId($oid);
$matrix['missing order_id'] = mtucAud009F04_runCheckout(
    new Phase4FakeCpHttpTransport(),
    201,
    array('id' => 10, 'shop_id' => 1, 'unicid' => Phase4TestHarness::TEST_UNICID),
    $oid
);

$oid = mtucAud009F04_nextOrderId();
$po = mtucAud009F04_payloadOrderId($oid);
$matrix['wrong order_id'] = mtucAud009F04_runCheckout(
    new Phase4FakeCpHttpTransport(),
    201,
    mtucAud009F04_validData('999999', Phase4TestHarness::TEST_UNICID),
    $oid
);

$oid = mtucAud009F04_nextOrderId();
$po = mtucAud009F04_payloadOrderId($oid);
$d = mtucAud009F04_validData($po, Phase4TestHarness::TEST_UNICID);
$d['order_id'] = (int) $po;
$matrix['order_id non-string'] = mtucAud009F04_runCheckout(new Phase4FakeCpHttpTransport(), 201, $d, $oid);

$oid = mtucAud009F04_nextOrderId();
$po = mtucAud009F04_payloadOrderId($oid);
$matrix['missing unicid'] = mtucAud009F04_runCheckout(
    new Phase4FakeCpHttpTransport(),
    201,
    array('id' => 10, 'shop_id' => 1, 'order_id' => $po),
    $oid
);

$oid = mtucAud009F04_nextOrderId();
$po = mtucAud009F04_payloadOrderId($oid);
$matrix['wrong unicid'] = mtucAud009F04_runCheckout(
    new Phase4FakeCpHttpTransport(),
    201,
    mtucAud009F04_validData($po, '00000000-0000-0000-0000-000000000000'),
    $oid
);

$oid = mtucAud009F04_nextOrderId();
$po = mtucAud009F04_payloadOrderId($oid);
$d = mtucAud009F04_validData($po, Phase4TestHarness::TEST_UNICID);
$d['unicid'] = 123;
$matrix['unicid non-string'] = mtucAud009F04_runCheckout(new Phase4FakeCpHttpTransport(), 201, $d, $oid);

$oid = mtucAud009F04_nextOrderId();
$po = mtucAud009F04_payloadOrderId($oid);
$matrix['data list'] = mtucAud009F04_runCheckout(
    new Phase4FakeCpHttpTransport(),
    201,
    array(10, 1, $po, Phase4TestHarness::TEST_UNICID),
    $oid,
    true
);

foreach ($matrix as $label => $case) {
    mtucAud009F04_assertUnknown($case, $label);
}

// -------------------------------------------------------------------------
// Second submit after identity mismatch — no additional CP POST
// -------------------------------------------------------------------------
$mismatch = $matrix['wrong order_id'];
$postsBefore = $mismatch['posts'];
$second = $mismatch['stack']['submission']->submit($mismatch['input']);
mtucAud009F04_assert(empty($second['success']), 'second submit: fails');
mtucAud009F04_assert(
    Phase7TestHarness::countOrderPosts($mismatch['transport']) === $postsBefore,
    'second submit: CP POST count unchanged'
);
mtucAud009F04_assert(
    !empty($second['ambiguous_blocked']),
    'second submit: remains ambiguous blocked'
);

$view = MtUniCreditCheckoutPreparedViewState::fromAttempt($mismatch['attempt']);
mtucAud009F04_assert(
    $view['mode'] === MtUniCreditCheckoutPreparedViewState::MODE_AMBIGUOUS,
    'reload: MODE_AMBIGUOUS'
);
mtucAud009F04_assert(empty($view['can_submit']), 'reload: can_submit=false');

// -------------------------------------------------------------------------
// Product / Cart stay + no resend
// -------------------------------------------------------------------------
foreach (array('product', 'cart') as $entry) {
    $transport = new Phase4FakeCpHttpTransport();
    $oidSf = mtucAud009F04_nextOrderId();
    $poSf = mtucAud009F04_payloadOrderId($oidSf);
    $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
    $transport->enqueueJson(
        201,
        mtucAud009F04_successBody(mtucAud009F04_validData('999999', Phase4TestHarness::TEST_UNICID))
    );
    $stack = Phase9TestHarness::stack($transport);
    $input = $entry === 'product'
        ? Phase9TestHarness::productStorefrontInput($stack, $oidSf)
        : Phase9TestHarness::cartStorefrontInput($stack, $oidSf);
    $result = $stack['storefront']->submit($input);
    $posts = Phase7TestHarness::countOrderPosts($transport);
    mtucAud009F04_assert(empty($result['success']), $entry . ': fails');
    mtucAud009F04_assert(!empty($result['ambiguous_blocked']), $entry . ': ambiguous');
    mtucAud009F04_assert(
        MtUniCreditFinancingTerminalNavigationSupport::isCpCreateFailureStayOnPage($result),
        $entry . ': stay-on-page'
    );

    $input2 = $entry === 'product'
        ? Phase9TestHarness::productStorefrontInput($stack, $oidSf)
        : Phase9TestHarness::cartStorefrontInput($stack, $oidSf);
    $secondSf = $stack['storefront']->submit($input2);
    mtucAud009F04_assert(empty($secondSf['success']), $entry . ': second fails');
    mtucAud009F04_assert(
        Phase7TestHarness::countOrderPosts($transport) === $posts,
        $entry . ': no additional CP create'
    );
}

// -------------------------------------------------------------------------
if (count($failures) > 0) {
    echo 'AUD-009 F04: FAIL (' . count($failures) . ' failures, ' . $passes . ' passes)' . PHP_EOL;
    exit(1);
}

echo 'AUD-009 F04: PASS (' . $passes . ' passes)' . PHP_EOL;
exit(0);
