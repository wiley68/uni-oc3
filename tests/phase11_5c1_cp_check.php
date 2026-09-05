<?php

/**
 * Phase 11.5C.1 — Product Process 1 CP-create failure → error modal (not empty cart).
 * Run: php tests/phase11_5c1_cp_check.php
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
    $storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mtuc-phase115c1cp-storage';
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

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtuc115c1cp_assert($condition, $message)
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
function mtuc115c1cp_read($path)
{
    $body = @file_get_contents($path);

    return is_string($body) ? $body : '';
}

/**
 * @param array<string, mixed> $stack
 * @param int $orderId
 * @param int $customerId
 * @param int|null $orderCreateCounter
 * @return array<string, mixed>
 */
function mtuc115c1cp_productInput(array $stack, $orderId, $customerId, &$orderCreateCounter = null)
{
    $input = Phase9TestHarness::productStorefrontInput($stack, $orderId);
    $input['customer']['customer_id'] = (int) $customerId;
    $storeId = (int) $stack['storeId'];
    $input['add_order'] = function ($orderData) use ($stack, $orderId, $storeId, &$orderCreateCounter) {
        if ($orderCreateCounter !== null) {
            $orderCreateCounter++;
        }
        $stack['memoryDb']->seedOrder($orderId, $storeId, MtUniCreditConstants::EXTENSION_CODE);

        return $orderId;
    };

    return $input;
}

/**
 * Simulate Product controller failure JSON enrichment for CP-create failure.
 *
 * @param array<string, mixed> $result
 * @return array<string, mixed>
 */
function mtuc115c1cp_controllerJson(array $result)
{
    $json = array(
        'success' => false,
        'error' => isset($result['error']) ? (string) $result['error'] : 'request_failed',
        'message' => isset($result['message']) ? (string) $result['message'] : '',
        'order_id' => (int) (isset($result['order_id']) ? $result['order_id'] : 0),
    );
    if (!empty($result['cp_succeeded'])) {
        $json['cp_succeeded'] = true;
    }
    if (array_key_exists('recoverable', $result)) {
        $json['recoverable'] = !empty($result['recoverable']);
    }
    if (!empty($result['ambiguous_blocked'])) {
        $json['ambiguous_blocked'] = true;
    }
    if (!empty($result['apply_native_order_status'])) {
        $json['apply_native_order_status'] = true;
    }

    if (MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveRemoteRejectTerminal($result)) {
        $session = array();

        return MtUniCreditFinancingTerminalNavigationSupport::enrichDefinitiveRemoteRejectThankYou(
            $json,
            $session,
            (int) $result['order_id'],
            'https://shop.test/index.php?route=checkout/success'
        );
    }
    if (MtUniCreditFinancingTerminalNavigationSupport::isCpCreateFailureStayOnPage($result)) {
        $session = array();

        return MtUniCreditFinancingTerminalNavigationSupport::enrichCpCreateFailureModal($json, $session);
    }
    if (!empty($result['order_id'])) {
        $json['redirect'] = 'https://shop.test/index.php?route=extension/payment/mt_uni_credit/prepared';
    }

    return $json;
}

$thankYouUrl = 'https://shop.test/index.php?route=checkout/success';
$preparedNeedle = 'mt_uni_credit/prepared';
$cartNeedle = 'checkout/cart';

// ---------------------------------------------------------------------------
// Detector
// ---------------------------------------------------------------------------
$cpFailResult = array(
    'success' => false,
    'error' => MtUniCreditControlPanelErrorClass::REJECTED,
    'order_id' => 116101,
    'cp_succeeded' => false,
    'recoverable' => true,
    'ambiguous_blocked' => false,
    'message' => MtUniCreditControlPanelOrderLifecycleService::CUSTOMER_FAILURE_MESSAGE,
);
mtuc115c1cp_assert(
    MtUniCreditFinancingTerminalNavigationSupport::isCpCreateFailureStayOnPage($cpFailResult),
    'detector: CP reject with local order is stay-on-page'
);

$smartReject = $cpFailResult;
$smartReject['cp_succeeded'] = true;
$smartReject['error'] = MtUniCreditSmartUcfFailureClassification::CLASS_REMOTE_REJECT;
$smartReject['recoverable'] = false;
mtuc115c1cp_assert(
    !MtUniCreditFinancingTerminalNavigationSupport::isCpCreateFailureStayOnPage($smartReject),
    'detector: SmartUCF terminal is NOT CP stay-on-page'
);
mtuc115c1cp_assert(
    MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveRemoteRejectTerminal($smartReject),
    'detector: SmartUCF terminal still Thank You'
);

$noOrder = $cpFailResult;
$noOrder['order_id'] = 0;
mtuc115c1cp_assert(
    !MtUniCreditFinancingTerminalNavigationSupport::isCpCreateFailureStayOnPage($noOrder),
    'detector: no local order is not CP stay-on-page modal path'
);

$sessionCp = array(
    MtUniCreditCheckoutConfirmPreparation::SESSION_PREPARED_ORDER_ID => 99,
    MtUniCreditFinancingTerminalNavigationSupport::SESSION_SUCCESS_ORDER_ID => 99,
);
$enrichedCp = MtUniCreditFinancingTerminalNavigationSupport::enrichCpCreateFailureModal(
    array(
        'success' => false,
        'error' => MtUniCreditControlPanelErrorClass::REJECTED,
        'message' => MtUniCreditControlPanelOrderLifecycleService::CUSTOMER_FAILURE_MESSAGE,
        'order_id' => 116101,
        'redirect' => 'https://shop.test/index.php?route=checkout/cart',
    ),
    $sessionCp
);
mtuc115c1cp_assert(
    (string) $enrichedCp['terminal_ui'] === MtUniCreditFinancingTerminalNavigationSupport::UI_ERROR_MODAL,
    'enrich: terminal_ui=error_modal'
);
mtuc115c1cp_assert(!empty($enrichedCp['stay_on_page']), 'enrich: stay_on_page');
mtuc115c1cp_assert(empty($enrichedCp['redirect']), 'enrich: redirect removed');
mtuc115c1cp_assert(
    !isset($sessionCp[MtUniCreditCheckoutConfirmPreparation::SESSION_PREPARED_ORDER_ID]),
    'enrich: prepared session cleared'
);
mtuc115c1cp_assert(
    !isset($sessionCp[MtUniCreditFinancingTerminalNavigationSupport::SESSION_SUCCESS_ORDER_ID]),
    'enrich: success_order_id not set'
);
mtuc115c1cp_assert(
    (string) $enrichedCp['message'] === MtUniCreditControlPanelOrderLifecycleService::CUSTOMER_FAILURE_MESSAGE,
    'enrich: definitive CP customer message (OC4 parity)'
);

// ---------------------------------------------------------------------------
// Product controller / JS / modal wiring
// ---------------------------------------------------------------------------
$productCtrl = mtuc115c1cp_read(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'product.php'
);
$js = mtuc115c1cp_read(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'view' . DIRECTORY_SEPARATOR . 'theme' . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR
        . 'template' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'storefront.js'
);
$modalTwig = mtuc115c1cp_read(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'view' . DIRECTORY_SEPARATOR . 'theme' . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR
        . 'template' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'modal.twig'
);

mtuc115c1cp_assert(
    strpos($productCtrl, 'isCpCreateFailureStayOnPage') !== false
        && strpos($productCtrl, 'enrichCpCreateFailureModal') !== false,
    'product: CP failure modal wiring'
);
mtuc115c1cp_assert(
    strpos($productCtrl, 'isDefinitiveRemoteRejectTerminal') !== false,
    'product: SmartUCF Thank You path still present before CP modal'
);
mtuc115c1cp_assert(
    strpos($js, 'terminal_ui') !== false && strpos($js, 'error_modal') !== false,
    'storefront.js: handles terminal_ui error_modal'
);
mtuc115c1cp_assert(
    strpos($js, 'showErrorModal') !== false && strpos($js, 'closeErrorModal') !== false,
    'storefront.js: error modal open/close'
);
mtuc115c1cp_assert(
    strpos($modalTwig, 'data-mtuc-error-modal') !== false
        && strpos($modalTwig, 'data-mtuc-error-dismiss') !== false,
    'modal.twig: separate error dialog markup'
);

// ---------------------------------------------------------------------------
// Lifecycle: logged + guest definitive CP failure
// ---------------------------------------------------------------------------
foreach (array('logged' => 42, 'guest' => 0) as $actor => $customerId) {
    $transport = new Phase4FakeCpHttpTransport();
    $payloads = Phase7TestHarness::loginAndOrderSuccessPayloads();
    $transport->enqueueJson(200, $payloads['login']);
    $transport->enqueueJson(422, array('success' => false, 'message' => 'invalid'));
    $stack = Phase9TestHarness::stack($transport);
    $orderId = 116200 + (int) $customerId;
    $orderCreates = 0;
    $input = mtuc115c1cp_productInput($stack, $orderId, $customerId, $orderCreates);
    $result = $stack['storefront']->submit($input);

    mtuc115c1cp_assert(empty($result['success']), $actor . ': CP fail overall failure');
    mtuc115c1cp_assert(empty($result['cp_succeeded']), $actor . ': CP not succeeded');
    mtuc115c1cp_assert((int) $result['order_id'] === $orderId, $actor . ': local OC order exists');
    mtuc115c1cp_assert($orderCreates === 1, $actor . ': exactly one local OC materialization');
    mtuc115c1cp_assert(
        Phase7TestHarness::countOrderPosts($transport) === 1,
        $actor . ': one CP POST attempted'
    );
    mtuc115c1cp_assert(
        count($stack['smartUcfProbe']->calls) === 0,
        $actor . ': zero SmartUCF calls'
    );
    $attempt = $stack['attempts']->findByStoreOrder($stack['storeId'], $orderId);
    mtuc115c1cp_assert(
        $attempt !== null
            && $attempt['state'] === MtUniCreditFinancingAttemptState::CP_FAILED_RETRYABLE,
        $actor . ': attempt state cp_failed_retryable'
    );
    mtuc115c1cp_assert(
        empty($result['apply_native_order_status']),
        $actor . ': no native success status authorization'
    );
    mtuc115c1cp_assert(
        (string) $result['message'] === MtUniCreditControlPanelOrderLifecycleService::CUSTOMER_FAILURE_MESSAGE,
        $actor . ': customer-safe definitive message'
    );
    mtuc115c1cp_assert(
        strpos((string) $result['message'], '422') === false
            && strpos((string) $result['message'], 'HTTP') === false
            && stripos((string) $result['message'], 'endpoint') === false,
        $actor . ': message has no CP/HTTP internals'
    );

    $json = mtuc115c1cp_controllerJson($result);
    mtuc115c1cp_assert(
        (string) $json['terminal_ui'] === MtUniCreditFinancingTerminalNavigationSupport::UI_ERROR_MODAL,
        $actor . ': controller JSON terminal_ui=error_modal'
    );
    mtuc115c1cp_assert(empty($json['redirect']), $actor . ': no redirect');
    mtuc115c1cp_assert(
        empty($json['redirect'])
            || (
                strpos((string) $json['redirect'], $cartNeedle) === false
                && strpos((string) $json['redirect'], $preparedNeedle) === false
                && strpos((string) $json['redirect'], 'checkout/success') === false
            ),
        $actor . ': no cart/prepared/Thank You redirect'
    );
    mtuc115c1cp_assert(empty($json['success']), $actor . ': success remains false');
}

// ---------------------------------------------------------------------------
// Ambiguous CP outcome: block unsafe retry + safe customer message
// ---------------------------------------------------------------------------
$transportAmb = new Phase4FakeCpHttpTransport();
$payloadsAmb = Phase7TestHarness::loginAndOrderSuccessPayloads();
$transportAmb->enqueueJson(200, $payloadsAmb['login']);
$transportAmb->enqueueTimeout();
$stackAmb = Phase9TestHarness::stack($transportAmb);
$orderAmb = 116310;
$createsAmb = 0;
$inputAmb = mtuc115c1cp_productInput($stackAmb, $orderAmb, 7, $createsAmb);
$resultAmb = $stackAmb['storefront']->submit($inputAmb);
mtuc115c1cp_assert(empty($resultAmb['success']), 'ambiguous: first submit fails');
mtuc115c1cp_assert(!empty($resultAmb['ambiguous_blocked']), 'ambiguous: ambiguous_blocked');
mtuc115c1cp_assert(
    (string) $resultAmb['message'] === MtUniCreditControlPanelOrderLifecycleService::CUSTOMER_AMBIGUOUS_MESSAGE,
    'ambiguous: customer ambiguous message'
);
$postsAfterFirst = Phase7TestHarness::countOrderPosts($transportAmb);
if (isset($resultAmb['session']) && is_array($resultAmb['session'])) {
    $inputAmb['session'] = $resultAmb['session'];
}
$retryAmb = $stackAmb['storefront']->submit($inputAmb);
mtuc115c1cp_assert(empty($retryAmb['success']), 'ambiguous: retry still fails');
mtuc115c1cp_assert(!empty($retryAmb['ambiguous_blocked']), 'ambiguous: retry blocked');
mtuc115c1cp_assert(
    Phase7TestHarness::countOrderPosts($transportAmb) === $postsAfterFirst,
    'ambiguous: no unsafe second CP POST'
);
$jsonAmb = mtuc115c1cp_controllerJson($resultAmb);
mtuc115c1cp_assert(
    (string) $jsonAmb['terminal_ui'] === MtUniCreditFinancingTerminalNavigationSupport::UI_ERROR_MODAL,
    'ambiguous: still error_modal UX (not cart)'
);
mtuc115c1cp_assert(empty($jsonAmb['redirect']), 'ambiguous: no redirect');

// ---------------------------------------------------------------------------
// Safe retry after definitive CP failure
// ---------------------------------------------------------------------------
$transportRetry = new Phase4FakeCpHttpTransport();
$payloadsRetry = Phase7TestHarness::loginAndOrderSuccessPayloads();
$transportRetry->enqueueJson(200, $payloadsRetry['login']);
$transportRetry->enqueueJson(422, array('success' => false, 'message' => 'invalid'));
$stackRetry = Phase9TestHarness::stack($transportRetry);
$orderRetry = 116320;
$createsRetry = 0;
$inputRetry = mtuc115c1cp_productInput($stackRetry, $orderRetry, 9, $createsRetry);
$firstRetry = $stackRetry['storefront']->submit($inputRetry);
mtuc115c1cp_assert(empty($firstRetry['success']), 'retry: first CP fail');
mtuc115c1cp_assert($createsRetry === 1, 'retry: one OC order after first fail');

// Preserve application session bind so retry reuses the same local OC order.
if (isset($firstRetry['session']) && is_array($firstRetry['session'])) {
    $inputRetry['session'] = $firstRetry['session'];
}

Phase9TestHarness::enqueueCpOrderCreateSuccess($transportRetry);
$secondRetry = $stackRetry['storefront']->submit($inputRetry);
mtuc115c1cp_assert(!empty($secondRetry['success']), 'retry: second submit succeeds');
mtuc115c1cp_assert($createsRetry === 1, 'retry: no duplicate local OC order');
mtuc115c1cp_assert(
    Phase7TestHarness::countOrderPosts($transportRetry) === 2,
    'retry: exactly two CP POSTs (fail then success)'
);
mtuc115c1cp_assert(
    (int) $secondRetry['control_panel_order_id'] > 0,
    'retry: one eventual CP order id'
);
mtuc115c1cp_assert(
    count($stackRetry['smartUcfProbe']->calls) === 1,
    'retry: one SmartUCF call after CP success'
);
mtuc115c1cp_assert(!empty($secondRetry['bank_redirect']), 'retry: trusted bank redirect');

// ---------------------------------------------------------------------------
// SmartUCF failure + successful P1 + P2 regressions
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
$resultReject = $stackReject['storefront']->submit(
    Phase9TestHarness::productStorefrontInput($stackReject, 116401)
);
mtuc115c1cp_assert(
    MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveRemoteRejectTerminal($resultReject),
    'regression: SmartUCF reject still terminal Thank You candidate'
);
$jsonReject = mtuc115c1cp_controllerJson($resultReject);
mtuc115c1cp_assert(
    !empty($jsonReject['redirect']) && strpos((string) $jsonReject['redirect'], 'checkout/success') !== false,
    'regression: SmartUCF reject → checkout/success'
);
mtuc115c1cp_assert(
    empty($jsonReject['terminal_ui'])
        || (string) $jsonReject['terminal_ui'] !== MtUniCreditFinancingTerminalNavigationSupport::UI_ERROR_MODAL,
    'regression: SmartUCF reject is NOT error_modal'
);

$transportOk = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportOk);
$stackOk = Phase9TestHarness::stack($transportOk);
$resultOk = $stackOk['storefront']->submit(
    Phase9TestHarness::productStorefrontInput($stackOk, 116402)
);
mtuc115c1cp_assert(!empty($resultOk['success']) && !empty($resultOk['bank_redirect']), 'regression: P1 success bank redirect');
mtuc115c1cp_assert(
    !MtUniCreditFinancingTerminalNavigationSupport::isCpCreateFailureStayOnPage($resultOk),
    'regression: P1 success not CP failure modal'
);

$p2Session = array();
$p2Payload = MtUniCreditFinancingTerminalNavigationSupport::enrichProcess2ThankYou(
    array('success' => true, 'redirect' => '', 'bank_redirect' => false),
    $p2Session,
    116403,
    $thankYouUrl
);
mtuc115c1cp_assert(
    !empty($p2Payload['success']) && (string) $p2Payload['redirect'] === $thankYouUrl,
    'regression: P2 Thank You intact'
);

echo PHP_EOL . 'Phase 11.5C.1 CP checks: ' . $passes . ' passed';
if ($failures) {
    echo ', ' . count($failures) . ' failed' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    echo 'PHASE 11.5C.1 PRODUCT P1 CP FAILURE POPUP: BLOCKED' . PHP_EOL;
    exit(1);
}

echo ', 0 failed' . PHP_EOL;
echo 'PHASE 11.5C.1 PRODUCT P1 CP FAILURE POPUP: PASS — LOCAL' . PHP_EOL;
exit(0);
