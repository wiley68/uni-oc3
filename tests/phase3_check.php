<?php

/**
 * Phase 3 shop configuration + calculator domain checks.
 * Run: php tests/phase3_check.php
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/support/phase2_memory_db.php';
require_once __DIR__ . '/support/phase3_shop_fixture.php';

$failures = array();
$passes = 0;

function mtuc3_assert(bool $condition, string $message): void
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

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}

require_once $lib . DIRECTORY_SEPARATOR . 'bootstrap.php';

$golden = mtuc_phase0_load_fixture('calculator_golden.json');
$calcRequired = array(
    'calculator/bootstrap.php',
    'calculator/calculator.php',
    'calculator/cart_scheme_resolver.php',
    'shop_cache_repository.php',
    'shop_configuration_cache.php',
    'shop_configuration_snapshot_validator.php',
);

foreach ($calcRequired as $relative) {
    mtuc3_assert(is_file($lib . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative)), 'required file: ' . $relative);
}

$phase3Sql = MtUniCreditPersistenceSchema::createPhase3TableStatements('oc_');
mtuc3_assert(count($phase3Sql) === 1, 'Phase 3 schema creates shop cache table');
mtuc3_assert(strpos($phase3Sql[0], 'mt_uni_credit_shop_cache') !== false, 'shop cache table name present');
mtuc3_assert(strpos($phase3Sql[0], 'uniq_mt_uni_credit_shop_cache_store_unicid') !== false, 'shop cache unique index present');

$shop = mtuc3_golden_shop();
$product = mtuc3_golden_product();
$calculator = new MtUniCreditCalculator('2026-08-17');

$standard = $calculator->resolvePreferredOffer($shop, $product, 'standard');
mtuc3_assert($standard !== null, 'standard_preferred offer present');
if ($standard !== null) {
    mtuc3_assert($standard->kopCode === 'STD', 'standard_preferred kop STD');
    mtuc3_assert($standard->months === 12, 'standard_preferred months 12');
    mtuc3_assert(abs($standard->monthlyInstallment - 95.00) < 0.001, 'standard_preferred monthly 95.00');
    mtuc3_assert(abs($standard->glp - 18.00) < 0.001, 'standard_preferred GLP 18.00');
    mtuc3_assert(abs($standard->gpr - 27.96) < 0.01, 'standard_preferred GPR offerFactory 27.96');
}

$promo = $calculator->resolvePreferredOffer($shop, $product, 'promo');
mtuc3_assert($promo !== null, 'promo_0_percent offer present');
if ($promo !== null) {
    mtuc3_assert($promo->kopCode === 'PROMO', 'promo kop PROMO');
    mtuc3_assert(abs($promo->monthlyInstallment - 83.33) < 0.01, 'promo monthly 83.33');
    mtuc3_assert(abs($promo->glp) < 0.001, 'promo GLP 0');
    mtuc3_assert(abs($promo->gpr - 0.01) < 0.001, 'promo GPR offerFactory 0.01');
    $scheme = null;
    foreach ($calculator->availableSchemes($shop, $product, 'promo') as $candidate) {
        if ($candidate->kopCode === 'PROMO' && $candidate->months === 12) {
            $scheme = $candidate;
            break;
        }
    }
    mtuc3_assert($scheme !== null, 'promo scheme for calculateScheme found');
    if ($scheme !== null) {
        $result = $calculator->calculateScheme($shop, 1000.0, $scheme);
        mtuc3_assert(abs($result->gpr) < 0.001, 'promo GPR calculateScheme floor 0.0');
    }
}

mtuc3_assert(!$calculator->isAvailableForAmount($shop, 99.99), 'price below shop min rejected');
mtuc3_assert($calculator->isAvailableForAmount($shop, 100.0), 'price at shop min accepted');
mtuc3_assert($calculator->isAvailableForAmount($shop, 10000.0), 'price at shop max accepted');
mtuc3_assert(!$calculator->isAvailableForAmount($shop, 10000.01), 'price above shop max rejected');

$schemaShop = mtuc3_schema_filter_shop();
$schemaProduct = new MtUniCreditProductContext(42, array(7, 9), 1000.0);
$standardSchemes = $calculator->availableSchemes($schemaShop, $schemaProduct, 'standard');
mtuc3_assert(count($standardSchemes) === 3, 'filter_eligibility standard scheme count 3');
$promoSchemes = $calculator->availableSchemes($schemaShop, $schemaProduct, 'promo');
mtuc3_assert(count($promoSchemes) === 1, 'filter_eligibility promo scheme count 1');

$lockedScheme = null;
foreach ($standardSchemes as $scheme) {
    if ($scheme->filterId === 11 && $scheme->months === 24) {
        $lockedScheme = $scheme;
        break;
    }
}
mtuc3_assert($lockedScheme !== null, 'first_installment locked scheme found');
if ($lockedScheme !== null) {
    $locked = $calculator->calculateScheme($schemaShop, 1000.0, $lockedScheme);
    mtuc3_assert(abs($locked->firstInstallment->amount - 41.67) < 0.01, 'locked first installment 41.67');
    mtuc3_assert($locked->firstInstallment->locked === true, 'first installment locked flag');
    mtuc3_assert(abs($locked->financedAmount - 958.33) < 0.01, 'locked financed 958.33');
    mtuc3_assert(abs($locked->monthlyInstallment - 47.92) < 0.01, 'locked monthly 47.92');
    mtuc3_assert(abs($locked->totalPayable - 1150.08) < 0.02, 'locked total 1150.08');
    mtuc3_assert(abs($locked->gpr - 19.76) < 0.01, 'locked GPR calculateScheme 19.76');
}

$userShop = mtuc3_golden_shop(array('uni_first_vnoska' => 1));
$userScheme = null;
foreach ($calculator->availableSchemes($userShop, $product, 'standard') as $scheme) {
    if ($scheme->months === 12) {
        $userScheme = $scheme;
        break;
    }
}
if ($userScheme !== null) {
    $userResult = $calculator->calculateScheme($userShop, 1000.0, $userScheme, 200.0);
    mtuc3_assert(abs($userResult->firstInstallment->amount - 200.0) < 0.001, 'user first installment 200');
    mtuc3_assert(abs($userResult->financedAmount - 800.0) < 0.001, 'user financed 800');
    mtuc3_assert(abs($userResult->monthlyInstallment - 76.0) < 0.001, 'user monthly 76');
    mtuc3_assert(abs($userResult->totalPayable - 912.0) < 0.001, 'user total 912');
}

$selector = new MtUniCreditPreferredOfferSelector();
$tieOffers = array(
    new MtUniCreditOffer('standard', 'A', 12, 95.0, 18.0, 27.0, 1000.0, 0.095),
    new MtUniCreditOffer('standard', 'B', 12, 90.0, 18.0, 26.0, 1000.0, 0.09),
    new MtUniCreditOffer('standard', 'C', 24, 50.0, 17.0, 20.0, 1000.0, 0.05),
);
$chosen = $selector->select($tieOffers, 12);
mtuc3_assert($chosen !== null && $chosen->kopCode === 'B', 'preferred tie-break lowest monthly at 12 months');
$fallback = $selector->select($tieOffers, 18);
mtuc3_assert($fallback !== null && $fallback->kopCode === 'C', 'preferred fallback highest months');

$orderingInput = $golden['cases'][array_search('scheme_ordering', array_column($golden['cases'], 'id'), true)]['input'];
$orderingSchemes = array();
foreach ($orderingInput as $row) {
    $orderingSchemes[] = new MtUniCreditAvailableScheme(
        $row['type'],
        $row['kop'],
        (int) $row['months'],
        (int) $row['filterId'],
        array('uni_promo' => (int) $row['uni_promo']),
        array('interestPercent' => (float) $row['interestPercent'], 'coeff' => 0.1)
    );
}
$sorted = MtUniCreditSchemePresentationOrder::sort($orderingSchemes, $shop);
$labels = array();
foreach ($sorted as $scheme) {
    $labels[] = MtUniCreditSchemePresentationCategory::presentationLabel($scheme, $shop);
}
$expectedLabels = $golden['cases'][array_search('scheme_ordering', array_column($golden['cases'], 'id'), true)]['expect_labels'];
mtuc3_assert($labels === $expectedLabels, 'scheme ordering labels match golden fixture');

$resolver = new MtUniCreditCartSchemeResolver($calculator);
// OC4 cart golden uses product 1 + category 7 (filter 11 is product_id 42 only).
$lineProduct = new MtUniCreditProductContext(1, array(7), 1000.0);
$cartLine = new MtUniCreditCartLine($lineProduct, 0, 1, 1000.0);
$cart = new MtUniCreditCartContext(array($cartLine), 1000.0);
$resolution = $resolver->resolve($schemaShop, $cart);
mtuc3_assert(count($resolution->standardSchemes) === 3, 'cart single line standard intersection count');
$cartTwo = new MtUniCreditCartContext(array($cartLine, $cartLine), 1000.0);
$resolutionTwo = $resolver->resolve($schemaShop, $cartTwo);
mtuc3_assert(count($resolutionTwo->standardSchemes) === 3, 'cart duplicate lines keep common schemes');
mtuc3_assert($resolver->lcm(array(6, 12)) === 12, 'cart lcm 6 and 12');
mtuc3_assert($resolver->lcm(array(6, 8)) === 24, 'cart lcm 6 and 8');

$currencyGate = new MtUniCreditCurrencyGate();
mtuc3_assert($currencyGate->supports($shop, 'BGN'), 'currency BGN supported for uni_eur 0');
mtuc3_assert(!$currencyGate->supports(array_replace($shop, array('uni_eur' => 0)), 'EUR'), 'EUR rejected when shop expects BGN');
mtuc3_assert($currencyGate->supports(array_replace($shop, array('uni_eur' => 2)), 'EUR'), 'EUR supported for uni_eur 2');

$validator = new MtUniCreditShopConfigurationSnapshotValidator();
try {
    $validator->validate($shop, 'TEST-UNICID');
    mtuc3_assert(true, 'valid shop snapshot passes validation');
} catch (MtUniCreditShopSnapshotValidationException $exception) {
    mtuc3_assert(false, 'valid shop snapshot passes validation');
}

$invalid = $shop;
unset($invalid['coeff_list']);
try {
    $validator->validate($invalid, 'TEST-UNICID');
    mtuc3_assert(false, 'missing coeff_list fails validation');
} catch (MtUniCreditShopSnapshotValidationException $exception) {
    mtuc3_assert(true, 'missing coeff_list fails validation');
}

$memoryDb = new Phase2MemoryDb();
$db = new MtUniCreditDbAdapter($memoryDb, 'oc_');
$cacheRepo = new MtUniCreditShopCacheRepository($db, new MtUniCreditPersistenceClock(function () {
    return 1700000000;
}));
$cacheService = new MtUniCreditShopConfigurationCache($cacheRepo);
mtuc3_assert($cacheService->replaceSnapshot(0, 'TEST-UNICID', $shop), 'shop cache replace valid snapshot');
mtuc3_assert($cacheService->getFreshShopData(0, 'TEST-UNICID') !== null, 'shop cache fresh read succeeds');
mtuc3_assert($cacheService->getFreshShopData(1, 'TEST-UNICID') === null, 'shop cache no cross-store fallback');
$badReplace = $cacheService->replaceSnapshot(0, 'TEST-UNICID', array('uni_status' => 2));
mtuc3_assert($badReplace === false, 'invalid snapshot does not replace cache');
mtuc3_assert($cacheService->getFreshShopData(0, 'TEST-UNICID') !== null, 'invalid replace preserves previous cache');

$moduleTwig = file_get_contents($root . DIRECTORY_SEPARATOR . 'upload/admin/view/template/extension/module/mt_uni_credit.twig');
mtuc3_assert(strpos($moduleTwig, 'text-danger">*</span> {{ entry_unicid') === false, 'UNICID label does not use red required marker');
mtuc3_assert(strpos($moduleTwig, 'mt-uni-required') !== false, 'module twig uses neutral required marker');

echo PHP_EOL . 'Phase 3 checks: ' . $passes . ' passed';
if ($failures) {
    echo ', ' . count($failures) . ' failed' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo ', 0 failed' . PHP_EOL;
exit(0);
