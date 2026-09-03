<?php

/**
 * Included from mtuc8_run() — inherits that function scope (continues body_1).
 *
 * @var string $root
 * @var string $lib
 * @var string $catalog
 * @var MtUniCreditStorefrontCalculatorPresenter $presenter
 * @var MtUniCreditProductContext $product
 * @var array<string, mixed> $eligible
 */

// EUR display uses word "евро", never the € symbol (buttons + popup source labels)
$eurShop = mtuc3_golden_shop(array('uni_eur' => 3, 'uni_vnoska' => 1));
$eurPresented = $presenter->presentProduct($eurShop, $product, 'EUR');
mtuc8_assert(is_array($eurPresented) && isset($eurPresented['offers']['standard']['installment_label']), 'EUR presenter label present');
mtuc8_assert(
    strpos($eurPresented['offers']['standard']['installment_label'], 'евро') !== false,
    'EUR installment_label uses евро'
);
mtuc8_assert(
    strpos($eurPresented['offers']['standard']['installment_label'], '€') === false,
    'EUR installment_label has no € symbol'
);
$bgnLabel = $eligible['offers']['standard']['installment_label'];
mtuc8_assert(strpos($bgnLabel, 'лв.') !== false, 'BGN installment_label uses лв.');
$jsPathForCurrency = $catalog . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'theme' . DIRECTORY_SEPARATOR
    . 'default' . DIRECTORY_SEPARATOR . 'template' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR
    . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'storefront.js';
$jsCurrency = (string) file_get_contents($jsPathForCurrency);
mtuc8_assert(strpos($jsCurrency, 'formatMoneyWithCurrency') !== false, 'JS formats popup amounts with currency label');
mtuc8_assert(strpos($jsCurrency, '"евро"') !== false, 'JS EUR label is евро');
mtuc8_assert(strpos($jsCurrency, '€') === false, 'JS has no € symbol');

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
    /** @var array<string, string> */
    private $values = array();
    public function __construct()
    {
        $this->values = array(
            'config_store_id' => '1',
            'config_url' => 'https://shop.example/',
            'config_ssl' => 'https://shop.example/',
        );
    }
    /**
     * @param string $key
     * @return string|null
     */
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
    /**
     * @param string $key
     * @return string|null
     */
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
