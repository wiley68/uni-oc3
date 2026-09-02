<?php

/**
 * Lightweight Phase 0 contract checks. Run: php tests/phase0_check.php
 *
 * PHP 7.3 compatible. No PHPUnit. No live network.
 */
require_once __DIR__ . '/bootstrap.php';

$failures = array();
$passes = 0;

function mtuc_assert(bool $condition, string $message): void
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

$requiredFixtures = array(
    'calculator_golden.json',
    'hmac_callback_vector.json',
    'cp_auth_contract.json',
    'cp_api_endpoints.json',
    'cp_order_payload.json',
    'status_vocabulary.json',
    'extension_identity.json',
    'oc3_lifecycle.json',
    'oc3_routing.json',
    'compatibility_matrix.json',
    'php_floor.json',
    'secret_deployment.json',
    'process1_contract.json',
    'process2_contract.json',
    'privacy_retention.json',
    'shop_cache_contract.json',
    'journal_asset_pattern.json',
    'inbound_api_contract.json',
    'forbidden_php_syntax.json',
    'cp_environments.json',
);

foreach ($requiredFixtures as $name) {
    try {
        $data = mtuc_phase0_load_fixture($name);
        mtuc_assert($data !== array(), 'fixture loads: ' . $name);
    } catch (Exception $e) {
        mtuc_assert(false, 'fixture loads: ' . $name . ' — ' . $e->getMessage());
    }
}

$hmac = mtuc_phase0_load_fixture('hmac_callback_vector.json');
$vector = $hmac['vector'];
$canonical = $vector['timestamp'] . "\n" . $vector['nonce'] . "\n" . $vector['raw_body'];
$signature = hash_hmac('sha256', $canonical, $vector['secret']);
mtuc_assert($hmac['algorithm'] === 'sha256', 'HMAC algorithm is sha256');
mtuc_assert($hmac['headers']['timestamp'] === 'X-UniPayment-Timestamp', 'HMAC timestamp header');
mtuc_assert($hmac['headers']['nonce'] === 'X-UniPayment-Nonce', 'HMAC nonce header');
mtuc_assert($hmac['headers']['signature'] === 'X-UniPayment-Signature', 'HMAC signature header');
mtuc_assert(strlen($vector['nonce']) === 64, 'nonce length 64');
mtuc_assert((bool) preg_match('/^[0-9a-fA-F]{64}$/', $vector['nonce']), 'nonce hex charset');
mtuc_assert($hmac['rules']['timestamp_tolerance_seconds'] === 300, 'timestamp window 300');
mtuc_assert($hmac['rules']['nonce_retention_seconds'] === 900, 'nonce retention 900');
mtuc_assert($signature === $vector['expected_sha256_hmac'], 'known HMAC vector matches');
mtuc_assert(!preg_match('/prod|live|avalon/i', $vector['secret']), 'HMAC secret is not a production-looking value');

$pretty = json_encode(json_decode($vector['raw_body'], true), JSON_PRETTY_PRINT);
$reencoded = hash_hmac('sha256', $vector['timestamp'] . "\n" . $vector['nonce'] . "\n" . $pretty, $vector['secret']);
mtuc_assert($reencoded !== $vector['expected_sha256_hmac'], 're-encoded JSON does not match frozen HMAC');

$parityFixtures = array(
    'calculator_golden.json',
    'hmac_callback_vector.json',
    'cp_auth_contract.json',
    'cp_api_endpoints.json',
    'cp_order_payload.json',
    'status_vocabulary.json',
);
$referenceFixtures = dirname(MTUC_PHASE0_ROOT) . DIRECTORY_SEPARATOR . 'reference-uni-oc4' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures';
foreach ($parityFixtures as $name) {
    $ours = hash_file('sha256', MTUC_PHASE0_FIXTURES . DIRECTORY_SEPARATOR . $name);
    $theirsPath = $referenceFixtures . DIRECTORY_SEPARATOR . $name;
    if (is_file($theirsPath)) {
        $theirs = hash_file('sha256', $theirsPath);
        mtuc_assert($ours === $theirs, 'OC4 parity checksum: ' . $name);
    } else {
        mtuc_assert(false, 'OC4 reference fixture missing for checksum: ' . $name);
    }
}

$calc = mtuc_phase0_load_fixture('calculator_golden.json');
mtuc_assert(isset($calc['formulas']['monthly_installment']), 'calculator formulas present');
mtuc_assert($calc['shop']['uni_minstojnost'] === 100, 'calculator shop min bound');
mtuc_assert($calc['shop']['uni_maxstojnost'] === 10000, 'calculator shop max bound');
$caseIds = array();
foreach ($calc['cases'] as $case) {
    $caseIds[] = $case['id'];
}
foreach (array('standard_preferred', 'promo_0_percent', 'cart_intersection', 'currency_gate') as $id) {
    mtuc_assert(in_array($id, $caseIds, true), 'calculator golden case: ' . $id);
}

$identity = mtuc_phase0_load_fixture('extension_identity.json');
mtuc_assert($identity['code'] === 'mt_uni_credit', 'module code mt_uni_credit');
mtuc_assert($identity['extension_type_primary'] === 'payment', 'primary type payment');
mtuc_assert(isset($identity['oc3_module_settings_code']), 'fixture module settings code');
mtuc_assert(isset($identity['oc3_payment_settings_code']), 'fixture payment settings code');
mtuc_assert($identity['version'] === '2.0.2', 'module version 2.0.2 frozen');
mtuc_assert($identity['release_version_status'] === 'closed_d2', 'D2 version closed');

$life = mtuc_phase0_load_fixture('oc3_lifecycle.json');
mtuc_assert($life['findings']['confirm_always_creates_new_order'] === true, 'OC3 confirm always addOrder');
mtuc_assert(strpos($life['project_rule'], 'must never create a second order') !== false, 'checkout never second order');

$status = mtuc_phase0_load_fixture('status_vocabulary.json');
$ids = array();
foreach ($status['module_outbound_bank_status'] as $row) {
    $ids[] = $row['status_id'];
}
foreach (array('bank_sent_process1', 'bank_sent_process2', 'bank_send_failed', 'bank_send_failed_cp', 'bank_send_failed_smartucf') as $sid) {
    mtuc_assert(in_array($sid, $ids, true), 'status id frozen: ' . $sid);
}
mtuc_assert((int) $status['process_flag']['process_2_when'] === 1, 'Process 2 when uni_proces === 1');

$phpFloor = mtuc_phase0_load_fixture('php_floor.json');
mtuc_assert($phpFloor['status'] === 'closed', 'D1 PHP floor closed');
mtuc_assert($phpFloor['floor'] === '7.3.0', 'PHP floor 7.3.0');

$secretDeploy = mtuc_phase0_load_fixture('secret_deployment.json');
mtuc_assert($secretDeploy['status'] === 'closed_for_phase_0', 'D3 closed for Phase 0');
mtuc_assert($secretDeploy['filenames']['passphrase'] === 'secrets/smartucf-key.php', 'D3 passphrase path frozen');

$cpEnv = mtuc_phase0_load_fixture('cp_environments.json');
mtuc_assert($cpEnv['status'] === 'closed_for_phase_0', 'D4 closed for Phase 0');
mtuc_assert($cpEnv['api_prefix'] === '/api/v1', 'CP API prefix frozen');
mtuc_assert(in_array('online.ucfin.bg', $cpEnv['smartucf_hosts'], true), 'SmartUCF production host frozen');

$contractsPath = MTUC_PHASE0_DOCS . DIRECTORY_SEPARATOR . 'CONTRACTS.md';
$runtimePath = MTUC_PHASE0_DOCS . DIRECTORY_SEPARATOR . 'RUNTIME_VERIFICATION.md';
mtuc_assert(is_file($contractsPath), 'docs/CONTRACTS.md exists');
mtuc_assert(is_file($runtimePath), 'docs/RUNTIME_VERIFICATION.md exists');
$contracts = file_get_contents($contractsPath);
$requiredIds = array(
    'MODULE-001',
    'MODULE-005',
    'CALC-001',
    'CP-AUTH-001',
    'CACHE-001',
    'CP-ORDER-001',
    'STATUS-001',
    'P1-001',
    'P2-001',
    'SEC-HMAC-001',
    'STORE-001',
    'PII-001',
    'RETENTION-001',
    'IDEM-001',
    'OC3-CHECKOUT-001',
    'OCMOD-001',
    'JOURNAL-001',
    'PHP-001',
    'DEPLOY-001',
);
foreach ($requiredIds as $cid) {
    mtuc_assert(strpos($contracts, $cid) !== false, 'CONTRACTS.md contains ' . $cid);
}
mtuc_assert(strpos($contracts, 'D1 PHP floor                       | **CLOSED**') !== false, 'CONTRACTS.md D1 closed');
mtuc_assert(strpos($contracts, 'D2 Module version                  | **CLOSED**') !== false, 'CONTRACTS.md D2 closed');
mtuc_assert(
    strpos($contracts, 'D3 Secrets/certs                   | **CLOSED (Phase 2 verified)**') !== false
        || strpos($contracts, 'D3 Secrets/certs                   | **CLOSED FOR PHASE 0**') !== false,
    'CONTRACTS.md D3 closed'
);
mtuc_assert(strpos($contracts, 'D4 CP/SmartUCF env + OC3 callbacks | **CLOSED FOR PHASE 0**') !== false, 'CONTRACTS.md D4 closed');
mtuc_assert(strpos($contracts, 'Phase 0 STOP GATE:** **PASS**') !== false, 'CONTRACTS.md STOP GATE PASS');

$forbiddenTree = array(
    MTUC_PHASE0_ROOT . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'payment' . DIRECTORY_SEPARATOR . 'mt_uni_credit.php',
);
foreach ($forbiddenTree as $path) {
    mtuc_assert(!is_file($path), 'Phase 0/1 must not create storefront payment controller: ' . str_replace(MTUC_PHASE0_ROOT . DIRECTORY_SEPARATOR, '', $path));
}
$installXml = MTUC_PHASE0_ROOT . DIRECTORY_SEPARATOR . 'install.xml';
$phase1Admin = MTUC_PHASE0_ROOT . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'payment' . DIRECTORY_SEPARATOR . 'mt_uni_credit.php';
if (is_file($installXml)) {
    if (is_file($phase1Admin)) {
        mtuc_assert(filesize($installXml) > 0, 'Phase 1 install.xml must be populated');
    } else {
        mtuc_assert(filesize($installXml) === 0, 'baseline install.xml placeholder must stay empty before Phase 1');
    }
} else {
    mtuc_assert(true, 'install.xml absent in Phase 0');
}

$syntax = mtuc_phase0_load_fixture('forbidden_php_syntax.json');
$phpFiles = array();
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(MTUC_PHASE0_ROOT, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    $path = $file->getPathname();
    if (strpos($path, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR) !== false) {
        continue;
    }
    if (strpos($path, DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR) !== false) {
        continue;
    }
    if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
        $phpFiles[] = $path;
    }
}

foreach ($phpFiles as $path) {
    $src = file_get_contents($path);
    mtuc_assert(!mtuc_phase0_contains_live_remote_host($src), 'no live-network call in ' . str_replace(MTUC_PHASE0_ROOT . DIRECTORY_SEPARATOR, '', $path));
    foreach ($syntax['patterns'] as $pattern) {
        if (preg_match('/' . $pattern['regex'] . '/m', $src)) {
            mtuc_assert(false, 'forbidden syntax ' . $pattern['id'] . ' in ' . str_replace(MTUC_PHASE0_ROOT . DIRECTORY_SEPARATOR, '', $path));
        }
    }
}

$workspaceParent = dirname(MTUC_PHASE0_ROOT);
foreach (array('reference-uni-oc4', 'reference-jet-oc3', 'reference-oc3-core', 'reference-oc3-store') as $ref) {
    mtuc_assert(is_dir($workspaceParent . DIRECTORY_SEPARATOR . $ref) || is_dir(dirname($workspaceParent) . DIRECTORY_SEPARATOR . $ref), 'reference directory present for read: ' . $ref);
}

echo PHP_EOL;
if ($failures) {
    echo 'FAILED ' . count($failures) . ' / ' . ($passes + count($failures)) . PHP_EOL;
    foreach ($failures as $f) {
        echo ' - ' . $f . PHP_EOL;
    }
    exit(1);
}

echo 'OK ' . $passes . ' checks' . PHP_EOL;
exit(0);
