<?php

/**
 * REM-OC3-CACHE-001 — opt-in real MySQL/MariaDB single-flight concurrency probe.
 *
 * Run: php tests/phase_rem_oc3_cache_mysql_concurrency_probe.php
 *
 * Requires an ISOLATED disposable database configured through environment variables:
 *
 *   MTUC_PROBE_DB_HOST, MTUC_PROBE_DB_PORT (default 3306), MTUC_PROBE_DB_USER,
 *   MTUC_PROBE_DB_PASSWORD, MTUC_PROBE_DB_NAME (optional; a disposable DB is created),
 *   MTUC_PROBE_DB_PREFIX (default oc_)
 *
 * Never touches a production or live shop database. When no isolated database is configured the
 * probe reports the environment blocker explicitly and exits 0 (skipped) — it is intentionally NOT
 * part of the ordinary offline suite.
 *
 * Proves against REAL MySQL/MariaDB semantics: one owner per exact scope; stale presentation;
 * strict submission; missing/too-old/corrupt scopes; contenders issue no second GET while blocked;
 * owner disconnect releases the named lock; reconnect/dropped connection invalidates a previously
 * issued ownership proof (which therefore cannot persist); a non-owner cannot release the owner
 * lock; scopes are independent; push and refresh share one lock identity; and the push snapshot
 * remains final after an earlier refresh commits.
 *
 * PHP 7.3 compatible. No live CP / SmartUCF network.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/support/shop_cache_concurrency_worker.php';

$failures = array();
$passes = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucProbe_assert($condition, $message)
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
 * @return bool
 */
function mtucProbe_phpBinary()
{
    return (defined('PHP_BINARY') && PHP_BINARY !== '') ? (string) PHP_BINARY : 'php';
}

/**
 * Spawn one independent worker process.
 *
 * @param array<string, mixed> $config
 * @param array<string, string|int> $args
 * @return array<string, mixed>
 */
function mtucProbe_spawn($config, array $args)
{
    $php = mtucProbe_phpBinary();
    $workerScript = __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'shop_cache_concurrency_worker.php';
    $commandParts = array(escapeshellarg($php), escapeshellarg($workerScript));
    foreach ($args as $key => $value) {
        $commandParts[] = escapeshellarg('--' . $key . '=' . $value);
    }

    $env = array(
        'MTUC_PROBE_DB_HOST' => (string) $config['host'],
        'MTUC_PROBE_DB_PORT' => (string) $config['port'],
        'MTUC_PROBE_DB_USER' => (string) $config['user'],
        'MTUC_PROBE_DB_PASSWORD' => (string) $config['password'],
        'MTUC_PROBE_DB_NAME' => (string) $config['database'],
        'MTUC_PROBE_DB_PREFIX' => (string) $config['prefix'],
    );

    $descriptors = array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );
    $pipes = array();
    $process = proc_open(implode(' ', $commandParts), $descriptors, $pipes, dirname(__DIR__), $env);
    if (!is_resource($process)) {
        return array('process' => null, 'pipes' => array(), 'output' => 'spawn_failed');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    return array('process' => $process, 'pipes' => $pipes, 'output' => '');
}

/**
 * @param array<string, mixed> $handle
 * @return int|null exit code, null while still running
 */
function mtucProbe_poll($handle)
{
    if (!isset($handle['process']) || !is_resource($handle['process'])) {
        return -1;
    }
    $status = proc_get_status($handle['process']);
    if (!empty($status['running'])) {
        return null;
    }

    return (int) $status['exitcode'];
}

/**
 * @param array<string, mixed> $handle
 * @return void
 */
function mtucProbe_close($handle)
{
    if (!isset($handle['process']) || !is_resource($handle['process'])) {
        return;
    }
    foreach ($handle['pipes'] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($handle['process']);
}

/**
 * @param array<int, array<string, mixed>> $handles
 * @param int $timeoutSeconds
 * @return int count of workers that exited before the timeout
 */
function mtucProbe_waitAll(array $handles, $timeoutSeconds = 60)
{
    $deadline = microtime(true) + (int) $timeoutSeconds;
    $finished = 0;
    while (microtime(true) < $deadline) {
        $finished = 0;
        foreach ($handles as $handle) {
            if (mtucProbe_poll($handle) !== null) {
                $finished++;
            }
        }
        if ($finished >= count($handles)) {
            break;
        }
        usleep(50000);
    }

    return $finished;
}

$config = mtuc_probe_config_from_env();
$configured = is_string($config['host']) && $config['host'] !== ''
    && is_string($config['user']) && $config['user'] !== '';

if (!$configured) {
    echo 'REM-OC3-CACHE-001 real MySQL/MariaDB concurrency probe: REAL MYSQL PROBE BLOCKED BY ENVIRONMENT' . PHP_EOL;
    echo 'ENVIRONMENT BLOCKER: no isolated probe database configured.' . PHP_EOL;
    echo 'Set MTUC_PROBE_DB_HOST / MTUC_PROBE_DB_USER (+ MTUC_PROBE_DB_PASSWORD) and optionally' . PHP_EOL;
    echo 'MTUC_PROBE_DB_NAME to run the real-DB single-flight probe. No production/live DB is used.' . PHP_EOL;
    echo 'NOTE: no database was contacted (offline test isolation); driver availability: '
        . (function_exists('mysqli_connect') ? 'mysqli present' : 'mysqli missing') . '.' . PHP_EOL;

    exit(0);
}

$disposableDatabase = null;
if (!is_string($config['database']) || $config['database'] === '') {
    $disposableDatabase = 'mtuc_probe_' . bin2hex(random_bytes(6));
    $config['database'] = $disposableDatabase;
}

$adminConfig = $config;
$adminConfig['database'] = '';
$admin = mtuc_probe_connect($adminConfig);

if ($disposableDatabase !== null) {
    $admin->execute('CREATE DATABASE `' . $disposableDatabase . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}

$db = mtuc_probe_connect($config);
mtuc_probe_install_schema($db);

$runKey = 'rem_' . bin2hex(random_bytes(4));
$storeId = Phase4TestHarness::TEST_STORE_ID;
$unicid = Phase4TestHarness::TEST_UNICID;
$workers = 4;

echo 'REM-OC3-CACHE-001 real MySQL/MariaDB concurrency probe (isolated DB: ' . $config['database'] . ')' . PHP_EOL;

// ---------------------------------------------------------------------------
// Bursts: stale presentation / strict submission / missing / too old / corrupt
// ---------------------------------------------------------------------------
$bursts = array(
    'stale_presentation' => array('cache' => 'stale', 'surface' => 'presentation'),
    'stale_submission' => array('cache' => 'stale', 'surface' => 'submission'),
    'missing' => array('cache' => 'missing', 'surface' => 'presentation'),
    'too_old' => array('cache' => 'too_old', 'surface' => 'presentation'),
    'corrupt' => array('cache' => 'corrupt', 'surface' => 'presentation'),
);

foreach ($bursts as $label => $spec) {
    $burstKey = $runKey . ':' . $label;
    $db->execute("DELETE FROM `" . $config['prefix'] . "probe_counter` WHERE `probe_key` LIKE '" . $burstKey . "%'");
    $db->execute("DELETE FROM `" . $config['prefix'] . "probe_barrier` WHERE `probe_key` = '" . $burstKey . ":barrier'");
    $db->execute("DELETE FROM `" . $config['prefix'] . "probe_result` WHERE `run_key` = '" . $burstKey . "'");
    mtuc_probe_seed_cache($db, $storeId, $unicid, $spec['cache']);

    $handles = array();
    for ($i = 0; $i < $workers; $i++) {
        $handles[] = mtucProbe_spawn($config, array(
            'scenario' => 'cache_refresh',
            'worker' => 'w' . $i,
            'run-key' => $burstKey,
            'store-id' => $storeId,
            'unicid' => $unicid,
            'surface' => $spec['surface'],
            'barrier-key' => $burstKey . ':barrier',
            'barrier-expected' => $workers,
        ));
    }
    $finished = mtucProbe_waitAll($handles, 60);
    foreach ($handles as $handle) {
        mtucProbe_close($handle);
    }

    $getCount = mtuc_probe_count($db, $burstKey . ':shop_get');
    $results = mtuc_probe_results($db, $burstKey);
    $errors = 0;
    foreach ($results as $row) {
        if ((string) $row['outcome'] === 'error') {
            $errors++;
        }
    }

    mtucProbe_assert($finished === $workers, $label . ': all ' . $workers . ' workers finished');
    mtucProbe_assert($errors === 0, $label . ': no worker errors');
    mtucProbe_assert($getCount === 1, $label . ': exactly one remote GET /shop for the burst (got ' . $getCount . ')');
}

// ---------------------------------------------------------------------------
// Cross-scope independence
// ---------------------------------------------------------------------------
$scopeKey = $runKey . ':scope';
$scopeA = $unicid;
$scopeB = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
mtuc_probe_seed_cache($db, $storeId, $scopeA, 'stale');
mtuc_probe_seed_cache($db, $storeId, $scopeB, 'stale');
$db->execute("DELETE FROM `" . $config['prefix'] . "probe_barrier` WHERE `probe_key` = '" . $scopeKey . ":barrier'");

$handles = array();
$scopeNames = array('a' => $scopeA, 'b' => $scopeB);
foreach ($scopeNames as $suffix => $scopeUnicid) {
    $handles[] = mtucProbe_spawn($config, array(
        'scenario' => 'cache_refresh',
        'worker' => 'scope' . $suffix,
        'run-key' => $scopeKey . ':' . $suffix,
        'store-id' => $storeId,
        'unicid' => $scopeUnicid,
        'surface' => 'presentation',
        'barrier-key' => $scopeKey . ':barrier',
        'barrier-expected' => 2,
        'hold-ms' => 200,
    ));
}
mtucProbe_waitAll($handles, 60);
foreach ($handles as $handle) {
    mtucProbe_close($handle);
}
mtucProbe_assert(mtuc_probe_count($db, $scopeKey . ':a:shop_get') === 1, 'cross-scope: scope A performed one GET /shop');
mtucProbe_assert(mtuc_probe_count($db, $scopeKey . ':b:shop_get') === 1, 'cross-scope: scope B performed one GET /shop');

// ---------------------------------------------------------------------------
// Owner crash recovery (abrupt worker termination releases the named lock)
// ---------------------------------------------------------------------------
$crashKey = $runKey . ':crash';
$db->execute("DELETE FROM `" . $config['prefix'] . "probe_barrier` WHERE `probe_key` = '" . $crashKey . ":barrier'");
$db->execute("DELETE FROM `" . $config['prefix'] . "probe_result` WHERE `run_key` = '" . $crashKey . "'");

$holder = mtucProbe_spawn($config, array(
    'scenario' => 'owner_crash_hold',
    'worker' => 'holder',
    'run-key' => $crashKey,
    'store-id' => $storeId,
    'unicid' => $unicid,
    'barrier-key' => $crashKey . ':barrier',
    'barrier-expected' => 1,
));

$holderAcquired = false;
$deadline = microtime(true) + 30;
while (microtime(true) < $deadline) {
    foreach (mtuc_probe_results($db, $crashKey) as $row) {
        if ((string) $row['worker'] === 'holder' && (string) $row['outcome'] === 'acquired') {
            $holderAcquired = true;
        }
    }
    if ($holderAcquired) {
        break;
    }
    usleep(50000);
}
mtucProbe_assert($holderAcquired, 'owner crash: holder acquired the scope lock');

if (isset($holder['process']) && is_resource($holder['process'])) {
    proc_terminate($holder['process']);
}
mtucProbe_close($holder);

$rookie = mtucProbe_spawn($config, array(
    'scenario' => 'lock_probe',
    'worker' => 'rookie',
    'run-key' => $crashKey,
    'store-id' => $storeId,
    'unicid' => $unicid,
    'barrier-key' => $crashKey . ':barrier2',
    'barrier-expected' => 1,
));
mtucProbe_waitAll(array($rookie), 30);
mtucProbe_close($rookie);

$recovered = false;
foreach (mtuc_probe_results($db, $crashKey) as $row) {
    if ((string) $row['worker'] === 'rookie' && (string) $row['outcome'] === 'acquired') {
        $recovered = true;
    }
}
mtucProbe_assert($recovered, 'owner crash: lock is recoverable after the owner connection died');

// ---------------------------------------------------------------------------
// Non-owner release (two real connections in one process: DB-level proof)
// ---------------------------------------------------------------------------
$connA = mtuc_probe_connect($config);
$connB = mtuc_probe_connect($config);
$lockA = new MtUniCreditShopConfigurationRefreshLock(new MtUniCreditDbAdapter($connA, (string) $config['prefix']));
$lockB = new MtUniCreditShopConfigurationRefreshLock(new MtUniCreditDbAdapter($connB, (string) $config['prefix']));
$tokenA = $lockA->acquire($storeId, $unicid);
mtucProbe_assert(is_string($tokenA) && $tokenA !== '', 'non-owner release: owner acquired the scope lock');
$stolen = $lockB->release($storeId, $unicid, $tokenA);
mtucProbe_assert(
    is_array($stolen)
        && $stolen['ok'] === false
        && $stolen['outcome'] === MtUniCreditShopConfigurationRefreshLock::RELEASE_OUTCOME_NOT_OWNED,
    'non-owner release: another connection cannot release the owner lock'
);
mtucProbe_assert($lockB->acquire($storeId, $unicid) === null, 'non-owner release: owner still holds the lock');
mtucProbe_assert($lockA->release($storeId, $unicid, $tokenA)['ok'] === true, 'non-owner release: real owner can release');
$connA->close();
$connB->close();

// ---------------------------------------------------------------------------
// Push vs refresh: ONE lock identity; the push snapshot remains final
// ---------------------------------------------------------------------------
$pushKey = $runKey . ':pushvrefresh';
$db->execute("DELETE FROM `" . $config['prefix'] . "probe_result` WHERE `run_key` = '" . $pushKey . "'");
$db->execute("DELETE FROM `" . $config['prefix'] . "probe_barrier` WHERE `probe_key` = '" . $pushKey . ":barrier'");
mtuc_probe_seed_cache($db, $storeId, $unicid, 'stale');

$pushHandle = mtucProbe_spawn($config, array(
    'scenario' => 'push_vs_refresh',
    'worker' => 'pusher',
    'run-key' => $pushKey,
    'store-id' => $storeId,
    'unicid' => $unicid,
    'barrier-key' => $pushKey . ':barrier',
    'barrier-expected' => 1,
));
mtucProbe_waitAll(array($pushHandle), 60);
mtucProbe_close($pushHandle);

$orderingOk = false;
foreach (mtuc_probe_results($db, $pushKey) as $row) {
    if (
        (string) $row['worker'] === 'pusher'
        && (string) $row['outcome'] === 'push_final'
    ) {
        $orderingOk = true;
    }
}
mtucProbe_assert(
    $orderingOk,
    'push vs refresh: one shared lock identity serializes both; the push snapshot remains final'
);

// ---------------------------------------------------------------------------
// Stale ownership proof (dead connection) cannot persist
// ---------------------------------------------------------------------------
$staleKey = $runKey . ':staleproof';
$db->execute("DELETE FROM `" . $config['prefix'] . "probe_result` WHERE `run_key` = '" . $staleKey . "'");
$db->execute("DELETE FROM `" . $config['prefix'] . "probe_barrier` WHERE `probe_key` = '" . $staleKey . ":barrier'");
mtuc_probe_seed_cache($db, $storeId, $unicid, 'stale');

$staleHandle = mtucProbe_spawn($config, array(
    'scenario' => 'stale_proof',
    'worker' => 'stale',
    'run-key' => $staleKey,
    'store-id' => $storeId,
    'unicid' => $unicid,
    'barrier-key' => $staleKey . ':barrier',
    'barrier-expected' => 1,
));
mtucProbe_waitAll(array($staleHandle), 60);
mtucProbe_close($staleHandle);

$staleProofRejected = false;
foreach (mtuc_probe_results($db, $staleKey) as $row) {
    if (
        (string) $row['worker'] === 'stale'
        && (string) $row['outcome'] === 'stale_proof_rejected'
    ) {
        $staleProofRejected = true;
    }
}
mtucProbe_assert(
    $staleProofRejected,
    'stale PHP ownership proof (dropped/replaced connection) cannot persist and leaves no write'
);

if ($disposableDatabase !== null) {
    $admin->execute('DROP DATABASE `' . $disposableDatabase . '`');
}
$admin->close();
$db->close();

echo PHP_EOL . 'REM-OC3-CACHE-001 MySQL concurrency probe: ' . $passes . ' PASS, ' . count($failures) . ' FAIL' . PHP_EOL;
if ($failures !== array()) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

exit(0);
