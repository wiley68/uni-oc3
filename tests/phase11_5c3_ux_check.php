<?php

/**
 * Phase 11.5C.3 — Checkout UX/runtime closure (CSS, first installment, loader, cart clear).
 * Run: php tests/phase11_5c3_ux_check.php
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
    $storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mtuc-phase115c3ux-storage';
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
function mtuc115c3ux_assert($condition, $message)
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
function mtuc115c3ux_read($path)
{
    $body = @file_get_contents($path);

    return is_string($body) ? $body : '';
}

$controllerPath = $root . '/upload/catalog/controller/extension/payment/mt_uni_credit.php';
$twigPath = $root . '/upload/catalog/view/theme/default/template/extension/payment/mt_uni_credit.twig';
$jsPath = $root . '/upload/catalog/view/theme/default/template/extension/payment/mt_uni_credit_checkout.js';
$cssPath = $root . '/upload/catalog/view/theme/default/template/extension/payment/mt_uni_credit_checkout.css';
$presenterPath = $lib . '/storefront_calculator_presenter.php';
$servicePath = $lib . '/checkout_financing_submission_service.php';
$storefrontCss = $root . '/upload/catalog/view/theme/default/template/extension/mt_uni_credit/storefront.css';

$controller = mtuc115c3ux_read($controllerPath);
$twig = mtuc115c3ux_read($twigPath);
$js = mtuc115c3ux_read($jsPath);
$css = mtuc115c3ux_read($cssPath);
$presenter = mtuc115c3ux_read($presenterPath);
$service = mtuc115c3ux_read($servicePath);
$sfCss = mtuc115c3ux_read($storefrontCss);

// --- CSS delivery ---
mtuc115c3ux_assert(
    strpos($sfCss, '#mt-uni-credit-product-modal .mt-uni-credit-storefront__popup-calc') !== false
        && strpos($sfCss, '#mt-uni-credit-checkout-root') === false,
    'root cause: storefront.css scopes popup styles to product/cart modals only'
);
mtuc115c3ux_assert(
    strpos($css, '#mt-uni-credit-checkout-root .mt-uni-credit-storefront__popup-calc') !== false,
    'checkout.css bridges popup-calc under checkout root'
);
mtuc115c3ux_assert(
    strpos($twig, 'data-mtuc-checkout-asset="css"') !== false
        && strpos($twig, 'checkout_css_href') !== false
        && strpos($twig, '<link rel="stylesheet" href="{{ checkout_css_href }}"') !== false,
    'rendered HTML includes stylesheet link for checkout CSS'
);
mtuc115c3ux_assert(
    strpos($twig, 'product_css_href') !== false
        && strpos($twig, '<link rel="stylesheet" href="{{ product_css_href }}"') !== false,
    'rendered HTML includes storefront CSS link'
);
mtuc115c3ux_assert(
    strpos($twig, "data-mtuc-checkout-asset") !== false
        && strpos($twig, 'querySelector(\'link[data-mtuc-checkout-asset="css"]') !== false,
    'AJAX re-render: idempotent link insertion guard'
);
mtuc115c3ux_assert(
    (strpos($controller, "'checkout_css_href'") !== false
        || strpos($controller, "['checkout_css_href']") !== false)
        && strpos($controller, 'CHECKOUT_ASSET_CSS_RELATIVE') !== false
        && strpos($controller, 'buildCheckoutPanelViewData') !== false,
    'controller exposes versioned checkout_css_href to Twig'
);
mtuc115c3ux_assert(
    strpos($css, 'mt-uni-credit-checkout--processing') !== false
        && strpos($css, 'mt-uni-credit-checkout-processing-active') !== false,
    'loader CSS lock classes present'
);

// --- First installment reset ---
mtuc115c3ux_assert(
    strpos($js, 'resetFirstInstallmentForSchemeChange') !== false
        && strpos($js, 'recalculate(scheme, 0)') !== false,
    'scheme change resets first installment to 0 before recalculate'
);
mtuc115c3ux_assert(
    strpos($js, 'show_first_installment === false') !== false
        && strpos($js, '$first.val("0")') !== false,
    'non-supporting scheme forces DOM first installment to 0'
);
mtuc115c3ux_assert(
    strpos($js, 'resolveSubmitFirstInstallment') !== false
        && strpos($js, 'return 0') !== false,
    'submit uses 0 when first installment not shown'
);
mtuc115c3ux_assert(
    strpos($presenter, 'firstInstallment->visible') !== false,
    'server presentSchemeCalculation uses calculator visible flag'
);

$calc = new MtUniCreditCalculator();
$shop = mtuc4_valid_shop_snapshot();
$shop['uni_first_vnoska'] = 1;
$shop['uni_proces'] = 0;
// Prefer a real scheme from snapshot if available via presentSchemeCalculation path.
$presenterObj = new MtUniCreditStorefrontCalculatorPresenter($calc);
$memoryDb = new Phase2MemoryDb();
Phase5TestHarness::seedFreshCache($memoryDb, Phase5TestHarness::STORE_A);
$db = new MtUniCreditDbAdapter($memoryDb, 'oc_');
$unicid = Phase4TestHarness::TEST_UNICID;
$freshShop = MtUniCreditBootstrap::shopConfigurationCacheFromDb($db)->getFreshShopData(Phase5TestHarness::STORE_A, $unicid);
if (!is_array($freshShop) || $freshShop === array()) {
    $freshShop = $shop;
}
$freshShop['uni_first_vnoska'] = 1;

// Build a minimal available scheme via cart resolution when possible.
$cartFactory = new MtUniCreditOc3CartContextFactory(function () {
    return array(7);
});
$cart = $cartFactory->create(Phase5TestHarness::cartProducts(), 500.0);
$resolution = (new MtUniCreditCartSchemeResolver($calc))->resolve($freshShop, $cart);
$schemes = (new MtUniCreditCartSchemeResolver($calc))->unifiedSchemes($resolution, $freshShop);
mtuc115c3ux_assert(count($schemes) > 0, 'fixture has eligible checkout schemes');

if (count($schemes) >= 1) {
    $schemeA = $schemes[0];
    $withFirst = $presenterObj->presentSchemeCalculation($freshShop, 500.0, $schemeA, 120.0);
    mtuc115c3ux_assert(
        is_array($withFirst) && (float) $withFirst['first_installment'] >= 0,
        'scheme A recalculate accepts first installment request'
    );

    // Disable first installment shop-wide → visible false → amount 0.
    $freshShopNoFirst = $freshShop;
    $freshShopNoFirst['uni_first_vnoska'] = 0;
    $without = $presenterObj->presentSchemeCalculation($freshShopNoFirst, 500.0, $schemeA, 120.0);
    mtuc115c3ux_assert(
        is_array($without)
            && (float) $without['first_installment'] === 0.0
            && empty($without['show_first_installment']),
        'scheme without first-installment support → amount 0 + hidden'
    );

    // Reverse: re-enable → server returns authoritative value (not stale 120 blindly if locked).
    $again = $presenterObj->presentSchemeCalculation($freshShop, 500.0, $schemeA, 0.0);
    mtuc115c3ux_assert(
        is_array($again) && !empty($again['show_first_installment']),
        'reverse switch restores first-installment visibility from server'
    );
}

// --- Loader ---
mtuc115c3ux_assert(
    strpos($js, 'function setProcessing(active)') !== false
        && strpos($js, 'mt-uni-credit-checkout--processing') !== false
        && strpos($js, 'mt-uni-credit-checkout-processing-active') !== false,
    'loader reuses Product/Cart/OC4 terminal lock classes'
);
mtuc115c3ux_assert(
    strpos($js, 'setProcessing(true)') !== false
        && strpos($js, 'confirmBusy = true') !== false
        && preg_match('/confirmBusy\s*\|\|\s*redirectTerminal/', $js) === 1,
    'valid confirm enables lock before AJAX; double-submit blocked'
);
mtuc115c3ux_assert(
    preg_match('/json\\.redirect[\\s\\S]*?window\\.location[\\s\\S]*?return;/s', $js) === 1
        && strpos($js, 'Keep loader ON until navigation') !== false,
    'success navigation keeps loader active'
);
mtuc115c3ux_assert(
    substr_count($js, 'setProcessing(false)') >= 2,
    'error responses release lock'
);

// --- Cart clear ---
mtuc115c3ux_assert(
    strpos($controller, 'clearCartAfterSuccessfulHandoff') !== false
        && preg_match('/clearCartAfterSuccessfulHandoff\\s*\\([\\s\\S]*?\\$this->cart\\s*\\)/s', $controller) === 1,
    'confirm clears cart via $this->cart after handoff'
);
mtuc115c3ux_assert(strpos($controller, 'isset($this->cart)') === false, 'no isset($this->cart) trap');
mtuc115c3ux_assert(strpos($service, "'bank_status'") !== false, 'checkout submit exposes bank_status');

final class Mtuc115c3UxProbeCart
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

$probe = new Mtuc115c3UxProbeCart(3);
$cleared = MtUniCreditFinancingTerminalNavigationSupport::clearCartAfterSuccessfulHandoff(
    array('success' => true, 'bank_status' => MtUniCreditBankStatus::SENT_PROCESS1),
    $probe
);
mtuc115c3ux_assert($cleared && $probe->count === 0 && $probe->clearCalls === 1, 'P1 handoff clears cart');

$probe2 = new Mtuc115c3UxProbeCart(2);
$cleared2 = MtUniCreditFinancingTerminalNavigationSupport::clearCartAfterSuccessfulHandoff(
    array('success' => true, 'bank_status' => MtUniCreditBankStatus::SENT_PROCESS2),
    $probe2
);
mtuc115c3ux_assert($cleared2 && $probe2->count === 0, 'P2 handoff clears cart');

$probe3 = new Mtuc115c3UxProbeCart(4);
$noClear = MtUniCreditFinancingTerminalNavigationSupport::clearCartAfterSuccessfulHandoff(
    array('success' => true, 'redirect' => 'https://bank.test', 'bank_redirect' => true),
    $probe3
);
mtuc115c3ux_assert(!$noClear && $probe3->count === 4, 'no premature clear without bank_status');

// Index/calculate must not clear
$indexBody = '';
if (preg_match('/function index\s*\([\s\S]*?\n    public function /s', $controller, $m)) {
    $indexBody = $m[0];
}
mtuc115c3ux_assert(
    $indexBody !== '' && strpos($indexBody, 'clearCartAfterSuccessfulHandoff') === false,
    'render/index does not clear cart'
);
$calcBody = '';
if (preg_match('/function calculate\s*\([\s\S]*?\n    public function /s', $controller, $m)) {
    $calcBody = $m[0];
}
$recalcBody = '';
if (preg_match('/function recalculate\s*\([\s\S]*?\n    public function /s', $controller, $m)) {
    $recalcBody = $m[0];
}
mtuc115c3ux_assert(
    strpos($calcBody, 'clearCart') === false && strpos($recalcBody, 'clearCart') === false,
    'calculate/recalculate do not clear cart'
);

// Identity: confirm still prepare + no addOrder
$confirmBody = '';
if (preg_match('/function confirm\s*\([\s\S]*?\n    public function /s', $controller, $m)) {
    $confirmBody = $m[0];
}
mtuc115c3ux_assert(
    strpos($confirmBody, 'prepareCheckoutConfirm') !== false
        && strpos($confirmBody, 'addOrder(') === false,
    'native order_id reuse intact; no second addOrder'
);
mtuc115c3ux_assert(
    strpos($confirmBody, 'enrichProcess2ThankYou') !== false
        && strpos($controller, 'CHECKOUT_SUCCESS_ROUTE') !== false,
    'Thank You identity path preserved'
);

// Scope: no broad registry cleanup in this controller beyond cart clear
mtuc115c3ux_assert(
    strpos($controller, 'isset($this->model_') === false,
    'no broad isset($this->model_*) cleanup'
);

echo PHP_EOL . 'Phase 11.5C.3 UX summary: ' . $passes . ' passed, ' . count($failures) . ' failed' . PHP_EOL;
if ($failures) {
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'PHASE 11.5C.3 CHECKOUT UX RUNTIME CLOSURE: PASS — LOCAL' . PHP_EOL;
exit(0);
