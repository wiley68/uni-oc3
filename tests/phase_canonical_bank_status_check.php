<?php

/**
 * Canonical inbound bank-status: strict types, max13, P1↔P2 CONFLICT, replay.
 * Run: php tests/phase_canonical_bank_status_check.php
 */
require_once __DIR__ . '/bootstrap.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DIR_STORAGE')) {
    mtuc_test_define_dir_storage('mtuc-canonical-bank');
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
require_once __DIR__ . '/support/phase6_harness.php';
require_once __DIR__ . '/support/phase7_harness.php';
require_once __DIR__ . '/support/canonical_harness.php';


$failures = array();
$passes = 0;

function mtucCanonBank_assert(bool $condition, string $message): void
{
    global $failures, $passes;
    CanonicalTestHarness::assert($condition, $message, $failures, $passes);
}

/**
 * @param array<string, mixed> $stack
 * @param int $orderId
 * @return void
 */
function mtucCanonBank_seed(array $stack, int $orderId): void
{
    $stack['memoryDb']->seedOrder($orderId, $stack['storeId']);
    $stack['memoryDb']->seedFinancingAttempt(array(
        'store_id' => $stack['storeId'],
        'order_id' => $orderId,
        'unicid' => $stack['unicid'],
    ));
}

$stack = Phase6TestHarness::stack();
$orderId = 92001;
mtucCanonBank_seed($stack, $orderId);

$nonString = CanonicalTestHarness::invokeOrderBankStatus(array(
    'operation' => MtUniCreditInboundApiOperations::ORDER_BANK_STATUS,
    'order_id' => $orderId,
    'status_id' => MtUniCreditBankStatus::SENT_PROCESS1,
    'status' => 'label',
), $stack);
mtucCanonBank_assert(
    $nonString['status'] === 400
        && is_array($nonString['payload'])
        && $nonString['payload']['error'] === 'invalid_payload',
    'non-string order_id rejected'
);

$statusInt = CanonicalTestHarness::invokeOrderBankStatus(array(
    'operation' => MtUniCreditInboundApiOperations::ORDER_BANK_STATUS,
    'order_id' => (string) $orderId,
    'status_id' => 7,
    'status' => 'label',
), $stack);
mtucCanonBank_assert(
    $statusInt['status'] === 400
        && is_array($statusInt['payload'])
        && $statusInt['payload']['error'] === 'invalid_payload',
    'non-string status_id rejected'
);

$max13 = str_repeat('9', 13);
$stack['memoryDb']->seedOrder((int) $max13, $stack['storeId']);
$stack['memoryDb']->seedFinancingAttempt(array(
    'store_id' => $stack['storeId'],
    'order_id' => (int) $max13,
    'unicid' => $stack['unicid'],
));
$ok13 = CanonicalTestHarness::invokeOrderBankStatus(array(
    'operation' => MtUniCreditInboundApiOperations::ORDER_BANK_STATUS,
    'order_id' => $max13,
    'status_id' => MtUniCreditBankStatus::SENT_PROCESS1,
    'status' => 'P1',
), $stack);
mtucCanonBank_assert($ok13['status'] === 200, '13-char order_id accepted');

$tooLong = CanonicalTestHarness::invokeOrderBankStatus(array(
    'operation' => MtUniCreditInboundApiOperations::ORDER_BANK_STATUS,
    'order_id' => str_repeat('9', 14),
    'status_id' => MtUniCreditBankStatus::SENT_PROCESS1,
    'status' => 'P1',
), $stack);
mtucCanonBank_assert(
    $tooLong['status'] === 400
        && is_array($tooLong['payload'])
        && $tooLong['payload']['error'] === 'invalid_payload',
    '14-char order_id rejected'
);

$p1Order = 92010;
mtucCanonBank_seed($stack, $p1Order);
$p1 = CanonicalTestHarness::invokeOrderBankStatus(array(
    'operation' => MtUniCreditInboundApiOperations::ORDER_BANK_STATUS,
    'order_id' => (string) $p1Order,
    'status_id' => MtUniCreditBankStatus::SENT_PROCESS1,
    'status' => 'P1',
), $stack);
mtucCanonBank_assert($p1['status'] === 200, 'P1 applied');

$p1ToP2 = CanonicalTestHarness::invokeOrderBankStatus(array(
    'operation' => MtUniCreditInboundApiOperations::ORDER_BANK_STATUS,
    'order_id' => (string) $p1Order,
    'status_id' => MtUniCreditBankStatus::SENT_PROCESS2,
    'status' => 'P2',
), $stack);
mtucCanonBank_assert(
    $p1ToP2['status'] === 409
        && is_array($p1ToP2['payload'])
        && $p1ToP2['payload']['error'] === 'semantic_conflict',
    'P1→P2 CONFLICT'
);

$p2Order = 92020;
mtucCanonBank_seed($stack, $p2Order);
$p2 = CanonicalTestHarness::invokeOrderBankStatus(array(
    'operation' => MtUniCreditInboundApiOperations::ORDER_BANK_STATUS,
    'order_id' => (string) $p2Order,
    'status_id' => MtUniCreditBankStatus::SENT_PROCESS2,
    'status' => 'P2',
), $stack);
mtucCanonBank_assert($p2['status'] === 200, 'P2 applied');

$p2ToP1 = CanonicalTestHarness::invokeOrderBankStatus(array(
    'operation' => MtUniCreditInboundApiOperations::ORDER_BANK_STATUS,
    'order_id' => (string) $p2Order,
    'status_id' => MtUniCreditBankStatus::SENT_PROCESS1,
    'status' => 'P1',
), $stack);
mtucCanonBank_assert(
    $p2ToP1['status'] === 409
        && is_array($p2ToP1['payload'])
        && $p2ToP1['payload']['error'] === 'semantic_conflict',
    'P2→P1 CONFLICT'
);

$replay = CanonicalTestHarness::invokeOrderBankStatus(array(
    'operation' => MtUniCreditInboundApiOperations::ORDER_BANK_STATUS,
    'order_id' => (string) $p2Order,
    'status_id' => MtUniCreditBankStatus::SENT_PROCESS2,
    'status' => 'P2',
), $stack);
mtucCanonBank_assert(
    $replay['status'] === 200
        && is_array($replay['payload'])
        && isset($replay['payload']['data']['applied'])
        && $replay['payload']['data']['applied'] === false,
    'same status replay is idempotent (applied=false)'
);

echo PHP_EOL . 'canonical bank status: ' . $passes . ' passed, ' . count($failures) . ' failed' . PHP_EOL;
exit(count($failures) === 0 ? 0 : 1);
