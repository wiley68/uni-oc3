<?php

/**
 * Phase 11.5C.2 — Cart failure navigation parity with Product.
 * Run: php tests/phase11_5c2_check.php
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
    $storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mtuc-phase115c2-storage';
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
function mtuc115c2_assert($condition, $message)
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
function mtuc115c2_read($path)
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
function mtuc115c2_cartInput(array $stack, $orderId, $customerId, &$orderCreateCounter = null)
{
    $input = Phase9TestHarness::cartStorefrontInput($stack, $orderId);
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
 * Simulate Cart controller failure JSON enrichment (Product parity).
 *
 * @param array<string, mixed> $result
 * @return array<string, mixed>
 */
function mtuc115c2_controllerJson(array $result)
{
    $json = array(
        'success' => false,
        'error' => isset($result['error']) ? (string) $result['error'] : 'request_failed',
        'message' => isset($result['message']) ? (string) $result['message'] : '',
        'cart_unchanged' => true,
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

    $session = array();
    if (MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveRemoteRejectTerminal($result)) {
        return MtUniCreditFinancingTerminalNavigationSupport::enrichDefinitiveRemoteRejectThankYou(
            $json,
            $session,
            (int) $result['order_id'],
            'https://shop.test/index.php?route=checkout/success'
        );
    }
    if (MtUniCreditFinancingTerminalNavigationSupport::isCpCreateFailureStayOnPage($result)) {
        return MtUniCreditFinancingTerminalNavigationSupport::enrichCpCreateFailureModal($json, $session);
    }
    if (!empty($result['order_id'])) {
        $json['redirect'] = 'https://shop.test/index.php?route=extension/payment/mt_uni_credit/prepared';
    }

    return $json;
}

/**
 * @param array<string, mixed> $json
 * @param bool $allowThankYou
 * @return bool
 */
function mtuc115c2_noBadCheckoutRedirect(array $json, $allowThankYou = false)
{
    if (empty($json['redirect'])) {
        return true;
    }
    $redirect = (string) $json['redirect'];
    if ($allowThankYou && strpos($redirect, 'checkout/success') !== false) {
        return strpos($redirect, 'mt_uni_credit/prepared') === false
            && strpos($redirect, 'checkout/cart') === false
            && !preg_match('#route=checkout/checkout(?:&|$)#', $redirect)
            && !preg_match('#route=checkout(?:&|$)#', $redirect);
    }

    return strpos($redirect, 'checkout/success') === false
        && strpos($redirect, 'mt_uni_credit/prepared') === false
        && strpos($redirect, 'checkout/cart') === false
        && strpos($redirect, 'checkout/checkout') === false
        && !preg_match('#route=checkout(?:&|$)#', $redirect);
}

$thankYouUrl = 'https://shop.test/index.php?route=checkout/success';

// ---------------------------------------------------------------------------
// Cart controller wiring (source) — Product parity methods, no SmartUCF string
// ---------------------------------------------------------------------------
$cartCtrl = mtuc115c2_read(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'cart.php'
);
mtuc115c2_assert(
    stripos($cartCtrl, 'SmartUCF') === false,
    'cart controller: no SmartUCF string (Phase 8)'
);
mtuc115c2_assert(
    strpos($cartCtrl, 'isDefinitiveRemoteRejectTerminal') !== false
        && strpos($cartCtrl, 'enrichDefinitiveRemoteRejectThankYou') !== false,
    'cart: SmartUCF terminal Thank You wiring'
);
mtuc115c2_assert(
    strpos($cartCtrl, 'isCpCreateFailureStayOnPage') !== false
        && strpos($cartCtrl, 'enrichCpCreateFailureModal') !== false,
    'cart: CP failure error-modal wiring'
);
mtuc115c2_assert(
    strpos($cartCtrl, 'CHECKOUT_SUCCESS_ROUTE') !== false,
    'cart: Thank You route available'
);

mtuc115c2_assert(
    strpos($cartCtrl, 'isDefinitiveRemoteRejectTerminal') !== false
        && strpos($cartCtrl, 'isCpCreateFailureStayOnPage') !== false
        && strpos($cartCtrl, 'CHECKOUT_PREPARED_ROUTE') !== false
        && strpos($cartCtrl, 'isDefinitiveRemoteRejectTerminal')
        < strpos($cartCtrl, 'isCpCreateFailureStayOnPage')
        && strpos($cartCtrl, 'isCpCreateFailureStayOnPage')
        < strrpos($cartCtrl, 'CHECKOUT_PREPARED_ROUTE'),
    'cart: failure branch checks Thank You then error modal before prepared'
);

$js = mtuc115c2_read(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'view' . DIRECTORY_SEPARATOR . 'theme' . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR
        . 'template' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'storefront.js'
);
mtuc115c2_assert(
    strpos($js, 'terminal_ui') !== false && strpos($js, 'error_modal') !== false,
    'shared storefront.js: Cart reuses error_modal handling'
);

// ---------------------------------------------------------------------------
// Cart P1 logged + guest — broken SmartUCF → Thank You
// ---------------------------------------------------------------------------
foreach (array('logged' => 51, 'guest' => 0) as $actor => $customerId) {
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
    $orderId = 117100 + (int) $customerId;
    $creates = 0;
    $result = $stack['storefront']->submit(mtuc115c2_cartInput($stack, $orderId, $customerId, $creates));

    mtuc115c2_assert(empty($result['success']), 'P1 ' . $actor . ' Smart: overall failure');
    mtuc115c2_assert(!empty($result['cp_succeeded']), 'P1 ' . $actor . ' Smart: CP succeeded');
    mtuc115c2_assert((int) $result['order_id'] === $orderId, 'P1 ' . $actor . ' Smart: 1 local OC order id');
    mtuc115c2_assert($creates === 1, 'P1 ' . $actor . ' Smart: one OC materialization');
    mtuc115c2_assert(
        Phase7TestHarness::countOrderPosts($transport) === 1,
        'P1 ' . $actor . ' Smart: one CP order create'
    );
    mtuc115c2_assert(
        count($stack['smartUcfProbe']->calls) === 1,
        'P1 ' . $actor . ' Smart: one SmartUCF attempt (not success)'
    );
    mtuc115c2_assert(
        Phase9TestHarness::bankStatusId($stack, $orderId) === MtUniCreditBankStatus::SEND_FAILED_SMARTUCF,
        'P1 ' . $actor . ' Smart: bank_send_failed_smartucf'
    );
    mtuc115c2_assert(
        MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveRemoteRejectTerminal($result),
        'P1 ' . $actor . ' Smart: terminal Thank You candidate'
    );

    $json = mtuc115c2_controllerJson($result);
    mtuc115c2_assert(
        !empty($json['redirect']) && strpos((string) $json['redirect'], 'checkout/success') !== false,
        'P1 ' . $actor . ' Smart: redirect checkout/success'
    );
    mtuc115c2_assert(
        mtuc115c2_noBadCheckoutRedirect($json, true),
        'P1 ' . $actor . ' Smart: no prepared/cart/checkout redirect'
    );
    mtuc115c2_assert(
        empty($json['terminal_ui'])
            || (string) $json['terminal_ui'] !== MtUniCreditFinancingTerminalNavigationSupport::UI_ERROR_MODAL,
        'P1 ' . $actor . ' Smart: NOT error_modal'
    );

    $adapter = new MtUniCreditDbAdapter($stack['memoryDb'], 'oc_');
    $svc = new MtUniCreditFinancingPresentationService(
        new MtUniCreditFinancingPresentationRepository($adapter)
    );
    $tyHtml = $svc->renderCustomerThankYouHtml(
        $svc->customerThankYouRows($stack['storeId'], $orderId)
    );
    mtuc115c2_assert(
        strpos($tyHtml, MtUniCreditFinancingLeasingPresenter::SMARTUCF_TERMINAL_FAILURE_MESSAGE) !== false,
        'P1 ' . $actor . ' Smart: Thank You failure presentation'
    );
}

// ---------------------------------------------------------------------------
// Cart P1 logged + guest — broken CP → error modal
// ---------------------------------------------------------------------------
foreach (array('logged' => 61, 'guest' => 0) as $actor => $customerId) {
    $transport = new Phase4FakeCpHttpTransport();
    $payloads = Phase7TestHarness::loginAndOrderSuccessPayloads();
    $transport->enqueueJson(200, $payloads['login']);
    $transport->enqueueJson(422, array('success' => false, 'message' => 'invalid'));
    $stack = Phase9TestHarness::stack($transport);
    $orderId = 117200 + (int) $customerId;
    $creates = 0;
    $result = $stack['storefront']->submit(mtuc115c2_cartInput($stack, $orderId, $customerId, $creates));

    mtuc115c2_assert(empty($result['success']), 'P1 ' . $actor . ' CP: fail');
    mtuc115c2_assert(empty($result['cp_succeeded']), 'P1 ' . $actor . ' CP: no CP success');
    mtuc115c2_assert($creates === 1, 'P1 ' . $actor . ' CP: one OC order');
    mtuc115c2_assert(
        Phase7TestHarness::countOrderPosts($transport) === 1,
        'P1 ' . $actor . ' CP: one failed CP POST'
    );
    mtuc115c2_assert(
        count($stack['smartUcfProbe']->calls) === 0,
        'P1 ' . $actor . ' CP: zero SmartUCF'
    );
    $attempt = $stack['attempts']->findByStoreOrder($stack['storeId'], $orderId);
    mtuc115c2_assert(
        $attempt !== null
            && $attempt['state'] === MtUniCreditFinancingAttemptState::CP_FAILED_RETRYABLE,
        'P1 ' . $actor . ' CP: cp_failed_retryable'
    );
    mtuc115c2_assert(
        (string) $result['message'] === MtUniCreditControlPanelOrderLifecycleService::CUSTOMER_FAILURE_MESSAGE,
        'P1 ' . $actor . ' CP: definitive customer message'
    );

    $json = mtuc115c2_controllerJson($result);
    mtuc115c2_assert(
        (string) $json['terminal_ui'] === MtUniCreditFinancingTerminalNavigationSupport::UI_ERROR_MODAL,
        'P1 ' . $actor . ' CP: terminal_ui=error_modal'
    );
    mtuc115c2_assert(!empty($json['stay_on_page']), 'P1 ' . $actor . ' CP: stay_on_page');
    mtuc115c2_assert(empty($json['redirect']), 'P1 ' . $actor . ' CP: no redirect');
    mtuc115c2_assert(
        mtuc115c2_noBadCheckoutRedirect($json, false),
        'P1 ' . $actor . ' CP: no checkout/prepared navigation'
    );
}

// ---------------------------------------------------------------------------
// Cart P2 logged + guest — broken CP → error modal (no SmartUCF)
// ---------------------------------------------------------------------------
foreach (array('logged' => 71, 'guest' => 0) as $actor => $customerId) {
    $transport = new Phase4FakeCpHttpTransport();
    $payloads = Phase7TestHarness::loginAndOrderSuccessPayloads();
    $transport->enqueueJson(200, $payloads['login']);
    $transport->enqueueJson(422, array('success' => false, 'message' => 'invalid'));
    $stack = Phase9TestHarness::stack(
        $transport,
        null,
        null,
        Phase5TestHarness::STORE_A,
        array('uni_proces' => 1)
    );
    $orderId = 117300 + (int) $customerId;
    $creates = 0;
    $result = $stack['storefront']->submit(mtuc115c2_cartInput($stack, $orderId, $customerId, $creates));

    mtuc115c2_assert(empty($result['success']), 'P2 ' . $actor . ' CP: fail');
    mtuc115c2_assert(empty($result['cp_succeeded']), 'P2 ' . $actor . ' CP: no CP');
    mtuc115c2_assert($creates === 1, 'P2 ' . $actor . ' CP: one OC order');
    mtuc115c2_assert(
        count($stack['smartUcfProbe']->calls) === 0,
        'P2 ' . $actor . ' CP: zero SmartUCF'
    );
    $json = mtuc115c2_controllerJson($result);
    mtuc115c2_assert(
        (string) $json['terminal_ui'] === MtUniCreditFinancingTerminalNavigationSupport::UI_ERROR_MODAL,
        'P2 ' . $actor . ' CP: error_modal'
    );
    mtuc115c2_assert(empty($json['redirect']), 'P2 ' . $actor . ' CP: no redirect');
    mtuc115c2_assert(
        mtuc115c2_noBadCheckoutRedirect($json, false),
        'P2 ' . $actor . ' CP: no checkout navigation'
    );
}

// ---------------------------------------------------------------------------
// Ambiguous CP on Cart: no unsafe second POST
// ---------------------------------------------------------------------------
$transportAmb = new Phase4FakeCpHttpTransport();
$payloadsAmb = Phase7TestHarness::loginAndOrderSuccessPayloads();
$transportAmb->enqueueJson(200, $payloadsAmb['login']);
$transportAmb->enqueueTimeout();
$stackAmb = Phase9TestHarness::stack($transportAmb);
$orderAmb = 117410;
$createsAmb = 0;
$inputAmb = mtuc115c2_cartInput($stackAmb, $orderAmb, 8, $createsAmb);
$resultAmb = $stackAmb['storefront']->submit($inputAmb);
mtuc115c2_assert(!empty($resultAmb['ambiguous_blocked']), 'ambiguous: blocked');
mtuc115c2_assert(
    (string) $resultAmb['message'] === MtUniCreditControlPanelOrderLifecycleService::CUSTOMER_AMBIGUOUS_MESSAGE,
    'ambiguous: customer message'
);
$postsAmb = Phase7TestHarness::countOrderPosts($transportAmb);
if (isset($resultAmb['session']) && is_array($resultAmb['session'])) {
    $inputAmb['session'] = $resultAmb['session'];
}
$retryAmb = $stackAmb['storefront']->submit($inputAmb);
mtuc115c2_assert(!empty($retryAmb['ambiguous_blocked']), 'ambiguous: retry still blocked');
mtuc115c2_assert(
    Phase7TestHarness::countOrderPosts($transportAmb) === $postsAmb,
    'ambiguous: no second CP POST'
);
$jsonAmb = mtuc115c2_controllerJson($resultAmb);
mtuc115c2_assert(
    (string) $jsonAmb['terminal_ui'] === MtUniCreditFinancingTerminalNavigationSupport::UI_ERROR_MODAL,
    'ambiguous: error_modal UX'
);
mtuc115c2_assert(empty($jsonAmb['redirect']), 'ambiguous: no redirect');

// ---------------------------------------------------------------------------
// Safe CP retry after definitive failure (no duplicate OC)
// ---------------------------------------------------------------------------
$transportRetry = new Phase4FakeCpHttpTransport();
$payloadsRetry = Phase7TestHarness::loginAndOrderSuccessPayloads();
$transportRetry->enqueueJson(200, $payloadsRetry['login']);
$transportRetry->enqueueJson(422, array('success' => false, 'message' => 'invalid'));
$stackRetry = Phase9TestHarness::stack($transportRetry);
$orderRetry = 117420;
$createsRetry = 0;
$inputRetry = mtuc115c2_cartInput($stackRetry, $orderRetry, 9, $createsRetry);
$firstRetry = $stackRetry['storefront']->submit($inputRetry);
mtuc115c2_assert(empty($firstRetry['success']), 'retry: first fail');
if (isset($firstRetry['session']) && is_array($firstRetry['session'])) {
    $inputRetry['session'] = $firstRetry['session'];
}
Phase9TestHarness::enqueueCpOrderCreateSuccess($transportRetry);
$secondRetry = $stackRetry['storefront']->submit($inputRetry);
mtuc115c2_assert(!empty($secondRetry['success']), 'retry: second success');
mtuc115c2_assert($createsRetry === 1, 'retry: no duplicate OC order');
mtuc115c2_assert(
    Phase7TestHarness::countOrderPosts($transportRetry) === 2,
    'retry: two CP POSTs (fail then success)'
);
mtuc115c2_assert(
    count($stackRetry['smartUcfProbe']->calls) === 1,
    'retry: one SmartUCF after CP success'
);
mtuc115c2_assert(!empty($secondRetry['bank_redirect']), 'retry: bank redirect');

// ---------------------------------------------------------------------------
// Successful P1 + P2 regressions (Cart)
// ---------------------------------------------------------------------------
$transportOk = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportOk);
$stackOk = Phase9TestHarness::stack($transportOk);
$resultOk = $stackOk['storefront']->submit(
    Phase9TestHarness::cartStorefrontInput($stackOk, 117501)
);
mtuc115c2_assert(!empty($resultOk['success']) && !empty($resultOk['bank_redirect']), 'P1 success: bank redirect');
mtuc115c2_assert(
    !MtUniCreditFinancingTerminalNavigationSupport::isCpCreateFailureStayOnPage($resultOk),
    'P1 success: not CP failure modal'
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
$orderP2 = 117502;
$resultP2 = $stackP2['storefront']->submit(
    Phase9TestHarness::cartStorefrontInput($stackP2, $orderP2)
);
mtuc115c2_assert(!empty($resultP2['success']), 'P2 success: success');
mtuc115c2_assert(
    Phase9TestHarness::bankStatusId($stackP2, $orderP2) === MtUniCreditBankStatus::SENT_PROCESS2,
    'P2 success: bank_sent_process2'
);
mtuc115c2_assert(
    count($stackP2['smartUcfProbe']->calls) === 0,
    'P2 success: no SmartUCF'
);
$p2Session = array();
$p2Payload = MtUniCreditFinancingTerminalNavigationSupport::enrichProcess2ThankYou(
    array('success' => true, 'redirect' => '', 'bank_redirect' => false),
    $p2Session,
    $orderP2,
    $thankYouUrl
);
mtuc115c2_assert(
    (string) $p2Payload['redirect'] === $thankYouUrl,
    'P2 success: Thank You enrichment'
);

// ---------------------------------------------------------------------------
// Product 11.5C.1 regression (source still wired)
// ---------------------------------------------------------------------------
$productCtrl = mtuc115c2_read(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'product.php'
);
mtuc115c2_assert(
    strpos($productCtrl, 'enrichDefinitiveRemoteRejectThankYou') !== false
        && strpos($productCtrl, 'enrichCpCreateFailureModal') !== false,
    'Product regression: Thank You + CP modal wiring intact'
);

echo PHP_EOL . 'Phase 11.5C.2 checks: ' . $passes . ' passed';
if ($failures) {
    echo ', ' . count($failures) . ' failed' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    echo 'PHASE 11.5C.2 CART FAILURE NAVIGATION PARITY: BLOCKED' . PHP_EOL;
    exit(1);
}

echo ', 0 failed' . PHP_EOL;
echo 'PHASE 11.5C.2 CART FAILURE NAVIGATION PARITY: PASS — LOCAL' . PHP_EOL;
exit(0);
