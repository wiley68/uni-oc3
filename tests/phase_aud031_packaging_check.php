<?php

/**
 * AUD-031-F02 — packaging exclusion policy + manifest assertions.
 * Run: php tests/phase_aud031_packaging_check.php
 *
 * Does NOT rebuild or replace dist/CC_OpenCartv.3.x_UNI_v.2.0.2.ocmod.zip
 * PHP 7.3 compatible. Offline (invokes local PowerShell harness only).
 */
require_once __DIR__ . '/bootstrap.php';

$root = MTUC_PHASE0_ROOT;
$failures = array();
$passes = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucAud031_assert($condition, $message)
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

$packagePs1 = (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'package.ps1');
$policyPs1 = (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'package_policy.ps1');

mtucAud031_assert(strpos($packagePs1, 'package_policy.ps1') !== false, 'package.ps1 loads package_policy.ps1');
mtucAud031_assert(strpos($packagePs1, 'Get-ApprovedPackageSourceFiles') !== false, 'package.ps1 uses approved source set');
mtucAud031_assert(strpos($packagePs1, 'Get-FileSha256Hex') !== false || strpos($packagePs1, 'SHA256') !== false, 'package.ps1 byte-integrity SHA256');
mtucAud031_assert(strpos($packagePs1, 'forbiddenEntries') !== false, 'package.ps1 retains forbiddenEntries (phase4)');
mtucAud031_assert(strpos($packagePs1, "'upload/config/environment.php'") !== false, 'package.ps1 lists forbidden upload/config/environment.php');
mtucAud031_assert(strpos($packagePs1, 'upload/system/library/mt_uni_credit/keys/.htaccess') !== false, 'package.ps1 expects keys/.htaccess');
mtucAud031_assert(strpos($packagePs1, 'upload/system/library/mt_uni_credit/secrets/.htaccess') !== false, 'package.ps1 expects secrets/.htaccess');
mtucAud031_assert(strpos($packagePs1, "'upload/system/library/mt_uni_credit/secrets/smartucf-key.php'") !== false, 'package.ps1 expects smartucf-key.php');
mtucAud031_assert(strpos($packagePs1, 'avalon_private_key.pem') !== false, 'package.ps1 keeps exact private key exclusion');
mtucAud031_assert(strpos($packagePs1, 'force_test_cp_create_422') !== false || strpos($policyPs1, 'force_test_cp_create_422') !== false, 'debug-hook sentinel present');
mtucAud031_assert(strpos($policyPs1, 'BEGIN PRIVATE KEY') !== false, 'private-key block sentinel present');
mtucAud031_assert(strpos($policyPs1, '.env') !== false, 'policy rejects .env');
mtucAud031_assert(strpos($policyPs1, '.vscode') !== false, 'policy rejects IDE metadata');
mtucAud031_assert(strpos($policyPs1, '.pem') !== false, 'policy rejects credential extensions');
mtucAud031_assert(strpos($policyPs1, 'Get-ChildItem') !== false && strpos($policyPs1, '-Force') !== false, 'approved set enumerates with -Force (hidden)');

$frozen = $root . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'CC_OpenCartv.3.x_UNI_v.2.0.2.ocmod.zip';
$frozenHashBefore = is_file($frozen) ? hash_file('sha256', $frozen) : null;

$harness = $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'aud031_packaging_harness.ps1';
mtucAud031_assert(is_file($harness), 'packaging harness script exists');

$cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -File ' . escapeshellarg($harness);
exec($cmd . ' 2>&1', $out, $code);
foreach ($out as $line) {
    echo $line . PHP_EOL;
}
mtucAud031_assert($code === 0, 'packaging harness exit 0');

$frozenHashAfter = is_file($frozen) ? hash_file('sha256', $frozen) : null;
if ($frozenHashBefore !== null) {
    mtucAud031_assert($frozenHashBefore === $frozenHashAfter, 'frozen release ZIP bytes unchanged');
} else {
    mtucAud031_assert(true, 'frozen release ZIP absent (skip byte lock)');
}

// phase4-compatible source contracts still hold
mtucAud031_assert(strpos($packagePs1, 'upload/system/library/mt_uni_credit/config/environment.php') !== false, 'phase4: environment.php expected path retained');

echo PHP_EOL . 'AUD-031 packaging: ' . $passes . ' PASS, ' . count($failures) . ' FAIL' . PHP_EOL;
if ($failures !== array()) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

exit(0);
