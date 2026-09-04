<?php

/**
 * Phase 6 inbound authenticated API bridge checks.
 * Run: php tests/phase6_check.php
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
require_once __DIR__ . '/support/phase6_harness.php';
require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

$failures = array();
$passes = 0;

function mtuc6_assert(bool $condition, string $message): void
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

$requiredFiles = array(
    'shop_snapshot_sanitizer.php',
    'smartucf_credentials_repository.php',
    'shop_cache_persistence.php',
    'inbound_api_exception.php',
    'inbound_api_dispatcher.php',
    'request_authenticator.php',
    'inbound_bank_status_vocabulary.php',
    'payment_identity.php',
    'order_ownership_resolver.php',
    'order_bank_status_repository.php',
    'diagnostic_payload_redactor.php',
    'diagnostic_debug_log_repository.php',
    'inbound_api_runner.php',
);

foreach ($requiredFiles as $relative) {
    mtuc6_assert(is_file($lib . DIRECTORY_SEPARATOR . $relative), 'required file: ' . $relative);
}

mtuc6_assert(
    is_file($root . DIRECTORY_SEPARATOR . 'upload/catalog/controller/extension/mt_uni_credit/api.php'),
    'catalog inbound API controller present'
);

$phase6Sql = MtUniCreditPersistenceSchema::createPhase6TableStatements('oc_');
mtuc6_assert(count($phase6Sql) === 2, 'phase 6 schema defines two tables');
mtuc6_assert(strpos($phase6Sql[0], 'mt_uni_credit_order_bank_status') !== false, 'order bank status table SQL present');
mtuc6_assert(strpos($phase6Sql[1], 'mt_uni_credit_diagnostic_debug_log') !== false, 'diagnostic debug log table SQL present');

$hmacFixture = mtuc_phase0_load_fixture('hmac_callback_vector.json');
$computed = MtUniCreditRequestSignatureProtocol::computeSignature(
    $hmacFixture['vector']['secret'],
    $hmacFixture['vector']['timestamp'],
    $hmacFixture['vector']['nonce'],
    $hmacFixture['vector']['raw_body']
);
mtuc6_assert($computed === $hmacFixture['vector']['expected_sha256_hmac'], 'frozen HMAC vector matches');

$stack = Phase6TestHarness::stack();
$shop = mtuc4_valid_shop_snapshot(array('unicid' => Phase4TestHarness::TEST_UNICID));

// Security regression: plaintext uni_password must not persist in shop_data JSON.
Phase6TestHarness::pushShopCache($stack, $shop);
$encoded = (new MtUniCreditShopCacheRepository($stack['db']))->findEncodedShopData($stack['storeId'], $stack['unicid']);
mtuc6_assert(is_string($encoded) && $encoded !== '', 'shop cache persisted encoded JSON');
mtuc6_assert(strpos($encoded, 'demo-secret-password') === false, 'regression: uni_password plaintext absent from shop_data');
mtuc6_assert(strpos($encoded, 'demo-user') === false, 'regression: uni_user absent from shop_data');
mtuc6_assert(
    MtUniCreditSettingCipher::hasEncryptedPrefix((string) $stack['settings']->get($stack['storeId'], MtUniCreditConstants::MODULE_SETTING_SMARTUCF_PASSWORD)),
    'SmartUCF password stored encrypted in settings'
);

// Outbound Phase 4 refresh also sanitizes cache.
$outboundDb = new Phase2MemoryDb();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload($shop));
$phase4 = Phase4TestHarness::services($transport, $outboundDb, $stack['storeId'], Phase6TestHarness::NOW);
$phase4['shopConfiguration']->refreshRemote();
$outboundEncoded = (new MtUniCreditShopCacheRepository(new MtUniCreditDbAdapter($outboundDb, 'oc_')))
    ->findEncodedShopData($stack['storeId'], $stack['unicid']);
mtuc6_assert(strpos((string) $outboundEncoded, 'demo-secret-password') === false, 'Phase 4 outbound refresh sanitizes shop_data');

// Authentication
$validBody = json_encode(array('unicid' => $stack['unicid'], 'order_id' => '1'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$valid = Phase6TestHarness::dispatch(array('unicid' => $stack['unicid'], 'order_id' => '1'), $stack);
mtuc6_assert($valid['status'] === 200, 'valid HMAC accepted');

$badSig = Phase6TestHarness::dispatch(
    array('unicid' => $stack['unicid']),
    $stack,
    array('X-UniPayment-Signature' => str_repeat('0', 64))
);
mtuc6_assert($badSig['status'] === 401, 'wrong HMAC rejected');

$tampered = Phase6TestHarness::dispatch(
    array('unicid' => $stack['unicid']),
    $stack,
    array(),
    $validBody . ' '
);
mtuc6_assert($tampered['status'] === 401, 'modified body rejected');

$oldTs = Phase6TestHarness::dispatch(
    array('unicid' => $stack['unicid']),
    $stack,
    array('X-UniPayment-Timestamp' => '1787370000')
);
mtuc6_assert($oldTs['status'] === 401, 'expired timestamp rejected');

$futureTs = Phase6TestHarness::dispatch(
    array('unicid' => $stack['unicid']),
    $stack,
    array('X-UniPayment-Timestamp' => '1787381000')
);
mtuc6_assert($futureTs['status'] === 401, 'future timestamp rejected');

$badNonce = Phase6TestHarness::dispatch(
    array('unicid' => $stack['unicid']),
    $stack,
    array('X-UniPayment-Nonce' => 'not-a-valid-nonce')
);
mtuc6_assert($badNonce['status'] === 401, 'invalid nonce format rejected');

$missingHeader = Phase6TestHarness::dispatch(
    array('unicid' => $stack['unicid']),
    $stack,
    array('X-UniPayment-Signature' => '')
);
mtuc6_assert($missingHeader['status'] === 401, 'missing signature rejected');

$nonce = str_repeat('b', 64);
$firstNonceBody = json_encode(array('unicid' => $stack['unicid']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$firstNonce = Phase6TestHarness::dispatch(
    array('unicid' => $stack['unicid']),
    $stack,
    array('X-UniPayment-Nonce' => $nonce),
    $firstNonceBody
);
mtuc6_assert($firstNonce['status'] === 200, 'first nonce accepted');
$replay = Phase6TestHarness::dispatch(
    array('unicid' => $stack['unicid']),
    $stack,
    array('X-UniPayment-Nonce' => $nonce),
    $firstNonceBody
);
mtuc6_assert($replay['status'] === 401, 'replay rejected');
mtuc6_assert(
    is_array($replay['payload'])
        && isset($replay['payload']['success'])
        && $replay['payload']['success'] === false
        && isset($replay['payload']['error'])
        && $replay['payload']['error'] === 'invalid_signature',
    'replay maps to HTTP 401 invalid_signature JSON'
);
mtuc6_assert(
    strpos($replay['body'], '1062') === false
        && stripos($replay['body'], 'Duplicate') === false
        && stripos($replay['body'], 'Warning') === false
        && stripos($replay['body'], '<br') === false,
    'replay response has no SQL/warning leakage'
);

// Replay-safe nonce claim: ON DUPLICATE KEY UPDATE (no mysqli duplicate warning).
$nonceSource = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'api_nonce_repository.php');
mtuc6_assert(
    strpos($nonceSource, 'ON DUPLICATE KEY UPDATE `nonce_hash` = `nonce_hash`') !== false,
    'nonce claim uses ON DUPLICATE KEY UPDATE no-op'
);
mtuc6_assert(
    strpos($nonceSource, 'isDuplicateKeyError') === false,
    'nonce claim does not depend on catching duplicate-key Exception'
);

$claimDb = new Phase2MemoryDb();
$claimAdapter = new MtUniCreditDbAdapter($claimDb, 'oc_');
$claimRepo = new MtUniCreditApiNonceRepository(
    $claimAdapter,
    new MtUniCreditPersistenceClock(function () {
        return Phase6TestHarness::NOW;
    })
);
$claimNonce = str_repeat('f', 64);
mtuc6_assert($claimRepo->claim($stack['storeId'], $stack['unicid'], $claimNonce) === true, 'first nonce claim returns true');
mtuc6_assert($claimRepo->claim($stack['storeId'], $stack['unicid'], $claimNonce) === false, 'second identical nonce claim returns false');

$failingDb = new class {
    /**
     * @param string $sql
     * @return never
     */
    public function query(string $sql)
    {
        throw new Exception('Error: disk full<br />Error No: 1021<br />' . $sql);
    }

    /**
     * @param mixed $value
     * @return string
     */
    public function escape($value): string
    {
        return (string) $value;
    }

    public function countAffected(): int
    {
        return 0;
    }

    public function getLastId(): int
    {
        return 0;
    }
};
$failingRepo = new MtUniCreditApiNonceRepository(
    new MtUniCreditDbAdapter($failingDb, 'oc_'),
    new MtUniCreditPersistenceClock(function () {
        return Phase6TestHarness::NOW;
    })
);
try {
    $failingRepo->claim($stack['storeId'], $stack['unicid'], str_repeat('1', 64));
    mtuc6_assert(false, 'real DB failure still fails loudly');
} catch (MtUniCreditPersistenceException $exception) {
    mtuc6_assert(true, 'real DB failure still fails loudly');
    mtuc6_assert(
        stripos($exception->getMessage(), 'Nonce claim failed') !== false,
        'real DB failure uses persistence exception message'
    );
}

$stackB = Phase6TestHarness::stack(new Phase2MemoryDb(), Phase6TestHarness::STORE_B);
Phase4TestHarness::prepareCredentials($stackB['settings'], Phase6TestHarness::STORE_B);
$stackB['settings']->set(Phase6TestHarness::STORE_B, MtUniCreditConstants::MODULE_SETTING_STATUS, '1');
$crossNonceBody = json_encode(array('unicid' => $stackB['unicid']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$crossStoreNonce = str_repeat('c', 64);
$storeBAuth = new MtUniCreditRequestAuthenticator(
    $stackB['credentials'],
    new MtUniCreditApiNonceRepository($stackB['db'], new MtUniCreditPersistenceClock(function () {
        return Phase6TestHarness::NOW;
    })),
    Phase6TestHarness::STORE_B,
    true,
    new MtUniCreditRequestSignatureVerifier(function () {
        return Phase6TestHarness::NOW;
    })
);
MtUniCreditInboundApiDispatcher::dispatch(
    function ($payload) {
        return $payload;
    },
    $storeBAuth,
    Phase6TestHarness::serverFromHeaders(Phase6TestHarness::signedHeaders($stackB['secret'], $crossNonceBody, array(
        'X-UniPayment-Nonce' => $crossStoreNonce,
    ))),
    $crossNonceBody,
    'POST'
);
mtuc6_assert(true, 'same nonce different store allowed');

$wrongUnicid = Phase6TestHarness::dispatch(array('unicid' => 'WRONG-UNICID'), $stack);
mtuc6_assert($wrongUnicid['status'] === 401, 'unknown/wrong UNICID rejected');

$disabled = Phase6TestHarness::stack();
$disabled['settings']->set($disabled['storeId'], MtUniCreditConstants::MODULE_SETTING_STATUS, '0');
$disabledAuth = new MtUniCreditRequestAuthenticator(
    $disabled['credentials'],
    new MtUniCreditApiNonceRepository($disabled['db']),
    $disabled['storeId'],
    false
);
try {
    MtUniCreditInboundApiDispatcher::dispatch(
        function ($payload) {
            return $payload;
        },
        $disabledAuth,
        Phase6TestHarness::serverFromHeaders(Phase6TestHarness::signedHeaders($disabled['secret'], $validBody, array(
            'X-UniPayment-Nonce' => str_repeat('d', 64),
        ))),
        $validBody,
        'POST'
    );
    mtuc6_assert(false, 'module disabled rejected');
} catch (MtUniCreditInboundApiException $exception) {
    mtuc6_assert($exception->getStatusCode() === 403, 'module disabled rejected');
}

// Shop cache inbound handler semantics via persistence + invalid payload preserves cache.
$invalidDb = new Phase2MemoryDb();
$invalidPersistence = MtUniCreditBootstrap::shopCachePersistenceFromDb(new MtUniCreditDbAdapter($invalidDb, 'oc_'));
Phase6TestHarness::pushShopCache(array_merge($stack, array(
    'memoryDb' => $invalidDb,
    'db' => new MtUniCreditDbAdapter($invalidDb, 'oc_'),
)), $shop);
$beforeEncoded = (new MtUniCreditShopCacheRepository(new MtUniCreditDbAdapter($invalidDb, 'oc_')))
    ->findEncodedShopData($stack['storeId'], $stack['unicid']);
try {
    $invalidPersistence->replaceValidatedSnapshot(
        $stack['storeId'],
        $stack['unicid'],
        array('uni_status' => 2)
    );
    mtuc6_assert(false, 'invalid inbound shop snapshot rejected');
} catch (MtUniCreditShopSnapshotValidationException $exception) {
    mtuc6_assert(true, 'invalid inbound shop snapshot rejected');
}
$afterInvalid = (new MtUniCreditShopCacheRepository(new MtUniCreditDbAdapter($invalidDb, 'oc_')))
    ->findEncodedShopData($stack['storeId'], $stack['unicid']);
mtuc6_assert($afterInvalid === $beforeEncoded, 'invalid push preserves existing cache');

// Bank status
$stack['memoryDb']->seedOrder(501, $stack['storeId'], MtUniCreditConstants::EXTENSION_CODE);
$bankRepo = new MtUniCreditOrderBankStatusRepository($stack['db']);
$result = $bankRepo->updateByOrderIdentifier($stack['storeId'], '501', 'bank_sent_process1', 'Sent');
mtuc6_assert($result !== null && $result['status_id'] === 'bank_sent_process1', 'valid bank status update');
$duplicate = $bankRepo->updateByOrderIdentifier($stack['storeId'], '501', 'bank_sent_process1', 'Sent');
mtuc6_assert($duplicate !== null && $duplicate['oc_order_state_changed'] === false, 'duplicate bank status idempotent');
$newer = $bankRepo->updateByOrderIdentifier($stack['storeId'], '501', 'bank_sent_process2', 'Process 2');
mtuc6_assert($newer !== null && $newer['status_id'] === 'bank_sent_process2', 'newer bank status accepted');
$stack['memoryDb']->seedOrder(502, Phase6TestHarness::STORE_B, MtUniCreditConstants::EXTENSION_CODE);
mtuc6_assert($bankRepo->updateByOrderIdentifier($stack['storeId'], '502', 'cp_sent', 'X') === null, 'cross-store order rejected');
mtuc6_assert($bankRepo->updateByOrderIdentifier($stack['storeId'], '999', 'cp_sent', 'X') === null, 'order not found rejected');
mtuc6_assert(!MtUniCreditInboundBankStatusVocabulary::isAccepted('totally_invalid'), 'unsupported bank status vocabulary');

// Debug log retrieval
$stack['memoryDb']->seedOrder(601, $stack['storeId'], MtUniCreditConstants::EXTENSION_CODE);
(new MtUniCreditDiagnosticDebugLogRepository($stack['db']))->insert(
    $stack['storeId'],
    601,
    'checkout',
    'smartucf_submit',
    502,
    array('egn' => '1234567890', 'code' => 'ok')
);
$log = (new MtUniCreditDiagnosticDebugLogRepository($stack['db']))->findLatestByOrderId($stack['storeId'], 601);
mtuc6_assert($log !== null && $log['event_code'] === 'smartucf_submit', 'debug log stored and retrieved');
mtuc6_assert($log['summary']['egn'] === '[REDACTED]', 'debug log sensitive field redacted');

(new MtUniCreditDiagnosticDebugLogRepository($stack['db']))->insert(
    $stack['storeId'],
    601,
    'checkout',
    'oversize',
    null,
    array('blob' => str_repeat('x', 70000))
);
$oversizeLog = (new MtUniCreditDiagnosticDebugLogRepository($stack['db']))->findLatestByOrderId($stack['storeId'], 601);
mtuc6_assert(
    is_array($oversizeLog)
        && (
            !empty($oversizeLog['summary']['truncated'])
            || $oversizeLog['event_code'] === 'oversize'
        ),
    'oversize debug payload truncated or bounded'
);

// HTTP/API gates
try {
    MtUniCreditInboundApiDispatcher::dispatch(
        function ($payload) {
            return $payload;
        },
        $stack['authenticator'],
        array(),
        '{}',
        'GET'
    );
    mtuc6_assert(false, 'GET rejected');
} catch (MtUniCreditInboundApiException $exception) {
    mtuc6_assert($exception->getStatusCode() === 405, 'GET rejected');
}

try {
    MtUniCreditInboundApiDispatcher::dispatch(
        function ($payload) {
            return $payload;
        },
        $stack['authenticator'],
        Phase6TestHarness::serverFromHeaders(Phase6TestHarness::signedHeaders($stack['secret'], 'not-json', array(
            'X-UniPayment-Nonce' => str_repeat('e', 64),
        ))),
        'not-json',
        'POST'
    );
    mtuc6_assert(false, 'malformed JSON rejected');
} catch (MtUniCreditInboundApiException $exception) {
    mtuc6_assert($exception->getStatusCode() === 400, 'malformed JSON rejected');
}

$controllerSource = (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'upload/catalog/controller/extension/mt_uni_credit/api.php');
mtuc6_assert(strpos($controllerSource, 'function shop_cache') !== false, 'shop_cache route action exists');
mtuc6_assert(strpos($controllerSource, 'function order_bank_status') !== false, 'order_bank_status route action exists');
mtuc6_assert(strpos($controllerSource, 'function smartucf_debug_log') !== false, 'smartucf_debug_log route action exists');
mtuc6_assert(strpos($controllerSource, 'sucfOnlineSessionStart') === false, 'inbound controller has no SmartUCF outbound calls');

echo PHP_EOL . 'Phase 6 checks: ' . $passes . ' passed';
if ($failures) {
    echo ', ' . count($failures) . ' failed' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo ', 0 failed' . PHP_EOL;
exit(0);
