<?php

/**
 * Phase 11.5C Final Frontend Closure — Issues 1–3 local gates.
 *
 * Run: php tests/phase11_5c_frontend_closure_check.php
 */
require_once __DIR__ . '/bootstrap.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DIR_STORAGE')) {
    $storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mtuc-phase115c-fe-storage';
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
if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'oc_');
}

require_once $lib . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . '/support/phase2_memory_db.php';
require_once __DIR__ . '/support/phase4_harness.php';
require_once __DIR__ . '/support/phase5_harness.php';
require_once __DIR__ . '/support/phase9_harness.php';

$failures = array();
$passes = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtuc115cfe_assert($condition, $message)
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
 * @param string $type
 * @param int $filterId
 * @param string $kop
 * @return array<string, mixed>
 */
function mtuc115cfe_scheme($key, $months, $category, $type = 'promo', $filterId = 1, $kop = 'KOP')
{
    return array(
        'key' => $key,
        'months' => $months,
        'presentation_category' => $category,
        'scheme_type' => $type,
        'filter_id' => $filterId,
        'kop_code' => $kop,
        'label' => $months . ' месеца',
        'description' => '',
    );
}

// ---------------------------------------------------------------------------
// ISSUE 2 — normal Checkout default selection
// ---------------------------------------------------------------------------
$zeroBucket = array(
    mtuc115cfe_scheme('promo|Z|6|1', 6, MtUniCreditSchemePresentationCategory::ZERO_PROMO),
    mtuc115cfe_scheme('promo|Z|12|1', 12, MtUniCreditSchemePresentationCategory::ZERO_PROMO),
    mtuc115cfe_scheme('promo|Z|24|1', 24, MtUniCreditSchemePresentationCategory::ZERO_PROMO),
);
$presenterZero = array(
    'offers' => array(
        'promo' => array(
            'preferred_scheme_key' => 'promo|Z|6|1',
            'schemes' => $zeroBucket,
        ),
        'standard' => array('preferred_scheme_key' => '', 'schemes' => array()),
    ),
);
$session = array();
$sel = MtUniCreditCheckoutSchemeSelection::resolveInitialSchemeSelection($presenterZero, $session, 0);
mtuc115cfe_assert($sel['source'] === 'checkout_default', 'ISSUE2: 0% bucket uses checkout_default source');
mtuc115cfe_assert($sel['key'] === 'promo|Z|24|1', 'ISSUE2: 0% 6+12+24 → 24');

$mixed = array(
    mtuc115cfe_scheme('promo|Z|12|1', 12, MtUniCreditSchemePresentationCategory::ZERO_PROMO),
    mtuc115cfe_scheme('promo|P|36|1', 36, MtUniCreditSchemePresentationCategory::NONZERO_PROMO),
);
$presenterMixed = array(
    'offers' => array(
        'promo' => array('preferred_scheme_key' => 'promo|P|36|1', 'schemes' => $mixed),
        'standard' => array('preferred_scheme_key' => '', 'schemes' => array()),
    ),
);
$session = array();
$sel = MtUniCreditCheckoutSchemeSelection::resolveInitialSchemeSelection($presenterMixed, $session, 0);
mtuc115cfe_assert($sel['key'] === 'promo|Z|12|1', 'ISSUE2: 0% 12 beats promo 36');

$promoOnly = array(
    mtuc115cfe_scheme('promo|P|12|1', 12, MtUniCreditSchemePresentationCategory::NONZERO_PROMO),
    mtuc115cfe_scheme('promo|P|24|1', 24, MtUniCreditSchemePresentationCategory::NONZERO_PROMO),
);
$presenterPromo = array(
    'offers' => array(
        'promo' => array('preferred_scheme_key' => 'promo|P|12|1', 'schemes' => $promoOnly),
        'standard' => array('preferred_scheme_key' => '', 'schemes' => array()),
    ),
);
$session = array();
$sel = MtUniCreditCheckoutSchemeSelection::resolveInitialSchemeSelection($presenterPromo, $session, 0);
mtuc115cfe_assert($sel['key'] === 'promo|P|24|1', 'ISSUE2: no 0% → longest promo 24');

$stdOnly = array(
    mtuc115cfe_scheme('standard|DEF|12|1', 12, MtUniCreditSchemePresentationCategory::STANDARD, 'standard', 1, 'DEF'),
    mtuc115cfe_scheme('standard|DEF|24|1', 24, MtUniCreditSchemePresentationCategory::STANDARD, 'standard', 1, 'DEF'),
);
$presenterStd = array(
    'offers' => array(
        'standard' => array(
            'preferred_scheme_key' => 'standard|DEF|12|1',
            'schemes' => $stdOnly,
        ),
        'promo' => array('preferred_scheme_key' => '', 'schemes' => array()),
    ),
);
$session = array();
$sel = MtUniCreditCheckoutSchemeSelection::resolveInitialSchemeSelection($presenterStd, $session, 0);
mtuc115cfe_assert($sel['key'] === 'standard|DEF|12|1', 'ISSUE2: no promo → CP preferred_scheme_key');

// Tie-break: same months → lower filter_id wins
$tie = array(
    mtuc115cfe_scheme('promo|Z|24|9', 24, MtUniCreditSchemePresentationCategory::ZERO_PROMO, 'promo', 9, 'ZB'),
    mtuc115cfe_scheme('promo|Z|24|2', 24, MtUniCreditSchemePresentationCategory::ZERO_PROMO, 'promo', 2, 'ZA'),
);
$presenterTie = array(
    'offers' => array(
        'promo' => array('preferred_scheme_key' => 'promo|Z|24|9', 'schemes' => $tie),
        'standard' => array('preferred_scheme_key' => '', 'schemes' => array()),
    ),
);
$session = array();
$sel = MtUniCreditCheckoutSchemeSelection::resolveInitialSchemeSelection($presenterTie, $session, 0);
mtuc115cfe_assert($sel['key'] === 'promo|Z|24|2', 'ISSUE2: tie months → lower filter_id');

// Buy preference overrides all buckets
MtUniCreditProductBuyPreference::save($session, array(
    'store_id' => 0,
    'product_id' => 42,
    'scheme_type' => 'promo',
    'kop_code' => 'Z',
    'months' => 6,
    'filter_id' => 1,
    'scheme_key' => 'promo|Z|6|1',
));
$sel = MtUniCreditCheckoutSchemeSelection::resolveInitialSchemeSelection($presenterZero, $session, 0);
mtuc115cfe_assert($sel['source'] === 'product_buy', 'ISSUE2: Buy preference source');
mtuc115cfe_assert($sel['buy_matched'] === true, 'ISSUE2: Buy preference matched');
mtuc115cfe_assert($sel['key'] === 'promo|Z|6|1', 'ISSUE2: Buy preference exact key overrides longest 0%');

// ---------------------------------------------------------------------------
// ISSUE 3 — payment preselect
// ---------------------------------------------------------------------------
$sessionBuy = array();
MtUniCreditProductBuyPreference::save($sessionBuy, array(
    'store_id' => 1,
    'product_id' => 7,
    'scheme_key' => 'standard|K|12|1',
    'scheme_type' => 'standard',
    'kop_code' => 'K',
    'months' => 12,
    'filter_id' => 1,
));
$methods = array(
    'cod' => array('code' => 'cod', 'title' => 'COD', 'sort_order' => 1),
    'mt_uni_credit' => array(
        'code' => 'mt_uni_credit',
        'title' => 'UniCredit',
        'sort_order' => 5,
    ),
);
$applied = MtUniCreditProductBuyPreference::applyPaymentIfAvailable($sessionBuy, $methods, 1);
mtuc115cfe_assert($applied === true, 'ISSUE3: Buy preference applies payment');
mtuc115cfe_assert(
    isset($sessionBuy['payment_method']['code'])
        && $sessionBuy['payment_method']['code'] === 'mt_uni_credit',
    'ISSUE3: session.payment_method = mt_uni_credit'
);

$sessionNormal = array();
$applied = MtUniCreditProductBuyPreference::applyPaymentIfAvailable($sessionNormal, $methods, 1);
mtuc115cfe_assert($applied === false, 'ISSUE3: normal Checkout no forced payment');
mtuc115cfe_assert(!isset($sessionNormal['payment_method']), 'ISSUE3: normal Checkout payment_method unset');

$sessionStale = array();
$sessionStale[MtUniCreditProductBuyPreference::SESSION_KEY] = array(
    'flow' => MtUniCreditProductBuyPreference::FLOW,
    'store_id' => 1,
    'prefer_payment' => true,
    'payment_code' => 'mt_uni_credit',
    'scheme_key' => 'standard|K|12|1',
    'created_at' => time() - MtUniCreditProductBuyPreference::TTL_SECONDS - 10,
);
$applied = MtUniCreditProductBuyPreference::applyPaymentIfAvailable($sessionStale, $methods, 1);
mtuc115cfe_assert($applied === false, 'ISSUE3: stale Buy preference no forced payment');
mtuc115cfe_assert(
    !isset($sessionStale[MtUniCreditProductBuyPreference::SESSION_KEY]),
    'ISSUE3: stale preference cleared'
);

$sessionBuy['payment_method'] = $methods['cod'];
MtUniCreditProductBuyPreference::clearIfPaymentChangedAway($sessionBuy);
mtuc115cfe_assert(
    !isset($sessionBuy[MtUniCreditProductBuyPreference::SESSION_KEY]),
    'ISSUE3: clearing preference when other payment saved'
);

$installXml = (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'install.xml');
mtuc115cfe_assert(
    strpos($installXml, 'product_buy/applyPaymentPreselect') !== false,
    'ISSUE3: OCMOD wires applyPaymentPreselect'
);
mtuc115cfe_assert(
    strpos($installXml, 'product_buy/onPaymentMethodSaved') !== false,
    'ISSUE3: OCMOD wires onPaymentMethodSaved'
);
mtuc115cfe_assert(
    is_file(
        $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
            . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
            . DIRECTORY_SEPARATOR . 'product_buy.php'
    ),
    'ISSUE3: product_buy controller present'
);

// ---------------------------------------------------------------------------
// ISSUE 1 — P1 leasing snapshot → mail rows, no P2 sensitive fields
// ---------------------------------------------------------------------------
$coSrc = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'checkout_financing_submission_service.php');
$sfSrc = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'storefront_financing_submission_service.php');
$supportSrc = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'process_two_submission_support.php');
mtuc115cfe_assert(
    preg_match('/persistLeasingSnapshot\s*\([\s\S]*?\$isProcess2\s*\)/', $coSrc) === 1,
    'ISSUE1: checkout passes process2 flag into snapshot persist'
);
mtuc115cfe_assert(
    preg_match('/persistLeasingSnapshot\s*\([\s\S]*?\$isProcess2\s*\)/', $sfSrc) === 1,
    'ISSUE1: storefront passes process2 flag into snapshot persist'
);
mtuc115cfe_assert(
    strpos($supportSrc, '$process2 = true') !== false
        || strpos($supportSrc, '$process2 =true') !== false
        || preg_match('/function persistLeasingSnapshot\([\s\S]*\$process2/', $supportSrc) === 1,
    'ISSUE1: persistLeasingSnapshot accepts process2 argument'
);

$memoryDb = Phase4TestHarness::memoryDb();
$db = new MtUniCreditDbAdapter($memoryDb, 'oc_');
$storeId = Phase5TestHarness::STORE_A;
$orderId = 30101;
Phase9TestHarness::seedBankOrder($memoryDb, $orderId, $storeId);
$attempts = new MtUniCreditFinancingAttemptRepository($db, new MtUniCreditPersistenceClock(function () {
    return 1700000000;
}));
$attempt = $attempts->findOrCreateCheckoutAttempt(
    $storeId,
    $orderId,
    Phase4TestHarness::TEST_UNICID,
    hash('sha256', 'p1-mail-op'),
    hash('sha256', 'p1-mail-sel'),
    hash('sha256', 'p1-mail-fp')
);

$calc = new MtUniCreditCalculationResult(
    new MtUniCreditAvailableScheme(
        'standard',
        'KOPSTD',
        12,
        1,
        array(),
        array('interestPercent' => 5.5, 'coeff' => 1.05, 'installmentCount' => 12)
    ),
    500.0,
    new MtUniCreditFirstInstallmentState(100.0, false, true),
    500.0,
    45.0,
    640.0,
    5.5,
    6.1
);
MtUniCreditProcessTwoSubmissionSupport::persistLeasingSnapshot(
    $calc,
    $orderId,
    (int) $attempt['attempt_id'],
    $db,
    null,
    false
);
(new MtUniCreditOrderBankStatusRepository($db))->updateByOrderIdentifier(
    $storeId,
    (string) $orderId,
    MtUniCreditBankStatus::SENT_PROCESS1,
    MtUniCreditBankStatus::LABEL_SENT_PROCESS1
);

$svc = new MtUniCreditFinancingPresentationService(new MtUniCreditFinancingPresentationRepository($db));
$customerRows = $svc->filterCustomerFacingRows(
    $svc->rowsForOrder($storeId, $orderId, MtUniCreditFinancingPresentationAudience::CUSTOMER)
);
$customerMap = array();
foreach ($customerRows as $row) {
    $customerMap[$row['label']] = $row['value'];
}
mtuc115cfe_assert(isset($customerMap[MtUniCreditFinancingLeasingPresenter::LABEL_MONTHS]), 'ISSUE1: P1 mail months present');
mtuc115cfe_assert(isset($customerMap[MtUniCreditFinancingLeasingPresenter::LABEL_KOP]), 'ISSUE1: P1 mail KOP present');
mtuc115cfe_assert(isset($customerMap[MtUniCreditFinancingLeasingPresenter::LABEL_MONTHLY]), 'ISSUE1: P1 mail monthly present');
mtuc115cfe_assert(
    !isset($customerMap[MtUniCreditFinancingLeasingPresenter::LABEL_EGN]),
    'ISSUE1: P1 mail no EGN'
);
mtuc115cfe_assert(
    !isset($customerMap[MtUniCreditFinancingLeasingPresenter::LABEL_PHONE2]),
    'ISSUE1: P1 mail no phone2'
);
mtuc115cfe_assert(
    !isset($customerMap[MtUniCreditFinancingLeasingPresenter::LABEL_MESSAGE])
        || $customerMap[MtUniCreditFinancingLeasingPresenter::LABEL_MESSAGE]
            !== MtUniCreditFinancingLeasingPresenter::PROCESS2_MESSAGE,
    'ISSUE1: P1 mail no Process 2 customer message'
);

$thankHtml = $svc->customerThankYouHtml($storeId, $orderId);
mtuc115cfe_assert($thankHtml !== '', 'ISSUE1: P1 Thank You HTML present from same snapshot');
mtuc115cfe_assert(strpos($thankHtml, 'KOPSTD') !== false, 'ISSUE1: Thank You shares snapshot KOP');

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
