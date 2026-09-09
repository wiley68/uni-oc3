<?php

/**
 * AUD-018 F01/F02 — Product Buy navigation lifecycle.
 *
 * Run: php tests/phase_aud018_product_buy_navigation_check.php
 *
 * Anti-false-positive:
 * - must fail if mt_uni_nav transport is removed
 * - must fail if normal Checkout activates session-wide pending/active without token
 * - must fail if category/search/information no longer clear active preference
 * - must fail if Checkout lifecycle incorrectly clears active
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
    mtuc_test_define_dir_storage('mtuc-aud018');
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

$failures = array();
$passes = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucAud018_assert($condition, $message)
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
function mtucAud018_read($path)
{
    $body = @file_get_contents($path);

    return is_string($body) ? $body : '';
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function mtucAud018_saveFields(array $extra = array())
{
    return array_merge(array(
        'store_id' => 0,
        'product_id' => 42,
        'scheme_type' => 'standard',
        'kop_code' => 'STD',
        'months' => 12,
        'filter_id' => 1,
        'scheme_key' => 'standard|STD|12',
    ), $extra);
}

/**
 * @return array<string, mixed>
 */
function mtucAud018_presenter()
{
    return array(
        'offers' => array(
            'standard' => array(
                'preferred_scheme_key' => 'standard|STD|24',
                'schemes' => array(
                    array(
                        'key' => 'standard|STD|12',
                        'label' => '12',
                        'months' => 12,
                        'scheme_type' => 'standard',
                        'kop_code' => 'STD',
                        'filter_id' => 1,
                        'presentation_category' => MtUniCreditSchemePresentationCategory::STANDARD,
                    ),
                    array(
                        'key' => 'standard|STD|24',
                        'label' => '24',
                        'months' => 24,
                        'scheme_type' => 'standard',
                        'kop_code' => 'STD',
                        'filter_id' => 1,
                        'presentation_category' => MtUniCreditSchemePresentationCategory::STANDARD,
                    ),
                ),
            ),
            'promo' => array('preferred_scheme_key' => '', 'schemes' => array()),
        ),
    );
}

$prefSrc = mtucAud018_read($lib . '/product_buy_preference.php');
$routeSrc = mtucAud018_read($lib . '/storefront_route_resolver.php');
$registrySrc = mtucAud018_read($lib . '/catalog_event_registry.php');
$installXml = mtucAud018_read($root . '/install.xml');
$productSrc = mtucAud018_read(
    $root . '/upload/catalog/controller/extension/mt_uni_credit/product.php'
);

mtucAud018_assert(
    strpos($prefSrc, 'AUD-018') !== false
        && strpos($prefSrc, 'NAV_PARAM') !== false
        && strpos($prefSrc, "mt_uni_nav") !== false,
    'F01 static: NAV_PARAM mt_uni_nav present'
);
mtucAud018_assert(
    strpos($prefSrc, 'issueNavigationCookie') !== false
        && strpos($prefSrc, 'clearNavigationCookie') !== false
        && strpos($prefSrc, 'navigationIdFromCookie') !== false,
    'F01 static: mt_uni_nav cookie transport helpers'
);
mtucAud018_assert(
    strpos($productSrc, 'appendNavigationToCheckoutUrl') !== false
        && strpos($productSrc, 'navigation_id') !== false,
    'F01 static: stash returns navigation-bearing redirect'
);
mtucAud018_assert(
    strpos($installXml, 'mt_uni_credit:buy_nav') !== false
        && strpos($installXml, 'sessionStorage') !== false
        && strpos($installXml, 'writeNavCookie') !== false
        && strpos($installXml, 'payment[_-]method') !== false
        && strpos($installXml, 'XMLHttpRequest') !== false
        && strpos($installXml, 'if (!nav || !window.jQuery)') === false,
    'F01 static: buy_nav cookie + XHR + Journal SEO URL match'
);
mtucAud018_assert(
    strpos($routeSrc, 'isUnrelatedStorefrontRoute') !== false
        && strpos($routeSrc, 'isCheckoutLifecycleRoute') !== false
        && strpos($routeSrc, 'isLayoutFragmentRoute') !== false,
    'F02 static: route classifier helpers'
);
mtucAud018_assert(
    strpos($registrySrc, 'mt_uni_credit_buy_guard_storefront') !== false
        && strpos($registrySrc, 'controller/*/before') !== false
        && strpos($registrySrc, 'mt_uni_credit_buy_payment_view') !== false
        && strpos($registrySrc, 'onPaymentMethodView') !== false,
    'F02 static: wildcard buy_guard + payment_method view events'
);

// ---------------------------------------------------------------------------
// F01 — pending activation / isolation
// ---------------------------------------------------------------------------
$session = array();
$navN = MtUniCreditProductBuyPreference::save($session, mtucAud018_saveFields());
mtucAud018_assert(
    preg_match('/^[a-f0-9]{32,}$/i', $navN) === 1,
    'F01 save returns random navigation_id'
);
mtucAud018_assert(
    $session[MtUniCreditProductBuyPreference::SESSION_KEY]['state']
        === MtUniCreditProductBuyPreference::STATE_PENDING,
    'F01 save state pending'
);

$loadedWrong = MtUniCreditProductBuyPreference::load($session, 0, '');
mtucAud018_assert($loadedWrong === null, 'F01 pending without token → no activate');
mtucAud018_assert(
    $session[MtUniCreditProductBuyPreference::SESSION_KEY]['state']
        === MtUniCreditProductBuyPreference::STATE_PENDING,
    'F01 pending preserved when token missing (until competing checkout entry)'
);

$loadedM = MtUniCreditProductBuyPreference::load($session, 0, 'deadbeefdeadbeefdeadbeefdeadbeef');
mtucAud018_assert($loadedM === null, 'F01 pending with different token M → no activate');

$loadedOk = MtUniCreditProductBuyPreference::load($session, 0, $navN);
mtucAud018_assert(
    is_array($loadedOk) && $loadedOk['state'] === MtUniCreditProductBuyPreference::STATE_ACTIVE,
    'F01 pending + matching N → active'
);
mtucAud018_assert(
    isset($session[MtUniCreditProductBuyPreference::CHECKOUT_GUARD_KEY])
        && hash_equals($session[MtUniCreditProductBuyPreference::CHECKOUT_GUARD_KEY], $navN),
    'F01 activate sets guard=N'
);

$selOk = MtUniCreditCheckoutSchemeSelection::resolveInitialSchemeSelection(
    mtucAud018_presenter(),
    $session,
    0,
    $navN
);
mtucAud018_assert($selOk['source'] === 'product_buy' && $selOk['key'] === 'standard|STD|12', 'F01 Checkout resolve with N');

// Same-Checkout AJAX often omits mt_uni_nav (Journal); bound guard keeps Buy active.
$selAjaxNoToken = MtUniCreditCheckoutSchemeSelection::resolveInitialSchemeSelection(
    mtucAud018_presenter(),
    $session,
    0,
    ''
);
mtucAud018_assert(
    $selAjaxNoToken['source'] === 'product_buy' && $selAjaxNoToken['key'] === 'standard|STD|12',
    'F01 same-Checkout AJAX without N keeps Buy via guard'
);
mtucAud018_assert(
    isset($session[MtUniCreditProductBuyPreference::SESSION_KEY]),
    'F01 AJAX without N does not clear active preference'
);

$selOther = MtUniCreditCheckoutSchemeSelection::resolveInitialSchemeSelection(
    mtucAud018_presenter(),
    $session,
    0,
    'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
);
mtucAud018_assert(
    $selOther['source'] === 'checkout_default',
    'F01 different token M → no Buy override'
);

// Same navigation refresh/AJAX with explicit token
$selRefresh = MtUniCreditCheckoutSchemeSelection::resolveInitialSchemeSelection(
    mtucAud018_presenter(),
    $session,
    0,
    $navN
);
mtucAud018_assert($selRefresh['source'] === 'product_buy', 'F01 same navigation refresh preserves');

// Competing checkout entry clears pending
$sessionPendingCompete = array();
$navP = MtUniCreditProductBuyPreference::save($sessionPendingCompete, mtucAud018_saveFields());
unset($navP);
MtUniCreditProductBuyPreference::onCheckoutEntryWithoutMatchingNav($sessionPendingCompete, '');
mtucAud018_assert(
    !isset($sessionPendingCompete[MtUniCreditProductBuyPreference::SESSION_KEY]),
    'F01 competing Checkout entry clears pending'
);

// Competing checkout entry also clears active (new visit without token)
$sessionActiveCompete = $session;
MtUniCreditProductBuyPreference::onCheckoutEntryWithoutMatchingNav($sessionActiveCompete, '');
mtucAud018_assert(
    !isset($sessionActiveCompete[MtUniCreditProductBuyPreference::SESSION_KEY]),
    'F01 competing Checkout entry clears active Buy'
);
$selAfterCompete = MtUniCreditCheckoutSchemeSelection::resolveInitialSchemeSelection(
    mtucAud018_presenter(),
    $sessionActiveCompete,
    0,
    ''
);
mtucAud018_assert(
    $selAfterCompete['source'] === 'checkout_default',
    'F01 after competing entry Buy no longer overrides'
);

// URL helper
$url = MtUniCreditProductBuyPreference::appendNavigationToCheckoutUrl(
    'index.php?route=checkout/checkout',
    $navN
);
mtucAud018_assert(
    strpos($url, 'mt_uni_nav=' . rawurlencode($navN)) !== false,
    'F01 appendNavigationToCheckoutUrl encodes token'
);

// ---------------------------------------------------------------------------
// F02 — unrelated routes clear active
// ---------------------------------------------------------------------------
$sessionActive = array();
$navA = MtUniCreditProductBuyPreference::save($sessionActive, mtucAud018_saveFields());
MtUniCreditProductBuyPreference::load($sessionActive, 0, $navA);
mtucAud018_assert(
    $sessionActive[MtUniCreditProductBuyPreference::SESSION_KEY]['state']
        === MtUniCreditProductBuyPreference::STATE_ACTIVE,
    'F02 fixture active'
);

$unrelated = array(
    'product/category',
    'product/search',
    'product/manufacturer/info',
    'information/information',
    'account/account',
    'common/home',
    'checkout/cart',
    'extension/foo/bar',
    'extension/module/example',
    'extension/account/example',
    'checkout/unrelated',
    'checkout/custom_page',
    'checkout/success',
    'checkout/failure',
);
foreach ($unrelated as $route) {
    mtucAud018_assert(
        MtUniCreditStorefrontRouteResolver::isUnrelatedStorefrontRoute($route)
            || MtUniCreditStorefrontRouteResolver::isCartPageRoute($route)
            || MtUniCreditStorefrontRouteResolver::isHomepageRoute($route)
            || $route === 'checkout/success'
            || strpos($route, 'checkout/success/') === 0,
        'F02 classifier marks unrelated/clear target: ' . $route
    );
    mtucAud018_assert(
        !MtUniCreditStorefrontRouteResolver::isCheckoutLifecycleRoute($route),
        'F02 unrelated is not checkout lifecycle: ' . $route
    );
}

// Residual F02 regression: blanket checkout/* or extension/* must NOT preserve.
foreach (array('extension/foo/bar', 'extension/module/example', 'checkout/unrelated') as $route) {
    mtucAud018_assert(
        MtUniCreditStorefrontRouteResolver::isUnrelatedStorefrontRoute($route),
        'F02 residual: unrelated must CLEAR class (' . $route . ')'
    );
    mtucAud018_assert(
        !MtUniCreditStorefrontRouteResolver::isCheckoutLifecycleRoute($route),
        'F02 residual: must not lifecycle-preserve (' . $route . ')'
    );
}
mtucAud018_assert(
    strpos($routeSrc, 'checkoutLifecycleBases') !== false
        && strpos($routeSrc, 'unicreditPaymentLifecycleBases') !== false,
    'F02 residual static: explicit Checkout + UniCredit payment allowlists'
);
mtucAud018_assert(
    strpos($routeSrc, "if (strpos(\$route, 'checkout/') === 0) {\n            return true;") === false
        && strpos($routeSrc, "if (strpos(\$route, 'extension/') === 0) {\n            return false;") === false,
    'F02 residual static: removed over-broad lifecycle preserve branches'
);

foreach (array('product/category', 'product/search', 'information/information', 'account/login') as $route) {
    $tmp = $sessionActive;
    MtUniCreditProductBuyPreference::clearOnUnrelatedStorefront($tmp);
    mtucAud018_assert(
        !isset($tmp[MtUniCreditProductBuyPreference::SESSION_KEY]),
        'F02 clearOnUnrelatedStorefront clears active (' . $route . ')'
    );
}

// Nested layout fragments (Loader chrome) must NOT clear Buy preference.
$layoutFragments = array(
    'common/header',
    'common/footer',
    'common/column_left',
    'common/column_right',
    'common/content_top',
    'common/content_bottom',
    'common/cart',
);
foreach ($layoutFragments as $route) {
    mtucAud018_assert(
        MtUniCreditStorefrontRouteResolver::isLayoutFragmentRoute($route),
        'F02 layout fragment class: ' . $route
    );
    mtucAud018_assert(
        !MtUniCreditStorefrontRouteResolver::isUnrelatedStorefrontRoute($route),
        'F02 layout fragment not unrelated: ' . $route
    );
}
mtucAud018_assert(
    !MtUniCreditStorefrontRouteResolver::isLayoutFragmentRoute('common/home'),
    'F02 common/home is homepage, not layout fragment'
);
mtucAud018_assert(
    MtUniCreditStorefrontRouteResolver::isHomepageRoute('common/home'),
    'F02 common/home remains homepage clear target'
);

// Realistic Checkout render: activate then nested common/* must preserve.
$sessionNested = array();
$navNested = MtUniCreditProductBuyPreference::save($sessionNested, mtucAud018_saveFields());
MtUniCreditProductBuyPreference::onCheckoutEntry($sessionNested, 0, $navNested);
mtucAud018_assert(
    isset($sessionNested[MtUniCreditProductBuyPreference::SESSION_KEY])
        && (string) $sessionNested[MtUniCreditProductBuyPreference::SESSION_KEY]['state']
        === MtUniCreditProductBuyPreference::STATE_ACTIVE,
    'F02 nested fixture activated'
);
foreach ($layoutFragments as $route) {
    $tmpNested = $sessionNested;
    // Mimic controller policy: layout fragments do not clear.
    if (MtUniCreditStorefrontRouteResolver::isUnrelatedStorefrontRoute($route)) {
        MtUniCreditProductBuyPreference::clearOnUnrelatedStorefront($tmpNested);
    }
    mtucAud018_assert(
        isset($tmpNested[MtUniCreditProductBuyPreference::SESSION_KEY]),
        'F02 nested common/* preserve after Checkout activation: ' . $route
    );
}

// Preserve Buy path + explicit Checkout lifecycle allowlist
$preserveLifecycle = array(
    'checkout/checkout',
    'checkout/checkout/country',
    'checkout/login',
    'checkout/login/save',
    'checkout/register/save',
    'checkout/guest/save',
    'checkout/guest_shipping/save',
    'checkout/payment_method',
    'checkout/payment_method/save',
    'checkout/shipping_method',
    'checkout/shipping_method/save',
    'checkout/payment_address',
    'checkout/payment_address/save',
    'checkout/shipping_address',
    'checkout/shipping_address/save',
    'checkout/confirm',
    'extension/payment/mt_uni_credit',
    'extension/payment/mt_uni_credit/confirm',
    'extension/payment/mt_uni_credit/calculate',
);
foreach ($preserveLifecycle as $route) {
    mtucAud018_assert(
        MtUniCreditStorefrontRouteResolver::isCheckoutLifecycleRoute($route),
        'F02 lifecycle PRESERVE: ' . $route
    );
    mtucAud018_assert(
        !MtUniCreditStorefrontRouteResolver::isUnrelatedStorefrontRoute($route),
        'F02 lifecycle not unrelated: ' . $route
    );
}

mtucAud018_assert(
    MtUniCreditStorefrontRouteResolver::isCartAddRoute('checkout/cart/add'),
    'F02 cart/add is handoff route'
);
mtucAud018_assert(
    MtUniCreditStorefrontRouteResolver::isCheckoutLifecycleRoute('checkout/cart/add'),
    'F02 cart/add is checkout lifecycle'
);
mtucAud018_assert(
    !MtUniCreditStorefrontRouteResolver::isUnrelatedStorefrontRoute('checkout/cart/add'),
    'F02 cart/add not unrelated'
);
mtucAud018_assert(
    MtUniCreditStorefrontRouteResolver::isProductBuyRoute('extension/mt_uni_credit/product_buy'),
    'F02 Product Buy stash route preserved class'
);
mtucAud018_assert(
    !MtUniCreditStorefrontRouteResolver::isUnrelatedStorefrontRoute('extension/mt_uni_credit/product_buy'),
    'F02 Product Buy stash not unrelated'
);

// Residual F02: Product Buy exact-or-slash boundary (no sibling prefixes).
$productBuyPositives = array(
    'extension/mt_uni_credit/product',
    'extension/mt_uni_credit/product/widget',
    'extension/mt_uni_credit/product_buy',
    'extension/mt_uni_credit/product_buy/applyPaymentPreselect',
    'extension/mt_uni_credit/product_buy/onStorefrontNavigation',
);
foreach ($productBuyPositives as $route) {
    mtucAud018_assert(
        MtUniCreditStorefrontRouteResolver::isProductBuyRoute($route),
        'F02 Product Buy boundary PRESERVE: ' . $route
    );
    mtucAud018_assert(
        !MtUniCreditStorefrontRouteResolver::isUnrelatedStorefrontRoute($route),
        'F02 Product Buy boundary not unrelated: ' . $route
    );
}

$productBuySiblings = array(
    'extension/mt_uni_credit/product_evil',
    'extension/mt_uni_credit/product123',
    'extension/mt_uni_credit/product_buy_evil',
    'extension/mt_uni_credit/product_buy2',
);
foreach ($productBuySiblings as $route) {
    mtucAud018_assert(
        !MtUniCreditStorefrontRouteResolver::isProductBuyRoute($route),
        'F02 Product Buy sibling not Product Buy: ' . $route
    );
    mtucAud018_assert(
        MtUniCreditStorefrontRouteResolver::isUnrelatedStorefrontRoute($route),
        'F02 Product Buy sibling CLEAR class: ' . $route
    );
}
mtucAud018_assert(
    strpos($routeSrc, "strpos(\$route, 'extension/mt_uni_credit/product') === 0") === false
        && strpos($routeSrc, "strpos(\$route, 'extension/mt_uni_credit/product_buy') === 0") === false,
    'F02 residual static: no bare Product Buy prefix match'
);

// Product page: active cleared, pending preserved
$sessionProd = array();
$navProd = MtUniCreditProductBuyPreference::save($sessionProd, mtucAud018_saveFields());
MtUniCreditProductBuyPreference::clearIfActivated($sessionProd);
mtucAud018_assert(
    isset($sessionProd[MtUniCreditProductBuyPreference::SESSION_KEY]),
    'F02 product page keeps pending'
);
MtUniCreditProductBuyPreference::load($sessionProd, 0, $navProd);
MtUniCreditProductBuyPreference::clearIfActivated($sessionProd);
mtucAud018_assert(
    !isset($sessionProd[MtUniCreditProductBuyPreference::SESSION_KEY]),
    'F02 product page clears active'
);

// cart/home full clear
$sessionCart = array();
$navCart = MtUniCreditProductBuyPreference::save($sessionCart, mtucAud018_saveFields());
MtUniCreditProductBuyPreference::load($sessionCart, 0, $navCart);
MtUniCreditProductBuyPreference::clear($sessionCart);
mtucAud018_assert(!isset($sessionCart[MtUniCreditProductBuyPreference::SESSION_KEY]), 'F02 cart/home clear');

// payment away
$sessionPay = array();
$navPay = MtUniCreditProductBuyPreference::save($sessionPay, mtucAud018_saveFields());
MtUniCreditProductBuyPreference::load($sessionPay, 0, $navPay);
$sessionPay['payment_method'] = array('code' => 'cod');
MtUniCreditProductBuyPreference::clearIfPaymentChangedAway($sessionPay);
mtucAud018_assert(!isset($sessionPay[MtUniCreditProductBuyPreference::SESSION_KEY]), 'F02 payment away clears');

// TTL
$sessionTtl = array();
$navTtl = MtUniCreditProductBuyPreference::save($sessionTtl, mtucAud018_saveFields());
$sessionTtl[MtUniCreditProductBuyPreference::SESSION_KEY]['created_at'] = time() - 2000;
mtucAud018_assert(
    MtUniCreditProductBuyPreference::load($sessionTtl, 0, $navTtl) === null,
    'F02 TTL clears'
);

// Legacy without navigation_id
$sessionLegacy = array();
$sessionLegacy[MtUniCreditProductBuyPreference::SESSION_KEY] = array(
    'flow' => MtUniCreditProductBuyPreference::FLOW,
    'store_id' => 0,
    'scheme_key' => 'standard|STD|12',
    'state' => MtUniCreditProductBuyPreference::STATE_PENDING,
    'created_at' => time(),
);
mtucAud018_assert(
    MtUniCreditProductBuyPreference::load($sessionLegacy, 0, 'anything') === null,
    'F02 legacy without navigation_id cleared'
);

// Store mismatch
$sessionStore = array();
$navStore = MtUniCreditProductBuyPreference::save($sessionStore, mtucAud018_saveFields(array('store_id' => 1)));
mtucAud018_assert(
    MtUniCreditProductBuyPreference::load($sessionStore, 2, $navStore) === null,
    'F02 store mismatch clears'
);

// Token alone cannot create preference
$sessionEmpty = array();
mtucAud018_assert(
    MtUniCreditProductBuyPreference::load($sessionEmpty, 0, 'ffffffffffffffffffffffffffffffff') === null,
    'F01 token without session preference → no effect'
);

// Minimal OC3 Controller/Registry stubs for product_buy controller smoke tests.
if (!class_exists('Registry', false)) {
    class Registry
    {
        /** @var array<string, mixed> */
        private $data = array();

        /**
         * @param string $key
         * @return mixed
         */
        public function get($key)
        {
            return isset($this->data[$key]) ? $this->data[$key] : null;
        }

        /**
         * @param string $key
         * @param mixed $value
         * @return void
         */
        public function set($key, $value)
        {
            $this->data[$key] = $value;
        }
    }
}
if (!class_exists('Controller', false)) {
    class Controller
    {
        /** @var object */
        public $session;
        /** @var object */
        public $request;
        /** @var object */
        public $config;

        /**
         * @param Registry $registry
         */
        public function __construct($registry)
        {
            $this->session = $registry->get('session');
            $this->request = $registry->get('request');
            $this->config = $registry->get('config');
        }
    }
}

require_once $root . '/upload/catalog/controller/extension/mt_uni_credit/product_buy.php';

$sessionCtrl = array();
$navCtrl = MtUniCreditProductBuyPreference::save($sessionCtrl, mtucAud018_saveFields());
MtUniCreditProductBuyPreference::load($sessionCtrl, 0, $navCtrl);
$registry = new Registry();
$sessionObj = new stdClass();
$sessionObj->data = &$sessionCtrl;
$registry->set('session', $sessionObj);
$req = new stdClass();
$req->get = array();
$req->post = array();
$registry->set('request', $req);
$registry->set('config', new class {
    /**
     * @param string $k
     * @return mixed
     */
    public function get($k)
    {
        return $k === 'config_store_id' ? 0 : null;
    }
});
$ctrl = new ControllerExtensionMtUniCreditProductBuy($registry);
$route = 'product/category';
$data = array();
$ctrl->onStorefrontNavigation($route, $data);
mtucAud018_assert(
    !isset($sessionCtrl[MtUniCreditProductBuyPreference::SESSION_KEY]),
    'F02 controller clears active on category'
);

foreach (array('extension/foo/bar', 'extension/module/example', 'checkout/unrelated') as $clearRoute) {
    $sessionCtrl = array();
    $navCtrl = MtUniCreditProductBuyPreference::save($sessionCtrl, mtucAud018_saveFields());
    MtUniCreditProductBuyPreference::load($sessionCtrl, 0, $navCtrl);
    $sessionObj->data = &$sessionCtrl;
    $routeClear = $clearRoute;
    $dataClear = array();
    $ctrl->onStorefrontNavigation($routeClear, $dataClear);
    mtucAud018_assert(
        !isset($sessionCtrl[MtUniCreditProductBuyPreference::SESSION_KEY]),
        'F02 controller clears active on ' . $clearRoute
    );
}

foreach (
    array(
        'checkout/checkout',
        'checkout/shipping_method',
        'checkout/payment_address',
        'checkout/shipping_address',
        'checkout/confirm',
        'extension/payment/mt_uni_credit',
    ) as $keepRoute
) {
    $sessionKeep = array();
    $navKeep = MtUniCreditProductBuyPreference::save($sessionKeep, mtucAud018_saveFields());
    MtUniCreditProductBuyPreference::load($sessionKeep, 0, $navKeep);
    $sessionObjKeep = new stdClass();
    $sessionObjKeep->data = &$sessionKeep;
    $registryKeep = new Registry();
    $registryKeep->set('session', $sessionObjKeep);
    $reqKeep = new stdClass();
    $reqKeep->get = array(MtUniCreditProductBuyPreference::NAV_PARAM => $navKeep);
    $reqKeep->post = array();
    $registryKeep->set('request', $reqKeep);
    $registryKeep->set('config', new class {
        /**
         * @param string $k
         * @return mixed
         */
        public function get($k)
        {
            return $k === 'config_store_id' ? 0 : null;
        }
    });
    $ctrlKeep = new ControllerExtensionMtUniCreditProductBuy($registryKeep);
    $routeKeep = $keepRoute;
    $dataKeep = array();
    $ctrlKeep->onStorefrontNavigation($routeKeep, $dataKeep);
    mtucAud018_assert(
        isset($sessionKeep[MtUniCreditProductBuyPreference::SESSION_KEY]),
        'F02 controller PRESERVE active on ' . $keepRoute
    );
}

// Checkout AJAX must not clear
$sessionAjax = array();
$navAjax = MtUniCreditProductBuyPreference::save($sessionAjax, mtucAud018_saveFields());
MtUniCreditProductBuyPreference::load($sessionAjax, 0, $navAjax);
$sessionObj2 = new stdClass();
$sessionObj2->data = &$sessionAjax;
$registry2 = new Registry();
$registry2->set('session', $sessionObj2);
$req2 = new stdClass();
$req2->get = array(MtUniCreditProductBuyPreference::NAV_PARAM => $navAjax);
$req2->post = array();
$registry2->set('request', $req2);
$registry2->set('config', new class {
    /**
     * @param string $k
     * @return mixed
     */
    public function get($k)
    {
        return $k === 'config_store_id' ? 0 : null;
    }
});
$ctrl2 = new ControllerExtensionMtUniCreditProductBuy($registry2);
$routeAjax = 'checkout/payment_method';
$dataAjax = array();
$ctrl2->onStorefrontNavigation($routeAjax, $dataAjax);
mtucAud018_assert(
    isset($sessionAjax[MtUniCreditProductBuyPreference::SESSION_KEY]),
    'F02 Checkout AJAX does not clear active'
);

// Pending + cart/add preserved via classifier (controller early-return)
$sessionHandoff = array();
MtUniCreditProductBuyPreference::save($sessionHandoff, mtucAud018_saveFields());
$sessionObj3 = new stdClass();
$sessionObj3->data = &$sessionHandoff;
$registry3 = new Registry();
$registry3->set('session', $sessionObj3);
$registry3->set('request', $req2);
$registry3->set('config', new class {
    /**
     * @param string $k
     * @return mixed
     */
    public function get($k)
    {
        return 0;
    }
});
$ctrl3 = new ControllerExtensionMtUniCreditProductBuy($registry3);
$routeAdd = 'checkout/cart/add';
$dataAdd = array();
$ctrl3->onStorefrontNavigation($routeAdd, $dataAdd);
mtucAud018_assert(
    isset($sessionHandoff[MtUniCreditProductBuyPreference::SESSION_KEY])
        && $sessionHandoff[MtUniCreditProductBuyPreference::SESSION_KEY]['state']
        === MtUniCreditProductBuyPreference::STATE_PENDING,
    'F02 pending preserved across cart/add'
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
