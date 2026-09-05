<?php

/**
 * Phase 11.5C.2 — Cart success-only live cart clear after bank handoff.
 * Run: php tests/phase11_5c2_clear_check.php
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
    $storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mtuc-phase115c2clear-storage';
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
function mtuc115c2c_assert($condition, $message)
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
function mtuc115c2c_read($path)
{
    $body = @file_get_contents($path);

    return is_string($body) ? $body : '';
}

final class Mtuc115c2ClearProbeCart
{
    /** @var int */
    public $count;

    /** @var int */
    public $clearCalls = 0;

    /**
     * @param int $count
     */
    public function __construct($count)
    {
        $this->count = (int) $count;
    }

    /**
     * @return void
     */
    public function clear()
    {
        $this->clearCalls++;
        $this->count = 0;
    }
}

/**
 * @param array<string, mixed> $stack
 * @param int $orderId
 * @return array<string, mixed>
 */
function mtuc115c2c_cartInput(array $stack, $orderId)
{
    return Phase9TestHarness::cartStorefrontInput($stack, $orderId);
}

$cartCtrl = mtuc115c2c_read(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'cart.php'
);
$productCtrl = mtuc115c2c_read(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'product.php'
);
$paymentCtrl = mtuc115c2c_read(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'payment'
        . DIRECTORY_SEPARATOR . 'mt_uni_credit.php'
);

mtuc115c2c_assert(
    strpos($cartCtrl, 'clearCartAfterSuccessfulHandoff') !== false,
    'cart controller wires success-only cart clear'
);
mtuc115c2c_assert(
    strpos($productCtrl, 'clearCartAfterSuccessfulHandoff') === false
        && strpos($productCtrl, 'cart->clear') === false,
    'Product: no cart clear wiring'
);
mtuc115c2c_assert(
    strpos($paymentCtrl, 'cart->clear') === false,
    'Checkout/payment: no cart clear regression'
);

// Detector edges
mtuc115c2c_assert(
    !MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff(array(
        'success' => true,
        'order_id' => 1,
        'redirect' => 'https://bank.example/x',
        'bank_redirect' => true,
    )),
    'detector: success+redirect alone is NOT enough without bank_status'
);
mtuc115c2c_assert(
    MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff(array(
        'success' => true,
        'bank_status' => MtUniCreditBankStatus::SENT_PROCESS1,
    )),
    'detector: bank_sent_process1 is successful handoff'
);
mtuc115c2c_assert(
    MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff(array(
        'success' => true,
        'bank_status' => MtUniCreditBankStatus::SENT_PROCESS2,
    )),
    'detector: bank_sent_process2 is successful handoff'
);
mtuc115c2c_assert(
    !MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff(array(
        'success' => false,
        'bank_status' => MtUniCreditBankStatus::SENT_PROCESS1,
    )),
    'detector: failure never clears even with sent status'
);
mtuc115c2c_assert(
    !MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff(array(
        'success' => true,
        'bank_status' => MtUniCreditBankStatus::SEND_FAILED_SMARTUCF,
    )),
    'detector: bank_send_failed_smartucf is NOT successful handoff'
);

// ---------------------------------------------------------------------------
// P1 success — clear cart, preserve bank redirect
// ---------------------------------------------------------------------------
$transportP1 = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportP1);
$stackP1 = Phase9TestHarness::stack($transportP1);
$orderP1 = 118101;
$inputP1 = mtuc115c2c_cartInput($stackP1, $orderP1);
$resultP1 = $stackP1['storefront']->submit($inputP1);
mtuc115c2c_assert(!empty($resultP1['success']), 'P1 success: success');
mtuc115c2c_assert(!empty($resultP1['bank_redirect']), 'P1 success: bank_redirect');
mtuc115c2c_assert(
    (string) $resultP1['bank_status'] === MtUniCreditBankStatus::SENT_PROCESS1,
    'P1 success: bank_status bank_sent_process1'
);
mtuc115c2c_assert(
    Phase9TestHarness::bankStatusId($stackP1, $orderP1) === MtUniCreditBankStatus::SENT_PROCESS1,
    'P1 success: persisted bank_sent_process1'
);
$probeP1 = new Mtuc115c2ClearProbeCart(3);
mtuc115c2c_assert($probeP1->count > 0, 'P1 success: cart count before > 0');
$clearedP1 = MtUniCreditFinancingTerminalNavigationSupport::clearCartAfterSuccessfulHandoff($resultP1, $probeP1);
mtuc115c2c_assert($clearedP1 === true, 'P1 success: clear invoked');
mtuc115c2c_assert($probeP1->count === 0, 'P1 success: cart count after = 0');
mtuc115c2c_assert($probeP1->clearCalls === 1, 'P1 success: clear once');
mtuc115c2c_assert(!empty($resultP1['redirect']), 'P1 success: bank redirect preserved');

// Replay: empty cart does not break; clear is idempotent; no duplicate SmartUCF
$inputReplay = $inputP1;
if (isset($resultP1['session']) && is_array($resultP1['session'])) {
    $inputReplay['session'] = $resultP1['session'];
}
$replayP1 = $stackP1['storefront']->submit($inputReplay);
mtuc115c2c_assert(!empty($replayP1['success']), 'P1 replay: success');
mtuc115c2c_assert(
    count($stackP1['smartUcfProbe']->calls) === 1,
    'P1 replay: no duplicate SmartUCF'
);
mtuc115c2c_assert(
    Phase7TestHarness::countOrderPosts($transportP1) === 1,
    'P1 replay: no duplicate CP create'
);
$clearedReplay = MtUniCreditFinancingTerminalNavigationSupport::clearCartAfterSuccessfulHandoff($replayP1, $probeP1);
mtuc115c2c_assert($clearedReplay === true && $probeP1->count === 0, 'P1 replay: clear idempotent on empty cart');

// ---------------------------------------------------------------------------
// P2 success — clear cart, Thank You path
// ---------------------------------------------------------------------------
$transportP2 = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportP2);
$stackP2 = Phase9TestHarness::stack(
    $transportP2,
    null,
    null,
    Phase5TestHarness::STORE_A,
    array('uni_proces' => 1)
);
$orderP2 = 118102;
$resultP2 = $stackP2['storefront']->submit(mtuc115c2c_cartInput($stackP2, $orderP2));
mtuc115c2c_assert(!empty($resultP2['success']), 'P2 success: success');
mtuc115c2c_assert(
    (string) $resultP2['bank_status'] === MtUniCreditBankStatus::SENT_PROCESS2,
    'P2 success: bank_status bank_sent_process2'
);
mtuc115c2c_assert(
    Phase9TestHarness::bankStatusId($stackP2, $orderP2) === MtUniCreditBankStatus::SENT_PROCESS2,
    'P2 success: persisted bank_sent_process2'
);
mtuc115c2c_assert(empty($resultP2['bank_redirect']), 'P2 success: no bank_redirect (Thank You)');
$probeP2 = new Mtuc115c2ClearProbeCart(2);
$clearedP2 = MtUniCreditFinancingTerminalNavigationSupport::clearCartAfterSuccessfulHandoff($resultP2, $probeP2);
mtuc115c2c_assert($clearedP2 === true && $probeP2->count === 0, 'P2 success: cart cleared');
$p2Session = array();
$p2Payload = MtUniCreditFinancingTerminalNavigationSupport::enrichProcess2ThankYou(
    array('success' => true, 'redirect' => '', 'bank_redirect' => false),
    $p2Session,
    $orderP2,
    'https://shop.test/index.php?route=checkout/success'
);
mtuc115c2c_assert(
    strpos((string) $p2Payload['redirect'], 'checkout/success') !== false,
    'P2 success: Thank You preserved'
);

// ---------------------------------------------------------------------------
// Broken SmartUCF — do NOT clear
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
$orderReject = 118201;
$resultReject = $stackReject['storefront']->submit(mtuc115c2c_cartInput($stackReject, $orderReject));
mtuc115c2c_assert(empty($resultReject['success']), 'Smart fail: overall failure');
mtuc115c2c_assert(
    (string) $resultReject['bank_status'] === MtUniCreditBankStatus::SEND_FAILED_SMARTUCF,
    'Smart fail: bank_send_failed_smartucf'
);
mtuc115c2c_assert(
    !MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff($resultReject),
    'Smart fail: not successful handoff'
);
$probeReject = new Mtuc115c2ClearProbeCart(4);
$clearedReject = MtUniCreditFinancingTerminalNavigationSupport::clearCartAfterSuccessfulHandoff(
    $resultReject,
    $probeReject
);
mtuc115c2c_assert($clearedReject === false && $probeReject->count === 4, 'Smart fail: cart NOT cleared');
mtuc115c2c_assert(
    MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveRemoteRejectTerminal($resultReject),
    'Smart fail: Thank You failure path preserved'
);

// ---------------------------------------------------------------------------
// Broken CP P1 + P2 — do NOT clear
// ---------------------------------------------------------------------------
foreach (array('P1' => array(), 'P2' => array('uni_proces' => 1)) as $label => $shopOverrides) {
    $transportCp = new Phase4FakeCpHttpTransport();
    $payloads = Phase7TestHarness::loginAndOrderSuccessPayloads();
    $transportCp->enqueueJson(200, $payloads['login']);
    $transportCp->enqueueJson(422, array('success' => false, 'message' => 'invalid'));
    $stackCp = Phase9TestHarness::stack(
        $transportCp,
        null,
        null,
        Phase5TestHarness::STORE_A,
        $shopOverrides
    );
    $orderCp = 118300 + ($label === 'P2' ? 1 : 0);
    $resultCp = $stackCp['storefront']->submit(mtuc115c2c_cartInput($stackCp, $orderCp));
    mtuc115c2c_assert(empty($resultCp['success']), $label . ' CP fail: failure');
    mtuc115c2c_assert(empty($resultCp['cp_succeeded']), $label . ' CP fail: no CP');
    mtuc115c2c_assert(
        !MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff($resultCp),
        $label . ' CP fail: not handoff success'
    );
    $probeCp = new Mtuc115c2ClearProbeCart(5);
    $clearedCp = MtUniCreditFinancingTerminalNavigationSupport::clearCartAfterSuccessfulHandoff($resultCp, $probeCp);
    mtuc115c2c_assert($clearedCp === false && $probeCp->count === 5, $label . ' CP fail: cart NOT cleared');
    $sessionCp = array();
    $jsonCp = MtUniCreditFinancingTerminalNavigationSupport::enrichCpCreateFailureModal(
        array(
            'success' => false,
            'message' => (string) $resultCp['message'],
            'order_id' => (int) $resultCp['order_id'],
        ),
        $sessionCp
    );
    mtuc115c2c_assert(
        (string) $jsonCp['terminal_ui'] === MtUniCreditFinancingTerminalNavigationSupport::UI_ERROR_MODAL
            && !empty($jsonCp['stay_on_page']),
        $label . ' CP fail: error modal stay_on_page'
    );
}

// ---------------------------------------------------------------------------
// SmartUCF success + later CP PATCH failure — cart still cleared
// ---------------------------------------------------------------------------
$transportPatch = new Phase4FakeCpHttpTransport();
$transportPatch->failStatusPatch = true;
Phase9TestHarness::enqueueCpCreateSuccess($transportPatch);
$stackPatch = Phase9TestHarness::stack($transportPatch);
$orderPatch = 118401;
$resultPatch = $stackPatch['storefront']->submit(mtuc115c2c_cartInput($stackPatch, $orderPatch));
mtuc115c2c_assert(!empty($resultPatch['success']), 'PATCH fail: overall success');
mtuc115c2c_assert(!empty($resultPatch['bank_redirect']), 'PATCH fail: bank redirect');
mtuc115c2c_assert(
    (string) $resultPatch['bank_status'] === MtUniCreditBankStatus::SENT_PROCESS1,
    'PATCH fail: bank_sent_process1 durable'
);
mtuc115c2c_assert(
    count($stackPatch['smartUcfProbe']->calls) === 1,
    'PATCH fail: single SmartUCF call'
);
$probePatch = new Mtuc115c2ClearProbeCart(1);
mtuc115c2c_assert(
    MtUniCreditFinancingTerminalNavigationSupport::clearCartAfterSuccessfulHandoff($resultPatch, $probePatch)
        && $probePatch->count === 0,
    'PATCH fail: cart cleared despite CP PATCH failure'
);

// ---------------------------------------------------------------------------
// P2 mail failure after bank_sent_process2 — cart remains cleared
// ---------------------------------------------------------------------------
$transportMail = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportMail);
$stackMail = Phase9TestHarness::stack(
    $transportMail,
    null,
    null,
    Phase5TestHarness::STORE_A,
    array('uni_proces' => 1)
);
$stackMail['process2Mailer']->forceFailure = true;
$orderMail = 118402;
$resultMail = $stackMail['storefront']->submit(mtuc115c2c_cartInput($stackMail, $orderMail));
mtuc115c2c_assert(
    Phase9TestHarness::bankStatusId($stackMail, $orderMail) === MtUniCreditBankStatus::SENT_PROCESS2,
    'mail fail: bank_sent_process2 established'
);
// Success may still be true when bank_sent is durable; clear gate follows bank_status.
if (!empty($resultMail['success'])) {
    mtuc115c2c_assert(
        (string) $resultMail['bank_status'] === MtUniCreditBankStatus::SENT_PROCESS2,
        'mail fail: success result carries bank_sent_process2'
    );
    $probeMail = new Mtuc115c2ClearProbeCart(2);
    mtuc115c2c_assert(
        MtUniCreditFinancingTerminalNavigationSupport::clearCartAfterSuccessfulHandoff($resultMail, $probeMail)
            && $probeMail->count === 0,
        'mail fail: cart cleared when handoff success returned'
    );
} else {
    // If architecture returns failure after mail fail, still assert durable status and
    // that success-only clear does not run on failure payload.
    $probeMail = new Mtuc115c2ClearProbeCart(2);
    mtuc115c2c_assert(
        !MtUniCreditFinancingTerminalNavigationSupport::clearCartAfterSuccessfulHandoff($resultMail, $probeMail)
            && $probeMail->count === 2,
        'mail fail: failure payload does not clear via success-only gate'
    );
    // But once bank_sent_process2 is known, a synthetic handoff result still clears.
    $synthetic = array(
        'success' => true,
        'bank_status' => MtUniCreditBankStatus::SENT_PROCESS2,
        'order_id' => $orderMail,
    );
    mtuc115c2c_assert(
        MtUniCreditFinancingTerminalNavigationSupport::clearCartAfterSuccessfulHandoff($synthetic, $probeMail)
            && $probeMail->count === 0,
        'mail fail: durable bank_sent_process2 authorizes clear (no rollback)'
    );
}

echo PHP_EOL . 'Phase 11.5C.2 cart-clear checks: ' . $passes . ' passed';
if ($failures) {
    echo ', ' . count($failures) . ' failed' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    echo 'PHASE 11.5C.2 CART SUCCESS CLEAR: BLOCKED' . PHP_EOL;
    exit(1);
}

echo ', 0 failed' . PHP_EOL;
echo 'PHASE 11.5C.2 CART SUCCESS CLEAR: PASS — LOCAL' . PHP_EOL;
exit(0);
