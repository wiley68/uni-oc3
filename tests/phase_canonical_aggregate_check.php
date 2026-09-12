<?php

/**
 * Aggregate runner for canonical CP↔OC3 focused offline checks.
 * Run: php tests/phase_canonical_aggregate_check.php
 *
 * No network. Exit non-zero on any failure.
 */
$php = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : 'php';
$root = dirname(__DIR__);
$testsDir = __DIR__;

$scripts = array(
    'phase_canonical_inbound_check.php',
    'phase_canonical_financing_resolver_check.php',
    'phase_canonical_bank_status_check.php',
    'phase_canonical_smartucf_debug_check.php',
    'phase_canonical_cp_client_check.php',
    'phase_canonical_status_sync_check.php',
    'phase_canonical_p1_lifecycle_check.php',
    'phase_canonical_p2_lifecycle_check.php',
    'phase_canonical_freetext_check.php',
    'phase_canonical_order_id_string_check.php',
);

// Optional safe historical scripts that remain offline and usually green.
$optionalHistorical = array(
    'phase7_check.php',
    'phase9_check.php',
);

$runOptional = in_array('--with-historical', $argv, true);
if ($runOptional) {
    $scripts = array_merge($scripts, $optionalHistorical);
}

$passed = 0;
$failed = 0;
$results = array();

foreach ($scripts as $script) {
    $path = $testsDir . DIRECTORY_SEPARATOR . $script;
    if (!is_file($path)) {
        $failed++;
        $results[] = array('script' => $script, 'ok' => false, 'code' => 127, 'note' => 'missing');
        echo 'MISSING  ' . $script . PHP_EOL;
        continue;
    }

    echo PHP_EOL . '======== ' . $script . ' ========' . PHP_EOL;
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($path);
    $output = array();
    $code = 0;
    exec($cmd . ' 2>&1', $output, $code);
    echo implode(PHP_EOL, $output) . PHP_EOL;
    if ((int) $code === 0) {
        $passed++;
        $results[] = array('script' => $script, 'ok' => true, 'code' => 0);
        echo 'OK  ' . $script . PHP_EOL;
    } else {
        $failed++;
        $results[] = array('script' => $script, 'ok' => false, 'code' => (int) $code);
        echo 'FAIL  ' . $script . ' (exit ' . (int) $code . ')' . PHP_EOL;
    }
}

echo PHP_EOL . '======== AGGREGATE ========' . PHP_EOL;
echo 'passed=' . $passed . ' failed=' . $failed . ' total=' . ($passed + $failed) . PHP_EOL;
foreach ($results as $row) {
    echo ($row['ok'] ? 'PASS' : 'FAIL') . '  ' . $row['script'] . PHP_EOL;
}

exit($failed === 0 ? 0 : 1);
