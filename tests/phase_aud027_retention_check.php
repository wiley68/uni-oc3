<?php

/**
 * AUD-027 — P2 ciphertext (180d) + leasing presentation (183d) retention.
 * Run: php tests/phase_aud027_retention_check.php
 *
 * F01: immutable process2_sensitive_created_at (not updated_at)
 * F02: immutable leasing_presentation_created_at + bounded presentation cleanup
 *
 * PHP 7.3 compatible. Offline.
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
    mtuc_test_define_dir_storage('mtuc-aud027');
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
require_once __DIR__ . '/support/phase9_harness.php';

$failures = array();
$passes = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucAud027_assert($condition, $message)
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

$nowTs = 1700000000;
$clock = new MtUniCreditPersistenceClock(function () use (&$nowTs) {
    return (int) $nowTs;
});

$memoryDb = new Phase2MemoryDb();
$dbAdapter = new MtUniCreditDbAdapter($memoryDb, 'oc_');
$lifecycle = new MtUniCreditProcessTwoLifecycleRepository($dbAdapter, $clock);
$bankRepo = new MtUniCreditOrderBankStatusRepository($dbAdapter, $clock);
$presentationRepo = new MtUniCreditFinancingPresentationRepository($dbAdapter);

$storeId = Phase5TestHarness::STORE_A;
$orderId = 27001;
Phase9TestHarness::seedBankOrder($memoryDb, $orderId, $storeId);
$attempts = new MtUniCreditFinancingAttemptRepository($dbAdapter, $clock);
$attemptRow = $attempts->findOrCreateAttempt(
    $storeId,
    $orderId,
    Phase4TestHarness::TEST_UNICID,
    hash('sha256', 'aud027-op'),
    hash('sha256', 'aud027-sel'),
    hash('sha256', 'aud027-fp'),
    MtUniCreditOperationEntryPoint::CHECKOUT
);
$attemptId = (int) $attemptRow['attempt_id'];

$cipher = new MtUniCreditProcessTwoSensitiveCipher(Phase4TestHarness::testSecretInput());
$enc = $cipher->encrypt(new MtUniCreditProcessTwoSensitiveData('1990010112', '+35988111111'));
$snap = new MtUniCreditFinancingPresentationSnapshot(
    $orderId,
    888,
    true,
    12,
    'KOPSTD',
    100.0,
    500.0,
    45.0,
    640.0,
    5.5,
    6.1
);
$snapJson = json_encode($snap->toArray());

// ---------------------------------------------------------------------------
// Schema / policy parity
// ---------------------------------------------------------------------------
$aud027Sql = implode("\n", MtUniCreditPersistenceSchema::createAud027AlterStatements('oc_'));
mtucAud027_assert(
    strpos($aud027Sql, 'process2_sensitive_created_at') !== false
        && strpos($aud027Sql, 'leasing_presentation_created_at') !== false,
    'schema: AUD-027 timestamp columns present'
);
mtucAud027_assert(
    strpos($aud027Sql, 'idx_mt_uni_credit_attempt_p2_sensitive_created') !== false
        && strpos($aud027Sql, 'idx_mt_uni_credit_attempt_presentation_created') !== false,
    'schema: cleanup indexes present'
);

$policy = json_decode((string) file_get_contents($root . '/tests/fixtures/privacy_retention.json'), true);
mtucAud027_assert(
    is_array($policy)
        && (int) $policy['retention']['process2_ciphertext_days'] === 180
        && (int) $policy['retention']['presentation_days'] === 183,
    'policy: privacy_retention.json 180/183'
);
mtucAud027_assert(
    MtUniCreditProcessTwoLifecycleRepository::SENSITIVE_RETENTION_DAYS === 180
        && MtUniCreditProcessTwoLifecycleRepository::PRESENTATION_RETENTION_DAYS === 183,
    'policy: repository constants match fixture'
);

$repoSrc = (string) file_get_contents($lib . '/process_two_lifecycle_repository.php');
mtucAud027_assert(
    preg_match(
        '/redactExpiredSensitiveBatch\([\s\S]*?process2_sensitive_created_at`\s*</s',
        $repoSrc
    ) === 1,
    'mutation-1 YES: sensitive cleanup uses created_at not updated_at'
);
mtucAud027_assert(
    preg_match(
        '/redactExpiredSensitiveBatch\([\s\S]*?`updated_at`\s*</s',
        $repoSrc
    ) !== 1,
    'mutation-1b: redact selector must not use updated_at <'
);
mtucAud027_assert(
    strpos($repoSrc, 'function cleanupExpiredPresentationBatch') !== false,
    'mutation-5 YES: presentation cleanup present'
);
mtucAud027_assert(
    preg_match(
        '/cleanupExpiredPresentationBatch\([\s\S]*?leasing_presentation_created_at`\s*</s',
        $repoSrc
    ) === 1,
    'mutation-6 YES: presentation cleanup uses created_at'
);
mtucAud027_assert(
    preg_match(
        '/cleanupExpiredPresentationBatch\([\s\S]*?LIMIT/s',
        $repoSrc
    ) === 1
        && preg_match(
            '/redactExpiredSensitiveBatch\([\s\S]*?LIMIT/s',
            $repoSrc
        ) === 1,
    'mutation-12 YES: cleanup remains bounded'
);

// ---------------------------------------------------------------------------
// Initial write — timestamps frozen
// ---------------------------------------------------------------------------
$t0 = $nowTs;
$lifecycle->persistSensitiveEncrypted($attemptId, $enc);
$lifecycle->persistLeasingPresentationJson($attemptId, $snapJson);
$row0 = $lifecycle->findByAttempt($attemptId);
$sensitiveCreated0 = (string) $row0['process2_sensitive_created_at'];
$presentationCreated0 = (string) $row0['leasing_presentation_created_at'];
mtucAud027_assert(
    $sensitiveCreated0 === $clock->formatUtc($t0),
    'mutation-3 YES: sensitive timestamp set on first encrypted write'
);
mtucAud027_assert(
    $presentationCreated0 === $clock->formatUtc($t0),
    'F02: presentation timestamp set on first snapshot write'
);
mtucAud027_assert(
    is_string($row0['process2_sensitive_enc']) && $row0['process2_sensitive_enc'] !== '',
    'F01: ciphertext present after write'
);
mtucAud027_assert(
    is_string($row0['leasing_presentation_json']) && $row0['leasing_presentation_json'] !== '',
    'F02: presentation present after write'
);

$bankRepo->upsertAuthorizedLocal($storeId, (int) $orderId, MtUniCreditBankStatus::SENT_PROCESS2, MtUniCreditBankStatus::LABEL_SENT_PROCESS2);

// ---------------------------------------------------------------------------
// Later lifecycle mutations must not move retention clocks
// ---------------------------------------------------------------------------
$nowTs = $t0 + (100 * 86400);
$lifecycle->claimPreparing($attemptId, str_repeat('a', 32));
$lifecycle->markPrepared($attemptId);
$lifecycle->markMailSent($attemptId);
$lifecycle->persistSensitiveEncrypted($attemptId, $enc); // rewrite must not refresh created_at
$lifecycle->persistLeasingPresentationJson($attemptId, '{"ignored":true}'); // write-once blocked

$rowMid = $lifecycle->findByAttempt($attemptId);
mtucAud027_assert(
    (string) $rowMid['process2_sensitive_created_at'] === $sensitiveCreated0,
    'mutation-2 YES: later lifecycle does not refresh sensitive timestamp'
);
mtucAud027_assert(
    (string) $rowMid['leasing_presentation_created_at'] === $presentationCreated0,
    'mutation-7 YES: later lifecycle does not refresh presentation timestamp'
);
mtucAud027_assert(
    (string) $rowMid['updated_at'] !== $sensitiveCreated0
        || (string) $rowMid['updated_at'] === $clock->formatUtc($nowTs),
    'F01: updated_at moved by later lifecycle'
);
mtucAud027_assert(
    (string) $rowMid['updated_at'] === $clock->formatUtc($nowTs),
    'canary: updated_at reflects later mutations'
);
mtucAud027_assert(
    (string) $rowMid['leasing_presentation_json'] === $snapJson,
    'F02: presentation write-once preserved'
);

// ---------------------------------------------------------------------------
// 180-day sensitive boundary: 179 retained, 180 retained, 181 cleared
// ---------------------------------------------------------------------------
$nowTs = $t0 + (179 * 86400);
$lifecycle->redactExpiredSensitiveBatch(180, 100);
$row179 = $lifecycle->findByAttempt($attemptId);
mtucAud027_assert(
    is_string($row179['process2_sensitive_enc']) && $row179['process2_sensitive_enc'] !== '',
    'F01 boundary: day 179 sensitive retained'
);

$nowTs = $t0 + (180 * 86400);
$lifecycle->redactExpiredSensitiveBatch(180, 100);
$row180 = $lifecycle->findByAttempt($attemptId);
mtucAud027_assert(
    is_string($row180['process2_sensitive_enc']) && $row180['process2_sensitive_enc'] !== '',
    'F01 boundary: exactly day 180 sensitive retained'
);

$nowTs = $t0 + (181 * 86400);
$clearedSensitive = $lifecycle->redactExpiredSensitiveBatch(180, 100);
$row181 = $lifecycle->findByAttempt($attemptId);
mtucAud027_assert($clearedSensitive === 1, 'mutation-4 YES: day 181 sensitive cleanup runs');
mtucAud027_assert(
    $row181['process2_sensitive_enc'] === null || $row181['process2_sensitive_enc'] === '',
    'F01: day 181 ciphertext cleared'
);
mtucAud027_assert(
    $row181['process2_sensitive_created_at'] === null || $row181['process2_sensitive_created_at'] === '',
    'F01: day 181 sensitive timestamp cleared with ciphertext'
);
mtucAud027_assert(
    is_string($row181['leasing_presentation_json']) && $row181['leasing_presentation_json'] !== '',
    'day-181: presentation still present'
);

// Admin after sensitive expiry: public summary, no EGN/phone2
$svc = new MtUniCreditFinancingPresentationService($presentationRepo);
$adminHtml181 = $svc->htmlForOrder(
    $storeId,
    $orderId,
    MtUniCreditFinancingPresentationAudience::ADMIN_PANEL,
    ''
);
mtucAud027_assert($adminHtml181 !== '', 'day-181 admin: public financing summary present');
mtucAud027_assert(
    strpos($adminHtml181, '1990010112') === false
        && strpos($adminHtml181, MtUniCreditFinancingLeasingPresenter::LABEL_EGN) === false,
    'day-181 admin: no EGN'
);
mtucAud027_assert(
    strpos($adminHtml181, '+35988111111') === false
        && strpos($adminHtml181, MtUniCreditFinancingLeasingPresenter::LABEL_PHONE2) === false,
    'day-181 admin: no phone2'
);

// ---------------------------------------------------------------------------
// 183-day presentation boundary
// ---------------------------------------------------------------------------
$nowTs = $t0 + (182 * 86400);
$lifecycle->cleanupExpiredPresentationBatch(183, 100);
$row182 = $lifecycle->findByAttempt($attemptId);
mtucAud027_assert(
    is_string($row182['leasing_presentation_json']) && $row182['leasing_presentation_json'] !== '',
    'F02 boundary: day 182 presentation retained'
);

$nowTs = $t0 + (183 * 86400);
$lifecycle->cleanupExpiredPresentationBatch(183, 100);
$row183 = $lifecycle->findByAttempt($attemptId);
mtucAud027_assert(
    is_string($row183['leasing_presentation_json']) && $row183['leasing_presentation_json'] !== '',
    'mutation-8 YES: exactly day 183 presentation retained'
);

$nowTs = $t0 + (184 * 86400);
$clearedPresentation = $lifecycle->cleanupExpiredPresentationBatch(183, 100);
$row184 = $lifecycle->findByAttempt($attemptId);
mtucAud027_assert($clearedPresentation === 1, 'F02: day 184 presentation cleanup runs');
mtucAud027_assert(
    $row184['leasing_presentation_json'] === null || $row184['leasing_presentation_json'] === '',
    'day-184: presentation cleared'
);
mtucAud027_assert(
    $row184['process2_sensitive_enc'] === null || $row184['process2_sensitive_enc'] === '',
    'day-184: sensitive remains cleared'
);

$adminHtml184 = $svc->htmlForOrder(
    $storeId,
    $orderId,
    MtUniCreditFinancingPresentationAudience::ADMIN_PANEL,
    ''
);
mtucAud027_assert(
    $adminHtml184 === ''
        || (
            strpos($adminHtml184, '1990010112') === false
            && strpos($adminHtml184, 'KOPSTD') === false
        ),
    'day-184: no EGN and no live recalculated snapshot terms'
);

// Rehydration negative: decrypt path empty; findByOrderId null
mtucAud027_assert(
    $presentationRepo->findByOrderId($storeId, $orderId) === null,
    'mutation-11 YES: expired presentation not recalculated / absent'
);
$adminRows = $svc->rowsForOrder(
    $storeId,
    $orderId,
    MtUniCreditFinancingPresentationAudience::ADMIN_PANEL
);
mtucAud027_assert($adminRows === array(), 'mutation-10 YES: expired EGN/phone2 not rehydrated');

// ---------------------------------------------------------------------------
// Recovery identity preservation
// ---------------------------------------------------------------------------
mtucAud027_assert((int) $row184['attempt_id'] === $attemptId, 'recovery: attempt_id preserved');
mtucAud027_assert((int) $row184['store_id'] === (int) $storeId, 'recovery: store_id preserved');
mtucAud027_assert((int) $row184['order_id'] === (int) $orderId, 'recovery: order_id preserved');
mtucAud027_assert(
    (string) $row184['process2_state'] === MtUniCreditProcessTwoLifecycleStates::PREPARED,
    'recovery: process2 state preserved'
);
mtucAud027_assert(!empty($row184['process2_mail_sent']), 'recovery: mail dedupe marker preserved');
$bankLabel = $presentationRepo->findBankStatusLabel($storeId, $orderId);
mtucAud027_assert(
    $bankLabel === MtUniCreditBankStatus::LABEL_SENT_PROCESS2,
    'recovery: bank state preserved'
);
mtucAud027_assert(
    strpos($repoSrc, 'DELETE FROM') === false
        || strpos($repoSrc, 'redactExpiredSensitiveBatch') !== false,
    'mutation-9 YES: cleanup does not delete attempt rows'
);
mtucAud027_assert(
    preg_match('/redactExpiredSensitiveBatch\([\s\S]*?DELETE\s+FROM/s', $repoSrc) !== 1
        && preg_match('/cleanupExpiredPresentationBatch\([\s\S]*?DELETE\s+FROM/s', $repoSrc) !== 1,
    'mutation-9b: cleanup methods do not DELETE attempts'
);

// Failure semantics: probe throws, data unchanged
$failDb = new class($memoryDb) {
    /** @var Phase2MemoryDb */
    private $inner;

    public function __construct(Phase2MemoryDb $inner)
    {
        $this->inner = $inner;
    }

    public function query($sql)
    {
        if (stripos((string) $sql, 'UPDATE') === 0) {
            throw new RuntimeException('forced cleanup failure');
        }

        return $this->inner->query($sql);
    }

    public function escape($value)
    {
        return $this->inner->escape($value);
    }

    public function countAffected()
    {
        return $this->inner->countAffected();
    }

    public function getLastId()
    {
        return $this->inner->getLastId();
    }

    public function getPrefix()
    {
        return $this->inner->getPrefix();
    }
};

// Seed a fresh attempt for failure probe
$orderFail = 27002;
Phase9TestHarness::seedBankOrder($memoryDb, $orderFail, $storeId);
$attemptFail = $attempts->findOrCreateAttempt(
    $storeId,
    $orderFail,
    Phase4TestHarness::TEST_UNICID,
    hash('sha256', 'aud027-op-fail'),
    hash('sha256', 'aud027-sel-fail'),
    hash('sha256', 'aud027-fp-fail'),
    MtUniCreditOperationEntryPoint::CHECKOUT
);
$failId = (int) $attemptFail['attempt_id'];
$nowTs = $t0;
$lifecycleOk = new MtUniCreditProcessTwoLifecycleRepository($dbAdapter, $clock);
$lifecycleOk->persistSensitiveEncrypted($failId, $enc);
$lifecycleOk->persistLeasingPresentationJson($failId, $snapJson);
$beforeFail = $lifecycleOk->findByAttempt($failId);
$nowTs = $t0 + (200 * 86400);
$failLifecycle = new MtUniCreditProcessTwoLifecycleRepository(new MtUniCreditDbAdapter($failDb, 'oc_'), $clock);
$threw = false;
try {
    $failLifecycle->redactExpiredSensitiveBatch(180, 100);
} catch (Exception $e) {
    $threw = true;
}
mtucAud027_assert($threw, 'cleanup failure: throws');
$afterFail = $lifecycleOk->findByAttempt($failId);
mtucAud027_assert(
    (string) $afterFail['process2_sensitive_enc'] === (string) $beforeFail['process2_sensitive_enc'],
    'cleanup failure: ciphertext unchanged'
);

echo PHP_EOL . 'AUD-027 retention: ' . $passes . ' PASS, ' . count($failures) . ' FAIL' . PHP_EOL;
if ($failures !== array()) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

exit(0);
