<?php

/**
 * Phase 8 Product/Cart storefront + OCMOD/Journal local checks.
 * Run: php tests/phase8_check.php
 *
 * Assertion body lives in support/phase8_cases_body.php and is required inside
 * mtuc8_run() so IDE type inference is not overloaded on {main}.
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

/**
 * @param string $root
 * @param string $lib
 * @param string $catalog
 * @return void
 */
function mtuc8_run($root, $lib, $catalog)
{
    require __DIR__ . '/support/phase8_body_1.php';
    require __DIR__ . '/support/phase8_body_2.php';
    require __DIR__ . '/support/phase8_body_3.php';
    require __DIR__ . '/support/phase8_body_4.php';
    require __DIR__ . '/support/phase8_body_5.php';
}

mtuc8_run($root, $lib, $catalog);

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
