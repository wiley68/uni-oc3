<?php

/**
 * Canonical SmartUCF debug ownership: P2 denied, wrong UNICID, missing lifecycle, ambiguous.
 * Run: php tests/phase_canonical_smartucf_debug_check.php
 */
require_once __DIR__ . '/bootstrap.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DIR_STORAGE')) {
    mtuc_test_define_dir_storage('mtuc-canonical-debug');
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

function mtucCanonDbg_assert(bool $condition, string $message): void
{
    global $failures, $passes;
    CanonicalTestHarness::assert($condition, $message, $failures, $passes);
}

$stack = Phase6TestHarness::stack();
$memoryDb = $stack['memoryDb'];
$db = $stack['db'];

$orderOk = 93001;
$memoryDb->seedOrder($orderOk, $stack['storeId']);
$memoryDb->seedFinancingAttempt(array(
    'store_id' => $stack['storeId'],
    'order_id' => $orderOk,
    'unicid' => $stack['unicid'],
    'smartucf_state' => MtUniCreditSmartUcfLifecycleStates::CREATED,
));
$diag = new MtUniCreditDiagnosticDebugLogRepository($db);
$diag->insert(
    $stack['storeId'],
    $orderOk,
    'checkout',
    'smartucf_submit',
    200,
    array(
        'type' => MtUniCreditDiagnosticJournal::TYPE_SMARTUCF_SESSION,
        'operation' => MtUniCreditDiagnosticJournal::OPERATION_SESSION_START,
        'outcome' => 'ok',
    )
);

$ok = CanonicalTestHarness::invokeSmartUcfDebug(array(
    'operation' => MtUniCreditInboundApiOperations::SMARTUCF_DEBUG_LOG,
    'order_id' => (string) $orderOk,
), $stack);
mtucCanonDbg_assert($ok['status'] === 200, 'P1 SmartUCF lifecycle debug allowed');

$orderP2 = 93002;
$memoryDb->seedOrder($orderP2, $stack['storeId']);
$memoryDb->seedFinancingAttempt(array(
    'store_id' => $stack['storeId'],
    'order_id' => $orderP2,
    'unicid' => $stack['unicid'],
    'smartucf_state' => MtUniCreditSmartUcfLifecycleStates::CREATED,
));
(new MtUniCreditOrderBankStatusRepository($db))->upsertAuthorizedLocal(
    $stack['storeId'],
    $orderP2,
    MtUniCreditBankStatus::SENT_PROCESS2,
    'P2',
    MtUniCreditBankStatusTransitionPolicy::SOURCE_LOCAL_LIFECYCLE
);
$p2Denied = CanonicalTestHarness::invokeSmartUcfDebug(array(
    'operation' => MtUniCreditInboundApiOperations::SMARTUCF_DEBUG_LOG,
    'order_id' => (string) $orderP2,
), $stack);
mtucCanonDbg_assert(
    $p2Denied['status'] === 404
        && is_array($p2Denied['payload'])
        && $p2Denied['payload']['error'] === 'order_not_found',
    'P2 bank status denies debug (opaque 404)'
);

$wrongUnicidStack = Phase6TestHarness::stack($memoryDb);
// Force authenticator UNICID mismatch by seeding attempt under different unicid while signing with stack secret.
$orderWrong = 93003;
$memoryDb->seedOrder($orderWrong, $stack['storeId']);
$memoryDb->seedFinancingAttempt(array(
    'store_id' => $stack['storeId'],
    'order_id' => $orderWrong,
    'unicid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
    'smartucf_state' => MtUniCreditSmartUcfLifecycleStates::CREATED,
));
$wrong = CanonicalTestHarness::invokeSmartUcfDebug(array(
    'operation' => MtUniCreditInboundApiOperations::SMARTUCF_DEBUG_LOG,
    'order_id' => (string) $orderWrong,
), $stack);
mtucCanonDbg_assert(
    $wrong['status'] === 404
        && is_array($wrong['payload'])
        && $wrong['payload']['error'] === 'order_not_found',
    'wrong UNICID ownership → opaque 404'
);

$orderMissing = 93004;
$memoryDb->seedOrder($orderMissing, $stack['storeId']);
$memoryDb->seedFinancingAttempt(array(
    'store_id' => $stack['storeId'],
    'order_id' => $orderMissing,
    'unicid' => $stack['unicid'],
    'smartucf_state' => MtUniCreditSmartUcfLifecycleStates::NOT_STARTED,
));
$missingLifecycle = CanonicalTestHarness::invokeSmartUcfDebug(array(
    'operation' => MtUniCreditInboundApiOperations::SMARTUCF_DEBUG_LOG,
    'order_id' => (string) $orderMissing,
), $stack);
mtucCanonDbg_assert(
    $missingLifecycle['status'] === 404,
    'missing SmartUCF lifecycle → opaque 404'
);

$orderAmb = 93005;
$memoryDb->seedOrder($orderAmb, $stack['storeId']);
$memoryDb->seedFinancingAttempt(array(
    'store_id' => $stack['storeId'],
    'order_id' => $orderAmb,
    'unicid' => $stack['unicid'],
    'smartucf_state' => MtUniCreditSmartUcfLifecycleStates::CREATED,
));
$memoryDb->seedFinancingAttempt(array(
    'store_id' => $stack['storeId'],
    'order_id' => $orderAmb,
    'unicid' => $stack['unicid'],
    'smartucf_state' => MtUniCreditSmartUcfLifecycleStates::CREATED,
    'operation_key_hash' => hash('sha256', 'amb-b'),
), true);
$amb = CanonicalTestHarness::invokeSmartUcfDebug(array(
    'operation' => MtUniCreditInboundApiOperations::SMARTUCF_DEBUG_LOG,
    'order_id' => (string) $orderAmb,
), $stack);
mtucCanonDbg_assert(
    $amb['status'] === 404
        && is_array($amb['payload'])
        && $amb['payload']['error'] === 'order_not_found',
    'ambiguous ownership → opaque 404 (no disclosure)'
);

echo PHP_EOL . 'canonical smartucf debug: ' . $passes . ' passed, ' . count($failures) . ' failed' . PHP_EOL;
exit(count($failures) === 0 ? 0 : 1);
