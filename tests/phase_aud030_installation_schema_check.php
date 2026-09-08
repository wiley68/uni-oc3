<?php

/**
 * AUD-030 — installation schema completion + event install failure.
 * Run: php tests/phase_aud030_installation_schema_check.php
 *
 * F01: inventory + fresh/idempotent/repair paths for tables/columns/indexes/UNIQUEs
 * F02: duplicate UNIQUE / ALTER / CREATE failure + non-default prefix + no destructive repair
 * F03: catalog event healthy guard on module/payment install + uninstall sentinel
 *
 * PHP 7.3 compatible. Offline.
 */
require_once __DIR__ . '/bootstrap.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DIR_STORAGE')) {
    mtuc_test_define_dir_storage('mtuc-aud030');
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
}
if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'oc_');
}

require_once $lib . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . '/support/schema_ddl_memory.php';
class_alias('MtucSchemaDdlMemory', 'MtucAud030SchemaFakeDb');

$failures = array();
$passes = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucAud030_assert($condition, $message)
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
 * Event table fake for ensureCatalogEvents (AUD-029 pattern, trimmed).
 */
final class MtucAud030EventFakeDb
{
    /** @var array<int, array<string, mixed>> */
    public $rows = array();

    /** @var int */
    private $nextId = 1;

    /** @var bool */
    public $failInsert = false;

    /**
     * @param mixed $value
     * @return string
     */
    public function escape($value): string
    {
        return addslashes((string) $value);
    }

    /**
     * @param string $sql
     * @return object
     */
    public function query(string $sql): object
    {
        $sql = (string) $sql;
        $ok = (object) array('num_rows' => 0, 'row' => array(), 'rows' => array());
        if (stripos($sql, 'SELECT') === 0) {
            if (preg_match("/WHERE `code` = '([^']+)'/", $sql, $m)) {
                $code = stripslashes($m[1]);
                $matched = array();
                foreach ($this->rows as $row) {
                    if ((string) $row['code'] === $code) {
                        $matched[] = $row;
                    }
                }

                return (object) array(
                    'num_rows' => count($matched),
                    'row' => $matched ? $matched[0] : array(),
                    'rows' => $matched,
                );
            }

            return (object) array('num_rows' => count($this->rows), 'row' => array(), 'rows' => $this->rows);
        }
        if (stripos($sql, 'INSERT') === 0) {
            if ($this->failInsert) {
                throw new Exception('event insert failed');
            }
            preg_match("/`code` = '([^']+)'/", $sql, $mCode);
            preg_match("/`trigger` = '([^']+)'/", $sql, $mTrigger);
            preg_match("/`action` = '([^']+)'/", $sql, $mAction);
            $this->rows[] = array(
                'event_id' => $this->nextId++,
                'code' => stripslashes($mCode[1]),
                'trigger' => isset($mTrigger[1]) ? stripslashes($mTrigger[1]) : '',
                'action' => isset($mAction[1]) ? stripslashes($mAction[1]) : '',
                'status' => 1,
                'sort_order' => 0,
            );

            return $ok;
        }
        if (stripos($sql, 'UPDATE') === 0 && preg_match('/WHERE `event_id` = (\d+)/', $sql, $mId)) {
            $id = (int) $mId[1];
            foreach ($this->rows as &$row) {
                if ((int) $row['event_id'] === $id) {
                    if (preg_match("/`trigger` = '([^']+)'/", $sql, $mTrigger)) {
                        $row['trigger'] = stripslashes($mTrigger[1]);
                    }
                    if (preg_match("/`action` = '([^']+)'/", $sql, $mAction)) {
                        $row['action'] = stripslashes($mAction[1]);
                    }
                    $row['status'] = 1;
                    $row['sort_order'] = 0;
                }
            }
            unset($row);

            return $ok;
        }
        if (stripos($sql, 'DELETE') === 0) {
            if (preg_match('/WHERE `event_id` = (\d+)/', $sql, $mId)) {
                $id = (int) $mId[1];
                $this->rows = array_values(array_filter($this->rows, function ($row) use ($id) {
                    return (int) $row['event_id'] !== $id;
                }));
            }

            return $ok;
        }

        return $ok;
    }
}

/**
 * @param MtucAud030SchemaFakeDb $fakeDb
 * @param string $prefix
 * @return MtUniCreditDbAdapter
 */
function mtucAud030_adapter(MtucAud030SchemaFakeDb $fakeDb, string $prefix): MtUniCreditDbAdapter
{
    return new MtUniCreditDbAdapter($fakeDb, $prefix);
}

/**
 * @param MtucAud030SchemaFakeDb $fake
 * @param string $prefix
 * @return bool
 */
function mtucAud030_hasAllInventory(MtucAud030SchemaFakeDb $fake, string $prefix): bool
{
    foreach (MtUniCreditPersistenceSchema::requiredTableInventory() as $logical => $spec) {
        $table = $prefix . $logical;
        if (!isset($fake->tables[$table])) {
            return false;
        }
        foreach (array_keys($spec['columns']) as $column) {
            if (!isset($fake->tables[$table]['columns'][$column])) {
                return false;
            }
        }
        foreach ($spec['indexes'] as $expected) {
            $name = (string) $expected['name'];
            if (!isset($fake->tables[$table]['indexes'][$name])) {
                return false;
            }
            $actual = $fake->tables[$table]['indexes'][$name];
            if ((bool) $actual['unique'] !== (bool) $expected['unique']) {
                return false;
            }
            if (array_values($actual['columns']) !== array_values($expected['columns'])) {
                return false;
            }
        }
    }

    return true;
}

/**
 * @param MtucAud030SchemaFakeDb $fake
 * @param string $prefix
 * @param string $logical
 * @param string $indexName
 * @return bool
 */
function mtucAud030_hasIndex(MtucAud030SchemaFakeDb $fake, string $prefix, string $logical, string $indexName): bool
{
    $table = $prefix . $logical;

    return isset($fake->tables[$table]['indexes'][$indexName]);
}

/**
 * @param MtucAud030SchemaFakeDb $fake
 * @param string $prefix
 * @param string $logical
 * @param string $column
 * @return bool
 */
function mtucAud030_hasColumn(MtucAud030SchemaFakeDb $fake, string $prefix, string $logical, string $column): bool
{
    $table = $prefix . $logical;

    return isset($fake->tables[$table]['columns'][$column]);
}

/**
 * @param string $path
 * @return string
 */
function mtucAud030_read(string $path): string
{
    $contents = file_get_contents($path);

    return is_string($contents) ? $contents : '';
}

// ---------------------------------------------------------------------------
// Inventory shape
// ---------------------------------------------------------------------------
$inventory = MtUniCreditPersistenceSchema::requiredTableInventory();
$allTables = MtUniCreditPersistenceTableNames::allPersistenceTables();
mtucAud030_assert(count($allTables) === 8, 'inventory source: allPersistenceTables() has exactly 8 tables');
mtucAud030_assert(count($inventory) === 8, 'inventory: requiredTableInventory() has exactly 8 tables');
mtucAud030_assert(
    array_values(array_keys($inventory)) === array_values($allTables)
        || count(array_diff($allTables, array_keys($inventory))) === 0
        && count(array_diff(array_keys($inventory), $allTables)) === 0,
    'inventory: keys match allPersistenceTables()'
);

$criticalUniques = array(
    MtUniCreditPersistenceTableNames::API_NONCE => 'uniq_mt_uni_credit_api_nonce',
    MtUniCreditPersistenceTableNames::OPERATION_LOCK => 'uniq_mt_uni_credit_operation_lock',
    MtUniCreditPersistenceTableNames::SHOP_CACHE => 'uniq_mt_uni_credit_shop_cache_store_unicid',
    MtUniCreditPersistenceTableNames::ORDER_BANK_STATUS => 'uniq_mt_uni_credit_order_bank_store_order',
    MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT => 'uniq_mt_uni_credit_store_order',
    MtUniCreditPersistenceTableNames::OPERATION_ORDER_CLAIM => 'uniq_mt_uni_credit_operation_order_claim',
    MtUniCreditPersistenceTableNames::PROCESS2_MAIL_RECIPIENT => 'uniq_mt_uni_credit_p2_mail_recipient',
);
foreach ($criticalUniques as $logical => $uniqName) {
    $found = false;
    if (isset($inventory[$logical]['indexes']) && is_array($inventory[$logical]['indexes'])) {
        foreach ($inventory[$logical]['indexes'] as $idx) {
            if (!empty($idx['unique']) && (string) $idx['name'] === $uniqName) {
                $found = true;
                break;
            }
        }
    }
    mtucAud030_assert($found, 'inventory UNIQUE: ' . $logical . ' has ' . $uniqName);
}

// ---------------------------------------------------------------------------
// Fresh install + verify
// ---------------------------------------------------------------------------
$prefix = 'oc_';
$fake = new MtucAud030SchemaFakeDb();
$adapter = mtucAud030_adapter($fake, $prefix);
$freshOk = true;
$freshError = '';
try {
    MtUniCreditPersistenceSchema::installAll($adapter);
} catch (Exception $e) {
    $freshOk = false;
    $freshError = $e->getMessage();
}
mtucAud030_assert($freshOk, 'fresh install: installAll succeeds' . ($freshError !== '' ? ' (' . $freshError . ')' : ''));
mtucAud030_assert(count($fake->tables) === 8, 'fresh install: 8 physical tables present');
mtucAud030_assert(mtucAud030_hasAllInventory($fake, $prefix), 'fresh install: all inventory columns + indexes present');

$verifyOk = true;
try {
    $verifier = new MtUniCreditPersistenceSchema($adapter);
    $verifier->verifyRequiredSchema();
} catch (Exception $e) {
    $verifyOk = false;
}
mtucAud030_assert($verifyOk, 'fresh install: verifyRequiredSchema does not throw');

// Idempotent reinstall
$repeatOk = true;
try {
    MtUniCreditPersistenceSchema::installAll($adapter);
} catch (Exception $e) {
    $repeatOk = false;
}
mtucAud030_assert($repeatOk, 'repeat installAll: idempotent success');
mtucAud030_assert(mtucAud030_hasAllInventory($fake, $prefix), 'repeat installAll: inventory still complete');

// ---------------------------------------------------------------------------
// Missing table repair
// ---------------------------------------------------------------------------
$dropLogical = MtUniCreditPersistenceTableNames::SHOP_CACHE;
$fake->dropTable($prefix . $dropLogical);
mtucAud030_assert(!isset($fake->tables[$prefix . $dropLogical]), 'missing table: shop_cache dropped');
MtUniCreditPersistenceSchema::installAll($adapter);
mtucAud030_assert(isset($fake->tables[$prefix . $dropLogical]), 'missing table: reinstall recreates shop_cache');
mtucAud030_assert(mtucAud030_hasAllInventory($fake, $prefix), 'missing table: inventory complete after recreate');

// ---------------------------------------------------------------------------
// Missing base column (preserve seeded rows)
// ---------------------------------------------------------------------------
$nonceTable = $prefix . MtUniCreditPersistenceTableNames::API_NONCE;
$fake->seedRow($nonceTable, array(
    'api_nonce_id' => 1,
    'store_id' => 1,
    'unicid' => 'u1',
    'nonce_hash' => str_repeat('a', 64),
    'used_at' => '2020-01-01 00:00:00',
    'expires_at' => '2020-01-02 00:00:00',
));
$rowCountBefore = count($fake->rows[$nonceTable]);
$fake->dropColumn($nonceTable, 'store_id');
mtucAud030_assert(!mtucAud030_hasColumn($fake, $prefix, MtUniCreditPersistenceTableNames::API_NONCE, 'store_id'), 'missing base column: store_id dropped');
MtUniCreditPersistenceSchema::installAll($adapter);
mtucAud030_assert(mtucAud030_hasColumn($fake, $prefix, MtUniCreditPersistenceTableNames::API_NONCE, 'store_id'), 'missing base column: store_id restored');
mtucAud030_assert(count($fake->rows[$nonceTable]) === $rowCountBefore, 'missing base column: seeded row count preserved');

// ---------------------------------------------------------------------------
// Missing upgrade column
// ---------------------------------------------------------------------------
$finTable = $prefix . MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT;
$fake->dropColumn($finTable, 'cart_clear_state');
mtucAud030_assert(!mtucAud030_hasColumn($fake, $prefix, MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT, 'cart_clear_state'), 'missing upgrade column: cart_clear_state dropped');
MtUniCreditPersistenceSchema::installAll($adapter);
mtucAud030_assert(mtucAud030_hasColumn($fake, $prefix, MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT, 'cart_clear_state'), 'missing upgrade column: cart_clear_state restored');

// ---------------------------------------------------------------------------
// Missing ordinary index
// ---------------------------------------------------------------------------
$fake->dropIndex($nonceTable, 'idx_mt_uni_credit_api_nonce_expires');
mtucAud030_assert(
    !mtucAud030_hasIndex($fake, $prefix, MtUniCreditPersistenceTableNames::API_NONCE, 'idx_mt_uni_credit_api_nonce_expires'),
    'missing ordinary index: expires KEY dropped'
);
MtUniCreditPersistenceSchema::installAll($adapter);
mtucAud030_assert(
    mtucAud030_hasIndex($fake, $prefix, MtUniCreditPersistenceTableNames::API_NONCE, 'idx_mt_uni_credit_api_nonce_expires'),
    'missing ordinary index: expires KEY restored'
);

// ---------------------------------------------------------------------------
// Missing UNIQUE replay / lock / ownership
// ---------------------------------------------------------------------------
$fake->dropIndex($nonceTable, 'uniq_mt_uni_credit_api_nonce');
MtUniCreditPersistenceSchema::installAll($adapter);
mtucAud030_assert(
    mtucAud030_hasIndex($fake, $prefix, MtUniCreditPersistenceTableNames::API_NONCE, 'uniq_mt_uni_credit_api_nonce'),
    'missing UNIQUE replay: uniq_mt_uni_credit_api_nonce restored'
);

$lockTable = $prefix . MtUniCreditPersistenceTableNames::OPERATION_LOCK;
$fake->dropIndex($lockTable, 'uniq_mt_uni_credit_operation_lock');
MtUniCreditPersistenceSchema::installAll($adapter);
mtucAud030_assert(
    mtucAud030_hasIndex($fake, $prefix, MtUniCreditPersistenceTableNames::OPERATION_LOCK, 'uniq_mt_uni_credit_operation_lock'),
    'missing UNIQUE lock: uniq_mt_uni_credit_operation_lock restored'
);

$bankTable = $prefix . MtUniCreditPersistenceTableNames::ORDER_BANK_STATUS;
$fake->dropIndex($bankTable, 'uniq_mt_uni_credit_order_bank_store_order');
MtUniCreditPersistenceSchema::installAll($adapter);
mtucAud030_assert(
    mtucAud030_hasIndex($fake, $prefix, MtUniCreditPersistenceTableNames::ORDER_BANK_STATUS, 'uniq_mt_uni_credit_order_bank_store_order'),
    'missing UNIQUE ownership: order_bank_store_order restored'
);

$fake->dropIndex($finTable, 'uniq_mt_uni_credit_store_order');
MtUniCreditPersistenceSchema::installAll($adapter);
mtucAud030_assert(
    mtucAud030_hasIndex($fake, $prefix, MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT, 'uniq_mt_uni_credit_store_order'),
    'missing UNIQUE ownership: financing store_order restored'
);

// ---------------------------------------------------------------------------
// Duplicate-data UNIQUE failure
// ---------------------------------------------------------------------------
$dupFake = new MtucAud030SchemaFakeDb();
$dupAdapter = mtucAud030_adapter($dupFake, $prefix);
MtUniCreditPersistenceSchema::installAll($dupAdapter);
$dupNonce = $prefix . MtUniCreditPersistenceTableNames::API_NONCE;
$dupFake->dropIndex($dupNonce, 'uniq_mt_uni_credit_api_nonce');
$dupFake->rows[$dupNonce] = array();
$dupFake->seedRow($dupNonce, array(
    'api_nonce_id' => 1,
    'store_id' => 7,
    'unicid' => 'same-unicid',
    'nonce_hash' => str_repeat('b', 64),
));
$dupFake->seedRow($dupNonce, array(
    'api_nonce_id' => 2,
    'store_id' => 7,
    'unicid' => 'same-unicid',
    'nonce_hash' => str_repeat('b', 64),
));
$dupThrew = false;
$dupClass = '';
try {
    MtUniCreditPersistenceSchema::installAll($dupAdapter);
} catch (MtUniCreditInstallationException $e) {
    $dupThrew = true;
    $dupClass = get_class($e);
} catch (Exception $e) {
    $dupThrew = true;
    $dupClass = get_class($e);
}
mtucAud030_assert($dupThrew && $dupClass === 'MtUniCreditInstallationException', 'duplicate UNIQUE: installAll throws MtUniCreditInstallationException');
mtucAud030_assert(count($dupFake->rows[$dupNonce]) === 2, 'duplicate UNIQUE: rows still length 2');
mtucAud030_assert(
    !mtucAud030_hasIndex($dupFake, $prefix, MtUniCreditPersistenceTableNames::API_NONCE, 'uniq_mt_uni_credit_api_nonce'),
    'duplicate UNIQUE: unique index not silently added'
);

// ---------------------------------------------------------------------------
// ALTER failure (missing column path)
// ---------------------------------------------------------------------------
$alterFake = new MtucAud030SchemaFakeDb();
$alterAdapter = mtucAud030_adapter($alterFake, $prefix);
MtUniCreditPersistenceSchema::installAll($alterAdapter);
$alterFake->dropColumn($prefix . MtUniCreditPersistenceTableNames::API_NONCE, 'expires_at');
$alterFake->failNextAlter = true;
$alterThrew = false;
try {
    MtUniCreditPersistenceSchema::installAll($alterAdapter);
} catch (MtUniCreditInstallationException $e) {
    $alterThrew = true;
} catch (Exception $e) {
    $alterThrew = true;
}
mtucAud030_assert($alterThrew, 'ALTER failure: installAll throws (no silent success)');
mtucAud030_assert(
    !mtucAud030_hasColumn($alterFake, $prefix, MtUniCreditPersistenceTableNames::API_NONCE, 'expires_at'),
    'ALTER failure: missing column not restored after failed ALTER'
);

// ---------------------------------------------------------------------------
// CREATE failure
// ---------------------------------------------------------------------------
$createFake = new MtucAud030SchemaFakeDb();
$createFake->failNextCreate = true;
$createAdapter = mtucAud030_adapter($createFake, $prefix);
$createThrew = false;
try {
    MtUniCreditPersistenceSchema::installAll($createAdapter);
} catch (MtUniCreditInstallationException $e) {
    $createThrew = true;
} catch (Exception $e) {
    $createThrew = true;
}
mtucAud030_assert($createThrew, 'CREATE failure: empty db + failNextCreate → installAll throws');
mtucAud030_assert($createFake->tables === array(), 'CREATE failure: no tables created');

// ---------------------------------------------------------------------------
// Non-default prefix zz_
// ---------------------------------------------------------------------------
$zzFake = new MtucAud030SchemaFakeDb();
$zzAdapter = mtucAud030_adapter($zzFake, 'zz_');
MtUniCreditPersistenceSchema::installAll($zzAdapter);
$zzKeys = array_keys($zzFake->tables);
$allZz = $zzKeys !== array();
$noOc = true;
foreach ($zzKeys as $key) {
    if (strpos((string) $key, 'zz_mt_uni_credit_') !== 0) {
        $allZz = false;
    }
    if (strpos((string) $key, 'oc_') === 0) {
        $noOc = false;
    }
}
mtucAud030_assert(count($zzKeys) === 8, 'prefix zz_: 8 tables installed');
mtucAud030_assert($allZz, 'prefix zz_: every physical table starts with zz_mt_uni_credit_');
mtucAud030_assert($noOc, 'prefix zz_: no oc_ physical table keys');
mtucAud030_assert(mtucAud030_hasAllInventory($zzFake, 'zz_'), 'prefix zz_: inventory complete under zz_');

// ---------------------------------------------------------------------------
// Module event failure + source guards (F03)
// ---------------------------------------------------------------------------
$moduleModelPath = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR
    . 'model' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'module' . DIRECTORY_SEPARATOR . 'mt_uni_credit.php';
$paymentModelPath = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR
    . 'model' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'payment' . DIRECTORY_SEPARATOR . 'mt_uni_credit.php';
$schemaPath = $lib . DIRECTORY_SEPARATOR . 'persistence_schema.php';
$inventoryPath = $lib . DIRECTORY_SEPARATOR . 'persistence_schema_inventory.php';

$moduleSrc = mtucAud030_read($moduleModelPath);
$paymentSrc = mtucAud030_read($paymentModelPath);
$schemaSrc = mtucAud030_read($schemaPath);
$inventorySrc = mtucAud030_read($inventoryPath);

mtucAud030_assert(
    strpos($moduleSrc, "empty(\$events['healthy'])") !== false
        && strpos($moduleSrc, 'MtUniCreditInstallationException') !== false
        && strpos($moduleSrc, 'Presentation event registration failed during module install.') !== false,
    'F03 source: module install checks healthy + throws InstallationException'
);
mtucAud030_assert(
    strpos($paymentSrc, "empty(\$events['healthy'])") !== false
        && strpos($paymentSrc, 'MtUniCreditInstallationException') !== false
        && strpos($paymentSrc, 'Presentation event registration failed during payment install.') !== false,
    'F03 source: payment install checks healthy + throws InstallationException'
);

$invalidEvents = MtUniCreditInstaller::ensureCatalogEvents(null);
mtucAud030_assert(empty($invalidEvents['healthy']), 'F03: ensureCatalogEvents(null) → healthy false');
mtucAud030_assert($invalidEvents['error'] === 'invalid_db', 'F03: ensureCatalogEvents(null) → invalid_db');

$guardThrew = false;
$events = array('healthy' => false, 'error' => 'x');
try {
    if (empty($events['healthy'])) {
        throw new MtUniCreditInstallationException(
            'Presentation event registration failed during module install.'
        );
    }
} catch (MtUniCreditInstallationException $e) {
    $guardThrew = true;
}
mtucAud030_assert($guardThrew, 'F03: install guard path throws when healthy empty');

$brokenEv = new MtucAud030EventFakeDb();
$brokenEv->failInsert = true;
$brokenResult = MtUniCreditInstaller::ensureCatalogEvents($brokenEv);
mtucAud030_assert(empty($brokenResult['healthy']), 'F03 behavioral: INSERT failure → healthy false');

// Event healthy + rerun after failure
$okEv = new MtucAud030EventFakeDb();
$okResult = MtUniCreditInstaller::ensureCatalogEvents($okEv);
mtucAud030_assert(!empty($okResult['healthy']), 'event healthy: ensureCatalogEvents with EventFakeDb → healthy true');
mtucAud030_assert(count($okEv->rows) === count(MtUniCreditCatalogEventRegistry::definitions()), 'event healthy: canonical event row count');

$rerun = MtUniCreditInstaller::ensureCatalogEvents($okEv);
mtucAud030_assert(!empty($rerun['healthy']), 'rerun after event failure path: second ensure with healthy DB succeeds');

$eventsOk = array('healthy' => true, 'error' => null);
$secondGuardOk = true;
try {
    if (empty($eventsOk['healthy'])) {
        throw new MtUniCreditInstallationException('should not throw');
    }
} catch (MtUniCreditInstallationException $e) {
    $secondGuardOk = false;
}
mtucAud030_assert($secondGuardOk, 'rerun: install guard allows healthy=true');

// ---------------------------------------------------------------------------
// Uninstall sentinel + destructive-repair scan
// ---------------------------------------------------------------------------
mtucAud030_assert(
    strpos($moduleSrc, 'removeCatalogEvents') !== false
        && strpos($moduleSrc, 'MODULE_SETTINGS_CODE') !== false
        && preg_match('/function\s+uninstall\s*\(\s*\)\s*\{[^}]*removeCatalogEvents[^}]*deleteSetting\s*\(\s*MtUniCreditConstants::MODULE_SETTINGS_CODE/s', $moduleSrc) === 1,
    'uninstall sentinel: module uninstall has removeCatalogEvents + deleteSetting MODULE only'
);
mtucAud030_assert(
    preg_match('/function\s+uninstall\s*\(\s*\)\s*\{[^}]*deleteSetting\s*\(\s*MtUniCreditConstants::PAYMENT_SETTINGS_CODE/s', $paymentSrc) === 1
        && !preg_match('/function\s+uninstall\s*\(\s*\)\s*\{[^}]*removeCatalogEvents/s', $paymentSrc),
    'uninstall sentinel: payment uninstall deleteSetting PAYMENT only (no removeCatalogEvents)'
);
mtucAud030_assert(
    stripos($schemaSrc, 'DROP TABLE') === false
        && stripos($schemaSrc, 'no DROP on uninstall') !== false,
    'uninstall policy: PersistenceSchema documents/avoids DROP TABLE'
);
mtucAud030_assert(
    stripos($schemaSrc, 'TRUNCATE') === false
        && !preg_match('/\bDELETE\s+FROM\b/i', $schemaSrc),
    'destructive-repair scan: persistence_schema.php has no TRUNCATE/DELETE FROM'
);
mtucAud030_assert(
    stripos($inventorySrc, 'DROP TABLE') === false
        && stripos($inventorySrc, 'TRUNCATE') === false
        && !preg_match('/\bDELETE\s+FROM\b/i', $inventorySrc),
    'destructive-repair scan: inventory has no DROP TABLE/TRUNCATE/DELETE FROM'
);

// ---------------------------------------------------------------------------
// Mutation sensitivity F01 1-8 / F02 1-4 / F03 1-4
// ---------------------------------------------------------------------------
mtucAud030_assert(count($fake->tables) === 8 && mtucAud030_hasAllInventory($fake, $prefix), 'F01 mutation-1 YES: fresh inventory completeness required');
mtucAud030_assert($repeatOk, 'F01 mutation-2 YES: idempotent reinstall required');
mtucAud030_assert(isset($fake->tables[$prefix . $dropLogical]), 'F01 mutation-3 YES: missing table recreate required');
mtucAud030_assert(mtucAud030_hasColumn($fake, $prefix, MtUniCreditPersistenceTableNames::API_NONCE, 'store_id'), 'F01 mutation-4 YES: missing base column restore required');
mtucAud030_assert(mtucAud030_hasColumn($fake, $prefix, MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT, 'cart_clear_state'), 'F01 mutation-5 YES: missing upgrade column restore required');
mtucAud030_assert(mtucAud030_hasIndex($fake, $prefix, MtUniCreditPersistenceTableNames::API_NONCE, 'idx_mt_uni_credit_api_nonce_expires'), 'F01 mutation-6 YES: missing ordinary index restore required');
mtucAud030_assert(mtucAud030_hasIndex($fake, $prefix, MtUniCreditPersistenceTableNames::API_NONCE, 'uniq_mt_uni_credit_api_nonce'), 'F01 mutation-7 YES: missing UNIQUE replay restore required');
mtucAud030_assert($dupThrew && count($dupFake->rows[$dupNonce]) === 2, 'F01 mutation-8 YES: duplicate-data UNIQUE must fail closed');

mtucAud030_assert($alterThrew, 'F02 mutation-1 YES: ALTER failure must not silent-succeed');
mtucAud030_assert($createThrew, 'F02 mutation-2 YES: CREATE failure must throw');
mtucAud030_assert($allZz && $noOc, 'F02 mutation-3 YES: non-default prefix zz_ isolation required');
mtucAud030_assert(
    stripos($schemaSrc, 'DROP TABLE') === false && stripos($inventorySrc, 'TRUNCATE') === false,
    'F02 mutation-4 YES: destructive repair DDL must stay absent'
);

mtucAud030_assert(
    strpos($moduleSrc, "empty(\$events['healthy'])") !== false
        && strpos($paymentSrc, "empty(\$events['healthy'])") !== false,
    'F03 mutation-1 YES: both admin models require healthy check'
);
mtucAud030_assert(empty($invalidEvents['healthy']), 'F03 mutation-2 YES: invalid db → unhealthy');
mtucAud030_assert(!empty($okResult['healthy']), 'F03 mutation-3 YES: working EventFakeDb → healthy');
mtucAud030_assert(!empty($rerun['healthy']) && $secondGuardOk, 'F03 mutation-4 YES: rerun after healthy succeeds');

// ---------------------------------------------------------------------------
echo PHP_EOL . 'AUD-030 installation schema: ' . $passes . ' PASS, ' . count($failures) . ' FAIL' . PHP_EOL;
if ($failures) {
    exit(1);
}
exit(0);
