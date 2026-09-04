<?php

/**
 * Phase 11.5A.1 — CP → Store inbound contract closure (store-side).
 * Run: php tests/phase11_5a_check.php
 *
 * Covers shop_cache, smartucf_debug_log, order_bank_status end-to-end
 * through the shared dispatcher + controller-equivalent handlers.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/support/phase2_memory_db.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

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

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtuc115a_assert($condition, $message)
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
 * @return string
 */
function mtuc115a_nonce()
{
    static $n = 0;
    $n++;

    return str_pad(dechex($n), 64, '0', STR_PAD_LEFT);
}

/**
 * Invoke a controller-equivalent inbound handler through auth + dispatcher.
 *
 * @param string $endpoint shop_cache|order_bank_status|smartucf_debug_log
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $stack
 * @param array<string, string> $headerOverrides
 * @param string|null $method
 * @param string|null $rawBodyOverride
 * @return array{status: int, body: string, payload: array<string, mixed>|null}
 */
function mtuc115a_invoke($endpoint, array $payload, array $stack, array $headerOverrides = array(), $method = 'POST', $rawBodyOverride = null)
{
    $rawBody = $rawBodyOverride !== null
        ? $rawBodyOverride
        : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($rawBody === false) {
        $rawBody = '';
    }

    $headers = Phase6TestHarness::signedHeaders($stack['secret'], $rawBody, $headerOverrides);
    $server = Phase6TestHarness::serverFromHeaders($headers);
    $server['REQUEST_METHOD'] = $method;

    $storeId = (int) $stack['storeId'];
    $db = $stack['db'];

    $handler = null;
    if ($endpoint === 'shop_cache') {
        $handler = function (array $body, $unicid) use ($storeId, $db) {
            $data = isset($body['data']) ? $body['data'] : null;
            if (!is_array($data) || $data === array()) {
                throw new MtUniCreditInboundApiException(
                    'Полето data трябва да съдържа пълна конфигурация на магазина.',
                    400,
                    'invalid_payload'
                );
            }
            if (isset($data['unicid']) && (!is_string($data['unicid']) || !hash_equals($unicid, $data['unicid']))) {
                throw new MtUniCreditInboundApiException(
                    'UNICID в конфигурацията не съвпада с този на магазина.',
                    400,
                    'invalid_payload'
                );
            }
            $persistence = MtUniCreditBootstrap::shopCachePersistenceFromDb($db);
            $persistence->replaceValidatedSnapshot($storeId, $unicid, $data);
            $cache = new MtUniCreditShopCacheRepository($db);

            return array(
                'success' => true,
                'message' => 'Кешът на shop данни е обновен успешно.',
                'data' => $cache->findMetadata($storeId, $unicid),
            );
        };
    } elseif ($endpoint === 'order_bank_status') {
        $handler = function (array $body, $unicid) use ($storeId, $db) {
            unset($unicid);
            $orderId = isset($body['order_id']) ? $body['order_id'] : null;
            if (!is_string($orderId) && !is_int($orderId)) {
                throw new MtUniCreditInboundApiException('Полето order_id е задължително.', 400, 'invalid_payload');
            }
            $orderId = trim((string) $orderId);
            if ($orderId === '' || strlen($orderId) > 64) {
                throw new MtUniCreditInboundApiException('Полето order_id е невалидно.', 400, 'invalid_payload');
            }
            $statusId = isset($body['status_id']) ? $body['status_id'] : null;
            if (!is_string($statusId) && !is_int($statusId)) {
                throw new MtUniCreditInboundApiException('Полето status_id е задължително.', 400, 'invalid_payload');
            }
            $statusId = trim((string) $statusId);
            if ($statusId === '' || strlen($statusId) > 255) {
                throw new MtUniCreditInboundApiException('Полето status_id е невалидно.', 400, 'invalid_payload');
            }
            if (!MtUniCreditInboundBankStatusVocabulary::isAccepted($statusId)) {
                throw new MtUniCreditInboundApiException('Неподдържан банков статус.', 400, 'unsupported_status');
            }
            $status = isset($body['status']) ? $body['status'] : '';
            if (!is_string($status) || strlen($status) > 255) {
                throw new MtUniCreditInboundApiException('Полето status е невалидно.', 400, 'invalid_payload');
            }
            $status = trim($status);
            $result = (new MtUniCreditOrderBankStatusRepository($db))->updateByOrderIdentifier(
                $storeId,
                $orderId,
                $statusId,
                $status
            );
            if ($result === null) {
                throw new MtUniCreditInboundApiException('Поръчката не е намерена в магазина.', 404, 'order_not_found');
            }

            return array(
                'success' => true,
                'message' => 'Банковият статус е обновен успешно.',
                'data' => $result,
            );
        };
    } elseif ($endpoint === 'smartucf_debug_log') {
        $handler = function (array $body, $unicid) use ($storeId, $db) {
            unset($unicid);
            $orderIdRaw = isset($body['order_id']) ? $body['order_id'] : null;
            if (!is_string($orderIdRaw) && !is_int($orderIdRaw)) {
                throw new MtUniCreditInboundApiException('Полето order_id е задължително.', 400, 'invalid_payload');
            }
            $orderIdRaw = trim((string) $orderIdRaw);
            if ($orderIdRaw === '' || strlen($orderIdRaw) > 64 || !ctype_digit($orderIdRaw)) {
                throw new MtUniCreditInboundApiException('Полето order_id е невалидно.', 400, 'invalid_payload');
            }
            $orderId = (int) $orderIdRaw;
            $ownership = new MtUniCreditOrderOwnershipResolver($db);
            if ($ownership->resolveAuthorizedOrderId($storeId, $orderIdRaw) === null) {
                throw new MtUniCreditInboundApiException(
                    'Не е намерена диагностична информация за тази поръчка.',
                    404,
                    'order_not_found'
                );
            }
            $log = (new MtUniCreditDiagnosticDebugLogRepository($db))->findLatestSmartUcfSessionByOrderId($storeId, $orderId);
            if ($log === null) {
                throw new MtUniCreditInboundApiException(
                    'Не е намерена диагностична информация за тази поръчка.',
                    404,
                    'order_not_found'
                );
            }

            return array(
                'success' => true,
                'data' => array(
                    'order_id' => $orderIdRaw,
                    'oc_order_id' => $orderId,
                    'log' => $log,
                ),
            );
        };
    } else {
        throw new InvalidArgumentException('Unknown endpoint: ' . $endpoint);
    }

    try {
        $result = MtUniCreditInboundApiDispatcher::dispatch(
            $handler,
            $stack['authenticator'],
            $server,
            $rawBody,
            $method
        );
        $encoded = MtUniCreditInboundApiDispatcher::encodeResponse($result, 200);
    } catch (MtUniCreditInboundApiException $exception) {
        $encoded = MtUniCreditInboundApiDispatcher::encodeException($exception);
    } catch (MtUniCreditShopSnapshotValidationException $exception) {
        $encoded = MtUniCreditInboundApiDispatcher::encodeResponse(array(
            'success' => false,
            'message' => $exception->getMessage(),
            'error' => $exception->errorCode(),
            'data' => array('violations' => $exception->violations()),
        ), 422);
    } catch (Exception $exception) {
        $encoded = MtUniCreditInboundApiDispatcher::encodeResponse(array(
            'success' => false,
            'message' => 'Модулът не можа да обработи заявката.',
        ), 500);
    }

    $decoded = json_decode($encoded['body'], true);

    return array(
        'status' => (int) $encoded['status'],
        'body' => (string) $encoded['body'],
        'payload' => is_array($decoded) ? $decoded : null,
    );
}

/**
 * @param string $endpoint
 * @param array<string, mixed> $stack
 * @return void
 */
function mtuc115a_assert_auth_matrix($endpoint, array $stack)
{
    $base = array('unicid' => $stack['unicid']);
    if ($endpoint === 'shop_cache') {
        $base['data'] = mtuc4_valid_shop_snapshot(array('unicid' => $stack['unicid']));
    } elseif ($endpoint === 'order_bank_status') {
        $base['order_id'] = '1';
        $base['status_id'] = 'cp_sent';
        $base['status'] = 'CP';
    } else {
        $base['order_id'] = '1';
    }

    $ok = mtuc115a_invoke($endpoint, $base, $stack, array('X-UniPayment-Nonce' => mtuc115a_nonce()));
    // Auth may succeed while business layer returns 404 for missing order — still proves HMAC accepted.
    mtuc115a_assert(
        $ok['status'] !== 401 && $ok['status'] !== 403 && $ok['status'] !== 405,
        $endpoint . ' auth: valid signed request accepted by security layer'
    );

    $bad = mtuc115a_invoke($endpoint, $base, $stack, array(
        'X-UniPayment-Nonce' => mtuc115a_nonce(),
        'X-UniPayment-Signature' => str_repeat('0', 64),
    ));
    mtuc115a_assert($bad['status'] === 401, $endpoint . ' auth: wrong HMAC → 401');

    $expired = mtuc115a_invoke($endpoint, $base, $stack, array(
        'X-UniPayment-Nonce' => mtuc115a_nonce(),
        'X-UniPayment-Timestamp' => (string) (Phase6TestHarness::NOW - 301),
    ));
    mtuc115a_assert($expired['status'] === 401, $endpoint . ' auth: expired timestamp → 401');

    $future = mtuc115a_invoke($endpoint, $base, $stack, array(
        'X-UniPayment-Nonce' => mtuc115a_nonce(),
        'X-UniPayment-Timestamp' => (string) (Phase6TestHarness::NOW + 301),
    ));
    mtuc115a_assert($future['status'] === 401, $endpoint . ' auth: future timestamp → 401');

    $badNonce = mtuc115a_invoke($endpoint, $base, $stack, array(
        'X-UniPayment-Nonce' => 'not-hex-nonce',
    ));
    mtuc115a_assert($badNonce['status'] === 401, $endpoint . ' auth: malformed nonce → 401');

    $nonce = mtuc115a_nonce();
    $first = mtuc115a_invoke($endpoint, $base, $stack, array('X-UniPayment-Nonce' => $nonce));
    $replay = mtuc115a_invoke($endpoint, $base, $stack, array('X-UniPayment-Nonce' => $nonce));
    mtuc115a_assert($first['status'] !== 401, $endpoint . ' auth: first nonce accepted');
    mtuc115a_assert($replay['status'] === 401, $endpoint . ' auth: replayed nonce → 401');

    $malformed = mtuc115a_invoke($endpoint, $base, $stack, array(
        'X-UniPayment-Nonce' => mtuc115a_nonce(),
    ), 'POST', '{not-json');
    mtuc115a_assert($malformed['status'] === 400, $endpoint . ' auth: malformed JSON → 400');

    $get = mtuc115a_invoke($endpoint, $base, $stack, array(
        'X-UniPayment-Nonce' => mtuc115a_nonce(),
    ), 'GET');
    mtuc115a_assert($get['status'] === 405, $endpoint . ' auth: wrong method → 405');

    $wrongStore = mtuc115a_invoke($endpoint, array_merge($base, array('unicid' => 'WRONG-UNICID')), $stack, array(
        'X-UniPayment-Nonce' => mtuc115a_nonce(),
    ));
    mtuc115a_assert($wrongStore['status'] === 401, $endpoint . ' auth: wrong store/unicid → 401');
}

// ---------------------------------------------------------------------------
// Contract constants
// ---------------------------------------------------------------------------
mtuc115a_assert(
    MtUniCreditRequestSignatureProtocol::TIMESTAMP_TOLERANCE_SECONDS === 300,
    'timestamp tolerance ±300s'
);
mtuc115a_assert(
    MtUniCreditRequestSignatureProtocol::NONCE_HEX_LENGTH === 64,
    'nonce length 64 hex'
);
mtuc115a_assert(
    MtUniCreditSecurityConstants::NONCE_RETENTION_SECONDS === 900,
    'nonce retention 900s'
);
mtuc115a_assert(
    MtUniCreditSecurityConstants::SHOP_CACHE_TTL_SECONDS === 86400,
    'shop cache TTL 86400s'
);

$controllerPath = $root . DIRECTORY_SEPARATOR . 'upload/catalog/controller/extension/mt_uni_credit/api.php';
$controllerSource = (string) file_get_contents($controllerPath);
mtuc115a_assert(strpos($controllerSource, 'function shop_cache') !== false, 'route shop_cache present');
mtuc115a_assert(strpos($controllerSource, 'function order_bank_status') !== false, 'route order_bank_status present');
mtuc115a_assert(strpos($controllerSource, 'function smartucf_debug_log') !== false, 'route smartucf_debug_log present');
mtuc115a_assert(strpos($controllerSource, 'isset($this->db)') === false, 'api controller avoids isset($this->db)');
mtuc115a_assert(strpos($controllerSource, 'isset($this->config)') === false, 'api controller avoids isset($this->config)');
mtuc115a_assert(strpos($controllerSource, 'addOrderHistory') === false, 'inbound API never calls addOrderHistory');
mtuc115a_assert(strpos($controllerSource, 'sucfOnlineSessionStart') === false, 'inbound API never calls SmartUCF');

$bankRepoSource = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'order_bank_status_repository.php');
mtuc115a_assert(strpos($bankRepoSource, 'last-write wins') !== false, 'bank status docs last-write-wins parity');

// ---------------------------------------------------------------------------
// A — shop_cache
// ---------------------------------------------------------------------------
$stackA = Phase6TestHarness::stack();
$shop = mtuc4_valid_shop_snapshot(array(
    'unicid' => $stackA['unicid'],
    'uni_minstojnost' => 150,
    'uni_proces' => 0,
));

$push1 = mtuc115a_invoke('shop_cache', array(
    'unicid' => $stackA['unicid'],
    'data' => $shop,
), $stackA, array('X-UniPayment-Nonce' => mtuc115a_nonce()));
mtuc115a_assert($push1['status'] === 200 && !empty($push1['payload']['success']), 'shop_cache: valid update → 200');
mtuc115a_assert(
    is_array($push1['payload']['data'])
        && !empty($push1['payload']['data']['is_fresh'])
        && isset($push1['payload']['data']['fetched_at']),
    'shop_cache: response includes fresh metadata'
);

$encoded = (new MtUniCreditShopCacheRepository($stackA['db']))->findEncodedShopData($stackA['storeId'], $stackA['unicid']);
mtuc115a_assert(is_string($encoded) && strpos($encoded, 'demo-secret-password') === false, 'shop_cache: sensitive password absent from cache JSON');
mtuc115a_assert(strpos((string) $encoded, 'demo-user') === false, 'shop_cache: sensitive uni_user absent from cache JSON');
mtuc115a_assert(
    MtUniCreditSettingCipher::hasEncryptedPrefix(
        (string) $stackA['settings']->get($stackA['storeId'], MtUniCreditConstants::MODULE_SETTING_SMARTUCF_PASSWORD)
    ),
    'shop_cache: SmartUCF password encrypted in settings'
);

$latest = (new MtUniCreditShopCacheRepository($stackA['db']))->findLatest($stackA['storeId'], $stackA['unicid']);
mtuc115a_assert(
    is_array($latest)
        && (float) $latest['shop_data']['uni_minstojnost'] === 150.0
        && !isset($latest['shop_data']['uni_password']),
    'shop_cache: consumers see new non-sensitive config immediately'
);

$creds = MtUniCreditBootstrap::smartucfCredentialsRepositoryFromDb($stackA['db']);
$hydrated = MtUniCreditShopProcessContext::hydrateSmartUcfCredentials($latest['shop_data'], $stackA['storeId'], $creds);
mtuc115a_assert(
    isset($hydrated['uni_user'], $hydrated['uni_password'])
        && $hydrated['uni_user'] === 'demo-user'
        && $hydrated['uni_password'] === 'demo-secret-password',
    'shop_cache: read path reconstructs SmartUCF credentials'
);
mtuc115a_assert(
    MtUniCreditShopProcessContext::normalized($latest['shop_data']) === MtUniCreditShopProcessContext::PROCESS_1,
    'shop_cache: process selection reflects pushed uni_proces'
);

$shopSame = $shop;
$push2 = mtuc115a_invoke('shop_cache', array(
    'unicid' => $stackA['unicid'],
    'data' => $shopSame,
), $stackA, array('X-UniPayment-Nonce' => mtuc115a_nonce()));
mtuc115a_assert($push2['status'] === 200, 'shop_cache: idempotent business snapshot → 200');

$beforeBad = (new MtUniCreditShopCacheRepository($stackA['db']))->findEncodedShopData($stackA['storeId'], $stackA['unicid']);
$badPush = mtuc115a_invoke('shop_cache', array(
    'unicid' => $stackA['unicid'],
    'data' => array('uni_status' => 2),
), $stackA, array('X-UniPayment-Nonce' => mtuc115a_nonce()));
mtuc115a_assert($badPush['status'] === 422, 'shop_cache: malformed payload → 422');
$afterBad = (new MtUniCreditShopCacheRepository($stackA['db']))->findEncodedShopData($stackA['storeId'], $stackA['unicid']);
mtuc115a_assert($afterBad === $beforeBad, 'shop_cache: malformed push atomic — cache unchanged');

$emptyData = mtuc115a_invoke('shop_cache', array(
    'unicid' => $stackA['unicid'],
    'data' => array(),
), $stackA, array('X-UniPayment-Nonce' => mtuc115a_nonce()));
mtuc115a_assert($emptyData['status'] === 400, 'shop_cache: empty data → 400');

// Full snapshot replacement: field disappearance
$shopTrimmed = $shop;
unset($shopTrimmed['uni_email']);
$shopTrimmed['uni_minstojnost'] = 200;
$pushTrim = mtuc115a_invoke('shop_cache', array(
    'unicid' => $stackA['unicid'],
    'data' => $shopTrimmed,
), $stackA, array('X-UniPayment-Nonce' => mtuc115a_nonce()));
mtuc115a_assert($pushTrim['status'] === 200, 'shop_cache: full snapshot replace accepted');
$afterTrim = (new MtUniCreditShopCacheRepository($stackA['db']))->findLatest($stackA['storeId'], $stackA['unicid']);
mtuc115a_assert(
    is_array($afterTrim)
        && !isset($afterTrim['shop_data']['uni_email'])
        && (float) $afterTrim['shop_data']['uni_minstojnost'] === 200.0,
    'shop_cache: full snapshot removes omitted CP-owned fields'
);
mtuc115a_assert(
    $stackA['credentials']->getUnicid($stackA['storeId']) === $stackA['unicid'],
    'shop_cache: local-only UNICID credential not erased by push'
);

// Multistore isolation for shop_cache
$sharedDb = new Phase2MemoryDb();
$stackStoreA = Phase6TestHarness::stack($sharedDb, Phase6TestHarness::STORE_A);
$stackStoreB = Phase6TestHarness::stack($sharedDb, Phase6TestHarness::STORE_B);
$shopA = mtuc4_valid_shop_snapshot(array('unicid' => $stackStoreA['unicid'], 'uni_minstojnost' => 111));
$shopB = mtuc4_valid_shop_snapshot(array('unicid' => $stackStoreB['unicid'], 'uni_minstojnost' => 222));
mtuc115a_invoke('shop_cache', array('unicid' => $stackStoreA['unicid'], 'data' => $shopA), $stackStoreA, array(
    'X-UniPayment-Nonce' => mtuc115a_nonce(),
));
mtuc115a_invoke('shop_cache', array('unicid' => $stackStoreB['unicid'], 'data' => $shopB), $stackStoreB, array(
    'X-UniPayment-Nonce' => mtuc115a_nonce(),
));
$rowA = (new MtUniCreditShopCacheRepository($stackStoreA['db']))->findLatest($stackStoreA['storeId'], $stackStoreA['unicid']);
$rowB = (new MtUniCreditShopCacheRepository($stackStoreB['db']))->findLatest($stackStoreB['storeId'], $stackStoreB['unicid']);
mtuc115a_assert(
    (float) $rowA['shop_data']['uni_minstojnost'] === 111.0
        && (float) $rowB['shop_data']['uni_minstojnost'] === 222.0,
    'shop_cache: multistore caches isolated'
);

mtuc115a_assert_auth_matrix('shop_cache', Phase6TestHarness::stack());

// ---------------------------------------------------------------------------
// B — smartucf_debug_log
// ---------------------------------------------------------------------------
$stackDbg = Phase6TestHarness::stack();
$stackDbg['memoryDb']->seedOrder(701, $stackDbg['storeId'], MtUniCreditConstants::EXTENSION_CODE);
(new MtUniCreditDiagnosticDebugLogRepository($stackDbg['db']))->insert(
    $stackDbg['storeId'],
    701,
    'checkout',
    'smartucf_submit',
    200,
    array(
        'type' => MtUniCreditDiagnosticJournal::TYPE_SMARTUCF_SESSION,
        'operation' => MtUniCreditDiagnosticJournal::OPERATION_SESSION_START,
        'egn' => '1234567890',
        'phone2' => '0888123456',
        'password' => 'secret-value',
        'user' => 'smart-user',
        'Authorization' => 'Bearer abc.def',
        'outcome' => 'ok',
        'http_class' => '2xx',
        'request' => array('orderNo' => '701', 'onlineProductCode' => 'KOP1'),
        'response' => array('errorCode' => 0, 'errorText' => 'ok'),
    )
);

$dbgOk = mtuc115a_invoke('smartucf_debug_log', array(
    'unicid' => $stackDbg['unicid'],
    'order_id' => '701',
), $stackDbg, array('X-UniPayment-Nonce' => mtuc115a_nonce()));
mtuc115a_assert($dbgOk['status'] === 200 && !empty($dbgOk['payload']['success']), 'debug: valid scoped read → 200');
$log = $dbgOk['payload']['data']['log'];
mtuc115a_assert($log['summary']['egn'] === '[REDACTED]', 'debug: EGN redacted');
mtuc115a_assert($log['summary']['phone2'] === '[REDACTED]', 'debug: phone2 redacted');
mtuc115a_assert($log['summary']['password'] === '[REDACTED]', 'debug: password redacted');
mtuc115a_assert($log['summary']['user'] === '[REDACTED]', 'debug: user redacted');
mtuc115a_assert($log['summary']['Authorization'] === '[REDACTED]', 'debug: Authorization redacted');
mtuc115a_assert($log['summary']['outcome'] === 'ok', 'debug: safe operational field preserved');
mtuc115a_assert(
    strpos($dbgOk['body'], '1234567890') === false
        && strpos($dbgOk['body'], 'secret-value') === false
        && strpos($dbgOk['body'], 'Bearer abc') === false,
    'debug: response body has no sensitive plaintext'
);

$stackDbg['memoryDb']->seedOrder(702, $stackDbg['storeId'], MtUniCreditConstants::EXTENSION_CODE);
$dbgEmpty = mtuc115a_invoke('smartucf_debug_log', array(
    'unicid' => $stackDbg['unicid'],
    'order_id' => '702',
), $stackDbg, array('X-UniPayment-Nonce' => mtuc115a_nonce()));
mtuc115a_assert(
    $dbgEmpty['status'] === 404 && $dbgEmpty['payload']['error'] === 'order_not_found',
    'debug: owned order without rows → opaque 404 (OC4 parity)'
);

$dbgMissing = mtuc115a_invoke('smartucf_debug_log', array(
    'unicid' => $stackDbg['unicid'],
    'order_id' => '99999',
), $stackDbg, array('X-UniPayment-Nonce' => mtuc115a_nonce()));
mtuc115a_assert(
    $dbgMissing['status'] === 404 && $dbgMissing['payload']['error'] === 'order_not_found',
    'debug: unknown order → opaque 404'
);

// Bound: SmartUCF session preferred over later generic lifecycle rows (11.5A.4)
(new MtUniCreditDiagnosticDebugLogRepository($stackDbg['db']))->insert(
    $stackDbg['storeId'],
    701,
    'checkout',
    'cp_status_patch_success',
    200,
    array('outcome' => 'patched', 'message' => 'generic shadow')
);
$dbgLatest = mtuc115a_invoke('smartucf_debug_log', array(
    'unicid' => $stackDbg['unicid'],
    'order_id' => '701',
), $stackDbg, array('X-UniPayment-Nonce' => mtuc115a_nonce()));
mtuc115a_assert(
    $dbgLatest['payload']['data']['log']['event_code'] === 'smartucf_submit',
    'debug: SmartUCF session preferred over later generic row'
);
mtuc115a_assert(
    isset($dbgLatest['payload']['data']['log']['request']['orderNo'])
        && $dbgLatest['payload']['data']['log']['request']['orderNo'] === '701',
    'debug: SmartUCF request body returned to CP'
);

// Cross-store
$dbgShared = new Phase2MemoryDb();
$dbgA = Phase6TestHarness::stack($dbgShared, Phase6TestHarness::STORE_A);
$dbgB = Phase6TestHarness::stack($dbgShared, Phase6TestHarness::STORE_B);
$dbgShared->seedOrder(801, $dbgA['storeId'], MtUniCreditConstants::EXTENSION_CODE);
(new MtUniCreditDiagnosticDebugLogRepository($dbgA['db']))->insert(
    $dbgA['storeId'],
    801,
    'checkout',
    'success',
    200,
    array(
        'type' => MtUniCreditDiagnosticJournal::TYPE_SMARTUCF_SESSION,
        'operation' => MtUniCreditDiagnosticJournal::OPERATION_SESSION_START,
        'outcome' => 'a',
        'request' => array('orderNo' => '801'),
        'response' => array('status' => 'ok'),
    )
);
$cross = mtuc115a_invoke('smartucf_debug_log', array(
    'unicid' => $dbgB['unicid'],
    'order_id' => '801',
), $dbgB, array('X-UniPayment-Nonce' => mtuc115a_nonce()));
mtuc115a_assert($cross['status'] === 404, 'debug: store B cannot read store A diagnostics');

mtuc115a_assert_auth_matrix('smartucf_debug_log', Phase6TestHarness::stack());

// ---------------------------------------------------------------------------
// C — order_bank_status
// ---------------------------------------------------------------------------
$stackBank = Phase6TestHarness::stack();
$stackBank['memoryDb']->seedOrder(901, $stackBank['storeId'], MtUniCreditConstants::EXTENSION_CODE);

$fwd = mtuc115a_invoke('order_bank_status', array(
    'unicid' => $stackBank['unicid'],
    'order_id' => '901',
    'status_id' => 'bank_sent_process1',
    'status' => 'Изпратен Банка - Процес 1',
), $stackBank, array('X-UniPayment-Nonce' => mtuc115a_nonce()));
mtuc115a_assert($fwd['status'] === 200 && $fwd['payload']['data']['status_id'] === 'bank_sent_process1', 'bank: valid forward status');
mtuc115a_assert($fwd['payload']['data']['oc_order_state_changed'] === false, 'bank: oc_order_state_changed false');

$same = mtuc115a_invoke('order_bank_status', array(
    'unicid' => $stackBank['unicid'],
    'order_id' => '901',
    'status_id' => 'bank_sent_process1',
    'status' => 'Изпратен Банка - Процес 1',
), $stackBank, array('X-UniPayment-Nonce' => mtuc115a_nonce()));
mtuc115a_assert($same['status'] === 200 && $same['payload']['data']['oc_order_state_changed'] === false, 'bank: same-status idempotent');

// OC4 parity: last-write wins (store does NOT reject regressive push; CP must not send them)
$stale = mtuc115a_invoke('order_bank_status', array(
    'unicid' => $stackBank['unicid'],
    'order_id' => '901',
    'status_id' => 'cp_sent',
    'status' => 'Създаден в КП',
), $stackBank, array('X-UniPayment-Nonce' => mtuc115a_nonce()));
mtuc115a_assert(
    $stale['status'] === 200 && $stale['payload']['data']['status_id'] === 'cp_sent',
    'bank: OC4 last-write-wins accepts rewrite (CP must not push stale)'
);

$termA = mtuc115a_invoke('order_bank_status', array(
    'unicid' => $stackBank['unicid'],
    'order_id' => '901',
    'status_id' => 'bank_sent_process1',
    'status' => 'P1',
), $stackBank, array('X-UniPayment-Nonce' => mtuc115a_nonce()));
$termB = mtuc115a_invoke('order_bank_status', array(
    'unicid' => $stackBank['unicid'],
    'order_id' => '901',
    'status_id' => 'bank_send_failed_smartucf',
    'status' => 'Fail',
), $stackBank, array('X-UniPayment-Nonce' => mtuc115a_nonce()));
mtuc115a_assert(
    $termA['status'] === 200 && $termB['status'] === 200
        && $termB['payload']['data']['status_id'] === 'bank_send_failed_smartucf',
    'bank: terminal conflict resolved by last-write (OC4 parity; CP must avoid)'
);

$unknown = mtuc115a_invoke('order_bank_status', array(
    'unicid' => $stackBank['unicid'],
    'order_id' => '901',
    'status_id' => 'totally_invalid_status',
    'status' => 'X',
), $stackBank, array('X-UniPayment-Nonce' => mtuc115a_nonce()));
mtuc115a_assert(
    $unknown['status'] === 400 && $unknown['payload']['error'] === 'unsupported_status',
    'bank: unknown status rejected'
);

$missing = mtuc115a_invoke('order_bank_status', array(
    'unicid' => $stackBank['unicid'],
    'order_id' => '40404',
    'status_id' => 'cp_sent',
    'status' => 'X',
), $stackBank, array('X-UniPayment-Nonce' => mtuc115a_nonce()));
mtuc115a_assert($missing['status'] === 404, 'bank: unknown order → 404');

// Admin presentation reads local row (no CP HTTP)
$labels = (new MtUniCreditFinancingPresentationRepository($stackBank['db']))->bankStatusLabelsForOrders(
    array(array('order_id' => 901, 'store_id' => $stackBank['storeId'])),
    $stackBank['storeId']
);
mtuc115a_assert(
    isset($labels[0]) && $labels[0] === 'Fail',
    'bank: Admin list/detail source reflects pushed status_label locally'
);

// Multistore
$bankShared = new Phase2MemoryDb();
$bankA = Phase6TestHarness::stack($bankShared, Phase6TestHarness::STORE_A);
$bankB = Phase6TestHarness::stack($bankShared, Phase6TestHarness::STORE_B);
$bankShared->seedOrder(910, $bankA['storeId'], MtUniCreditConstants::EXTENSION_CODE);
$crossBank = mtuc115a_invoke('order_bank_status', array(
    'unicid' => $bankB['unicid'],
    'order_id' => '910',
    'status_id' => 'cp_sent',
    'status' => 'X',
), $bankB, array('X-UniPayment-Nonce' => mtuc115a_nonce()));
mtuc115a_assert($crossBank['status'] === 404, 'bank: store B cannot mutate store A order');

mtuc115a_assert(
    MtUniCreditInboundBankStatusVocabulary::isAccepted('cp_sent')
        && MtUniCreditInboundBankStatusVocabulary::isAccepted('smartucf_sent')
        && MtUniCreditInboundBankStatusVocabulary::isAccepted('bank_sent_process1')
        && MtUniCreditInboundBankStatusVocabulary::isAccepted('bank_sent_process2')
        && MtUniCreditInboundBankStatusVocabulary::isAccepted('bank_send_failed')
        && MtUniCreditInboundBankStatusVocabulary::isAccepted('bank_send_failed_cp')
        && MtUniCreditInboundBankStatusVocabulary::isAccepted('bank_send_failed_smartucf')
        && MtUniCreditInboundBankStatusVocabulary::isAccepted('10')
        && MtUniCreditInboundBankStatusVocabulary::isAccepted('08'),
    'bank: full accepted vocabulary including numeric SmartUCF codes'
);

mtuc115a_assert_auth_matrix('order_bank_status', Phase6TestHarness::stack());

// Atomic nonce claim (simultaneous identical nonce)
$claimDb = new Phase2MemoryDb();
$claimAdapter = new MtUniCreditDbAdapter($claimDb, 'oc_');
$claimRepo = new MtUniCreditApiNonceRepository(
    $claimAdapter,
    new MtUniCreditPersistenceClock(function () {
        return Phase6TestHarness::NOW;
    })
);
$claimNonce = str_repeat('ab', 32);
mtuc115a_assert($claimRepo->claim(Phase6TestHarness::STORE_A, Phase4TestHarness::TEST_UNICID, $claimNonce) === true, 'atomic nonce: first wins');
mtuc115a_assert($claimRepo->claim(Phase6TestHarness::STORE_A, Phase4TestHarness::TEST_UNICID, $claimNonce) === false, 'atomic nonce: second rejected');

echo PHP_EOL . 'Phase 11.5A checks: ' . $passes . ' passed';
if ($failures) {
    echo ', ' . count($failures) . ' failed' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo ', 0 failed' . PHP_EOL;
exit(0);
