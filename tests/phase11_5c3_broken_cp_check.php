<?php

/**
 * Phase 11.5C.3 — Checkout broken CP → Thank You (Woo/PS cross-module parity).
 *
 * Authority: Woo / PS8 / PS9 Checkout broken CP.
 * Current OC4 stay-on-Checkout is known parity debt — NOT used as authority.
 * Product/Cart stay-page error modal must remain unchanged.
 *
 * Run: php tests/phase11_5c3_broken_cp_check.php
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
    $storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mtuc-phase115c3cp-storage';
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
function mtuc115c3cp_assert($condition, $message)
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
function mtuc115c3cp_read($path)
{
    $body = @file_get_contents($path);

    return is_string($body) ? $body : '';
}

/**
 * @param array<string, mixed> $submit
 * @param int $orderId
 * @param array<string, mixed> $session
 * @return array<string, mixed>
 */
function mtuc115c3cp_confirmJson(array $submit, $orderId, array &$session)
{
    $orderId = (int) $orderId;
    if (empty($submit['order_id']) && $orderId > 0) {
        $submit['order_id'] = $orderId;
    }

    if (MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveRemoteRejectTerminal($submit)) {
        return MtUniCreditFinancingTerminalNavigationSupport::enrichDefinitiveRemoteRejectThankYou(
            $submit,
            $session,
            $orderId,
            'https://shop.test/index.php?route=checkout/success'
        );
    }

    if (MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveCheckoutCpFailureTerminal($submit)) {
        return MtUniCreditFinancingTerminalNavigationSupport::enrichDefinitiveCheckoutCpFailureThankYou(
            array(
                'success' => false,
                'error' => isset($submit['error']) ? (string) $submit['error'] : 'cp_rejected',
                'order_id' => $orderId,
                'cp_succeeded' => false,
                'bank_status' => isset($submit['bank_status']) ? (string) $submit['bank_status'] : '',
            ),
            $session,
            $orderId,
            'https://shop.test/index.php?route=checkout/success'
        );
    }

    return array(
        'success' => false,
        'error' => isset($submit['message']) ? (string) $submit['message'] : 'stay',
    );
}

/**
 * Minimal status-0 order table for native failure finalization.
 */
final class Mtuc115c3CpOrderDb
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

        return (object) array('num_rows' => 0, 'row' => array(), 'rows' => array());
    }
}

$controllerPath = $root . '/upload/catalog/controller/extension/payment/mt_uni_credit.php';
$productPath = $root . '/upload/catalog/controller/extension/mt_uni_credit/product.php';
$cartPath = $root . '/upload/catalog/controller/extension/mt_uni_credit/cart.php';
$controller = mtuc115c3cp_read($controllerPath);
$productSrc = mtuc115c3cp_read($productPath);
$cartSrc = mtuc115c3cp_read($cartPath);

// --- Authority / constants ---
mtuc115c3cp_assert(
    MtUniCreditBankStatus::SEND_FAILED_CP === 'bank_send_failed_cp',
    'bank status id = bank_send_failed_cp'
);
mtuc115c3cp_assert(
    MtUniCreditBankStatus::LABEL_SEND_FAILED_CP === 'Неуспешно изпратен Банка - КП',
    'bank status BG label'
);
$cpFail = MtUniCreditBankStatus::controlPanelFailure(false);
mtuc115c3cp_assert(
    $cpFail['status_id'] === MtUniCreditBankStatus::SEND_FAILED_CP
        && $cpFail['status_label'] === MtUniCreditBankStatus::LABEL_SEND_FAILED_CP,
    'controlPanelFailure() returns SEND_FAILED_CP pair'
);
mtuc115c3cp_assert(
    MtUniCreditFinancingLeasingPresenter::CP_TERMINAL_FAILURE_TITLE === 'Поръчката е създадена',
    'CP_TERMINAL_FAILURE_TITLE exact'
);
mtuc115c3cp_assert(
    strpos(MtUniCreditFinancingLeasingPresenter::CP_TERMINAL_FAILURE_MESSAGE, 'потвърждението за регистрацията') !== false
        && strpos(MtUniCreditFinancingLeasingPresenter::CP_TERMINAL_FAILURE_MESSAGE, 'Не изпращайте поръчката повторно.') !== false
        && strpos(MtUniCreditFinancingLeasingPresenter::CP_TERMINAL_FAILURE_MESSAGE, 'Търговецът ще провери статуса на заявката.') !== false,
    'CP_TERMINAL_FAILURE_MESSAGE exact paragraphs'
);
mtuc115c3cp_assert(
    strpos($controller, 'isDefinitiveCheckoutCpFailureTerminal') !== false
        && strpos($controller, 'enrichDefinitiveCheckoutCpFailureThankYou') !== false,
    'Checkout controller wires CP Thank You terminal'
);
mtuc115c3cp_assert(
    strpos($productSrc, 'isDefinitiveCheckoutCpFailureTerminal') === false
        && strpos($cartSrc, 'isDefinitiveCheckoutCpFailureTerminal') === false,
    'Product/Cart do NOT use Checkout CP Thank You detector'
);
mtuc115c3cp_assert(
    strpos($productSrc, 'enrichCpCreateFailureModal') !== false
        && strpos($cartSrc, 'enrichCpCreateFailureModal') !== false,
    'Product/Cart keep error_modal CP failure path'
);

$expectedMessage = MtUniCreditFinancingLeasingPresenter::CP_TERMINAL_FAILURE_TITLE
    . "\n\n"
    . MtUniCreditFinancingLeasingPresenter::CP_TERMINAL_FAILURE_MESSAGE;

/**
 * @param string $label
 * @param int $customerId
 * @param bool $process2
 * @return void
 */
function mtuc115c3cp_runCase($label, $customerId, $process2)
{
    $transport = new Phase4FakeCpHttpTransport();
    $payloads = Phase7TestHarness::loginAndOrderSuccessPayloads();
    $transport->enqueueJson(200, $payloads['login']);
    $transport->enqueueJson(422, array('success' => false, 'message' => 'invalid'));

    $shopOverrides = $process2 ? array('uni_proces' => 1) : array();
    $stack = Phase9TestHarness::stack($transport, null, null, Phase5TestHarness::STORE_A, $shopOverrides);

    $orderId = 206000 + ($process2 ? 200 : 0) + (int) $customerId;
    Phase9TestHarness::seedBankOrder($stack['memoryDb'], $orderId, $stack['storeId']);

    $ordersProp = (new ReflectionClass($stack['memoryDb']))->getProperty('orders');
    $ordersProp->setAccessible(true);
    $ordersBefore = $ordersProp->getValue($stack['memoryDb']);
    mtuc115c3cp_assert(isset($ordersBefore[$orderId]), $label . ': OC order exists before submit');
    $countBefore = count($ordersBefore);

    $nativeDb = new Mtuc115c3CpOrderDb();
    $nativeDb->orders[$orderId] = array(
        'order_id' => $orderId,
        'order_status_id' => 0,
        'store_id' => (int) $stack['storeId'],
        'payment_code' => MtUniCreditConstants::EXTENSION_CODE,
    );
    $configured = 5;
    mtuc115c3cp_assert(
        (int) MtUniCreditNativeOrderStatusSupport::readOrderStatusId($nativeDb, $orderId) === 0,
        $label . ': initial order_status_id = 0'
    );

    $input = $process2
        ? Phase9TestHarness::submitInputProcess2($orderId, $stack['storeId'])
        : Phase9TestHarness::submitInput($orderId, $stack['storeId']);
    $input['customer_id'] = (int) $customerId;

    $submit = $stack['submission']->submit($input);

    mtuc115c3cp_assert(empty($submit['success']), $label . ': overall failure');
    mtuc115c3cp_assert((int) $submit['order_id'] === $orderId, $label . ': same order_id');
    mtuc115c3cp_assert(empty($submit['cp_succeeded']), $label . ': cp_succeeded=false');
    mtuc115c3cp_assert(
        (int) (isset($submit['control_panel_order_id']) ? $submit['control_panel_order_id'] : 0) === 0,
        $label . ': control_panel_order_id=0'
    );
    mtuc115c3cp_assert(empty($submit['ambiguous_blocked']), $label . ': not ambiguous');
    mtuc115c3cp_assert(
        (string) $submit['bank_status'] === MtUniCreditBankStatus::SEND_FAILED_CP,
        $label . ': bank_send_failed_cp'
    );
    mtuc115c3cp_assert(
        (string) $submit['bank_status'] !== MtUniCreditBankStatus::SENT_PROCESS1
            && (string) $submit['bank_status'] !== MtUniCreditBankStatus::SENT_PROCESS2
            && (string) $submit['bank_status'] !== MtUniCreditBankStatus::SEND_FAILED_SMARTUCF,
        $label . ': not success/SmartUCF bank statuses'
    );
    mtuc115c3cp_assert(!empty($submit['apply_native_order_status']), $label . ': apply_native authorised');
    mtuc115c3cp_assert(
        MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveCheckoutCpFailureTerminal($submit),
        $label . ': Checkout CP terminal detector'
    );
    mtuc115c3cp_assert(
        !MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveRemoteRejectTerminal($submit),
        $label . ': NOT SmartUCF terminal'
    );
    mtuc115c3cp_assert(
        Phase7TestHarness::countOrderPosts($transport) === 1,
        $label . ': CP POST attempted once (create fails)'
    );
    // Delta = failed create response, no successful CP order id.
    mtuc115c3cp_assert(
        Phase9TestHarness::smartUcfCallCount($stack['smartUcfProbe']) === 0,
        $label . ': SmartUCF = 0'
    );
    $ordersAfter = $ordersProp->getValue($stack['memoryDb']);
    mtuc115c3cp_assert(
        isset($ordersAfter[$orderId]) && count($ordersAfter) === $countBefore,
        $label . ': no second OC order'
    );

    mtuc115c3cp_assert(
        !MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff($submit),
        $label . ': NOT successful handoff'
    );
    $cartProbe = new class {
        public $cleared = false;

        public function clear()
        {
            $this->cleared = true;
        }
    };
    $cleared = MtUniCreditFinancingTerminalNavigationSupport::clearCartAfterSuccessfulHandoff($submit, $cartProbe);
    mtuc115c3cp_assert($cleared === false && $cartProbe->cleared === false, $label . ': cart NOT cleared');

    if (!empty($submit['apply_native_order_status'])) {
        $current = MtUniCreditNativeOrderStatusSupport::readOrderStatusId($nativeDb, $orderId);
        if (MtUniCreditNativeOrderStatusSupport::shouldApplyHistory($current, $configured)) {
            $nativeDb->orders[$orderId]['order_status_id'] = $configured;
            $nativeDb->history[] = array('order_id' => $orderId, 'order_status_id' => $configured);
        }
    }
    mtuc115c3cp_assert(
        (int) $nativeDb->orders[$orderId]['order_status_id'] === $configured,
        $label . ': native status > 0 (configured payment status)'
    );

    $session = array('order_id' => $orderId);
    if ($customerId > 0) {
        $session['customer_id'] = $customerId;
    }
    $json = mtuc115c3cp_confirmJson($submit, $orderId, $session);
    mtuc115c3cp_assert(
        !empty($json['redirect']) && strpos((string) $json['redirect'], 'checkout/success') !== false,
        $label . ': redirect checkout/success'
    );
    mtuc115c3cp_assert(
        (int) $session['order_id'] === $orderId
            && (int) $session[MtUniCreditFinancingTerminalNavigationSupport::SESSION_SUCCESS_ORDER_ID] === $orderId,
        $label . ': Thank You session identity'
    );
    global $expectedMessage;
    mtuc115c3cp_assert(
        (string) $json['message'] === $expectedMessage,
        $label . ': exact CP Thank You message'
    );
    mtuc115c3cp_assert(
        (string) $json['step'] === MtUniCreditFinancingTerminalNavigationSupport::STEP_CP_TERMINAL_FAILED,
        $label . ': step cp_terminal_failed'
    );

    $adapter = new MtUniCreditDbAdapter($stack['memoryDb'], 'oc_');
    $svc = new MtUniCreditFinancingPresentationService(
        new MtUniCreditFinancingPresentationRepository($adapter)
    );
    $tyHtml = $svc->renderCustomerThankYouHtml(
        $svc->customerThankYouRows($stack['storeId'], $orderId)
    );
    mtuc115c3cp_assert(
        strpos($tyHtml, MtUniCreditFinancingLeasingPresenter::CP_TERMINAL_FAILURE_TITLE) !== false,
        $label . ': Thank You title present'
    );
    mtuc115c3cp_assert(
        strpos($tyHtml, 'потвърждението за регистрацията') !== false
            && strpos($tyHtml, 'Не изпращайте поръчката повторно.') !== false
            && strpos($tyHtml, 'Търговецът ще провери статуса на заявката.') !== false,
        $label . ': Thank You body paragraphs present'
    );
    mtuc115c3cp_assert(
        strpos($tyHtml, MtUniCreditBankStatus::LABEL_SEND_FAILED_CP) !== false,
        $label . ': Thank You shows CP bank label'
    );
    mtuc115c3cp_assert(
        strpos($tyHtml, MtUniCreditFinancingLeasingPresenter::SMARTUCF_TERMINAL_FAILURE_MESSAGE) === false,
        $label . ': not SmartUCF failure message'
    );
}

// ---------------------------------------------------------------------------
// Four mandatory cases
// ---------------------------------------------------------------------------
mtuc115c3cp_runCase('P1 logged', 91, false);
mtuc115c3cp_runCase('P1 guest', 0, false);
mtuc115c3cp_runCase('P2 logged', 92, true);
mtuc115c3cp_runCase('P2 guest', 0, true);

// ---------------------------------------------------------------------------
// Ambiguous CP must NOT Thank You as definitive CP failure
// ---------------------------------------------------------------------------
$transportAmb = new Phase4FakeCpHttpTransport();
$payloadsAmb = Phase7TestHarness::loginAndOrderSuccessPayloads();
$transportAmb->enqueueJson(200, $payloadsAmb['login']);
$transportAmb->enqueueTimeout();
$stackAmb = Phase9TestHarness::stack($transportAmb);
$ambOrder = 206999;
Phase9TestHarness::seedBankOrder($stackAmb['memoryDb'], $ambOrder, $stackAmb['storeId']);
$ambSubmit = $stackAmb['submission']->submit(Phase9TestHarness::submitInput($ambOrder, $stackAmb['storeId']));
mtuc115c3cp_assert(empty($ambSubmit['success']), 'ambiguous: failure');
mtuc115c3cp_assert(!empty($ambSubmit['ambiguous_blocked']), 'ambiguous: ambiguous_blocked');
mtuc115c3cp_assert(
    !MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveCheckoutCpFailureTerminal($ambSubmit),
    'ambiguous: NOT definitive Checkout CP Thank You'
);
$sessionAmb = array('order_id' => $ambOrder);
$jsonAmb = mtuc115c3cp_confirmJson($ambSubmit, $ambOrder, $sessionAmb);
mtuc115c3cp_assert(empty($jsonAmb['redirect']), 'ambiguous: stay Checkout (no Thank You redirect)');

// ---------------------------------------------------------------------------
// Product / Cart isolation — stay error modal
// ---------------------------------------------------------------------------
foreach (array('product', 'cart') as $entry) {
    $transport = new Phase4FakeCpHttpTransport();
    $payloads = Phase7TestHarness::loginAndOrderSuccessPayloads();
    $transport->enqueueJson(200, $payloads['login']);
    $transport->enqueueJson(422, array('success' => false, 'message' => 'invalid'));
    $stack = Phase9TestHarness::stack($transport);
    $oid = $entry === 'product' ? 207101 : 207102;
    $input = $entry === 'product'
        ? Phase9TestHarness::productStorefrontInput($stack, $oid)
        : Phase9TestHarness::cartStorefrontInput($stack, $oid);
    $result = $stack['storefront']->submit($input);
    mtuc115c3cp_assert(empty($result['cp_succeeded']), $entry . ' isolation: no CP success');
    mtuc115c3cp_assert(
        MtUniCreditFinancingTerminalNavigationSupport::isCpCreateFailureStayOnPage($result),
        $entry . ' isolation: stay-on-page candidate'
    );
    $sess = array();
    $json = MtUniCreditFinancingTerminalNavigationSupport::enrichCpCreateFailureModal(
        array(
            'success' => false,
            'order_id' => (int) $result['order_id'],
            'message' => isset($result['message']) ? (string) $result['message'] : '',
        ),
        $sess
    );
    mtuc115c3cp_assert(
        (string) $json['terminal_ui'] === MtUniCreditFinancingTerminalNavigationSupport::UI_ERROR_MODAL,
        $entry . ' isolation: error_modal'
    );
    mtuc115c3cp_assert(empty($json['redirect']), $entry . ' isolation: no Thank You redirect');
    mtuc115c3cp_assert(
        empty($sess[MtUniCreditFinancingTerminalNavigationSupport::SESSION_SUCCESS_ORDER_ID]),
        $entry . ' isolation: no success_order_id session'
    );
}

// ---------------------------------------------------------------------------
// SmartUCF failure canary (Checkout)
// ---------------------------------------------------------------------------
$transportSmart = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportSmart);
$stackSmart = Phase9TestHarness::stack(
    $transportSmart,
    function (array $options): array {
        return array(
            'body' => Phase9TestHarness::rejectBody(),
            'error' => '',
            'http_code' => 400,
        );
    }
);
$smartOrder = 207201;
Phase9TestHarness::seedBankOrder($stackSmart['memoryDb'], $smartOrder, $stackSmart['storeId']);
$smartSubmit = $stackSmart['submission']->submit(Phase9TestHarness::submitInput($smartOrder, $stackSmart['storeId']));
mtuc115c3cp_assert(
    MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveRemoteRejectTerminal($smartSubmit),
    'Smart canary: remote_reject terminal'
);
mtuc115c3cp_assert(
    (string) $smartSubmit['bank_status'] === MtUniCreditBankStatus::SEND_FAILED_SMARTUCF,
    'Smart canary: bank_send_failed_smartucf'
);
mtuc115c3cp_assert(
    !MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveCheckoutCpFailureTerminal($smartSubmit),
    'Smart canary: NOT Checkout CP failure terminal'
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
$p1Order = 207301;
Phase9TestHarness::seedBankOrder($stackP1['memoryDb'], $p1Order, $stackP1['storeId']);
$p1Ok = $stackP1['submission']->submit(Phase9TestHarness::submitInput($p1Order, $stackP1['storeId']));
mtuc115c3cp_assert(!empty($p1Ok['success']) && !empty($p1Ok['bank_redirect']), 'canary P1: success + bank redirect');
mtuc115c3cp_assert(
    (string) $p1Ok['bank_status'] === MtUniCreditBankStatus::SENT_PROCESS1,
    'canary P1: bank_sent_process1'
);
mtuc115c3cp_assert(
    MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff($p1Ok),
    'canary P1: cart-clear eligible handoff'
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
$p2Order = 207302;
Phase9TestHarness::seedBankOrder($stackP2['memoryDb'], $p2Order, $stackP2['storeId']);
$p2Ok = $stackP2['submission']->submit(Phase9TestHarness::submitInputProcess2($p2Order, $stackP2['storeId']));
mtuc115c3cp_assert(!empty($p2Ok['success']), 'canary P2: success');
mtuc115c3cp_assert(
    (string) $p2Ok['bank_status'] === MtUniCreditBankStatus::SENT_PROCESS2,
    'canary P2: bank_sent_process2'
);
mtuc115c3cp_assert(
    MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff($p2Ok),
    'canary P2: cart-clear eligible handoff'
);

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
