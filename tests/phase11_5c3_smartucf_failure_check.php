<?php

/**
 * Phase 11.5C.3 — Checkout P1 definitive SmartUCF failure → Thank You parity.
 *
 * Authority: Product/Cart OC3 + OC4 applyCheckoutUniCreditOrderStatus
 * (payment_mt_uni_credit_order_status_id on terminal remote_reject).
 *
 * Run: php tests/phase11_5c3_smartucf_failure_check.php
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
    $storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mtuc-phase115c3sf-storage';
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
function mtuc115c3sf_assert($condition, $message)
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
function mtuc115c3sf_read($path)
{
    $body = @file_get_contents($path);

    return is_string($body) ? $body : '';
}

/**
 * Simulate Checkout confirm() terminal JSON after submit (Product/Cart parity).
 *
 * @param array<string, mixed> $submit
 * @param int $orderId
 * @param array<string, mixed> $session
 * @return array<string, mixed>
 */
function mtuc115c3sf_confirmJson(array $submit, $orderId, array &$session)
{
    $orderId = (int) $orderId;
    if (empty($submit['order_id']) && $orderId > 0) {
        $submit['order_id'] = $orderId;
    }

    if (!MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveRemoteRejectTerminal($submit)) {
        return array(
            'success' => false,
            'error' => isset($submit['message']) ? (string) $submit['message'] : 'stay',
            'error_code' => isset($submit['error']) ? (string) $submit['error'] : '',
        );
    }

    $json = array(
        'success' => false,
        'error' => isset($submit['error']) ? (string) $submit['error'] : 'remote_reject',
        'order_id' => $orderId,
        'cp_succeeded' => !empty($submit['cp_succeeded']),
        'bank_status' => isset($submit['bank_status']) ? (string) $submit['bank_status'] : '',
        'apply_native_order_status' => !empty($submit['apply_native_order_status']),
    );
    if (array_key_exists('recoverable', $submit)) {
        $json['recoverable'] = !empty($submit['recoverable']);
    }

    return MtUniCreditFinancingTerminalNavigationSupport::enrichDefinitiveRemoteRejectThankYou(
        $json,
        $session,
        $orderId,
        'https://shop.test/index.php?route=checkout/success'
    );
}

/**
 * Minimal status-0 order table for native failure finalization.
 */
final class Mtuc115c3SfOrderDb
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

$controllerPath = $root . '/upload/catalog/controller/extension/payment/mt_uni_credit.php';
$checkoutSubmitPath = $root . '/upload/system/library/mt_uni_credit/checkout_financing_submission_service.php';
$productPath = $root . '/upload/catalog/controller/extension/mt_uni_credit/product.php';
$cartPath = $root . '/upload/catalog/controller/extension/mt_uni_credit/cart.php';
$jsPath = $root . '/upload/catalog/view/theme/default/template/extension/payment/mt_uni_credit_checkout.js';
$oc4Ctrl = dirname($root) . '/reference-uni-oc4/catalog/controller/payment/mt_uni_credit.php';

$controller = mtuc115c3sf_read($controllerPath);
$checkoutSubmitSrc = mtuc115c3sf_read($checkoutSubmitPath);
$productSrc = mtuc115c3sf_read($productPath);
$cartSrc = mtuc115c3sf_read($cartPath);
$js = mtuc115c3sf_read($jsPath);
$oc4 = mtuc115c3sf_read($oc4Ctrl);

// --- Source wiring ---
mtuc115c3sf_assert(
    strpos($controller, 'isDefinitiveRemoteRejectTerminal') !== false,
    'Checkout confirm: isDefinitiveRemoteRejectTerminal'
);
mtuc115c3sf_assert(
    strpos($controller, 'enrichDefinitiveRemoteRejectThankYou') !== false,
    'Checkout confirm: enrichDefinitiveRemoteRejectThankYou'
);
mtuc115c3sf_assert(
    strpos($controller, 'maybeApplyCheckoutNativeOrderStatusOnDefinitiveFailure') !== false,
    'Checkout: separate definitive-failure native status helper'
);
mtuc115c3sf_assert(
    strpos($controller, 'isSuccessfulBankHandoff') !== false
        && strpos($controller, 'maybeApplyCheckoutNativeOrderStatusAfterHandoff') !== false,
    'Checkout: success handoff helper still separate'
);
mtuc115c3sf_assert(
    preg_match(
        "/'success'\\s*=>\\s*false[\\s\\S]{0,400}'order_id'\\s*=>\\s*\\\$orderId/",
        $checkoutSubmitSrc
    ) === 1,
    'Checkout submit failure payload includes order_id'
);
mtuc115c3sf_assert(
    strpos($productSrc, 'isDefinitiveRemoteRejectTerminal') !== false
        && strpos($cartSrc, 'isDefinitiveRemoteRejectTerminal') !== false,
    'Product/Cart canary wiring intact'
);
mtuc115c3sf_assert(
    strpos($oc4, 'applyCheckoutUniCreditOrderStatus') !== false
        && strpos($oc4, 'PAYMENT_ORDER_STATUS_SETTING') !== false,
    'OC4 authority: applyCheckoutUniCreditOrderStatus uses payment order status'
);
mtuc115c3sf_assert(
    strpos($js, 'json.redirect') !== false
        && strpos($js, 'Keep loader ON until navigation') !== false,
    'Checkout JS: redirect keeps loader until navigation'
);
mtuc115c3sf_assert(
    MtUniCreditConstants::PAYMENT_SETTING_ORDER_STATUS_ID === 'payment_mt_uni_credit_order_status_id',
    'native failure status key = payment_mt_uni_credit_order_status_id'
);
mtuc115c3sf_assert(
    MtUniCreditBankStatus::SEND_FAILED_SMARTUCF === 'bank_send_failed_smartucf',
    'bank status id bank_send_failed_smartucf'
);
mtuc115c3sf_assert(
    MtUniCreditBankStatus::LABEL_SEND_FAILED_SMARTUCF === 'Неуспешно изпратен Банка - SmartUCF',
    'bank status BG label'
);

// Success handoff must NOT treat SmartUCF failure as success.
mtuc115c3sf_assert(
    !MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff(array(
        'success' => false,
        'bank_status' => MtUniCreditBankStatus::SEND_FAILED_SMARTUCF,
        'cp_succeeded' => true,
        'order_id' => 1,
        'error' => 'remote_reject',
    )),
    'isSuccessfulBankHandoff excludes bank_send_failed_smartucf'
);

// ---------------------------------------------------------------------------
// Checkout P1 logged + guest — broken SmartUCF
// ---------------------------------------------------------------------------
foreach (array('logged' => 81, 'guest' => 0) as $actor => $customerId) {
    $transport = new Phase4FakeCpHttpTransport();
    Phase9TestHarness::enqueueCpCreateSuccess($transport);
    $stack = Phase9TestHarness::stack(
        $transport,
        function (array $options): array {
            return array(
                'body' => Phase9TestHarness::rejectBody(),
                'error' => '',
                'http_code' => 400,
            );
        }
    );

    $orderId = 205100 + (int) $customerId;
    // Native checkout allocates the order BEFORE CP/SmartUCF (status 0 Missing Orders).
    Phase9TestHarness::seedBankOrder($stack['memoryDb'], $orderId, $stack['storeId']);
    $ordersProp = (new ReflectionClass($stack['memoryDb']))->getProperty('orders');
    $ordersProp->setAccessible(true);
    $ordersMap = $ordersProp->getValue($stack['memoryDb']);
    mtuc115c3sf_assert(
        isset($ordersMap[$orderId]),
        'P1 ' . $actor . ': OC order row exists before submit'
    );
    $orderCountBefore = count($ordersMap);

    $nativeDb = new Mtuc115c3SfOrderDb();
    $nativeDb->orders[$orderId] = array(
        'order_id' => $orderId,
        'order_status_id' => 0,
        'store_id' => (int) $stack['storeId'],
        'payment_code' => MtUniCreditConstants::EXTENSION_CODE,
    );
    $configured = 5;
    mtuc115c3sf_assert(
        (int) MtUniCreditNativeOrderStatusSupport::readOrderStatusId($nativeDb, $orderId) === 0,
        'P1 ' . $actor . ': initial order_status_id = 0'
    );

    $input = Phase9TestHarness::submitInput($orderId, $stack['storeId']);
    // Guest vs logged does not change Checkout submit lifecycle (no customer_id gate).
    $input['customer_id'] = (int) $customerId;

    $submit = $stack['submission']->submit($input);

    mtuc115c3sf_assert(empty($submit['success']), 'P1 ' . $actor . ': overall failure');
    mtuc115c3sf_assert((int) $submit['order_id'] === $orderId, 'P1 ' . $actor . ': order_id on failure result');
    mtuc115c3sf_assert(!empty($submit['cp_succeeded']), 'P1 ' . $actor . ': cp_succeeded');
    mtuc115c3sf_assert(
        (string) $submit['error'] === MtUniCreditSmartUcfFailureClassification::CLASS_REMOTE_REJECT,
        'P1 ' . $actor . ': error=remote_reject'
    );
    mtuc115c3sf_assert(empty($submit['recoverable']), 'P1 ' . $actor . ': not recoverable');
    mtuc115c3sf_assert(empty($submit['ambiguous_blocked']), 'P1 ' . $actor . ': not ambiguous');
    mtuc115c3sf_assert(!empty($submit['apply_native_order_status']), 'P1 ' . $actor . ': apply_native authorised');
    mtuc115c3sf_assert(
        (string) $submit['bank_status'] === MtUniCreditBankStatus::SEND_FAILED_SMARTUCF,
        'P1 ' . $actor . ': bank_send_failed_smartucf'
    );
    mtuc115c3sf_assert(
        MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveRemoteRejectTerminal($submit),
        'P1 ' . $actor . ': definitive terminal detector'
    );
    mtuc115c3sf_assert(
        Phase7TestHarness::countOrderPosts($transport) === 1,
        'P1 ' . $actor . ': CP create = 1'
    );
    mtuc115c3sf_assert(
        Phase9TestHarness::smartUcfCallCount($stack['smartUcfProbe']) === 1,
        'P1 ' . $actor . ': SmartUCF = 1'
    );
    $ordersAfter = $ordersProp->getValue($stack['memoryDb']);
    mtuc115c3sf_assert(
        isset($ordersAfter[$orderId]) && count($ordersAfter) === $orderCountBefore,
        'P1 ' . $actor . ': same single OC order after failure'
    );

    // Native status finalization (separate from success handoff).
    mtuc115c3sf_assert(
        !MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff($submit),
        'P1 ' . $actor . ': NOT successful handoff (no success cart clear)'
    );
    $cartProbe = new class {
        public $cleared = false;

        public function clear()
        {
            $this->cleared = true;
        }

        public function countProducts()
        {
            return 2;
        }
    };
    $cleared = MtUniCreditFinancingTerminalNavigationSupport::clearCartAfterSuccessfulHandoff(
        $submit,
        $cartProbe
    );
    mtuc115c3sf_assert($cleared === false && $cartProbe->cleared === false, 'P1 ' . $actor . ': cart NOT cleared');

    if (
        !empty($submit['apply_native_order_status'])
        && MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveRemoteRejectTerminal($submit)
    ) {
        $current = MtUniCreditNativeOrderStatusSupport::readOrderStatusId($nativeDb, $orderId);
        if (MtUniCreditNativeOrderStatusSupport::shouldApplyHistory($current, $configured)) {
            $nativeDb->orders[$orderId]['order_status_id'] = $configured;
            $nativeDb->history[] = array('order_id' => $orderId, 'order_status_id' => $configured);
        }
    }
    mtuc115c3sf_assert(
        (int) $nativeDb->orders[$orderId]['order_status_id'] === $configured,
        'P1 ' . $actor . ': native status → configured payment status (>0)'
    );
    mtuc115c3sf_assert(count($nativeDb->history) === 1, 'P1 ' . $actor . ': one order_history row');

    $session = array('order_id' => $orderId);
    if ($actor === 'logged') {
        $session['customer_id'] = (int) $customerId;
    }
    $json = mtuc115c3sf_confirmJson($submit, $orderId, $session);
    mtuc115c3sf_assert(
        !empty($json['redirect']) && strpos((string) $json['redirect'], 'checkout/success') !== false,
        'P1 ' . $actor . ': redirect checkout/success'
    );
    mtuc115c3sf_assert(
        (int) $session['order_id'] === $orderId
            && (int) $session[MtUniCreditFinancingTerminalNavigationSupport::SESSION_SUCCESS_ORDER_ID] === $orderId,
        'P1 ' . $actor . ': Thank You session identity'
    );
    mtuc115c3sf_assert(
        (string) $json['message'] === MtUniCreditFinancingLeasingPresenter::SMARTUCF_TERMINAL_FAILURE_MESSAGE,
        'P1 ' . $actor . ': shared SMARTUCF_TERMINAL_FAILURE_MESSAGE'
    );
    mtuc115c3sf_assert(
        (string) $json['step'] === MtUniCreditFinancingTerminalNavigationSupport::STEP_SMARTUCF_TERMINAL_FAILED,
        'P1 ' . $actor . ': step smartucf_terminal_failed'
    );
    mtuc115c3sf_assert(empty($json['success']), 'P1 ' . $actor . ': JSON success remains false');

    $adapter = new MtUniCreditDbAdapter($stack['memoryDb'], 'oc_');
    $svc = new MtUniCreditFinancingPresentationService(
        new MtUniCreditFinancingPresentationRepository($adapter)
    );
    $tyHtml = $svc->renderCustomerThankYouHtml(
        $svc->customerThankYouRows($stack['storeId'], $orderId)
    );
    mtuc115c3sf_assert(
        strpos($tyHtml, MtUniCreditFinancingLeasingPresenter::SMARTUCF_TERMINAL_FAILURE_MESSAGE) !== false,
        'P1 ' . $actor . ': Thank You presenter failure text'
    );
    mtuc115c3sf_assert(
        strpos($tyHtml, MtUniCreditBankStatus::LABEL_SEND_FAILED_SMARTUCF) !== false,
        'P1 ' . $actor . ': Thank You shows SmartUCF bank label'
    );

    // Replay: no duplicate CP / SmartUCF
    $replay = $stack['submission']->submit($input);
    mtuc115c3sf_assert(
        Phase7TestHarness::countOrderPosts($transport) === 1,
        'P1 ' . $actor . ' replay: CP create still 1'
    );
    mtuc115c3sf_assert(
        Phase9TestHarness::smartUcfCallCount($stack['smartUcfProbe']) === 1,
        'P1 ' . $actor . ' replay: SmartUCF still 1'
    );
    mtuc115c3sf_assert(
        MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveRemoteRejectTerminal($replay)
            || (string) Phase9TestHarness::bankStatusId($stack, $orderId) === MtUniCreditBankStatus::SEND_FAILED_SMARTUCF,
        'P1 ' . $actor . ' replay: remains terminal failed'
    );
}

// ---------------------------------------------------------------------------
// Ambiguous SmartUCF must NOT Thank You
// ---------------------------------------------------------------------------
$transportAmb = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportAmb);
$stackAmb = Phase9TestHarness::stack(
    $transportAmb,
    function (array $options): array {
        return array(
            'body' => '',
            'error' => 'timeout',
            'http_code' => 0,
        );
    }
);
$ambOrder = 205199;
Phase9TestHarness::seedBankOrder($stackAmb['memoryDb'], $ambOrder, $stackAmb['storeId']);
$ambSubmit = $stackAmb['submission']->submit(Phase9TestHarness::submitInput($ambOrder, $stackAmb['storeId']));
mtuc115c3sf_assert(empty($ambSubmit['success']), 'ambiguous: failure');
mtuc115c3sf_assert(
    !MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveRemoteRejectTerminal($ambSubmit),
    'ambiguous: NOT definitive Thank You terminal'
);
$sessionAmb = array('order_id' => $ambOrder);
$jsonAmb = mtuc115c3sf_confirmJson($ambSubmit, $ambOrder, $sessionAmb);
mtuc115c3sf_assert(
    empty($jsonAmb['redirect']),
    'ambiguous: stay Checkout (no checkout/success redirect)'
);

// ---------------------------------------------------------------------------
// Normal P1 / P2 canaries
// ---------------------------------------------------------------------------
$transportP1 = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportP1);
$stackP1 = Phase9TestHarness::stack(
    $transportP1,
    function (array $options): array {
        return array(
            'body' => Phase9TestHarness::successBody(),
            'error' => '',
            'http_code' => 200,
        );
    }
);
$p1Order = 205301;
Phase9TestHarness::seedBankOrder($stackP1['memoryDb'], $p1Order, $stackP1['storeId']);
$p1Ok = $stackP1['submission']->submit(Phase9TestHarness::submitInput($p1Order, $stackP1['storeId']));
mtuc115c3sf_assert(!empty($p1Ok['success']), 'canary P1: success');
mtuc115c3sf_assert(
    (string) $p1Ok['bank_status'] === MtUniCreditBankStatus::SENT_PROCESS1,
    'canary P1: bank_sent_process1'
);
mtuc115c3sf_assert(!empty($p1Ok['bank_redirect']), 'canary P1: bank redirect');
mtuc115c3sf_assert(
    MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff($p1Ok),
    'canary P1: successful handoff (cart clear eligible)'
);
mtuc115c3sf_assert(
    !MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveRemoteRejectTerminal($p1Ok),
    'canary P1: not failure terminal'
);

$transportP2 = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportP2);
$stackP2 = Phase9TestHarness::stack(
    $transportP2,
    null,
    null,
    Phase5TestHarness::STORE_A,
    array('uni_proces' => 1)
);
$p2Order = 205302;
Phase9TestHarness::seedBankOrder($stackP2['memoryDb'], $p2Order, $stackP2['storeId']);
$p2Ok = $stackP2['submission']->submit(Phase9TestHarness::submitInputProcess2($p2Order, $stackP2['storeId']));
mtuc115c3sf_assert(!empty($p2Ok['success']), 'canary P2: success');
mtuc115c3sf_assert(
    (string) $p2Ok['bank_status'] === MtUniCreditBankStatus::SENT_PROCESS2,
    'canary P2: bank_sent_process2'
);
mtuc115c3sf_assert(
    MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff($p2Ok),
    'canary P2: successful handoff'
);
mtuc115c3sf_assert(
    Phase9TestHarness::smartUcfCallCount($stackP2['smartUcfProbe']) === 0,
    'canary P2: no SmartUCF'
);

// ---------------------------------------------------------------------------
// Product / Cart broken Smart canaries
// ---------------------------------------------------------------------------
foreach (array('product', 'cart') as $entry) {
    $transport = new Phase4FakeCpHttpTransport();
    Phase9TestHarness::enqueueCpCreateSuccess($transport);
    $stack = Phase9TestHarness::stack(
        $transport,
        function (array $options): array {
            return array(
                'body' => Phase9TestHarness::rejectBody(),
                'error' => '',
                'http_code' => 400,
            );
        }
    );
    $oid = $entry === 'product' ? 205401 : 205402;
    $input = $entry === 'product'
        ? Phase9TestHarness::productStorefrontInput($stack, $oid)
        : Phase9TestHarness::cartStorefrontInput($stack, $oid);
    $result = $stack['storefront']->submit($input);
    mtuc115c3sf_assert(
        MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveRemoteRejectTerminal($result),
        $entry . ' canary: definitive SmartUCF terminal'
    );
    mtuc115c3sf_assert(
        Phase9TestHarness::bankStatusId($stack, $oid) === MtUniCreditBankStatus::SEND_FAILED_SMARTUCF,
        $entry . ' canary: bank_send_failed_smartucf'
    );
    $sess = array();
    $enriched = MtUniCreditFinancingTerminalNavigationSupport::enrichDefinitiveRemoteRejectThankYou(
        array('success' => false, 'order_id' => $oid),
        $sess,
        $oid,
        'https://shop.test/index.php?route=checkout/success'
    );
    mtuc115c3sf_assert(
        strpos((string) $enriched['redirect'], 'checkout/success') !== false,
        $entry . ' canary: Thank You redirect'
    );
}

echo PHP_EOL;
if ($failures === array()) {
    echo 'RESULT  PASS (' . $passes . ' assertions)' . PHP_EOL;
    exit(0);
}

echo 'RESULT  FAIL (' . count($failures) . ' failed / ' . $passes . ' passed)' . PHP_EOL;
foreach ($failures as $f) {
    echo '  - ' . $f . PHP_EOL;
}
exit(1);
