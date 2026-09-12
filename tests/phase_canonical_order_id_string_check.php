<?php

/**
 * OC3-FINAL-02 / OC3-GATE-01 / OC3-GATE-02: canonical order_id STRING + native 32-bit-safe conversion.
 * Run: php tests/phase_canonical_order_id_string_check.php
 */
require_once __DIR__ . '/bootstrap.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DIR_STORAGE')) {
    mtuc_test_define_dir_storage('mtuc-canonical-order-id-string');
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
}
if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'oc_');
}

require_once $lib . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . '/support/phase2_memory_db.php';
require_once __DIR__ . '/support/schema_ddl_memory.php';
require_once __DIR__ . '/support/phase4_harness.php';
require_once __DIR__ . '/support/phase5_harness.php';
require_once __DIR__ . '/support/phase6_harness.php';
require_once __DIR__ . '/support/phase7_harness.php';
require_once __DIR__ . '/support/canonical_harness.php';
require_once __DIR__ . '/support/phase9_harness.php';
require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

$failures = array();
$passes = 0;

function mtucCanonOid_assert(bool $condition, string $message): void
{
    global $failures, $passes;
    CanonicalTestHarness::assert($condition, $message, $failures, $passes);
}

$order13 = '1234567890123';
$storeId = Phase5TestHarness::STORE_A;
$unicid = Phase4TestHarness::TEST_UNICID;
$p1 = MtUniCreditBankStatus::process1Sent();
$p2 = MtUniCreditBankStatus::process2Sent();

// ---------------------------------------------------------------------------
// A. Persist 13-digit string attempt, read back, resolve exact string type
// ---------------------------------------------------------------------------
$memoryA = new Phase2MemoryDb();
$dbA = new MtUniCreditDbAdapter($memoryA, 'oc_');
$attemptsA = new MtUniCreditFinancingAttemptRepository($dbA);
$created = $attemptsA->findOrCreateAttempt(
    $storeId,
    $order13,
    $unicid,
    hash('sha256', 'oid-a-op'),
    hash('sha256', 'oid-a-sel'),
    hash('sha256', 'oid-a-fp'),
    MtUniCreditOperationEntryPoint::CHECKOUT
);
mtucCanonOid_assert(is_string($created['order_id']) && $created['order_id'] === $order13, 'A: attempt persists 13-digit string');
$reloaded = $attemptsA->findByStoreOrder($storeId, $order13);
mtucCanonOid_assert(
    $reloaded !== null && is_string($reloaded['order_id']) && $reloaded['order_id'] === $order13,
    'A: findByStoreOrder returns string order_id'
);
$resolvedA = (new MtUniCreditFinancingOrderResolver($dbA))->resolve($storeId, $unicid, $order13);
mtucCanonOid_assert(
    $resolvedA !== null
        && is_string($resolvedA['order_id'])
        && $resolvedA['order_id'] === $order13
        && !is_int($resolvedA['order_id']),
    'A: resolver returns exact canonical string type'
);

// ---------------------------------------------------------------------------
// B. Status sync with 13-digit
// ---------------------------------------------------------------------------
$memoryB = new Phase2MemoryDb();
$attemptB = $memoryB->seedFinancingAttempt(array(
    'store_id' => $storeId,
    'order_id' => $order13,
    'unicid' => $unicid,
));
$portB = new CanonicalRecordingStatusPort();
$syncB = CanonicalTestHarness::statusSync($memoryB, $portB);
$admitB = $syncB->admitTarget((int) $attemptB['attempt_id'], $p1['status_id'], $p1['status_label']);
mtucCanonOid_assert($admitB === MtUniCreditControlPanelStatusSyncService::ADMIT, 'B: admit 13-digit attempt');
$bankB = new MtUniCreditOrderBankStatusRepository(new MtUniCreditDbAdapter($memoryB, 'oc_'));
$writeB = $bankB->upsertAuthorizedLocal(
    $storeId,
    $order13,
    $p1['status_id'],
    $p1['status_label'],
    MtUniCreditBankStatusTransitionPolicy::SOURCE_LOCAL_LIFECYCLE
);
mtucCanonOid_assert(
    is_array($writeB)
        && $writeB['order_id'] === $order13
        && is_string($writeB['order_id']),
    'B: local bank status stores/returns string order_id'
);
$foundB = $bankB->findByOrderId($storeId, $order13);
mtucCanonOid_assert(
    $foundB !== null
        && $foundB['order_id'] === $order13
        && $foundB['order_reference'] === $order13
        && $foundB['status_id'] === $p1['status_id'],
    'B: findByOrderId + order_reference same canonical string'
);
$patchState = $syncB->retryPending((int) $attemptB['attempt_id'], $order13);
mtucCanonOid_assert(
    in_array(
        $patchState,
        array(
            MtUniCreditControlPanelStatusSyncStates::PENDING,
            MtUniCreditControlPanelStatusSyncStates::CONFIRMED,
        ),
        true
    ),
    'B: status sync retryPending accepts 13-digit string'
);
mtucCanonOid_assert(count($portB->calls) >= 1, 'B: PATCH invoked for 13-digit');
$patchPayloadB = $portB->calls[0];
mtucCanonOid_assert(
    isset($patchPayloadB['order_id'])
        && is_string($patchPayloadB['order_id'])
        && $patchPayloadB['order_id'] === $order13,
    'B: PATCH payload order_id is exact 13-digit string'
);

// ---------------------------------------------------------------------------
// C. P1 path with 13-digit string — no host-width (int) fixture cast
// ---------------------------------------------------------------------------
$transportC = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportC);
$stackC = Phase9TestHarness::stack($transportC);
Phase9TestHarness::seedBankOrder($stackC['memoryDb'], $order13, $stackC['storeId']);
$resultC = $stackC['submission']->submit(Phase9TestHarness::submitInput($order13, $stackC['storeId']));
mtucCanonOid_assert(!empty($resultC['success']), 'C: P1 submit succeeds with 13-digit');
$attemptC = $stackC['attempts']->findByStoreOrder($stackC['storeId'], $order13);
mtucCanonOid_assert(
    $attemptC !== null && is_string($attemptC['order_id']) && $attemptC['order_id'] === $order13,
    'C: attempt order_id stored as string'
);
$patchC = Phase9TestHarness::lastStatusPatchPayload($transportC);
mtucCanonOid_assert(
    is_array($patchC)
        && isset($patchC['order_id'])
        && is_string($patchC['order_id'])
        && $patchC['order_id'] === $order13,
    'C: PATCH payload exact 13-digit string'
);
$createsC = Phase7TestHarness::countOrderPosts($transportC);
$smartC = Phase9TestHarness::smartUcfCallCount($stackC['smartUcfProbe']);
$resultCReplay = $stackC['submission']->submit(Phase9TestHarness::submitInput($order13, $stackC['storeId']));
mtucCanonOid_assert(!empty($resultCReplay['success']) || !empty($resultCReplay['bank_redirect']), 'C: P1 replay ok');
mtucCanonOid_assert(Phase7TestHarness::countOrderPosts($transportC) === $createsC, 'C: no second CP create');
mtucCanonOid_assert(
    Phase9TestHarness::smartUcfCallCount($stackC['smartUcfProbe']) === $smartC,
    'C: no second SmartUCF'
);

// ---------------------------------------------------------------------------
// D. P2 with 13-digit string — no host-width (int) fixture cast
// ---------------------------------------------------------------------------
$transportD = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportD);
$stackD = Phase9TestHarness::stack(
    $transportD,
    null,
    null,
    Phase5TestHarness::STORE_A,
    array('uni_proces' => 1)
);
$orderDString = '1234567890124';
Phase9TestHarness::seedBankOrder($stackD['memoryDb'], $orderDString, $stackD['storeId']);
$resultD = $stackD['submission']->submit(Phase9TestHarness::submitInputProcess2($orderDString, $stackD['storeId']));
mtucCanonOid_assert(!empty($resultD['success']), 'D: P2 submit succeeds with 13-digit');
mtucCanonOid_assert(
    Phase9TestHarness::bankStatusId($stackD, $orderDString) === MtUniCreditBankStatus::SENT_PROCESS2,
    'D: local bank_sent_process2 for 13-digit'
);
$attemptD = $stackD['attempts']->findByStoreOrder($stackD['storeId'], $orderDString);
mtucCanonOid_assert(
    $attemptD !== null && $attemptD['order_id'] === $orderDString && is_string($attemptD['order_id']),
    'D: P2 attempt order_id is string'
);
$patchD = Phase9TestHarness::lastStatusPatchPayload($transportD);
mtucCanonOid_assert(
    is_array($patchD) && isset($patchD['order_id']) && $patchD['order_id'] === $orderDString,
    'D: P2 PATCH payload exact 13-digit string'
);

// ---------------------------------------------------------------------------
// E. Resolver: 13 string, reject 14, reject int; no int cast in source
// ---------------------------------------------------------------------------
$memoryE = new Phase2MemoryDb();
$memoryE->seedFinancingAttempt(array(
    'store_id' => $storeId,
    'order_id' => $order13,
    'unicid' => $unicid,
));
$resolverE = new MtUniCreditFinancingOrderResolver(new MtUniCreditDbAdapter($memoryE, 'oc_'));
$okE = $resolverE->resolve($storeId, $unicid, $order13);
mtucCanonOid_assert($okE !== null && $okE['order_id'] === $order13 && is_string($okE['order_id']), 'E: 13 string resolves');
mtucCanonOid_assert($resolverE->resolve($storeId, $unicid, str_repeat('9', 14)) === null, 'E: 14-digit rejected');
mtucCanonOid_assert($resolverE->resolve($storeId, $unicid, 1234567890123) === null, 'E: int order_id rejected');
$resolverSrc = file_get_contents($lib . DIRECTORY_SEPARATOR . 'financing_order_resolver.php');
mtucCanonOid_assert(
    is_string($resolverSrc) && strpos($resolverSrc, '(int) $canonicalOrderId') === false,
    'E: resolver source has no (int)$canonicalOrderId cast'
);
mtucCanonOid_assert(
    is_string($resolverSrc) && strpos($resolverSrc, 'order_id` = " . (int)') === false,
    'E: resolver source has no integer SQL order_id binding'
);

// ---------------------------------------------------------------------------
// F. Production UNICID wiring via ControlPanelOrderLifecycleService (GATE-02A)
// ---------------------------------------------------------------------------
final class MtucCanonOidSpyCpClient
{
    /** @var MtUniCreditControlPanelClient */
    private $inner;

    /** @var array<int, string> */
    public $configuredUnicidReads = array();

    /** @var string|null */
    public $overrideUnicid = null;

    public function __construct(MtUniCreditControlPanelClient $inner)
    {
        $this->inner = $inner;
    }

    /**
     * @return string
     */
    public function getConfiguredUnicid()
    {
        if (is_string($this->overrideUnicid)) {
            $this->configuredUnicidReads[] = $this->overrideUnicid;

            return $this->overrideUnicid;
        }
        $value = $this->inner->getConfiguredUnicid();
        $this->configuredUnicidReads[] = $value;

        return $value;
    }

    /**
     * @param string $name
     * @param array<int, mixed> $arguments
     * @return mixed
     */
    public function __call($name, array $arguments)
    {
        return call_user_func_array(array($this->inner, $name), $arguments);
    }
}

$transportF = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportF);
$stackFBase = Phase9TestHarness::stack($transportF);
$spyClientF = new MtucCanonOidSpyCpClient($stackFBase['client']);
$lifecycleF = new MtUniCreditControlPanelOrderLifecycleService(
    $stackFBase['attempts'],
    $stackFBase['locks'],
    $stackFBase['client'],
    null,
    $stackFBase['process1'],
    $stackFBase['bankStatuses'],
    $stackFBase['process2']
);
$clientProp = new ReflectionProperty('MtUniCreditControlPanelOrderLifecycleService', 'client');
$clientProp->setAccessible(true);
$clientProp->setValue($lifecycleF, $spyClientF);
$servicesF = Phase4TestHarness::services($transportF, $stackFBase['memoryDb'], $stackFBase['storeId'], Phase9TestHarness::NOW);
$submissionF = new MtUniCreditCheckoutFinancingSubmissionService(
    $stackFBase['attempts'],
    $lifecycleF,
    $servicesF['credentials'],
    new MtUniCreditShopConfigurationCache(
        new MtUniCreditShopCacheRepository($stackFBase['db'], $stackFBase['clock']),
        null,
        MtUniCreditBootstrap::shopCachePersistenceFromDb($stackFBase['db'])
    )
);
// Native-safe string identity for UNICID wiring (not a 13-digit / host-width fixture).
$orderFString = '96031';
Phase9TestHarness::seedBankOrder($stackFBase['memoryDb'], $orderFString, $stackFBase['storeId']);
$resultF = $submissionF->submit(Phase9TestHarness::submitInput($orderFString, $stackFBase['storeId']));
mtucCanonOid_assert(!empty($resultF['success']), 'F: lifecycle submit with spy client succeeds');
mtucCanonOid_assert(count($spyClientF->configuredUnicidReads) >= 1, 'F: lifecycle read getConfiguredUnicid()');
mtucCanonOid_assert(
    $spyClientF->configuredUnicidReads[0] === Phase4TestHarness::TEST_UNICID,
    'F: authoritativeShopUnicid sourced from getConfiguredUnicid()'
);

$attemptF = $stackFBase['attempts']->findByStoreOrder($stackFBase['storeId'], $orderFString);
mtucCanonOid_assert($attemptF !== null, 'F: attempt exists for recovery probe');
$tableF = $stackFBase['db']->getPrefix() . MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT;
$resetMissingTargetWindowF = function () use ($stackFBase, $tableF, $attemptF) {
    $stackFBase['db']->query(
        "UPDATE `{$tableF}` SET"
            . " `cp_status_sync_state` = '" . MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED . "',"
            . " `cp_status_sync_status_id` = NULL,"
            . " `cp_status_sync_status` = NULL,"
            . " `unicid` = '" . $stackFBase['db']->escape(Phase4TestHarness::TEST_UNICID) . "'"
            . " WHERE `attempt_id` = " . (int) $attemptF['attempt_id']
    );
};

// Correct configured UNICID reaches recovery via lifecycle replay (not direct coordinator UNICID arg).
$resetMissingTargetWindowF();
$spyClientF->configuredUnicidReads = array();
$spyClientF->overrideUnicid = null;
$createsBeforeRec = Phase7TestHarness::countOrderPosts($transportF);
$smartBeforeRec = Phase9TestHarness::smartUcfCallCount($stackFBase['smartUcfProbe']);
$replayOk = $submissionF->submit(Phase9TestHarness::submitInput($orderFString, $stackFBase['storeId']));
mtucCanonOid_assert(
    !empty($replayOk['success']) || !empty($replayOk['bank_redirect']),
    'F: lifecycle replay with correct configured UNICID succeeds'
);
mtucCanonOid_assert(
    in_array(Phase4TestHarness::TEST_UNICID, $spyClientF->configuredUnicidReads, true),
    'F: recovery path read getConfiguredUnicid() on lifecycle replay'
);
$targetAfterOk = (new MtUniCreditControlPanelStatusSyncService(
    new MtUniCreditControlPanelStatusSyncRepository($stackFBase['db']),
    $stackFBase['client']
))->readPersistedTarget((int) $attemptF['attempt_id']);
mtucCanonOid_assert(
    is_array($targetAfterOk) && $targetAfterOk['status_id'] === $p1['status_id'],
    'F: correct configured UNICID recovery re-admits P1 target'
);
mtucCanonOid_assert(
    Phase7TestHarness::countOrderPosts($transportF) === $createsBeforeRec,
    'F: recovery creates no second CP order'
);
mtucCanonOid_assert(
    Phase9TestHarness::smartUcfCallCount($stackFBase['smartUcfProbe']) === $smartBeforeRec,
    'F: recovery creates no second SmartUCF session'
);

// Wrong configured UNICID blocks recovery through lifecycle composition.
$resetMissingTargetWindowF();
$spyClientF->overrideUnicid = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
$spyClientF->configuredUnicidReads = array();
$submissionF->submit(Phase9TestHarness::submitInput($orderFString, $stackFBase['storeId']));
mtucCanonOid_assert(
    in_array('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', $spyClientF->configuredUnicidReads, true),
    'F: wrong configured UNICID was obtained from getConfiguredUnicid()'
);
$targetWrong = (new MtUniCreditControlPanelStatusSyncService(
    new MtUniCreditControlPanelStatusSyncRepository($stackFBase['db']),
    $stackFBase['client']
))->readPersistedTarget((int) $attemptF['attempt_id']);
mtucCanonOid_assert(
    is_array($targetWrong)
        && (string) $targetWrong['state'] === MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED,
    'F: wrong configured UNICID blocks recovery'
);

// Missing configured UNICID blocks recovery.
$resetMissingTargetWindowF();
$spyClientF->overrideUnicid = '';
$submissionF->submit(Phase9TestHarness::submitInput($orderFString, $stackFBase['storeId']));
$targetMissing = (new MtUniCreditControlPanelStatusSyncService(
    new MtUniCreditControlPanelStatusSyncRepository($stackFBase['db']),
    $stackFBase['client']
))->readPersistedTarget((int) $attemptF['attempt_id']);
mtucCanonOid_assert(
    is_array($targetMissing)
        && (string) $targetMissing['state'] === MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED,
    'F: missing configured UNICID blocks recovery'
);

// Attempt UNICID cannot self-authorize (empty attempt + correct shop credentials).
$spyClientF->overrideUnicid = null;
$stackFBase['db']->query(
    "UPDATE `{$tableF}` SET"
        . " `unicid` = '',"
        . " `cp_status_sync_state` = '" . MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED . "',"
        . " `cp_status_sync_status_id` = NULL,"
        . " `cp_status_sync_status` = NULL"
        . " WHERE `attempt_id` = " . (int) $attemptF['attempt_id']
);
$submissionF->submit(Phase9TestHarness::submitInput($orderFString, $stackFBase['storeId']));
$targetSelf = (new MtUniCreditControlPanelStatusSyncService(
    new MtUniCreditControlPanelStatusSyncRepository($stackFBase['db']),
    $stackFBase['client']
))->readPersistedTarget((int) $attemptF['attempt_id']);
mtucCanonOid_assert(
    is_array($targetSelf)
        && (string) $targetSelf['state'] === MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED,
    'F: attempt UNICID cannot self-authorize recovery'
);

$lifecycleSrc = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'control_panel_order_lifecycle_service.php');
mtucCanonOid_assert(
    strpos($lifecycleSrc, 'getConfiguredUnicid()') !== false,
    'F: lifecycle wires getConfiguredUnicid into Process 1'
);
mtucCanonOid_assert(
    !preg_match('/->run\s*\([\s\S]*?\$row\s*\[\s*[\'"]unicid[\'"]\s*\]/', $lifecycleSrc)
        && !preg_match('/->run\s*\([\s\S]*?\$attempt\s*\[\s*[\'"]unicid[\'"]\s*\]/', $lifecycleSrc),
    'F: attempt-row UNICID is not passed as authoritativeShopUnicid to run()'
);

// ---------------------------------------------------------------------------
// G. Schema inventory + migration preservation + failure visibility (GATE-02C/D)
// ---------------------------------------------------------------------------
$inventory = MtUniCreditPersistenceSchemaInventory::tables();
mtucCanonOid_assert(
    isset($inventory[MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT]['columns']['order_id'])
        && strpos($inventory[MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT]['columns']['order_id'], 'VARCHAR(13)') === 0,
    'G: financing_attempt.order_id inventory VARCHAR(13)'
);
mtucCanonOid_assert(
    isset($inventory[MtUniCreditPersistenceTableNames::ORDER_BANK_STATUS]['columns']['order_id'])
        && strpos($inventory[MtUniCreditPersistenceTableNames::ORDER_BANK_STATUS]['columns']['order_id'], 'VARCHAR(13)') === 0,
    'G: order_bank_status.order_id inventory VARCHAR(13)'
);
mtucCanonOid_assert(
    isset($inventory[MtUniCreditPersistenceTableNames::OPERATION_ORDER_CLAIM]['columns']['order_id'])
        && strpos($inventory[MtUniCreditPersistenceTableNames::OPERATION_ORDER_CLAIM]['columns']['order_id'], 'VARCHAR(13)') === 0,
    'G: operation_order_claim.order_id inventory VARCHAR(13)'
);
mtucCanonOid_assert(
    isset($inventory[MtUniCreditPersistenceTableNames::DIAGNOSTIC_DEBUG_LOG]['columns']['order_id'])
        && strpos($inventory[MtUniCreditPersistenceTableNames::DIAGNOSTIC_DEBUG_LOG]['columns']['order_id'], 'VARCHAR(13)') === 0,
    'G: diagnostic_debug_log.order_id inventory VARCHAR(13)'
);
mtucCanonOid_assert(
    method_exists('MtUniCreditPersistenceSchema', 'ensureCanonicalOrderIdColumnTypes'),
    'G: ensureCanonicalOrderIdColumnTypes exists'
);
$schemaPhp = file_get_contents($lib . DIRECTORY_SEPARATOR . 'persistence_schema.php');
mtucCanonOid_assert(
    is_string($schemaPhp)
        && strpos($schemaPhp, 'ensureCanonicalOrderIdColumnTypes()') !== false
        && strpos($schemaPhp, 'completeRequiredSchema()') !== false,
    'G: ensureCanonicalOrderIdColumnTypes called from install path'
);

$ddl = new MtucSchemaDdlMemory();
$prefix = 'oc_';
$faTable = $prefix . MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT;
$obsTable = $prefix . MtUniCreditPersistenceTableNames::ORDER_BANK_STATUS;
$claimTable = $prefix . MtUniCreditPersistenceTableNames::OPERATION_ORDER_CLAIM;
$diagTable = $prefix . MtUniCreditPersistenceTableNames::DIAGNOSTIC_DEBUG_LOG;
$ddl->query(
    "CREATE TABLE `{$faTable}` ("
        . " `attempt_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,"
        . " `store_id` INT UNSIGNED NOT NULL,"
        . " `order_id` INT UNSIGNED NULL,"
        . " PRIMARY KEY (`attempt_id`),"
        . " UNIQUE KEY `uniq_mt_uni_credit_store_order` (`store_id`, `order_id`)"
        . ") ENGINE=InnoDB"
);
$ddl->query(
    "CREATE TABLE `{$obsTable}` ("
        . " `order_bank_status_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,"
        . " `store_id` INT UNSIGNED NOT NULL,"
        . " `order_id` INT UNSIGNED NOT NULL,"
        . " PRIMARY KEY (`order_bank_status_id`),"
        . " UNIQUE KEY `uniq_mt_uni_credit_order_bank_store_order` (`store_id`, `order_id`)"
        . ") ENGINE=InnoDB"
);
$ddl->query(
    "CREATE TABLE `{$claimTable}` ("
        . " `operation_order_claim_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,"
        . " `store_id` INT UNSIGNED NOT NULL,"
        . " `order_id` INT UNSIGNED NULL,"
        . " PRIMARY KEY (`operation_order_claim_id`),"
        . " KEY `idx_mt_uni_credit_operation_order_claim_order` (`store_id`, `order_id`)"
        . ") ENGINE=InnoDB"
);
$ddl->query(
    "CREATE TABLE `{$diagTable}` ("
        . " `diagnostic_debug_log_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,"
        . " `store_id` INT UNSIGNED NOT NULL,"
        . " `order_id` INT UNSIGNED NOT NULL,"
        . " PRIMARY KEY (`diagnostic_debug_log_id`),"
        . " KEY `idx_mt_uni_credit_diag_store_order` (`store_id`, `order_id`)"
        . ") ENGINE=InnoDB"
);
// Representative existing row values (memory harness preserves cells across MODIFY).
$ddl->seedRow($faTable, array('attempt_id' => 1, 'store_id' => 0, 'order_id' => 91001));
$ddl->seedRow($obsTable, array('order_bank_status_id' => 1, 'store_id' => 0, 'order_id' => 91001));
$ddl->seedRow($claimTable, array('operation_order_claim_id' => 1, 'store_id' => 0, 'order_id' => 91001));
$ddl->seedRow($diagTable, array('diagnostic_debug_log_id' => 1, 'store_id' => 0, 'order_id' => 91001));

$schemaAdapter = new MtUniCreditDbAdapter($ddl, $prefix);
$schema = new MtUniCreditPersistenceSchema($schemaAdapter);
$schema->ensureCanonicalOrderIdColumnTypes();

$schemaTargets = array($faTable, $obsTable, $claimTable, $diagTable);
foreach ($schemaTargets as $table) {
    $show = $ddl->query('SHOW COLUMNS FROM `' . $table . '`');
    $type = '';
    foreach ($show->rows as $row) {
        if ((string) $row['Field'] === 'order_id') {
            $type = strtolower((string) $row['Type']);
            break;
        }
    }
    mtucCanonOid_assert($type === 'varchar(13)', 'G: INT→VARCHAR migrate on ' . $table);
}
mtucCanonOid_assert(
    isset($ddl->rows[$faTable][0]['order_id'])
        && (string) $ddl->rows[$faTable][0]['order_id'] === '91001',
    'G: existing row textual value preserved after MODIFY (memory harness)'
);
$idxFa = $ddl->query('SHOW INDEX FROM `' . $faTable . '`');
$hasStoreOrderIdx = false;
foreach ($idxFa->rows as $idxRow) {
    if ((string) $idxRow['Key_name'] === 'uniq_mt_uni_credit_store_order') {
        $hasStoreOrderIdx = true;
        break;
    }
}
mtucCanonOid_assert($hasStoreOrderIdx, 'G: index preserved after order_id type change');
$secondOk = true;
try {
    $schema->ensureCanonicalOrderIdColumnTypes();
} catch (Exception $exception) {
    $secondOk = false;
}
mtucCanonOid_assert($secondOk, 'G: ensureCanonicalOrderIdColumnTypes idempotent');

// Visible failure: insufficient string width / unexpected type.
$badDdl = new MtucSchemaDdlMemory();
$badTable = $prefix . MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT;
$badDdl->query(
    "CREATE TABLE `{$badTable}` ("
        . " `attempt_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,"
        . " `order_id` VARCHAR(12) NULL,"
        . " PRIMARY KEY (`attempt_id`)"
        . ") ENGINE=InnoDB"
);
$badThrown = false;
try {
    (new MtUniCreditPersistenceSchema(new MtUniCreditDbAdapter($badDdl, $prefix)))
        ->ensureCanonicalOrderIdColumnTypes();
} catch (MtUniCreditInstallationException $exception) {
    $badThrown = true;
}
mtucCanonOid_assert($badThrown, 'G: insufficient VARCHAR width fails visibly');

// Visible failure: post-repair still integer (forced inspection failure).
$stuckDdl = new MtucSchemaDdlMemory();
foreach (array($faTable, $obsTable, $claimTable, $diagTable) as $table) {
    $stuckDdl->query(
        "CREATE TABLE `{$table}` ("
            . " `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,"
            . " `order_id` INT UNSIGNED NULL,"
            . " PRIMARY KEY (`id`)"
            . ") ENGINE=InnoDB"
    );
}
$stuckSchema = new MtUniCreditPersistenceSchema(new MtUniCreditDbAdapter($stuckDdl, $prefix));
$stuckSchema->ensureCanonicalOrderIdColumnTypes();
// Force integer type after successful migrate to simulate failed conversion inspection.
$stuckDdl->tables[$faTable]['columns']['order_id'] = 'int unsigned';
$verifyThrown = false;
try {
    $ref = new ReflectionClass($stuckSchema);
    $method = $ref->getMethod('verifyCanonicalOrderIdColumnTypes');
    $method->setAccessible(true);
    $method->invoke($stuckSchema);
} catch (MtUniCreditInstallationException $exception) {
    $verifyThrown = true;
}
mtucCanonOid_assert($verifyThrown, 'G: post-repair integer type fails visibly');

// Visible failure: missing required index on verifyRequiredSchema (exact postcondition).
// Target: financing_attempt.uniq_mt_uni_credit_store_order (canonical order-id unique).
$targetLogical = MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT;
$targetIndex = 'uniq_mt_uni_credit_store_order';
$targetPhysical = $prefix . $targetLogical;
$idxDdl = new MtucSchemaDdlMemory();
$idxAdapter = new MtUniCreditDbAdapter($idxDdl, $prefix);
$idxSchema = new MtUniCreditPersistenceSchema($idxAdapter);
$completeInstallOk = true;
try {
    MtUniCreditPersistenceSchema::installAll($idxAdapter);
} catch (Exception $exception) {
    $completeInstallOk = false;
}
mtucCanonOid_assert($completeInstallOk, 'G: complete fake schema installAll succeeds');
$completeVerifyBefore = true;
try {
    $idxSchema->verifyRequiredSchema();
} catch (Exception $exception) {
    $completeVerifyBefore = false;
}
mtucCanonOid_assert($completeVerifyBefore, 'G: complete schema verifyRequiredSchema PASS before index removal');
mtucCanonOid_assert(
    isset($idxDdl->tables[$targetPhysical]['indexes'][$targetIndex]),
    'G: target index present before removal'
);
$idxDdl->dropIndex($targetPhysical, $targetIndex);
mtucCanonOid_assert(
    !isset($idxDdl->tables[$targetPhysical]['indexes'][$targetIndex]),
    'G: target index removed (only mutation)'
);
$idxFailMessage = '';
$idxFailClass = '';
try {
    $idxSchema->verifyRequiredSchema();
} catch (MtUniCreditInstallationException $exception) {
    $idxFailClass = get_class($exception);
    $idxFailMessage = $exception->getMessage();
} catch (Exception $exception) {
    $idxFailClass = get_class($exception);
    $idxFailMessage = $exception->getMessage();
}
$expectedIndexPhrase = 'missing or mismatched index on ' . $targetLogical;
mtucCanonOid_assert(
    $idxFailClass === 'MtUniCreditInstallationException',
    'G: missing target index throws MtUniCreditInstallationException'
);
mtucCanonOid_assert(
    strpos($idxFailMessage, $expectedIndexPhrase) !== false,
    'G: exception identifies intended table/index failure (' . $targetLogical . ' / ' . $targetIndex . ')'
);
mtucCanonOid_assert(
    strpos($idxFailMessage, 'missing table ') === false,
    'G: unrelated missing-table failure cannot satisfy index assertion'
);
// Restore exact target index definition from inventory, then verify again.
$idxDdl->tables[$targetPhysical]['indexes'][$targetIndex] = array(
    'unique' => true,
    'columns' => array('store_id', 'order_id'),
);
$completeVerifyAfter = true;
try {
    $idxSchema->verifyRequiredSchema();
} catch (Exception $exception) {
    $completeVerifyAfter = false;
}
mtucCanonOid_assert($completeVerifyAfter, 'G: complete schema verifyRequiredSchema PASS after index restore');

$memoryG = new Phase2MemoryDb();
$seeded = $memoryG->seedFinancingAttempt(array(
    'store_id' => $storeId,
    'order_id' => 91001,
    'unicid' => $unicid,
));
mtucCanonOid_assert(is_string($seeded['order_id']) && $seeded['order_id'] === '91001', 'G: memory seed stores string digits');
$seeded13 = $memoryG->seedFinancingAttempt(array(
    'store_id' => $storeId,
    'order_id' => $order13,
    'unicid' => $unicid,
));
mtucCanonOid_assert(is_string($seeded13['order_id']) && $seeded13['order_id'] === $order13, 'G: memory seed stores 13-digit string');

// ---------------------------------------------------------------------------
// H. Native OC3 conversion: decimal-string range before int cast (GATE-01)
// ---------------------------------------------------------------------------
$sim32 = '2147483647';
mtucCanonOid_assert(MtUniCreditShopOrderId::fitsDecimalMaximum('1', $sim32), 'H: 1 fits simulated 32-bit max');
mtucCanonOid_assert(MtUniCreditShopOrderId::fitsDecimalMaximum('2147483647', $sim32), 'H: 2147483647 fits simulated 32-bit max');
mtucCanonOid_assert(!MtUniCreditShopOrderId::fitsDecimalMaximum('2147483648', $sim32), 'H: 2147483648 does not fit simulated 32-bit max');
$nativeMax = MtUniCreditShopOrderId::NATIVE_OC3_ORDER_ID_MAX;
mtucCanonOid_assert($nativeMax === '4294967295', 'H: native OC3 max is INT UNSIGNED 4294967295');
mtucCanonOid_assert(MtUniCreditShopOrderId::fitsDecimalMaximum('4294967295', $nativeMax), 'H: 4294967295 fits native max');
mtucCanonOid_assert(!MtUniCreditShopOrderId::fitsDecimalMaximum('4294967296', $nativeMax), 'H: 4294967296 does not fit native max');

mtucCanonOid_assert(MtUniCreditShopOrderId::tryNativeOc3OrderId('1') === 1, 'H: tryNative 1');
mtucCanonOid_assert(MtUniCreditShopOrderId::tryNativeOc3OrderId('2147483647') === 2147483647, 'H: tryNative 2147483647');
// Above simulated 32-bit: on a real 32-bit host tryNative must return null; on 64-bit it may
// still convert if <= native max AND <= PHP_INT_MAX. Prove BOTH limits via fitsDecimalMaximum
// composition (host-independent), then assert tryNative rejects above native max always.
mtucCanonOid_assert(
    MtUniCreditShopOrderId::tryNativeOc3OrderId('4294967296') === null,
    'H: tryNative rejects above native max 4294967296'
);
mtucCanonOid_assert(
    MtUniCreditShopOrderId::tryNativeOc3OrderId('4294967295') !== null
        || !MtUniCreditShopOrderId::fitsDecimalMaximum('4294967295', (string) PHP_INT_MAX),
    'H: tryNative 4294967295 only when it also fits PHP_INT_MAX'
);
// When simulating 32-bit constraint, effective max is min(native, 2147483647).
$effective32 = MtUniCreditShopOrderId::fitsDecimalMaximum('2147483648', $sim32)
    ? $nativeMax
    : $sim32;
mtucCanonOid_assert(
    !MtUniCreditShopOrderId::fitsDecimalMaximum('2147483648', $effective32),
    'H: effective 32-bit path rejects 2147483648 before int cast'
);
mtucCanonOid_assert(
    MtUniCreditShopOrderId::tryNativeOc3OrderId($order13) === null,
    'H: tryNative rejects 13-digit (above native INT UNSIGNED)'
);
$shopSrc = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'shop_order_id.php');
mtucCanonOid_assert(
    strpos($shopSrc, 'fitsDecimalMaximum') !== false
        && strpos($shopSrc, 'PHP_INT_MAX') !== false
        && preg_match('/tryNativeOc3OrderId[\s\S]*?return \(int\) \$normalized;/s', $shopSrc),
    'H: tryNative proves range via fitsDecimalMaximum before int cast'
);
mtucCanonOid_assert(
    !preg_match('/tryNativeOc3OrderId[\s\S]*?\(int\)\s*\$normalized[\s\S]*?fitsDecimalMaximum/s', $shopSrc),
    'H: no int cast of canonical value before range check'
);

// ---------------------------------------------------------------------------
// I. Diagnostic 13-digit round trip (GATE-02E)
// ---------------------------------------------------------------------------
$memoryI = new Phase2MemoryDb();
$dbI = new MtUniCreditDbAdapter($memoryI, 'oc_');
$diagI = new MtUniCreditDiagnosticDebugLogRepository($dbI);
$diagId = $diagI->insert(
    $storeId,
    $order13,
    MtUniCreditOperationEntryPoint::CHECKOUT,
    'gate02_diag_roundtrip',
    200,
    array('message' => '13-digit diagnostic round trip', 'outcome' => 'ok')
);
mtucCanonOid_assert($diagId > 0, 'I: diagnostic insert with 13-digit succeeds');
$latestI = $diagI->findLatestByOrderId($storeId, $order13);
mtucCanonOid_assert(
    is_array($latestI)
        && isset($latestI['order_id'])
        && is_string($latestI['order_id'])
        && $latestI['order_id'] === $order13
        && !is_int($latestI['order_id']),
    'I: diagnostic findLatest returns exact 13-digit string'
);
$exported = $diagI->findAllForStore($storeId);
$foundExport = null;
foreach ($exported as $entry) {
    if (is_array($entry) && isset($entry['order_id']) && (string) $entry['order_id'] === $order13) {
        $foundExport = $entry;
        break;
    }
}
mtucCanonOid_assert(
    is_array($foundExport)
        && is_string($foundExport['order_id'])
        && $foundExport['order_id'] === $order13,
    'I: diagnostic export list preserves exact 13-digit string'
);

// Host-width-dependent fixture cast must be absent from this focused suite.
$thisSrc = (string) file_get_contents(__FILE__);
$castNeedlePrefix = '(int) $';
mtucCanonOid_assert(
    strpos($thisSrc, $castNeedlePrefix . 'order13') === false
        && strpos($thisSrc, $castNeedlePrefix . 'orderDString') === false
        && strpos($thisSrc, $castNeedlePrefix . 'orderFString') === false,
    'GATE-02B: no host-width int casts of canonical string fixtures'
);

echo PHP_EOL . 'canonical order_id string: ' . $passes . ' passed, ' . count($failures) . ' failed' . PHP_EOL;
if ($failures) {
    foreach ($failures as $failure) {
        echo 'FAIL  ' . $failure . PHP_EOL;
    }
}
exit(count($failures) === 0 ? 0 : 1);
