<?php

/**
 * SmartUCF credential hardening: pair semantics + fail-before-network.
 * Run: php tests/phase_smartucf_credential_hardening_check.php
 *
 * PHP 7.3 compatible. Offline. No network.
 */
require_once __DIR__ . '/bootstrap.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DIR_STORAGE')) {
    mtuc_test_define_dir_storage('mtuc-smartucf-cred-hardening');
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
function mtucCredHard_assert($condition, $message)
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
function mtucCredHard_catch(callable $callback)
{
    try {
        $callback();

        return null;
    } catch (Exception $exception) {
        return $exception;
    }
}

/**
 * Fault-injecting DB double for atomic credential writes.
 */
final class MtucCredHardFaultDb
{
    /** @var Phase2MemoryDb */
    private $inner;

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
function mtucCredHard_persistStack(array $options = array())
{
    $memory = new Phase2MemoryDb();
    $inject = new MtucCredHardFaultDb($memory);
    if (isset($options['fail_cred_write'])) {
        $inject->failOnCredentialWriteNumber = (int) $options['fail_cred_write'];
    }
    $db = new MtUniCreditDbAdapter($inject, 'oc_');
    $settings = new MtUniCreditSettingStore($db, MtUniCreditConstants::MODULE_SETTINGS_CODE);
    $creds = MtUniCreditBootstrap::smartucfCredentialsRepositoryFromDb($db);
    $cache = new MtUniCreditShopCacheRepository($db);
    $persistence = MtUniCreditBootstrap::shopCachePersistenceFromDb($db);

    return array(
        'memory' => $memory,
        'inject' => $inject,
        'db' => $db,
        'settings' => $settings,
        'creds' => $creds,
        'cache' => $cache,
        'persistence' => $persistence,
        'storeId' => Phase4TestHarness::TEST_STORE_ID,
        'unicid' => Phase4TestHarness::TEST_UNICID,
    );
}

/**
 * @param array<string, mixed> $stack
 * @param string $cacheMarker
 * @return void
 */
function mtucCredHard_seedCacheOnly(array $stack, $cacheMarker)
{
    $sanitized = MtUniCreditShopSnapshotSanitizer::sanitize(mtuc4_valid_shop_snapshot(array(
        'unicid' => $stack['unicid'],
        'uni_email' => $cacheMarker,
    )));
    unset($sanitized['uni_user'], $sanitized['uni_password']);
    $stack['cache']->replaceValidated($stack['storeId'], $stack['unicid'], $sanitized);
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function mtucCredHard_p1Snapshot(array $overrides = array())
{
    return mtuc4_valid_shop_snapshot(array_merge(array(
        'uni_proces' => 0,
        'uni_user' => 'cred-user',
        'uni_password' => 'cred-pass',
    ), $overrides));
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function mtucCredHard_p2Snapshot(array $overrides = array())
{
    return mtuc4_valid_shop_snapshot(array_merge(array(
        'uni_proces' => 1,
    ), $overrides));
}

$validator = new MtUniCreditShopConfigurationSnapshotValidator();
$unicid = Phase4TestHarness::TEST_UNICID;

// ---------------------------------------------------------------------------
// A. Pair ingress — Process 1
// ---------------------------------------------------------------------------
mtucCredHard_assert(
    mtucCredHard_catch(function () use ($validator, $unicid) {
        $validator->validate(mtucCredHard_p1Snapshot(), $unicid);
    }) === null,
    'A: Process 1 full valid pair accepted'
);

$p1Absent = mtucCredHard_p1Snapshot();
unset($p1Absent['uni_user'], $p1Absent['uni_password']);
mtucCredHard_assert(
    mtucCredHard_catch(function () use ($validator, $unicid, $p1Absent) {
        $validator->validate($p1Absent, $unicid);
    }) instanceof MtUniCreditShopSnapshotValidationException,
    'A: Process 1 both absent rejected'
);

$p1UserOnly = mtucCredHard_p1Snapshot();
unset($p1UserOnly['uni_password']);
mtucCredHard_assert(
    mtucCredHard_catch(function () use ($validator, $unicid, $p1UserOnly) {
        $validator->validate($p1UserOnly, $unicid);
    }) instanceof MtUniCreditShopSnapshotValidationException,
    'A: Process 1 user-only rejected'
);

$p1PassOnly = mtucCredHard_p1Snapshot();
unset($p1PassOnly['uni_user']);
mtucCredHard_assert(
    mtucCredHard_catch(function () use ($validator, $unicid, $p1PassOnly) {
        $validator->validate($p1PassOnly, $unicid);
    }) instanceof MtUniCreditShopSnapshotValidationException,
    'A: Process 1 password-only rejected'
);

mtucCredHard_assert(
    mtucCredHard_catch(function () use ($validator, $unicid) {
        $validator->validate(mtucCredHard_p1Snapshot(array('uni_user' => '   ')), $unicid);
    }) instanceof MtUniCreditShopSnapshotValidationException,
    'A: Process 1 blank user rejected'
);

mtucCredHard_assert(
    mtucCredHard_catch(function () use ($validator, $unicid) {
        $validator->validate(mtucCredHard_p1Snapshot(array('uni_password' => '')), $unicid);
    }) instanceof MtUniCreditShopSnapshotValidationException,
    'A: Process 1 blank password rejected'
);

// ---------------------------------------------------------------------------
// B. Process 2 pair semantics
// ---------------------------------------------------------------------------
$stackB = mtucCredHard_persistStack();
mtucCredHard_seedCacheOnly($stackB, 'p2-keep@example.test');
$stackB['creds']->savePair($stackB['storeId'], 'KEEP-U', 'KEEP-P');
$priorUserRaw = $stackB['settings']->get($stackB['storeId'], MtUniCreditConstants::MODULE_SETTING_SMARTUCF_USER);
$priorPassRaw = $stackB['settings']->get($stackB['storeId'], MtUniCreditConstants::MODULE_SETTING_SMARTUCF_PASSWORD);
mtucCredHard_assert(
    is_string($priorUserRaw) && strpos($priorUserRaw, 'enc:v1:') === 0
        && is_string($priorPassRaw) && strpos($priorPassRaw, 'enc:v1:') === 0,
    'B: prior pair stored as enc:v1:'
);

$p2Absent = mtucCredHard_p2Snapshot(array('uni_email' => 'p2-absent@example.test'));
unset($p2Absent['uni_user'], $p2Absent['uni_password']);
$stackB['persistence']->replaceValidatedSnapshot($stackB['storeId'], $stackB['unicid'], $p2Absent);
mtucCredHard_assert(
    $stackB['settings']->get($stackB['storeId'], MtUniCreditConstants::MODULE_SETTING_SMARTUCF_USER) === $priorUserRaw
        && $stackB['settings']->get($stackB['storeId'], MtUniCreditConstants::MODULE_SETTING_SMARTUCF_PASSWORD) === $priorPassRaw,
    'B: Process 2 both absent preserves pair byte-for-byte'
);
mtucCredHard_assert(
    $stackB['creds']->getUser($stackB['storeId']) === 'KEEP-U'
        && $stackB['creds']->getPassword($stackB['storeId']) === 'KEEP-P',
    'B: Process 2 both absent decrypts prior pair'
);

$stackB['persistence']->replaceValidatedSnapshot(
    $stackB['storeId'],
    $stackB['unicid'],
    mtucCredHard_p2Snapshot(array(
        'uni_email' => 'p2-rotate@example.test',
        'uni_user' => 'NEW-U',
        'uni_password' => 'NEW-P',
    ))
);
mtucCredHard_assert(
    $stackB['creds']->getUser($stackB['storeId']) === 'NEW-U'
        && $stackB['creds']->getPassword($stackB['storeId']) === 'NEW-P',
    'B: Process 2 full valid pair rotates'
);
$rotatedUserRaw = $stackB['settings']->get($stackB['storeId'], MtUniCreditConstants::MODULE_SETTING_SMARTUCF_USER);
$rotatedPassRaw = $stackB['settings']->get($stackB['storeId'], MtUniCreditConstants::MODULE_SETTING_SMARTUCF_PASSWORD);
mtucCredHard_assert($rotatedUserRaw !== $priorUserRaw, 'B: rotation replaces ciphertext');

$partialEx = mtucCredHard_catch(function () use ($stackB) {
    $snap = mtucCredHard_p2Snapshot(array('uni_user' => 'ONLY-U'));
    unset($snap['uni_password']);
    $stackB['persistence']->replaceValidatedSnapshot($stackB['storeId'], $stackB['unicid'], $snap);
});
mtucCredHard_assert(
    $partialEx instanceof MtUniCreditShopSnapshotValidationException
        || $partialEx instanceof MtUniCreditPersistenceValidationException,
    'B: Process 2 user-only rejected'
);
mtucCredHard_assert(
    $stackB['creds']->getUser($stackB['storeId']) === 'NEW-U'
        && $stackB['creds']->getPassword($stackB['storeId']) === 'NEW-P',
    'B: Process 2 user-only leaves prior pair unchanged'
);

$passOnlyEx = mtucCredHard_catch(function () use ($stackB) {
    $snap = mtucCredHard_p2Snapshot(array('uni_password' => 'ONLY-P'));
    unset($snap['uni_user']);
    $stackB['persistence']->replaceValidatedSnapshot($stackB['storeId'], $stackB['unicid'], $snap);
});
mtucCredHard_assert(
    $passOnlyEx instanceof MtUniCreditShopSnapshotValidationException
        || $passOnlyEx instanceof MtUniCreditPersistenceValidationException,
    'B: Process 2 password-only rejected'
);

$blankEx = mtucCredHard_catch(function () use ($stackB) {
    $stackB['persistence']->replaceValidatedSnapshot(
        $stackB['storeId'],
        $stackB['unicid'],
        mtucCredHard_p2Snapshot(array(
            'uni_user' => 'OK-U',
            'uni_password' => '  ',
        ))
    );
});
mtucCredHard_assert(
    $blankEx instanceof MtUniCreditShopSnapshotValidationException
        || $blankEx instanceof MtUniCreditPersistenceValidationException,
    'B: Process 2 blank partial pair rejected'
);
mtucCredHard_assert(
    $stackB['settings']->get($stackB['storeId'], MtUniCreditConstants::MODULE_SETTING_SMARTUCF_USER) === $rotatedUserRaw
        && $stackB['settings']->get($stackB['storeId'], MtUniCreditConstants::MODULE_SETTING_SMARTUCF_PASSWORD) === $rotatedPassRaw,
    'B: invalid pair leaves encrypted pair byte-for-byte unchanged'
);

// ---------------------------------------------------------------------------
// C. Atomicity — fail on second credential write
// ---------------------------------------------------------------------------
$atom = mtucCredHard_persistStack();
mtucCredHard_seedCacheOnly($atom, 'atom-old@example.test');
$atom['creds']->savePair($atom['storeId'], 'OLD-U', 'OLD-P');
$atomUserRaw = $atom['settings']->get($atom['storeId'], MtUniCreditConstants::MODULE_SETTING_SMARTUCF_USER);
$atomPassRaw = $atom['settings']->get($atom['storeId'], MtUniCreditConstants::MODULE_SETTING_SMARTUCF_PASSWORD);
$atom['inject']->resetInjectionCounters();
$atom['inject']->failOnCredentialWriteNumber = 2;
$atomEx = mtucCredHard_catch(function () use ($atom) {
    $atom['persistence']->replaceValidatedSnapshot(
        $atom['storeId'],
        $atom['unicid'],
        mtucCredHard_p1Snapshot(array(
            'uni_email' => 'atom-new@example.test',
            'uni_user' => 'ATOM-U',
            'uni_password' => 'ATOM-P',
        ))
    );
});
mtucCredHard_assert($atomEx instanceof Exception, 'C: second credential write failure throws');
mtucCredHard_assert(
    $atom['settings']->get($atom['storeId'], MtUniCreditConstants::MODULE_SETTING_SMARTUCF_USER) === $atomUserRaw
        && $atom['settings']->get($atom['storeId'], MtUniCreditConstants::MODULE_SETTING_SMARTUCF_PASSWORD) === $atomPassRaw,
    'C: original pair restored exactly after failed second write'
);
mtucCredHard_assert(
    $atom['creds']->getUser($atom['storeId']) === 'OLD-U'
        && $atom['creds']->getPassword($atom['storeId']) === 'OLD-P',
    'C: no mixed old/new credential state'
);
$atomCache = $atom['cache']->findFresh($atom['storeId'], $atom['unicid']);
mtucCredHard_assert(
    is_array($atomCache)
        && isset($atomCache['shop_data']['uni_email'])
        && $atomCache['shop_data']['uni_email'] === 'atom-old@example.test',
    'C: cache update not committed when credential mutation fails'
);

// ---------------------------------------------------------------------------
// D. Encryption properties
// ---------------------------------------------------------------------------
$cipher = new MtUniCreditSettingCipher((new MtUniCreditEncryptionKeyProvider())->resolveDerivedKey());
$enc1 = $cipher->encrypt('same-plain');
$enc2 = $cipher->encrypt('same-plain');
mtucCredHard_assert(strpos($enc1, 'enc:v1:') === 0 && strpos($enc2, 'enc:v1:') === 0, 'D: ciphertext begins with enc:v1:');
mtucCredHard_assert($enc1 !== $enc2, 'D: same plaintext encrypts differently twice');
mtucCredHard_assert($cipher->decrypt($enc1) === 'same-plain', 'D: decrypt round-trip');
$tampered = substr($enc1, 0, -1) . (substr($enc1, -1) === 'A' ? 'B' : 'A');
mtucCredHard_assert(
    mtucCredHard_catch(function () use ($cipher, $tampered) {
        $cipher->decrypt($tampered);
    }) instanceof Exception,
    'D: tampered ciphertext rejected'
);
$wrongKey = new MtUniCreditSettingCipher(str_repeat("\x02", 32));
mtucCredHard_assert(
    mtucCredHard_catch(function () use ($wrongKey, $enc1) {
        $wrongKey->decrypt($enc1);
    }) instanceof Exception,
    'D: wrong-key ciphertext rejected'
);
$plainStore = mtucCredHard_persistStack();
$plainStore['settings']->set(
    $plainStore['storeId'],
    MtUniCreditConstants::MODULE_SETTING_SMARTUCF_USER,
    'plaintext-not-allowed'
);
mtucCredHard_assert(
    $plainStore['creds']->getUser($plainStore['storeId']) === null,
    'D: no plaintext fallback on missing enc:v1: prefix'
);

// ---------------------------------------------------------------------------
// E. Cache credential removal
// ---------------------------------------------------------------------------
$cacheStack = mtucCredHard_persistStack();
$cacheStack['persistence']->replaceValidatedSnapshot(
    $cacheStack['storeId'],
    $cacheStack['unicid'],
    mtucCredHard_p1Snapshot(array(
        'uni_email' => 'cache-clean@example.test',
        'uni_user' => 'CACHE-U',
        'uni_password' => 'CACHE-P',
    ))
);
$cached = $cacheStack['cache']->findFresh($cacheStack['storeId'], $cacheStack['unicid']);
$encoded = is_array($cached) ? json_encode($cached['shop_data']) : '';
mtucCredHard_assert(
    is_array($cached)
        && !array_key_exists('uni_user', $cached['shop_data'])
        && !array_key_exists('uni_password', $cached['shop_data']),
    'E: shop_data contains no uni_user/uni_password keys'
);
mtucCredHard_assert(
    is_string($encoded)
        && strpos($encoded, 'CACHE-U') === false
        && strpos($encoded, 'CACHE-P') === false
        && strpos($encoded, 'uni_user') === false
        && strpos($encoded, 'uni_password') === false,
    'E: shop_data JSON has no credential plaintext or keys'
);

// ---------------------------------------------------------------------------
// F. Runtime fail-before-network guard
// ---------------------------------------------------------------------------
/**
 * @param Phase4FakeCpHttpTransport $transport
 * @param callable|null $httpExecutor
 * @return array<string, mixed>
 */
function mtucCredHard_runtimeStack($transport, $httpExecutor = null)
{
    $stack = Phase9TestHarness::stack($transport, $httpExecutor);
    $sanitized = MtUniCreditShopSnapshotSanitizer::sanitize(mtucCredHard_p1Snapshot());
    unset($sanitized['uni_user'], $sanitized['uni_password']);
    $stack['memoryDb']->query('DELETE FROM `' . $stack['db']->getPrefix() . 'mt_uni_credit_shop_cache`');
    $cache = new MtUniCreditShopCacheRepository($stack['db'], $stack['clock']);
    $cache->replaceValidated($stack['storeId'], Phase4TestHarness::TEST_UNICID, $sanitized);
    $creds = MtUniCreditBootstrap::smartucfCredentialsRepositoryFromDb($stack['db']);
    $settings = new MtUniCreditSettingStore($stack['db'], MtUniCreditConstants::MODULE_SETTINGS_CODE);
    // stack() seeds a valid encrypted pair; runtime cases start from a cleared pair unless
    // they explicitly savePair (missing/corrupt/valid/empty scenarios).
    $settings->delete($stack['storeId'], MtUniCreditConstants::MODULE_SETTING_SMARTUCF_USER);
    $settings->delete($stack['storeId'], MtUniCreditConstants::MODULE_SETTING_SMARTUCF_PASSWORD);

    return array_merge($stack, array(
        'creds' => $creds,
        'settings' => $settings,
        'cacheRepo' => $cache,
    ));
}

/**
 * @param array<string, mixed> $stack
 * @param string $orderId
 * @return MtUniCreditSmartUcfCoordinationResult
 */
function mtucCredHard_runProcess1(array $stack, $orderId)
{
    $shop = $stack['cacheRepo']->findFresh($stack['storeId'], Phase4TestHarness::TEST_UNICID);
    $shopData = is_array($shop) ? $shop['shop_data'] : array();
    $shopData = MtUniCreditShopProcessContext::hydrateSmartUcfCredentials(
        $shopData,
        $stack['storeId'],
        $stack['creds']
    );
    $order = Phase7TestHarness::orderRow((int) $orderId, $stack['storeId']);
    $attempt = $stack['attempts']->findOrCreateCheckoutAttempt(
        $stack['storeId'],
        $orderId,
        Phase4TestHarness::TEST_UNICID,
        hash('sha256', 'cred-hard|' . $orderId),
        hash('sha256', 'sel|' . $orderId),
        hash('sha256', 'fp|' . $orderId)
    );
    $table = $stack['db']->getPrefix() . MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT;
    $stack['db']->query(
        "UPDATE `{$table}` SET `control_panel_order_id` = 4242, `state` = 'cp_created'"
            . ' WHERE `attempt_id` = ' . (int) $attempt['attempt_id']
    );
    $calc = Phase9TestHarness::calculation($shopData);

    return $stack['process1']->run(
        (int) $attempt['attempt_id'],
        $shopData,
        $order,
        Phase7TestHarness::orderProducts(),
        $calc,
        $orderId,
        4242,
        $stack['bankStatuses'],
        Phase4TestHarness::TEST_UNICID
    );
}

$rtMissingUser = mtucCredHard_runtimeStack(new Phase4FakeCpHttpTransport());
$rtMissingUser['creds']->savePair($rtMissingUser['storeId'], 'ONLY-TEMP', 'HAS-PASS');
$rtMissingUser['settings']->delete($rtMissingUser['storeId'], MtUniCreditConstants::MODULE_SETTING_SMARTUCF_USER);
$resultMissingUser = mtucCredHard_runProcess1($rtMissingUser, '97011');
mtucCredHard_assert($resultMissingUser->isFailed(), 'F: missing stored username fails');
mtucCredHard_assert(
    Phase9TestHarness::smartUcfCallCount($rtMissingUser['smartUcfProbe']) === 0,
    'F: missing username blocks before SmartUCF client'
);

$rtMissingPass = mtucCredHard_runtimeStack(new Phase4FakeCpHttpTransport());
$rtMissingPass['creds']->savePair($rtMissingPass['storeId'], 'HAS-USER', 'ONLY-TEMP');
$rtMissingPass['settings']->delete($rtMissingPass['storeId'], MtUniCreditConstants::MODULE_SETTING_SMARTUCF_PASSWORD);
$resultMissingPass = mtucCredHard_runProcess1($rtMissingPass, '97012');
mtucCredHard_assert($resultMissingPass->isFailed(), 'F: missing stored password fails');
mtucCredHard_assert(
    Phase9TestHarness::smartUcfCallCount($rtMissingPass['smartUcfProbe']) === 0,
    'F: missing password blocks before SmartUCF client'
);

$rtCorruptUser = mtucCredHard_runtimeStack(new Phase4FakeCpHttpTransport());
$rtCorruptUser['creds']->savePair($rtCorruptUser['storeId'], 'GOOD-U', 'GOOD-P');
$rtCorruptUser['settings']->set(
    $rtCorruptUser['storeId'],
    MtUniCreditConstants::MODULE_SETTING_SMARTUCF_USER,
    'enc:v1:' . base64_encode(str_repeat('x', 40))
);
$resultCorruptUser = mtucCredHard_runProcess1($rtCorruptUser, '97013');
mtucCredHard_assert($resultCorruptUser->isFailed(), 'F: corrupt username ciphertext fails');
mtucCredHard_assert(
    Phase9TestHarness::smartUcfCallCount($rtCorruptUser['smartUcfProbe']) === 0,
    'F: corrupt username blocks before client'
);

$rtCorruptPass = mtucCredHard_runtimeStack(new Phase4FakeCpHttpTransport());
$rtCorruptPass['creds']->savePair($rtCorruptPass['storeId'], 'GOOD-U', 'GOOD-P');
$rtCorruptPass['settings']->set(
    $rtCorruptPass['storeId'],
    MtUniCreditConstants::MODULE_SETTING_SMARTUCF_PASSWORD,
    'enc:v1:' . base64_encode(str_repeat('y', 40))
);
$resultCorruptPass = mtucCredHard_runProcess1($rtCorruptPass, '97014');
mtucCredHard_assert($resultCorruptPass->isFailed(), 'F: corrupt password ciphertext fails');
mtucCredHard_assert(
    Phase9TestHarness::smartUcfCallCount($rtCorruptPass['smartUcfProbe']) === 0,
    'F: corrupt password blocks before client'
);

$rtOk = mtucCredHard_runtimeStack(new Phase4FakeCpHttpTransport());
$rtOk['creds']->savePair($rtOk['storeId'], 'OK-U', 'OK-P');
$resultOk = mtucCredHard_runProcess1($rtOk, '97015');
mtucCredHard_assert($resultOk->isCreated(), 'F: valid pair allows SmartUCF success');
mtucCredHard_assert(
    Phase9TestHarness::smartUcfCallCount($rtOk['smartUcfProbe']) === 1,
    'F: valid pair invokes SmartUCF client once'
);
$live = Phase9TestHarness::smartUcfPayloadAt($rtOk['smartUcfProbe'], 0);
mtucCredHard_assert(
    is_array($live) && $live['user'] === 'OK-U' && $live['pass'] === 'OK-P',
    'F: valid pair reaches client with non-empty credentials'
);

$rtEmpty = mtucCredHard_runtimeStack(new Phase4FakeCpHttpTransport());
$resultEmpty = mtucCredHard_runProcess1($rtEmpty, '97016');
mtucCredHard_assert($resultEmpty->isFailed(), 'F: empty credentials fail before network');
mtucCredHard_assert(
    Phase9TestHarness::smartUcfCallCount($rtEmpty['smartUcfProbe']) === 0,
    'F: empty credentials never reach SmartUCF client'
);
mtucCredHard_assert(
    $resultEmpty->errorClass() === MtUniCreditSmartUcfSessionCoordinator::ERROR_CREDENTIALS_INCOMPLETE,
    'F: empty credentials classified as credentials_incomplete'
);

// ---------------------------------------------------------------------------
// G. Diagnostics redaction
// ---------------------------------------------------------------------------
$redacted = MtUniCreditDiagnosticPayloadRedactor::redact(array(
    'user' => 'visible-user',
    'pass' => 'visible-pass',
    'uni_user' => 'visible-uni-user',
    'uni_password' => 'visible-uni-pass',
    'sucfOnlineSessionID' => 'sess-keep-me',
    'nested' => array(
        'user' => 'nested-user',
        'sucfOnlineSessionID' => 'sess-nested',
    ),
));
$redactedJson = json_encode($redacted);
mtucCredHard_assert(
    is_string($redactedJson)
        && strpos($redactedJson, 'visible-user') === false
        && strpos($redactedJson, 'visible-pass') === false
        && strpos($redactedJson, 'visible-uni-user') === false
        && strpos($redactedJson, 'visible-uni-pass') === false
        && strpos($redactedJson, 'nested-user') === false,
    'G: user/pass/uni_user/uni_password redacted'
);
mtucCredHard_assert(
    isset($redacted['sucfOnlineSessionID']) && $redacted['sucfOnlineSessionID'] === 'sess-keep-me'
        && isset($redacted['nested']['sucfOnlineSessionID'])
        && $redacted['nested']['sucfOnlineSessionID'] === 'sess-nested',
    'G: sucfOnlineSessionID remains visible'
);

echo PHP_EOL . 'smartucf credential hardening: ' . $passes . ' passed, ' . count($failures) . ' failed' . PHP_EOL;
if ($failures) {
    foreach ($failures as $failure) {
        echo 'FAIL  ' . $failure . PHP_EOL;
    }
}
exit(count($failures) === 0 ? 0 : 1);
