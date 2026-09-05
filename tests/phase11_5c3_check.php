<?php

/**
 * Phase 11.5C.3 — Checkout financing selection block parity.
 * Run: php tests/phase11_5c3_check.php
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
    $storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mtuc-phase115c3-storage';
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
require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

$failures = array();
$passes = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtuc115c3_assert($condition, $message)
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
function mtuc115c3_read($path)
{
    $body = @file_get_contents($path);

    return is_string($body) ? $body : '';
}

$p1Exact = "Можете да изберете 'Срок за кредита', предпочитаната от Вас 'Месечна вноска', както и при желание 'Първоначална вноска'. След което да потвърдите избора си. Ще бъдете прехвърлени към страницата на UniCredit за довършване на покупката си на кредит.";

$controllerPath = $root . '/upload/catalog/controller/extension/payment/mt_uni_credit.php';
$modelPath = $root . '/upload/catalog/model/extension/payment/mt_uni_credit.php';
$twigPath = $root . '/upload/catalog/view/theme/default/template/extension/payment/mt_uni_credit.twig';
$jsPath = $root . '/upload/catalog/view/theme/default/template/extension/payment/mt_uni_credit_checkout.js';
$cssPath = $root . '/upload/catalog/view/theme/default/template/extension/payment/mt_uni_credit_checkout.css';
$bgPath = $root . '/upload/catalog/language/bg-bg/extension/payment/mt_uni_credit.php';
$servicePath = $lib . '/checkout_financing_submission_service.php';
$schemeSelPath = $lib . '/checkout_scheme_selection.php';

$controller = mtuc115c3_read($controllerPath);
$model = mtuc115c3_read($modelPath);
$twig = mtuc115c3_read($twigPath);
$js = mtuc115c3_read($jsPath);
$css = mtuc115c3_read($cssPath);
$bg = mtuc115c3_read($bgPath);
$service = mtuc115c3_read($servicePath);

mtuc115c3_assert(is_file($twigPath), 'checkout payment twig exists');
mtuc115c3_assert(is_file($jsPath), 'checkout JS asset exists');
mtuc115c3_assert(is_file($cssPath), 'checkout CSS asset exists');
mtuc115c3_assert(is_file($schemeSelPath), 'checkout scheme selection helper exists');

mtuc115c3_assert(strpos($bg, "'heading_title'] = 'УниКредит покупки на кредит'") !== false, 'block title uses OC4 casing');
mtuc115c3_assert(strpos($bg, "'button_confirm'] = 'Потвърди поръчката'") !== false, 'confirm label matches OC4');
mtuc115c3_assert(strpos($bg, 'text_checkout_helper_process1') !== false, 'P1 helper key present in language file');
mtuc115c3_assert(
    strpos($bg, "'text_checkout_helper_process1'") !== false
        && strpos($bg, "'text_checkout_helper_process2'") !== false,
    'P1 and P2 helper language keys exist'
);

// OC4 authority: P1 and P2 helpers are identical (uni-woo/PS parity).
$_ = array();
include $bgPath;
mtuc115c3_assert(
    isset($_['text_checkout_helper_process1'], $_['text_checkout_helper_process2'])
        && $_['text_checkout_helper_process1'] === $p1Exact
        && $_['text_checkout_helper_process2'] === $p1Exact,
    'P2 helper matches OC4 authority (identical to P1)'
);

mtuc115c3_assert(strpos($twig, 'mt-uni-credit-checkout-root') !== false, 'checkout root present');
mtuc115c3_assert(strpos($twig, 'data-mtuc-checkout-helper') !== false, 'process helper marker');
mtuc115c3_assert(strpos($twig, 'data-mtuc-schemes') !== false, 'scheme select present');
mtuc115c3_assert(strpos($twig, 'data-mtuc-first') !== false, 'first installment control');
mtuc115c3_assert(strpos($twig, 'data-mtuc-display="monthly_installment"') !== false, 'monthly installment display');
mtuc115c3_assert(strpos($twig, 'id="button-confirm"') !== false, 'confirm button present');
mtuc115c3_assert(strpos($twig, '{% if consents %}') !== false, 'consent block gated');
mtuc115c3_assert(strpos($twig, 'name="consent" value="1"') === false, 'no language-fallback consent when empty (OC4 checkout)');
mtuc115c3_assert(substr_count($twig, 'data-mtuc-consents') === 1, 'single consent container only when consents exist');
mtuc115c3_assert(strpos($twig, '{% if process2 %}') !== false, 'P2 egn/phone2 gated');
mtuc115c3_assert(strpos($twig, 'name="egn"') !== false && strpos($twig, 'name="phone2"') !== false, 'P2 fields present');

mtuc115c3_assert(strpos($controller, 'presentCheckoutFinancingPanel') !== false, 'index uses panel presenter');
mtuc115c3_assert(strpos($controller, 'function calculate') !== false, 'calculate endpoint');
mtuc115c3_assert(strpos($controller, 'function recalculate') !== false, 'recalculate endpoint');
mtuc115c3_assert(strpos($controller, 'prepareCheckoutConfirm') !== false, 'confirm still prepares native order reuse');
mtuc115c3_assert(strpos($controller, 'submitCheckoutFinancing') !== false, 'confirm submits financing');
mtuc115c3_assert(strpos($controller, 'error_consent') !== false, 'server consent rejection path');
mtuc115c3_assert(strpos($controller, 'isUniCreditPaymentSelected') !== false, 'payment method gate');
mtuc115c3_assert(strpos($model, 'presentCheckoutFinancingPanel') !== false, 'model panel render');
mtuc115c3_assert(strpos($model, 'recalculateCheckoutSelection') !== false, 'model recalculate');
mtuc115c3_assert(strpos($model, "'scheme_key'") !== false, 'submit passes scheme_key');
mtuc115c3_assert(strpos($service, "isset(\$input['scheme_key'])") !== false, 'submission uses selected scheme');

mtuc115c3_assert(strpos($js, 'recalculate_url') !== false, 'JS recalculates via server');
mtuc115c3_assert(strpos($js, 'confirm_url') !== false, 'JS confirms via server');
mtuc115c3_assert(strpos($js, 'consentsAccepted') !== false, 'JS consent gate');
mtuc115c3_assert(strpos($js, 'ajaxComplete') !== false, 'survives checkout AJAX refresh');
mtuc115c3_assert(strpos($css, 'checkout-panel__helper') !== false, 'helper visual style');

// Render has no side-effect calls in present path.
$modelPresent = '';
if (preg_match('/function presentCheckoutFinancingPanel\s*\([\s\S]*?\n    public function /s', $model, $m)) {
    $modelPresent = $m[0];
}
mtuc115c3_assert($modelPresent !== '', 'presentCheckoutFinancingPanel extractable');
mtuc115c3_assert(strpos($modelPresent, 'submit(') === false, 'present does not submit');
mtuc115c3_assert(strpos($modelPresent, 'addOrder') === false, 'present does not addOrder');
mtuc115c3_assert(stripos($modelPresent, 'SmartUCF') === false && strpos($modelPresent, 'smartucf') === false, 'present does not call SmartUCF');
mtuc115c3_assert(strpos($modelPresent, 'createOrder') === false, 'present does not createOrder');

// Index render: no CP/mail side effects.
$indexBody = '';
if (preg_match('/function index\s*\([\s\S]*?\n    public function /s', $controller, $m)) {
    $indexBody = $m[0];
}
mtuc115c3_assert($indexBody !== '', 'index() extractable');
mtuc115c3_assert(strpos($indexBody, 'submitCheckoutFinancing') === false, 'index does not submit financing');
mtuc115c3_assert(strpos($indexBody, 'prepareCheckoutConfirm') === false, 'index does not prepare order');
mtuc115c3_assert(strpos($indexBody, 'addOrder') === false, 'index does not addOrder');

// Consent: empty shop → no mandatory consent; configured → reject until accepted.
$resolver = new MtUniCreditStorefrontConsentResolver();
$shopNoConsent = mtuc4_valid_shop_snapshot();
unset($shopNoConsent['consents']);
$shopNoConsent['consents'] = array();
mtuc115c3_assert($resolver->normalize($shopNoConsent) === array(), 'no consent → empty normalize');
mtuc115c3_assert(
    strpos($twig, '{% if consents %}') !== false && strpos($twig, 'name="consent" value="1"') === false,
    'empty consent list: no empty wrapper / no fallback checkbox'
);

$shopWithConsent = mtuc4_valid_shop_snapshot();
$shopWithConsent['consents'] = array(
    array('id' => 9, 'name' => 'Тестово съгласие', 'url' => 'https://example.test/c', 'mandatory' => 1),
);
mtuc115c3_assert(!$resolver->isSatisfied($shopWithConsent, array()), 'required consent unchecked → reject');
mtuc115c3_assert($resolver->isSatisfied($shopWithConsent, array(9)), 'required consent checked → allow');

// Scheme selection helper preferred key.
$presenter = array(
    'offers' => array(
        'standard' => array(
            'preferred_scheme_key' => 'standard|KOP|12|1',
            'schemes' => array(
                array('key' => 'standard|KOP|12|1', 'months' => 12, 'description' => '', 'label' => '12 месеца'),
                array('key' => 'standard|KOP|24|1', 'months' => 24, 'description' => '', 'label' => '24 месеца'),
            ),
        ),
    ),
);
$session = array();
$resolved = MtUniCreditCheckoutSchemeSelection::resolveInitialSchemeSelection($presenter, $session, 0);
mtuc115c3_assert($resolved['key'] === 'standard|KOP|12|1', 'default preferred scheme selected');
$options = MtUniCreditCheckoutSchemeSelection::buildCheckoutSchemeOptions($presenter, $resolved['key']);
mtuc115c3_assert(count($options) === 2 && !empty($options[0]['selected']), 'scheme options mark preferred selected');

// Order reuse: confirm must not call addOrder; prepare + submit only.
$confirmBody = '';
if (preg_match('/function confirm\s*\([\s\S]*?\n    public function /s', $controller, $m)) {
    $confirmBody = $m[0];
}
mtuc115c3_assert($confirmBody !== '', 'confirm() extractable');
mtuc115c3_assert(strpos($confirmBody, 'addOrder(') === false, 'confirm does not call addOrder');
mtuc115c3_assert(strpos($confirmBody, 'addOrderHistory(') === false, 'confirm body avoids addOrderHistory string');
mtuc115c3_assert(strpos($confirmBody, 'prepareCheckoutConfirm') !== false, 'confirm reuses native order via prepare');
mtuc115c3_assert(strpos($confirmBody, 'submitCheckoutFinancing') !== false, 'confirm submits against prepared order');

// Payment method switch: endpoints refuse when other method selected.
mtuc115c3_assert(
    strpos($controller, 'isUniCreditPaymentSelected') !== false
        && substr_count($controller, 'isUniCreditPaymentSelected()') >= 3,
    'calculate/recalculate/confirm gate on UniCredit payment method'
);

echo PHP_EOL . 'Phase 11.5C.3 summary: ' . $passes . ' passed, ' . count($failures) . ' failed' . PHP_EOL;
if ($failures) {
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'PHASE 11.5C.3 CHECKOUT FINANCING BLOCK PARITY: PASS — LOCAL' . PHP_EOL;
exit(0);
