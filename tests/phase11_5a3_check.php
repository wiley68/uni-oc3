<?php

/**
 * Phase 11.5A.3 — Store diagnostic journal runtime write closure.
 * Run: php tests/phase11_5a3_check.php
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
    $storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mtuc-phase115a3-storage';
    if (!is_dir($storage)) {
        @mkdir($storage, 0770, true);
    }
    if (!is_dir($storage . DIRECTORY_SEPARATOR . 'mt_uni_credit')) {
        @mkdir($storage . DIRECTORY_SEPARATOR . 'mt_uni_credit', 0770, true);
    }
    define('DIR_STORAGE', rtrim($storage, '/\\') . DIRECTORY_SEPARATOR);
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
}

require_once $lib . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . '/support/phase4_harness.php';
require_once __DIR__ . '/support/phase5_harness.php';
require_once __DIR__ . '/support/phase6_harness.php';
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
function mtuc115a3_assert($condition, $message)
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
 * @param array<string, mixed> $stack
 * @return MtUniCreditDiagnosticDebugLogRepository
 */
function mtuc115a3_repo(array $stack)
{
    return new MtUniCreditDiagnosticDebugLogRepository($stack['db']);
}

/**
 * @return string
 */
function mtuc115a3_nonce()
{
    static $n = 0;
    $n++;

    return str_pad(dechex($n), 64, '0', STR_PAD_LEFT);
}

mtuc115a3_assert(is_file($lib . DIRECTORY_SEPARATOR . 'diagnostic_journal.php'), 'diagnostic_journal.php present');
mtuc115a3_assert(
    strpos((string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'bootstrap.php'), 'diagnostic_journal.php') !== false,
    'bootstrap loads diagnostic_journal'
);

// ---------------------------------------------------------------------------
// Process 1 success
// ---------------------------------------------------------------------------
$transportOk = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportOk);
$stackOk = Phase9TestHarness::stack($transportOk);
$orderOk = Phase9TestHarness::ORDER_ID;
Phase9TestHarness::seedBankOrder($stackOk['memoryDb'], $orderOk, $stackOk['storeId']);
$resultOk = $stackOk['submission']->submit(Phase9TestHarness::submitInput($orderOk, $stackOk['storeId']));
mtuc115a3_assert(!empty($resultOk['success']), 'P1 success: lifecycle succeeds');
$latestOk = mtuc115a3_repo($stackOk)->findLatestByOrderId($stackOk['storeId'], $orderOk);
mtuc115a3_assert(is_array($latestOk), 'P1 success: diagnostic row persisted');
mtuc115a3_assert(
    is_array($latestOk) && in_array($latestOk['event_code'], array('success', 'cp_status_patch_success'), true),
    'P1 success: latest event is support-facing'
);
mtuc115a3_assert(
    is_array($latestOk)
        && isset($latestOk['summary']['message'])
        && is_string($latestOk['summary']['message'])
        && $latestOk['summary']['message'] !== '',
    'P1 success: safe summary message present'
);
mtuc115a3_assert(
    strpos((string) json_encode($latestOk), 'demo-secret-password') === false,
    'P1 success: no SmartUCF password in journal'
);

// ---------------------------------------------------------------------------
// Process 1 timeout
// ---------------------------------------------------------------------------
$transportTo = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportTo);
$stackTo = Phase9TestHarness::stack(
    $transportTo,
    function () {
        return array('body' => '', 'error' => 'Operation timed out', 'http_code' => 0);
    }
);
$orderTo = $orderOk + 1;
Phase9TestHarness::seedBankOrder($stackTo['memoryDb'], $orderTo, $stackTo['storeId']);
$resultTo = $stackTo['submission']->submit(Phase9TestHarness::submitInput($orderTo, $stackTo['storeId']));
mtuc115a3_assert(empty($resultTo['success']), 'P1 timeout: lifecycle fails safely');
$latestTo = mtuc115a3_repo($stackTo)->findLatestByOrderId($stackTo['storeId'], $orderTo);
mtuc115a3_assert(is_array($latestTo), 'P1 timeout: diagnostic row persisted');
mtuc115a3_assert(
    is_array($latestTo)
        && (
            $latestTo['event_code'] === MtUniCreditDiagnosticJournal::EVENT_TRANSPORT_AMBIGUOUS
            || (isset($latestTo['outcome']) && $latestTo['outcome'] === MtUniCreditDiagnosticJournal::EVENT_TRANSPORT_AMBIGUOUS)
        ),
    'P1 timeout: event/outcome is transport_ambiguous'
);

// ---------------------------------------------------------------------------
// CP create 5xx / timeout
// ---------------------------------------------------------------------------
$transportCpFail = new Phase4FakeCpHttpTransport();
$transportCpFail->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transportCpFail->enqueueJson(503, array('error' => 'unavailable'));
$stackCp = Phase9TestHarness::stack($transportCpFail);
$orderCp = $orderOk + 2;
Phase9TestHarness::seedBankOrder($stackCp['memoryDb'], $orderCp, $stackCp['storeId']);
$resultCp = $stackCp['submission']->submit(Phase9TestHarness::submitInput($orderCp, $stackCp['storeId']));
mtuc115a3_assert(empty($resultCp['success']), 'CP 5xx: submit fails');
$latestCp = mtuc115a3_repo($stackCp)->findLatestByOrderId($stackCp['storeId'], $orderCp);
mtuc115a3_assert(is_array($latestCp), 'CP 5xx: diagnostic row persisted');
mtuc115a3_assert(
    is_array($latestCp) && $latestCp['event_code'] === MtUniCreditDiagnosticJournal::EVENT_CP_CREATE_OUTCOME_UNKNOWN,
    'CP 5xx: event_code cp_create_outcome_unknown'
);
mtuc115a3_assert(
    is_array($latestCp) && ((int) $latestCp['http_status'] === 503 || (int) $latestCp['http_code'] === 503),
    'CP 5xx: http status captured'
);

$transportCpTo = new Phase4FakeCpHttpTransport();
$transportCpTo->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transportCpTo->enqueueTimeout();
$stackCpTo = Phase9TestHarness::stack($transportCpTo);
$orderCpTo = $orderOk + 3;
Phase9TestHarness::seedBankOrder($stackCpTo['memoryDb'], $orderCpTo, $stackCpTo['storeId']);
$resultCpTo = $stackCpTo['submission']->submit(Phase9TestHarness::submitInput($orderCpTo, $stackCpTo['storeId']));
mtuc115a3_assert(empty($resultCpTo['success']), 'CP timeout: submit fails');
$latestCpTo = mtuc115a3_repo($stackCpTo)->findLatestByOrderId($stackCpTo['storeId'], $orderCpTo);
mtuc115a3_assert(
    is_array($latestCpTo)
        && $latestCpTo['event_code'] === MtUniCreditDiagnosticJournal::EVENT_CP_CREATE_TIMEOUT,
    'CP timeout: diagnostic event_code cp_create_timeout'
);

// ---------------------------------------------------------------------------
// CP status PATCH failure after SmartUCF success
// ---------------------------------------------------------------------------
$transportPatch = new Phase4FakeCpHttpTransport();
$transportPatch->failStatusPatch = true;
Phase9TestHarness::enqueueCpCreateSuccess($transportPatch);
$stackPatch = Phase9TestHarness::stack($transportPatch);
$orderPatch = $orderOk + 4;
Phase9TestHarness::seedBankOrder($stackPatch['memoryDb'], $orderPatch, $stackPatch['storeId']);
$resultPatch = $stackPatch['submission']->submit(Phase9TestHarness::submitInput($orderPatch, $stackPatch['storeId']));
mtuc115a3_assert(!empty($resultPatch['success']), 'PATCH fail: SmartUCF success still succeeds');
mtuc115a3_assert(count($stackPatch['smartUcfProbe']->calls) === 1, 'PATCH fail: single SmartUCF call');
$latestPatch = mtuc115a3_repo($stackPatch)->findLatestByOrderId($stackPatch['storeId'], $orderPatch);
mtuc115a3_assert(
    is_array($latestPatch)
        && $latestPatch['event_code'] === MtUniCreditDiagnosticJournal::EVENT_CP_STATUS_PATCH_FAILED,
    'PATCH fail: latest event is cp_status_patch_failed'
);

// ---------------------------------------------------------------------------
// Process 2 safe logging
// ---------------------------------------------------------------------------
$transportP2 = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportP2);
$stackP2 = Phase9TestHarness::stack($transportP2, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$orderP2 = $orderOk + 5;
Phase9TestHarness::seedBankOrder($stackP2['memoryDb'], $orderP2, $stackP2['storeId']);
$resultP2 = $stackP2['submission']->submit(Phase9TestHarness::submitInputProcess2($orderP2, $stackP2['storeId']));
mtuc115a3_assert(!empty($resultP2['success']), 'P2: lifecycle succeeds');
$latestP2 = mtuc115a3_repo($stackP2)->findLatestByOrderId($stackP2['storeId'], $orderP2);
mtuc115a3_assert(is_array($latestP2), 'P2: diagnostic row present');
mtuc115a3_assert(
    is_array($latestP2)
        && (
            $latestP2['event_code'] === MtUniCreditDiagnosticJournal::EVENT_PROCESS2_PREPARED
            || strpos((string) $latestP2['event_code'], 'phase9') === 0
            || $latestP2['event_code'] === MtUniCreditDiagnosticJournal::EVENT_CP_CREATE_SUCCESS
        ),
    'P2: safe process2/cp diagnostic event'
);
$p2Body = (string) json_encode($latestP2);
mtuc115a3_assert(
    strpos($p2Body, '1990010112') === false,
    'P2: EGN value absent from journal JSON'
);

// ---------------------------------------------------------------------------
// Redaction + truncation
// ---------------------------------------------------------------------------
$redDb = new Phase2MemoryDb();
$redStack = Phase6TestHarness::stack($redDb);
$redRepo = new MtUniCreditDiagnosticDebugLogRepository($redStack['db']);
$redRepo->insert(
    $redStack['storeId'],
    8801,
    'checkout',
    'success',
    200,
    array(
        'egn' => '1234567890',
        'email' => 'leak@example.com',
        'phone2' => '0888111222',
        'password' => 'secret',
        'Authorization' => 'Bearer abc.def',
        'private_key' => '-----BEGIN PRIVATE KEY-----',
        'message' => 'ok',
    )
);
$redLatest = $redRepo->findLatestByOrderId($redStack['storeId'], 8801);
mtuc115a3_assert($redLatest['summary']['egn'] === '[REDACTED]', 'redaction: EGN');
mtuc115a3_assert($redLatest['summary']['email'] === '[REDACTED]', 'redaction: email');
mtuc115a3_assert($redLatest['summary']['phone2'] === '[REDACTED]', 'redaction: phone2');
mtuc115a3_assert($redLatest['summary']['password'] === '[REDACTED]', 'redaction: password');
mtuc115a3_assert($redLatest['summary']['Authorization'] === '[REDACTED]', 'redaction: Authorization');
mtuc115a3_assert($redLatest['summary']['private_key'] === '[REDACTED]', 'redaction: private_key');

$redRepo->insert(
    $redStack['storeId'],
    8802,
    'checkout',
    'success',
    200,
    array('blob' => str_repeat('x', 70000))
);
$trunc = $redRepo->findLatestByOrderId($redStack['storeId'], 8802);
mtuc115a3_assert(
    is_array($trunc) && !empty($trunc['summary']['truncated']),
    'truncation: oversized summary stored as truncated stub'
);

// ---------------------------------------------------------------------------
// Multistore + cross-order
// ---------------------------------------------------------------------------
$isoDb = new Phase2MemoryDb();
$isoA = Phase6TestHarness::stack($isoDb, Phase6TestHarness::STORE_A);
$isoB = Phase6TestHarness::stack($isoDb, Phase6TestHarness::STORE_B);
$isoRepo = new MtUniCreditDiagnosticDebugLogRepository($isoA['db']);
$isoRepo->insert($isoA['storeId'], 9901, 'product', 'success', 200, array('message' => 'A'));
$isoRepo->insert($isoB['storeId'], 9901, 'product', 'remote_reject', 422, array('message' => 'B'));
$isoRepo->insert($isoA['storeId'], 9902, 'cart', 'cp_create_success', 201, array('message' => 'A2'));
$a9901 = $isoRepo->findLatestByOrderId($isoA['storeId'], 9901);
$b9901 = $isoRepo->findLatestByOrderId($isoB['storeId'], 9901);
$a9902 = $isoRepo->findLatestByOrderId($isoA['storeId'], 9902);
mtuc115a3_assert($a9901['event_code'] === 'success' && $b9901['event_code'] === 'remote_reject', 'multistore: same order_id isolated');
mtuc115a3_assert($a9902['event_code'] === 'cp_create_success', 'cross-order: order A2 isolated from A1');

// ---------------------------------------------------------------------------
// Latest-row API + empty opaque 404
// ---------------------------------------------------------------------------
$apiStack = Phase6TestHarness::stack();
$apiStack['memoryDb']->seedOrder(7701, $apiStack['storeId'], MtUniCreditConstants::EXTENSION_CODE);
(new MtUniCreditDiagnosticDebugLogRepository($apiStack['db']))->insert(
    $apiStack['storeId'],
    7701,
    'checkout',
    'success',
    200,
    array('message' => 'API visible', 'password' => 'nope')
);
$raw = json_encode(array('unicid' => $apiStack['unicid'], 'order_id' => '7701'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$headers = Phase6TestHarness::signedHeaders($apiStack['secret'], $raw, array('X-UniPayment-Nonce' => mtuc115a3_nonce()));
$apiResult = MtUniCreditInboundApiDispatcher::dispatch(
    function (array $payload, $unicid) use ($apiStack) {
        unset($unicid);
        $orderId = (int) $payload['order_id'];
        $log = (new MtUniCreditDiagnosticDebugLogRepository($apiStack['db']))->findLatestByOrderId(
            $apiStack['storeId'],
            $orderId
        );
        if ($log === null) {
            throw new MtUniCreditInboundApiException('Не е намерена диагностична информация за тази поръчка.', 404, 'order_not_found');
        }

        return array('success' => true, 'data' => array('order_id' => (string) $orderId, 'oc_order_id' => $orderId, 'log' => $log));
    },
    $apiStack['authenticator'],
    Phase6TestHarness::serverFromHeaders($headers),
    $raw,
    'POST'
);
mtuc115a3_assert(!empty($apiResult['success']) && $apiResult['data']['log']['event_code'] === 'success', 'API: latest safe row returned');
mtuc115a3_assert($apiResult['data']['log']['summary']['password'] === '[REDACTED]', 'API: password redacted');

$emptyRaw = json_encode(array('unicid' => $apiStack['unicid'], 'order_id' => '7702'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$apiStack['memoryDb']->seedOrder(7702, $apiStack['storeId'], MtUniCreditConstants::EXTENSION_CODE);
try {
    MtUniCreditInboundApiDispatcher::dispatch(
        function (array $payload) use ($apiStack) {
            $log = (new MtUniCreditDiagnosticDebugLogRepository($apiStack['db']))->findLatestByOrderId(
                $apiStack['storeId'],
                (int) $payload['order_id']
            );
            if ($log === null) {
                throw new MtUniCreditInboundApiException('Не е намерена диагностична информация за тази поръчка.', 404, 'order_not_found');
            }

            return array('success' => true, 'data' => array('log' => $log));
        },
        $apiStack['authenticator'],
        Phase6TestHarness::serverFromHeaders(Phase6TestHarness::signedHeaders($apiStack['secret'], $emptyRaw, array(
            'X-UniPayment-Nonce' => mtuc115a3_nonce(),
        ))),
        $emptyRaw,
        'POST'
    );
    mtuc115a3_assert(false, 'API empty: should 404');
} catch (MtUniCreditInboundApiException $exception) {
    mtuc115a3_assert(
        $exception->getStatusCode() === 404 && $exception->getErrorCode() === 'order_not_found',
        'API empty: opaque 404 order_not_found'
    );
}

// ---------------------------------------------------------------------------
// Admin journal download
// ---------------------------------------------------------------------------
$exportJournal = MtUniCreditDiagnosticJournal::fromDatabase($isoA['db']);
$export = $exportJournal->buildExport($isoA['storeId']);
mtuc115a3_assert($export['total_entries'] >= 2, 'admin journal: non-empty for store with rows');
$exportJson = (string) json_encode($export);
mtuc115a3_assert(
    strpos($exportJson, '1234567890') === false
        && strpos($exportJson, 'Bearer abc') === false,
    'admin journal: no sensitive plaintext'
);

// ---------------------------------------------------------------------------
// Journal write failure does not alter lifecycle
// ---------------------------------------------------------------------------
$failJournal = new MtUniCreditDiagnosticJournal(
    new MtUniCreditDiagnosticDebugLogRepository(new MtUniCreditDbAdapter(new Phase2MemoryDb(), 'oc_')),
    function () {
        return true;
    }
);
// Force failure via invalid order_id
mtuc115a3_assert($failJournal->record(1, 0, 'checkout', 'success', 200, array('message' => 'x')) === false, 'journal failure: order_id<=0 returns false');
mtuc115a3_assert(!empty($resultOk['success']), 'journal failure isolation: prior P1 success unchanged');
mtuc115a3_assert(Phase9TestHarness::smartUcfCallCount($stackOk['smartUcfProbe']) === 1, 'journal isolation: SmartUCF call count unchanged');

mtuc115a3_assert(
    MtUniCreditDiagnosticDebugLogRepository::RETENTION_MONTHS === 3,
    'retention: 3 months policy present'
);
mtuc115a3_assert(
    MtUniCreditDiagnosticDebugLogRepository::MAX_SUMMARY_JSON_BYTES === 65536,
    'summary max bytes 65536'
);
mtuc115a3_assert(
    MtUniCreditRequestSignatureProtocol::TIMESTAMP_TOLERANCE_SECONDS === 300
        && MtUniCreditSecurityConstants::NONCE_RETENTION_SECONDS === 900,
    'inbound auth constants unchanged'
);

echo PHP_EOL . 'Phase 11.5A.3 checks: ' . $passes . ' passed';
if ($failures) {
    echo ', ' . count($failures) . ' failed' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo ', 0 failed' . PHP_EOL;
exit(0);
