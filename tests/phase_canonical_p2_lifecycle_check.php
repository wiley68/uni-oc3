<?php

/**
 * Canonical Process 2 target-first lifecycle.
 * Run: php tests/phase_canonical_p2_lifecycle_check.php
 */
require_once __DIR__ . '/bootstrap.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DIR_STORAGE')) {
    mtuc_test_define_dir_storage('mtuc-canonical-p2');
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
require_once __DIR__ . '/support/phase9_harness.php';
require_once __DIR__ . '/support/recording_process_two_mailer.php';
require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

$failures = array();
$passes = 0;

function mtucCanonP2_assert(bool $condition, string $message): void
{
    global $failures, $passes;
    CanonicalTestHarness::assert($condition, $message, $failures, $passes);
}

$p2 = MtUniCreditBankStatus::process2Sent();

// Target before local via admitTarget (no PATCH yet).
$memoryDb = new Phase2MemoryDb();
$attempt = $memoryDb->seedFinancingAttempt(array(
    'store_id' => Phase5TestHarness::STORE_A,
    'order_id' => 97001,
    'unicid' => Phase4TestHarness::TEST_UNICID,
));
$port = new CanonicalRecordingStatusPort();
$sync = CanonicalTestHarness::statusSync($memoryDb, $port);
$admit = $sync->admitTarget((int) $attempt['attempt_id'], $p2['status_id'], $p2['status_label']);
mtucCanonP2_assert($admit === MtUniCreditControlPanelStatusSyncService::ADMIT, 'P2 target admitted before local');
mtucCanonP2_assert(count($port->calls) === 0, 'no PATCH before local P2 handoff');

// Happy path Process 2: local bank_sent_process2 + one PATCH.
$transportOk = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportOk);
$mailer = new MtUniCreditRecordingProcessTwoMailer();
$stackOk = Phase9TestHarness::stack(
    $transportOk,
    null,
    null,
    Phase5TestHarness::STORE_A,
    array('uni_proces' => 1)
);
$stackOk['process2Mailer'] = $mailer;
$orderOk = 97010;
Phase9TestHarness::seedBankOrder($stackOk['memoryDb'], $orderOk, $stackOk['storeId']);
$resultOk = $stackOk['submission']->submit(Phase9TestHarness::submitInputProcess2($orderOk, $stackOk['storeId']));
mtucCanonP2_assert(!empty($resultOk['success']), 'P2 happy path succeeds');
mtucCanonP2_assert(
    Phase9TestHarness::bankStatusId($stackOk, $orderOk) === MtUniCreditBankStatus::SENT_PROCESS2,
    'local bank_sent_process2 written'
);
mtucCanonP2_assert(Phase9TestHarness::countStatusPatches($transportOk) === 1, 'exactly one CP PATCH');
mtucCanonP2_assert(Phase9TestHarness::smartUcfCallCount($stackOk['smartUcfProbe']) === 0, 'P2: zero SmartUCF');
$attemptOk = $stackOk['attempts']->findByStoreOrder($stackOk['storeId'], $orderOk);
$sensitiveRow = (new MtUniCreditProcessTwoLifecycleRepository($stackOk['db']))->findByAttempt((int) $attemptOk['attempt_id']);
mtucCanonP2_assert(
    array_key_exists('process2_sensitive_enc', $sensitiveRow)
        && $sensitiveRow['process2_sensitive_enc'] === null
        && array_key_exists('process2_sensitive_created_at', $sensitiveRow)
        && $sensitiveRow['process2_sensitive_created_at'] === null,
    'P2 reaches bank_sent_process2 with both sensitive columns SQL NULL'
);
$snapshot = MtUniCreditApplicationSnapshot::decode($attemptOk['application_snapshot_json']);
mtucCanonP2_assert(
    is_array($snapshot) && is_array($snapshot['customer'])
        && array_keys($snapshot['customer']) === array('name', 'firstname', 'lastname', 'phone', 'email', 'address', 'address2'),
    'P2 application snapshot uses the existing customer allowlist'
);
$wire = array();
foreach ($transportOk->requests as $request) {
    $path = parse_url($request['url'], PHP_URL_PATH);
    if (substr($path, -7) === '/orders' || substr($path, -14) === '/orders/status') {
        $wire[$request['method']] = $request['payload'];
    }
}
$transportLegacy = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportLegacy);
$stackLegacy = Phase9TestHarness::stack(
    $transportLegacy, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1)
);
Phase9TestHarness::seedBankOrder($stackLegacy['memoryDb'], $orderOk, $stackLegacy['storeId']);
$legacyInput = Phase9TestHarness::submitInputProcess2($orderOk, $stackLegacy['storeId']);
$legacyInput['process2'] = array('egn' => 'obsolete-scalar', 'phone2' => array('nested' => array('obsolete')));
$legacyInput['unipayment_egn'] = array('obsolete');
mtucCanonP2_assert(!empty($stackLegacy['submission']->submit($legacyInput)['success']), 'P2 ignores obsolete scalar and nested inputs');
$legacyWire = array();
foreach ($transportLegacy->requests as $request) {
    $path = parse_url($request['url'], PHP_URL_PATH);
    if (substr($path, -7) === '/orders' || substr($path, -14) === '/orders/status') {
        $legacyWire[$request['method']] = $request['payload'];
    }
}
mtucCanonP2_assert(
    isset($wire['POST'], $wire['PATCH']) && $wire === $legacyWire
        && $wire['POST']['version'] === MtUniCreditConstants::VERSION
        && $wire['PATCH'] === array('order_id' => (string) $orderOk, 'status' => $p2['status_label'], 'status_id' => $p2['status_id']),
    'P2 exact CP create and status bodies are identical with obsolete inputs'
);
$targetOk = (new MtUniCreditControlPanelStatusSyncService(
    new MtUniCreditControlPanelStatusSyncRepository($stackOk['db']),
    $stackOk['client']
))->readPersistedTarget((int) $attemptOk['attempt_id']);
mtucCanonP2_assert(
    is_array($targetOk) && $targetOk['status_id'] === $p2['status_id'],
    'durable P2 target persisted'
);

// PATCH failure preserves pending (no repeated handoff create).
$transportFail = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportFail);
$transportFail->failStatusPatch = true;
$stackFail = Phase9TestHarness::stack(
    $transportFail,
    null,
    null,
    Phase5TestHarness::STORE_A,
    array('uni_proces' => 1)
);
$orderFail = 97020;
Phase9TestHarness::seedBankOrder($stackFail['memoryDb'], $orderFail, $stackFail['storeId']);
$resultFail = $stackFail['submission']->submit(Phase9TestHarness::submitInputProcess2($orderFail, $stackFail['storeId']));
mtucCanonP2_assert(!empty($resultFail['success']), 'P2 local handoff can succeed even when PATCH fails');
$attemptFail = $stackFail['attempts']->findByStoreOrder($stackFail['storeId'], $orderFail);
$targetFail = (new MtUniCreditControlPanelStatusSyncService(
    new MtUniCreditControlPanelStatusSyncRepository($stackFail['db']),
    $stackFail['client']
))->readPersistedTarget((int) $attemptFail['attempt_id']);
mtucCanonP2_assert(
    is_array($targetFail)
        && $targetFail['state'] === MtUniCreditControlPanelStatusSyncStates::PENDING
        && $targetFail['status_id'] === $p2['status_id'],
    'PATCH failure preserves pending P2 target'
);
$createsFail = Phase7TestHarness::countOrderPosts($transportFail);
$stackFail['submission']->submit(Phase9TestHarness::submitInputProcess2($orderFail, $stackFail['storeId']));
mtucCanonP2_assert(
    Phase7TestHarness::countOrderPosts($transportFail) === $createsFail,
    'no repeated CP create / handoff on replay'
);

// Prepared replay + mail ownership.
$transportMail = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportMail);
$mailerMail = new MtUniCreditRecordingProcessTwoMailer();
$stackMail = Phase9TestHarness::stack(
    $transportMail,
    null,
    null,
    Phase5TestHarness::STORE_A,
    array('uni_proces' => 1)
);
// Inject recording mailer into process2 coordinator if stack exposes it.
if (isset($stackMail['process2Mailer']) && $stackMail['process2Mailer'] instanceof MtUniCreditRecordingProcessTwoMailer) {
    $mailerMail = $stackMail['process2Mailer'];
}
$orderMail = 97030;
Phase9TestHarness::seedBankOrder($stackMail['memoryDb'], $orderMail, $stackMail['storeId']);
$firstMail = $stackMail['submission']->submit(Phase9TestHarness::submitInputProcess2($orderMail, $stackMail['storeId']));
mtucCanonP2_assert(!empty($firstMail['success']), 'P2 mail path first submit succeeds');
$attemptMail = $stackMail['attempts']->findByStoreOrder($stackMail['storeId'], $orderMail);
$p2Row = (new MtUniCreditProcessTwoLifecycleRepository($stackMail['db']))->findByAttempt((int) $attemptMail['attempt_id']);
mtucCanonP2_assert(
    is_array($p2Row)
        && (string) $p2Row['process2_state'] === MtUniCreditProcessTwoLifecycleStates::PREPARED,
    'prepared state after P2 handoff'
);
$secondMail = $stackMail['submission']->submit(Phase9TestHarness::submitInputProcess2($orderMail, $stackMail['storeId']));
mtucCanonP2_assert(
    !empty($secondMail['success']) || (isset($secondMail['process2_state']) && $secondMail['process2_state'] === MtUniCreditProcessTwoLifecycleStates::PREPARED),
    'prepared replay remains success/prepared'
);
mtucCanonP2_assert(
    Phase7TestHarness::countOrderPosts($transportMail) === 1,
    'prepared replay: still one CP create'
);

echo PHP_EOL . 'canonical p2 lifecycle: ' . $passes . ' passed, ' . count($failures) . ' failed' . PHP_EOL;
exit(count($failures) === 0 ? 0 : 1);
