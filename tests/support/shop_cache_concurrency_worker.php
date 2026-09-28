<?php

/**
 * REM-OC3-CACHE-001 — real MySQL/MariaDB concurrency probe worker (opt-in).
 *
 * This file is BOTH:
 *  - the shared probe library (isolated-DB adapter, atomic counters, barrier, result rows), and
 *  - the CLI worker executed by tests/phase_rem_oc3_cache_mysql_concurrency_probe.php.
 *
 * Independent PHP processes + independent DB connections + explicit synchronization barrier +
 * disposable isolated DB/table state. Never touches a production or live shop database.
 *
 * PHP 7.3 compatible.
 */

$mtucProbeRoot = dirname(__DIR__, 2);
if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $mtucProbeRoot . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}

require_once $mtucProbeRoot . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__) . '/support/phase2_memory_db.php';
require_once dirname(__DIR__) . '/support/phase4_harness.php';
require_once dirname(__DIR__) . '/fixtures/cp_shop_snapshot.php';

if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', MtUniCreditEncryptionTestSecret::INSTALLATION_TEST_SECRET);
}

/**
 * Minimal OC3-like DB object over mysqli (query/escape/countAffected/getLastId + getPrefix).
 */
final class MtucProbeMysql
{
    /** @var mysqli */
    private $link;

    /** @var string */
    private $prefix;

    /** @var int */
    private $affected = 0;

    /**
     * @param mysqli $link
     * @param string $prefix
     */
    public function __construct($link, $prefix)
    {
        $this->link = $link;
        $this->prefix = (string) $prefix;
    }

    /**
     * @return string
     */
    public function getPrefix()
    {
        return $this->prefix;
    }

    /**
     * @param string $sql
     * @return object
     */
    public function query($sql)
    {
        $result = mysqli_query($this->link, $sql);
        if ($result === false) {
            throw new RuntimeException('probe SQL failed: ' . mysqli_error($this->link));
        }

        $this->affected = (int) mysqli_affected_rows($this->link);

        if ($result === true) {
            return (object) array('num_rows' => 0, 'row' => array(), 'rows' => array());
        }

        $rows = array();
        while (($row = mysqli_fetch_assoc($result)) !== null) {
            $rows[] = $row;
        }
        mysqli_free_result($result);

        return (object) array(
            'num_rows' => count($rows),
            'row' => $rows === array() ? array() : $rows[0],
            'rows' => $rows,
        );
    }

    /**
     * @param string $sql
     * @return int affected rows
     */
    public function execute($sql)
    {
        $result = mysqli_query($this->link, $sql);
        if ($result === false) {
            throw new RuntimeException('probe SQL failed: ' . mysqli_error($this->link));
        }

        $affected = (int) mysqli_affected_rows($this->link);
        if ($result !== true) {
            mysqli_free_result($result);
        }
        $this->affected = $affected;

        return $affected;
    }

    /**
     * @param string $value
     * @return string
     */
    public function escape($value)
    {
        return mysqli_real_escape_string($this->link, (string) $value);
    }

    /**
     * @return int
     */
    public function countAffected()
    {
        return $this->affected;
    }

    /**
     * @return int
     */
    public function getLastId()
    {
        return (int) mysqli_insert_id($this->link);
    }

    /**
     * @return void
     */
    public function close()
    {
        mysqli_close($this->link);
    }
}

/**
 * CP transport double whose remote-GET accounting is atomic in the isolated DB.
 */
final class MtucProbeCounterTransport implements MtUniCreditCpHttpTransport
{
    /** @var MtucProbeMysql */
    private $db;

    /** @var string */
    private $runKey;

    /** @var array<string, mixed> */
    private $snapshot;

    /** @var int microseconds */
    private $shopDelay;

    /**
     * @param MtucProbeMysql $db
     * @param string $runKey
     * @param array<string, mixed> $snapshot
     * @param int $shopDelay
     */
    public function __construct($db, $runKey, array $snapshot, $shopDelay = 150000)
    {
        $this->db = $db;
        $this->runKey = (string) $runKey;
        $this->snapshot = $snapshot;
        $this->shopDelay = (int) $shopDelay;
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
        unset($headers, $payload);

        $path = (string) parse_url((string) $url, PHP_URL_PATH);
        mtuc_probe_bump($this->db, $this->runKey . ':requests');

        if (strtoupper((string) $method) === 'POST' && strpos($path, '/auth/login') !== false) {
            mtuc_probe_bump($this->db, $this->runKey . ':login');

            return new MtUniCreditCpHttpResponse(200, json_encode(array(
                'success' => true,
                'error' => null,
                'message' => 'ok',
                'data' => array(
                    'access_token' => str_repeat('a', 64),
                    'token_type' => 'Bearer',
                    'expires_in' => 86400,
                    'shop' => array(
                        'id' => 1,
                        'name' => Phase4TestHarness::TEST_SHOP_URL,
                        'unicid' => Phase4TestHarness::TEST_UNICID,
                    ),
                ),
            )));
        }

        if (strtoupper((string) $method) === 'GET' && strpos($path, '/shop') !== false) {
            // Atomic remote-GET counter: the probe's single-flight proof.
            mtuc_probe_bump($this->db, $this->runKey . ':shop_get');
            if ($this->shopDelay > 0) {
                usleep($this->shopDelay);
            }

            return new MtUniCreditCpHttpResponse(200, json_encode(array(
                'success' => true,
                'error' => null,
                'message' => 'ok',
                'data' => $this->snapshot,
            )));
        }

        throw new RuntimeException('unexpected probe CP call: ' . $method . ' ' . $path);
    }
}

/**
 * @return array<string, mixed>
 */
function mtuc_probe_config_from_env()
{
    return array(
        'host' => getenv('MTUC_PROBE_DB_HOST'),
        'port' => getenv('MTUC_PROBE_DB_PORT') !== false ? (int) getenv('MTUC_PROBE_DB_PORT') : 3306,
        'user' => getenv('MTUC_PROBE_DB_USER'),
        'password' => getenv('MTUC_PROBE_DB_PASSWORD') !== false ? (string) getenv('MTUC_PROBE_DB_PASSWORD') : '',
        'database' => getenv('MTUC_PROBE_DB_NAME'),
        'prefix' => getenv('MTUC_PROBE_DB_PREFIX') !== false ? (string) getenv('MTUC_PROBE_DB_PREFIX') : 'oc_',
    );
}

/**
 * @param array<string, mixed> $config
 * @param string|null $database Override database name.
 * @return MtucProbeMysql
 */
function mtuc_probe_connect(array $config, $database = null)
{
    if (!function_exists('mysqli_connect')) {
        throw new RuntimeException('mysqli extension is not available.');
    }
    if (!is_string($config['host']) || $config['host'] === '' || !is_string($config['user']) || $config['user'] === '') {
        throw new RuntimeException('probe DB host/user are not configured.');
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    $dbName = $database !== null ? (string) $database : (string) $config['database'];
    $link = mysqli_connect(
        (string) $config['host'],
        (string) $config['user'],
        (string) $config['password'],
        $dbName,
        (int) $config['port']
    );
    if (!$link) {
        throw new RuntimeException('probe DB connect failed: ' . mysqli_connect_error());
    }

    return new MtucProbeMysql($link, (string) $config['prefix']);
}

/**
 * Create the isolated probe schema (settings + shop cache + probe bookkeeping tables).
 *
 * @param MtucProbeMysql $db
 * @return void
 */
function mtuc_probe_install_schema($db)
{
    $prefix = $db->getPrefix();

    $statements = MtUniCreditPersistenceSchema::createPhase3TableStatements($prefix);
    foreach ($statements as $statement) {
        $db->execute($statement);
    }

    $db->execute(
        "CREATE TABLE IF NOT EXISTS `{$prefix}setting` ("
            . "`setting_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,"
            . "`store_id` INT NOT NULL DEFAULT 0,"
            . "`code` VARCHAR(128) NOT NULL,"
            . "`key` VARCHAR(128) NOT NULL,"
            . "`value` TEXT,"
            . "`serialized` TINYINT(1) NOT NULL DEFAULT 0,"
            . "PRIMARY KEY (`setting_id`),"
            . "UNIQUE KEY `uniq_probe_setting` (`store_id`, `code`, `key`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $db->execute(
        "CREATE TABLE IF NOT EXISTS `{$prefix}probe_counter` ("
            . "`probe_key` VARCHAR(160) NOT NULL,"
            . "`hits` INT UNSIGNED NOT NULL DEFAULT 0,"
            . "PRIMARY KEY (`probe_key`)"
            . ") ENGINE=InnoDB"
    );
    $db->execute(
        "CREATE TABLE IF NOT EXISTS `{$prefix}probe_barrier` ("
            . "`probe_key` VARCHAR(160) NOT NULL,"
            . "`worker` VARCHAR(40) NOT NULL,"
            . "`created_at` DATETIME NOT NULL,"
            . "PRIMARY KEY (`probe_key`, `worker`)"
            . ") ENGINE=InnoDB"
    );
    $db->execute(
        "CREATE TABLE IF NOT EXISTS `{$prefix}probe_result` ("
            . "`result_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,"
            . "`run_key` VARCHAR(160) NOT NULL,"
            . "`worker` VARCHAR(40) NOT NULL,"
            . "`scenario` VARCHAR(40) NOT NULL,"
            . "`outcome` VARCHAR(40) NOT NULL,"
            . "`detail` VARCHAR(255) NOT NULL DEFAULT '',"
            . "`created_at` DATETIME NOT NULL,"
            . "PRIMARY KEY (`result_id`)"
            . ") ENGINE=InnoDB"
    );
}

/**
 * @param MtucProbeMysql $db
 * @param string $key
 * @return void
 */
function mtuc_probe_bump($db, $key)
{
    $table = $db->getPrefix() . 'probe_counter';
    $db->execute(
        "INSERT INTO `{$table}` (`probe_key`, `hits`) VALUES ('" . $db->escape($key) . "', 1)"
            . " ON DUPLICATE KEY UPDATE `hits` = `hits` + 1"
    );
}

/**
 * @param MtucProbeMysql $db
 * @param string $key
 * @return int
 */
function mtuc_probe_count($db, $key)
{
    $table = $db->getPrefix() . 'probe_counter';
    $result = $db->query(
        "SELECT `hits` FROM `{$table}` WHERE `probe_key` = '" . $db->escape($key) . "' LIMIT 1"
    );
    if (!is_object($result) || (int) $result->num_rows !== 1) {
        return 0;
    }

    return (int) $result->row['hits'];
}

/**
 * @param MtucProbeMysql $db
 * @param string $key
 * @param string $worker
 * @param int $expected
 * @param int $timeoutSeconds
 * @return bool all workers arrived before the timeout
 */
function mtuc_probe_barrier_wait($db, $key, $worker, $expected, $timeoutSeconds = 20)
{
    $table = $db->getPrefix() . 'probe_barrier';
    $db->execute(
        "INSERT INTO `{$table}` (`probe_key`, `worker`, `created_at`)"
            . " VALUES ('" . $db->escape($key) . "','" . $db->escape($worker) . "', UTC_TIMESTAMP())"
            . " ON DUPLICATE KEY UPDATE `created_at` = VALUES(`created_at`)"
    );

    $deadline = microtime(true) + max(1, (int) $timeoutSeconds);
    while (microtime(true) < $deadline) {
        $result = $db->query(
            "SELECT COUNT(*) AS `arrived` FROM `{$table}` WHERE `probe_key` = '" . $db->escape($key) . "'"
        );
        if (is_object($result) && (int) $result->row['arrived'] >= (int) $expected) {
            return true;
        }
        usleep(25000);
    }

    return false;
}

/**
 * @param MtucProbeMysql $db
 * @param string $runKey
 * @param string $worker
 * @param string $scenario
 * @param string $outcome
 * @param string $detail
 * @return void
 */
function mtuc_probe_record_result($db, $runKey, $worker, $scenario, $outcome, $detail = '')
{
    $table = $db->getPrefix() . 'probe_result';
    $db->execute(
        "INSERT INTO `{$table}` (`run_key`, `worker`, `scenario`, `outcome`, `detail`, `created_at`)"
            . " VALUES ("
            . "'" . $db->escape($runKey) . "',"
            . "'" . $db->escape($worker) . "',"
            . "'" . $db->escape($scenario) . "',"
            . "'" . $db->escape($outcome) . "',"
            . "'" . $db->escape(substr($detail, 0, 255)) . "',"
            . " UTC_TIMESTAMP())"
    );
}

/**
 * @param MtucProbeMysql $db
 * @param string $runKey
 * @return array<int, array<string, string>>
 */
function mtuc_probe_results($db, $runKey)
{
    $table = $db->getPrefix() . 'probe_result';
    $result = $db->query(
        "SELECT `worker`, `scenario`, `outcome`, `detail` FROM `{$table}`"
            . " WHERE `run_key` = '" . $db->escape($runKey) . "' ORDER BY `result_id` ASC"
    );

    return is_object($result) ? $result->rows : array();
}

/**
 * Seed the exact-scope shop cache row directly (stale / too old / corrupt / missing).
 *
 * @param MtucProbeMysql $db
 * @param int $storeId
 * @param string $unicid
 * @param string $state fresh|stale|too_old|corrupt|missing
 * @param int $ttl
 * @param int $lkg
 * @return void
 */
function mtuc_probe_seed_cache($db, $storeId, $unicid, $state, $ttl = 86400, $lkg = 21600)
{
    $table = $db->getPrefix() . MtUniCreditPersistenceTableNames::SHOP_CACHE;
    if ($state === 'missing') {
        $db->execute(
            "DELETE FROM `{$table}` WHERE `store_id` = " . (int) $storeId
                . " AND `unicid` = '" . $db->escape($unicid) . "'"
        );

        return;
    }

    $now = time();
    $fetchedAt = $now;
    $expiresAt = $now + $ttl;
    if ($state === 'stale') {
        $fetchedAt = $now - $ttl - 600;
        $expiresAt = $now - 600;
    } elseif ($state === 'too_old') {
        $fetchedAt = $now - $ttl - $lkg - 600;
        $expiresAt = $now - $lkg - 600;
    }

    $shopData = $state === 'corrupt'
        ? '{not-json'
        : json_encode(mtuc4_valid_shop_snapshot(), JSON_UNESCAPED_UNICODE);
    if ($state === 'corrupt') {
        $fetchedAt = $now - 60;
        $expiresAt = $now + $ttl;
    }

    $db->execute(
        "REPLACE INTO `{$table}`"
            . " (`store_id`, `unicid`, `shop_data`, `fetched_at`, `expires_at`, `created_at`, `updated_at`)"
            . " VALUES ("
            . (int) $storeId . ","
            . "'" . $db->escape($unicid) . "',"
            . "'" . $db->escape($shopData) . "',"
            . "FROM_UNIXTIME(" . (int) $fetchedAt . "),"
            . "FROM_UNIXTIME(" . (int) $expiresAt . "),"
            . "FROM_UNIXTIME(" . (int) $fetchedAt . "),"
            . "FROM_UNIXTIME(" . (int) $fetchedAt . ")"
            . ")"
    );
}

/**
 * @param MtucProbeMysql $db
 * @param int $storeId
 * @param string $unicid
 * @return string shop_data|''
 */
function mtuc_probe_cached_shop_data($db, $storeId, $unicid)
{
    $table = $db->getPrefix() . MtUniCreditPersistenceTableNames::SHOP_CACHE;
    $result = $db->query(
        "SELECT `shop_data` FROM `{$table}` WHERE `store_id` = " . (int) $storeId
            . " AND `unicid` = '" . $db->escape($unicid) . "' LIMIT 1"
    );
    if (!is_object($result) || (int) $result->num_rows !== 1) {
        return '';
    }

    return (string) $result->row['shop_data'];
}

/**
 * @param array<int, string> $argv
 * @return array<string, string>
 */
function mtuc_probe_parse_args(array $argv)
{
    $args = array();
    foreach ($argv as $index => $value) {
        if ($index === 0) {
            continue;
        }
        $value = (string) $value;
        if (strpos($value, '--') !== 0) {
            continue;
        }
        $value = substr($value, 2);
        $parts = explode('=', $value, 2);
        $args[$parts[0]] = isset($parts[1]) ? $parts[1] : '1';
    }

    return $args;
}

/**
 * Worker entry point — one independent process / one independent DB connection.
 *
 * @param array<int, string> $argv
 * @return int exit code
 */
function mtuc_probe_worker_main(array $argv)
{
    $args = mtuc_probe_parse_args($argv);
    $scenario = isset($args['scenario']) ? (string) $args['scenario'] : '';
    $worker = isset($args['worker']) ? (string) $args['worker'] : 'w0';
    $runKey = isset($args['run-key']) ? (string) $args['run-key'] : 'probe';
    $storeId = isset($args['store-id']) ? (int) $args['store-id'] : Phase4TestHarness::TEST_STORE_ID;
    $unicid = isset($args['unicid']) ? (string) $args['unicid'] : Phase4TestHarness::TEST_UNICID;
    $surface = isset($args['surface']) ? (string) $args['surface'] : 'presentation';
    $barrierKey = isset($args['barrier-key']) ? (string) $args['barrier-key'] : $runKey . ':barrier';
    $barrierExpected = isset($args['barrier-expected']) ? (int) $args['barrier-expected'] : 1;
    $holdMs = isset($args['hold-ms']) ? (int) $args['hold-ms'] : 0;

    $config = mtuc_probe_config_from_env();
    $mysql = mtuc_probe_connect($config);
    $db = new MtUniCreditDbAdapter($mysql, (string) $config['prefix']);
    $settings = new MtUniCreditSettingStore($db, MtUniCreditConstants::MODULE_SETTINGS_CODE);
    Phase4TestHarness::prepareCredentials($settings, $storeId);

    mtuc_probe_barrier_wait($mysql, $barrierKey, $worker, $barrierExpected);

    try {
        if ($scenario === 'owner_crash_hold') {
            $lock = new MtUniCreditShopConfigurationRefreshLock($db);
            $token = $lock->acquire($storeId, $unicid);
            if (!is_string($token)) {
                mtuc_probe_record_result($mysql, $runKey, $worker, $scenario, 'error', 'lock_not_acquired');

                return 1;
            }
            mtuc_probe_record_result($mysql, $runKey, $worker, $scenario, 'acquired', 'holding');
            // Hold until the probe kills this process (abrupt connection close).
            while (true) {
                usleep(100000);
            }
        }

        if ($scenario === 'lock_probe') {
            $lock = new MtUniCreditShopConfigurationRefreshLock($db);
            $acquired = is_string($lock->acquire($storeId, $unicid)) ? 'acquired' : 'busy';
            mtuc_probe_record_result($mysql, $runKey, $worker, $scenario, $acquired, '');

            return 0;
        }

        if ($scenario === 'push_vs_refresh') {
            // ONE lock identity must serialize the pull refresh and the push persistence.
            $refreshLock = new MtUniCreditShopConfigurationRefreshLock($db);
            $refreshPersistence = new MtUniCreditShopCachePersistence(
                new MtUniCreditShopCacheRepository($db),
                new MtUniCreditShopConfigurationSnapshotValidator(),
                MtUniCreditBootstrap::smartucfCredentialsRepositoryFromDb($db),
                $refreshLock
            );
            $pushMysql = mtuc_probe_connect($config);
            $pushDb = new MtUniCreditDbAdapter($pushMysql, (string) $config['prefix']);
            $pushPersistence = new MtUniCreditShopCachePersistence(
                new MtUniCreditShopCacheRepository($pushDb),
                new MtUniCreditShopConfigurationSnapshotValidator(),
                MtUniCreditBootstrap::smartucfCredentialsRepositoryFromDb($pushDb),
                new MtUniCreditShopConfigurationRefreshLock($pushDb)
            );

            // T0/T1 refresh owner acquires; remote GET is simulated by the worker holding the lock.
            $refreshToken = $refreshLock->acquire($storeId, $unicid);
            if (!is_string($refreshToken)) {
                $pushMysql->close();
                mtuc_probe_record_result($mysql, $runKey, $worker, $scenario, 'error', 'refresh_lock_not_acquired');

                return 1;
            }

            // T2/T3 push arrives and cannot write under the in-flight refresh.
            $pushBlocked = false;
            try {
                $pushPersistence->replaceValidatedSnapshot(
                    $storeId,
                    $unicid,
                    mtuc4_valid_shop_snapshot(array('uni_email' => 'probe-push@example.test'))
                );
            } catch (MtUniCreditPersistenceException $exception) {
                $pushBlocked = true;
            }

            // T4 refresh persists under its connection-verified ownership and releases.
            $refreshPersistence->replaceValidatedSnapshot(
                $storeId,
                $unicid,
                mtuc4_valid_shop_snapshot(array('uni_email' => 'probe-refresh@example.test')),
                $refreshToken
            );
            $released = $refreshLock->release($storeId, $unicid, $refreshToken);

            // T5/T6/T7 the push acquires the same lock identity and its snapshot remains final.
            $pushPersistence->replaceValidatedSnapshot(
                $storeId,
                $unicid,
                mtuc4_valid_shop_snapshot(array('uni_email' => 'probe-push@example.test'))
            );
            $final = mtuc_probe_cached_shop_data($db, $storeId, $unicid);
            $pushMysql->close();

            $ok = $pushBlocked
                && is_array($released)
                && !empty($released['ok'])
                && strpos($final, 'probe-push@example.test') !== false
                && strpos($final, 'probe-refresh@example.test') === false;
            mtuc_probe_record_result(
                $mysql,
                $runKey,
                $worker,
                $scenario,
                $ok ? 'push_final' : 'ordering_violated',
                ''
            );

            return 0;
        }

        if ($scenario === 'stale_proof') {
            // A proof captured on a dead/replaced connection must never authorize a write.
            $ownMysql = mtuc_probe_connect($config);
            $ownDb = new MtUniCreditDbAdapter($ownMysql, (string) $config['prefix']);
            $ownLock = new MtUniCreditShopConfigurationRefreshLock($ownDb);
            $ownPersistence = new MtUniCreditShopCachePersistence(
                new MtUniCreditShopCacheRepository($ownDb),
                new MtUniCreditShopConfigurationSnapshotValidator(),
                MtUniCreditBootstrap::smartucfCredentialsRepositoryFromDb($ownDb),
                $ownLock
            );
            $token = $ownLock->acquire($storeId, $unicid);
            $validBefore = is_string($token)
                && $ownLock->ownershipState($storeId, $unicid)
                    === MtUniCreditShopConfigurationRefreshLock::OWNERSHIP_VALID;

            // Drop the dedicated connection: MySQL releases the named lock and the captured proof
            // must be dropped too.
            $ownMysql->close();
            $staleAfter = $ownLock->ownershipState($storeId, $unicid)
                === MtUniCreditShopConfigurationRefreshLock::OWNERSHIP_STALE;

            $refused = false;
            try {
                $ownPersistence->replaceValidatedSnapshot(
                    $storeId,
                    $unicid,
                    mtuc4_valid_shop_snapshot(array('uni_email' => 'probe-stale@example.test'))
                );
            } catch (MtUniCreditPersistenceException $exception) {
                $refused = true;
            }
            $row = mtuc_probe_cached_shop_data($db, $storeId, $unicid);
            $noWrite = strpos($row, 'probe-stale@example.test') === false;

            mtuc_probe_record_result(
                $mysql,
                $runKey,
                $worker,
                $scenario,
                ($validBefore && $staleAfter && $refused && $noWrite) ? 'stale_proof_rejected' : 'stale_proof_leaked',
                ''
            );

            return 0;
        }

        if ($scenario === 'cache_refresh') {
            $transport = new MtucProbeCounterTransport($mysql, $runKey, mtuc4_valid_shop_snapshot());
            $stack = MtUniCreditCpServiceFactory::create(
                $db,
                $settings,
                $storeId,
                Phase4TestHarness::TEST_SHOP_URL,
                Phase4TestHarness::TEST_SHOP_URL,
                $transport,
                null,
                MtUniCreditEncryptionTestSecret::testSecretInput(),
                Phase4TestHarness::environmentConfigPath(),
                Phase4TestHarness::offlineDestinationPolicy()
            );
            $service = $stack['shopConfiguration'];

            if ($surface === 'submission') {
                try {
                    $service->getForSubmission();
                    mtuc_probe_record_result($mysql, $runKey, $worker, $scenario, 'submitted', 'strict_ok');
                } catch (MtUniCreditShopConfigurationUnavailableException $exception) {
                    mtuc_probe_record_result($mysql, $runKey, $worker, $scenario, 'fail_closed', $exception->reason());
                }
            } else {
                $shop = $service->getForPresentation();
                if (is_array($shop) && $shop !== array()) {
                    mtuc_probe_record_result($mysql, $runKey, $worker, $scenario, 'presented', 'ok');
                } else {
                    mtuc_probe_record_result($mysql, $runKey, $worker, $scenario, 'fail_closed', 'no_snapshot');
                }
            }

            if ($holdMs > 0) {
                usleep($holdMs * 1000);
            }

            return 0;
        }

        mtuc_probe_record_result($mysql, $runKey, $worker, $scenario, 'error', 'unknown_scenario');

        return 2;
    } catch (Throwable $exception) {
        mtuc_probe_record_result($mysql, $runKey, $worker, $scenario, 'error', get_class($exception));

        return 3;
    }
}

if (
    PHP_SAPI === 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && basename((string) $_SERVER['SCRIPT_FILENAME']) === 'shop_cache_concurrency_worker.php'
) {
    exit(mtuc_probe_worker_main(isset($argv) ? $argv : array()));
}
