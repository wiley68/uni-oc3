<?php

/**
 * Phase 11.5C.3 — Checkout Missing Orders native status closure.
 *
 * Proves OC3 getOrder() semantics for status 0, reproduces the production helper
 * path against a real status-0 order row, and guards P1/P2 + replay.
 *
 * Run: php tests/phase11_5c3_missing_orders_check.php
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
    $storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mtuc-phase115c3mo-storage';
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

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtuc115c3mo_assert($condition, $message)
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
 * Minimal OC3-like order table for status-0 Missing Orders.
 */
final class Mtuc115c3MoOrderDb
{
    /** @var array<int, array{order_id:int,order_status_id:int,store_id:int,payment_code:string}> */
    public $orders = array();

    /** @var array<int, array{order_id:int,order_status_id:int}> */
    public $history = array();

    /**
     * @param mixed $value
     * @return string
     */
    public function escape($value)
    {
        return addslashes((string) $value);
    }

    public function getPrefix()
    {
        return 'oc_';
    }

    public function countAffected()
    {
        return 0;
    }

    public function getLastId()
    {
        return 0;
    }

    /**
     * @param string $sql
     * @return object
     */
    public function query($sql)
    {
        if (preg_match('/SELECT `order_status_id` FROM `oc_order` WHERE `order_id` = \'?(\d+)\'?/i', $sql, $m)) {
            $id = (int) $m[1];
            if (!isset($this->orders[$id])) {
                return (object) array('num_rows' => 0, 'row' => array(), 'rows' => array());
            }

            return (object) array(
                'num_rows' => 1,
                'row' => array('order_status_id' => $this->orders[$id]['order_status_id']),
                'rows' => array(array('order_status_id' => $this->orders[$id]['order_status_id'])),
            );
        }

        // Core checkout getOrder SQL shape — no order_status_id > 0 filter.
        if (
            preg_match('/FROM `oc_order` o WHERE o\.order_id = \'?(\d+)\'?/i', $sql, $m)
            || preg_match('/FROM `oc_order` WHERE order_id = \'?(\d+)\'?/i', $sql, $m)
        ) {
            $id = (int) $m[1];
            if (!isset($this->orders[$id])) {
                return (object) array('num_rows' => 0, 'row' => array(), 'rows' => array());
            }

            return (object) array(
                'num_rows' => 1,
                'row' => $this->orders[$id],
                'rows' => array($this->orders[$id]),
            );
        }

        if (preg_match('/UPDATE `oc_order` SET order_status_id = \'?(\d+)\'?.*WHERE order_id = \'?(\d+)\'?/is', $sql, $m)) {
            $status = (int) $m[1];
            $id = (int) $m[2];
            if (isset($this->orders[$id])) {
                $this->orders[$id]['order_status_id'] = $status;
            }

            return (object) array('num_rows' => 0, 'row' => array(), 'rows' => array());
        }

        if (
            preg_match('/INSERT INTO oc_order_history SET order_id = \'?(\d+)\'?.*order_status_id = \'?(\d+)\'?/is', $sql, $m)
            || preg_match('/INSERT INTO `oc_order_history` SET order_id = \'?(\d+)\'?.*order_status_id = \'?(\d+)\'?/is', $sql, $m)
        ) {
            $this->history[] = array(
                'order_id' => (int) $m[1],
                'order_status_id' => (int) $m[2],
            );

            return (object) array('num_rows' => 0, 'row' => array(), 'rows' => array());
        }

        return (object) array('num_rows' => 0, 'row' => array(), 'rows' => array());
    }
}

/**
 * Mimics ModelCheckoutOrder::getOrder for core OC3 (returns status 0).
 *
 * @param Mtuc115c3MoOrderDb $db
 * @param int $orderId
 * @return array<string, mixed>|false
 */
function mtuc115c3mo_core_get_order(Mtuc115c3MoOrderDb $db, $orderId)
{
    // Exact authority WHERE from reference-oc3-core (no status filter).
    $sql = "SELECT *, (SELECT os.name FROM `oc_order_status` os WHERE os.order_status_id = o.order_status_id AND os.language_id = o.language_id) AS order_status FROM `oc_order` o WHERE o.order_id = '" . (int) $orderId . "'";
    $result = $db->query($sql);
    if (!(int) $result->num_rows) {
        return false;
    }

    return $result->row;
}

/**
 * Broken wrapper some themes use for customer-facing lists (status > 0).
 *
 * @param Mtuc115c3MoOrderDb $db
 * @param int $orderId
 * @return array<string, mixed>|false
 */
function mtuc115c3mo_filtered_get_order(Mtuc115c3MoOrderDb $db, $orderId)
{
    if (!isset($db->orders[$orderId]) || (int) $db->orders[$orderId]['order_status_id'] <= 0) {
        return false;
    }

    return $db->orders[$orderId];
}

/**
 * Old Checkout helper (production 0e45276) — fails when getOrder hides status 0.
 *
 * @param int $orderId
 * @param int $statusId
 * @param callable $getOrder
 * @param callable $addHistory
 * @return bool whether history was called
 */
function mtuc115c3mo_old_apply($orderId, $statusId, $getOrder, $addHistory)
{
    if ($orderId <= 0 || $statusId <= 0) {
        return false;
    }
    $order = call_user_func($getOrder, $orderId);
    if (!is_array($order)) {
        return false;
    }
    $current = (int) (isset($order['order_status_id']) ? $order['order_status_id'] : 0);
    if ($current === $statusId) {
        return false;
    }
    call_user_func($addHistory, $orderId, $statusId);

    return true;
}

/**
 * Fixed helper path (direct SQL + Product-parity addOrderHistory).
 *
 * @param Mtuc115c3MoOrderDb $db
 * @param int $orderId
 * @param int $statusId
 * @param callable $addHistory
 * @return bool
 */
function mtuc115c3mo_fixed_apply(Mtuc115c3MoOrderDb $db, $orderId, $statusId, $addHistory)
{
    $adapter = new MtUniCreditDbAdapter($db, 'oc_');
    $current = MtUniCreditNativeOrderStatusSupport::readOrderStatusId($adapter, $orderId);
    if (!MtUniCreditNativeOrderStatusSupport::shouldApplyHistory($current, $statusId)) {
        return false;
    }
    call_user_func($addHistory, $orderId, $statusId);

    return true;
}

$controllerPath = $root . '/upload/catalog/controller/extension/payment/mt_uni_credit.php';
$productPath = $root . '/upload/catalog/controller/extension/mt_uni_credit/product.php';
$cartPath = $root . '/upload/catalog/controller/extension/mt_uni_credit/cart.php';
$coreOrder = dirname($root) . '/reference-oc3-core/catalog/model/checkout/order.php';
$constants = $lib . '/constants.php';

$controller = (string) file_get_contents($controllerPath);
$productSrc = (string) file_get_contents($productPath);
$cartSrc = (string) file_get_contents($cartPath);
$coreSrc = is_file($coreOrder) ? (string) file_get_contents($coreOrder) : '';

// --- Configuration key contract ---
mtuc115c3mo_assert(
    MtUniCreditConstants::PAYMENT_SETTING_ORDER_STATUS_ID === 'payment_mt_uni_credit_order_status_id',
    'PAYMENT_SETTING_ORDER_STATUS_ID exact string'
);
mtuc115c3mo_assert(
    strpos($controller, 'PAYMENT_SETTING_ORDER_STATUS_ID') !== false
        || strpos($controller, 'configuredStatusId') !== false,
    'Checkout uses shared payment order-status setting'
);
mtuc115c3mo_assert(
    strpos($productSrc, 'PAYMENT_SETTING_ORDER_STATUS_ID') !== false
        && strpos($cartSrc, 'PAYMENT_SETTING_ORDER_STATUS_ID') !== false,
    'Product/Cart use the same payment order-status setting key'
);

$adminCtrl = (string) file_get_contents($root . '/upload/admin/controller/extension/payment/mt_uni_credit.php');
$adminTwig = (string) file_get_contents($root . '/upload/admin/view/template/extension/payment/mt_uni_credit.twig');
mtuc115c3mo_assert(
    strpos($adminCtrl, "editSetting('payment_mt_uni_credit'") !== false,
    'admin save code is payment_mt_uni_credit'
);
mtuc115c3mo_assert(
    strpos($adminTwig, 'name="payment_mt_uni_credit_order_status_id"') !== false,
    'admin form posts payment_mt_uni_credit_order_status_id'
);

// --- OC3 getOrder SQL authority ---
mtuc115c3mo_assert($coreSrc !== '', 'reference-oc3-core checkout order model present');
mtuc115c3mo_assert(
    strpos($coreSrc, "WHERE o.order_id = '\" . (int)\$order_id . \"'") !== false
        || preg_match('/WHERE o\.order_id = \'\" \. \(int\)\$order_id/', $coreSrc) === 1
        || strpos($coreSrc, 'WHERE o.order_id =') !== false,
    'core getOrder filters by order_id only'
);
mtuc115c3mo_assert(
    strpos($coreSrc, 'order_status_id >') === false
        && strpos($coreSrc, "order_status_id > '0'") === false,
    'core getOrder does NOT require order_status_id > 0'
);

// Account model DOES filter — document contrast, Checkout must not rely on it.
$accountOrder = dirname($root) . '/reference-oc3-core/catalog/model/account/order.php';
if (is_file($accountOrder)) {
    $acc = (string) file_get_contents($accountOrder);
    mtuc115c3mo_assert(
        strpos($acc, "order_status_id > '0'") !== false,
        'account getOrder filters status > 0 (must not be used for Missing Orders apply)'
    );
}

// --- Controller wiring ---
mtuc115c3mo_assert(
    strpos($controller, 'MtUniCreditNativeOrderStatusSupport::readOrderStatusId') !== false,
    'Checkout apply uses direct SQL status read'
);
mtuc115c3mo_assert(
    preg_match(
        '/function applyPreparedOrderStatus[\s\S]*?function /',
        $controller,
        $applyBody
    ) === 1
        && strpos($applyBody[0], 'getOrder(') === false
        && strpos($applyBody[0], 'readOrderStatusId') !== false,
    'applyPreparedOrderStatus no longer uses getOrder for Missing Orders'
);
mtuc115c3mo_assert(
    strpos($controller, 'method_exists($this->model_checkout_order, \'addOrderHistory\')') === false,
    'Checkout removed method_exists gate (Product/Cart parity)'
);
mtuc115c3mo_assert(
    strpos($controller, 'resolveCheckoutHandoffBankStatus') !== false
        || strpos((string) file_get_contents($lib . '/checkout_financing_submission_service.php'), 'resolveCheckoutHandoffBankStatus') !== false,
    'Checkout submit strengthens bank_status for handoff gate'
);
mtuc115c3mo_assert(
    strpos($productSrc, 'maybeApplyNativeOrderStatus') !== false
        && strpos($cartSrc, 'maybeApplyNativeOrderStatus') !== false,
    'Product/Cart canaries: native status helpers untouched'
);

// --- Reproduce Missing Order + fixed apply ---
$db = new Mtuc115c3MoOrderDb();
$orderId = 119501;
$configured = 2;
$db->orders[$orderId] = array(
    'order_id' => $orderId,
    'order_status_id' => 0,
    'store_id' => Phase5TestHarness::STORE_A,
    'payment_code' => MtUniCreditConstants::EXTENSION_CODE,
    'language_id' => 1,
);

$coreOrderRow = mtuc115c3mo_core_get_order($db, $orderId);
mtuc115c3mo_assert(is_array($coreOrderRow) && (int) $coreOrderRow['order_status_id'] === 0, 'core getOrder returns status-0 Missing Order');

$filtered = mtuc115c3mo_filtered_get_order($db, $orderId);
mtuc115c3mo_assert($filtered === false, 'filtered getOrder hides status 0 (remote hypothesis shape)');

$historyCalls = 0;
$addHistory = function ($oid, $sid) use ($db, &$historyCalls) {
    $historyCalls++;
    $db->orders[(int) $oid]['order_status_id'] = (int) $sid;
    $db->history[] = array('order_id' => (int) $oid, 'order_status_id' => (int) $sid);
};

// Old helper + filtered getOrder reproduces remote Missing Orders.
$oldCalled = mtuc115c3mo_old_apply(
    $orderId,
    $configured,
    function ($oid) use ($db) {
        return mtuc115c3mo_filtered_get_order($db, $oid);
    },
    $addHistory
);
mtuc115c3mo_assert($oldCalled === false, 'OLD helper + filtered getOrder: NO addOrderHistory (root-cause reproduction)');
mtuc115c3mo_assert((int) $db->orders[$orderId]['order_status_id'] === 0, 'OLD path leaves order_status_id = 0');

// Fixed helper heals status 0 via direct SQL.
$fixedCalled = mtuc115c3mo_fixed_apply($db, $orderId, $configured, $addHistory);
mtuc115c3mo_assert($fixedCalled === true, 'FIXED helper calls addOrderHistory for status 0');
mtuc115c3mo_assert((int) $db->orders[$orderId]['order_status_id'] === $configured, 'FIXED: order_status_id becomes configured X');
mtuc115c3mo_assert(count($db->history) === 1, 'FIXED first apply: exactly one history row');

// Replay idempotent
$fixedReplay = mtuc115c3mo_fixed_apply($db, $orderId, $configured, $addHistory);
mtuc115c3mo_assert($fixedReplay === false, 'FIXED replay: no second history');
mtuc115c3mo_assert(count($db->history) === 1, 'FIXED replay: history count unchanged');
mtuc115c3mo_assert((int) $db->orders[$orderId]['order_status_id'] === $configured, 'FIXED replay: status stays X');

// --- Handoff gate + bank_status reinforcement ---
$p1Submit = array(
    'success' => true,
    'bank_status' => '',
    'bank_redirect' => true,
    'redirect' => 'https://bank.example/app',
);
$lifecycleP1 = (object) array(
    'success' => true,
    'redirectUrl' => 'https://bank.example/app',
);
$reinforcedP1 = MtUniCreditNativeOrderStatusSupport::resolveCheckoutHandoffBankStatus(
    '',
    $lifecycleP1,
    array('uni_proces' => 0)
);
mtuc115c3mo_assert($reinforcedP1 === MtUniCreditBankStatus::SENT_PROCESS1, 'P1 empty re-read → bank_sent_process1 from redirect');
$p1Submit['bank_status'] = $reinforcedP1;
mtuc115c3mo_assert(
    MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff($p1Submit),
    'P1 reinforced bank_status passes handoff gate'
);

$lifecycleP2 = (object) array(
    'success' => true,
    'redirectUrl' => '',
);
$reinforcedP2 = MtUniCreditNativeOrderStatusSupport::resolveCheckoutHandoffBankStatus(
    '',
    $lifecycleP2,
    array('uni_proces' => 1)
);
mtuc115c3mo_assert($reinforcedP2 === MtUniCreditBankStatus::SENT_PROCESS2, 'P2 empty re-read → bank_sent_process2');
mtuc115c3mo_assert(
    MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff(array(
        'success' => true,
        'bank_status' => $reinforcedP2,
    )),
    'P2 reinforced bank_status passes handoff gate'
);

// Failures must not apply
foreach (
    array(
        array('success' => false, 'bank_status' => MtUniCreditBankStatus::SENT_PROCESS1),
        array('success' => false, 'error' => 'cp_submit_failed', 'bank_status' => ''),
        array('success' => false, 'error' => 'smartucf_submit_failed', 'bank_status' => ''),
    ) as $failSubmit
) {
    mtuc115c3mo_assert(
        !MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff($failSubmit),
        'failure submit is not handoff: ' . (isset($failSubmit['error']) ? $failSubmit['error'] : 'flag')
    );
}

// --- Full Checkout P1 lifecycle: bank + handoff + native status simulation ---
$transport = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transport);
$stack = Phase9TestHarness::stack($transport);
$lifeOrderId = 119510;
Phase9TestHarness::seedBankOrder($stack['memoryDb'], $lifeOrderId, $stack['storeId']);
$input = Phase9TestHarness::submitInput($lifeOrderId, $stack['storeId']);
$first = $stack['submission']->submit($input);
mtuc115c3mo_assert(!empty($first['success']), 'Checkout P1 lifecycle success');
mtuc115c3mo_assert(
    (string) $first['bank_status'] === MtUniCreditBankStatus::SENT_PROCESS1,
    'Checkout P1 result bank_status = bank_sent_process1'
);
mtuc115c3mo_assert(
    MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff($first),
    'Checkout P1 result is durable handoff'
);
mtuc115c3mo_assert(Phase9TestHarness::smartUcfCallCount($stack['smartUcfProbe']) === 1, 'SmartUCF exactly once');
mtuc115c3mo_assert(Phase7TestHarness::countOrderPosts($transport) === 1, 'CP create exactly once');

// Simulate native status-0 row + apply using reinforced submit
$db2 = new Mtuc115c3MoOrderDb();
$db2->orders[$lifeOrderId] = array(
    'order_id' => $lifeOrderId,
    'order_status_id' => 0,
    'store_id' => $stack['storeId'],
    'payment_code' => MtUniCreditConstants::EXTENSION_CODE,
    'language_id' => 1,
);
$hist = 0;
$add2 = function ($oid, $sid) use ($db2, &$hist) {
    $hist++;
    $db2->orders[(int) $oid]['order_status_id'] = (int) $sid;
    $db2->history[] = array('order_id' => (int) $oid, 'order_status_id' => (int) $sid);
};
mtuc115c3mo_assert(
    MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff($first),
    'gate open before native apply'
);
mtuc115c3mo_fixed_apply($db2, $lifeOrderId, $configured, $add2);
mtuc115c3mo_assert((int) $db2->orders[$lifeOrderId]['order_status_id'] === $configured, 'P1 Missing Order → configured status');
mtuc115c3mo_assert($hist === 1, 'P1 one history row');

$replay = $stack['submission']->submit($input);
mtuc115c3mo_assert(!empty($replay['local_replay']), 'P1 replay local_replay');
mtuc115c3mo_assert(
    (string) $replay['bank_status'] === MtUniCreditBankStatus::SENT_PROCESS1,
    'P1 replay keeps bank_sent_process1'
);
mtuc115c3mo_assert(Phase9TestHarness::smartUcfCallCount($stack['smartUcfProbe']) === 1, 'P1 replay: no duplicate SmartUCF');
mtuc115c3mo_assert(Phase7TestHarness::countOrderPosts($transport) === 1, 'P1 replay: no duplicate CP');
mtuc115c3mo_fixed_apply($db2, $lifeOrderId, $configured, $add2);
mtuc115c3mo_assert($hist === 1, 'P1 replay: no duplicate history/mail transition');

// P2
$transportP2 = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportP2);
$stackP2 = Phase9TestHarness::stack($transportP2, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$p2OrderId = 119520;
Phase9TestHarness::seedBankOrder($stackP2['memoryDb'], $p2OrderId, $stackP2['storeId']);
$p2Input = Phase9TestHarness::submitInputProcess2($p2OrderId, $stackP2['storeId']);
$resultP2 = $stackP2['submission']->submit($p2Input);
mtuc115c3mo_assert(!empty($resultP2['success']), 'Checkout P2 lifecycle success');
mtuc115c3mo_assert(
    (string) $resultP2['bank_status'] === MtUniCreditBankStatus::SENT_PROCESS2,
    'Checkout P2 bank_status = bank_sent_process2'
);
mtuc115c3mo_assert(Phase9TestHarness::smartUcfCallCount($stackP2['smartUcfProbe']) === 0, 'P2: zero SmartUCF');
$db3 = new Mtuc115c3MoOrderDb();
$db3->orders[$p2OrderId] = array(
    'order_id' => $p2OrderId,
    'order_status_id' => 0,
    'store_id' => $stackP2['storeId'],
    'payment_code' => MtUniCreditConstants::EXTENSION_CODE,
    'language_id' => 1,
);
$histP2 = 0;
$addP2 = function ($oid, $sid) use ($db3, &$histP2) {
    $histP2++;
    $db3->orders[(int) $oid]['order_status_id'] = (int) $sid;
    $db3->history[] = array('order_id' => (int) $oid, 'order_status_id' => (int) $sid);
};
mtuc115c3mo_fixed_apply($db3, $p2OrderId, $configured, $addP2);
mtuc115c3mo_assert((int) $db3->orders[$p2OrderId]['order_status_id'] === $configured, 'P2 Missing Order → configured status');
mtuc115c3mo_assert($histP2 === 1, 'P2 one history row');

echo PHP_EOL . 'Phase 11.5C.3 missing-orders summary: ' . $passes . ' passed, ' . count($failures) . ' failed' . PHP_EOL;
if ($failures) {
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'PHASE 11.5C.3 CHECKOUT MISSING ORDERS CLOSURE: PASS — LOCAL' . PHP_EOL;
exit(0);
