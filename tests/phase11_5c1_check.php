<?php

/**
 * Phase 11.5C.1 — Product Process 1 definitive SmartUCF failure → Thank You (not empty cart).
 * Run: php tests/phase11_5c1_check.php
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
    $storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mtuc-phase115c1-storage';
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
function mtuc115c1_assert($condition, $message)
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
function mtuc115c1_read($path)
{
    $body = @file_get_contents($path);

    return is_string($body) ? $body : '';
}

$thankYouUrl = 'https://shop.test/index.php?route=checkout/success';
$preparedUrl = 'https://shop.test/index.php?route=extension/payment/mt_uni_credit/prepared';
$cartUrl = 'https://shop.test/index.php?route=checkout/cart';

// ---------------------------------------------------------------------------
// Detector: definitive remote_reject only
// ---------------------------------------------------------------------------
$rejectResult = array(
    'success' => false,
    'error' => MtUniCreditSmartUcfFailureClassification::CLASS_REMOTE_REJECT,
    'order_id' => 50101,
    'cp_succeeded' => true,
    'recoverable' => false,
    'ambiguous_blocked' => false,
    'apply_native_order_status' => true,
    'message' => MtUniCreditSmartUcfSessionCoordinator::CUSTOMER_FAILED,
);
mtuc115c1_assert(
    MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveRemoteRejectTerminal($rejectResult),
    'detector: remote_reject after CP is terminal'
);

$ambiguous = $rejectResult;
$ambiguous['error'] = 'smartucf_outcome_unknown';
$ambiguous['ambiguous_blocked'] = true;
$ambiguous['recoverable'] = false;
mtuc115c1_assert(
    !MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveRemoteRejectTerminal($ambiguous),
    'detector: outcome_unknown is NOT terminal Thank You'
);

$preSend = $rejectResult;
$preSend['error'] = MtUniCreditSmartUcfFailureClassification::CLASS_PRE_SEND;
$preSend['recoverable'] = true;
$preSend['apply_native_order_status'] = false;
mtuc115c1_assert(
    !MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveRemoteRejectTerminal($preSend),
    'detector: retryable pre_send is NOT terminal Thank You'
);

$noCp = $rejectResult;
$noCp['cp_succeeded'] = false;
mtuc115c1_assert(
    !MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveRemoteRejectTerminal($noCp),
    'detector: without CP success is NOT terminal Thank You'
);

$p1Success = array(
    'success' => true,
    'order_id' => 50102,
    'bank_redirect' => true,
    'redirect' => 'https://bank.test/app',
);
mtuc115c1_assert(
    !MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveRemoteRejectTerminal($p1Success),
    'detector: SmartUCF success is NOT terminal Thank You'
);

// ---------------------------------------------------------------------------
// Enrichment: session + checkout/success (logged + guest identical navigation)
// ---------------------------------------------------------------------------
foreach (array('logged', 'guest') as $actor) {
    $session = array(
        MtUniCreditCheckoutConfirmPreparation::SESSION_PREPARED_ORDER_ID => 999,
    );
    if ($actor === 'logged') {
        $session['customer_id'] = 42;
    }
    $payload = array(
        'success' => false,
        'error' => MtUniCreditSmartUcfFailureClassification::CLASS_REMOTE_REJECT,
        'order_id' => 50110,
        'message' => 'placeholder',
    );
    $enriched = MtUniCreditFinancingTerminalNavigationSupport::enrichDefinitiveRemoteRejectThankYou(
        $payload,
        $session,
        50110,
        $thankYouUrl
    );
    mtuc115c1_assert(
        (string) $enriched['redirect'] === $thankYouUrl,
        $actor . ': redirect is checkout/success'
    );
    mtuc115c1_assert(
        strpos((string) $enriched['redirect'], 'checkout/cart') === false
            && strpos((string) $enriched['redirect'], 'checkout/cart') === false
            && (string) $enriched['redirect'] !== $cartUrl
            && (string) $enriched['redirect'] !== $preparedUrl,
        $actor . ': redirect is NOT cart/prepared'
    );
    mtuc115c1_assert(empty($enriched['success']), $actor . ': success remains false (not fake success)');
    mtuc115c1_assert(!empty($enriched['terminal']), $actor . ': terminal flag set');
    mtuc115c1_assert(
        (string) $enriched['step'] === MtUniCreditFinancingTerminalNavigationSupport::STEP_SMARTUCF_TERMINAL_FAILED,
        $actor . ': step smartucf_terminal_failed'
    );
    mtuc115c1_assert(
        (string) $enriched['message'] === MtUniCreditFinancingLeasingPresenter::SMARTUCF_TERMINAL_FAILURE_MESSAGE,
        $actor . ': customer-safe SMARTUCF_TERMINAL_FAILURE_MESSAGE'
    );
    mtuc115c1_assert(
        strpos((string) $enriched['message'], 'HTTP') === false
            && strpos((string) $enriched['message'], '422') === false
            && strpos((string) $enriched['message'], 'certificate') === false,
        $actor . ': message has no raw SmartUCF/HTTP/cert detail'
    );
    mtuc115c1_assert((int) $session['order_id'] === 50110, $actor . ': session order_id stashed');
    mtuc115c1_assert(
        (int) $session[MtUniCreditFinancingTerminalNavigationSupport::SESSION_SUCCESS_ORDER_ID] === 50110,
        $actor . ': session mt_uni_credit_success_order_id stashed'
    );
    mtuc115c1_assert(
        !isset($session[MtUniCreditCheckoutConfirmPreparation::SESSION_PREPARED_ORDER_ID]),
        $actor . ': prepared-order session key cleared'
    );
}

// ---------------------------------------------------------------------------
// Product controller wiring (source)
// ---------------------------------------------------------------------------
$productCtrl = mtuc115c1_read(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'product.php'
);
mtuc115c1_assert(
    strpos($productCtrl, 'enrichDefinitiveRemoteRejectThankYou') !== false,
    'product: uses enrichDefinitiveRemoteRejectThankYou'
);
mtuc115c1_assert(
    strpos($productCtrl, 'isDefinitiveRemoteRejectTerminal') !== false,
    'product: detects terminal SmartUCF failure before prepared fallback'
);
mtuc115c1_assert(
    strpos($productCtrl, 'CHECKOUT_SUCCESS_ROUTE') !== false,
    'product: Thank You route available'
);
// Failure branch must not always force prepared for remote_reject — terminal path first.
$failBranch = '';
if (preg_match(
    '/maybeApplyNativeOrderStatus\(\$result\);(.*?)respondJson\(\$this, \$json\);/s',
    $productCtrl,
    $m
)) {
    $failBranch = $m[1];
}
mtuc115c1_assert(
    $failBranch !== ''
        && strpos($failBranch, 'isDefinitiveRemoteRejectTerminal') !== false
        && strpos($failBranch, 'enrichDefinitiveRemoteRejectThankYou') !== false,
    'product: failure branch routes terminal reject to Thank You'
);

// Successful Process 1 must still prefer bank_redirect (regression).
mtuc115c1_assert(
    strpos($productCtrl, 'bank_redirect') !== false
        && strpos($productCtrl, 'Process 1 success must navigate to the trusted bank URL') !== false,
    'product: successful P1 bank redirect preserved'
);

// ---------------------------------------------------------------------------
// Lifecycle harness: remote reject → bank status + presentation
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
$orderReject = 115501;
$resultReject = $stackReject['storefront']->submit(
    Phase9TestHarness::productStorefrontInput($stackReject, $orderReject)
);
mtuc115c1_assert(empty($resultReject['success']), 'lifecycle: reject overall failure');
mtuc115c1_assert(!empty($resultReject['cp_succeeded']), 'lifecycle: CP succeeded');
mtuc115c1_assert(
    (string) $resultReject['error'] === MtUniCreditSmartUcfFailureClassification::CLASS_REMOTE_REJECT,
    'lifecycle: error remote_reject'
);
mtuc115c1_assert(
    MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveRemoteRejectTerminal($resultReject),
    'lifecycle: result is terminal Thank You candidate'
);
mtuc115c1_assert(
    Phase9TestHarness::bankStatusId($stackReject, $orderReject) === MtUniCreditBankStatus::SEND_FAILED_SMARTUCF,
    'lifecycle: local bank_send_failed_smartucf'
);
mtuc115c1_assert(Phase9TestHarness::countStatusPatches($transportReject) === 1, 'lifecycle: CP PATCH once');
mtuc115c1_assert(empty($resultReject['bank_redirect']), 'lifecycle: no bank_redirect on reject');

$sessionReject = array();
$jsonReject = array(
    'success' => false,
    'error' => (string) $resultReject['error'],
    'order_id' => (int) $resultReject['order_id'],
    'message' => (string) $resultReject['message'],
);
$jsonReject = MtUniCreditFinancingTerminalNavigationSupport::enrichDefinitiveRemoteRejectThankYou(
    $jsonReject,
    $sessionReject,
    (int) $resultReject['order_id'],
    $thankYouUrl
);
mtuc115c1_assert(
    strpos((string) $jsonReject['redirect'], 'checkout/success') !== false,
    'lifecycle→nav: redirect contains checkout/success'
);
mtuc115c1_assert(
    strpos((string) $jsonReject['redirect'], 'checkout/cart') === false
        && strpos((string) $jsonReject['redirect'], 'mt_uni_credit/prepared') === false,
    'lifecycle→nav: never cart or prepared'
);

// Thank You presentation without leasing snapshot (typical Process 1).
$adapter = new MtUniCreditDbAdapter($stackReject['memoryDb'], 'oc_');
$svc = new MtUniCreditFinancingPresentationService(
    new MtUniCreditFinancingPresentationRepository($adapter)
);
$tyRows = $svc->customerThankYouRows($stackReject['storeId'], $orderReject);
$tyHtml = $svc->renderCustomerThankYouHtml($tyRows);
mtuc115c1_assert($tyHtml !== '', 'thankyou: HTML present for SmartUCF failure');
mtuc115c1_assert(
    strpos($tyHtml, MtUniCreditFinancingLeasingPresenter::SMARTUCF_TERMINAL_FAILURE_MESSAGE) !== false,
    'thankyou: SMARTUCF_TERMINAL_FAILURE_MESSAGE visible'
);
mtuc115c1_assert(
    strpos($tyHtml, MtUniCreditBankStatus::LABEL_SEND_FAILED_SMARTUCF) !== false,
    'thankyou: bank failure status label visible'
);
mtuc115c1_assert(
    strpos($tyHtml, MtUniCreditFinancingLeasingPresenter::LABEL_EGN) === false
        && strpos($tyHtml, MtUniCreditFinancingLeasingPresenter::LABEL_PHONE2) === false,
    'thankyou: no EGN/phone2'
);
mtuc115c1_assert(
    strpos($tyHtml, 'Declined by bank') === false
        && strpos($tyHtml, 'errorCode') === false
        && strpos($tyHtml, '422') === false,
    'thankyou: no raw SmartUCF error payload'
);

// Successful P1 regression (bank redirect).
$transportOk = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportOk);
$stackOk = Phase9TestHarness::stack($transportOk, null);
$orderOk = 115502;
$resultOk = $stackOk['storefront']->submit(
    Phase9TestHarness::productStorefrontInput($stackOk, $orderOk)
);
mtuc115c1_assert(!empty($resultOk['success']), 'P1 success: success');
mtuc115c1_assert(!empty($resultOk['bank_redirect']), 'P1 success: bank_redirect');
mtuc115c1_assert(
    !empty($resultOk['redirect']) && strpos((string) $resultOk['redirect'], 'checkout/success') === false,
    'P1 success: redirect is bank URL not Thank You'
);
mtuc115c1_assert(
    !MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveRemoteRejectTerminal($resultOk),
    'P1 success: not classified as terminal failure'
);

// Process 2 regression: still Thank You via enrichProcess2ThankYou.
$p2Session = array();
$p2Payload = MtUniCreditFinancingTerminalNavigationSupport::enrichProcess2ThankYou(
    array('success' => true, 'redirect' => '', 'bank_redirect' => false),
    $p2Session,
    115503,
    $thankYouUrl
);
mtuc115c1_assert(
    !empty($p2Payload['success']) && (string) $p2Payload['redirect'] === $thankYouUrl,
    'P2 regression: Thank You enrichment intact'
);

echo PHP_EOL . 'Phase 11.5C.1 checks: ' . $passes . ' passed';
if ($failures) {
    echo ', ' . count($failures) . ' failed' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    echo 'PHASE 11.5C.1 PRODUCT P1 SMARTUCF FAILURE RESULT: BLOCKED' . PHP_EOL;
    exit(1);
}

echo ', 0 failed' . PHP_EOL;
echo 'PHASE 11.5C.1 PRODUCT P1 SMARTUCF FAILURE RESULT: PASS — LOCAL' . PHP_EOL;
exit(0);
