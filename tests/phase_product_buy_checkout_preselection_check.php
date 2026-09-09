<?php

/**
 * Manual-release defect #2 — Product Buy → Checkout preselection.
 *
 * Proves the real production boundaries:
 * - stashBuyPreference persists exact scheme_key + navigation_id
 * - Checkout payment_method preselect applies UniCredit only with matching mt_uni_nav
 * - exact Buy scheme beats normal Checkout default ranking
 * - normal Checkout without Buy does not force UniCredit
 * - same-nav refresh retains both; stale/unrelated paths do not
 *
 * Run: php tests/phase_product_buy_checkout_preselection_check.php
 */
require_once __DIR__ . '/bootstrap.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
}

require_once $lib . DIRECTORY_SEPARATOR . 'bootstrap.php';

$failures = array();
$passes = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucBuyPre_assert($condition, $message)
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
 * @param string $key
 * @param int $months
 * @param string $category
 * @return array<string, mixed>
 */
function mtucBuyPre_scheme($key, $months, $category)
{
    $parts = explode('|', $key);
    return array(
        'key' => $key,
        'type' => isset($parts[0]) ? $parts[0] : 'promo',
        'kop_code' => isset($parts[1]) ? $parts[1] : 'Z',
        'months' => $months,
        'filter_id' => 1,
        'label' => $months . ' months',
        'presentation_category' => $category,
        'zero_promo' => $category === MtUniCreditSchemePresentationCategory::ZERO_PROMO,
    );
}

/**
 * Presenter where normal default ranking prefers scheme A (24m 0%), not Buy scheme B (6m).
 *
 * @return array<string, mixed>
 */
function mtucBuyPre_presenter()
{
    return array(
        'offers' => array(
            'promo' => array(
                'preferred_scheme_key' => 'promo|Z|12',
                'schemes' => array(
                    mtucBuyPre_scheme('promo|Z|6', 6, MtUniCreditSchemePresentationCategory::ZERO_PROMO),
                    mtucBuyPre_scheme('promo|Z|12', 12, MtUniCreditSchemePresentationCategory::ZERO_PROMO),
                    mtucBuyPre_scheme('promo|Z|24', 24, MtUniCreditSchemePresentationCategory::ZERO_PROMO),
                ),
            ),
            'standard' => array(
                'preferred_scheme_key' => '',
                'schemes' => array(),
            ),
        ),
    );
}

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

$schemeA = 'promo|Z|24';
$schemeB = 'promo|Z|6';
$presenter = mtucBuyPre_presenter();

$defaultOnly = array();
$defaultSel = MtUniCreditCheckoutSchemeSelection::resolveInitialSchemeSelection($presenter, $defaultOnly, 0, '');
mtucBuyPre_assert(
    $defaultSel['source'] === 'checkout_default' && $defaultSel['key'] === $schemeA,
    'fixture: normal Checkout default ranking prefers scheme A (24m)'
);

// ---------------------------------------------------------------------------
// Transport contract (why the manual defect was invisible to prior tests)
// ---------------------------------------------------------------------------
$installXml = (string) file_get_contents($root . '/install.xml');
mtucBuyPre_assert(
    strpos($installXml, 'XMLHttpRequest.prototype.open') !== false
        || strpos($installXml, 'XMLHttpRequest.prototype') !== false,
    'transport: native XHR open hook present'
);
mtucBuyPre_assert(
    strpos($installXml, 'tries >= 200') !== false
        && strpos($installXml, 'setInterval') !== false,
    'transport: bounded jQuery wait 50ms x 200'
);
mtucBuyPre_assert(
    strpos($installXml, 'if (!nav || !window.jQuery)') === false,
    'transport: no fail-closed early return when jQuery missing'
);

// ---------------------------------------------------------------------------
// Real Product Buy stash path (exact scheme identity + navigation_id)
// ---------------------------------------------------------------------------
$session = array();
$navId = MtUniCreditProductBuyPreference::save($session, array(
    'store_id' => 0,
    'product_id' => 42,
    'scheme_key' => $schemeB,
    'scheme_type' => 'promo',
    'kop_code' => 'Z',
    'months' => 6,
    'filter_id' => 0,
));
$pref = $session[MtUniCreditProductBuyPreference::SESSION_KEY];
mtucBuyPre_assert(
    is_array($pref)
        && $pref['state'] === MtUniCreditProductBuyPreference::STATE_PENDING
        && (string) $pref['scheme_key'] === $schemeB
        && (string) $pref['navigation_id'] === $navId
        && !empty($pref['prefer_payment']),
    'buy: pending preference stores exact scheme B + navigation_id'
);

$checkoutUrl = MtUniCreditProductBuyPreference::appendNavigationToCheckoutUrl(
    'index.php?route=checkout/checkout',
    $navId
);
mtucBuyPre_assert(
    strpos($checkoutUrl, 'mt_uni_nav=' . rawurlencode($navId)) !== false,
    'buy: checkout redirect carries mt_uni_nav'
);

// Lost-nav gate (pre-fix symptom): without token neither payment nor scheme Buy wins.
$lost = $session;
$methods = array(
    'cod' => array('code' => 'cod', 'title' => 'Cash', 'sort_order' => 1),
    'mt_uni_credit' => array('code' => 'mt_uni_credit', 'title' => 'UniCredit', 'sort_order' => 2),
);
$lostPay = MtUniCreditProductBuyPreference::applyPaymentIfAvailable($lost, $methods, 0, '');
$lostSel = MtUniCreditCheckoutSchemeSelection::resolveInitialSchemeSelection($presenter, $lost, 0, '');
mtucBuyPre_assert(
    $lostPay === false
        && (!isset($lost['payment_method']) || (string) $lost['payment_method']['code'] !== 'mt_uni_credit')
        && $lostSel['source'] === 'checkout_default'
        && $lostSel['key'] === $schemeA,
    'gate: missing mt_uni_nav → no UniCredit force + default scheme A'
);

// ---------------------------------------------------------------------------
// Checkout activation with matching nav: payment + exact scheme B
// ---------------------------------------------------------------------------
$registry = new Registry();
$sessionObj = new stdClass();
$sessionObj->data = &$session;
$registry->set('session', $sessionObj);
$req = new stdClass();
$req->get = array('mt_uni_nav' => $navId);
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

$session['payment_methods'] = $methods;
$data = array();
$ctrl->applyPaymentPreselect($data);
mtucBuyPre_assert(
    isset($session['payment_method']['code'])
        && (string) $session['payment_method']['code'] === 'mt_uni_credit'
        && (string) $session[MtUniCreditProductBuyPreference::SESSION_KEY]['state']
        === MtUniCreditProductBuyPreference::STATE_ACTIVE,
    'checkout: UniCredit payment method preselected via applyPaymentPreselect'
);

$sel = MtUniCreditCheckoutSchemeSelection::resolveInitialSchemeSelection(
    $presenter,
    $session,
    0,
    $navId
);
mtucBuyPre_assert(
    $sel['source'] === 'product_buy'
        && $sel['buy_matched'] === true
        && $sel['key'] === $schemeB
        && $sel['key'] !== $schemeA,
    'checkout: exact Buy scheme B restored over default scheme A'
);

$options = MtUniCreditCheckoutSchemeSelection::buildCheckoutSchemeOptions($presenter, $sel['key']);
$selectedLabels = array();
foreach ($options as $opt) {
    if (!empty($opt['selected'])) {
        $selectedLabels[] = $opt['key'];
    }
}
mtucBuyPre_assert(
    $selectedLabels === array($schemeB),
    'checkout: presenter options mark exact scheme B selected'
);

// Same-navigation refresh / AJAX
$session['payment_method'] = $methods['cod'];
$ctrl->applyPaymentPreselect($data);
$selRefresh = MtUniCreditCheckoutSchemeSelection::resolveInitialSchemeSelection(
    $presenter,
    $session,
    0,
    $navId
);
mtucBuyPre_assert(
    isset($session['payment_method']['code'])
        && (string) $session['payment_method']['code'] === 'mt_uni_credit'
        && $selRefresh['key'] === $schemeB
        && $selRefresh['source'] === 'product_buy',
    'refresh: same navigation keeps UniCredit + scheme B'
);

// ---------------------------------------------------------------------------
// Stale Buy preference: scheme no longer eligible → normal default
// ---------------------------------------------------------------------------
$sessionStale = array();
$navStale = MtUniCreditProductBuyPreference::save($sessionStale, array(
    'store_id' => 0,
    'product_id' => 42,
    'scheme_key' => 'promo|Z|5',
    'scheme_type' => 'promo',
    'kop_code' => 'Z',
    'months' => 5,
    'filter_id' => 0,
));
$staleSel = MtUniCreditCheckoutSchemeSelection::resolveInitialSchemeSelection(
    $presenter,
    $sessionStale,
    0,
    $navStale
);
mtucBuyPre_assert(
    $staleSel['source'] === 'checkout_default'
        && $staleSel['buy_matched'] === false
        && $staleSel['key'] === $schemeA,
    'stale: invalid Buy scheme rejected; normal default applies'
);

// ---------------------------------------------------------------------------
// Normal Checkout without Product Buy must not force UniCredit
// ---------------------------------------------------------------------------
$sessionNormal = array();
$sessionNormal['payment_methods'] = $methods;
$sessionNormal['payment_method'] = $methods['cod'];
$req->get = array();
$req->post = array();
$sessionObj->data = &$sessionNormal;
$ctrlNormal = new ControllerExtensionMtUniCreditProductBuy($registry);
$dataNormal = array();
$ctrlNormal->applyPaymentPreselect($dataNormal);
mtucBuyPre_assert(
    isset($sessionNormal['payment_method']['code'])
        && (string) $sessionNormal['payment_method']['code'] === 'cod',
    'normal: Checkout without Buy does not force UniCredit'
);
$normalSel = MtUniCreditCheckoutSchemeSelection::resolveInitialSchemeSelection(
    $presenter,
    $sessionNormal,
    0,
    ''
);
mtucBuyPre_assert(
    $normalSel['source'] === 'checkout_default' && $normalSel['key'] === $schemeA,
    'normal: scheme uses checkout_default ranking'
);

// ---------------------------------------------------------------------------
// Unrelated later navigation: old Buy preference cleared / ignored
// ---------------------------------------------------------------------------
$sessionLater = array();
$navLater = MtUniCreditProductBuyPreference::save($sessionLater, array(
    'store_id' => 0,
    'product_id' => 42,
    'scheme_key' => $schemeB,
    'scheme_type' => 'promo',
    'kop_code' => 'Z',
    'months' => 6,
    'filter_id' => 0,
));
MtUniCreditProductBuyPreference::load($sessionLater, 0, $navLater);
MtUniCreditProductBuyPreference::clear($sessionLater);
$sessionLater['payment_methods'] = $methods;
$sessionLater['payment_method'] = $methods['cod'];
$sessionObj->data = &$sessionLater;
$req->get = array();
$ctrlLater = new ControllerExtensionMtUniCreditProductBuy($registry);
$dataLater = array();
$ctrlLater->applyPaymentPreselect($dataLater);
$laterSel = MtUniCreditCheckoutSchemeSelection::resolveInitialSchemeSelection(
    $presenter,
    $sessionLater,
    0,
    ''
);
mtucBuyPre_assert(
    (string) $sessionLater['payment_method']['code'] === 'cod'
        && $laterSel['key'] === $schemeA
        && $laterSel['source'] === 'checkout_default',
    'later: after leave Buy lifecycle, UniCredit/scheme B not restored'
);

// Competing checkout entry without nav clears pending
$sessionCompete = array();
MtUniCreditProductBuyPreference::save($sessionCompete, array(
    'store_id' => 0,
    'product_id' => 42,
    'scheme_key' => $schemeB,
    'scheme_type' => 'promo',
    'kop_code' => 'Z',
    'months' => 6,
    'filter_id' => 0,
));
$sessionObj->data = &$sessionCompete;
$req->get = array();
$route = 'checkout/checkout';
$dataCompete = array();
$ctrlCompete = new ControllerExtensionMtUniCreditProductBuy($registry);
$ctrlCompete->onStorefrontNavigation($route, $dataCompete);
mtucBuyPre_assert(
    !isset($sessionCompete[MtUniCreditProductBuyPreference::SESSION_KEY]),
    'lifecycle: competing Checkout entry without nav clears pending Buy'
);

// Buy itself must remain preference-only (no CP/SmartUCF in stash controller source)
$productSrc = (string) file_get_contents(
    $root . '/upload/catalog/controller/extension/mt_uni_credit/product.php'
);
$stashPos = strpos($productSrc, 'function stashBuyPreference');
$stashChunk = $stashPos === false ? '' : substr($productSrc, $stashPos, 1200);
mtucBuyPre_assert(
    $stashChunk !== ''
        && strpos($stashChunk, 'ProductBuyPreference::save') !== false
        && stripos($stashChunk, 'ControlPanel') === false
        && stripos($stashChunk, 'SmartUcf') === false
        && stripos($stashChunk, 'addOrder') === false,
    'buy: stashBuyPreference is preference/redirect only (no order/CP/SmartUCF)'
);

echo PHP_EOL . 'Product Buy → Checkout preselection: ' . $passes . ' passed';
if ($failures) {
    echo ', ' . count($failures) . ' failed' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo ', 0 failed' . PHP_EOL;
exit(0);
