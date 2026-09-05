<?php

/**
 * Phase 11.5C.2 — Product/Cart P1 real transport canary after Registry-audit recovery.
 * Run: php tests/phase11_5c2_p1_transport_check.php
 *
 * Guards remote regression: CP created but SmartUCF never called.
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
    $storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mtuc-phase115c2p1transport-storage';
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
function mtuc115c2p1_assert($condition, $message)
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

final class Mtuc115c2P1CartProbe
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
 * @return array<string, mixed>|null
 */
function mtuc115c2p1_smartDiagnostic(array $stack, $orderId)
{
    $repo = new MtUniCreditDiagnosticDebugLogRepository($stack['db']);

    return $repo->findLatestSmartUcfSessionByOrderId((int) $stack['storeId'], (int) $orderId);
}

/**
 * @param string $label
 * @param array<string, mixed> $result
 * @param array<string, mixed> $stack
 * @param Phase4FakeCpHttpTransport $transport
 * @param int $orderId
 * @return void
 */
function mtuc115c2p1_assertP1Success($label, array $result, array $stack, Phase4FakeCpHttpTransport $transport, $orderId)
{
    mtuc115c2p1_assert(!empty($result['success']), $label . ': success');
    mtuc115c2p1_assert((int) $result['order_id'] === (int) $orderId, $label . ': local OC order id');
    mtuc115c2p1_assert(
        Phase7TestHarness::countOrderPosts($transport) === 1,
        $label . ': CP POST exactly 1'
    );
    mtuc115c2p1_assert(
        count($stack['smartUcfProbe']->calls) === 1,
        $label . ': SmartUCF transport exactly 1'
    );
    mtuc115c2p1_assert(
        Phase9TestHarness::bankStatusId($stack, $orderId) === MtUniCreditBankStatus::SENT_PROCESS1,
        $label . ': bank_sent_process1'
    );
    mtuc115c2p1_assert(
        (string) (isset($result['bank_status']) ? $result['bank_status'] : '') === MtUniCreditBankStatus::SENT_PROCESS1,
        $label . ': result bank_status bank_sent_process1'
    );
    mtuc115c2p1_assert(!empty($result['bank_redirect']), $label . ': bank_redirect flag');
    mtuc115c2p1_assert(
        !empty($result['redirect']) && strpos((string) $result['redirect'], 'checkout/success') === false,
        $label . ': trusted bank redirect URL'
    );
    mtuc115c2p1_assert(
        Phase9TestHarness::countStatusPatches($transport) >= 1,
        $label . ': CP PATCH expected (>=1)'
    );

    $smart = mtuc115c2p1_smartDiagnostic($stack, $orderId);
    mtuc115c2p1_assert(is_array($smart), $label . ': SmartUCF diagnostic row present');
    mtuc115c2p1_assert(
        is_array($smart) && $smart['type'] === MtUniCreditDiagnosticJournal::TYPE_SMARTUCF_SESSION,
        $label . ': diagnostic type=smartucf_session'
    );
    mtuc115c2p1_assert(
        is_array($smart) && is_array($smart['request']) && $smart['request'] !== array(),
        $label . ': diagnostic redacted request present'
    );
    mtuc115c2p1_assert(
        is_array($smart) && is_array($smart['response']) && $smart['response'] !== array(),
        $label . ': diagnostic redacted response present'
    );
}

// ---------------------------------------------------------------------------
// Production recovery: only Cart `$this->cart` differs from 8cb9abd
// ---------------------------------------------------------------------------
$cartCtrl = (string) @file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'cart.php'
);
$productCtrl = (string) @file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'product.php'
);
$runtimeSrc = (string) @file_get_contents($lib . DIRECTORY_SEPARATOR . 'storefront_runtime.php');
$successCtrl = (string) @file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'checkout_success.php'
);

mtuc115c2p1_assert(
    preg_match('/clearCartAfterSuccessfulHandoff\\s*\\(\\s*\\$result\\s*,\\s*\\$this->cart\\s*\\)/s', $cartCtrl) === 1,
    'recovery: Cart keeps $this->cart clear fix'
);
mtuc115c2p1_assert(
    strpos($cartCtrl, 'isset($this->model_localisation_country)') !== false,
    'recovery: Cart localisation isset guards restored'
);
mtuc115c2p1_assert(
    strpos($productCtrl, 'isset($this->model_localisation_country)') !== false,
    'recovery: Product localisation isset guards restored'
);
mtuc115c2p1_assert(
    strpos($runtimeSrc, 'isset($controller->model_extension_mt_uni_credit_product)') !== false,
    'recovery: storefront_runtime isset model guard restored'
);
mtuc115c2p1_assert(
    strpos($successCtrl, 'isset($this->customer)') !== false,
    'recovery: checkout_success isset customer guard restored'
);

// ---------------------------------------------------------------------------
// Product P1 canary — MUST reach SmartUCF
// ---------------------------------------------------------------------------
$transportProduct = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportProduct);
$stackProduct = Phase9TestHarness::stack($transportProduct);
$orderProduct = 120101;
$resultProduct = $stackProduct['storefront']->submit(
    Phase9TestHarness::productStorefrontInput($stackProduct, $orderProduct)
);
mtuc115c2p1_assertP1Success('Product P1', $resultProduct, $stackProduct, $transportProduct, $orderProduct);
mtuc115c2p1_assert(
    strpos($productCtrl, 'clearCartAfterSuccessfulHandoff') === false,
    'Product P1: controller has no cart clear'
);

// ---------------------------------------------------------------------------
// Cart P1 — SmartUCF + clear after handoff
// ---------------------------------------------------------------------------
$transportCart = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportCart);
$stackCart = Phase9TestHarness::stack($transportCart);
$orderCart = 120102;
$resultCart = $stackCart['storefront']->submit(
    Phase9TestHarness::cartStorefrontInput($stackCart, $orderCart)
);
mtuc115c2p1_assertP1Success('Cart P1', $resultCart, $stackCart, $transportCart, $orderCart);
$probe = new Mtuc115c2P1CartProbe(4);
$cleared = MtUniCreditFinancingTerminalNavigationSupport::clearCartAfterSuccessfulHandoff($resultCart, $probe);
mtuc115c2p1_assert($cleared === true && $probe->count === 0, 'Cart P1: cart clear after SmartUCF handoff');
mtuc115c2p1_assert(
    count($stackCart['smartUcfProbe']->calls) === 1,
    'Cart P1: clear does not add SmartUCF calls'
);

// ---------------------------------------------------------------------------
// Product P2 + Cart P2
// ---------------------------------------------------------------------------
$transportP2Product = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportP2Product);
$stackP2Product = Phase9TestHarness::stack(
    $transportP2Product,
    null,
    null,
    Phase5TestHarness::STORE_A,
    array('uni_proces' => 1)
);
$orderP2Product = 120201;
$resultP2Product = $stackP2Product['storefront']->submit(
    Phase9TestHarness::productStorefrontInput($stackP2Product, $orderP2Product)
);
mtuc115c2p1_assert(!empty($resultP2Product['success']), 'Product P2: success');
mtuc115c2p1_assert(
    Phase9TestHarness::bankStatusId($stackP2Product, $orderP2Product) === MtUniCreditBankStatus::SENT_PROCESS2,
    'Product P2: bank_sent_process2'
);
mtuc115c2p1_assert(
    count($stackP2Product['smartUcfProbe']->calls) === 0,
    'Product P2: no SmartUCF'
);

$transportP2Cart = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportP2Cart);
$stackP2Cart = Phase9TestHarness::stack(
    $transportP2Cart,
    null,
    null,
    Phase5TestHarness::STORE_A,
    array('uni_proces' => 1)
);
$orderP2Cart = 120202;
$resultP2Cart = $stackP2Cart['storefront']->submit(
    Phase9TestHarness::cartStorefrontInput($stackP2Cart, $orderP2Cart)
);
mtuc115c2p1_assert(!empty($resultP2Cart['success']), 'Cart P2: success');
mtuc115c2p1_assert(
    Phase9TestHarness::bankStatusId($stackP2Cart, $orderP2Cart) === MtUniCreditBankStatus::SENT_PROCESS2,
    'Cart P2: bank_sent_process2'
);
mtuc115c2p1_assert(
    count($stackP2Cart['smartUcfProbe']->calls) === 0,
    'Cart P2: no SmartUCF'
);
$probeP2 = new Mtuc115c2P1CartProbe(2);
mtuc115c2p1_assert(
    MtUniCreditFinancingTerminalNavigationSupport::clearCartAfterSuccessfulHandoff($resultP2Cart, $probeP2)
        && $probeP2->count === 0,
    'Cart P2: cart clear after bank_sent_process2'
);

echo PHP_EOL . 'Phase 11.5C.2 P1 transport checks: ' . $passes . ' passed';
if ($failures) {
    echo ', ' . count($failures) . ' failed' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    echo 'PHASE 11.5C.2 REGRESSION RECOVERY: BLOCKED' . PHP_EOL;
    exit(1);
}

echo ', 0 failed' . PHP_EOL;
echo 'PHASE 11.5C.2 REGRESSION RECOVERY: PASS — LOCAL' . PHP_EOL;
exit(0);
