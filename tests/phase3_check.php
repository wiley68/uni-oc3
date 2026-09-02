<?php

/**
 * Phase 3 shop configuration + calculator domain checks.
 * Run: php tests/phase3_check.php
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/support/phase2_memory_db.php';
require_once __DIR__ . '/support/phase3_shop_fixture.php';
require_once __DIR__ . '/support/phase3_golden_runner.php';

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
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
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

$oracleDate = isset($golden['source']['oracle_date']) ? (string) $golden['source']['oracle_date'] : '2026-08-17';
$calculator = new MtUniCreditCalculator($oracleDate);
$goldenRunner = new Phase3GoldenRunner($golden, $calculator);
$goldenResult = $goldenRunner->runAll();

$fixtureCaseIds = array();
foreach ($golden['cases'] as $case) {
    if (isset($case['id'])) {
        $fixtureCaseIds[] = (string) $case['id'];
    }
}

echo 'Golden inventory: ' . implode(', ', $fixtureCaseIds) . PHP_EOL;

foreach ($goldenResult['case_results'] as $caseId => $status) {
    if ($status === 'PASS') {
        $passes++;
        echo 'PASS  [GOLDEN ' . $caseId . ']' . PHP_EOL;
    } else {
        $failures[] = '[GOLDEN ' . $caseId . '] case failed';
        echo 'FAIL  [GOLDEN ' . $caseId . ']' . PHP_EOL;
    }
}

foreach ($goldenResult['failures'] as $goldenFailure) {
    $failures[] = $goldenFailure;
    echo 'FAIL  ' . $goldenFailure . PHP_EOL;
}

$executedCount = count($goldenResult['executed']);
$fixtureCount = $goldenResult['fixture_count'];
mtuc3_assert($executedCount === $fixtureCount, 'golden executed_case_count === fixture_case_count (' . $executedCount . '/' . $fixtureCount . ')');

$goldenPassed = 0;
foreach ($goldenResult['case_results'] as $status) {
    if ($status === 'PASS') {
        $goldenPassed++;
    }
}
echo 'Golden cases: ' . $goldenPassed . '/' . $fixtureCount . ' executed and passed' . PHP_EOL;
mtuc3_assert($goldenPassed === $fixtureCount, 'golden cases all passed (' . $goldenPassed . '/' . $fixtureCount . ')');

$shop = mtuc3_golden_shop(array(
    'uni_user' => 'example-user',
    'uni_password' => 'demo-secret-password',
));

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
$cacheService = MtUniCreditBootstrap::shopConfigurationCacheFromDb($db);
mtuc3_assert($cacheService->replaceSnapshot(0, 'TEST-UNICID', $shop), 'shop cache replace valid snapshot');
$encodedShopData = (new MtUniCreditShopCacheRepository($db))->findEncodedShopData(0, 'TEST-UNICID');
mtuc3_assert(is_string($encodedShopData) && strpos($encodedShopData, 'demo-secret-password') === false, 'sanitized shop_data excludes uni_password plaintext');
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
