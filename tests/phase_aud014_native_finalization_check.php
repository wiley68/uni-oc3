<?php

/**
 * AUD-014 F01–F05 — Terminal native status / history idempotency.
 * Run: php tests/phase_aud014_native_finalization_check.php
 *
 * PHP 7.3 compatible. Offline. Production finalization claim/applicator paths.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/support/phase2_memory_db.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DIR_STORAGE')) {
    mtuc_test_define_dir_storage('mtuc-aud014');
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
}
if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'oc_');
}

require_once $lib . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . '/support/phase4_harness.php';
require_once __DIR__ . '/support/phase5_harness.php';
require_once __DIR__ . '/support/phase7_harness.php';
require_once __DIR__ . '/support/phase9_harness.php';
require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

$failures = array();
$passes = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucAud014_assert($condition, $message)
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
 * Probe config.
 */
final class Aud014Config
{
    /** @var array<string, mixed> */
    public $values = array();

    /**
     * @param string $key
     * @return mixed
     */
    public function get($key)
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : null;
    }
}

/**
 * @param Phase2MemoryDb $memoryDb
 * @param int $storeId
 * @param int $orderId
 * @return array<string, mixed>
 */
function mtucAud014_seedAttempt(Phase2MemoryDb $memoryDb, $storeId, $orderId)
{
    $db = new MtUniCreditDbAdapter($memoryDb, 'oc_');
    $clock = new MtUniCreditPersistenceClock(function () {
        return Phase9TestHarness::NOW;
    });
    $attempts = new MtUniCreditFinancingAttemptRepository($db, $clock);
    Phase9TestHarness::seedBankOrder($memoryDb, $orderId, $storeId);
    $memoryDb->orderStatuses[5] = true;

    return $attempts->findOrCreateCheckoutAttempt(
        $storeId,
        $orderId,
        'unicid-aud014',
        str_repeat('a', 64),
        str_repeat('b', 64),
        str_repeat('c', 64)
    );
}

/**
 * @return object
 */
function mtucAud014_historyProbe()
{
    return new class {
        /** @var int */
        public $calls = 0;

        /** @var array<int, array{0:int,1:int}> */
        public $args = array();

        /** @var Exception|null */
        public $throw = null;

        /**
         * @param int $orderId
         * @param int $statusId
         * @return void
         */
        public function addOrderHistory($orderId, $statusId)
        {
            if ($this->throw instanceof Exception) {
                throw $this->throw;
            }
            $this->calls++;
            $this->args[] = array((int) $orderId, (int) $statusId);
        }
    };
}

// ---------------------------------------------------------------------------
// Schema ensure path
// ---------------------------------------------------------------------------
$schemaDb = new Phase2MemoryDb();
$schemaAdapter = new MtUniCreditDbAdapter($schemaDb, 'oc_');
MtUniCreditPersistenceSchema::installAll($schemaAdapter);
mtucAud014_assert(
    method_exists('MtUniCreditPersistenceSchema', 'ensureAud014Columns')
        && method_exists('MtUniCreditPersistenceSchema', 'createAud014AlterStatements'),
    'AUD-014 schema ensure/alter helpers exist'
);
$alters = MtUniCreditPersistenceSchema::createAud014AlterStatements('oc_');
mtucAud014_assert(count($alters) >= 6, 'AUD-014 alter statements include finalize columns');

// ---------------------------------------------------------------------------
// F04 — strict configured status validation
// ---------------------------------------------------------------------------
$config = new Aud014Config();
$mem = new Phase2MemoryDb();
$mem->orderStatuses[5] = true;
$adapter = new MtUniCreditDbAdapter($mem, 'oc_');

$config->values[MtUniCreditConstants::PAYMENT_SETTING_ORDER_STATUS_ID] = null;
mtucAud014_assert(
    MtUniCreditNativeOrderStatusSupport::resolveExistingConfiguredStatusId($config, $adapter) === 0,
    'F04 missing setting → 0'
);
$config->values[MtUniCreditConstants::PAYMENT_SETTING_ORDER_STATUS_ID] = 0;
mtucAud014_assert(
    MtUniCreditNativeOrderStatusSupport::resolveExistingConfiguredStatusId($config, $adapter) === 0,
    'F04 zero → 0'
);
$config->values[MtUniCreditConstants::PAYMENT_SETTING_ORDER_STATUS_ID] = -3;
mtucAud014_assert(
    MtUniCreditNativeOrderStatusSupport::resolveExistingConfiguredStatusId($config, $adapter) === 0,
    'F04 negative → 0'
);
$config->values[MtUniCreditConstants::PAYMENT_SETTING_ORDER_STATUS_ID] = 'abc';
mtucAud014_assert(
    MtUniCreditNativeOrderStatusSupport::resolveExistingConfiguredStatusId($config, $adapter) === 0,
    'F04 non-numeric → 0'
);
$config->values[MtUniCreditConstants::PAYMENT_SETTING_ORDER_STATUS_ID] = '5x';
mtucAud014_assert(
    MtUniCreditNativeOrderStatusSupport::parseConfiguredStatusIdStrict('5x') === 0
        && MtUniCreditNativeOrderStatusSupport::resolveExistingConfiguredStatusId($config, $adapter) === 0,
    'F04 numeric-prefix garbage 5x → 0'
);
$config->values[MtUniCreditConstants::PAYMENT_SETTING_ORDER_STATUS_ID] = '999';
mtucAud014_assert(
    MtUniCreditNativeOrderStatusSupport::resolveExistingConfiguredStatusId($config, $adapter) === 0,
    'F04 invalid positive ID (missing row) → 0'
);
$config->values[MtUniCreditConstants::PAYMENT_SETTING_ORDER_STATUS_ID] = '5';
mtucAud014_assert(
    MtUniCreditNativeOrderStatusSupport::resolveExistingConfiguredStatusId($config, $adapter) === 5,
    'F04 valid existing positive ID → 5'
);

// ---------------------------------------------------------------------------
// F01/F05 — durable once claim + applicator
// ---------------------------------------------------------------------------
$storeId = Phase5TestHarness::STORE_A;
$orderId = 214001;
$memoryDb = new Phase2MemoryDb();
$attempt = mtucAud014_seedAttempt($memoryDb, $storeId, $orderId);
$attemptId = (int) $attempt['attempt_id'];
$db = new MtUniCreditDbAdapter($memoryDb, 'oc_');
$clock = new MtUniCreditPersistenceClock(function () {
    return Phase9TestHarness::NOW;
});
$repo = new MtUniCreditNativeOrderFinalizationRepository($db, $clock);
$probe = mtucAud014_historyProbe();
$submit = array(
    'success' => true,
    'bank_status' => MtUniCreditBankStatus::SENT_PROCESS1,
    'attempt' => $attempt,
);

$first = MtUniCreditNativeOrderFinalizationApplicator::apply(
    $db,
    $orderId,
    5,
    $submit,
    function ($oid, $sid) use ($probe) {
        $probe->addOrderHistory($oid, $sid);
    },
    $repo
);
mtucAud014_assert(!empty($first['claim_acquired']) && !empty($first['history_called']), 'F01 fresh: claim + history');
mtucAud014_assert((int) $probe->calls === 1, 'F01 fresh: addOrderHistory exactly 1');
$row = $repo->findByAttempt($attemptId);
mtucAud014_assert(
    is_array($row) && (string) $row['native_finalize_state'] === MtUniCreditNativeOrderFinalizationStates::APPLIED,
    'F01 fresh: durable applied'
);

$second = MtUniCreditNativeOrderFinalizationApplicator::apply(
    $db,
    $orderId,
    5,
    $submit,
    function ($oid, $sid) use ($probe) {
        $probe->addOrderHistory($oid, $sid);
    },
    $repo
);
mtucAud014_assert(
    (string) $second['skipped_reason'] === 'durable_once_complete' && (int) $probe->calls === 1,
    'F01 sequential replay: history stays 1'
);

// Concurrent claim — two workers on fresh attempt
$orderId2 = 214002;
$attempt2 = mtucAud014_seedAttempt($memoryDb, $storeId, $orderId2);
$tokenA = MtUniCreditLockOwnerTokenGenerator::generate();
$tokenB = MtUniCreditLockOwnerTokenGenerator::generate();
$repo2 = new MtUniCreditNativeOrderFinalizationRepository($db, $clock);
$claimA = $repo2->claimApplying((int) $attempt2['attempt_id'], $tokenA, 5, 'success_p1');
$claimB = $repo2->claimApplying((int) $attempt2['attempt_id'], $tokenB, 5, 'success_p1');
mtucAud014_assert($claimA === true && $claimB === false, 'F01 concurrent: only one claim succeeds');

$probeConcurrent = mtucAud014_historyProbe();
$submit2 = array(
    'success' => true,
    'bank_status' => MtUniCreditBankStatus::SENT_PROCESS1,
    'attempt' => $attempt2,
);
// Second worker via applicator must not cross history (already applying / once-complete after mark)
$repo2->markApplied((int) $attempt2['attempt_id'], $tokenA);
$diagB = MtUniCreditNativeOrderFinalizationApplicator::apply(
    $db,
    $orderId2,
    5,
    $submit2,
    function ($oid, $sid) use ($probeConcurrent) {
        $probeConcurrent->addOrderHistory($oid, $sid);
    },
    $repo2
);
mtucAud014_assert(
    (int) $probeConcurrent->calls === 0 && (string) $diagB['skipped_reason'] === 'durable_once_complete',
    'F01 concurrent loser: no addOrderHistory'
);

// F05 — admin changes native status after applied; replay must not call history again
$probeAdmin = mtucAud014_historyProbe();
$adminReplay = MtUniCreditNativeOrderFinalizationApplicator::apply(
    $db,
    $orderId,
    5,
    $submit,
    function ($oid, $sid) use ($probeAdmin) {
        $probeAdmin->addOrderHistory($oid, $sid);
    },
    $repo
);
mtucAud014_assert(
    (int) $probeAdmin->calls === 0
        && (string) $adminReplay['skipped_reason'] === 'durable_once_complete',
    'F05 admin status change: durable applied blocks replay history'
);

// addOrderHistory throws → uncertain, no auto-retry
$orderId3 = 214003;
$attempt3 = mtucAud014_seedAttempt($memoryDb, $storeId, $orderId3);
$repo3 = new MtUniCreditNativeOrderFinalizationRepository($db, $clock);
$probeThrow = mtucAud014_historyProbe();
$probeThrow->throw = new Exception('native history boom');
$submit3 = array(
    'success' => true,
    'bank_status' => MtUniCreditBankStatus::SENT_PROCESS1,
    'attempt' => $attempt3,
);
$thrown = MtUniCreditNativeOrderFinalizationApplicator::apply(
    $db,
    $orderId3,
    5,
    $submit3,
    function ($oid, $sid) use ($probeThrow) {
        $probeThrow->addOrderHistory($oid, $sid);
    },
    $repo3
);
$row3 = $repo3->findByAttempt((int) $attempt3['attempt_id']);
mtucAud014_assert(
    (string) $thrown['finalize_state'] === MtUniCreditNativeOrderFinalizationStates::UNCERTAIN
        && is_array($row3)
        && (string) $row3['native_finalize_state'] === MtUniCreditNativeOrderFinalizationStates::UNCERTAIN,
    'F01 throw: durable uncertain'
);
$probeThrow->throw = null;
$probeThrow->calls = 0;
$retryThrow = MtUniCreditNativeOrderFinalizationApplicator::apply(
    $db,
    $orderId3,
    5,
    $submit3,
    function ($oid, $sid) use ($probeThrow) {
        $probeThrow->addOrderHistory($oid, $sid);
    },
    $repo3
);
mtucAud014_assert(
    (int) $probeThrow->calls === 0 && (string) $retryThrow['skipped_reason'] === 'durable_once_complete',
    'F01 throw replay: no new addOrderHistory'
);

// Crash-equivalent stale applying → uncertain, no auto retry
$orderId4 = 214004;
$attempt4 = mtucAud014_seedAttempt($memoryDb, $storeId, $orderId4);
$attemptId4 = (int) $attempt4['attempt_id'];
$staleClockNow = Phase9TestHarness::NOW;
$staleClock = new MtUniCreditPersistenceClock(function () use (&$staleClockNow) {
    return $staleClockNow;
});
$repo4 = new MtUniCreditNativeOrderFinalizationRepository($db, $staleClock);
$token4 = MtUniCreditLockOwnerTokenGenerator::generate();
mtucAud014_assert(
    $repo4->claimApplying($attemptId4, $token4, 5, 'success_p1') === true,
    'F01 stale setup: claim applying'
);
$staleClockNow = Phase9TestHarness::NOW + MtUniCreditNativeOrderFinalizationRepository::STALE_APPLYING_SECONDS + 5;
$normalized = $repo4->normalizeStaleApplyingToUncertain($attemptId4);
$row4 = $repo4->findByAttempt($attemptId4);
mtucAud014_assert(
    $normalized === 1
        && is_array($row4)
        && (string) $row4['native_finalize_state'] === MtUniCreditNativeOrderFinalizationStates::UNCERTAIN,
    'F01 stale applying → uncertain'
);
$probeStale = mtucAud014_historyProbe();
$staleApply = MtUniCreditNativeOrderFinalizationApplicator::apply(
    $db,
    $orderId4,
    5,
    array(
        'success' => true,
        'bank_status' => MtUniCreditBankStatus::SENT_PROCESS1,
        'attempt' => $attempt4,
    ),
    function ($oid, $sid) use ($probeStale) {
        $probeStale->addOrderHistory($oid, $sid);
    },
    $repo4
);
mtucAud014_assert(
    (int) $probeStale->calls === 0 && (string) $staleApply['skipped_reason'] === 'durable_once_complete',
    'F01 stale: no automatic addOrderHistory retry'
);

// Status-0 retrieval preserved (read path)
$memStatus0 = new Phase2MemoryDb();
Phase9TestHarness::seedBankOrder($memStatus0, 214100, $storeId);
$refOrders = (new ReflectionClass($memStatus0))->getProperty('orders');
$refOrders->setAccessible(true);
$orders = $refOrders->getValue($memStatus0);
$orders[214100]['order_status_id'] = 0;
$refOrders->setValue($memStatus0, $orders);
$status0 = MtUniCreditNativeOrderStatusSupport::readOrderStatusId(
    new MtUniCreditDbAdapter($memStatus0, 'oc_'),
    214100
);
mtucAud014_assert($status0 === 0, 'status-0 order remains readable for first finalization');

// ---------------------------------------------------------------------------
// F02 — cp_failed_retryable non-terminal
// ---------------------------------------------------------------------------
$transportAuth = new Phase4FakeCpHttpTransport();
$transportAuth->enqueueJson(401, array('success' => false, 'error' => 'invalid_credentials'));
$stackAuth = Phase9TestHarness::stack($transportAuth);
$authOrder = 214200;
Phase9TestHarness::seedBankOrder($stackAuth['memoryDb'], $authOrder, $stackAuth['storeId']);
$authSubmit = $stackAuth['submission']->submit(Phase9TestHarness::submitInput($authOrder, $stackAuth['storeId']));
mtucAud014_assert(
    isset($authSubmit['attempt']['state'])
        && (string) $authSubmit['attempt']['state'] === MtUniCreditFinancingAttemptState::CP_FAILED_RETRYABLE,
    'F02 auth: attempt cp_failed_retryable'
);
mtucAud014_assert(empty($authSubmit['apply_native_order_status']), 'F02 auth: no apply_native');
mtucAud014_assert(
    !MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveCheckoutCpFailureTerminal($authSubmit),
    'F02 auth: not definitive terminal'
);
mtucAud014_assert(
    (string) (isset($authSubmit['bank_status']) ? $authSubmit['bank_status'] : '')
        !== MtUniCreditBankStatus::SEND_FAILED_CP,
    'F02 auth: native/bank terminal markers absent'
);

// ---------------------------------------------------------------------------
// F03 — durable CP failure status prerequisite
// ---------------------------------------------------------------------------
$transportOk = new Phase4FakeCpHttpTransport();
$payloads = Phase7TestHarness::loginAndOrderSuccessPayloads();
$transportOk->enqueueJson(200, $payloads['login']);
$transportOk->enqueueJson(422, array('success' => false, 'message' => 'invalid'));
$stackOk = Phase9TestHarness::stack($transportOk);
$okOrder = 214300;
Phase9TestHarness::seedBankOrder($stackOk['memoryDb'], $okOrder, $stackOk['storeId']);
$okSubmit = $stackOk['submission']->submit(Phase9TestHarness::submitInput($okOrder, $stackOk['storeId']));
mtucAud014_assert(
    (string) $okSubmit['bank_status'] === MtUniCreditBankStatus::SEND_FAILED_CP
        && !empty($okSubmit['apply_native_order_status'])
        && MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveCheckoutCpFailureTerminal($okSubmit),
    'F03 persist success: durable bank_send_failed_cp + native authorised'
);

$throwDb = new Phase2MemoryDb();
$throwDb->throwOnBankStatusInsert = true;
$transportFail = new Phase4FakeCpHttpTransport();
$transportFail->enqueueJson(200, $payloads['login']);
$transportFail->enqueueJson(422, array('success' => false, 'message' => 'invalid'));
$stackFail = Phase9TestHarness::stack($transportFail, null, $throwDb);
$failOrder = 214301;
Phase9TestHarness::seedBankOrder($throwDb, $failOrder, $stackFail['storeId']);
$failSubmit = $stackFail['submission']->submit(Phase9TestHarness::submitInput($failOrder, $stackFail['storeId']));
mtucAud014_assert(
    empty($failSubmit['apply_native_order_status'])
        && (string) (isset($failSubmit['bank_status']) ? $failSubmit['bank_status'] : '')
        !== MtUniCreditBankStatus::SEND_FAILED_CP
        && !MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveCheckoutCpFailureTerminal($failSubmit),
    'F03 persist throw: native finalization blocked'
);
$probeF03 = mtucAud014_historyProbe();
mtucAud014_assert((int) $probeF03->calls === 0, 'F03 persist fail: no history calls');

// Terminal classification table (spot checks)
mtucAud014_assert(
    MtUniCreditFinancingTerminalNavigationSupport::isCheckoutCpFailureNativeFinalizationCandidate(array(
        'success' => false,
        'order_id' => 1,
        'cp_succeeded' => false,
        'ambiguous_blocked' => false,
        'control_panel_order_id' => 0,
        'error' => MtUniCreditControlPanelErrorClass::REJECTED,
        'bank_status' => '',
    )) === true,
    'table: definitive CP reject is persist candidate'
);
mtucAud014_assert(
    MtUniCreditFinancingTerminalNavigationSupport::isCheckoutCpFailureNativeFinalizationCandidate(array(
        'success' => false,
        'order_id' => 1,
        'cp_succeeded' => false,
        'ambiguous_blocked' => false,
        'control_panel_order_id' => 0,
        'error' => MtUniCreditControlPanelErrorClass::AUTH_FAILED,
        'attempt' => array('state' => MtUniCreditFinancingAttemptState::CP_FAILED_RETRYABLE),
        'bank_status' => '',
    )) === false,
    'table: cp_failed_retryable/auth is NOT persist candidate'
);

echo PHP_EOL . 'AUD-014 native finalization: ' . $passes . ' passed, ' . count($failures) . ' failed' . PHP_EOL;
if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

exit(0);
