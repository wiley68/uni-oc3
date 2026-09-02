<?php

/**
 * Phase 8 Product/Cart storefront + OCMOD/Journal local checks.
 * Run: php tests/phase8_check.php
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/support/phase2_memory_db.php';
require_once __DIR__ . '/support/phase3_shop_fixture.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';
$catalog = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
}

require_once $lib . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . '/support/phase4_harness.php';
require_once __DIR__ . '/support/phase5_harness.php';
require_once __DIR__ . '/support/phase7_harness.php';
require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

$failures = array();
$passes = 0;

function mtuc8_assert(bool $condition, string $message): void
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

$required = array(
    'storefront_asset_urls.php',
    'storefront_csrf.php',
    'product_buy_preference.php',
    'product_line.php',
    'oc3_product_line_resolver.php',
    'storefront_calculator_presenter.php',
    'storefront_order_draft_builder.php',
    'storefront_operation_identity.php',
    'storefront_financing_submission_service.php',
    'storefront_runtime.php',
);
foreach ($required as $file) {
    mtuc8_assert(is_file($lib . DIRECTORY_SEPARATOR . $file), 'required library: ' . $file);
}

$requiredCatalog = array(
    'controller/extension/mt_uni_credit/product.php',
    'controller/extension/mt_uni_credit/cart.php',
    'model/extension/mt_uni_credit/product.php',
    'model/extension/mt_uni_credit/cart.php',
    'view/theme/default/template/extension/mt_uni_credit/product_widget.twig',
    'view/theme/default/template/extension/mt_uni_credit/cart_widget.twig',
    'view/theme/default/template/extension/mt_uni_credit/modal.twig',
    'view/theme/default/template/extension/mt_uni_credit/storefront.css',
    'view/theme/default/template/extension/mt_uni_credit/storefront.js',
    'language/bg-bg/extension/mt_uni_credit/product.php',
    'language/bg-bg/extension/mt_uni_credit/cart.php',
    'language/en-gb/extension/mt_uni_credit/product.php',
    'language/en-gb/extension/mt_uni_credit/cart.php',
);
foreach ($requiredCatalog as $relative) {
    mtuc8_assert(
        is_file($catalog . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative)),
        'required catalog: ' . $relative
    );
}

mtuc8_assert(
    MtUniCreditConstants::PRODUCT_ROUTE === 'extension/mt_uni_credit/product',
    'PRODUCT_ROUTE constant'
);
mtuc8_assert(
    MtUniCreditConstants::CART_ROUTE === 'extension/mt_uni_credit/cart',
    'CART_ROUTE constant'
);

// Presenter eligible / ineligible
$shop = mtuc3_golden_shop(array('uni_eur' => 0, 'uni_vnoska' => 1));
$presenter = new MtUniCreditStorefrontCalculatorPresenter();
$product = new MtUniCreditProductContext(42, array(7), 500.0);
$eligible = $presenter->presentProduct($shop, $product, 'BGN');
mtuc8_assert(is_array($eligible) && isset($eligible['offers']['standard']), 'product presenter eligible');
$ineligible = $presenter->presentProduct($shop, new MtUniCreditProductContext(42, array(7), 1.0), 'BGN');
mtuc8_assert($ineligible === null, 'product presenter ineligible low amount');
$wrongCurrency = $presenter->presentProduct($shop, $product, 'USD');
mtuc8_assert($wrongCurrency === null, 'product presenter rejects unsupported currency');

// Quantity scales financing price in resolver
$resolver = new MtUniCreditOc3ProductLineResolver(
    function ($price, $taxClassId) {
        return (float) $price * 1.2;
    },
    function ($amount, $from, $to) {
        return (float) $amount;
    },
    function () {
        return array(3, 5);
    }
);
$lineQty1 = $resolver->resolve(
    array('product_id' => 9, 'price' => 100.0, 'tax_class_id' => 1, 'name' => 'P', 'model' => 'M'),
    1,
    array(),
    'BGN',
    'BGN'
);
$lineQty3 = $resolver->resolve(
    array('product_id' => 9, 'price' => 100.0, 'tax_class_id' => 1, 'name' => 'P', 'model' => 'M'),
    3,
    array(),
    'BGN',
    'BGN'
);
mtuc8_assert(
    abs($lineQty1->financingPrice - 120.0) < 0.0001,
    'resolver qty1 financingPrice = unitWithTax'
);
mtuc8_assert(
    abs($lineQty3->financingPrice - 360.0) < 0.0001,
    'resolver qty3 scales financingPrice'
);
mtuc8_assert($lineQty3->categories === array(3, 5), 'resolver categories from loader');

// Cart intersection empty vs eligible
$calculator = new MtUniCreditCalculator();
$cartResolver = new MtUniCreditCartSchemeResolver($calculator);
$emptyCart = new MtUniCreditCartContext(array(), 0.0);
$emptyResolution = $cartResolver->resolve($shop, $emptyCart);
mtuc8_assert(
    $emptyResolution->standardOffer === null && $emptyResolution->promoOffer === null,
    'cart intersection empty cart ineligible'
);
$eligibleCart = new MtUniCreditCartContext(
    array(mtuc3_cart_line(42, array(7), 500.0)),
    500.0
);
$eligibleResolution = $cartResolver->resolve($shop, $eligibleCart);
$cartPresented = $presenter->presentCart($shop, $eligibleCart, $eligibleResolution, 'BGN');
mtuc8_assert(is_array($cartPresented) && !empty($cartPresented['hide_secondary']), 'cart presenter eligible + hide_secondary');
mtuc8_assert(isset($cartPresented['cart_fingerprint']) && $cartPresented['cart_fingerprint'] !== '', 'cart fingerprint present');

// CSRF
$session = array();
$token = MtUniCreditStorefrontCsrf::issue($session);
mtuc8_assert(strlen($token) === 64, 'csrf token 32 bytes hex');
mtuc8_assert(MtUniCreditStorefrontCsrf::verify($session, $token), 'csrf verify ok');
mtuc8_assert(!MtUniCreditStorefrontCsrf::verify($session, 'deadbeef'), 'csrf reject mismatch');

// Buy preference TTL
$prefSession = array();
MtUniCreditProductBuyPreference::save($prefSession, array(
    'store_id' => 0,
    'product_id' => 42,
    'scheme_type' => 'standard',
    'kop_code' => 'STD',
    'months' => 12,
    'filter_id' => 0,
));
$loaded = MtUniCreditProductBuyPreference::load($prefSession, 0);
mtuc8_assert(is_array($loaded) && $loaded['payment_code'] === MtUniCreditConstants::EXTENSION_CODE, 'buy preference save/load');
$prefSession[MtUniCreditProductBuyPreference::SESSION_KEY]['created_at'] = time() - 2000;
mtuc8_assert(MtUniCreditProductBuyPreference::load($prefSession, 0) === null, 'buy preference TTL expiry clears');

// Asset URL missing file
$missing = MtUniCreditStorefrontAssetUrls::versionedUrl(
    $lib . DIRECTORY_SEPARATOR . 'does-not-exist-' . uniqid() . '.css',
    'catalog/view/theme/default/template/extension/mt_uni_credit/missing.css'
);
mtuc8_assert($missing === '', 'asset url missing file returns empty');

// storeUrl must not require HTTP_SERVER / HTTPS_SERVER to be defined
$stubConfig = new class {
    private $values;
    public function __construct()
    {
        $this->values = array(
            'config_store_id' => '1',
            'config_url' => 'https://shop.example/',
            'config_ssl' => 'https://shop.example/',
        );
    }
    public function get($key)
    {
        return isset($this->values[$key]) ? $this->values[$key] : null;
    }
};
$stubController = new stdClass();
$stubController->config = $stubConfig;
$stubController->request = new stdClass();
$stubController->request->server = array();
mtuc8_assert(
    MtUniCreditStorefrontRuntime::storeUrl($stubController) === 'https://shop.example/',
    'storeUrl uses config_url for non-default store without HTTP_SERVER'
);
$stubConfigDefault = new class {
    public function get($key)
    {
        if ($key === 'config_store_id') {
            return '0';
        }
        if ($key === 'config_url') {
            return 'http://fallback.example/';
        }
        if ($key === 'config_ssl') {
            return 'https://fallback.example/';
        }
        return null;
    }
};
$stubDefault = new stdClass();
$stubDefault->config = $stubConfigDefault;
$stubDefault->request = new stdClass();
$stubDefault->request->server = array();
mtuc8_assert(
    MtUniCreditStorefrontRuntime::storeUrl($stubDefault) === 'http://fallback.example/',
    'storeUrl falls back to config_url when HTTP_SERVER undefined'
);

$cartSrc = (string) file_get_contents($catalog . DIRECTORY_SEPARATOR . 'controller/extension/mt_uni_credit/cart.php');
$productSrc = (string) file_get_contents($catalog . DIRECTORY_SEPARATOR . 'controller/extension/mt_uni_credit/product.php');
mtuc8_assert(strpos($cartSrc, 'HTTP_SERVER') === false, 'cart controller has no bare HTTP_SERVER');
mtuc8_assert(strpos($productSrc, 'HTTP_SERVER') === false, 'product controller has no bare HTTP_SERVER');
mtuc8_assert(strpos($cartSrc, 'storeUrl($this)') !== false, 'cart uses storeUrl helper');
mtuc8_assert(strpos($productSrc, 'storeUrl($this)') !== false, 'product uses storeUrl helper');

$jsPath = $catalog . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'theme' . DIRECTORY_SEPARATOR
    . 'default' . DIRECTORY_SEPARATOR . 'template' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR
    . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'storefront.js';
$js = (string) file_get_contents($jsPath);
mtuc8_assert(strpos($js, 'waitForJQuery') !== false, 'JS waitForJQuery present');
mtuc8_assert(strpos($js, 'mtuc-bound') !== false, 'JS mtuc-bound present');
mtuc8_assert(strpos($js, '.mtuc') !== false, 'JS namespaced .mtuc events');
mtuc8_assert(strpos($js, '50') !== false && strpos($js, '200') !== false, 'JS wait 50×200');
mtuc8_assert(strpos($js, 'mtucDocBound') !== false, 'JS document handlers bound once');
mtuc8_assert(strpos($js, 'ajaxComplete') !== false, 'JS reinits after AJAX fragment rebuild');

$cssPath = $catalog . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'theme' . DIRECTORY_SEPARATOR
    . 'default' . DIRECTORY_SEPARATOR . 'template' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR
    . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'storefront.css';
$css = (string) file_get_contents($cssPath);
mtuc8_assert(!preg_match('/(^|\\n)\\s*\\.btn\\s*\\{/', $css), 'CSS does not style bare .btn {');

// OCMOD anchors
$installXml = (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'install.xml');
mtuc8_assert(strpos($installXml, 'mt_uni_credit:product') !== false, 'OCMOD product marker');
mtuc8_assert(strpos($installXml, 'mt_uni_credit:cart') !== false, 'OCMOD cart marker');
mtuc8_assert(strpos($installXml, '$data[\'products\'] = array();') !== false, 'OCMOD product controller anchor');
mtuc8_assert(strpos($installXml, '{{ content_bottom }}</div>') !== false, 'OCMOD cart template anchor');
mtuc8_assert(strpos($installXml, 'error="skip"') !== false, 'OCMOD theme error=skip');
mtuc8_assert(!preg_match('/<search[^>]*>[^<]*\\.\\*/', $installXml), 'OCMOD no broad .* regex search');

// Phase 7 regression: prepared without submitCheckoutFinancing
$paymentController = (string) file_get_contents(
    $catalog . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'extension'
        . DIRECTORY_SEPARATOR . 'payment' . DIRECTORY_SEPARATOR . 'mt_uni_credit.php'
);
if (preg_match('/function\\s+prepared\\s*\\([^)]*\\)\\s*\\{/', $paymentController, $m, PREG_OFFSET_CAPTURE)) {
    $start = (int) $m[0][1] + strlen($m[0][0]);
    $depth = 1;
    $len = strlen($paymentController);
    $body = '';
    for ($i = $start; $i < $len; $i++) {
        $ch = $paymentController[$i];
        if ($ch === '{') {
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                $body = substr($paymentController, $start, $i - $start);
                break;
            }
        }
    }
    mtuc8_assert(strpos($body, 'submitCheckoutFinancing') === false, 'prepared() still without submitCheckoutFinancing');
} else {
    mtuc8_assert(false, 'prepared() method extractable');
}

// Storefront controllers must not mention SmartUCF
$productCtrl = (string) file_get_contents(
    $catalog . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'extension'
        . DIRECTORY_SEPARATOR . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'product.php'
);
$cartCtrl = (string) file_get_contents(
    $catalog . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'extension'
        . DIRECTORY_SEPARATOR . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'cart.php'
);
mtuc8_assert(stripos($productCtrl, 'SmartUCF') === false, 'product controller no SmartUCF');
mtuc8_assert(stripos($cartCtrl, 'SmartUCF') === false, 'cart controller no SmartUCF');

// Idempotent double materialize
$transport = new Phase4FakeCpHttpTransport();
$payloads = Phase7TestHarness::loginAndOrderSuccessPayloads();
$transport->enqueueJson(200, $payloads['login']);
$transport->enqueueJson(201, $payloads['order']);
$stack = Phase7TestHarness::stack($transport);
$addCount = 0;
$credentials = MtUniCreditBootstrap::credentialsRepositoryFromDb($stack['db']);
$service = new MtUniCreditStorefrontFinancingSubmissionService(
    $stack['attempts'],
    $stack['locks'],
    $stack['lifecycle'],
    $credentials,
    new MtUniCreditShopConfigurationCache(
        new MtUniCreditShopCacheRepository($stack['db'], new MtUniCreditPersistenceClock(function () {
            return Phase7TestHarness::NOW;
        })),
        null,
        MtUniCreditBootstrap::shopCachePersistenceFromDb($stack['db'])
    )
);
$line = new MtUniCreditProductLine(42, 'Example', 'EX', array(7), 1, 500.0, 500.0, 500.0, 0, array(), 0);
$schemeKey = MtUniCreditStorefrontCalculatorPresenter::schemeKey('standard', 'KOPSTD', 12, 0);
$sessionBind = array();
$inputBase = array(
    'entry_point' => MtUniCreditOperationEntryPoint::PRODUCT,
    'store_id' => (int) $stack['storeId'],
    'currency_code' => 'BGN',
    'scheme_key' => $schemeKey,
    'product_line' => $line,
    'customer' => array(
        'firstname' => 'A',
        'lastname' => 'B',
        'email' => 'a@b.test',
        'telephone' => '0888',
        'address_1' => 'x',
        'city' => 'Sofia',
        'postcode' => '1000',
        'country_id' => 33,
        'zone_id' => 1,
    ),
    'invoice_prefix' => 'INV',
    'store_name' => 'Store',
    'store_url' => 'https://example.test/',
    'language_id' => 1,
    'currency_id' => 1,
    'currency_value' => 1.0,
    'add_order' => function ($orderData) use (&$addCount) {
        $addCount++;
        return 91001;
    },
    'load_order' => function ($orderId) use ($stack) {
        return Phase7TestHarness::orderRow((int) $orderId, (int) $stack['storeId'], 500.0);
    },
);

$input1 = $inputBase;
$input1['session'] = $sessionBind;
$result1 = $service->submit($input1);
mtuc8_assert(!empty($result1['order_id']), 'storefront submit first pass creates/binds order');
if (isset($result1['session']) && is_array($result1['session'])) {
    $sessionBind = $result1['session'];
}
$input2 = $inputBase;
$input2['session'] = $sessionBind;
$transport->enqueueJson(200, $payloads['login']);
$transport->enqueueJson(201, $payloads['order']);
$result2 = $service->submit($input2);
mtuc8_assert($addCount === 1, 'storefront submission idempotent addOrder counted once');

echo PHP_EOL . 'Phase 8 passes: ' . $passes . PHP_EOL;
if ($failures !== array()) {
    echo 'Phase 8 failures: ' . count($failures) . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'Phase 8 OK' . PHP_EOL;
exit(0);
