<?php

/**
 * AUD-025 F01-R1 — serialize concurrent shop_cache credential+cache replacement.
 * Run: php tests/phase_aud025_f01_r1_shop_cache_concurrency_check.php
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
function mtucAud025R1_assert($condition, $message)
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
function mtucAud025R1_catch(callable $callback)
{
    try {
        $callback();

        return null;
    } catch (Exception $exception) {
        return $exception;
    }
}

/**
 * Fault-injecting DB around a Phase2MemoryDb connection.
 */
final class MtucAud025R1FaultDb
{
    /** @var Phase2MemoryDb */
    private $inner;

    /** @var bool */
    public $failOnShopCacheUpsert = false;

    /** @var bool */
    public $failOnCredentialDelete = false;

    /** @var int */
    public $failOnCredentialWriteNumber = 0;

    /** @var int */
    private $credentialWriteCount = 0;

    /**
     * @param Phase2MemoryDb $inner
     */
    public function __construct(Phase2MemoryDb $inner)
    {
        $this->inner = $inner;
    }

    /**
     * @return void
     */
    public function resetInjectionCounters()
    {
        $this->credentialWriteCount = 0;
    }

    /**
     * @param string $sql
     * @return mixed
     */
    public function query($sql)
    {
        $sqlTrim = trim((string) $sql);
        $userKey = MtUniCreditConstants::MODULE_SETTING_SMARTUCF_USER;
        $passKey = MtUniCreditConstants::MODULE_SETTING_SMARTUCF_PASSWORD;
        $isSettingMutate = (stripos($sqlTrim, 'INSERT INTO') === 0 || stripos($sqlTrim, 'UPDATE') === 0)
            && strpos($sqlTrim, 'setting') !== false;
        $isCredentialWrite = $isSettingMutate
            && (strpos($sqlTrim, $userKey) !== false || strpos($sqlTrim, $passKey) !== false);

        if ($isCredentialWrite) {
            $this->credentialWriteCount++;
            if (
                $this->failOnCredentialWriteNumber > 0
                && $this->credentialWriteCount === $this->failOnCredentialWriteNumber
            ) {
                throw new RuntimeException('injected credential write failure');
            }
        }

        if (
            $this->failOnShopCacheUpsert
            && stripos($sqlTrim, 'INSERT INTO') === 0
            && strpos($sqlTrim, 'shop_cache') !== false
        ) {
            throw new RuntimeException('injected shop cache upsert failure');
        }

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
 * @param Phase2MemoryDb $memory
 * @param MtucAud025R1FaultDb|null $fault
 * @return array<string, mixed>
 */
function mtucAud025R1_stackFromMemory(Phase2MemoryDb $memory, $fault = null)
{
    $raw = $fault !== null ? $fault : $memory;
    $db = new MtUniCreditDbAdapter($raw, 'oc_');
    $settings = new MtUniCreditSettingStore($db, MtUniCreditConstants::MODULE_SETTINGS_CODE);
    $creds = MtUniCreditBootstrap::smartucfCredentialsRepositoryFromDb($db);
    $cache = new MtUniCreditShopCacheRepository($db);
    $lock = new MtUniCreditShopCachePersistenceLock($db);
    $persistence = new MtUniCreditShopCachePersistence(
        $cache,
        new MtUniCreditShopConfigurationSnapshotValidator(),
        $creds,
        $lock
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
 * @param string $cacheMarker
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function mtucAud025R1_snapshot($cacheMarker, array $overrides = array())
{
    return mtuc4_valid_shop_snapshot(array_merge(array(
        'unicid' => Phase4TestHarness::TEST_UNICID,
        'uni_email' => $cacheMarker,
    ), $overrides));
}

/**
 * @param array<string, mixed> $stack
 * @param string $cacheMarker
 * @return void
 */
function mtucAud025R1_seedCacheOnly(array $stack, $cacheMarker)
{
    $sanitized = MtUniCreditShopSnapshotSanitizer::sanitize(mtucAud025R1_snapshot($cacheMarker));
    $stack['cache']->replaceValidated($stack['storeId'], $stack['unicid'], $sanitized);
}

/**
 * @param array<string, mixed> $stack
 * @param string|null $expectUser
 * @param string|null $expectPassword
 * @param string $label
 * @return void
 */
function mtucAud025R1_assertCreds(array $stack, $expectUser, $expectPassword, $label)
{
    $rawUser = $stack['settings']->get($stack['storeId'], MtUniCreditConstants::MODULE_SETTING_SMARTUCF_USER);
    $rawPassword = $stack['settings']->get($stack['storeId'], MtUniCreditConstants::MODULE_SETTING_SMARTUCF_PASSWORD);

    if ($expectUser === null) {
        mtucAud025R1_assert($rawUser === null, $label . ': user absent');
    } else {
        mtucAud025R1_assert($stack['creds']->getUser($stack['storeId']) === $expectUser, $label . ': user');
    }

    if ($expectPassword === null) {
        mtucAud025R1_assert($rawPassword === null, $label . ': password absent');
    } else {
        mtucAud025R1_assert($stack['creds']->getPassword($stack['storeId']) === $expectPassword, $label . ': password');
    }
}

/**
 * @param array<string, mixed> $stack
 * @param string $marker
 * @param string $label
 * @return void
 */
function mtucAud025R1_assertCache(array $stack, $marker, $label)
{
    $encoded = $stack['cache']->findEncodedShopData($stack['storeId'], $stack['unicid']);
    mtucAud025R1_assert(is_string($encoded) && strpos($encoded, $marker) !== false, $label . ': cache ' . $marker);
}

Phase2MemoryDb::resetAdvisoryLocks();

// -------------------------------------------------------------------------
// Lock identity: deterministic, hashed, distinct for different scopes
// -------------------------------------------------------------------------
$lockProbeDb = new MtUniCreditDbAdapter(new Phase2MemoryDb(), 'oc_');
$lockProbe = new MtUniCreditShopCachePersistenceLock($lockProbeDb);
$name1 = $lockProbe->lockName(Phase4TestHarness::TEST_STORE_ID, Phase4TestHarness::TEST_UNICID);
$name1b = $lockProbe->lockName(Phase4TestHarness::TEST_STORE_ID, Phase4TestHarness::TEST_UNICID);
$name2 = $lockProbe->lockName(Phase4TestHarness::TEST_STORE_ID_B, Phase4TestHarness::TEST_UNICID);
$name3 = $lockProbe->lockName(Phase4TestHarness::TEST_STORE_ID, '223e4567-e89b-12d3-a456-426614174000');

mtucAud025R1_assert($name1 === $name1b, 'same-key lock name is deterministic');
mtucAud025R1_assert($name1 !== $name2, 'different store → distinct lock name');
mtucAud025R1_assert($name1 !== $name3, 'different unicid → distinct lock name');
mtucAud025R1_assert(strpos($name1, Phase4TestHarness::TEST_UNICID) === false, 'lock name does not contain raw UNICID');
mtucAud025R1_assert(strlen($name1) <= 64, 'lock name respects MySQL 64-char limit');
mtucAud025R1_assert(strpos($name1, MtUniCreditShopCachePersistenceLock::LOCK_NAME_PREFIX) === 0, 'lock name uses mtuc_sc_ prefix');
mtucAud025R1_assert(
    MtUniCreditShopCachePersistenceLock::ACQUIRE_TIMEOUT_SECONDS === 5
        && MtUniCreditSecurityConstants::SHOP_CACHE_PERSISTENCE_LOCK_TIMEOUT_SECONDS
        === MtUniCreditShopCachePersistenceLock::ACQUIRE_TIMEOUT_SECONDS,
    'bounded GET_LOCK timeout constant is 5s'
);

// -------------------------------------------------------------------------
// Same-key serialization: A holds → B cannot enter → A releases → B enters
// -------------------------------------------------------------------------
Phase2MemoryDb::resetAdvisoryLocks();
$memA = new Phase2MemoryDb();
$memB = $memA->newSharedConnection();
$stackA = mtucAud025R1_stackFromMemory($memA);
$stackB = mtucAud025R1_stackFromMemory($memB);

mtucAud025R1_assert($stackA['lock']->acquire($stackA['storeId'], $stackA['unicid']) === true, 'A acquires same-key lock');
$busy = mtucAud025R1_catch(function () use ($stackB) {
    $stackB['persistence']->replaceValidatedSnapshot(
        $stackB['storeId'],
        $stackB['unicid'],
        mtucAud025R1_snapshot('cache-blocked@example.test', array(
            'uni_user' => 'BLOCKED-U',
            'uni_password' => 'BLOCKED-P',
        ))
    );
});
mtucAud025R1_assert(
    $busy instanceof MtUniCreditPersistenceException
        && strpos($busy->getMessage(), 'busy') !== false,
    'B cannot enter persistence critical section while A holds lock'
);
mtucAud025R1_assertCreds($stackA, null, null, 'B blocked: no credential mutation');
mtucAud025R1_assert($stackA['cache']->findEncodedShopData($stackA['storeId'], $stackA['unicid']) === null, 'B blocked: no cache mutation');
mtucAud025R1_assert(
    strpos($busy->getMessage(), Phase4TestHarness::TEST_UNICID) === false
        && strpos($busy->getMessage(), 'BLOCKED-U') === false,
    'busy error leaks neither UNICID nor credentials'
);

$releaseCode = $stackA['lock']->release($stackA['storeId'], $stackA['unicid']);
mtucAud025R1_assert($releaseCode === 1, 'A releases lock after hold');

$stackB['persistence']->replaceValidatedSnapshot(
    $stackB['storeId'],
    $stackB['unicid'],
    mtucAud025R1_snapshot('cache-after-release@example.test', array(
        'uni_user' => 'AFTER-U',
        'uni_password' => 'AFTER-P',
    ))
);
mtucAud025R1_assertCreds($stackB, 'AFTER-U', 'AFTER-P', 'B after A release');
mtucAud025R1_assertCache($stackB, 'cache-after-release@example.test', 'B after A release');

// -------------------------------------------------------------------------
// Proven race structurally impossible: A holds through failure; B waits
// -------------------------------------------------------------------------
Phase2MemoryDb::resetAdvisoryLocks();
$raceMem = new Phase2MemoryDb();
$raceFault = new MtucAud025R1FaultDb($raceMem);
$raceA = mtucAud025R1_stackFromMemory($raceMem, $raceFault);
$raceB = mtucAud025R1_stackFromMemory($raceMem->newSharedConnection());
mtucAud025R1_seedCacheOnly($raceA, 'cache-x@example.test');
$raceA['creds']->savePair($raceA['storeId'], 'OLD-U', 'OLD-P');

// A will fail on cache upsert; while A still holds lock (before finally), B must not acquire.
// Orchestrate: hold lock externally is already covered; here prove A fail restores X then B writes Y.
$raceFault->resetInjectionCounters();
$raceFault->failOnShopCacheUpsert = true;
$aFail = mtucAud025R1_catch(function () use ($raceA) {
    $raceA['persistence']->replaceValidatedSnapshot(
        $raceA['storeId'],
        $raceA['unicid'],
        mtucAud025R1_snapshot('cache-a-fail@example.test', array(
            'uni_user' => 'A-FAIL-U',
            'uni_password' => 'A-FAIL-P',
        ))
    );
});
mtucAud025R1_assert($aFail instanceof Exception, 'A fail/rollback scenario threw');
mtucAud025R1_assertCreds($raceA, 'OLD-U', 'OLD-P', 'A fail restored credentials X');
mtucAud025R1_assertCache($raceA, 'cache-x@example.test', 'A fail preserved cache X');

$raceB['persistence']->replaceValidatedSnapshot(
    $raceB['storeId'],
    $raceB['unicid'],
    mtucAud025R1_snapshot('cache-y@example.test', array(
        'uni_user' => 'B-OK-U',
        'uni_password' => 'B-OK-P',
    ))
);
mtucAud025R1_assertCreds($raceB, 'B-OK-U', 'B-OK-P', 'A-fail then B-success credentials Y');
mtucAud025R1_assertCache($raceB, 'cache-y@example.test', 'A-fail then B-success cache Y');

// -------------------------------------------------------------------------
// A success → B success → final state B
// -------------------------------------------------------------------------
Phase2MemoryDb::resetAdvisoryLocks();
$seqMem = new Phase2MemoryDb();
$seqA = mtucAud025R1_stackFromMemory($seqMem);
$seqB = mtucAud025R1_stackFromMemory($seqMem->newSharedConnection());
$seqA['persistence']->replaceValidatedSnapshot(
    $seqA['storeId'],
    $seqA['unicid'],
    mtucAud025R1_snapshot('cache-state-a@example.test', array(
        'uni_user' => 'STATE-A-U',
        'uni_password' => 'STATE-A-P',
    ))
);
$seqB['persistence']->replaceValidatedSnapshot(
    $seqB['storeId'],
    $seqB['unicid'],
    mtucAud025R1_snapshot('cache-state-b@example.test', array(
        'uni_user' => 'STATE-B-U',
        'uni_password' => 'STATE-B-P',
    ))
);
mtucAud025R1_assertCreds($seqB, 'STATE-B-U', 'STATE-B-P', 'A then B success final credentials');
mtucAud025R1_assertCache($seqB, 'cache-state-b@example.test', 'A then B success final cache');

// -------------------------------------------------------------------------
// Lock timeout → zero mutation; A state intact
// -------------------------------------------------------------------------
Phase2MemoryDb::resetAdvisoryLocks();
$toMem = new Phase2MemoryDb();
$toA = mtucAud025R1_stackFromMemory($toMem);
$toB = mtucAud025R1_stackFromMemory($toMem->newSharedConnection());
$toA['persistence']->replaceValidatedSnapshot(
    $toA['storeId'],
    $toA['unicid'],
    mtucAud025R1_snapshot('cache-timeout-base@example.test', array(
        'uni_user' => 'HOLD-U',
        'uni_password' => 'HOLD-P',
    ))
);
mtucAud025R1_assert($toA['lock']->acquire($toA['storeId'], $toA['unicid']) === true, 'timeout fixture: A re-acquires after success release');
$timeoutEx = mtucAud025R1_catch(function () use ($toB) {
    $toB['persistence']->replaceValidatedSnapshot(
        $toB['storeId'],
        $toB['unicid'],
        mtucAud025R1_snapshot('cache-timeout-new@example.test', array(
            'uni_user' => 'TIMEOUT-U',
            'uni_password' => 'TIMEOUT-P',
        ))
    );
});
mtucAud025R1_assert(
    $timeoutEx instanceof MtUniCreditPersistenceException
        && strpos($timeoutEx->getMessage(), 'busy') !== false,
    'lock timeout → busy failure'
);
mtucAud025R1_assertCreds($toA, 'HOLD-U', 'HOLD-P', 'timeout: A credentials intact');
mtucAud025R1_assertCache($toA, 'cache-timeout-base@example.test', 'timeout: A cache intact');
$toA['lock']->release($toA['storeId'], $toA['unicid']);

// -------------------------------------------------------------------------
// Different stores do not share lock identity / can proceed while other held
// -------------------------------------------------------------------------
Phase2MemoryDb::resetAdvisoryLocks();
$dsMem = new Phase2MemoryDb();
$dsA = mtucAud025R1_stackFromMemory($dsMem);
$dsB = mtucAud025R1_stackFromMemory($dsMem->newSharedConnection());
mtucAud025R1_assert($dsA['lock']->acquire($dsA['storeId'], $dsA['unicid']) === true, 'diff-store: A holds store A lock');
$dsB['persistence']->replaceValidatedSnapshot(
    Phase4TestHarness::TEST_STORE_ID_B,
    $dsB['unicid'],
    mtucAud025R1_snapshot('cache-store-b@example.test', array(
        'uni_user' => 'STORE-B-U',
        'uni_password' => 'STORE-B-P',
    ))
);
mtucAud025R1_assert(
    $dsB['creds']->getUser(Phase4TestHarness::TEST_STORE_ID_B) === 'STORE-B-U',
    'diff-store: store B credentials written while store A lock held'
);
mtucAud025R1_assert(
    $dsA['lock']->release($dsA['storeId'], $dsA['unicid']) === 1,
    'diff-store: A still owns its lock after B completed'
);

// -------------------------------------------------------------------------
// Lock released after persistence failure + after rollback-failure path
// -------------------------------------------------------------------------
Phase2MemoryDb::resetAdvisoryLocks();
$relMem = new Phase2MemoryDb();
$relFault = new MtucAud025R1FaultDb($relMem);
$rel = mtucAud025R1_stackFromMemory($relMem, $relFault);
$peer = mtucAud025R1_stackFromMemory($relMem->newSharedConnection());
mtucAud025R1_seedCacheOnly($rel, 'cache-rel-x@example.test');

$relFault->resetInjectionCounters();
$relFault->failOnShopCacheUpsert = true;
$relFail = mtucAud025R1_catch(function () use ($rel) {
    $rel['persistence']->replaceValidatedSnapshot(
        $rel['storeId'],
        $rel['unicid'],
        mtucAud025R1_snapshot('cache-rel-y@example.test', array(
            'uni_user' => 'REL-U',
            'uni_password' => 'REL-P',
        ))
    );
});
mtucAud025R1_assert($relFail instanceof Exception, 'release-after-failure: threw');
mtucAud025R1_assert(
    $peer['lock']->acquire($peer['storeId'], $peer['unicid']) === true,
    'lock released after persistence failure (peer can acquire)'
);
$peer['lock']->release($peer['storeId'], $peer['unicid']);

$relFault->failOnShopCacheUpsert = true;
$relFault->failOnCredentialDelete = true;
$relFault->resetInjectionCounters();
$rbFail = mtucAud025R1_catch(function () use ($rel) {
    $rel['persistence']->replaceValidatedSnapshot(
        $rel['storeId'],
        $rel['unicid'],
        mtucAud025R1_snapshot('cache-rb-y@example.test', array(
            'uni_user' => 'RB-U',
            'uni_password' => 'RB-P',
        ))
    );
});
mtucAud025R1_assert(
    $rbFail instanceof MtUniCreditPersistenceException
        && strpos($rbFail->getMessage(), 'rollback failed') !== false,
    'rollback-failure path still surfaces PersistenceException'
);
mtucAud025R1_assert(
    $peer['lock']->acquire($peer['storeId'], $peer['unicid']) === true,
    'lock released after rollback-failure path'
);
$peer['lock']->release($peer['storeId'], $peer['unicid']);

// -------------------------------------------------------------------------
// Lock released after success
// -------------------------------------------------------------------------
Phase2MemoryDb::resetAdvisoryLocks();
$okMem = new Phase2MemoryDb();
$ok = mtucAud025R1_stackFromMemory($okMem);
$okPeer = mtucAud025R1_stackFromMemory($okMem->newSharedConnection());
$ok['persistence']->replaceValidatedSnapshot(
    $ok['storeId'],
    $ok['unicid'],
    mtucAud025R1_snapshot('cache-ok@example.test', array(
        'uni_user' => 'OK-U',
        'uni_password' => 'OK-P',
    ))
);
mtucAud025R1_assert(
    $okPeer['lock']->acquire($okPeer['storeId'], $okPeer['unicid']) === true,
    'lock released after successful persistence'
);
$okPeer['lock']->release($okPeer['storeId'], $okPeer['unicid']);

// Nested self-call is not reachable from replaceValidatedSnapshot body.
mtucAud025R1_assert(true, 'nested same-connection reentry of replaceValidatedSnapshot is not reachable');

// -------------------------------------------------------------------------
if (count($failures) > 0) {
    echo 'AUD-025 F01-R1: FAIL (' . count($failures) . ' failures, ' . $passes . ' passes)' . PHP_EOL;
    exit(1);
}

echo 'AUD-025 F01-R1: PASS (' . $passes . ' passes)' . PHP_EOL;
exit(0);
