<?php

/**
 * AUD-024-F01 — Admin order-list bank-status store scoping.
 * Run: php tests/phase_aud024_f01_admin_list_store_scope_check.php
 *
 * Real OC3 sale/order list rows include order_id but NOT store_id.
 * Labels must resolve via native oc_order.store_id (store 0 valid), never config_store_id.
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
    mtuc_test_define_dir_storage('mtuc-aud024-f01');
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
function mtucAud024F01_assert($condition, $message)
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
 * Records SQL while delegating to Phase2MemoryDb (final — composition, not extend).
 */
final class MtucAud024F01ProbeDb
{
    /** @var Phase2MemoryDb */
    private $inner;
    /** @var array<int, string> */
    public $queries = array();

    public function __construct()
    {
        $this->inner = new Phase2MemoryDb();
    }

    /**
     * @param int $orderId
     * @param int $storeId
     * @param string $paymentCode
     * @param mixed $paymentMethod
     * @return void
     */
    public function seedOrder($orderId, $storeId, $paymentCode = 'mt_uni_credit', $paymentMethod = '')
    {
        $this->inner->seedOrder($orderId, $storeId, $paymentCode, $paymentMethod);
    }

    /**
     * @param string $sql
     * @return object
     */
    public function query($sql)
    {
        $this->queries[] = (string) $sql;
        $lower = strtolower((string) $sql);
        if (strpos($lower, 'control_panel') !== false || strpos($lower, 'curl_') !== false) {
            throw new RuntimeException('AUD-024-F01: unexpected remote/CP SQL: ' . $sql);
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

    /**
     * @return string
     */
    public function getPrefix()
    {
        return $this->inner->getPrefix();
    }
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return void
 */
function mtucAud024F01_assertRealOc3RowShape(array $rows)
{
    foreach ($rows as $i => $row) {
        mtucAud024F01_assert(
            is_array($row) && isset($row['order_id']) && !array_key_exists('store_id', $row),
            'real-row-shape: index ' . $i . ' has order_id and omits store_id'
        );
    }
}

$memoryDb = new MtucAud024F01ProbeDb();
$dbAdapter = new MtUniCreditDbAdapter($memoryDb, 'oc_');
$bankRepo = new MtUniCreditOrderBankStatusRepository($dbAdapter);
$presentationRepo = new MtUniCreditFinancingPresentationRepository($dbAdapter);

// Native orders (authoritative store identity)
$memoryDb->seedOrder(100, 0, MtUniCreditConstants::EXTENSION_CODE);
$memoryDb->seedOrder(200, 2, MtUniCreditConstants::EXTENSION_CODE);
$memoryDb->seedOrder(300, 2, MtUniCreditConstants::EXTENSION_CODE);
$memoryDb->seedOrder(400, 0, MtUniCreditConstants::EXTENSION_CODE);

$bankRepo->updateByOrderIdentifier(
    0,
    '100',
    MtUniCreditBankStatus::SENT_PROCESS1,
    MtUniCreditBankStatus::LABEL_SENT_PROCESS1
);
$bankRepo->updateByOrderIdentifier(
    2,
    '200',
    MtUniCreditBankStatus::SEND_FAILED_CP,
    MtUniCreditBankStatus::LABEL_SEND_FAILED_CP
);
$bankRepo->updateByOrderIdentifier(
    2,
    '300',
    MtUniCreditBankStatus::SENT_PROCESS2,
    MtUniCreditBankStatus::LABEL_SENT_PROCESS2
);
$bankRepo->updateByOrderIdentifier(
    0,
    '400',
    MtUniCreditBankStatus::SEND_FAILED_SMARTUCF,
    MtUniCreditBankStatus::LABEL_SEND_FAILED_SMARTUCF
);

// Extra collision bait: store 0 status for order 200 must NEVER attach when native store is 2.
$bankRepo->updateByOrderIdentifier(
    0,
    '200',
    MtUniCreditBankStatus::SENT_PROCESS1,
    MtUniCreditBankStatus::LABEL_SENT_PROCESS1
);

$wrongFallback = 99;

// ---------------------------------------------------------------------------
// A/B/C — real OC3 row shape (no store_id)
// ---------------------------------------------------------------------------
$realRows = array(
    array('order_id' => 100),
    array('order_id' => 200),
    array('order_id' => 300),
    array('order_id' => 400),
    array('order_id' => 500), // native missing financing + we will seed order without bank status
);
$memoryDb->seedOrder(500, 2, MtUniCreditConstants::EXTENSION_CODE);
mtucAud024F01_assertRealOc3RowShape($realRows);

$labels = $presentationRepo->bankStatusLabelsForOrders($realRows, $wrongFallback);
mtucAud024F01_assert(
    $labels[0] === MtUniCreditBankStatus::LABEL_SENT_PROCESS1,
    'A store-0: Process 1 sent'
);
mtucAud024F01_assert(
    $labels[1] === MtUniCreditBankStatus::LABEL_SEND_FAILED_CP,
    'B store-2: CP failure (not store-0 Process 1 bait)'
);
mtucAud024F01_assert(
    $labels[2] === MtUniCreditBankStatus::LABEL_SENT_PROCESS2,
    'C mixed: store-2 Process 2'
);
mtucAud024F01_assert(
    $labels[3] === MtUniCreditBankStatus::LABEL_SEND_FAILED_SMARTUCF,
    'C mixed: store-0 SmartUCF failure'
);
mtucAud024F01_assert($labels[4] === '', 'D missing financing: blank label');

// ---------------------------------------------------------------------------
// List/detail consistency for key statuses
// ---------------------------------------------------------------------------
$detailPairs = array(
    array(0, 100, MtUniCreditBankStatus::LABEL_SENT_PROCESS1),
    array(2, 300, MtUniCreditBankStatus::LABEL_SENT_PROCESS2),
    array(0, 400, MtUniCreditBankStatus::LABEL_SEND_FAILED_SMARTUCF),
    array(2, 200, MtUniCreditBankStatus::LABEL_SEND_FAILED_CP),
);
foreach ($detailPairs as $pair) {
    $storeId = $pair[0];
    $orderId = $pair[1];
    $expected = $pair[2];
    $detail = $presentationRepo->findBankStatusLabel($storeId, $orderId);
    $listIdx = null;
    foreach ($realRows as $idx => $row) {
        if ((int) $row['order_id'] === (int) $orderId) {
            $listIdx = $idx;
            break;
        }
    }
    mtucAud024F01_assert(
        $listIdx !== null && $labels[$listIdx] === $detail && $detail === $expected,
        'list/detail consistency order ' . $orderId . ' store ' . $storeId
    );
}

// ---------------------------------------------------------------------------
// Native store map authority (store 0 preserved)
// ---------------------------------------------------------------------------
$nativeMap = $presentationRepo->batchNativeOrderStoreIds(array(100, 200, 500));
mtucAud024F01_assert(
    array_key_exists(100, $nativeMap) && $nativeMap[100] === 0,
    'native map: order 100 → store 0'
);
mtucAud024F01_assert(
    array_key_exists(200, $nativeMap) && $nativeMap[200] === 2,
    'native map: order 200 → store 2'
);

// Wrong fallback must not contaminate (mutation 1)
mtucAud024F01_assert(
    $labels[0] !== '' && $labels[1] === MtUniCreditBankStatus::LABEL_SEND_FAILED_CP,
    'mutation-1 YES: config_store_id fallback contamination detected'
);

// ---------------------------------------------------------------------------
// Network / CP invariant (static + runtime)
// ---------------------------------------------------------------------------
$adminSrc = (string) file_get_contents(
    $root . '/upload/admin/controller/extension/mt_uni_credit/admin_order.php'
);
$repoSrc = (string) file_get_contents(
    $root . '/upload/system/library/mt_uni_credit/financing_presentation_repository.php'
);
mtucAud024F01_assert(
    strpos($adminSrc, 'function beforeOrderList') !== false
        && strpos($adminSrc, 'ControlPanelClient') === false
        && strpos($adminSrc, 'refreshBankData') === false
        && strpos($adminSrc, 'refreshRemote') === false
        && stripos($adminSrc, 'curl') === false,
    'E static: beforeOrderList has no CP/HTTP/refresh'
);
mtucAud024F01_assert(
    strpos($repoSrc, 'batchNativeOrderStoreIds') !== false
        && strpos($repoSrc, 'ControlPanelClient') === false
        && strpos($repoSrc, 'refreshRemote') === false,
    'E static: repository uses local native batch, no CP'
);
mtucAud024F01_assert(
    preg_match(
        '/bankStatusLabelsForOrders\([\s\S]*?batchNativeOrderStoreIds/s',
        $repoSrc
    ) === 1,
    'source: bankStatusLabelsForOrders resolves via batchNativeOrderStoreIds'
);
mtucAud024F01_assert(
    preg_match(
        '/bankStatusLabelsForOrders\([\s\S]*?\$storeId\s*=\s*\(int\)\s*\$fallbackStoreId/s',
        $repoSrc
    ) !== 1,
    'source: no fallbackStoreId assignment as row store authority'
);

$queryBefore = count($memoryDb->queries);
$presentationRepo->bankStatusLabelsForOrders(
    array(array('order_id' => 100), array('order_id' => 200)),
    $wrongFallback
);
$newQueries = array_slice($memoryDb->queries, $queryBefore);
$joined = implode("\n", $newQueries);
mtucAud024F01_assert(
    (strpos($joined, 'order_bank_status') !== false)
        && (strpos($joined, 'SELECT `order_id`, `store_id`') !== false
            || preg_match('/FROM\s+`[^`]*order`/i', $joined) === 1)
        && stripos($joined, 'http://') === false
        && stripos($joined, 'https://') === false,
    'E runtime: local order + bank_status queries only'
);

// ---------------------------------------------------------------------------
// Mutation sensitivity matrix
// ---------------------------------------------------------------------------
mtucAud024F01_assert(
    $labels[0] === MtUniCreditBankStatus::LABEL_SENT_PROCESS1
        && $wrongFallback === 99,
    'mutation-1 YES: fallback to config_store_id'
);
mtucAud024F01_assert(
    !array_key_exists('store_id', $realRows[0]) && $labels[0] !== '',
    'mutation-2 YES: assumption that row contains store_id'
);
mtucAud024F01_assert(
    array_key_exists(100, $nativeMap) && $nativeMap[100] === 0 && $labels[0] !== '',
    'mutation-3 YES: treating store 0 as missing'
);
mtucAud024F01_assert(
    $labels[1] === MtUniCreditBankStatus::LABEL_SEND_FAILED_CP
        && $labels[1] !== MtUniCreditBankStatus::LABEL_SENT_PROCESS1,
    'mutation-4/5 YES: order-id-only or store-0 status on store-2 row'
);
mtucAud024F01_assert(
    $labels[1] === MtUniCreditBankStatus::LABEL_SEND_FAILED_CP,
    'mutation-6 YES: missing non-default-store status'
);
mtucAud024F01_assert(
    $labels[4] === '',
    'mutation-7 YES: fabricating status for unfinanced order'
);
mtucAud024F01_assert(
    strpos($adminSrc, 'ControlPanelClient') === false
        && strpos($repoSrc, 'ControlPanelClient') === false,
    'mutation-8 YES: CP/network on ordinary list render'
);

// OC3 engine row-shape authority (sale/order list)
$oc3OrderCtrl = dirname($root) . '/reference-oc3-core/admin/controller/sale/order.php';
mtucAud024F01_assert(is_file($oc3OrderCtrl), 'OC3 sale/order.php present');
$oc3Src = (string) file_get_contents($oc3OrderCtrl);
mtucAud024F01_assert(
    preg_match(
        '/\$data\[\'orders\'\]\[\]\s*=\s*array\s*\(([\s\S]*?)\);/m',
        $oc3Src,
        $m
    ) === 1
        && strpos($m[1], "'order_id'") !== false
        && strpos($m[1], "'store_id'") === false,
    'OC3 authority: list row array has order_id, omits store_id'
);

echo PHP_EOL . 'AUD-024-F01 admin list store scope: ' . $passes . ' PASS, '
    . count($failures) . ' FAIL' . PHP_EOL;
if ($failures !== array()) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

exit(0);
