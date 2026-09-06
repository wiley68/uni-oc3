<?php

/**
 * AUD-025 F01 — shop_cache credential+cache failure atomicity.
 * Run: php tests/phase_aud025_f01_shop_cache_atomicity_check.php
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
function mtucAud025F01_assert($condition, $message)
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
function mtucAud025F01_catch(callable $callback)
{
    try {
        $callback();

        return null;
    } catch (Exception $exception) {
        return $exception;
    }
}

/**
 * Fault-injecting OpenCart DB double around Phase2MemoryDb.
 */
final class MtucAud025FaultInjectingDb
{
    /** @var Phase2MemoryDb */
    private $inner;

    /** @var int 0 = off; N = fail before executing Nth SmartUCF setting INSERT/UPDATE */
    public $failOnCredentialWriteNumber = 0;

    /** @var bool */
    public $failOnShopCacheUpsert = false;

    /** @var bool */
    public $failOnCredentialDelete = false;

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
     * Reset injection counters after fixture seeding.
     *
     * @return void
     */
    public function resetInjectionCounters()
    {
        $this->credentialWriteCount = 0;
    }

    /**
     * @return Phase2MemoryDb
     */
    public function memory()
    {
        return $this->inner;
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
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function mtucAud025F01_stack(array $options = array())
{
    $memory = new Phase2MemoryDb();
    $inject = new MtucAud025FaultInjectingDb($memory);
    if (isset($options['fail_cred_write'])) {
        $inject->failOnCredentialWriteNumber = (int) $options['fail_cred_write'];
    }
    if (!empty($options['fail_cache'])) {
        $inject->failOnShopCacheUpsert = true;
    }
    if (!empty($options['fail_cred_delete'])) {
        $inject->failOnCredentialDelete = true;
    }

    $db = new MtUniCreditDbAdapter($inject, 'oc_');
    $settings = new MtUniCreditSettingStore($db, MtUniCreditConstants::MODULE_SETTINGS_CODE);
    $creds = MtUniCreditBootstrap::smartucfCredentialsRepositoryFromDb($db);
    $cache = new MtUniCreditShopCacheRepository($db);
    $persistence = MtUniCreditBootstrap::shopCachePersistenceFromDb($db);
    $storeId = Phase4TestHarness::TEST_STORE_ID;
    $unicid = Phase4TestHarness::TEST_UNICID;

    return array(
        'memory' => $memory,
        'inject' => $inject,
        'db' => $db,
        'settings' => $settings,
        'creds' => $creds,
        'cache' => $cache,
        'persistence' => $persistence,
        'storeId' => $storeId,
        'unicid' => $unicid,
    );
}

/**
 * @param string $cacheMarker
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function mtucAud025F01_snapshot($cacheMarker, array $overrides = array())
{
    return mtuc4_valid_shop_snapshot(array_merge(array(
        'unicid' => Phase4TestHarness::TEST_UNICID,
        'uni_email' => $cacheMarker,
    ), $overrides));
}

/**
 * Seed cache without mutating SmartUCF credentials.
 *
 * @param array<string, mixed> $stack
 * @param string $cacheMarker
 * @return string
 */
function mtucAud025F01_seedCacheOnly(array $stack, $cacheMarker)
{
    $sanitized = MtUniCreditShopSnapshotSanitizer::sanitize(mtucAud025F01_snapshot($cacheMarker));
    $stack['cache']->replaceValidated($stack['storeId'], $stack['unicid'], $sanitized);

    $encoded = $stack['cache']->findEncodedShopData($stack['storeId'], $stack['unicid']);
    mtucAud025F01_assert(is_string($encoded) && strpos($encoded, $cacheMarker) !== false, 'seed cache marker ' . $cacheMarker);

    return (string) $encoded;
}

/**
 * @param array<string, mixed> $stack
 * @param string|null $expectUser null = absent; string = decrypted plain
 * @param string|null $expectPassword null = absent; string = decrypted plain
 * @param string $label
 * @return void
 */
function mtucAud025F01_assertCredPresence(array $stack, $expectUser, $expectPassword, $label)
{
    $rawUser = $stack['settings']->get($stack['storeId'], MtUniCreditConstants::MODULE_SETTING_SMARTUCF_USER);
    $rawPassword = $stack['settings']->get($stack['storeId'], MtUniCreditConstants::MODULE_SETTING_SMARTUCF_PASSWORD);

    if ($expectUser === null) {
        mtucAud025F01_assert($rawUser === null, $label . ': user absent');
        mtucAud025F01_assert($stack['creds']->getUser($stack['storeId']) === null, $label . ': user decrypt null');
    } else {
        mtucAud025F01_assert($rawUser !== null && $rawUser !== '', $label . ': user present');
        mtucAud025F01_assert($stack['creds']->getUser($stack['storeId']) === $expectUser, $label . ': user value');
    }

    if ($expectPassword === null) {
        mtucAud025F01_assert($rawPassword === null, $label . ': password absent');
        mtucAud025F01_assert($stack['creds']->getPassword($stack['storeId']) === null, $label . ': password decrypt null');
    } else {
        mtucAud025F01_assert($rawPassword !== null && $rawPassword !== '', $label . ': password present');
        mtucAud025F01_assert($stack['creds']->getPassword($stack['storeId']) === $expectPassword, $label . ': password value');
    }
}

/**
 * @param array<string, mixed> $stack
 * @param string $cacheMarker
 * @param string $label
 * @return void
 */
function mtucAud025F01_assertCacheMarker(array $stack, $cacheMarker, $label)
{
    $encoded = $stack['cache']->findEncodedShopData($stack['storeId'], $stack['unicid']);
    mtucAud025F01_assert(is_string($encoded) && strpos($encoded, $cacheMarker) !== false, $label . ': cache marker ' . $cacheMarker);
}

/**
 * @param array<string, mixed> $stack
 * @param string|null $priorUser
 * @param string|null $priorPassword
 * @param string $priorCache
 * @param array<string, mixed> $failOptions applied after seed
 * @param string $caseLabel
 * @return void
 */
function mtucAud025F01_runFailureCase(array $stack, $priorUser, $priorPassword, $priorCache, array $failOptions, $caseLabel)
{
    mtucAud025F01_seedCacheOnly($stack, $priorCache);

    if ($priorUser !== null || $priorPassword !== null) {
        $stack['creds']->savePair($stack['storeId'], $priorUser, $priorPassword);
    }
    mtucAud025F01_assertCredPresence($stack, $priorUser, $priorPassword, $caseLabel . ' before');

    $stack['inject']->resetInjectionCounters();
    if (isset($failOptions['fail_cred_write'])) {
        $stack['inject']->failOnCredentialWriteNumber = (int) $failOptions['fail_cred_write'];
        $stack['inject']->failOnShopCacheUpsert = false;
    }
    if (!empty($failOptions['fail_cache'])) {
        $stack['inject']->failOnShopCacheUpsert = true;
        $stack['inject']->failOnCredentialWriteNumber = 0;
    }

    $exception = mtucAud025F01_catch(function () use ($stack) {
        $stack['persistence']->replaceValidatedSnapshot(
            $stack['storeId'],
            $stack['unicid'],
            mtucAud025F01_snapshot('cache-y@example.test', array(
                'uni_user' => 'NEW-U',
                'uni_password' => 'NEW-P',
            ))
        );
    });

    mtucAud025F01_assert($exception instanceof Exception, $caseLabel . ': threw');
    mtucAud025F01_assert(
        $exception !== null && strpos($exception->getMessage(), 'NEW-U') === false
            && strpos($exception->getMessage(), 'NEW-P') === false
            && strpos($exception->getMessage(), 'injected') !== false,
        $caseLabel . ': controlled exception without credential leak'
    );
    mtucAud025F01_assertCredPresence($stack, $priorUser, $priorPassword, $caseLabel . ' after');
    mtucAud025F01_assertCacheMarker($stack, $priorCache, $caseLabel . ' after');
}

// -------------------------------------------------------------------------
// Successful credential + cache replacement
// -------------------------------------------------------------------------
$ok = mtucAud025F01_stack();
mtucAud025F01_seedCacheOnly($ok, 'cache-old@example.test');
$ok['creds']->savePair($ok['storeId'], 'OLD-U', 'OLD-P');
$ok['persistence']->replaceValidatedSnapshot(
    $ok['storeId'],
    $ok['unicid'],
    mtucAud025F01_snapshot('cache-new@example.test', array(
        'uni_user' => 'NEW-U',
        'uni_password' => 'NEW-P',
    ))
);
mtucAud025F01_assertCredPresence($ok, 'NEW-U', 'NEW-P', 'success');
mtucAud025F01_assertCacheMarker($ok, 'cache-new@example.test', 'success');
$okEncoded = $ok['cache']->findEncodedShopData($ok['storeId'], $ok['unicid']);
mtucAud025F01_assert(
    is_string($okEncoded)
        && strpos($okEncoded, 'NEW-U') === false
        && strpos($okEncoded, 'NEW-P') === false
        && strpos($okEncoded, 'uni_user') === false,
    'success: credentials stripped from cache JSON'
);

// -------------------------------------------------------------------------
// Failure with previous credentials both absent
// -------------------------------------------------------------------------
mtucAud025F01_runFailureCase(
    mtucAud025F01_stack(),
    null,
    null,
    'cache-x-absent@example.test',
    array('fail_cache' => true),
    'prior absent / cache fail'
);

// -------------------------------------------------------------------------
// Failure with user-only previous state
// -------------------------------------------------------------------------
mtucAud025F01_runFailureCase(
    mtucAud025F01_stack(),
    'ONLY-U',
    null,
    'cache-x-useronly@example.test',
    array('fail_cache' => true),
    'prior user-only / cache fail'
);

// -------------------------------------------------------------------------
// Failure with password-only previous state
// -------------------------------------------------------------------------
mtucAud025F01_runFailureCase(
    mtucAud025F01_stack(),
    null,
    'ONLY-P',
    'cache-x-passonly@example.test',
    array('fail_cache' => true),
    'prior password-only / cache fail'
);

// -------------------------------------------------------------------------
// Failure with both previous values present
// -------------------------------------------------------------------------
mtucAud025F01_runFailureCase(
    mtucAud025F01_stack(),
    'OLD-U',
    'OLD-P',
    'cache-x-both@example.test',
    array('fail_cache' => true),
    'prior both / cache fail'
);

// -------------------------------------------------------------------------
// Failure before first credential write
// -------------------------------------------------------------------------
mtucAud025F01_runFailureCase(
    mtucAud025F01_stack(),
    null,
    null,
    'cache-x-before-first@example.test',
    array('fail_cred_write' => 1),
    'fail before first credential write'
);

// -------------------------------------------------------------------------
// Failure after user write (critical absent→insert→rollback)
// -------------------------------------------------------------------------
mtucAud025F01_runFailureCase(
    mtucAud025F01_stack(),
    null,
    null,
    'cache-x-after-user@example.test',
    array('fail_cred_write' => 2),
    'fail after user write / prior absent'
);

mtucAud025F01_runFailureCase(
    mtucAud025F01_stack(),
    'OLD-U',
    'OLD-P',
    'cache-x-after-user-both@example.test',
    array('fail_cred_write' => 2),
    'fail after user write / prior both'
);

// -------------------------------------------------------------------------
// Failure after password write (both credentials written, cache next)
// Use fail_cache which fires after both credential writes.
// -------------------------------------------------------------------------
mtucAud025F01_runFailureCase(
    mtucAud025F01_stack(),
    null,
    null,
    'cache-x-after-password@example.test',
    array('fail_cache' => true),
    'fail after password write / prior absent'
);

mtucAud025F01_runFailureCase(
    mtucAud025F01_stack(),
    'MIX-U',
    null,
    'cache-x-after-password-mixed@example.test',
    array('fail_cache' => true),
    'fail after password write / prior user-only'
);

// -------------------------------------------------------------------------
// Cross-store isolation: store B untouched on store A failure
// -------------------------------------------------------------------------
$cross = mtucAud025F01_stack();
$storeB = Phase4TestHarness::TEST_STORE_ID_B;
mtucAud025F01_seedCacheOnly($cross, 'cache-a-x@example.test');
$cross['creds']->savePair($storeB, 'STORE-B-U', 'STORE-B-P');
$cross['cache']->replaceValidated(
    $storeB,
    $cross['unicid'],
    MtUniCreditShopSnapshotSanitizer::sanitize(mtucAud025F01_snapshot('cache-b@example.test'))
);
$cross['inject']->resetInjectionCounters();
$cross['inject']->failOnShopCacheUpsert = true;
$crossEx = mtucAud025F01_catch(function () use ($cross) {
    $cross['persistence']->replaceValidatedSnapshot(
        $cross['storeId'],
        $cross['unicid'],
        mtucAud025F01_snapshot('cache-a-y@example.test', array(
            'uni_user' => 'NEW-A-U',
            'uni_password' => 'NEW-A-P',
        ))
    );
});
mtucAud025F01_assert($crossEx instanceof Exception, 'cross-store: store A threw');
mtucAud025F01_assertCredPresence($cross, null, null, 'cross-store store A');
mtucAud025F01_assertCacheMarker($cross, 'cache-a-x@example.test', 'cross-store store A');
mtucAud025F01_assert(
    $cross['creds']->getUser($storeB) === 'STORE-B-U'
        && $cross['creds']->getPassword($storeB) === 'STORE-B-P',
    'cross-store: store B credentials unchanged'
);
$encodedB = $cross['cache']->findEncodedShopData($storeB, $cross['unicid']);
mtucAud025F01_assert(is_string($encodedB) && strpos($encodedB, 'cache-b@example.test') !== false, 'cross-store: store B cache unchanged');

// -------------------------------------------------------------------------
// Omitted credentials leave existing pair untouched (process-2 snapshot)
// -------------------------------------------------------------------------
$omit = mtucAud025F01_stack();
mtucAud025F01_seedCacheOnly($omit, 'cache-omit-old@example.test');
$omit['creds']->savePair($omit['storeId'], 'KEEP-U', 'KEEP-P');
$omitSnapshot = mtucAud025F01_snapshot('cache-omit-new@example.test', array(
    'uni_proces' => 1,
));
unset($omitSnapshot['uni_user'], $omitSnapshot['uni_password']);
$omit['persistence']->replaceValidatedSnapshot($omit['storeId'], $omit['unicid'], $omitSnapshot);
mtucAud025F01_assertCredPresence($omit, 'KEEP-U', 'KEEP-P', 'omit credentials');
mtucAud025F01_assertCacheMarker($omit, 'cache-omit-new@example.test', 'omit credentials');

// -------------------------------------------------------------------------
// Rollback failure surfaces PersistenceException (not clean success)
// -------------------------------------------------------------------------
$rb = mtucAud025F01_stack();
mtucAud025F01_seedCacheOnly($rb, 'cache-rb-x@example.test');
$rb['inject']->resetInjectionCounters();
$rb['inject']->failOnShopCacheUpsert = true;
$rb['inject']->failOnCredentialDelete = true;
$rbEx = mtucAud025F01_catch(function () use ($rb) {
    $rb['persistence']->replaceValidatedSnapshot(
        $rb['storeId'],
        $rb['unicid'],
        mtucAud025F01_snapshot('cache-rb-y@example.test', array(
            'uni_user' => 'TEMP-U',
            'uni_password' => 'TEMP-P',
        ))
    );
});
mtucAud025F01_assert(
    $rbEx instanceof MtUniCreditPersistenceException,
    'rollback failure: PersistenceException'
);
mtucAud025F01_assert(
    $rbEx !== null
        && strpos($rbEx->getMessage(), 'rollback failed') !== false
        && strpos($rbEx->getMessage(), 'TEMP-U') === false,
    'rollback failure: controlled message without secrets'
);
mtucAud025F01_assert(
    $rbEx !== null && $rbEx->getPrevious() instanceof Exception,
    'rollback failure: preserves root cause as previous'
);

// -------------------------------------------------------------------------
if (count($failures) > 0) {
    echo 'AUD-025 F01: FAIL (' . count($failures) . ' failures, ' . $passes . ' passes)' . PHP_EOL;
    exit(1);
}

echo 'AUD-025 F01: PASS (' . $passes . ' passes)' . PHP_EOL;
exit(0);
