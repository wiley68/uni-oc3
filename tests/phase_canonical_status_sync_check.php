<?php

/**
 * Canonical durable CP status sync: ADMIT/SAME/CONFLICT/CAS/terminal allowlist.
 * Run: php tests/phase_canonical_status_sync_check.php
 */
require_once __DIR__ . '/bootstrap.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DIR_STORAGE')) {
    mtuc_test_define_dir_storage('mtuc-canonical-sync');
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

function mtucCanonSync_assert(bool $condition, string $message): void
{
    global $failures, $passes;
    CanonicalTestHarness::assert($condition, $message, $failures, $passes);
}

/**
 * Store double that fails the first N pending CAS writes.
 */
final class CanonicalCasMissStore implements MtUniCreditControlPanelStatusSyncStoreInterface
{
    /** @var MtUniCreditControlPanelStatusSyncRepository */
    private $inner;

    /** @var int */
    private $remainingMisses;

    /**
     * @param MtUniCreditDbAdapter $db
     * @param int $misses
     */
    public function __construct(MtUniCreditDbAdapter $db, int $misses)
    {
        $this->inner = new MtUniCreditControlPanelStatusSyncRepository($db);
        $this->remainingMisses = $misses;
    }

    /**
     * @param int $attemptId
     * @return array<string, mixed>|null
     */
    public function findByAttempt($attemptId)
    {
        return $this->inner->findByAttempt($attemptId);
    }

    /**
     * @param int $attemptId
     * @param string $expectedState
     * @param string|null $expectedStatusId
     * @param string|null $expectedStatus
     * @param string $newStatusId
     * @param string $newStatus
     * @return bool
     */
    public function compareAndSetPendingTarget(
        $attemptId,
        $expectedState,
        $expectedStatusId,
        $expectedStatus,
        $newStatusId,
        $newStatus
    ) {
        if ($this->remainingMisses > 0) {
            $this->remainingMisses--;

            return false;
        }

        return $this->inner->compareAndSetPendingTarget(
            $attemptId,
            $expectedState,
            $expectedStatusId,
            $expectedStatus,
            $newStatusId,
            $newStatus
        );
    }

    /**
     * @param int $attemptId
     * @param string $expectedStatusId
     * @param string $expectedStatus
     * @return bool
     */
    public function compareAndSetConfirmed($attemptId, $expectedStatusId, $expectedStatus)
    {
        return $this->inner->compareAndSetConfirmed($attemptId, $expectedStatusId, $expectedStatus);
    }

    /**
     * @param int $attemptId
     * @param string $expectedStatusId
     * @param string $expectedStatus
     * @param string $newState
     * @param string $errorClass
     * @return bool
     */
    public function compareAndSetFailure($attemptId, $expectedStatusId, $expectedStatus, $newState, $errorClass)
    {
        return $this->inner->compareAndSetFailure(
            $attemptId,
            $expectedStatusId,
            $expectedStatus,
            $newState,
            $errorClass
        );
    }
}

/**
 * @param int $orderId
 * @param array<string, mixed> $overrides
 * @return array{memoryDb: Phase2MemoryDb, attempt: array<string, mixed>, sync: MtUniCreditControlPanelStatusSyncService, port: CanonicalRecordingStatusPort}
 */
function mtucCanonSync_stack(int $orderId, array $overrides = array()): array
{
    $memoryDb = new Phase2MemoryDb();
    $attempt = $memoryDb->seedFinancingAttempt(array_merge(array(
        'store_id' => Phase6TestHarness::STORE_A,
        'order_id' => $orderId,
        'unicid' => Phase4TestHarness::TEST_UNICID,
    ), $overrides));
    $port = new CanonicalRecordingStatusPort();
    $sync = CanonicalTestHarness::statusSync($memoryDb, $port);

    return array(
        'memoryDb' => $memoryDb,
        'attempt' => $attempt,
        'sync' => $sync,
        'port' => $port,
    );
}

$p1 = MtUniCreditBankStatus::process1Sent();
$p2 = MtUniCreditBankStatus::process2Sent();

// ADMIT fresh target.
$s1 = mtucCanonSync_stack(95001);
$admit = $s1['sync']->admitTarget((int) $s1['attempt']['attempt_id'], $p1['status_id'], $p1['status_label']);
mtucCanonSync_assert($admit === MtUniCreditControlPanelStatusSyncService::ADMIT, 'ADMIT fresh not_needed → pending');
$target = $s1['sync']->readPersistedTarget((int) $s1['attempt']['attempt_id']);
mtucCanonSync_assert(
    is_array($target)
        && $target['state'] === MtUniCreditControlPanelStatusSyncStates::PENDING
        && $target['status_id'] === $p1['status_id'],
    'pending target persisted'
);

// SAME pending.
$samePending = $s1['sync']->admitTarget((int) $s1['attempt']['attempt_id'], $p1['status_id'], $p1['status_label']);
mtucCanonSync_assert($samePending === MtUniCreditControlPanelStatusSyncService::SAME, 'SAME pending');

// Confirm then SAME confirmed.
$confirmed = $s1['sync']->retryPending((int) $s1['attempt']['attempt_id'], '95001');
mtucCanonSync_assert($confirmed === MtUniCreditControlPanelStatusSyncStates::CONFIRMED, 'retryPending confirms');
mtucCanonSync_assert(count($s1['port']->calls) === 1, 'PATCH called once on confirm');
$sameConfirmed = $s1['sync']->admitTarget((int) $s1['attempt']['attempt_id'], $p1['status_id'], $p1['status_label']);
mtucCanonSync_assert($sameConfirmed === MtUniCreditControlPanelStatusSyncService::SAME, 'SAME confirmed');

// CONFLICT P1 vs P2.
$s2 = mtucCanonSync_stack(95002);
$s2['sync']->admitTarget((int) $s2['attempt']['attempt_id'], $p1['status_id'], $p1['status_label']);
$conflict = $s2['sync']->admitTarget((int) $s2['attempt']['attempt_id'], $p2['status_id'], $p2['status_label']);
mtucCanonSync_assert($conflict === MtUniCreditControlPanelStatusSyncService::CONFLICT, 'CONFLICT incompatible terminals');

// Stale confirm: pending→confirmed CAS miss leaves authoritative confirmed.
$s3 = mtucCanonSync_stack(95003);
$s3['sync']->admitTarget((int) $s3['attempt']['attempt_id'], $p1['status_id'], $p1['status_label']);
$repo = new MtUniCreditControlPanelStatusSyncRepository(new MtUniCreditDbAdapter($s3['memoryDb'], 'oc_'));
$repo->compareAndSetConfirmed((int) $s3['attempt']['attempt_id'], $p1['status_id'], $p1['status_label']);
$staleConfirm = $s3['sync']->retryPending((int) $s3['attempt']['attempt_id'], '95003');
mtucCanonSync_assert(
    $staleConfirm === MtUniCreditControlPanelStatusSyncStates::CONFIRMED,
    'stale confirm CAS miss reloads confirmed'
);

// Stale failure: pending→failed CAS miss when already confirmed.
$s4 = mtucCanonSync_stack(95004);
$s4['sync']->admitTarget((int) $s4['attempt']['attempt_id'], $p1['status_id'], $p1['status_label']);
$s4['port']->handler = function () use ($s4, $p1) {
    $repoPeer = new MtUniCreditControlPanelStatusSyncRepository(new MtUniCreditDbAdapter($s4['memoryDb'], 'oc_'));
    $repoPeer->compareAndSetConfirmed((int) $s4['attempt']['attempt_id'], $p1['status_id'], $p1['status_label']);
    throw new MtUniCreditCpHttpException(
        400,
        CanonicalTestHarness::failureEnvelope('invalid_payload', 'bad'),
        'bad',
        true,
        'invalid_payload'
    );
};
$staleFailure = $s4['sync']->retryPending((int) $s4['attempt']['attempt_id'], '95004');
mtucCanonSync_assert(
    $staleFailure === MtUniCreditControlPanelStatusSyncStates::CONFIRMED,
    'stale failure CAS miss keeps authoritative confirmed'
);

// First CAS miss then ADMIT on retry.
$s5 = mtucCanonSync_stack(95005);
$attemptId5 = (int) $s5['attempt']['attempt_id'];
$store5 = new CanonicalCasMissStore(new MtUniCreditDbAdapter($s5['memoryDb'], 'oc_'), 1);
$sync5 = new MtUniCreditControlPanelStatusSyncService($store5, $s5['port']);
$firstMissAdmit = $sync5->admitTarget($attemptId5, $p1['status_id'], $p1['status_label']);
mtucCanonSync_assert($firstMissAdmit === MtUniCreditControlPanelStatusSyncService::ADMIT, 'first CAS miss then ADMIT');

// Second CAS miss → REJECT (never claim ADMIT).
$s6 = mtucCanonSync_stack(95006);
$store6 = new CanonicalCasMissStore(new MtUniCreditDbAdapter($s6['memoryDb'], 'oc_'), 100);
$sync6 = new MtUniCreditControlPanelStatusSyncService($store6, $s6['port']);
$secondMiss = $sync6->admitTarget((int) $s6['attempt']['attempt_id'], $p1['status_id'], $p1['status_label']);
mtucCanonSync_assert($secondMiss === MtUniCreditControlPanelStatusSyncService::REJECT, 'second CAS miss → REJECT');

// Terminal allowlist: invalid_payload → terminal_failed.
$s7 = mtucCanonSync_stack(95007);
$s7['sync']->admitTarget((int) $s7['attempt']['attempt_id'], $p1['status_id'], $p1['status_label']);
$s7['port']->handler = function () {
    throw new MtUniCreditCpHttpException(
        400,
        CanonicalTestHarness::failureEnvelope('invalid_payload', 'bad'),
        'bad',
        true,
        'invalid_payload'
    );
};
$terminal = $s7['sync']->retryPending((int) $s7['attempt']['attempt_id'], '95007');
mtucCanonSync_assert(
    $terminal === MtUniCreditControlPanelStatusSyncStates::TERMINAL_FAILED,
    'terminal allowlist invalid_payload → terminal_failed'
);

echo PHP_EOL . 'canonical status sync: ' . $passes . ' passed, ' . count($failures) . ' failed' . PHP_EOL;
exit(count($failures) === 0 ? 0 : 1);
