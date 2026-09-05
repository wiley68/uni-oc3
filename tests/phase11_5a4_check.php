<?php

/**
 * Phase 11.5A.4 — SmartUCF diagnostic CP contract parity (CP + Woo authority).
 * Run: php tests/phase11_5a4_check.php
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
    $storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mtuc-phase115a4-storage';
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
function mtuc115a4_assert($condition, $message)
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
 * Mirror CP ShopModuleSmartUcfDebugFetcher::sanitizeData / sanitizeLog.
 *
 * @param array<string, mixed> $storeResponse
 * @return array<string, mixed>
 */
function mtuc115a4_cp_sanitize(array $storeResponse)
{
    $data = isset($storeResponse['data']) && is_array($storeResponse['data'])
        ? $storeResponse['data']
        : array();
    $log = isset($data['log']) && is_array($data['log']) ? $data['log'] : null;

    $scalarString = function ($value) {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    };
    $scalarHttp = function ($value) use ($scalarString) {
        if (is_int($value) || is_float($value)) {
            return (int) $value;
        }
        $text = $scalarString($value);
        if ($text !== null && ctype_digit($text)) {
            return (int) $text;
        }

        return $text;
    };

    $sanitizedLog = null;
    if ($log !== null) {
        $sanitizedLog = array(
            'order_id' => $scalarString(isset($log['order_id']) ? $log['order_id'] : null),
            'entry_point' => $scalarString(isset($log['entry_point']) ? $log['entry_point'] : null),
            'event_code' => $scalarString(isset($log['event_code']) ? $log['event_code'] : null),
            'http_status' => $scalarHttp(
                isset($log['http_status'])
                    ? $log['http_status']
                    : (isset($log['http_code']) ? $log['http_code'] : null)
            ),
            'http_code' => $scalarHttp(
                isset($log['http_code'])
                    ? $log['http_code']
                    : (isset($log['http_status']) ? $log['http_status'] : null)
            ),
            'summary' => $scalarString(isset($log['summary']) ? $log['summary'] : null),
            'created_at' => $scalarString(
                isset($log['created_at'])
                    ? $log['created_at']
                    : (isset($log['created_at_site']) ? $log['created_at_site'] : null)
            ),
            'created_at_site' => $scalarString(
                isset($log['created_at_site'])
                    ? $log['created_at_site']
                    : (isset($log['created_at']) ? $log['created_at'] : null)
            ),
            'request' => array_key_exists('request', $log) ? $log['request'] : null,
            'response' => array_key_exists('response', $log) ? $log['response'] : null,
        );
    }

    return array(
        'success' => true,
        'data' => array(
            'order_id' => $scalarString(isset($data['order_id']) ? $data['order_id'] : null),
            'oc_order_id' => $scalarString(isset($data['oc_order_id']) ? $data['oc_order_id'] : null),
            'wc_order_id' => $scalarHttp(isset($data['wc_order_id']) ? $data['wc_order_id'] : null),
            'log' => $sanitizedLog,
        ),
    );
}

/**
 * @return string
 */
function mtuc115a4_nonce()
{
    static $n = 0;
    $n++;

    return str_pad(dechex($n), 64, '0', STR_PAD_LEFT);
}

/**
 * @param array<string, mixed> $lifecycleStack Phase9 stack
 * @param int $orderId
 * @return array<string, mixed>
 */
function mtuc115a4_invoke_debug(array $lifecycleStack, $orderId)
{
    $apiStack = Phase6TestHarness::stack($lifecycleStack['memoryDb'], $lifecycleStack['storeId']);

    $raw = json_encode(
        array('unicid' => $apiStack['unicid'], 'order_id' => (string) $orderId),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    $headers = Phase6TestHarness::signedHeaders(
        $apiStack['secret'],
        $raw,
        array('X-UniPayment-Nonce' => mtuc115a4_nonce())
    );

    try {
        $result = MtUniCreditInboundApiDispatcher::dispatch(
            function (array $payload, $unicid) use ($apiStack) {
                unset($unicid);
                $orderIdRaw = trim((string) $payload['order_id']);
                $orderId = (int) $orderIdRaw;
                $ownership = new MtUniCreditOrderOwnershipResolver($apiStack['db']);
                if ($ownership->resolveAuthorizedOrderId($apiStack['storeId'], $orderIdRaw) === null) {
                    throw new MtUniCreditInboundApiException(
                        'Не е намерена диагностична информация за тази поръчка.',
                        404,
                        'order_not_found'
                    );
                }
                $log = (new MtUniCreditDiagnosticDebugLogRepository($apiStack['db']))
                    ->findLatestSmartUcfSessionByOrderId($apiStack['storeId'], $orderId);
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
            },
            $apiStack['authenticator'],
            Phase6TestHarness::serverFromHeaders($headers),
            $raw,
            'POST'
        );
        $encoded = MtUniCreditInboundApiDispatcher::encodeResponse($result, 200);
    } catch (MtUniCreditInboundApiException $exception) {
        $encoded = MtUniCreditInboundApiDispatcher::encodeException($exception);
    }

    $decoded = json_decode($encoded['body'], true);

    return array(
        'status' => (int) $encoded['status'],
        'body' => (string) $encoded['body'],
        'payload' => is_array($decoded) ? $decoded : array(),
    );
}

$fakeUser = 'fake_uni_user_A4';
$fakePass = 'fake_uni_pass_A4!';
$fakeFname = 'FakeFirstA4';
$fakeLname = 'FakeLastA4';
$fakePhone = '+359888000004';
$fakeEmail = 'fake.a4@example.test';
$fakeAddress = 'ul. Fake 4, Sofia';
$fakeSession = 'fake-session-id-A4-resume';

// ---------------------------------------------------------------------------
// CP consumer shape fixture
// ---------------------------------------------------------------------------
$cpFixture = array(
    'success' => true,
    'data' => array(
        'order_id' => '4401',
        'oc_order_id' => 4401,
        'log' => array(
            'order_id' => 4401,
            'entry_point' => 'checkout',
            'event_code' => 'success',
            'type' => 'smartucf_session',
            'http_status' => 200,
            'http_code' => 200,
            'created_at' => '2026-09-04 12:00:00',
            'created_at_gmt' => '2026-09-04 12:00:00',
            'request' => array(
                'user' => '[REDACTED]',
                'pass' => '[REDACTED]',
                'orderNo' => '4401',
                'onlineProductCode' => 'KOP1',
                'totalPrice' => 199.99,
                'initialPayment' => 0,
                'installmentCount' => 12,
                'monthlyPayment' => 16.66,
                'items' => array(
                    array(
                        'name' => 'Demo',
                        'code' => 'SKU1',
                        'type' => 'product',
                        'count' => 1,
                        'singlePrice' => 199.99,
                    ),
                ),
            ),
            'response' => array(
                'sucfOnlineSessionID' => 'abc-123-visible',
                'errorCode' => 0,
                'errorText' => 'ok',
            ),
            'transport_error' => null,
            'summary' => array('message' => 'SmartUCF session created successfully.'),
        ),
    ),
);
$cpView = mtuc115a4_cp_sanitize($cpFixture);
mtuc115a4_assert($cpView['data']['order_id'] === '4401', 'CP shape: order_id string');
mtuc115a4_assert($cpView['data']['oc_order_id'] === '4401', 'CP shape: oc_order_id scalar string');
mtuc115a4_assert($cpView['data']['log']['entry_point'] === 'checkout', 'CP shape: entry_point');
mtuc115a4_assert($cpView['data']['log']['event_code'] === 'success', 'CP shape: event_code');
mtuc115a4_assert($cpView['data']['log']['http_code'] === 200, 'CP shape: http_code');
mtuc115a4_assert($cpView['data']['log']['created_at'] === '2026-09-04 12:00:00', 'CP shape: created_at');
mtuc115a4_assert(is_array($cpView['data']['log']['request']), 'CP shape: request is decoded object');
mtuc115a4_assert(is_array($cpView['data']['log']['response']), 'CP shape: response is decoded object');
mtuc115a4_assert(
    $cpView['data']['log']['response']['sucfOnlineSessionID'] === 'abc-123-visible',
    'CP shape: sucfOnlineSessionID remains visible'
);
mtuc115a4_assert($cpView['data']['log']['summary'] === null, 'CP shape: array summary coerced to null (scalarString)');

// ---------------------------------------------------------------------------
// Woo redaction parity unit checks
// ---------------------------------------------------------------------------
$req = MtUniCreditDiagnosticPayloadRedactor::redactMixed(json_encode(array(
    'user' => $fakeUser,
    'pass' => $fakePass,
    'clientFirstName' => $fakeFname,
    'clientLastName' => $fakeLname,
    'clientPhone' => $fakePhone,
    'clientEmail' => $fakeEmail,
    'clientDeliveryAddress' => $fakeAddress,
    'orderNo' => '4401',
    'onlineProductCode' => 'KOP1',
    'totalPrice' => 199.99,
    'initialPayment' => 10,
    'installmentCount' => 6,
    'monthlyPayment' => 31.66,
    'items' => array(array('name' => 'Item', 'code' => 'C1', 'type' => 'product', 'count' => 1, 'singlePrice' => 189.99)),
)));
mtuc115a4_assert(is_array($req), 'redact: request decodes to array');
mtuc115a4_assert($req['user'] === '[REDACTED]', 'redact: user');
mtuc115a4_assert($req['pass'] === '[REDACTED]', 'redact: pass');
mtuc115a4_assert($req['clientFirstName'] === '[REDACTED]', 'redact: clientFirstName');
mtuc115a4_assert($req['clientLastName'] === '[REDACTED]', 'redact: clientLastName');
mtuc115a4_assert($req['clientPhone'] === '[REDACTED]', 'redact: clientPhone');
mtuc115a4_assert($req['clientEmail'] === '[REDACTED]', 'redact: clientEmail');
mtuc115a4_assert($req['clientDeliveryAddress'] === '[REDACTED]', 'redact: clientDeliveryAddress');
mtuc115a4_assert($req['orderNo'] === '4401', 'preserve: orderNo');
mtuc115a4_assert($req['onlineProductCode'] === 'KOP1', 'preserve: onlineProductCode');
mtuc115a4_assert($req['totalPrice'] === 199.99, 'preserve: totalPrice');
mtuc115a4_assert($req['initialPayment'] === 10, 'preserve: initialPayment');
mtuc115a4_assert($req['installmentCount'] === 6, 'preserve: installmentCount');
mtuc115a4_assert($req['monthlyPayment'] === 31.66, 'preserve: monthlyPayment');
mtuc115a4_assert(isset($req['items'][0]['name']) && $req['items'][0]['name'] === 'Item', 'preserve: items');

$resp = MtUniCreditDiagnosticPayloadRedactor::redactMixed(json_encode(array(
    'sucfOnlineSessionID' => $fakeSession,
    'errorCode' => 121,
    'errorText' => 'Already registered',
)));
mtuc115a4_assert($resp['sucfOnlineSessionID'] === $fakeSession, 'preserve: sucfOnlineSessionID visible');
mtuc115a4_assert($resp['errorCode'] === 121, 'preserve: errorCode');
mtuc115a4_assert($resp['errorText'] === 'Already registered', 'preserve: errorText');

// Session ID visible; credentials/PII still redacted (including nested).
$sessionVisible = MtUniCreditDiagnosticPayloadRedactor::redact(array(
    'sucfOnlineSessionID' => '123456789',
    'password' => 'secret-pass',
    'secret' => 'top-secret',
    'token' => 'bearer-token',
    'egn' => '1990010112',
    'response' => array(
        'sucfOnlineSessionID' => 'nested-sess-987',
        'password' => 'nested-pass',
    ),
));
mtuc115a4_assert($sessionVisible['sucfOnlineSessionID'] === '123456789', 'session: top-level visible');
mtuc115a4_assert($sessionVisible['password'] === '[REDACTED]', 'session: password redacted');
mtuc115a4_assert($sessionVisible['secret'] === '[REDACTED]', 'session: secret redacted');
mtuc115a4_assert($sessionVisible['token'] === '[REDACTED]', 'session: token redacted');
mtuc115a4_assert($sessionVisible['egn'] === '[REDACTED]', 'session: egn redacted');
mtuc115a4_assert(
    $sessionVisible['response']['sucfOnlineSessionID'] === 'nested-sess-987',
    'session: nested sucfOnlineSessionID visible'
);
mtuc115a4_assert($sessionVisible['response']['password'] === '[REDACTED]', 'session: nested password redacted');

// ---------------------------------------------------------------------------
// SmartUCF success — actual bodies + CP selection
// ---------------------------------------------------------------------------
$transportOk = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportOk);
$stackOk = Phase9TestHarness::stack($transportOk);
$orderOk = Phase9TestHarness::ORDER_ID;
Phase9TestHarness::seedBankOrder($stackOk['memoryDb'], $orderOk, $stackOk['storeId']);
$resultOk = $stackOk['submission']->submit(Phase9TestHarness::submitInput($orderOk, $stackOk['storeId']));
mtuc115a4_assert(!empty($resultOk['success']), 'P1 success: lifecycle succeeds');

$repoOk = new MtUniCreditDiagnosticDebugLogRepository($stackOk['db']);
$smartOk = $repoOk->findLatestSmartUcfSessionByOrderId($stackOk['storeId'], $orderOk);
mtuc115a4_assert(is_array($smartOk), 'P1 success: SmartUCF diagnostic row present');
mtuc115a4_assert(
    is_array($smartOk) && $smartOk['event_code'] === 'success',
    'P1 success: event_code=success'
);
mtuc115a4_assert(
    is_array($smartOk) && $smartOk['type'] === MtUniCreditDiagnosticJournal::TYPE_SMARTUCF_SESSION,
    'P1 success: type=smartucf_session'
);
mtuc115a4_assert(
    is_array($smartOk) && is_array($smartOk['request']) && isset($smartOk['request']['orderNo']),
    'P1 success: redacted request present'
);
mtuc115a4_assert(
    is_array($smartOk) && is_array($smartOk['response']),
    'P1 success: redacted response present'
);
mtuc115a4_assert(
    is_array($smartOk)
        && isset($smartOk['response']['sucfOnlineSessionID'])
        && $smartOk['response']['sucfOnlineSessionID'] === 'sess-phase9-ok',
    'P1 success: sucfOnlineSessionID preserved in diagnostic response'
);
mtuc115a4_assert(
    is_array($smartOk) && (int) $smartOk['http_code'] === 200,
    'P1 success: HTTP code present'
);
mtuc115a4_assert(
    is_array($smartOk) && $smartOk['entry_point'] !== '',
    'P1 success: entry point present'
);
mtuc115a4_assert(
    is_array($smartOk)
        && $smartOk['operation'] === MtUniCreditDiagnosticJournal::OPERATION_SESSION_START,
    'P1 success: operation identifies SmartUCF'
);

$apiOk = mtuc115a4_invoke_debug($stackOk, $orderOk);
mtuc115a4_assert($apiOk['status'] === 200, 'P1 success API: 200');
$cpOk = mtuc115a4_cp_sanitize($apiOk['payload']);
mtuc115a4_assert(is_array($cpOk['data']['log']['request']), 'P1 success CP: request object');
mtuc115a4_assert(is_array($cpOk['data']['log']['response']), 'P1 success CP: response object');

// ---------------------------------------------------------------------------
// Generic event shadowing (mandatory)
// ---------------------------------------------------------------------------
$repoOk->insert(
    $stackOk['storeId'],
    $orderOk,
    'checkout',
    'cp_create_success',
    201,
    array('message' => 'earlier create', 'outcome' => 'cp_create_success')
);
// Re-read after intentional later generic row (already have success; add patch after)
$repoOk->insert(
    $stackOk['storeId'],
    $orderOk,
    'checkout',
    'cp_status_patch_success',
    200,
    array('message' => 'later patch', 'outcome' => 'cp_status_patch_success')
);
$latestGeneric = $repoOk->findLatestByOrderId($stackOk['storeId'], $orderOk);
mtuc115a4_assert(
    is_array($latestGeneric) && $latestGeneric['event_code'] === 'cp_status_patch_success',
    'shadow setup: latest generic is cp_status_patch_success'
);
$shadowApi = mtuc115a4_invoke_debug($stackOk, $orderOk);
mtuc115a4_assert($shadowApi['status'] === 200, 'shadow API: 200');
mtuc115a4_assert(
    $shadowApi['payload']['data']['log']['event_code'] === 'success',
    'shadow: SmartUCF diagnostic returned (not later generic)'
);
mtuc115a4_assert(
    is_array($shadowApi['payload']['data']['log']['request']),
    'shadow: request still present'
);

// ---------------------------------------------------------------------------
// SmartUCF reject — errorCode/errorText visible
// ---------------------------------------------------------------------------
$transportRej = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportRej);
$stackRej = Phase9TestHarness::stack(
    $transportRej,
    function () {
        return array(
            'body' => Phase9TestHarness::rejectBody(),
            'error' => '',
            'http_code' => 200,
        );
    }
);
$orderRej = Phase9TestHarness::ORDER_ID + 10;
Phase9TestHarness::seedBankOrder($stackRej['memoryDb'], $orderRej, $stackRej['storeId']);
$stackRej['submission']->submit(Phase9TestHarness::submitInput($orderRej, $stackRej['storeId']));
$rejLog = (new MtUniCreditDiagnosticDebugLogRepository($stackRej['db']))
    ->findLatestSmartUcfSessionByOrderId($stackRej['storeId'], $orderRej);
mtuc115a4_assert(is_array($rejLog) && $rejLog['event_code'] === 'remote_reject', 'reject: event_code');
mtuc115a4_assert(
    is_array($rejLog)
        && is_array($rejLog['response'])
        && (string) $rejLog['response']['errorCode'] === 'E1',
    'reject: errorCode visible'
);
mtuc115a4_assert(
    is_array($rejLog)
        && is_array($rejLog['response'])
        && $rejLog['response']['errorText'] === 'Rejected by bank',
    'reject: errorText visible'
);
mtuc115a4_assert(
    is_array($rejLog) && is_array($rejLog['request']),
    'reject: redacted request present'
);
$rejBody = (string) json_encode($rejLog);
mtuc115a4_assert(strpos($rejBody, $fakeSession) === false, 'reject: no raw session id injected');

// ---------------------------------------------------------------------------
// Timeout — request present, response null, transport_error present
// ---------------------------------------------------------------------------
$transportTo = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportTo);
$stackTo = Phase9TestHarness::stack(
    $transportTo,
    function () {
        return array(
            'body' => '',
            'error' => 'Operation timed out after 10000 milliseconds',
            'http_code' => 0,
        );
    }
);
$orderTo = Phase9TestHarness::ORDER_ID + 20;
Phase9TestHarness::seedBankOrder($stackTo['memoryDb'], $orderTo, $stackTo['storeId']);
$stackTo['submission']->submit(Phase9TestHarness::submitInput($orderTo, $stackTo['storeId']));
$toLog = (new MtUniCreditDiagnosticDebugLogRepository($stackTo['db']))
    ->findLatestSmartUcfSessionByOrderId($stackTo['storeId'], $orderTo);
mtuc115a4_assert(is_array($toLog) && $toLog['event_code'] === 'transport_ambiguous', 'timeout: event');
mtuc115a4_assert(is_array($toLog) && is_array($toLog['request']), 'timeout: redacted request present');
mtuc115a4_assert(is_array($toLog) && $toLog['response'] === null, 'timeout: response null');
mtuc115a4_assert(
    is_array($toLog)
        && is_string($toLog['transport_error'])
        && $toLog['transport_error'] !== '',
    'timeout: transport_error present'
);

// ---------------------------------------------------------------------------
// Process 2 — no fake SmartUCF diagnostic for CP
// ---------------------------------------------------------------------------
$transportP2 = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportP2);
$stackP2 = Phase9TestHarness::stack(
    $transportP2,
    null,
    null,
    Phase5TestHarness::STORE_A,
    array('uni_proces' => 1)
);
$orderP2 = Phase9TestHarness::ORDER_ID + 30;
Phase9TestHarness::seedBankOrder($stackP2['memoryDb'], $orderP2, $stackP2['storeId']);
$stackP2['submission']->submit(Phase9TestHarness::submitInputProcess2($orderP2, $stackP2['storeId']));
$p2Smart = (new MtUniCreditDiagnosticDebugLogRepository($stackP2['db']))
    ->findLatestSmartUcfSessionByOrderId($stackP2['storeId'], $orderP2);
mtuc115a4_assert($p2Smart === null, 'P2: no SmartUCF diagnostic row');
$p2Api = mtuc115a4_invoke_debug($stackP2, $orderP2);
mtuc115a4_assert($p2Api['status'] === 404, 'P2: CP debug endpoint opaque 404');

// ---------------------------------------------------------------------------
// Privacy sweep on success diagnostic
// ---------------------------------------------------------------------------
$privacyHay = (string) json_encode($smartOk);
foreach (
    array(
        'demo-secret-password',
        $fakeUser,
        $fakePass,
        $fakeFname,
        $fakeLname,
        $fakePhone,
        $fakeEmail,
        $fakeAddress,
        $fakeSession,
        'BEGIN PRIVATE KEY',
        'passphrase',
    ) as $needle
) {
    mtuc115a4_assert(
        stripos($privacyHay, $needle) === false,
        'privacy: absent ' . substr($needle, 0, 24)
    );
}

// ---------------------------------------------------------------------------
// Admin export includes identifiable SmartUCF entry
// ---------------------------------------------------------------------------
$export = MtUniCreditDiagnosticJournal::fromDatabase($stackOk['db'])->buildExport($stackOk['storeId']);
$foundSmart = false;
foreach ($export['entries'] as $entry) {
    if (
        isset($entry['type'])
        && $entry['type'] === MtUniCreditDiagnosticJournal::TYPE_SMARTUCF_SESSION
        && is_array($entry['request'])
    ) {
        $foundSmart = true;
        break;
    }
}
mtuc115a4_assert($foundSmart, 'admin journal: SmartUCF entry identifiable with request');

echo PHP_EOL;
if ($failures) {
    echo 'FAILED ' . count($failures) . ' / asserted ' . ($passes + count($failures)) . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'PHASE 11.5A.4 SMARTUCF CP CONTRACT PARITY: PASS — LOCAL (' . $passes . ' assertions)' . PHP_EOL;
exit(0);
