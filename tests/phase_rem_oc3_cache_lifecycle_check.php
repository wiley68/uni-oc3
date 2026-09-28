<?php

/**
 * REM-OC3-CACHE-001 — deterministic shop-cache lifecycle coverage.
 *
 * Run: php tests/phase_rem_oc3_cache_lifecycle_check.php
 *
 * Offline (in-memory DB + fake CP transport). PHP 7.3 compatible. No live network.
 *
 * Covers: 24h TTL, 6h presentation-only LKG, submission never LKG, Class A/B/C taxonomy,
 * exact usable_until cleanup, single-flight ownership, push/manual semantics, secret-free
 * diagnostics and strict-resolution-before-side-effects ordering.
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
require_once __DIR__ . '/support/phase5_harness.php';
require_once __DIR__ . '/support/phase7_harness.php';
require_once __DIR__ . '/support/phase9_harness.php';
require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

/**
 * Transport double that raises an arbitrary Throwable (unknown/local defect injection).
 */
final class MtucRlcThrowingTransport implements MtUniCreditCpHttpTransport
{
    /** @var Throwable */
    private $exception;

    /**
     * @param Throwable $exception
     */
    public function __construct($exception)
    {
        $this->exception = $exception;
    }

    /**
     * @param string $method
     * @param string $url
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $payload
     * @return MtUniCreditCpHttpResponse
     */
    public function request($method, $url, array $headers, $payload)
    {
        throw $this->exception;
    }
}

/**
 * Minimal OpenCart-like config object.
 */
final class MtucRlcConfigStub
{
    /** @var array<string, mixed> */
    private $values;

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

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
 * Minimal OpenCart-like controller for storefront runtime memo tests.
 */
final class MtucRlcControllerStub
{
    /** @var MtucRlcConfigStub */
    public $config;

    /** @var Phase2MemoryDb */
    public $db;

    /**
     * @param MtucRlcConfigStub $config
     * @param Phase2MemoryDb $db
     */
    public function __construct(MtucRlcConfigStub $config, Phase2MemoryDb $db)
    {
        $this->config = $config;
        $this->db = $db;
    }
}

/**
 * Deterministic DB double that replaces the connection at the exact moment the live ownership
 * probe for a protected write runs: strictly AFTER acquire() and BEFORE the write.
 *
 * MySQL/MariaDB assign a new CONNECTION_ID() on reconnect and release every named advisory lock
 * owned by the dead connection, so a proof captured before the reconnect must no longer
 * authorize the write.
 */
final class MtucRlcReconnectOnWriteProbeDb
{
    /** @var Phase2MemoryDb */
    private $inner;

    /** @var bool Replace the connection on the next live ownership probe. */
    public $reconnectOnWriteProbe = true;

    /** @var int GET_LOCK() invocations observed (must stay 1: no silent re-acquire). */
    public $getLockCount = 0;

    /** @var int IS_USED_LOCK() ownership probes observed. */
    public $ownershipProbeCount = 0;

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
        $sqlText = (string) $sql;
        if (stripos($sqlText, 'GET_LOCK(') !== false) {
            $this->getLockCount++;
        }
        if (stripos($sqlText, 'IS_USED_LOCK(') !== false) {
            $this->ownershipProbeCount++;
            if ($this->reconnectOnWriteProbe) {
                $this->reconnectOnWriteProbe = false;
                $this->inner->simulateReconnect();
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

$failures = array();
$passes = 0;
$diagnostics = array();

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucRlc_assert($condition, $message)
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
 * @param int $now
 * @return MtUniCreditPersistenceClock
 */
function mtucRlc_clock($now)
{
    return new MtUniCreditPersistenceClock(function () use ($now) {
        return (int) $now;
    });
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function mtucRlc_snapshot(array $overrides = array())
{
    return mtuc4_valid_shop_snapshot($overrides);
}

/**
 * Build credentials/tokens/client for one store scope (transport double is injected).
 *
 * @param mixed $transport
 * @param Phase2MemoryDb $memoryDb
 * @param int $storeId
 * @param int $now
 * @return array<string, mixed>
 */
function mtucRlc_services($transport, $memoryDb, $storeId, $now)
{
    $db = new MtUniCreditDbAdapter($memoryDb, 'oc_');
    $settings = new MtUniCreditSettingStore($db, MtUniCreditConstants::MODULE_SETTINGS_CODE);
    Phase4TestHarness::prepareCredentials($settings, $storeId);
    $wallClock = function () use ($now) {
        return (int) $now;
    };
    $services = MtUniCreditCpServiceFactory::create(
        $db,
        $settings,
        $storeId,
        Phase4TestHarness::TEST_SHOP_URL,
        Phase4TestHarness::TEST_SHOP_URL,
        $transport,
        $wallClock,
        Phase4TestHarness::testSecretInput(),
        Phase4TestHarness::environmentConfigPath(),
        Phase4TestHarness::offlineDestinationPolicy()
    );

    return array(
        'db' => $db,
        'settings' => $settings,
        'services' => $services,
    );
}

/**
 * Full resolver wiring for one "PHP process / DB connection" against a memory DB.
 *
 * ONE exact-scope lock instance is shared by the persistence writer and the resolver so the
 * refresh owner persists under its connection-verified ownership (no nested lock acquisition).
 *
 * @param Phase2MemoryDb $memoryDb
 * @param mixed $transport CP transport double (fake, fault-injecting, etc.)
 * @param int $storeId
 * @param int $now
 * @param array<int, string> $diagnostics
 * @param callable|null $sleeper
 * @param MtUniCreditShopConfigurationRefreshLock|null $scopeLock Shared lock override
 * @return array<string, mixed>
 */
function mtucRlc_stack($memoryDb, $transport, $storeId, $now, &$diagnostics, $sleeper = null, $scopeLock = null)
{
    $boot = mtucRlc_services($transport, $memoryDb, $storeId, $now);
    $db = $boot['db'];
    $settings = $boot['settings'];
    $services = $boot['services'];
    $clock = mtucRlc_clock($now);
    $repository = new MtUniCreditShopCacheRepository($db, $clock);
    $refreshLock = $scopeLock instanceof MtUniCreditShopConfigurationRefreshLock
        ? $scopeLock
        : new MtUniCreditShopConfigurationRefreshLock($db);
    $persistence = new MtUniCreditShopCachePersistence(
        $repository,
        new MtUniCreditShopConfigurationSnapshotValidator(),
        MtUniCreditBootstrap::smartucfCredentialsRepositoryFromDb($db),
        $refreshLock
    );
    $logger = function ($message) use (&$diagnostics) {
        $diagnostics[] = (string) $message;
    };
    $service = new MtUniCreditShopConfigurationService(
        $services['credentials'],
        $repository,
        $services['client'],
        $services['tokens'],
        $storeId,
        null,
        $persistence,
        new MtUniCreditShopConfigurationFailureClassifier(),
        $refreshLock,
        $sleeper,
        $logger
    );

    return array(
        'memoryDb' => $memoryDb,
        'db' => $db,
        'settings' => $settings,
        'client' => $services['client'],
        'credentials' => $services['credentials'],
        'tokens' => $services['tokens'],
        'repository' => $repository,
        'persistence' => $persistence,
        'scopeLock' => $refreshLock,
        'refreshLock' => $refreshLock,
        'service' => $service,
        'transport' => $transport,
        'storeId' => (int) $storeId,
        'now' => (int) $now,
    );
}

/**
 * @param Phase2MemoryDb $memoryDb
 * @param int $storeId
 * @param int $now
 * @param array<string, mixed> $snapshot
 * @return void
 */
function mtucRlc_seed($memoryDb, $storeId, $now, array $snapshot)
{
    $db = new MtUniCreditDbAdapter($memoryDb, 'oc_');
    $clock = mtucRlc_clock($now);
    $persistence = new MtUniCreditShopCachePersistence(
        new MtUniCreditShopCacheRepository($db, $clock),
        new MtUniCreditShopConfigurationSnapshotValidator(),
        MtUniCreditBootstrap::smartucfCredentialsRepositoryFromDb($db),
        new MtUniCreditShopConfigurationRefreshLock($db)
    );
    $persistence->replaceValidatedSnapshot($storeId, Phase4TestHarness::TEST_UNICID, $snapshot);
}

/**
 * @param Phase4FakeCpHttpTransport $transport
 * @param array<string, mixed>|null $snapshot
 * @return void
 */
function mtucRlc_enqueueRefreshSuccess($transport, $snapshot = null)
{
    $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
    $transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload($snapshot));
}

/**
 * Successful login + TRANSIENT failure on the shop read (one recorded GET /shop attempt).
 *
 * @param Phase4FakeCpHttpTransport $transport
 * @return void
 */
function mtucRlc_enqueueRefreshTransient($transport)
{
    $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
    $transport->enqueueConnectionFailure();
}

/**
 * @param Phase4FakeCpHttpTransport $transport
 * @param int $status
 * @param string $error
 * @return void
 */
function mtucRlc_enqueueCanonicalFailure($transport, $status, $error)
{
    $transport->enqueueJson($status, array(
        'success' => false,
        'error' => (string) $error,
        'message' => 'canonical failure',
        'data' => new stdClass(),
    ));
}

/**
 * @param Phase4FakeCpHttpTransport $transport
 * @return int number of GET /shop requests
 */
function mtucRlc_shopGetCount($transport)
{
    $count = 0;
    foreach ($transport->requests as $request) {
        if (
            strtoupper((string) $request['method']) === 'GET'
            && strpos((string) $request['url'], '/shop') !== false
        ) {
            $count++;
        }
    }

    return $count;
}

/**
 * @param Phase4FakeCpHttpTransport $transport
 * @return int
 */
function mtucRlc_orderCreateCount($transport)
{
    $count = 0;
    foreach ($transport->requests as $request) {
        if (
            strtoupper((string) $request['method']) === 'POST'
            && substr((string) $request['url'], -7) === '/orders'
        ) {
            $count++;
        }
    }

    return $count;
}

/**
 * @param array<string, mixed> $stack
 * @param string $tokenName
 * @return string|null
 */
function mtucRlc_setting($stack, $tokenName)
{
    $value = $stack['settings']->get($stack['storeId'], $tokenName);

    return $value === null ? null : (string) $value;
}

$STORE = Phase4TestHarness::TEST_STORE_ID;
$UNICID = Phase4TestHarness::TEST_UNICID;
$T0 = 1700000000;
$TTL = (int) MtUniCreditSecurityConstants::SHOP_CACHE_TTL_SECONDS;
$LKG = (int) MtUniCreditSecurityConstants::SHOP_CACHE_LKG_SECONDS;
$noSleep = function ($microseconds) {
    unset($microseconds);
};

mtucRlc_assert($TTL === 86400, '1a TTL constant remains 86400');
mtucRlc_assert($LKG === 21600, '1b LKG constant is 21600');

// ---------------------------------------------------------------------------
// 1) fresh local → zero GET
// ---------------------------------------------------------------------------
$memoryDb = new Phase2MemoryDb();
$transport = new Phase4FakeCpHttpTransport();
mtucRlc_seed($memoryDb, $STORE, $T0, mtucRlc_snapshot(array('uni_maxstojnost' => 9000)));
$stack = mtucRlc_stack($memoryDb, $transport, $STORE, $T0 + 60, $diagnostics, $noSleep);
$fresh = $stack['service']->getForPresentation();
mtucRlc_assert(is_array($fresh) && (int) $fresh['uni_maxstojnost'] === 9000, '1c fresh local snapshot returned');
mtucRlc_assert($transport->requests === array(), '1d fresh local path performs zero HTTP');

// ---------------------------------------------------------------------------
// 2) exact expiry → refresh required
// ---------------------------------------------------------------------------
$memoryDb = new Phase2MemoryDb();
$transport = new Phase4FakeCpHttpTransport();
mtucRlc_seed($memoryDb, $STORE, $T0, mtucRlc_snapshot(array('uni_maxstojnost' => 9000)));
$stack = mtucRlc_stack($memoryDb, $transport, $STORE, $T0 + $TTL, $diagnostics, $noSleep);
$inspection = $stack['repository']->inspectScope($STORE, $UNICID);
mtucRlc_assert($inspection['state'] === MtUniCreditShopCacheRepository::STATE_STALE, '2a exact expiry is no longer fresh');
mtucRlc_assert((int) $inspection['stale_seconds'] === 0, '2b exact expiry retains the boundary (stale_seconds = 0)');
mtucRlc_enqueueRefreshSuccess($transport, mtucRlc_snapshot(array('uni_maxstojnost' => 9500)));
$refreshed = $stack['service']->getForPresentation();
mtucRlc_assert(is_array($refreshed) && (int) $refreshed['uni_maxstojnost'] === 9500, '2c exact expiry triggers a coordinated refresh');
mtucRlc_assert(mtucRlc_shopGetCount($transport) === 1, '2d exact expiry performs exactly one GET /shop');

// ---------------------------------------------------------------------------
// 3) stale + refresh success (TTL reset)
// ---------------------------------------------------------------------------
$memoryDb = new Phase2MemoryDb();
$transport = new Phase4FakeCpHttpTransport();
mtucRlc_seed($memoryDb, $STORE, $T0, mtucRlc_snapshot(array('uni_maxstojnost' => 9000)));
$stack = mtucRlc_stack($memoryDb, $transport, $STORE, $T0 + $TTL + 3600, $diagnostics, $noSleep);
mtucRlc_enqueueRefreshSuccess($transport, mtucRlc_snapshot(array('uni_maxstojnost' => 9600)));
$staleRefreshed = $stack['service']->getForPresentation();
mtucRlc_assert(is_array($staleRefreshed) && (int) $staleRefreshed['uni_maxstojnost'] === 9600, '3a stale + refresh success returns the fresh snapshot');
$afterRefresh = $stack['repository']->inspectScope($STORE, $UNICID);
mtucRlc_assert($afterRefresh['state'] === MtUniCreditShopCacheRepository::STATE_FRESH, '3b replacement resets the TTL window');
mtucRlc_assert(
    $afterRefresh['expires_at'] === gmdate('Y-m-d H:i:s', $T0 + $TTL + 3600 + $TTL),
    '3c replacement resets expires_at = refresh time + TTL'
);

// ---------------------------------------------------------------------------
// 4) stale <= 6h + transient presentation → LKG
// ---------------------------------------------------------------------------
$memoryDb = new Phase2MemoryDb();
$transport = new Phase4FakeCpHttpTransport();
mtucRlc_seed($memoryDb, $STORE, $T0, mtucRlc_snapshot(array('uni_maxstojnost' => 9000)));
$stack = mtucRlc_stack($memoryDb, $transport, $STORE, $T0 + $TTL + 3600, $diagnostics, $noSleep);
mtucRlc_enqueueRefreshTransient($transport);
$lkg = $stack['service']->getForPresentation();
mtucRlc_assert(
    is_array($lkg) && (int) $lkg['uni_maxstojnost'] === 9000,
    '4a stale + TRANSIENT presentation failure returns eligible LKG'
);
$stateAfterLkg = $stack['repository']->inspectScope($STORE, $UNICID);
mtucRlc_assert($stateAfterLkg['state'] === MtUniCreditShopCacheRepository::STATE_STALE, '4b Class A preserves known-good state');
mtucRlc_assert((int) $stateAfterLkg['stale_seconds'] === 3600, '4c stale age is reported for diagnostics');

// ---------------------------------------------------------------------------
// 5) stale > 6h → fail closed (no LKG, no purge)
// ---------------------------------------------------------------------------
$memoryDb = new Phase2MemoryDb();
$transport = new Phase4FakeCpHttpTransport();
mtucRlc_seed($memoryDb, $STORE, $T0, mtucRlc_snapshot(array('uni_maxstojnost' => 9000)));
$stack = mtucRlc_stack($memoryDb, $transport, $STORE, $T0 + $TTL + $LKG + 1, $diagnostics, $noSleep);
$inspection = $stack['repository']->inspectScope($STORE, $UNICID);
mtucRlc_assert($inspection['state'] === MtUniCreditShopCacheRepository::STATE_TOO_OLD, '5a beyond usable_until is purge-eligible state');
mtucRlc_assert($inspection['lkg_eligible'] === false, '5b beyond usable_until is never LKG-eligible');
mtucRlc_enqueueRefreshTransient($transport);
mtucRlc_assert($stack['service']->getForPresentation() === null, '5c stale > 6h + TRANSIENT fails closed (no LKG)');
mtucRlc_assert($stack['repository']->inspectScope($STORE, $UNICID)['row_present'] === true, '5d Class A never purges the row');

// ---------------------------------------------------------------------------
// 6) submission never uses LKG
// ---------------------------------------------------------------------------
$memoryDb = new Phase2MemoryDb();
$transport = new Phase4FakeCpHttpTransport();
mtucRlc_seed($memoryDb, $STORE, $T0, mtucRlc_snapshot(array('uni_maxstojnost' => 9000)));
$stack = mtucRlc_stack($memoryDb, $transport, $STORE, $T0 + $TTL + 3600, $diagnostics, $noSleep);
mtucRlc_enqueueRefreshTransient($transport);
$submissionFailure = null;
try {
    $stack['service']->getForSubmission();
} catch (MtUniCreditShopConfigurationUnavailableException $exception) {
    $submissionFailure = $exception;
}
mtucRlc_assert(
    $submissionFailure instanceof MtUniCreditShopConfigurationUnavailableException
        && $submissionFailure->reason() === MtUniCreditShopConfigurationUnavailableException::REASON_TRANSIENT,
    '6a submission fails closed on TRANSIENT (never LKG)'
);
mtucRlc_assert(
    $submissionFailure instanceof MtUniCreditShopConfigurationUnavailableException
        && $submissionFailure->failureClass() === MtUniCreditShopConfigurationFailureClassifier::TRANSIENT,
    '6b submission reports the TRANSIENT failure class'
);
$transport->enqueueConnectionFailure();
mtucRlc_assert($stack['service']->getForPresentation() !== null, '6c presentation still serves eligible LKG for the same scope');

// ---------------------------------------------------------------------------
// 7) Class B purge + no resurrection
// ---------------------------------------------------------------------------
$memoryDb = new Phase2MemoryDb();
$transport = new Phase4FakeCpHttpTransport();
mtucRlc_seed($memoryDb, $STORE, $T0, mtucRlc_snapshot());
$stack = mtucRlc_stack($memoryDb, $transport, $STORE, $T0, $diagnostics, $noSleep);
mtucRlc_enqueueRefreshSuccess($transport, mtucRlc_snapshot(array('uni_maxstojnost' => 9100)));
$stack['service']->refreshRemote();
mtucRlc_assert(mtucRlc_setting($stack, MtUniCreditCpTokenRepository::ACCESS_TOKEN) !== null, '7a successful refresh stores a CP token');
// Keep the CP token valid while making the SNAPSHOT stale, so a Class B purge (not mere
// token expiry) is what invalidates it.
$rawAfterRefresh = $stack['repository']->findEncodedShopData($STORE, $UNICID);
$memoryDb->seedRawShopCache(
    $STORE,
    $UNICID,
    $rawAfterRefresh,
    gmdate('Y-m-d H:i:s', $T0 - 7200),
    gmdate('Y-m-d H:i:s', $T0 - 3600)
);

$transportB = new Phase4FakeCpHttpTransport();
// The first canonical 401 invalidates the local token and triggers ONE idempotent re-login
// retry (frozen CP-AUTH-002). The retry must also be rejected before Class B is established.
mtucRlc_enqueueCanonicalFailure($transportB, 401, 'authentication_failed');
mtucRlc_enqueueCanonicalFailure($transportB, 401, 'authentication_failed');
$stackB = mtucRlc_stack($memoryDb, $transportB, $STORE, $T0 + 100, $diagnostics, $noSleep);
mtucRlc_assert($stackB['service']->getForPresentation() === null, '7b Class B presentation fails closed');
mtucRlc_assert(
    $stackB['repository']->inspectScope($STORE, $UNICID)['state'] === MtUniCreditShopCacheRepository::STATE_MISSING,
    '7c Class B purges the exact scoped row'
);
mtucRlc_assert(
    mtucRlc_setting($stackB, MtUniCreditCpTokenRepository::ACCESS_TOKEN) === null,
    '7d Class B invalidates the scoped CP token'
);

$transportB2 = new Phase4FakeCpHttpTransport();
$transportB2->enqueueConnectionFailure();
$stackB2 = mtucRlc_stack($memoryDb, $transportB2, $STORE, $T0 + 200, $diagnostics, $noSleep);
mtucRlc_assert($stackB2['service']->getForPresentation() === null, '7e a later TRANSIENT failure cannot resurrect purged state');
mtucRlc_assert(
    $stackB2['repository']->inspectScope($STORE, $UNICID)['state'] === MtUniCreditShopCacheRepository::STATE_MISSING,
    '7f purged scope stays missing after a TRANSIENT failure'
);

// ---------------------------------------------------------------------------
// 8/9/10) Class C preserves bytes, no same-attempt LKG, later transient may use LKG
// ---------------------------------------------------------------------------
$memoryDb = new Phase2MemoryDb();
$transport = new Phase4FakeCpHttpTransport();
mtucRlc_seed($memoryDb, $STORE, $T0, mtucRlc_snapshot());
$stack = mtucRlc_stack($memoryDb, $transport, $STORE, $T0, $diagnostics, $noSleep);
mtucRlc_enqueueRefreshSuccess($transport, mtucRlc_snapshot(array('uni_maxstojnost' => 9100)));
$stack['service']->refreshRemote();
$rawBefore = $stack['repository']->findEncodedShopData($STORE, $UNICID);
$tokenBefore = mtucRlc_setting($stack, MtUniCreditCpTokenRepository::ACCESS_TOKEN);
mtucRlc_assert(is_string($rawBefore) && $rawBefore !== '', '8a fixture row exists before the Class C attempt');
$memoryDb->seedRawShopCache(
    $STORE,
    $UNICID,
    $rawBefore,
    gmdate('Y-m-d H:i:s', $T0 - 7200),
    gmdate('Y-m-d H:i:s', $T0 - 3600)
);

$transportC = new Phase4FakeCpHttpTransport();
mtucRlc_enqueueCanonicalFailure($transportC, 422, 'invalid_payload');
$stackC = mtucRlc_stack($memoryDb, $transportC, $STORE, $T0 + 100, $diagnostics, $noSleep);
mtucRlc_assert($stackC['service']->getForPresentation() === null, '9a Class C: no same-attempt LKG');
mtucRlc_assert(
    $stackC['repository']->findEncodedShopData($STORE, $UNICID) === $rawBefore,
    '8b Class C preserves the stored row byte-identically'
);
mtucRlc_assert(
    mtucRlc_setting($stackC, MtUniCreditCpTokenRepository::ACCESS_TOKEN) === $tokenBefore,
    '8c Class C preserves the CP token bytes'
);

$transportC2 = new Phase4FakeCpHttpTransport();
$transportC2->enqueueConnectionFailure();
$stackC2 = mtucRlc_stack($memoryDb, $transportC2, $STORE, $T0 + 200, $diagnostics, $noSleep);
$laterLkg = $stackC2['service']->getForPresentation();
mtucRlc_assert(
    is_array($laterLkg) && (int) $laterLkg['uni_maxstojnost'] === 9100,
    '10a later independent TRANSIENT request may use eligible LKG after Class C'
);

// ---------------------------------------------------------------------------
// 11) missing cache
// ---------------------------------------------------------------------------
$memoryDb = new Phase2MemoryDb();
$transport = new Phase4FakeCpHttpTransport();
$stack = mtucRlc_stack($memoryDb, $transport, $STORE, $T0, $diagnostics, $noSleep);
mtucRlc_enqueueRefreshTransient($transport);
mtucRlc_assert($stack['service']->getForPresentation() === null, '11a missing cache + failed refresh fails closed');
mtucRlc_assert(mtucRlc_shopGetCount($transport) === 1, '11b missing cache triggers exactly one owner GET /shop');
$missingSubmission = null;
try {
    $stack['service']->getForSubmission();
} catch (MtUniCreditShopConfigurationUnavailableException $exception) {
    $missingSubmission = $exception;
}
mtucRlc_assert($missingSubmission !== null, '11c missing cache strict submission fails closed');

$memoryDb = new Phase2MemoryDb();
$transport = new Phase4FakeCpHttpTransport();
$stack = mtucRlc_stack($memoryDb, $transport, $STORE, $T0, $diagnostics, $noSleep);
mtucRlc_enqueueRefreshSuccess($transport, mtucRlc_snapshot());
mtucRlc_assert(is_array($stack['service']->getForPresentation()), '11d missing cache + success returns a fresh snapshot');
mtucRlc_assert(mtucRlc_shopGetCount($transport) === 1, '11e missing cache performs one owner GET /shop only');

// ---------------------------------------------------------------------------
// 12) corrupt stored JSON
// ---------------------------------------------------------------------------
$memoryDb = new Phase2MemoryDb();
$memoryDb->seedRawShopCache(
    $STORE,
    $UNICID,
    '{not-json',
    gmdate('Y-m-d H:i:s', $T0),
    gmdate('Y-m-d H:i:s', $T0 + $TTL)
);
$transport = new Phase4FakeCpHttpTransport();
$stack = mtucRlc_stack($memoryDb, $transport, $STORE, $T0 + 60, $diagnostics, $noSleep);
mtucRlc_assert(
    $stack['repository']->inspectScope($STORE, $UNICID)['state'] === MtUniCreditShopCacheRepository::STATE_CORRUPT,
    '12a corrupt stored JSON is reported as corrupt'
);
mtucRlc_enqueueRefreshTransient($transport);
mtucRlc_assert($stack['service']->getForPresentation() === null, '12b corrupt + TRANSIENT fails closed (no LKG)');
mtucRlc_assert(
    $stack['repository']->inspectScope($STORE, $UNICID)['state'] === MtUniCreditShopCacheRepository::STATE_CORRUPT,
    '12c corrupt row is not replaced by a failed attempt'
);
$memoryDbRepair = new Phase2MemoryDb();
$memoryDbRepair->seedRawShopCache(
    $STORE,
    $UNICID,
    '{not-json',
    gmdate('Y-m-d H:i:s', $T0),
    gmdate('Y-m-d H:i:s', $T0 + $TTL)
);
$transport = new Phase4FakeCpHttpTransport();
$stack = mtucRlc_stack($memoryDbRepair, $transport, $STORE, $T0 + 60, $diagnostics, $noSleep);
mtucRlc_enqueueRefreshSuccess($transport, mtucRlc_snapshot(array('uni_maxstojnost' => 9300)));
$repaired = $stack['service']->getForPresentation();
mtucRlc_assert(is_array($repaired) && (int) $repaired['uni_maxstojnost'] === 9300, '12d corrupt + successful refresh repairs the row');

// ---------------------------------------------------------------------------
// 13) wrong scope
// ---------------------------------------------------------------------------
$memoryDb = new Phase2MemoryDb();
mtucRlc_seed($memoryDb, $STORE + 1, $T0, mtucRlc_snapshot());
$transport = new Phase4FakeCpHttpTransport();
$stack = mtucRlc_stack($memoryDb, $transport, $STORE, $T0, $diagnostics, $noSleep);
mtucRlc_assert(
    $stack['repository']->inspectScope($STORE, $UNICID)['state'] === MtUniCreditShopCacheRepository::STATE_MISSING,
    '13a another store row is not visible in this scope'
);
mtucRlc_enqueueRefreshTransient($transport);
mtucRlc_assert($stack['service']->getForPresentation() === null, '13b wrong scope never serves another store snapshot');
mtucRlc_assert(mtucRlc_shopGetCount($transport) === 1, '13c wrong scope requires its own owner refresh');

// ---------------------------------------------------------------------------
// 14) uni_status=0 blocks financing / 15) uni_container_status=0 hides homepage only
// ---------------------------------------------------------------------------
$calc = new MtUniCreditCalculator();
$cartFactory = new MtUniCreditOc3CartContextFactory(function () {
    return array(7);
});
$cart = $cartFactory->create(Phase5TestHarness::cartProducts(), 500.0);

$offShop = mtucRlc_snapshot(array('uni_status' => 0));
$offResolution = (new MtUniCreditCartSchemeResolver($calc))->resolve($offShop, $cart);
mtucRlc_assert(
    (new MtUniCreditCartSchemeResolver($calc))->unifiedSchemes($offResolution, $offShop) === array(),
    '14a uni_status=0 blocks financing schemes'
);
mtucRlc_assert(
    (new MtUniCreditHomepageAdvertisingGate())->allowsShop($offShop) === false,
    '14b uni_status=0 also hides the homepage surface'
);

$containerOff = mtucRlc_snapshot(array('uni_container_status' => 0));
mtucRlc_assert(
    (new MtUniCreditHomepageAdvertisingGate())->allowsShop($containerOff) === false,
    '15a uni_container_status=0 hides the homepage surface'
);
$containerOffResolution = (new MtUniCreditCartSchemeResolver($calc))->resolve($containerOff, $cart);
mtucRlc_assert(
    count((new MtUniCreditCartSchemeResolver($calc))->unifiedSchemes($containerOffResolution, $containerOff)) > 0,
    '15b uni_container_status=0 does not block financing schemes'
);

// ---------------------------------------------------------------------------
// 16) valid push / 17) invalid push preservation
// ---------------------------------------------------------------------------
$memoryDb = new Phase2MemoryDb();
$transport = new Phase4FakeCpHttpTransport();
mtucRlc_seed($memoryDb, $STORE, $T0 - $TTL, mtucRlc_snapshot(array('uni_maxstojnost' => 9000)));
$stack = mtucRlc_stack($memoryDb, $transport, $STORE, $T0, $diagnostics, $noSleep);
mtucRlc_assert(
    $stack['service']->replaceSnapshot($UNICID, mtucRlc_snapshot(array('uni_maxstojnost' => 9700))) === true,
    '16a valid push replaces the snapshot'
);
$pushed = $stack['repository']->inspectScope($STORE, $UNICID);
mtucRlc_assert($pushed['state'] === MtUniCreditShopCacheRepository::STATE_FRESH, '16b valid push resets the TTL window');
mtucRlc_assert($pushed['expires_at'] === gmdate('Y-m-d H:i:s', $T0 + $TTL), '16c valid push sets expires_at = push time + TTL');
mtucRlc_assert($transport->requests === array(), '16d push never calls the Control Panel');

$rawBeforeInvalidPush = $stack['repository']->findEncodedShopData($STORE, $UNICID);
$invalidPushRejected = false;
try {
    $invalidPushRejected = $stack['service']->replaceSnapshot($UNICID, array('not' => 'a snapshot')) === false;
} catch (MtUniCreditShopSnapshotValidationException $exception) {
    $invalidPushRejected = true;
}
mtucRlc_assert($invalidPushRejected, '17a invalid push is rejected');
mtucRlc_assert(
    $stack['repository']->findEncodedShopData($STORE, $UNICID) === $rawBeforeInvalidPush,
    '17b invalid push preserves the known-good cache byte-identically'
);
mtucRlc_assert($transport->requests === array(), '17c rejected push never calls the Control Panel');

// ---------------------------------------------------------------------------
// 18) manual refresh classes
// ---------------------------------------------------------------------------
$memoryDb = new Phase2MemoryDb();
mtucRlc_seed($memoryDb, $STORE, $T0, mtucRlc_snapshot());
$manualA = new Phase4FakeCpHttpTransport();
$manualA->enqueueConnectionFailure();
$stackA = mtucRlc_stack($memoryDb, $manualA, $STORE, $T0 + $TTL + 60, $diagnostics, $noSleep);
$rawBeforeManualA = $stackA['repository']->findEncodedShopData($STORE, $UNICID);
$manualAFailed = false;
try {
    $stackA['service']->refreshRemote();
} catch (MtUniCreditCpException $exception) {
    $manualAFailed = true;
}
mtucRlc_assert($manualAFailed, '18a manual refresh Class A reports a technical failure');
mtucRlc_assert(
    $stackA['repository']->findEncodedShopData($STORE, $UNICID) === $rawBeforeManualA,
    '18b manual refresh Class A preserves the row'
);

$memoryDb = new Phase2MemoryDb();
mtucRlc_seed($memoryDb, $STORE, $T0, mtucRlc_snapshot());
$manualB = new Phase4FakeCpHttpTransport();
mtucRlc_enqueueCanonicalFailure($manualB, 401, 'authentication_failed');
$stackManualB = mtucRlc_stack($memoryDb, $manualB, $STORE, $T0 + $TTL + 60, $diagnostics, $noSleep);
$manualBFailed = false;
try {
    $stackManualB['service']->refreshRemote();
} catch (MtUniCreditCpException $exception) {
    $manualBFailed = true;
}
mtucRlc_assert($manualBFailed, '18c manual refresh Class B fails closed');
mtucRlc_assert(
    $stackManualB['repository']->inspectScope($STORE, $UNICID)['state'] === MtUniCreditShopCacheRepository::STATE_MISSING,
    '18d manual refresh Class B purges the exact scope'
);

$memoryDb = new Phase2MemoryDb();
mtucRlc_seed($memoryDb, $STORE, $T0, mtucRlc_snapshot());
$manualC = new Phase4FakeCpHttpTransport();
mtucRlc_enqueueCanonicalFailure($manualC, 422, 'invalid_payload');
$stackManualC = mtucRlc_stack($memoryDb, $manualC, $STORE, $T0 + $TTL + 60, $diagnostics, $noSleep);
$rawBeforeManualC = $stackManualC['repository']->findEncodedShopData($STORE, $UNICID);
$manualCFailed = false;
try {
    $stackManualC['service']->refreshRemote();
} catch (MtUniCreditCpException $exception) {
    $manualCFailed = true;
}
mtucRlc_assert($manualCFailed, '18e manual refresh Class C reports an invalid response');
mtucRlc_assert(
    $stackManualC['repository']->findEncodedShopData($STORE, $UNICID) === $rawBeforeManualC,
    '18f manual refresh Class C preserves the row'
);

// ---------------------------------------------------------------------------
// 19) exact usable_until retained / 20) usable_until + 1 is purge-eligible
// ---------------------------------------------------------------------------
$memoryDb = new Phase2MemoryDb();
$expiresAt = $T0 + $TTL;
$memoryDb->seedRawShopCache(
    $STORE,
    $UNICID,
    json_encode(mtucRlc_snapshot(), JSON_UNESCAPED_UNICODE),
    gmdate('Y-m-d H:i:s', $T0),
    gmdate('Y-m-d H:i:s', $expiresAt)
);
$atBoundary = mtucRlc_stack($memoryDb, new Phase4FakeCpHttpTransport(), $STORE, $expiresAt + $LKG, $diagnostics, $noSleep);
$boundaryState = $atBoundary['repository']->inspectScope($STORE, $UNICID);
mtucRlc_assert($boundaryState['state'] === MtUniCreditShopCacheRepository::STATE_STALE, '19a exact usable_until is retained');
mtucRlc_assert((int) $boundaryState['stale_seconds'] === $LKG, '19b stale age at usable_until is exactly LKG');
mtucRlc_assert(
    $boundaryState['usable_until'] === gmdate('Y-m-d H:i:s', $expiresAt + $LKG),
    '19c usable_until is reported exactly'
);
mtucRlc_assert($atBoundary['repository']->deleteExpiredBatch(50) === 0, '19d cleanup at exact usable_until deletes nothing');
mtucRlc_assert(
    $atBoundary['repository']->inspectScope($STORE, $UNICID)['row_present'] === true,
    '19e row still present at exact usable_until'
);

$pastBoundary = mtucRlc_stack($memoryDb, new Phase4FakeCpHttpTransport(), $STORE, $expiresAt + $LKG + 1, $diagnostics, $noSleep);
mtucRlc_assert(
    $pastBoundary['repository']->inspectScope($STORE, $UNICID)['state'] === MtUniCreditShopCacheRepository::STATE_TOO_OLD,
    '20a usable_until + 1 second is purge-eligible state'
);
mtucRlc_assert($pastBoundary['repository']->deleteExpiredBatch(50) === 1, '20b usable_until + 1 second is purged');
mtucRlc_assert(
    $pastBoundary['repository']->inspectScope($STORE, $UNICID)['state'] === MtUniCreditShopCacheRepository::STATE_MISSING,
    '20c cleanup removed the purge-eligible row'
);

// ---------------------------------------------------------------------------
// 21/22) stale presentation + strict submission contention
// ---------------------------------------------------------------------------
$memoryDb = new Phase2MemoryDb();
mtucRlc_seed($memoryDb, $STORE, $T0, mtucRlc_snapshot(array('uni_maxstojnost' => 9000)));
$ownerLock = new MtUniCreditShopConfigurationRefreshLock(new MtUniCreditDbAdapter($memoryDb, 'oc_'));
$ownerToken = $ownerLock->acquire($STORE, $UNICID);
mtucRlc_assert(is_string($ownerToken) && $ownerToken !== '', '21a owner acquires the scope refresh lock');

$peer = $memoryDb->newSharedConnection();
$peerTransport = new Phase4FakeCpHttpTransport();
$peerStack = mtucRlc_stack($peer, $peerTransport, $STORE, $T0 + $TTL + 3600, $diagnostics, $noSleep);
$contenderLkg = $peerStack['service']->getForPresentation();
mtucRlc_assert(
    $contenderLkg === null,
    '21b contender with an eligible stale row returns null (NO LKG without its own refresh)'
);
mtucRlc_assert($peerTransport->requests === array(), '21c presentation contender never calls the Control Panel');
mtucRlc_assert(
    $peerStack['repository']->inspectScope($STORE, $UNICID)['row_present'] === true,
    '21d contender neither purges nor rewrites the owner scope'
);

$contenderSubmission = null;
try {
    $peerStack['service']->getForSubmission();
} catch (MtUniCreditShopConfigurationUnavailableException $exception) {
    $contenderSubmission = $exception;
}
mtucRlc_assert(
    $contenderSubmission instanceof MtUniCreditShopConfigurationUnavailableException
        && $contenderSubmission->reason() === MtUniCreditShopConfigurationUnavailableException::REASON_CONTENDED,
    '22a strict submission contention waits, re-reads and fails closed'
);
mtucRlc_assert($peerTransport->requests === array(), '22b submission contender never calls the Control Panel');
mtucRlc_assert($ownerLock->release($STORE, $UNICID, $ownerToken)['ok'] === true, '22c owner releases the scope lock');

// ---------------------------------------------------------------------------
// 23) missing contention
// ---------------------------------------------------------------------------
$memoryDbMissing = new Phase2MemoryDb();
$missingOwnerLock = new MtUniCreditShopConfigurationRefreshLock(new MtUniCreditDbAdapter($memoryDbMissing, 'oc_'));
$missingOwnerToken = $missingOwnerLock->acquire($STORE, $UNICID);
mtucRlc_assert(is_string($missingOwnerToken), '23a missing-scope owner lock acquired');
$missingPeer = $memoryDbMissing->newSharedConnection();
$missingTransport = new Phase4FakeCpHttpTransport();
$missingStack = mtucRlc_stack($missingPeer, $missingTransport, $STORE, $T0, $diagnostics, $noSleep);
mtucRlc_assert($missingStack['service']->getForPresentation() === null, '23b missing-scope contender fails closed');
$missingSubmission = null;
try {
    $missingStack['service']->getForSubmission();
} catch (MtUniCreditShopConfigurationUnavailableException $exception) {
    $missingSubmission = $exception;
}
mtucRlc_assert($missingSubmission !== null, '23c missing-scope strict contention fails closed');
mtucRlc_assert($missingTransport->requests === array(), '23d missing-scope contender never calls the Control Panel');
$missingOwnerLock->release($STORE, $UNICID, $missingOwnerToken);

// ---------------------------------------------------------------------------
// 24) too-old contention
// ---------------------------------------------------------------------------
$memoryDbTooOld = new Phase2MemoryDb();
$memoryDbTooOld->seedRawShopCache(
    $STORE,
    $UNICID,
    json_encode(mtucRlc_snapshot(), JSON_UNESCAPED_UNICODE),
    gmdate('Y-m-d H:i:s', $T0),
    gmdate('Y-m-d H:i:s', $T0 + $TTL)
);
$tooOldOwnerLock = new MtUniCreditShopConfigurationRefreshLock(new MtUniCreditDbAdapter($memoryDbTooOld, 'oc_'));
$tooOldOwnerToken = $tooOldOwnerLock->acquire($STORE, $UNICID);
mtucRlc_assert(is_string($tooOldOwnerToken), '24a too-old scope owner lock acquired');
$tooOldPeer = $memoryDbTooOld->newSharedConnection();
$tooOldTransport = new Phase4FakeCpHttpTransport();
$tooOldStack = mtucRlc_stack($tooOldPeer, $tooOldTransport, $STORE, $T0 + $TTL + $LKG + 5, $diagnostics, $noSleep);
mtucRlc_assert($tooOldStack['service']->getForPresentation() === null, '24b too-old contender fails closed (no LKG)');
$tooOldSubmission = null;
try {
    $tooOldStack['service']->getForSubmission();
} catch (MtUniCreditShopConfigurationUnavailableException $exception) {
    $tooOldSubmission = $exception;
}
mtucRlc_assert($tooOldSubmission !== null, '24c too-old strict contention fails closed');
mtucRlc_assert($tooOldTransport->requests === array(), '24d too-old contender never calls the Control Panel');
$tooOldOwnerLock->release($STORE, $UNICID, $tooOldOwnerToken);

// ---------------------------------------------------------------------------
// 25) corrupt contention
// ---------------------------------------------------------------------------
$memoryDbCorrupt = new Phase2MemoryDb();
$memoryDbCorrupt->seedRawShopCache(
    $STORE,
    $UNICID,
    '{"uni_status":',
    gmdate('Y-m-d H:i:s', $T0),
    gmdate('Y-m-d H:i:s', $T0 + $TTL)
);
$corruptOwnerLock = new MtUniCreditShopConfigurationRefreshLock(new MtUniCreditDbAdapter($memoryDbCorrupt, 'oc_'));
$corruptOwnerToken = $corruptOwnerLock->acquire($STORE, $UNICID);
mtucRlc_assert(is_string($corruptOwnerToken), '25a corrupt-scope owner lock acquired');
$corruptPeer = $memoryDbCorrupt->newSharedConnection();
$corruptPeerTransport = new Phase4FakeCpHttpTransport();
$corruptPeerStack = mtucRlc_stack($corruptPeer, $corruptPeerTransport, $STORE, $T0 + 60, $diagnostics, $noSleep);
mtucRlc_assert($corruptPeerStack['service']->getForPresentation() === null, '25b corrupt contender fails closed (no LKG)');
$corruptSubmission = null;
try {
    $corruptPeerStack['service']->getForSubmission();
} catch (MtUniCreditShopConfigurationUnavailableException $exception) {
    $corruptSubmission = $exception;
}
mtucRlc_assert($corruptSubmission !== null, '25c corrupt strict contention fails closed');
mtucRlc_assert($corruptPeerTransport->requests === array(), '25d corrupt contender never calls the Control Panel');
$corruptOwnerLock->release($STORE, $UNICID, $corruptOwnerToken);

// ---------------------------------------------------------------------------
// 26) owner crash recovery
// ---------------------------------------------------------------------------
$memoryCrash = new Phase2MemoryDb();
$crashOwnerLock = new MtUniCreditShopConfigurationRefreshLock(new MtUniCreditDbAdapter($memoryCrash, 'oc_'));
$crashOwnerToken = $crashOwnerLock->acquire($STORE, $UNICID);
$crashPeer = $memoryCrash->newSharedConnection();
$crashPeerLock = new MtUniCreditShopConfigurationRefreshLock(new MtUniCreditDbAdapter($crashPeer, 'oc_'));
mtucRlc_assert(is_string($crashOwnerToken), '26a owner acquires the scope lock');
mtucRlc_assert($crashPeerLock->acquire($STORE, $UNICID) === null, '26b a second connection cannot own the same scope');
$memoryCrash->simulateConnectionClose();
$recoveredToken = $crashPeerLock->acquire($STORE, $UNICID);
mtucRlc_assert(is_string($recoveredToken) && $recoveredToken !== '', '26c crashed owner connection releases the scope');
mtucRlc_assert($crashPeerLock->release($STORE, $UNICID, $recoveredToken)['ok'] === true, '26d recovered owner can release');

// ---------------------------------------------------------------------------
// 27) cross-scope concurrency
// ---------------------------------------------------------------------------
$memoryScope = new Phase2MemoryDb();
$scopePeer = $memoryScope->newSharedConnection();
$scopeLockA = new MtUniCreditShopConfigurationRefreshLock(new MtUniCreditDbAdapter($memoryScope, 'oc_'));
$scopeLockB = new MtUniCreditShopConfigurationRefreshLock(new MtUniCreditDbAdapter($scopePeer, 'oc_'));
$scopeTokenA = $scopeLockA->acquire($STORE, $UNICID);
mtucRlc_assert(is_string($scopeTokenA), '27a scope 1 owner acquired');
$scope2Unicid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
$scopeTokenB = $scopeLockB->acquire($STORE, $scope2Unicid);
mtucRlc_assert(is_string($scopeTokenB) && $scopeTokenB !== '', '27b different scope is independently acquirable');
$thirdPeer = $memoryScope->newSharedConnection();
$thirdLock = new MtUniCreditShopConfigurationRefreshLock(new MtUniCreditDbAdapter($thirdPeer, 'oc_'));
mtucRlc_assert($thirdLock->acquire($STORE, $UNICID) === null, '27c scope 1 remains owned while scope 2 is held');
$scopeLockB->release($STORE, $scope2Unicid, $scopeTokenB);
$scopeLockA->release($STORE, $UNICID, $scopeTokenA);

// ---------------------------------------------------------------------------
// 28) non-owner release
// ---------------------------------------------------------------------------
$memoryRelease = new Phase2MemoryDb();
$releasePeer = $memoryRelease->newSharedConnection();
$lockX = new MtUniCreditShopConfigurationRefreshLock(new MtUniCreditDbAdapter($memoryRelease, 'oc_'));
$lockY = new MtUniCreditShopConfigurationRefreshLock(new MtUniCreditDbAdapter($releasePeer, 'oc_'));
$tokenX = $lockX->acquire($STORE, $UNICID);
mtucRlc_assert(is_string($tokenX), '28a owner acquires the scope lock');
$wrongRelease = $lockY->release($STORE, $UNICID, 'not-the-owner-token');
mtucRlc_assert(is_array($wrongRelease) && $wrongRelease['ok'] === false, '28b wrong ownership token cannot release');
$stolenRelease = $lockY->release($STORE, $UNICID, $tokenX);
mtucRlc_assert(
    is_array($stolenRelease)
        && $stolenRelease['ok'] === false
        && $stolenRelease['outcome'] === MtUniCreditShopConfigurationRefreshLock::RELEASE_OUTCOME_NOT_OWNER_TOKEN,
    '28c non-owner connection cannot release the owner lock'
);
mtucRlc_assert($lockY->acquire($STORE, $UNICID) === null, '28d owner lock survived both release attempts');
mtucRlc_assert($lockX->release($STORE, $UNICID, $tokenX)['ok'] === true, '28e the real owner can still release');

// ---------------------------------------------------------------------------
// 31) one remote GET per scope burst
// ---------------------------------------------------------------------------
$memoryDb = new Phase2MemoryDb();
mtucRlc_seed($memoryDb, $STORE, $T0, mtucRlc_snapshot());
$burstTransport = new Phase4FakeCpHttpTransport();
$burstStack = mtucRlc_stack($memoryDb, $burstTransport, $STORE, $T0 + $TTL + 10, $diagnostics, $noSleep);
mtucRlc_enqueueRefreshSuccess($burstTransport, mtucRlc_snapshot(array('uni_maxstojnost' => 9200)));
$burstStack['service']->getForPresentation();
mtucRlc_assert(mtucRlc_shopGetCount($burstTransport) === 1, '31a one owner remote GET per stale scope burst');
$loginCount = 0;
foreach ($burstTransport->requests as $request) {
    if (
        strtoupper((string) $request['method']) === 'POST'
        && strpos((string) $request['url'], '/auth/login') !== false
    ) {
        $loginCount++;
    }
}
mtucRlc_assert($loginCount === 1, '31b one coordinated login for the same burst');

// ---------------------------------------------------------------------------
// 30) strict resolver before addOrder / CP / SmartUCF + same snapshot carried
// ---------------------------------------------------------------------------
$staleSnapshot = mtucRlc_snapshot(array('coeff_list' => array(
    array(
        'onlineProductCode' => 'KOPSTD',
        'installmentCount' => 12,
        'coeff' => 2.10,
        'interestPercent' => 110.0,
    ),
)));

$memoryDb = new Phase2MemoryDb();
$transport = new Phase4FakeCpHttpTransport();
$p7 = Phase7TestHarness::stack($transport, $memoryDb, $STORE);
mtucRlc_seed($memoryDb, $STORE, $T0 - $TTL - 600, $staleSnapshot);
$failingStack = mtucRlc_stack($memoryDb, $transport, $STORE, $T0, $diagnostics, $noSleep);
$failingService = new MtUniCreditStorefrontFinancingSubmissionService(
    $p7['attempts'],
    $p7['locks'],
    $p7['lifecycle'],
    $failingStack['credentials'],
    $failingStack['service']
);
mtucRlc_enqueueRefreshTransient($transport);
$addOrderCalls = 0;
$input = Phase9TestHarness::productStorefrontInput($p7, 7301);
$originalAddOrder = $input['add_order'];
$input['add_order'] = function ($orderData) use (&$addOrderCalls, $originalAddOrder) {
    $addOrderCalls++;

    return call_user_func($originalAddOrder, $orderData);
};
$strictFailure = $failingService->submit($input);
mtucRlc_assert(
    is_array($strictFailure) && $strictFailure['success'] === false && $strictFailure['error'] === 'shop_cache_stale',
    '30a strict submission resolution failure fails the submit'
);
mtucRlc_assert($addOrderCalls === 0, '30b strict failure: addOrder count = 0');
mtucRlc_assert(mtucRlc_orderCreateCount($transport) === 0, '30c strict failure: zero CP order create calls');
mtucRlc_assert(Phase9TestHarness::smartUcfCallCount($p7['smartUcfProbe']) === 0, '30d strict failure: zero SmartUCF calls');

$memoryDb = new Phase2MemoryDb();
$transport = new Phase4FakeCpHttpTransport();
$p7 = Phase7TestHarness::stack($transport, $memoryDb, $STORE);
mtucRlc_seed($memoryDb, $STORE, $T0 - $TTL - 600, $staleSnapshot);
$successStack = mtucRlc_stack($memoryDb, $transport, $STORE, $T0, $diagnostics, $noSleep);
$successService = new MtUniCreditStorefrontFinancingSubmissionService(
    $p7['attempts'],
    $p7['locks'],
    $p7['lifecycle'],
    $successStack['credentials'],
    $successStack['service']
);
mtucRlc_enqueueRefreshSuccess($transport, mtucRlc_snapshot());
Phase9TestHarness::enqueueCpOrderCreateSuccess($transport);
$successResult = $successService->submit(Phase9TestHarness::productStorefrontInput($p7, 7302));
mtucRlc_assert(is_array($successResult) && $successResult['success'] === true, '30e stale + coordinated refresh submits successfully');
mtucRlc_assert(mtucRlc_shopGetCount($transport) === 1, '30f exactly one refresh GET for the submission path');

$orderCreateIndex = -1;
$shopGetIndex = -1;
foreach ($transport->requests as $index => $request) {
    $method = strtoupper((string) $request['method']);
    $url = (string) $request['url'];
    if ($orderCreateIndex < 0 && $method === 'POST' && substr($url, -7) === '/orders') {
        $orderCreateIndex = (int) $index;
    }
    if ($shopGetIndex < 0 && $method === 'GET' && strpos($url, '/shop') !== false) {
        $shopGetIndex = (int) $index;
    }
}
mtucRlc_assert(
    $shopGetIndex >= 0 && $orderCreateIndex > $shopGetIndex,
    '30g strict resolution happens strictly before the CP order create'
);

$cpCreatePayload = null;
foreach ($transport->requests as $request) {
    if (strtoupper((string) $request['method']) === 'POST' && substr((string) $request['url'], -7) === '/orders') {
        $cpCreatePayload = $request['payload'];
    }
}
$refreshedShop = mtucRlc_snapshot();
$calc = new MtUniCreditCalculator();
$productContext = (new MtUniCreditProductLine(42, 'Example', 'EX', array(7), 1, 500.0, 500.0, 500.0, 0, array(), 0))
    ->toProductContext();
$parsedScheme = MtUniCreditStorefrontCalculatorPresenter::parseSchemeKey('standard|KOPSTD|12');
$scheme = null;
foreach ($calc->availableSchemes($refreshedShop, $productContext, $parsedScheme['type']) as $candidate) {
    if ($candidate->kopCode === $parsedScheme['kop_code'] && $candidate->months === $parsedScheme['months']) {
        $scheme = $candidate;
        break;
    }
}
$expectedCalculation = $scheme !== null ? $calc->calculateScheme($refreshedShop, 500.0, $scheme, 0.0) : null;
$staleScheme = null;
foreach ($calc->availableSchemes($staleSnapshot, $productContext, $parsedScheme['type']) as $candidate) {
    if ($candidate->kopCode === $parsedScheme['kop_code'] && $candidate->months === $parsedScheme['months']) {
        $staleScheme = $candidate;
        break;
    }
}
$staleCalculation = $staleScheme !== null ? $calc->calculateScheme($staleSnapshot, 500.0, $staleScheme, 0.0) : null;
mtucRlc_assert(
    is_array($cpCreatePayload)
        && $expectedCalculation !== null
        && abs((float) $cpCreatePayload['vnoska'] - round((float) $expectedCalculation->monthlyInstallment, 2)) < 0.01,
    '30h CP payload is calculated from the refreshed submission snapshot'
);
mtucRlc_assert(
    $staleCalculation !== null
        && $expectedCalculation !== null
        && abs(
            round((float) $staleCalculation->monthlyInstallment, 2)
                - round((float) $expectedCalculation->monthlyInstallment, 2)
        ) > 0.01,
    '30i stale/refreshed fixture difference is discriminating'
);

// ---------------------------------------------------------------------------
// 32) local credential gate runs before network / lock / purge (never LKG)
// ---------------------------------------------------------------------------
$memoryDb = new Phase2MemoryDb();
$transport = new Phase4FakeCpHttpTransport();
mtucRlc_seed($memoryDb, $STORE, $T0, mtucRlc_snapshot(array('uni_maxstojnost' => 9000)));
$stack = mtucRlc_stack($memoryDb, $transport, $STORE, $T0 + $TTL + 3600, $diagnostics, $noSleep);
$stack['tokens']->save('known-good-token-value', 'Bearer', $T0 + $TTL);
$tokenBeforeGate = mtucRlc_setting($stack, MtUniCreditCpTokenRepository::ACCESS_TOKEN);
$typeBeforeGate = $stack['tokens']->getTokenType();
$stack['settings']->set($STORE, MtUniCreditConstants::MODULE_SETTING_SECRET, 'enc:v1:corrupt-envelope');
mtucRlc_assert(
    $stack['credentials']->isSecretReadable($STORE) === false,
    '32a fixture secret is unreadable (local gate fails)'
);
mtucRlc_assert(
    $stack['service']->getForPresentation() === null,
    '32b unreadable local credentials: presentation returns null (never LKG)'
);
mtucRlc_assert($transport->requests === array(), '32c unreadable local credentials: no CP network attempt');
mtucRlc_assert(
    $stack['repository']->inspectScope($STORE, $UNICID)['row_present'] === true,
    '32d unreadable local credentials: known-good row preserved (no purge/no Class B)'
);
mtucRlc_assert(
    mtucRlc_setting($stack, MtUniCreditCpTokenRepository::ACCESS_TOKEN) === $tokenBeforeGate,
    '32e unreadable local credentials: CP token preserved exactly (no purge)'
);
$gatedSubmission = null;
try {
    $stack['service']->getForSubmission();
} catch (MtUniCreditShopConfigurationUnavailableException $exception) {
    $gatedSubmission = $exception;
}
mtucRlc_assert(
    $gatedSubmission instanceof MtUniCreditShopConfigurationUnavailableException
        && $gatedSubmission->reason() === MtUniCreditShopConfigurationUnavailableException::REASON_NOT_CONFIGURED,
    '32f unreadable local credentials: strict submission fails closed as not_configured'
);
mtucRlc_assert($transport->requests === array(), '32g strict local gate failure performs no CP network attempt');
mtucRlc_assert(
    $stack['tokens']->getTokenType() === $typeBeforeGate
        && mtucRlc_setting($stack, MtUniCreditCpTokenRepository::ACCESS_TOKEN) === $tokenBeforeGate,
    '32h strict local gate failure has no side effects (token untouched)'
);

$memoryDbMissingSecret = new Phase2MemoryDb();
$missingSecretTransport = new Phase4FakeCpHttpTransport();
mtucRlc_seed($memoryDbMissingSecret, $STORE, $T0, mtucRlc_snapshot(array('uni_maxstojnost' => 9000)));
$missingSecretStack = mtucRlc_stack(
    $memoryDbMissingSecret,
    $missingSecretTransport,
    $STORE,
    $T0 + $TTL + 600,
    $diagnostics,
    $noSleep
);
$missingSecretStack['settings']->delete($STORE, MtUniCreditConstants::MODULE_SETTING_SECRET);
mtucRlc_assert(
    $missingSecretStack['service']->getForPresentation() === null,
    '32i missing local secret: presentation returns null (never LKG)'
);
mtucRlc_assert($missingSecretTransport->requests === array(), '32j missing local secret: no CP network attempt');
$missingSecretSubmission = null;
try {
    $missingSecretStack['service']->getForSubmission();
} catch (MtUniCreditShopConfigurationUnavailableException $exception) {
    $missingSecretSubmission = $exception;
}
mtucRlc_assert(
    $missingSecretSubmission instanceof MtUniCreditShopConfigurationUnavailableException
        && $missingSecretSubmission->reason() === MtUniCreditShopConfigurationUnavailableException::REASON_NOT_CONFIGURED,
    '32k missing local secret: strict submission fails closed as not_configured'
);

$memoryDbNoUnicid = new Phase2MemoryDb();
$noUnicidTransport = new Phase4FakeCpHttpTransport();
mtucRlc_seed($memoryDbNoUnicid, $STORE, $T0, mtucRlc_snapshot());
$noUnicidStack = mtucRlc_stack($memoryDbNoUnicid, $noUnicidTransport, $STORE, $T0 + $TTL + 600, $diagnostics, $noSleep);
$noUnicidStack['settings']->delete($STORE, MtUniCreditConstants::MODULE_SETTING_UNICID);
mtucRlc_assert($noUnicidStack['service']->getForPresentation() === null, '32l missing UNICID: presentation returns null');
mtucRlc_assert($noUnicidTransport->requests === array(), '32m missing UNICID: no CP network attempt');

// ---------------------------------------------------------------------------
// 33) contender LKG rule: no inferred Class A, no uncoordinated CP GET
// ---------------------------------------------------------------------------
$memoryDb = new Phase2MemoryDb();
mtucRlc_seed($memoryDb, $STORE, $T0, mtucRlc_snapshot(array('uni_maxstojnost' => 9000)));
$ownerLock = new MtUniCreditShopConfigurationRefreshLock(new MtUniCreditDbAdapter($memoryDb, 'oc_'));
$ownerToken = $ownerLock->acquire($STORE, $UNICID);
mtucRlc_assert(is_string($ownerToken) && $ownerToken !== '', '33a owner holds the exact-scope lock');
$peer = $memoryDb->newSharedConnection();
$peerTransport = new Phase4FakeCpHttpTransport();
$peerStack = mtucRlc_stack($peer, $peerTransport, $STORE, $T0 + $TTL + 600, $diagnostics, $noSleep);
mtucRlc_assert(
    $peerStack['service']->getForPresentation() === null,
    '33b contender cannot infer Class A from the owner → no LKG'
);
mtucRlc_assert($peerTransport->requests === array(), '33c contender issues no uncoordinated CP GET');
mtucRlc_assert(
    $ownerLock->release($STORE, $UNICID, $ownerToken)['ok'] === true,
    '33d owner releases after the contender test'
);

// ---------------------------------------------------------------------------
// 34) contender re-read returns fresh once the owner publishes fresh
// ---------------------------------------------------------------------------
$memoryDb = new Phase2MemoryDb();
mtucRlc_seed($memoryDb, $STORE, $T0, mtucRlc_snapshot(array('uni_maxstojnost' => 9000)));
$ownerLock = new MtUniCreditShopConfigurationRefreshLock(new MtUniCreditDbAdapter($memoryDb, 'oc_'));
$ownerToken = $ownerLock->acquire($STORE, $UNICID);
mtucRlc_assert(is_string($ownerToken), '34a owner holds the exact-scope lock');
$ownerStack = mtucRlc_stack(
    $memoryDb,
    new Phase4FakeCpHttpTransport(),
    $STORE,
    $T0 + $TTL + 600,
    $diagnostics,
    null,
    $ownerLock
);
$publishedDuringWait = false;
$publishSleeper = function ($microseconds) use ($ownerStack, &$publishedDuringWait, $ownerToken) {
    unset($microseconds);
    if ($publishedDuringWait) {
        return;
    }
    $publishedDuringWait = true;
    // Owner publishes under its own connection-verified scope ownership + exact token.
    $ownerStack['persistence']->replaceValidatedSnapshot(
        $ownerStack['storeId'],
        Phase4TestHarness::TEST_UNICID,
        mtucRlc_snapshot(array('uni_maxstojnost' => 9800)),
        $ownerToken
    );
};
$peer = $memoryDb->newSharedConnection();
$peerTransport = new Phase4FakeCpHttpTransport();
$peerStack = mtucRlc_stack($peer, $peerTransport, $STORE, $T0 + $TTL + 600, $diagnostics, $publishSleeper);
$contenderFresh = $peerStack['service']->getForPresentation();
mtucRlc_assert(
    is_array($contenderFresh) && (int) $contenderFresh['uni_maxstojnost'] === 9800,
    '34b contender re-read returns the freshly published snapshot'
);
mtucRlc_assert($peerTransport->requests === array(), '34c contender published-fresh path performs no CP GET');
mtucRlc_assert($publishedDuringWait === true, '34d owner published during the contender bounded wait');
$ownerLock->release($STORE, $UNICID, $ownerToken);

// ---------------------------------------------------------------------------
// 35) token validate-then-commit: malformed login/refresh never mutate state
// ---------------------------------------------------------------------------
$memoryDb = new Phase2MemoryDb();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$stack = mtucRlc_stack($memoryDb, $transport, $STORE, $T0, $diagnostics, $noSleep);
$stack['client']->login();
$tokenBefore = mtucRlc_setting($stack, MtUniCreditCpTokenRepository::ACCESS_TOKEN);
$typeBefore = $stack['tokens']->getTokenType();
$expiryBefore = $stack['tokens']->getExpiresAt();
mtucRlc_assert(is_string($tokenBefore) && $tokenBefore !== '', '35a baseline token stored');

$transport->enqueueJson(200, array(
    'success' => true,
    'error' => null,
    'message' => 'ok',
    'data' => array(
        'access_token' => '',
        'token_type' => 'Bearer',
        'expires_in' => 3600,
        'shop' => array('id' => 1, 'name' => Phase4TestHarness::TEST_SHOP_URL, 'unicid' => $UNICID),
    ),
));
$malformedLoginThrew = false;
try {
    $stack['client']->login();
} catch (MtUniCreditCpInvalidPayloadException $exception) {
    $malformedLoginThrew = true;
}
mtucRlc_assert($malformedLoginThrew, '35b malformed login response is rejected (Class C)');
mtucRlc_assert(
    mtucRlc_setting($stack, MtUniCreditCpTokenRepository::ACCESS_TOKEN) === $tokenBefore
        && $stack['tokens']->getTokenType() === $typeBefore
        && $stack['tokens']->getExpiresAt() === $expiryBefore
        && $stack['tokens']->hasToken() === true,
    '35c malformed login preserves exact token/type/expiry'
);

$transport->enqueueJson(200, array(
    'success' => true,
    'error' => null,
    'message' => 'ok',
    'data' => array('access_token' => '', 'token_type' => 'Bearer', 'expires_in' => 3600),
));
$malformedRefreshThrew = false;
try {
    $stack['client']->refreshToken();
} catch (MtUniCreditCpInvalidPayloadException $exception) {
    $malformedRefreshThrew = true;
}
mtucRlc_assert($malformedRefreshThrew, '35d malformed refresh response is rejected (Class C)');
mtucRlc_assert(
    mtucRlc_setting($stack, MtUniCreditCpTokenRepository::ACCESS_TOKEN) === $tokenBefore
        && $stack['tokens']->getExpiresAt() === $expiryBefore,
    '35e malformed refresh preserves exact token/expiry'
);

$transport->enqueueJson(401, array(
    'success' => false,
    'error' => 'authentication_failed',
    'message' => 'revoked',
    'data' => new stdClass(),
));
$refresh401Threw = false;
try {
    $stack['client']->refreshToken();
} catch (MtUniCreditCpAuthenticationException $exception) {
    $refresh401Threw = true;
}
mtucRlc_assert($refresh401Threw, '35f trusted 401 refresh throws authentication failure');
mtucRlc_assert($stack['tokens']->hasToken() === false, '35g trusted 401 still invalidates the stored token');

// ---------------------------------------------------------------------------
// 36) unknown / local infrastructure failures never yield LKG
// ---------------------------------------------------------------------------
$classifier = new MtUniCreditShopConfigurationFailureClassifier();
$unknown = $classifier->describe(new RuntimeException('unexpected programming defect'));
mtucRlc_assert(
    $unknown['class'] === MtUniCreditShopConfigurationFailureClassifier::CONTRACT_INVALID
        && $unknown['reason'] === MtUniCreditShopConfigurationFailureClassifier::REASON_UNKNOWN,
    '36a unknown Throwable classified CONTRACT_INVALID/unknown'
);
$localInfra = $classifier->describe(new MtUniCreditPersistenceException('local persistence defect'));
mtucRlc_assert(
    $localInfra['class'] === MtUniCreditShopConfigurationFailureClassifier::CONTRACT_INVALID
        && $localInfra['reason'] === MtUniCreditShopConfigurationFailureClassifier::REASON_LOCAL_INFRASTRUCTURE,
    '36b local infrastructure exception classified CONTRACT_INVALID/local_infrastructure'
);
mtucRlc_assert(
    $classifier->describe(new MtUniCreditCpConfigurationException('local config defect'))['class']
        === MtUniCreditShopConfigurationFailureClassifier::CONTRACT_INVALID,
    '36c local configuration defect is never TRANSIENT'
);

$memoryDb = new Phase2MemoryDb();
mtucRlc_seed($memoryDb, $STORE, $T0, mtucRlc_snapshot(array('uni_maxstojnost' => 9000)));
$unknownStack = mtucRlc_stack(
    $memoryDb,
    new MtucRlcThrowingTransport(new RuntimeException('unexpected transport defect')),
    $STORE,
    $T0 + $TTL + 600,
    $diagnostics,
    $noSleep
);
mtucRlc_assert(
    $unknownStack['service']->getForPresentation() === null,
    '36d unknown Throwable: presentation fails closed (no LKG)'
);
mtucRlc_assert(
    $unknownStack['repository']->inspectScope($STORE, $UNICID)['state'] === MtUniCreditShopCacheRepository::STATE_STALE,
    '36e unknown Throwable: known-good state preserved'
);
$unknownSubmission = null;
try {
    $unknownStack['service']->getForSubmission();
} catch (MtUniCreditShopConfigurationUnavailableException $exception) {
    $unknownSubmission = $exception;
}
mtucRlc_assert(
    $unknownSubmission instanceof MtUniCreditShopConfigurationUnavailableException
        && $unknownSubmission->reason() === MtUniCreditShopConfigurationUnavailableException::REASON_CONTRACT_INVALID,
    '36f unknown Throwable: submission fails closed as contract_invalid'
);

$memoryDb = new Phase2MemoryDb();
mtucRlc_seed($memoryDb, $STORE, $T0, mtucRlc_snapshot(array('uni_maxstojnost' => 9000)));
$noLockStack = mtucRlc_stack($memoryDb, new Phase4FakeCpHttpTransport(), $STORE, $T0 + $TTL + 600, $diagnostics, $noSleep);
$noLockService = new MtUniCreditShopConfigurationService(
    $noLockStack['credentials'],
    $noLockStack['repository'],
    $noLockStack['client'],
    $noLockStack['tokens'],
    $STORE,
    null,
    null,
    new MtUniCreditShopConfigurationFailureClassifier(),
    null,
    $noSleep
);
mtucRlc_assert(
    $noLockService->getForPresentation() === null,
    '36g local infrastructure defect (no scope lock): fails closed (no LKG)'
);
$noLockSubmissionThrew = false;
try {
    $noLockService->getForSubmission();
} catch (Throwable $exception) {
    $noLockSubmissionThrew = true;
}
mtucRlc_assert($noLockSubmissionThrew, '36h local infrastructure defect: submission fails closed');

// ---------------------------------------------------------------------------
// 37) connection-bound ownership proof
// ---------------------------------------------------------------------------
$memoryDb = new Phase2MemoryDb();
$ownerDb = new MtUniCreditDbAdapter($memoryDb, 'oc_');
$ownerLock = new MtUniCreditShopConfigurationRefreshLock($ownerDb);
mtucRlc_assert(
    $ownerLock->ownershipState($STORE, $UNICID) === MtUniCreditShopConfigurationRefreshLock::OWNERSHIP_NONE,
    '37a no ownership proof before acquire'
);
$ownerToken = $ownerLock->acquire($STORE, $UNICID);
mtucRlc_assert(is_string($ownerToken), '37b owner acquires the exact scope');
mtucRlc_assert(
    $ownerLock->ownershipState($STORE, $UNICID) === MtUniCreditShopConfigurationRefreshLock::OWNERSHIP_VALID,
    '37c ownership proof is valid for the current owner connection'
);
$otherLock = new MtUniCreditShopConfigurationRefreshLock(
    new MtUniCreditDbAdapter($memoryDb->newSharedConnection(), 'oc_')
);
mtucRlc_assert($otherLock->acquire($STORE, $UNICID) === null, '37d another connection cannot acquire the scope');
mtucRlc_assert(
    $otherLock->ownershipState($STORE, $UNICID) === MtUniCreditShopConfigurationRefreshLock::OWNERSHIP_NONE,
    '37e non-owner instance holds no valid proof'
);
mtucRlc_assert(
    $ownerLock->release($STORE, $UNICID, $ownerToken)['ok'] === true,
    '37e2 owner releases the scope before the next proof scenario'
);

$proofMem = new Phase2MemoryDb();
$proofLock = new MtUniCreditShopConfigurationRefreshLock(new MtUniCreditDbAdapter($proofMem, 'oc_'));
$proofToken = $proofLock->acquire($STORE, $UNICID);
mtucRlc_assert(
    is_string($proofToken)
        && $proofLock->ownershipState($STORE, $UNICID) === MtUniCreditShopConfigurationRefreshLock::OWNERSHIP_VALID,
    '37f proof valid while the owner connection is alive'
);
$proofMem->simulateConnectionClose();
mtucRlc_assert(
    $proofLock->ownershipState($STORE, $UNICID) === MtUniCreditShopConfigurationRefreshLock::OWNERSHIP_STALE,
    '37g disconnect invalidates the ownership proof'
);
$proofMem->simulateReconnect();
mtucRlc_assert(
    $proofLock->ownershipState($STORE, $UNICID) === MtUniCreditShopConfigurationRefreshLock::OWNERSHIP_NONE,
    '37h reconnect leaves no reusable proof behind'
);

$staleMem = new Phase2MemoryDb();
$staleDb = new MtUniCreditDbAdapter($staleMem, 'oc_');
$staleLock = new MtUniCreditShopConfigurationRefreshLock($staleDb);
$staleRepo = new MtUniCreditShopCacheRepository($staleDb, mtucRlc_clock($T0));
$stalePersistence = new MtUniCreditShopCachePersistence(
    $staleRepo,
    new MtUniCreditShopConfigurationSnapshotValidator(),
    MtUniCreditBootstrap::smartucfCredentialsRepositoryFromDb($staleDb),
    $staleLock
);
$staleToken = $staleLock->acquire($STORE, $UNICID);
mtucRlc_assert(is_string($staleToken), '37i stale-proof fixture acquired the scope lock');
$staleMem->simulateReconnect();
$staleWriteThrew = false;
try {
    $stalePersistence->replaceValidatedSnapshot($STORE, $UNICID, mtucRlc_snapshot(array('uni_maxstojnost' => 9900)));
} catch (MtUniCreditPersistenceException $exception) {
    $staleWriteThrew = true;
}
mtucRlc_assert($staleWriteThrew, '37j stale ownership proof cannot persist an already-fetched snapshot');
mtucRlc_assert(
    $staleRepo->findEncodedShopData($STORE, $UNICID) === null,
    '37k stale proof performs no write and does not re-acquire'
);
mtucRlc_assert(
    $staleLock->ownershipState($STORE, $UNICID) === MtUniCreditShopConfigurationRefreshLock::OWNERSHIP_NONE,
    '37l stale proof is dropped, never reused'
);

// ---------------------------------------------------------------------------
// 38/39) push and refresh share ONE lock identity; push commit remains final
// ---------------------------------------------------------------------------
$sharedMem = new Phase2MemoryDb();
$sharedDb = new MtUniCreditDbAdapter($sharedMem, 'oc_');
$sharedRepo = new MtUniCreditShopCacheRepository($sharedDb, mtucRlc_clock($T0));
$refreshScopeLock = new MtUniCreditShopConfigurationRefreshLock($sharedDb);
$refreshPersistence = new MtUniCreditShopCachePersistence(
    $sharedRepo,
    new MtUniCreditShopConfigurationSnapshotValidator(),
    MtUniCreditBootstrap::smartucfCredentialsRepositoryFromDb($sharedDb),
    $refreshScopeLock
);
$pushPersistence = new MtUniCreditShopCachePersistence(
    $sharedRepo,
    new MtUniCreditShopConfigurationSnapshotValidator(),
    MtUniCreditBootstrap::smartucfCredentialsRepositoryFromDb($sharedDb),
    new MtUniCreditShopConfigurationRefreshLock(new MtUniCreditDbAdapter($sharedMem->newSharedConnection(), 'oc_'))
);

$refreshToken = $refreshScopeLock->acquire($STORE, $UNICID);
mtucRlc_assert(is_string($refreshToken), '38a refresh acquires the scope lock before the push');
$pushBlocked = null;
try {
    $pushPersistence->replaceValidatedSnapshot($STORE, $UNICID, mtucRlc_snapshot(array('uni_maxstojnost' => 9950)));
} catch (MtUniCreditPersistenceException $exception) {
    $pushBlocked = $exception;
}
mtucRlc_assert($pushBlocked !== null, '38b push cannot write while a refresh holds the same lock identity');
mtucRlc_assert(
    $sharedRepo->inspectScope($STORE, $UNICID)['row_present'] === false,
    '38c a blocked push leaves no partial write'
);

$refreshPersistence->replaceValidatedSnapshot(
    $STORE,
    $UNICID,
    mtucRlc_snapshot(array('uni_maxstojnost' => 9100)),
    $refreshToken
);
mtucRlc_assert(
    $refreshScopeLock->release($STORE, $UNICID, $refreshToken)['ok'] === true,
    '39a refresh persists and releases the shared scope lock'
);
$pushPersistence->replaceValidatedSnapshot($STORE, $UNICID, mtucRlc_snapshot(array('uni_maxstojnost' => 9950)));
$finalOrdered = $sharedRepo->inspectScope($STORE, $UNICID);
mtucRlc_assert(
    is_array($finalOrdered['shop_data']) && (int) $finalOrdered['shop_data']['uni_maxstojnost'] === 9950,
    '39b push snapshot remains final after the earlier refresh committed'
);

// ---------------------------------------------------------------------------
// 40) storefront request memo is exact-scope (store_id + UNICID)
// ---------------------------------------------------------------------------
$memoMem = new Phase2MemoryDb();
$memoDb = new MtUniCreditDbAdapter($memoMem, 'oc_');
$memoSettings = new MtUniCreditSettingStore($memoDb, MtUniCreditConstants::MODULE_SETTINGS_CODE);
Phase4TestHarness::prepareCredentials($memoSettings, $STORE);
// Seed at REAL current time: loadPresentationShop builds its own real-clock repository, so the
// memoized snapshots must be locally fresh (no network, no coordinated refresh).
$memoNow = time();
$memoScopeLock = new MtUniCreditShopConfigurationRefreshLock($memoDb);
$memoPersistence = new MtUniCreditShopCachePersistence(
    new MtUniCreditShopCacheRepository($memoDb, mtucRlc_clock($memoNow)),
    new MtUniCreditShopConfigurationSnapshotValidator(),
    MtUniCreditBootstrap::smartucfCredentialsRepositoryFromDb($memoDb),
    $memoScopeLock
);
$unicidB = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
$memoPersistence->replaceValidatedSnapshot($STORE, $UNICID, mtucRlc_snapshot(array('uni_maxstojnost' => 8000)));
$memoPersistence->replaceValidatedSnapshot(
    $STORE,
    $unicidB,
    mtucRlc_snapshot(array('unicid' => $unicidB, 'uni_maxstojnost' => 7000))
);
$memoController = new MtucRlcControllerStub(
    new MtucRlcConfigStub(array(
        'config_store_id' => $STORE,
        'config_ssl' => Phase4TestHarness::TEST_SHOP_URL,
        'config_url' => Phase4TestHarness::TEST_SHOP_URL,
        MtUniCreditConstants::MODULE_SETTING_STATUS => 1,
        MtUniCreditConstants::PAYMENT_SETTING_STATUS => 1,
    )),
    $memoMem
);
MtUniCreditStorefrontRuntime::resetPresentationShopCache();
$memoA = MtUniCreditStorefrontRuntime::loadPresentationShop($memoController);
mtucRlc_assert(
    is_array($memoA) && (int) $memoA['uni_maxstojnost'] === 8000,
    '40a request memo resolves the scope-A snapshot'
);
$memoSettings->set($STORE, MtUniCreditConstants::MODULE_SETTING_UNICID, $unicidB);
$memoB = MtUniCreditStorefrontRuntime::loadPresentationShop($memoController);
mtucRlc_assert(
    is_array($memoB) && (int) $memoB['uni_maxstojnost'] === 7000,
    '40b same store + different UNICID is never served the scope-A memo'
);
MtUniCreditStorefrontRuntime::resetPresentationShopCache();

// ---------------------------------------------------------------------------
// 41) BOM guard on the notifier source
// ---------------------------------------------------------------------------
$notifierPath = $lib . DIRECTORY_SEPARATOR . 'satrudnik_failure_notifier.php';
$notifierPrefix = file_get_contents($notifierPath, false, null, 0, 5);
mtucRlc_assert($notifierPrefix === '<?php', '41a notifier source starts with "<?php"');
mtucRlc_assert(
    strpos((string) file_get_contents($notifierPath, false, null, 0, 3), "\xEF\xBB\xBF") !== 0,
    '41b no UTF-8 BOM prefix remains'
);

// ---------------------------------------------------------------------------
// 42) reconnect between acquire() and the write: the stale proof must fail closed
//
// The persistence path that acquires the lock itself must re-verify live ownership immediately
// before writeReplacement(). A connection replaced after acquire() (new CONNECTION_ID(), the dead
// connection's advisory locks released) can neither write the already-fetched snapshot nor
// silently re-acquire the lock to force the write through.
// ---------------------------------------------------------------------------
$reconnectMem = new Phase2MemoryDb();
$reconnectRaw = new MtucRlcReconnectOnWriteProbeDb($reconnectMem);
$reconnectDb = new MtUniCreditDbAdapter($reconnectRaw, 'oc_');
$reconnectRepo = new MtUniCreditShopCacheRepository($reconnectDb, mtucRlc_clock($T0));
$reconnectCreds = MtUniCreditBootstrap::smartucfCredentialsRepositoryFromDb($reconnectDb);
$reconnectPersistence = new MtUniCreditShopCachePersistence(
    $reconnectRepo,
    new MtUniCreditShopConfigurationSnapshotValidator(),
    $reconnectCreds,
    new MtUniCreditShopConfigurationRefreshLock($reconnectDb)
);

$reconnectThrew = null;
try {
    $reconnectPersistence->replaceValidatedSnapshot(
        $STORE,
        $UNICID,
        mtucRlc_snapshot(array('uni_maxstojnost' => 9700))
    );
} catch (MtUniCreditPersistenceException $exception) {
    $reconnectThrew = $exception;
}
mtucRlc_assert($reconnectThrew !== null, '42a reconnect between acquire() and the write fails closed');
mtucRlc_assert($reconnectRaw->getLockCount === 1, '42b no silent re-acquire for the already-fetched snapshot');
mtucRlc_assert($reconnectRaw->ownershipProbeCount === 1, '42c exactly one live ownership probe before the write');
mtucRlc_assert(
    $reconnectThrew === null
        || strpos($reconnectThrew->getMessage(), 'lost exact-scope lock ownership') !== false,
    '42d failure is a lost-ownership failure, never a transient/LKG downgrade'
);
mtucRlc_assert(
    $reconnectRepo->findEncodedShopData($STORE, $UNICID) === null,
    '42e stale/reconnected proof writes no snapshot'
);
mtucRlc_assert(
    $reconnectCreds->getUser($STORE) === null && $reconnectCreds->getPassword($STORE) === null,
    '42f stale/reconnected proof writes no SmartUCF credential pair'
);
$reconnectPeerLock = new MtUniCreditShopConfigurationRefreshLock(
    new MtUniCreditDbAdapter($reconnectMem->newSharedConnection(), 'oc_')
);
$reconnectPeerToken = $reconnectPeerLock->acquire($STORE, $UNICID);
mtucRlc_assert(
    is_string($reconnectPeerToken),
    '42g the failed writer leaves no stale proof and no held lock behind'
);
if (is_string($reconnectPeerToken)) {
    $reconnectPeerLock->release($STORE, $UNICID, $reconnectPeerToken);
}

// ---------------------------------------------------------------------------
// 43) the pre-held owner path requires the EXACT acquisition token
// ---------------------------------------------------------------------------
$holderMem = new Phase2MemoryDb();
$holderDb = new MtUniCreditDbAdapter($holderMem, 'oc_');
$holderRepo = new MtUniCreditShopCacheRepository($holderDb, mtucRlc_clock($T0));
$holderCreds = MtUniCreditBootstrap::smartucfCredentialsRepositoryFromDb($holderDb);
$holderScopeLock = new MtUniCreditShopConfigurationRefreshLock($holderDb);
$holderPersistence = new MtUniCreditShopCachePersistence(
    $holderRepo,
    new MtUniCreditShopConfigurationSnapshotValidator(),
    $holderCreds,
    $holderScopeLock
);
$holderToken = $holderScopeLock->acquire($STORE, $UNICID);
mtucRlc_assert(is_string($holderToken), '43a refresh owner holds the exact-scope lock');

$missingTokenThrew = null;
try {
    $holderPersistence->replaceValidatedSnapshot(
        $STORE,
        $UNICID,
        mtucRlc_snapshot(array('uni_maxstojnost' => 9600))
    );
} catch (MtUniCreditPersistenceException $exception) {
    $missingTokenThrew = $exception;
}
mtucRlc_assert($missingTokenThrew !== null, '43b pre-held owner path rejects a missing acquisition token');
mtucRlc_assert(
    $holderRepo->findEncodedShopData($STORE, $UNICID) === null,
    '43c missing acquisition token writes nothing'
);

$wrongTokenThrew = null;
try {
    $holderPersistence->replaceValidatedSnapshot(
        $STORE,
        $UNICID,
        mtucRlc_snapshot(array('uni_maxstojnost' => 9650)),
        'not-the-owner-token'
    );
} catch (MtUniCreditPersistenceException $exception) {
    $wrongTokenThrew = $exception;
}
mtucRlc_assert($wrongTokenThrew !== null, '43d pre-held owner path rejects a foreign acquisition token');
mtucRlc_assert(
    $holderRepo->findEncodedShopData($STORE, $UNICID) === null,
    '43e foreign acquisition token writes nothing'
);
mtucRlc_assert(
    $holderScopeLock->verifyWriteOwnership($STORE, $UNICID, $holderToken) === true,
    '43f a rejected foreign token does not destroy the genuine ownership proof'
);

$holderPersistence->replaceValidatedSnapshot(
    $STORE,
    $UNICID,
    mtucRlc_snapshot(array('uni_maxstojnost' => 9660)),
    $holderToken
);
mtucRlc_assert(
    (int) $holderRepo->inspectScope($STORE, $UNICID)['shop_data']['uni_maxstojnost'] === 9660,
    '43g the genuine owner persists with the exact acquisition token'
);
mtucRlc_assert(
    $holderScopeLock->release($STORE, $UNICID, $holderToken)['ok'] === true,
    '43h the genuine owner still releases the exact-scope lock'
);

// ---------------------------------------------------------------------------
// 29) no secret / UNICID / token leakage in lifecycle diagnostics
// ---------------------------------------------------------------------------
mtucRlc_assert(count($diagnostics) > 0, '29a lifecycle diagnostics were emitted');
$leak = '';
foreach ($diagnostics as $line) {
    foreach (array(
        $UNICID,
        Phase4TestHarness::TEST_SECRET,
        str_repeat('a', 64),
        'Bearer',
        'demo-secret-password',
        'shop_data',
    ) as $needle) {
        if ($needle !== '' && strpos($line, $needle) !== false) {
            $leak = $needle;
        }
    }
}
mtucRlc_assert($leak === '', '29b diagnostics never contain UNICID / secret / token / payload');
mtucRlc_assert(
    strpos(implode("\n", $diagnostics), 'scope=') !== false,
    '29c diagnostics carry a non-reversible scope fingerprint'
);

echo PHP_EOL . 'REM-OC3-CACHE-001 lifecycle: ' . $passes . ' PASS, ' . count($failures) . ' FAIL' . PHP_EOL;
if ($failures !== array()) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

exit(0);
