<?php

/**
 * AUD-025 F01-R2 — shop_cache advisory lock release anomaly observability.
 * Run: php tests/phase_aud025_f01_r2_lock_release_anomaly_check.php
 *
 * PHP 7.3 compatible. Offline.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/support/phase2_memory_db.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
}

require_once $lib . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . '/support/phase4_harness.php';
require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

$failures = array();
$passes = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucAud025R2_assert($condition, $message)
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
 * @param callable $callback
 * @return Exception|null
 */
function mtucAud025R2_catch(callable $callback)
{
    try {
        $callback();

        return null;
    } catch (Exception $exception) {
        return $exception;
    }
}

/**
 * DB double that can force RELEASE_LOCK anomalies and persistence faults.
 */
final class MtucAud025R2FaultDb
{
    /** @var Phase2MemoryDb */
    private $inner;

    /** @var int|null|'throw'|false false = off; 0/null = override; 'throw' = exception */
    public $releaseOverride = false;

    /** @var bool */
    public $failOnShopCacheUpsert = false;

    /** @var bool */
    public $failOnCredentialDelete = false;

    /**
     * @param Phase2MemoryDb $inner
     */
    public function __construct(Phase2MemoryDb $inner)
    {
        $this->inner = $inner;
    }

    /**
     * @param string $sql
     * @return mixed
     */
    public function query($sql)
    {
        $sqlTrim = trim((string) $sql);

        if (strpos($sqlTrim, 'RELEASE_LOCK(') !== false && $this->releaseOverride !== false) {
            if ($this->releaseOverride === 'throw') {
                throw new RuntimeException('injected RELEASE_LOCK query failure');
            }

            return (object) array(
                'num_rows' => 1,
                'row' => array('mtuc_lock' => $this->releaseOverride),
                'rows' => array(array('mtuc_lock' => $this->releaseOverride)),
            );
        }

        if (
            $this->failOnShopCacheUpsert
            && stripos($sqlTrim, 'INSERT INTO') === 0
            && strpos($sqlTrim, 'shop_cache') !== false
        ) {
            throw new RuntimeException('injected shop cache upsert failure');
        }

        $userKey = MtUniCreditConstants::MODULE_SETTING_SMARTUCF_USER;
        $passKey = MtUniCreditConstants::MODULE_SETTING_SMARTUCF_PASSWORD;
        if (
            $this->failOnCredentialDelete
            && stripos($sqlTrim, 'DELETE FROM') === 0
            && strpos($sqlTrim, 'setting') !== false
            && (strpos($sqlTrim, $userKey) !== false || strpos($sqlTrim, $passKey) !== false)
        ) {
            throw new RuntimeException('injected credential rollback delete failure');
        }

        return $this->inner->query($sql);
    }

    /**
     * @param string $value
     * @return string
     */
    public function escape($value)
    {
        return $this->inner->escape($value);
    }

    /**
     * @return int
     */
    public function countAffected()
    {
        return $this->inner->countAffected();
    }

    /**
     * @return int
     */
    public function getLastId()
    {
        return $this->inner->getLastId();
    }
}

/**
 * Clean stack builder.
 *
 * @param array<int, string> $logSink
 * @param MtucAud025R2FaultDb|null $fault
 * @return array<string, mixed>
 */
function mtucAud025R2_build(array &$logSink, $fault = null)
{
    if ($fault === null) {
        $memory = new Phase2MemoryDb();
        $rawDb = $memory;
    } else {
        $rawDb = $fault;
        $memory = null;
    }

    $db = new MtUniCreditDbAdapter($rawDb, 'oc_');
    $settings = new MtUniCreditSettingStore($db, MtUniCreditConstants::MODULE_SETTINGS_CODE);
    $creds = MtUniCreditBootstrap::smartucfCredentialsRepositoryFromDb($db);
    $cache = new MtUniCreditShopCacheRepository($db);
    $lock = new MtUniCreditShopCachePersistenceLock($db);
    $persistence = new MtUniCreditShopCachePersistence(
        $cache,
        new MtUniCreditShopConfigurationSnapshotValidator(),
        $creds,
        $lock,
        function ($message) use (&$logSink) {
            $logSink[] = (string) $message;
        }
    );

    return array(
        'memory' => $memory,
        'fault' => $fault,
        'db' => $db,
        'settings' => $settings,
        'creds' => $creds,
        'cache' => $cache,
        'lock' => $lock,
        'persistence' => $persistence,
        'storeId' => Phase4TestHarness::TEST_STORE_ID,
        'unicid' => Phase4TestHarness::TEST_UNICID,
    );
}

/**
 * @param string $marker
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function mtucAud025R2_snapshot($marker, array $overrides = array())
{
    return mtuc4_valid_shop_snapshot(array_merge(array(
        'unicid' => Phase4TestHarness::TEST_UNICID,
        'uni_email' => $marker,
    ), $overrides));
}

/**
 * @param array<string, mixed> $stack
 * @param string $marker
 * @return void
 */
function mtucAud025R2_seedCache(array $stack, $marker)
{
    $stack['cache']->replaceValidated(
        $stack['storeId'],
        $stack['unicid'],
        MtUniCreditShopSnapshotSanitizer::sanitize(mtucAud025R2_snapshot($marker))
    );
}

/**
 * @param array<int, string> $logs
 * @return string|null
 */
function mtucAud025R2_anomalyLog(array $logs)
{
    foreach ($logs as $line) {
        if (strpos($line, MtUniCreditShopCachePersistence::EVENT_LOCK_RELEASE_ANOMALY) !== false) {
            return $line;
        }
    }

    return null;
}

/**
 * @param string|null $line
 * @param string $label
 * @return void
 */
function mtucAud025R2_assertSafeAnomaly($line, $label)
{
    mtucAud025R2_assert(is_string($line) && $line !== '', $label . ': anomaly recorded');
    mtucAud025R2_assert(
        is_string($line)
            && strpos($line, 'event=' . MtUniCreditShopCachePersistence::EVENT_LOCK_RELEASE_ANOMALY) !== false
            && strpos($line, 'component=shop_cache_persistence') !== false
            && strpos($line, 'store_id=') !== false
            && strpos($line, 'outcome=') !== false,
        $label . ': safe metadata present'
    );
    mtucAud025R2_assert(
        is_string($line)
            && strpos($line, Phase4TestHarness::TEST_UNICID) === false
            && strpos($line, 'NEW-U') === false
            && strpos($line, 'NEW-P') === false
            && strpos($line, 'TEMP-U') === false
            && strpos($line, 'TEMP-P') === false
            && stripos($line, 'RELEASE_LOCK') === false
            && stripos($line, 'GET_LOCK') === false
            && strpos($line, 'password') === false
            && strpos($line, 'secret') === false,
        $label . ': no secrets/SQL/UNICID in diagnostic'
    );
}

Phase2MemoryDb::resetAdvisoryLocks();

// -------------------------------------------------------------------------
// release = 1 → no anomaly
// -------------------------------------------------------------------------
$logsOk = array();
$ok = mtucAud025R2_build($logsOk);
$ok['persistence']->replaceValidatedSnapshot(
    $ok['storeId'],
    $ok['unicid'],
    mtucAud025R2_snapshot('cache-ok@example.test', array(
        'uni_user' => 'OK-U',
        'uni_password' => 'OK-P',
    ))
);
mtucAud025R2_assert($ok['creds']->getUser($ok['storeId']) === 'OK-U', 'normal release: credentials committed');
mtucAud025R2_assert(mtucAud025R2_anomalyLog($logsOk) === null, 'release=1 → no anomaly log');

// -------------------------------------------------------------------------
// Helpers for success + release anomaly
// -------------------------------------------------------------------------
/**
 * @param int|null|string $releaseOverride
 * @param string $expectedOutcome
 * @param string $label
 * @return void
 */
function mtucAud025R2_successAnomalyCase($releaseOverride, $expectedOutcome, $label)
{
    Phase2MemoryDb::resetAdvisoryLocks();
    $logs = array();
    $memory = new Phase2MemoryDb();
    $fault = new MtucAud025R2FaultDb($memory);
    $fault->releaseOverride = $releaseOverride;
    $stack = mtucAud025R2_build($logs, $fault);

    $ex = mtucAud025R2_catch(function () use ($stack) {
        $stack['persistence']->replaceValidatedSnapshot(
            $stack['storeId'],
            $stack['unicid'],
            mtucAud025R2_snapshot('cache-success-anomaly@example.test', array(
                'uni_user' => 'NEW-U',
                'uni_password' => 'NEW-P',
            ))
        );
    });

    mtucAud025R2_assert($ex === null, $label . ': caller-visible success (no throw)');
    mtucAud025R2_assert($stack['creds']->getUser($stack['storeId']) === 'NEW-U', $label . ': credentials committed');
    mtucAud025R2_assert($stack['creds']->getPassword($stack['storeId']) === 'NEW-P', $label . ': password committed');
    $encoded = $stack['cache']->findEncodedShopData($stack['storeId'], $stack['unicid']);
    mtucAud025R2_assert(
        is_string($encoded) && strpos($encoded, 'cache-success-anomaly@example.test') !== false,
        $label . ': cache committed'
    );
    $line = mtucAud025R2_anomalyLog($logs);
    mtucAud025R2_assertSafeAnomaly($line, $label);
    mtucAud025R2_assert(
        is_string($line)
            && strpos($line, 'outcome=' . $expectedOutcome) !== false
            && strpos($line, 'persistence=committed') !== false,
        $label . ': outcome class + committed flag'
    );
}

mtucAud025R2_successAnomalyCase(
    0,
    MtUniCreditShopCachePersistenceLock::RELEASE_OUTCOME_NOT_OWNED,
    'success + RELEASE_LOCK=0'
);
mtucAud025R2_successAnomalyCase(
    null,
    MtUniCreditShopCachePersistenceLock::RELEASE_OUTCOME_MISSING_OR_ERROR,
    'success + RELEASE_LOCK=NULL'
);
mtucAud025R2_successAnomalyCase(
    'throw',
    MtUniCreditShopCachePersistenceLock::RELEASE_OUTCOME_QUERY_EXCEPTION,
    'success + RELEASE_LOCK throws'
);

// -------------------------------------------------------------------------
// Failure path + release anomaly (rollback succeeds)
// -------------------------------------------------------------------------
/**
 * @param int|null|string $releaseOverride
 * @param string $expectedOutcome
 * @param string $label
 * @return void
 */
function mtucAud025R2_failureAnomalyCase($releaseOverride, $expectedOutcome, $label)
{
    Phase2MemoryDb::resetAdvisoryLocks();
    $logs = array();
    $memory = new Phase2MemoryDb();
    $fault = new MtucAud025R2FaultDb($memory);
    $stack = mtucAud025R2_build($logs, $fault);
    mtucAud025R2_seedCache($stack, 'cache-x@example.test');
    $stack['creds']->savePair($stack['storeId'], 'OLD-U', 'OLD-P');

    $fault->failOnShopCacheUpsert = true;
    $fault->releaseOverride = $releaseOverride;

    $ex = mtucAud025R2_catch(function () use ($stack) {
        $stack['persistence']->replaceValidatedSnapshot(
            $stack['storeId'],
            $stack['unicid'],
            mtucAud025R2_snapshot('cache-y@example.test', array(
                'uni_user' => 'NEW-U',
                'uni_password' => 'NEW-P',
            ))
        );
    });

    mtucAud025R2_assert(
        $ex instanceof Exception && strpos($ex->getMessage(), 'injected shop cache') !== false,
        $label . ': original business failure propagated'
    );
    mtucAud025R2_assert($stack['creds']->getUser($stack['storeId']) === 'OLD-U', $label . ': credentials restored');
    mtucAud025R2_assert($stack['creds']->getPassword($stack['storeId']) === 'OLD-P', $label . ': password restored');
    $encoded = $stack['cache']->findEncodedShopData($stack['storeId'], $stack['unicid']);
    mtucAud025R2_assert(
        is_string($encoded) && strpos($encoded, 'cache-x@example.test') !== false,
        $label . ': cache preserved'
    );
    $line = mtucAud025R2_anomalyLog($logs);
    mtucAud025R2_assertSafeAnomaly($line, $label);
    mtucAud025R2_assert(
        is_string($line)
            && strpos($line, 'outcome=' . $expectedOutcome) !== false
            && strpos($line, 'persistence=failed') !== false,
        $label . ': outcome class + failed flag'
    );
}

mtucAud025R2_failureAnomalyCase(
    0,
    MtUniCreditShopCachePersistenceLock::RELEASE_OUTCOME_NOT_OWNED,
    'failure + RELEASE_LOCK=0'
);
mtucAud025R2_failureAnomalyCase(
    null,
    MtUniCreditShopCachePersistenceLock::RELEASE_OUTCOME_MISSING_OR_ERROR,
    'failure + RELEASE_LOCK=NULL'
);
mtucAud025R2_failureAnomalyCase(
    'throw',
    MtUniCreditShopCachePersistenceLock::RELEASE_OUTCOME_QUERY_EXCEPTION,
    'failure + RELEASE_LOCK throws'
);

// -------------------------------------------------------------------------
// Rollback-failure + release anomaly
// -------------------------------------------------------------------------
Phase2MemoryDb::resetAdvisoryLocks();
$logsRb = array();
$memRb = new Phase2MemoryDb();
$faultRb = new MtucAud025R2FaultDb($memRb);
$rb = mtucAud025R2_build($logsRb, $faultRb);
mtucAud025R2_seedCache($rb, 'cache-rb-x@example.test');
$faultRb->failOnShopCacheUpsert = true;
$faultRb->failOnCredentialDelete = true;
$faultRb->releaseOverride = 0;

$rbEx = mtucAud025R2_catch(function () use ($rb) {
    $rb['persistence']->replaceValidatedSnapshot(
        $rb['storeId'],
        $rb['unicid'],
        mtucAud025R2_snapshot('cache-rb-y@example.test', array(
            'uni_user' => 'TEMP-U',
            'uni_password' => 'TEMP-P',
        ))
    );
});
mtucAud025R2_assert(
    $rbEx instanceof MtUniCreditPersistenceException
        && strpos($rbEx->getMessage(), 'rollback failed') !== false,
    'rollback-failure + release anomaly: rollback exception remains primary'
);
$rbLine = mtucAud025R2_anomalyLog($logsRb);
mtucAud025R2_assertSafeAnomaly($rbLine, 'rollback-failure + release anomaly');
mtucAud025R2_assert(
    is_string($rbLine)
        && strpos($rbLine, 'outcome=' . MtUniCreditShopCachePersistenceLock::RELEASE_OUTCOME_NOT_OWNED) !== false
        && strpos($rbLine, 'persistence=failed') !== false,
    'rollback-failure + release anomaly: release diagnostic secondary'
);
mtucAud025R2_assert(
    $rbEx !== null && strpos($rbEx->getMessage(), 'shop_cache_lock_release_anomaly') === false,
    'rollback-failure: release anomaly not substituted as business exception'
);

// -------------------------------------------------------------------------
// Logging failure itself does not convert success into failure
// -------------------------------------------------------------------------
Phase2MemoryDb::resetAdvisoryLocks();
$memLogFail = new Phase2MemoryDb();
$faultLogFail = new MtucAud025R2FaultDb($memLogFail);
$faultLogFail->releaseOverride = 0;
$dbLogFail = new MtUniCreditDbAdapter($faultLogFail, 'oc_');
$persistenceLogFail = new MtUniCreditShopCachePersistence(
    new MtUniCreditShopCacheRepository($dbLogFail),
    new MtUniCreditShopConfigurationSnapshotValidator(),
    MtUniCreditBootstrap::smartucfCredentialsRepositoryFromDb($dbLogFail),
    new MtUniCreditShopCachePersistenceLock($dbLogFail),
    function () {
        throw new RuntimeException('injected logger failure');
    }
);
$logFailEx = mtucAud025R2_catch(function () use ($persistenceLogFail) {
    $persistenceLogFail->replaceValidatedSnapshot(
        Phase4TestHarness::TEST_STORE_ID,
        Phase4TestHarness::TEST_UNICID,
        mtucAud025R2_snapshot('cache-logfail@example.test', array(
            'uni_user' => 'LOG-U',
            'uni_password' => 'LOG-P',
        ))
    );
});
mtucAud025R2_assert($logFailEx === null, 'logger failure does not convert committed persistence into business failure');
$credsLogFail = MtUniCreditBootstrap::smartucfCredentialsRepositoryFromDb($dbLogFail);
mtucAud025R2_assert($credsLogFail->getUser(Phase4TestHarness::TEST_STORE_ID) === 'LOG-U', 'logger failure: credentials still committed');

// -------------------------------------------------------------------------
// Direct lock classification unit checks
// -------------------------------------------------------------------------
Phase2MemoryDb::resetAdvisoryLocks();
$classMem = new Phase2MemoryDb();
$classFault = new MtucAud025R2FaultDb($classMem);
$classLock = new MtUniCreditShopCachePersistenceLock(new MtUniCreditDbAdapter($classFault, 'oc_'));
mtucAud025R2_assert($classLock->acquire(Phase4TestHarness::TEST_STORE_ID, Phase4TestHarness::TEST_UNICID), 'unit: acquire');
$classFault->releaseOverride = 0;
$r0 = $classLock->release(Phase4TestHarness::TEST_STORE_ID, Phase4TestHarness::TEST_UNICID);
mtucAud025R2_assert(
    $r0['ok'] === false && $r0['outcome'] === MtUniCreditShopCachePersistenceLock::RELEASE_OUTCOME_NOT_OWNED,
    'unit: RELEASE_LOCK=0 classified not_owned'
);
$classFault->releaseOverride = null;
$rNull = $classLock->release(Phase4TestHarness::TEST_STORE_ID, Phase4TestHarness::TEST_UNICID);
mtucAud025R2_assert(
    $rNull['ok'] === false && $rNull['outcome'] === MtUniCreditShopCachePersistenceLock::RELEASE_OUTCOME_MISSING_OR_ERROR,
    'unit: RELEASE_LOCK=NULL classified missing_or_error'
);
$classFault->releaseOverride = 'throw';
$rThrow = $classLock->release(Phase4TestHarness::TEST_STORE_ID, Phase4TestHarness::TEST_UNICID);
mtucAud025R2_assert(
    $rThrow['ok'] === false && $rThrow['outcome'] === MtUniCreditShopCachePersistenceLock::RELEASE_OUTCOME_QUERY_EXCEPTION,
    'unit: RELEASE_LOCK throw classified query_exception'
);
$classFault->releaseOverride = false;
Phase2MemoryDb::resetAdvisoryLocks();
mtucAud025R2_assert($classLock->acquire(Phase4TestHarness::TEST_STORE_ID, Phase4TestHarness::TEST_UNICID), 'unit: re-acquire');
$rOk = $classLock->release(Phase4TestHarness::TEST_STORE_ID, Phase4TestHarness::TEST_UNICID);
mtucAud025R2_assert(
    $rOk['ok'] === true && $rOk['outcome'] === MtUniCreditShopCachePersistenceLock::RELEASE_OUTCOME_RELEASED,
    'unit: RELEASE_LOCK=1 classified released'
);

// -------------------------------------------------------------------------
if (count($failures) > 0) {
    echo 'AUD-025 F01-R2: FAIL (' . count($failures) . ' failures, ' . $passes . ' passes)' . PHP_EOL;
    exit(1);
}

echo 'AUD-025 F01-R2: PASS (' . $passes . ' passes)' . PHP_EOL;
exit(0);
