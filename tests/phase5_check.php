<?php

/**
 * Phase 5 payment method and checkout preparation checks.
 * Run: php tests/phase5_check.php
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/support/phase2_memory_db.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
}

require_once $lib . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . '/support/phase5_harness.php';

$failures = array();
$passes = 0;

function mtuc5_assert(bool $condition, string $message): void
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

$catalogRequired = array(
    'catalog/model/extension/payment/mt_uni_credit.php',
    'catalog/controller/extension/payment/mt_uni_credit.php',
    'catalog/language/bg-bg/extension/payment/mt_uni_credit.php',
    'catalog/language/en-gb/extension/payment/mt_uni_credit.php',
    'catalog/view/theme/default/template/extension/payment/mt_uni_credit.twig',
);

foreach ($catalogRequired as $relative) {
    mtuc5_assert(is_file($root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative)), 'required file: ' . $relative);
}

$libraryRequired = array(
    'checkout_live_grand_total.php',
    'checkout_order_cart_parity.php',
    'oc3_cart_context_factory.php',
    'checkout_financing_eligibility.php',
    'checkout_payment_availability.php',
    'checkout_confirm_preparation.php',
);

foreach ($libraryRequired as $relative) {
    mtuc5_assert(is_file($lib . DIRECTORY_SEPARATOR . $relative), 'required library: ' . $relative);
}

$lifecycle = mtuc_phase0_load_fixture('oc3_lifecycle.json');
$coreRoot = dirname($root) . DIRECTORY_SEPARATOR . 'reference-oc3-core';
mtuc5_assert(is_file($coreRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $lifecycle['files']['confirm'])), 'OC3 core confirm.php characterized');
mtuc5_assert(is_file($coreRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $lifecycle['files']['cod_payment_controller'])), 'OC3 core cod payment controller characterized');
mtuc5_assert($lifecycle['findings']['native_cod_confirm_does_not_call_addOrder'] === true, 'fixture: native COD confirm does not call addOrder');
mtuc5_assert($lifecycle['findings']['session_order_id_set_immediately_after_addOrder'] === true, 'fixture: session.order_id set after addOrder');

$controllerSource = (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'payment' . DIRECTORY_SEPARATOR . 'mt_uni_credit.php');
$modelSource = (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR . 'model' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'payment' . DIRECTORY_SEPARATOR . 'mt_uni_credit.php');
mtuc5_assert(strpos($controllerSource, 'addOrder(') === false, 'payment controller never calls addOrder');
mtuc5_assert(strpos($modelSource, 'addOrder(') === false, 'payment model never calls addOrder');
mtuc5_assert(strpos($controllerSource, 'addOrderHistory(') === false, 'Phase 5 confirm does not mutate native order status');
mtuc5_assert(strpos($modelSource, 'ControlPanelClient') === false, 'payment model has no CP client usage');
mtuc5_assert(strpos($modelSource, 'refreshRemote') === false, 'payment model has no CP refresh');
mtuc5_assert(strpos($modelSource, 'function getMethod') !== false, 'payment model implements getMethod');
mtuc5_assert(strpos($modelSource, 'prepareCheckoutConfirm') !== false, 'payment model implements prepareCheckoutConfirm');
mtuc5_assert(strpos($controllerSource, "function confirm") !== false, 'payment controller implements confirm');

$address = array('country_id' => Phase5TestHarness::COUNTRY_ID, 'zone_id' => Phase5TestHarness::ZONE_ID);
$cartProducts = Phase5TestHarness::cartProducts();
$total = 500.0;

$memoryDb = new Phase2MemoryDb();
$availability = Phase5TestHarness::availability($memoryDb, Phase5TestHarness::STORE_A);
$db = new MtUniCreditDbAdapter($memoryDb, 'oc_');

mtuc5_assert($availability->isAvailable($address, $total, 'BGN', $cartProducts, Phase5TestHarness::STORE_A, true, true, 0, $db), 'valid eligible cart available');
mtuc5_assert(!$availability->isAvailable($address, $total, 'BGN', $cartProducts, Phase5TestHarness::STORE_A, false, true, 0, $db), 'module disabled unavailable');
mtuc5_assert(!$availability->isAvailable($address, $total, 'BGN', $cartProducts, Phase5TestHarness::STORE_A, true, false, 0, $db), 'payment disabled unavailable');

$memoryDb->reset();
$availability = Phase5TestHarness::availability($memoryDb, Phase5TestHarness::STORE_A);
$db = new MtUniCreditDbAdapter($memoryDb, 'oc_');
mtuc5_assert(!$availability->isAvailable($address, $total, 'EUR', $cartProducts, Phase5TestHarness::STORE_A, true, true, 0, $db), 'unsupported currency unavailable');
mtuc5_assert(!$availability->isAvailable($address, 50.0, 'BGN', $cartProducts, Phase5TestHarness::STORE_A, true, true, 0, $db), 'below min unavailable');
mtuc5_assert(!$availability->isAvailable($address, 50000.0, 'BGN', $cartProducts, Phase5TestHarness::STORE_A, true, true, 0, $db), 'above max unavailable');

$memoryDb->reset();
$availability = Phase5TestHarness::availability($memoryDb, Phase5TestHarness::STORE_A);
$db = new MtUniCreditDbAdapter($memoryDb, 'oc_');
$memoryDb->seedGeoZone(Phase5TestHarness::GEO_ZONE_ID, Phase5TestHarness::COUNTRY_ID, Phase5TestHarness::ZONE_ID);
mtuc5_assert($availability->isAvailable($address, $total, 'BGN', $cartProducts, Phase5TestHarness::STORE_A, true, true, Phase5TestHarness::GEO_ZONE_ID, $db), 'geo zone allowed');
mtuc5_assert(!$availability->isAvailable($address, $total, 'BGN', $cartProducts, Phase5TestHarness::STORE_A, true, true, 999, $db), 'geo zone denied');

$memoryDb->reset();
$settings = new MtUniCreditSettingStore(new MtUniCreditDbAdapter($memoryDb, 'oc_'), MtUniCreditConstants::MODULE_SETTINGS_CODE);
Phase4TestHarness::prepareCredentials($settings, Phase5TestHarness::STORE_A);
$availabilityNoCache = new MtUniCreditCheckoutPaymentAvailability(
    MtUniCreditBootstrap::shopConfigurationCacheFromDb(new MtUniCreditDbAdapter($memoryDb, 'oc_')),
    new MtUniCreditCredentialsRepository($settings, Phase4TestHarness::cipher()),
    new MtUniCreditOc3CartContextFactory(function () {
        return array(7);
    })
);
mtuc5_assert(!$availabilityNoCache->isAvailable($address, $total, 'BGN', $cartProducts, Phase5TestHarness::STORE_A, true, true, 0, $db), 'missing cache unavailable');

$memoryDb->reset();
Phase5TestHarness::seedFreshCache($memoryDb, Phase5TestHarness::STORE_A, 1700000000);
$staleClock = new MtUniCreditPersistenceClock(function () {
    return 1700000000 + 90000;
});
$staleCache = new MtUniCreditShopConfigurationCache(new MtUniCreditShopCacheRepository(new MtUniCreditDbAdapter($memoryDb, 'oc_'), $staleClock));
$settings = new MtUniCreditSettingStore(new MtUniCreditDbAdapter($memoryDb, 'oc_'), MtUniCreditConstants::MODULE_SETTINGS_CODE);
Phase4TestHarness::prepareCredentials($settings, Phase5TestHarness::STORE_A);
$staleAvailability = new MtUniCreditCheckoutPaymentAvailability(
    $staleCache,
    new MtUniCreditCredentialsRepository($settings, Phase4TestHarness::cipher()),
    new MtUniCreditOc3CartContextFactory(function () {
        return array(7);
    })
);
mtuc5_assert(!$staleAvailability->isAvailable($address, $total, 'BGN', $cartProducts, Phase5TestHarness::STORE_A, true, true, 0, $db), 'stale cache unavailable');

$memoryDb->reset();
Phase5TestHarness::seedFreshCache($memoryDb, Phase5TestHarness::STORE_A);
Phase5TestHarness::seedFreshCache($memoryDb, Phase5TestHarness::STORE_B);
$availabilityA = Phase5TestHarness::availability($memoryDb, Phase5TestHarness::STORE_A);
$availabilityB = Phase5TestHarness::availability($memoryDb, Phase5TestHarness::STORE_B);
$db = new MtUniCreditDbAdapter($memoryDb, 'oc_');
mtuc5_assert($availabilityA->isAvailable($address, $total, 'BGN', $cartProducts, Phase5TestHarness::STORE_A, true, true, 0, $db), 'store A available');
mtuc5_assert($availabilityB->isAvailable($address, $total, 'BGN', $cartProducts, Phase5TestHarness::STORE_B, true, true, 0, $db), 'store B available with own cache');

$preparation = Phase5TestHarness::confirmPreparation($memoryDb, Phase5TestHarness::STORE_A);
$order = Phase5TestHarness::orderRow(42, Phase5TestHarness::STORE_A);
$result = $preparation->prepare(array(
    'payment_code' => MtUniCreditConstants::EXTENSION_CODE,
    'order_id' => 42,
    'prepared_order_id' => 0,
    'order' => $order,
    'order_products' => array(array('order_product_id' => 1, 'product_id' => 1, 'quantity' => 1)),
    'cart_products' => $cartProducts,
    'get_order_options' => function () {
        return array();
    },
    'checkout_grand_total' => 500.0,
    'currency_code' => 'BGN',
    'store_id' => Phase5TestHarness::STORE_A,
    'module_enabled' => true,
    'payment_enabled' => true,
    'success_url' => 'success',
));
mtuc5_assert(!empty($result['success']), 'confirm preparation succeeds for valid order');
mtuc5_assert((int) (isset($result['prepared_order_id']) ? $result['prepared_order_id'] : 0) === 42, 'confirm preparation returns prepared order id');

$resultMissing = $preparation->prepare(array(
    'payment_code' => MtUniCreditConstants::EXTENSION_CODE,
    'order_id' => 0,
    'prepared_order_id' => 0,
    'order' => null,
    'order_products' => array(),
    'cart_products' => $cartProducts,
    'get_order_options' => function () {
        return array();
    },
    'checkout_grand_total' => 500.0,
    'currency_code' => 'BGN',
    'store_id' => Phase5TestHarness::STORE_A,
    'module_enabled' => true,
    'payment_enabled' => true,
    'success_url' => 'success',
));
mtuc5_assert(isset($resultMissing['error']) && $resultMissing['error'] === 'order_missing', 'missing order id rejected');

$resultStore = $preparation->prepare(array(
    'payment_code' => MtUniCreditConstants::EXTENSION_CODE,
    'order_id' => 42,
    'prepared_order_id' => 0,
    'order' => Phase5TestHarness::orderRow(42, 999),
    'order_products' => array(array('order_product_id' => 1, 'product_id' => 1, 'quantity' => 1)),
    'cart_products' => $cartProducts,
    'get_order_options' => function () {
        return array();
    },
    'checkout_grand_total' => 500.0,
    'currency_code' => 'BGN',
    'store_id' => Phase5TestHarness::STORE_A,
    'module_enabled' => true,
    'payment_enabled' => true,
    'success_url' => 'success',
));
mtuc5_assert(isset($resultStore['error']) && $resultStore['error'] === 'order_store_mismatch', 'wrong store rejected');

$resultChanged = $preparation->prepare(array(
    'payment_code' => MtUniCreditConstants::EXTENSION_CODE,
    'order_id' => 42,
    'prepared_order_id' => 0,
    'order' => Phase5TestHarness::orderRow(42, Phase5TestHarness::STORE_A, 999.0),
    'order_products' => array(array('order_product_id' => 1, 'product_id' => 1, 'quantity' => 1)),
    'cart_products' => $cartProducts,
    'get_order_options' => function () {
        return array();
    },
    'checkout_grand_total' => 500.0,
    'currency_code' => 'BGN',
    'store_id' => Phase5TestHarness::STORE_A,
    'module_enabled' => true,
    'payment_enabled' => true,
    'success_url' => 'success',
));
mtuc5_assert(isset($resultChanged['error']) && $resultChanged['error'] === 'order_changed', 'cart/order mismatch rejected');

$resultIdempotent = $preparation->prepare(array(
    'payment_code' => MtUniCreditConstants::EXTENSION_CODE,
    'order_id' => 42,
    'prepared_order_id' => 42,
    'order' => $order,
    'order_products' => array(),
    'cart_products' => array(),
    'get_order_options' => function () {
        return array();
    },
    'checkout_grand_total' => 500.0,
    'currency_code' => 'BGN',
    'store_id' => Phase5TestHarness::STORE_A,
    'module_enabled' => true,
    'payment_enabled' => true,
    'success_url' => 'success',
));
mtuc5_assert(!empty($resultIdempotent['success']), 'prepared order id is idempotent');

$locks = new MtUniCreditOperationLockRepository(new MtUniCreditDbAdapter($memoryDb, 'oc_'));
$ownerA = MtUniCreditLockOwnerTokenGenerator::generate();
$ownerB = MtUniCreditLockOwnerTokenGenerator::generate();
$key = hash('sha256', 'checkout:' . Phase5TestHarness::STORE_A . ':42');
mtuc5_assert($locks->acquire(Phase5TestHarness::STORE_A, MtUniCreditOperationEntryPoint::CHECKOUT, $key, $ownerA), 'first checkout lock acquire succeeds');
mtuc5_assert(!$locks->acquire(Phase5TestHarness::STORE_A, MtUniCreditOperationEntryPoint::CHECKOUT, $key, $ownerB), 'duplicate checkout lock rejected');
$locks->release(Phase5TestHarness::STORE_A, MtUniCreditOperationEntryPoint::CHECKOUT, $key, $ownerA);

$bgLang = (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR . 'language' . DIRECTORY_SEPARATOR . 'bg-bg' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'payment' . DIRECTORY_SEPARATOR . 'mt_uni_credit.php');
mtuc5_assert(strpos($bgLang, 'УниКредит покупки на Кредит') !== false, 'BG payment title wording');
mtuc5_assert(strpos($bgLang, 'cache') === false && strpos($bgLang, 'Control Panel') === false, 'customer errors avoid internal diagnostics');

echo PHP_EOL . 'Phase 5 summary: ' . $passes . ' passed, ' . count($failures) . ' failed' . PHP_EOL;

if ($failures) {
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'PHASE 5 STOP GATE: PASS — LOCAL' . PHP_EOL;
exit(0);
