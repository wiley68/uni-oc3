<?php

/**
 * AUD-016 F01–F03 — Calculator selection identity / Checkout fail-closed / coefficient ambiguity.
 *
 * Run: php tests/phase_aud016_calculator_authority_check.php
 *
 * Anti-false-positive:
 * - must fail if filterId returns to public scheme keys
 * - must fail if Checkout preferred fallback for invalid selection is restored
 * - must fail if CoefficientResolver returns first conflicting duplicate
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
    mtuc_test_define_dir_storage('mtuc-aud016');
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
require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

$failures = array();
$passes = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucAud016_assert($condition, $message)
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
function mtucAud016_read($path)
{
    $body = @file_get_contents($path);

    return is_string($body) ? $body : '';
}

$presenterSrc = mtucAud016_read($lib . '/storefront_calculator_presenter.php');
$checkoutSrc = mtucAud016_read($lib . '/checkout_financing_submission_service.php');
$coeffSrc = mtucAud016_read($lib . '/calculator/coefficient_resolver.php');

mtucAud016_assert(
    strpos($presenterSrc, "count(\$parts) !== 3") !== false
        || strpos($presenterSrc, 'count($parts) !== 3') !== false,
    'F01 static: parseSchemeKey requires exactly 3 parts'
);
mtucAud016_assert(
    strpos($presenterSrc, 'AUD-016 F01') !== false
        && strpos($presenterSrc, 'rawurlencode') !== false,
    'F01 static: identity comment + urlencode present'
);
mtucAud016_assert(
    preg_match('/function schemeKey\s*\(\s*\$type\s*,\s*\$kopCode\s*,\s*\$months\s*\)/', $presenterSrc) === 1,
    'F01 static: schemeKey has no filterId parameter'
);
mtucAud016_assert(
    strpos($checkoutSrc, 'AUD-016 F02') !== false
        && strpos($checkoutSrc, 'preferred') !== false
        && strpos($checkoutSrc, 'never replace with preferred') !== false,
    'F02 static: preferred fallback removed / documented'
);
mtucAud016_assert(
    strpos($coeffSrc, 'AUD-016 F03') !== false
        && strpos($coeffSrc, 'normalizeFingerprint') !== false,
    'F03 static: conflict detection present'
);

// ---------------------------------------------------------------------------
// F01 — public key = type|kop|months
// ---------------------------------------------------------------------------
$keyA = MtUniCreditStorefrontCalculatorPresenter::schemeKey('standard', 'STD', 12);
$keyB = MtUniCreditStorefrontCalculatorPresenter::schemeKey('standard', 'STD', 12);
mtucAud016_assert($keyA === 'standard|STD|12', 'F01 key shape standard|STD|12');
mtucAud016_assert($keyA === $keyB, 'F01 same identity → same key regardless of filter metadata');
mtucAud016_assert(
    MtUniCreditProductSchemeList::keyFromParts('standard', 'STD', 12) === $keyA,
    'F01 ProductSchemeList key matches presenter'
);

$parsed = MtUniCreditStorefrontCalculatorPresenter::parseSchemeKey($keyA);
mtucAud016_assert(
    is_array($parsed)
        && $parsed['type'] === 'standard'
        && $parsed['kop_code'] === 'STD'
        && (int) $parsed['months'] === 12
        && !array_key_exists('filter_id', $parsed),
    'F01 parser resolves 3-part without filter_id'
);
mtucAud016_assert(
    MtUniCreditStorefrontCalculatorPresenter::parseSchemeKey('standard|STD|12|10') === null,
    'F01 parser rejects legacy 4-part key'
);
mtucAud016_assert(
    MtUniCreditStorefrontCalculatorPresenter::parseSchemeKey('standard|STD') === null,
    'F01 parser rejects malformed 2-part key'
);

// Presenter/list identity ignores filterId
$schemeFilter10 = new MtUniCreditAvailableScheme(
    'standard',
    'STD',
    12,
    10,
    array('uni_promo' => 0, 'uni_kop_desc' => 'A', 'uni_parva' => 0),
    array('coeff' => 0.095, 'interestPercent' => 5.0)
);
$schemeFilter20 = new MtUniCreditAvailableScheme(
    'standard',
    'STD',
    12,
    20,
    array('uni_promo' => 0, 'uni_kop_desc' => 'B', 'uni_parva' => 0),
    array('coeff' => 0.095, 'interestPercent' => 5.0)
);
mtucAud016_assert(
    MtUniCreditStorefrontCalculatorPresenter::keyForScheme($schemeFilter10)
        === MtUniCreditStorefrontCalculatorPresenter::keyForScheme($schemeFilter20)
        && MtUniCreditStorefrontCalculatorPresenter::keyForScheme($schemeFilter10) === 'standard|STD|12',
    'F01 keyForScheme: filterId 10 and 20 → same public key'
);
mtucAud016_assert(
    MtUniCreditProductSchemeList::key($schemeFilter10) === MtUniCreditProductSchemeList::key($schemeFilter20),
    'F01 ProductSchemeList::key collapses filter variants'
);

// ---------------------------------------------------------------------------
// F03 — coefficient ambiguity
// ---------------------------------------------------------------------------
$months = new MtUniCreditMonthResolver();
$resolver = new MtUniCreditCoefficientResolver($months);

$single = $resolver->find(array(
    array('onlineProductCode' => 'STD', 'installmentCount' => 12, 'coeff' => 0.095, 'interestPercent' => 1.0),
), 'STD', 12);
mtucAud016_assert(
    is_array($single) && abs((float) $single['coeff'] - 0.095) < 0.0000001,
    'F03 single exact coefficient → offer coeff'
);

$missing = $resolver->find(array(
    array('onlineProductCode' => 'OTHER', 'installmentCount' => 12, 'coeff' => 0.095, 'interestPercent' => 1.0),
), 'STD', 12);
mtucAud016_assert($missing === null, 'F03 missing coefficient → null');

$identical = $resolver->find(array(
    array('onlineProductCode' => 'STD', 'installmentCount' => 12, 'coeff' => 0.095, 'interestPercent' => 1.0),
    array('onlineProductCode' => 'STD', 'installmentCount' => 12, 'coeff' => 0.095, 'interestPercent' => 1.0),
), 'STD', 12);
mtucAud016_assert(
    is_array($identical) && abs((float) $identical['coeff'] - 0.095) < 0.0000001,
    'F03 identical duplicates → deterministic 0.095'
);

$conflictA = $resolver->find(array(
    array('onlineProductCode' => 'STD', 'installmentCount' => 12, 'coeff' => 0.095, 'interestPercent' => 1.0),
    array('onlineProductCode' => 'STD', 'installmentCount' => 12, 'coeff' => 0.105, 'interestPercent' => 1.0),
), 'STD', 12);
$conflictB = $resolver->find(array(
    array('onlineProductCode' => 'STD', 'installmentCount' => 12, 'coeff' => 0.105, 'interestPercent' => 1.0),
    array('onlineProductCode' => 'STD', 'installmentCount' => 12, 'coeff' => 0.095, 'interestPercent' => 1.0),
), 'STD', 12);
mtucAud016_assert($conflictA === null, 'F03 conflicting duplicates → null');
mtucAud016_assert($conflictB === null, 'F03 reversed conflicting duplicates → null (order-independent)');

$calc = new MtUniCreditCalculator();
$shopConflict = mtuc4_valid_shop_snapshot(array(
    'coeff_list' => array(
        array('onlineProductCode' => 'KOPSTD', 'installmentCount' => 12, 'coeff' => 0.095, 'interestPercent' => 5.0),
        array('onlineProductCode' => 'KOPSTD', 'installmentCount' => 12, 'coeff' => 0.105, 'interestPercent' => 5.0),
    ),
));
$productCtx = new MtUniCreditProductContext(42, array(7), 800.0);
$schemesConflict = $calc->availableSchemes($shopConflict, $productCtx, 'standard');
$has12 = false;
foreach ($schemesConflict as $scheme) {
    if ($scheme->kopCode === 'KOPSTD' && (int) $scheme->months === 12) {
        $has12 = true;
    }
}
mtucAud016_assert(!$has12, 'F03 conflicting coeff → no available 12m scheme/offer');

// ---------------------------------------------------------------------------
// F02 — Checkout exact selection fail-closed
// ---------------------------------------------------------------------------
$transport = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transport);
$stack = Phase9TestHarness::stack($transport);
$orderId = 16001;
Phase9TestHarness::seedBankOrder($stack['memoryDb'], $orderId, $stack['storeId']);

$inputOk = Phase9TestHarness::submitInput($orderId, $stack['storeId']);
$inputOk['scheme_key'] = 'standard|KOPSTD|12';
$resultOk = $stack['submission']->submit($inputOk);
mtucAud016_assert(!empty($resultOk['success']), 'F02 valid exact selection → success');
mtucAud016_assert(Phase7TestHarness::countOrderPosts($transport) === 1, 'F02 valid: CP create = 1');

// Stale months: only 12 enabled in shop; submit 24
$transportStale = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportStale);
$stackStale = Phase9TestHarness::stack($transportStale);
$orderStale = 16002;
Phase9TestHarness::seedBankOrder($stackStale['memoryDb'], $orderStale, $stackStale['storeId']);
$inputStale = Phase9TestHarness::submitInput($orderStale, $stackStale['storeId']);
$inputStale['scheme_key'] = 'standard|KOPSTD|24';
$resultStale = $stackStale['submission']->submit($inputStale);
mtucAud016_assert(
    isset($resultStale['error']) && $resultStale['error'] === 'unavailable',
    'F02 stale 24m selection → blocked'
);
mtucAud016_assert(Phase7TestHarness::countOrderPosts($transportStale) === 0, 'F02 stale: no CP create');

// Malformed / unknown / wrong KOP
$cases = array(
    array('malformed', 'not-a-key'),
    array('legacy 4-part', 'standard|KOPSTD|12|0'),
    array('unknown type', 'weird|KOPSTD|12'),
    array('wrong kop', 'standard|NOSUCH|12'),
    array('empty', ''),
);
foreach ($cases as $i => $case) {
    $transportBad = new Phase4FakeCpHttpTransport();
    Phase9TestHarness::enqueueCpCreateSuccess($transportBad);
    $stackBad = Phase9TestHarness::stack($transportBad);
    $oid = 16100 + $i;
    Phase9TestHarness::seedBankOrder($stackBad['memoryDb'], $oid, $stackBad['storeId']);
    $inputBad = Phase9TestHarness::submitInput($oid, $stackBad['storeId']);
    $inputBad['scheme_key'] = $case[1];
    $resultBad = $stackBad['submission']->submit($inputBad);
    mtucAud016_assert(
        isset($resultBad['error']) && $resultBad['error'] === 'unavailable',
        'F02 ' . $case[0] . ' → blocked'
    );
    mtucAud016_assert(Phase7TestHarness::countOrderPosts($transportBad) === 0, 'F02 ' . $case[0] . ': no CP');
}

// Preferred must not rescue stale key when a different preferred offer exists
$transportPref = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportPref);
$monthsMap = array();
for ($m = 3; $m <= 36; ++$m) {
    $monthsMap['uni_meseci_' . $m] = ($m === 12 || $m === 24) ? 1 : 0;
}
$stackPref = Phase9TestHarness::stack(
    $transportPref,
    null,
    null,
    Phase5TestHarness::STORE_A,
    array_merge($monthsMap, array(
        'uni_shema_current' => 24,
        'coeff_list' => array(
            array(
                'onlineProductCode' => 'KOPSTD',
                'installmentCount' => 12,
                'coeff' => 1.05,
                'interestPercent' => 5.5,
            ),
            array(
                'onlineProductCode' => 'KOPSTD',
                'installmentCount' => 24,
                'coeff' => 1.10,
                'interestPercent' => 6.0,
            ),
        ),
    ))
);
$orderPref = 16200;
Phase9TestHarness::seedBankOrder($stackPref['memoryDb'], $orderPref, $stackPref['storeId']);
// Submit identity that is NOT currently eligible: use promo kop that is not available
$inputPref = Phase9TestHarness::submitInput($orderPref, $stackPref['storeId']);
$inputPref['scheme_key'] = 'standard|WRONGKOP|12';
$resultPref = $stackPref['submission']->submit($inputPref);
mtucAud016_assert(
    isset($resultPref['error']) && $resultPref['error'] === 'unavailable',
    'F02 invalid identity not replaced by preferred standard/24'
);
mtucAud016_assert(Phase7TestHarness::countOrderPosts($transportPref) === 0, 'F02 preferred rescue blocked: no CP');

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
