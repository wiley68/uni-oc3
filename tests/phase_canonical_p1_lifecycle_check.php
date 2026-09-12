<?php

/**
 * Canonical Process 1 target-first lifecycle.
 * Run: php tests/phase_canonical_p1_lifecycle_check.php
 */
require_once __DIR__ . '/bootstrap.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DIR_STORAGE')) {
    mtuc_test_define_dir_storage('mtuc-canonical-p1');
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
require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

$failures = array();
$passes = 0;

function mtucCanonP1_assert(bool $condition, string $message): void
{
    global $failures, $passes;
    CanonicalTestHarness::assert($condition, $message, $failures, $passes);
}

$p1 = MtUniCreditBankStatus::process1Sent();
$p2 = MtUniCreditBankStatus::process2Sent();

// Target before local: admit → pending, no PATCH yet.
$memoryDb = new Phase2MemoryDb();
$attempt = $memoryDb->seedFinancingAttempt(array(
    'store_id' => Phase5TestHarness::STORE_A,
    'order_id' => 96001,
    'unicid' => Phase4TestHarness::TEST_UNICID,
));
$port = new CanonicalRecordingStatusPort();
$sync = CanonicalTestHarness::statusSync($memoryDb, $port);
$admit = $sync->admitTarget((int) $attempt['attempt_id'], $p1['status_id'], $p1['status_label']);
mtucCanonP1_assert($admit === MtUniCreditControlPanelStatusSyncService::ADMIT, 'target admitted before local');
mtucCanonP1_assert(count($port->calls) === 0, 'no PATCH before local handoff');

// Local fail blocks PATCH.
$memoryDb->throwOnBankStatusInsert = true;
$localBlocked = false;
try {
    (new MtUniCreditOrderBankStatusRepository(new MtUniCreditDbAdapter($memoryDb, 'oc_')))
        ->upsertAuthorizedLocal(
            Phase5TestHarness::STORE_A,
            96001,
            $p1['status_id'],
            $p1['status_label'],
            MtUniCreditBankStatusTransitionPolicy::SOURCE_LOCAL_LIFECYCLE
        );
} catch (Exception $exception) {
    $localBlocked = true;
}
$memoryDb->throwOnBankStatusInsert = false;
mtucCanonP1_assert($localBlocked, 'local bank write can fail closed');
mtucCanonP1_assert(count($port->calls) === 0, 'local fail blocks PATCH');
$stillPending = $sync->readPersistedTarget((int) $attempt['attempt_id']);
mtucCanonP1_assert(
    is_array($stillPending) && $stillPending['state'] === MtUniCreditControlPanelStatusSyncStates::PENDING,
    'pending target retained after local fail'
);

// PATCH fail leaves pending.
$port->handler = function () {
    throw new MtUniCreditCpTimeoutException('patch timeout');
};
$patchFailState = $sync->retryPending((int) $attempt['attempt_id'], '96001');
mtucCanonP1_assert(
    $patchFailState === MtUniCreditControlPanelStatusSyncStates::PENDING,
    'PATCH fail leaves pending'
);

// Happy path Process 1 + replay without second CP/SmartUCF.
$transportOk = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportOk);
$stackOk = Phase9TestHarness::stack($transportOk);
$orderOk = 96010;
Phase9TestHarness::seedBankOrder($stackOk['memoryDb'], $orderOk, $stackOk['storeId']);
$resultOk = $stackOk['submission']->submit(Phase9TestHarness::submitInput($orderOk, $stackOk['storeId']));
mtucCanonP1_assert(!empty($resultOk['success']), 'P1 happy path submit succeeds');
$attemptOk = $stackOk['attempts']->findByStoreOrder($stackOk['storeId'], $orderOk);
$syncOk = new MtUniCreditControlPanelStatusSyncService(
    new MtUniCreditControlPanelStatusSyncRepository($stackOk['db']),
    $stackOk['client']
);
$targetOk = $syncOk->readPersistedTarget((int) $attemptOk['attempt_id']);
mtucCanonP1_assert(
    is_array($targetOk)
        && in_array(
            $targetOk['state'],
            array(
                MtUniCreditControlPanelStatusSyncStates::PENDING,
                MtUniCreditControlPanelStatusSyncStates::CONFIRMED,
            ),
            true
        )
        && $targetOk['status_id'] === $p1['status_id'],
    'P1 durable target pending/confirmed after success'
);
mtucCanonP1_assert(
    Phase9TestHarness::bankStatusId($stackOk, $orderOk) === MtUniCreditBankStatus::SENT_PROCESS1,
    'local bank_sent_process1 written'
);
$createsAfterOk = Phase7TestHarness::countOrderPosts($transportOk);
$smartAfterOk = Phase9TestHarness::smartUcfCallCount($stackOk['smartUcfProbe']);
$patchesAfterOk = Phase9TestHarness::countStatusPatches($transportOk);

$resultReplay = $stackOk['submission']->submit(Phase9TestHarness::submitInput($orderOk, $stackOk['storeId']));
mtucCanonP1_assert(!empty($resultReplay['success']) || !empty($resultReplay['bank_redirect']), 'P1 replay remains successful path');
mtucCanonP1_assert(Phase7TestHarness::countOrderPosts($transportOk) === $createsAfterOk, 'replay: no second CP create');
mtucCanonP1_assert(
    Phase9TestHarness::smartUcfCallCount($stackOk['smartUcfProbe']) === $smartAfterOk,
    'replay: no second SmartUCF'
);
mtucCanonP1_assert(
    Phase9TestHarness::countStatusPatches($transportOk) >= $patchesAfterOk,
    'replay may retry pending PATCH only'
);

// Missing-target recovery after CREATED SmartUCF.
$transportRec = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportRec);
$stackRec = Phase9TestHarness::stack($transportRec);
$orderRec = 96020;
Phase9TestHarness::seedBankOrder($stackRec['memoryDb'], $orderRec, $stackRec['storeId']);
$stackRec['submission']->submit(Phase9TestHarness::submitInput($orderRec, $stackRec['storeId']));
$attemptRec = $stackRec['attempts']->findByStoreOrder($stackRec['storeId'], $orderRec);
$table = $stackRec['db']->getPrefix() . MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT;
$stackRec['db']->query(
    "UPDATE `{$table}` SET"
        . " `cp_status_sync_state` = '" . MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED . "',"
        . " `cp_status_sync_status_id` = NULL,"
        . " `cp_status_sync_status` = NULL"
        . " WHERE `attempt_id` = " . (int) $attemptRec['attempt_id']
);
$recovered = $stackRec['process1']->recoverMissingProcess1TargetAfterCreatedSmartUcf(
    (int) $attemptRec['attempt_id'],
    $stackRec['storeId'],
    $orderRec,
    Phase4TestHarness::TEST_UNICID,
    $stackRec['bankStatuses']
);
mtucCanonP1_assert($recovered === true, 'missing-target recovery returns true');
$targetRec = (new MtUniCreditControlPanelStatusSyncService(
    new MtUniCreditControlPanelStatusSyncRepository($stackRec['db']),
    $stackRec['client']
))->readPersistedTarget((int) $attemptRec['attempt_id']);
mtucCanonP1_assert(
    is_array($targetRec) && $targetRec['status_id'] === $p1['status_id'],
    'missing-target recovery re-admits P1'
);

$cpPostsBeforeRec = Phase7TestHarness::countOrderPosts($transportRec);
$smartBeforeRec = Phase9TestHarness::smartUcfCallCount($stackRec['smartUcfProbe']);
$stackRec['process1']->recoverMissingProcess1TargetAfterCreatedSmartUcf(
    (int) $attemptRec['attempt_id'],
    $stackRec['storeId'],
    $orderRec,
    Phase4TestHarness::TEST_UNICID,
    $stackRec['bankStatuses']
);
mtucCanonP1_assert(
    Phase7TestHarness::countOrderPosts($transportRec) === $cpPostsBeforeRec,
    'recovery no second CP create'
);
mtucCanonP1_assert(
    Phase9TestHarness::smartUcfCallCount($stackRec['smartUcfProbe']) === $smartBeforeRec,
    'recovery no second SmartUCF session'
);

// Independent current-shop UNICID: wrong / missing / empty attempt must fail closed.
// Reset to exact post-SmartUCF / pre-admission window before each denial probe.
$resetMissingTargetWindow = function () use ($stackRec, $table, $attemptRec) {
    $stackRec['db']->query(
        "UPDATE `{$table}` SET"
            . " `cp_status_sync_state` = '" . MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED . "',"
            . " `cp_status_sync_status_id` = NULL,"
            . " `cp_status_sync_status` = NULL,"
            . " `unicid` = '" . $stackRec['db']->escape(Phase4TestHarness::TEST_UNICID) . "'"
            . " WHERE `attempt_id` = " . (int) $attemptRec['attempt_id']
    );
};

$resetMissingTargetWindow();
$wrongAuth = $stackRec['process1']->recoverMissingProcess1TargetAfterCreatedSmartUcf(
    (int) $attemptRec['attempt_id'],
    $stackRec['storeId'],
    $orderRec,
    'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
    $stackRec['bankStatuses']
);
mtucCanonP1_assert($wrongAuth === false, 'wrong current-shop UNICID → recovery denied');
$targetWrong = (new MtUniCreditControlPanelStatusSyncService(
    new MtUniCreditControlPanelStatusSyncRepository($stackRec['db']),
    $stackRec['client']
))->readPersistedTarget((int) $attemptRec['attempt_id']);
mtucCanonP1_assert(
    is_array($targetWrong)
        && (string) $targetWrong['state'] === MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED,
    'wrong current-shop UNICID → no target admitted'
);

$resetMissingTargetWindow();
$missingAuth = $stackRec['process1']->recoverMissingProcess1TargetAfterCreatedSmartUcf(
    (int) $attemptRec['attempt_id'],
    $stackRec['storeId'],
    $orderRec,
    '',
    $stackRec['bankStatuses']
);
mtucCanonP1_assert($missingAuth === false, 'missing current-shop UNICID → recovery denied');

$stackRec['db']->query(
    "UPDATE `{$table}` SET"
        . " `cp_status_sync_state` = '" . MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED . "',"
        . " `cp_status_sync_status_id` = NULL,"
        . " `cp_status_sync_status` = NULL,"
        . " `unicid` = ''"
        . " WHERE `attempt_id` = " . (int) $attemptRec['attempt_id']
);
$rowCleared = $stackRec['attempts']->findById((int) $attemptRec['attempt_id']);
mtucCanonP1_assert(
    is_array($rowCleared) && trim((string) (isset($rowCleared['unicid']) ? $rowCleared['unicid'] : 'x')) === '',
    'attempt UNICID cleared for legacy-null probe'
);
$emptyAttempt = $stackRec['process1']->recoverMissingProcess1TargetAfterCreatedSmartUcf(
    (int) $attemptRec['attempt_id'],
    $stackRec['storeId'],
    $orderRec,
    Phase4TestHarness::TEST_UNICID,
    $stackRec['bankStatuses']
);
mtucCanonP1_assert($emptyAttempt === false, 'empty attempt UNICID → recovery denied');
$targetEmpty = (new MtUniCreditControlPanelStatusSyncService(
    new MtUniCreditControlPanelStatusSyncRepository($stackRec['db']),
    $stackRec['client']
))->readPersistedTarget((int) $attemptRec['attempt_id']);
mtucCanonP1_assert(
    is_array($targetEmpty)
        && (string) $targetEmpty['state'] === MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED
        && $targetEmpty['status_id'] === null,
    'empty attempt UNICID leaves no admitted target'
);

// Production entry: lifecycle/run uses credentials UNICID, not attempt-row self-reference.
$transportProd = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportProd);
$stackProd = Phase9TestHarness::stack($transportProd);
$orderProd = 96021;
Phase9TestHarness::seedBankOrder($stackProd['memoryDb'], $orderProd, $stackProd['storeId']);
$stackProd['submission']->submit(Phase9TestHarness::submitInput($orderProd, $stackProd['storeId']));
$attemptProd = $stackProd['attempts']->findByStoreOrder($stackProd['storeId'], $orderProd);
$tableProd = $stackProd['db']->getPrefix() . MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT;
$stackProd['db']->query(
    "UPDATE `{$tableProd}` SET"
        . " `cp_status_sync_state` = '" . MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED . "',"
        . " `cp_status_sync_status_id` = NULL,"
        . " `cp_status_sync_status` = NULL"
        . " WHERE `attempt_id` = " . (int) $attemptProd['attempt_id']
);
$configured = $stackProd['client']->getConfiguredUnicid();
mtucCanonP1_assert(
    $configured === Phase4TestHarness::TEST_UNICID && $configured !== '',
    'production credentials expose independent current-shop UNICID'
);
$shop = mtuc4_valid_shop_snapshot();
$orderRow = Phase7TestHarness::orderRow($orderProd, $stackProd['storeId']);
$calcProd = (new MtUniCreditCalculator())->calculateScheme(
    $shop,
    100.0,
    new MtUniCreditAvailableScheme(
        'standard',
        'KOPSTD',
        12,
        0,
        array(),
        array('coeff' => 1.05, 'interestPercent' => 5.5, 'installmentCount' => 12, 'onlineProductCode' => 'KOPSTD')
    ),
    0.0
);
$replay = $stackProd['process1']->run(
    (int) $attemptProd['attempt_id'],
    $shop,
    $orderRow,
    Phase7TestHarness::orderProducts(),
    $calcProd,
    $orderProd,
    (int) $attemptProd['control_panel_order_id'],
    $stackProd['bankStatuses'],
    $configured
);
mtucCanonP1_assert($replay->isCreated(), 'production replay with independent shop UNICID succeeds created path');
$targetProd = (new MtUniCreditControlPanelStatusSyncService(
    new MtUniCreditControlPanelStatusSyncRepository($stackProd['db']),
    $stackProd['client']
))->readPersistedTarget((int) $attemptProd['attempt_id']);
mtucCanonP1_assert(
    is_array($targetProd) && $targetProd['status_id'] === $p1['status_id'],
    'correct current-shop UNICID → recovery allowed via run()'
);

$stackProd['db']->query(
    "UPDATE `{$tableProd}` SET"
        . " `cp_status_sync_state` = '" . MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED . "',"
        . " `cp_status_sync_status_id` = NULL,"
        . " `cp_status_sync_status` = NULL"
        . " WHERE `attempt_id` = " . (int) $attemptProd['attempt_id']
);
$deniedRun = $stackProd['process1']->run(
    (int) $attemptProd['attempt_id'],
    $shop,
    $orderRow,
    Phase7TestHarness::orderProducts(),
    $calcProd,
    $orderProd,
    (int) $attemptProd['control_panel_order_id'],
    $stackProd['bankStatuses'],
    'cccccccc-cccc-cccc-cccc-cccccccccccc'
);
mtucCanonP1_assert($deniedRun->isCreated(), 'wrong UNICID still returns created SmartUCF result');
$targetDenied = (new MtUniCreditControlPanelStatusSyncService(
    new MtUniCreditControlPanelStatusSyncRepository($stackProd['db']),
    $stackProd['client']
))->readPersistedTarget((int) $attemptProd['attempt_id']);
mtucCanonP1_assert(
    is_array($targetDenied)
        && (string) $targetDenied['state'] === MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED,
    'wrong current-shop UNICID → no target/local/PATCH on replay'
);

// Internal 13/14 order_id boundaries (no truncation).
mtucCanonP1_assert(
    MtUniCreditShopOrderId::tryNormalize('1234567890123') === '1234567890123',
    'internal P1 path accepts 13-char order_id'
);
mtucCanonP1_assert(
    MtUniCreditShopOrderId::tryNormalize('12345678901234') === null,
    'internal P1 path rejects 14-char order_id'
);
$srcCoord = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'smart_ucf_session_coordinator.php');
$srcP2 = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'process_two_lifecycle_coordinator.php');
$srcSync = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'control_panel_status_sync_service.php');
mtucCanonP1_assert(
    strpos($srcCoord, 'substr((string) $localOrderId, 0, 13)') === false
        && strpos($srcP2, 'substr((string) $localOrderId, 0, 13)') === false
        && strpos($srcSync, 'substr($orderReference, 0, 13)') === false,
    'internal order_id truncation absent from P1/P2/status-sync'
);

// Wrong ownership denied.
$wrongOwned = 96030;
$stackOk['memoryDb']->seedOrder($wrongOwned, $stackOk['storeId']);
$stackOk['memoryDb']->seedFinancingAttempt(array(
    'store_id' => $stackOk['storeId'],
    'order_id' => $wrongOwned,
    'unicid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
));
$wrong = CanonicalTestHarness::invokeOrderBankStatus(array(
    'operation' => MtUniCreditInboundApiOperations::ORDER_BANK_STATUS,
    'order_id' => (string) $wrongOwned,
    'status_id' => MtUniCreditBankStatus::SENT_PROCESS1,
    'status' => 'P1',
), Phase6TestHarness::stack($stackOk['memoryDb'], $stackOk['storeId']));
mtucCanonP1_assert($wrong['status'] === 404, 'wrong ownership denied');

// P2 conflict against admitted P1 target.
$conflictMem = new Phase2MemoryDb();
$conflictAttempt = $conflictMem->seedFinancingAttempt(array(
    'store_id' => Phase5TestHarness::STORE_A,
    'order_id' => 96040,
));
$conflictSync = CanonicalTestHarness::statusSync($conflictMem, new CanonicalRecordingStatusPort());
$conflictSync->admitTarget((int) $conflictAttempt['attempt_id'], $p1['status_id'], $p1['status_label']);
$p2Conflict = $conflictSync->admitTarget(
    (int) $conflictAttempt['attempt_id'],
    $p2['status_id'],
    $p2['status_label']
);
mtucCanonP1_assert(
    $p2Conflict === MtUniCreditControlPanelStatusSyncService::CONFLICT,
    'P2 conflict against P1 target'
);

echo PHP_EOL . 'canonical p1 lifecycle: ' . $passes . ' passed, ' . count($failures) . ' failed' . PHP_EOL;
exit(count($failures) === 0 ? 0 : 1);
