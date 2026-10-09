<?php
/** Official pinned core fixtures; subprocess isolation avoids duplicate OC3 class names. */
require_once __DIR__ . '/bootstrap.php';
$root = __DIR__ . '/fixtures/oc3_core';
$manifest = json_decode(file_get_contents($root . '/manifest.json'), true);
$failed = 0;
foreach ($manifest as $row) {
    if (hash_file('sha256', $root . '/' . $row['version'] . '/' . $row['path']) !== $row['sha256']) {
        echo 'FAIL fixture checksum: ' . $row['path'] . PHP_EOL;
        $failed++;
    }
}
$xml = simplexml_load_file(dirname(__DIR__) . '/install.xml');
foreach (array('3.0.3.9', '3.0.5.1') as $version) {
    $anchors = 0;
    foreach ($xml->file as $file) {
        $path = str_replace('theme/*/', 'theme/default/', (string) $file['path']);
        $source = file_get_contents($root . '/' . $version . '/' . $path);
        foreach ($file->operation as $operation) {
            $search = trim((string) $operation->search);
            $count = substr_count($source, $search);
            echo ($count === 1 ? 'PASS ' : 'FAIL ') . $version . ' OCMOD ' . $path . ': ' . $count . PHP_EOL;
            $count === 1 ? $anchors++ : $failed++;
        }
    }
    echo $version . ' OCMOD anchors: ' . $anchors . PHP_EOL;
    foreach (array('admin', 'catalog') as $context) {
        $storage = MtUniCreditTestTempRoot::allocateModuleStorage('mtuc-core-' . $context);
        $command = escapeshellarg(PHP_BINARY) . ' -d ' . escapeshellarg('disable_functions=' . ini_get('disable_functions'))
            . ' ' . escapeshellarg(__DIR__ . '/support/oc3_outbound_core_probe.php')
            . ' ' . escapeshellarg($version) . ' ' . escapeshellarg($context) . ' ' . escapeshellarg($storage);
        passthru($command, $code);
        if ($code !== 0) { $failed++; }
        MtUniCreditTestTempRoot::cleanup($storage);
    }
}
echo 'OC3 OUTBOUND COMPATIBILITY: failures=' . $failed . PHP_EOL;
exit($failed === 0 ? 0 : 1);
