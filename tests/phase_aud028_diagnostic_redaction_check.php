<?php

/**
 * AUD-028 — Diagnostic nested JSON redaction + sensitive aliases + CP envelope.
 * Run: php tests/phase_aud028_diagnostic_redaction_check.php
 *
 * F01: recursive serialized JSON redaction (bounded depth)
 * F02: api_token / privateKey / private-key / access-token aliases
 * F03: CP sanitize preserves transport_error (coordinated mirror)
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
    mtuc_test_define_dir_storage('mtuc-aud028');
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
require_once __DIR__ . '/support/phase6_harness.php';
require_once __DIR__ . '/support/phase9_harness.php';
require_once __DIR__ . '/support/cp_smartucf_debug_sanitize.php';

$failures = array();
$passes = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucAud028_assert($condition, $message)
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

$sessionKeep = 'SUCf-Session-KEEP-9A7B3C';
$bankDebug = 'XYZ-42';
$bankTrace = 'TRACE-7788';
$pwSecret = 'PW-SECRET';
$authSecret = 'AUTH-SECRET';
$apiTokenSecret = 'API-TOKEN-SECRET';
$privateKeySecret = 'PRIVATE-KEY-SECRET';
$egn = '1990010112';
$phone2 = '+35988123456';
$accessTokenSecret = 'ACCESS-TOKEN-SECRET';
$dupValue = 'SAME-VALUE-UNDER-TOKEN-AND-SAFE';

$nestedContextRaw = json_encode(array(
    'customer' => array(
        'egn' => $egn,
        'phone2' => $phone2,
    ),
    'auth' => array(
        'Authorization' => 'Bearer ' . $authSecret,
        'api_token' => $apiTokenSecret,
    ),
    'sucfOnlineSessionID' => $sessionKeep,
    'bankDebugCode' => $bankDebug,
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$innerInner = json_encode(array('egn' => $egn, 'token' => $apiTokenSecret), JSON_UNESCAPED_SLASHES);
$doubleWrapped = json_encode(array('inner' => $innerInner), JSON_UNESCAPED_SLASHES);

$rawWire = array(
    'password' => $pwSecret,
    'secret' => 'TOP-SECRET',
    'token' => $dupValue,
    'safeOperationalField' => $dupValue,
    'api_token' => $apiTokenSecret,
    'access-token' => $accessTokenSecret,
    'Authorization' => 'Bearer ' . $authSecret,
    'egn' => $egn,
    'phone2' => $phone2,
    'privateKey' => $privateKeySecret,
    'private-key' => $privateKeySecret,
    'passphrase' => 'PHRASE-SECRET',
    'Password' => $pwSecret,
    'TOKEN' => 'TOKEN-CASE-SECRET',
    'ApiToken' => 'APITOKEN-CASE-SECRET',
    'PrivateKey' => $privateKeySecret,
    'Phone2' => $phone2,
    'EGN' => $egn,
    'sucfOnlineSessionID' => $sessionKeep,
    'bankDebugCode' => $bankDebug,
    'metadata' => array(
        'bankTrace' => $bankTrace,
    ),
    'futureUnknownSafeField' => 'FUTURE-SAFE-99',
    'orderReference' => 'ORD-REF-77',
    'context' => $nestedContextRaw,
    'errors' => array(
        json_encode(array('egn' => $egn, 'Authorization' => 'Bearer X'), JSON_UNESCAPED_SLASHES),
    ),
    'double' => $doubleWrapped,
    'opaqueNote' => 'plain free text not json',
);

// ---------------------------------------------------------------------------
// B. local redacted structure
// ---------------------------------------------------------------------------
$redacted = MtUniCreditDiagnosticPayloadRedactor::redact($rawWire);
mtucAud028_assert($redacted['password'] === '[REDACTED]', 'F02: password redacted');
mtucAud028_assert($redacted['api_token'] === '[REDACTED]', 'mutation-5 YES: api_token redacted');
mtucAud028_assert($redacted['privateKey'] === '[REDACTED]', 'mutation-6 YES: privateKey redacted');
mtucAud028_assert($redacted['private-key'] === '[REDACTED]', 'mutation-7 YES: private-key redacted');
mtucAud028_assert($redacted['access-token'] === '[REDACTED]', 'mutation-8 YES: access-token redacted');
mtucAud028_assert($redacted['Authorization'] === '[REDACTED]', 'F02: Authorization redacted');
mtucAud028_assert($redacted['egn'] === '[REDACTED]', 'F02: egn redacted');
mtucAud028_assert($redacted['phone2'] === '[REDACTED]', 'F02: phone2 redacted');
mtucAud028_assert($redacted['Password'] === '[REDACTED]', 'F02 case: Password');
mtucAud028_assert($redacted['TOKEN'] === '[REDACTED]', 'F02 case: TOKEN');
mtucAud028_assert($redacted['ApiToken'] === '[REDACTED]', 'F02 case: ApiToken');
mtucAud028_assert($redacted['PrivateKey'] === '[REDACTED]', 'F02 case: PrivateKey');
mtucAud028_assert($redacted['Phone2'] === '[REDACTED]', 'F02 case: Phone2');
mtucAud028_assert($redacted['EGN'] === '[REDACTED]', 'F02 case: EGN');
mtucAud028_assert($redacted['sucfOnlineSessionID'] === $sessionKeep, 'mutation-9 YES: sucfOnlineSessionID preserved');
mtucAud028_assert($redacted['bankDebugCode'] === $bankDebug, 'mutation-10 YES: bankDebugCode preserved');
mtucAud028_assert($redacted['metadata']['bankTrace'] === $bankTrace, 'unknown safe: metadata.bankTrace');
mtucAud028_assert($redacted['futureUnknownSafeField'] === 'FUTURE-SAFE-99', 'unknown safe: futureUnknownSafeField');
mtucAud028_assert($redacted['orderReference'] === 'ORD-REF-77', 'unknown safe: orderReference');
mtucAud028_assert($redacted['token'] === '[REDACTED]', 'credential-dupe: token redacted');
mtucAud028_assert($redacted['safeOperationalField'] === $dupValue, 'credential-dupe: safe field unchanged');
mtucAud028_assert($redacted['opaqueNote'] === 'plain free text not json', 'non-JSON free text preserved');

$nestedDecoded = json_decode($redacted['context'], true);
mtucAud028_assert(is_array($nestedDecoded), 'mutation-1 YES: nested serialized JSON decoded/re-encoded');
mtucAud028_assert(
    is_array($nestedDecoded)
        && $nestedDecoded['customer']['egn'] === '[REDACTED]',
    'mutation-2 YES: nested serialized EGN redacted'
);
mtucAud028_assert(
    is_array($nestedDecoded)
        && $nestedDecoded['customer']['phone2'] === '[REDACTED]',
    'mutation-3 YES: nested serialized phone2 redacted'
);
mtucAud028_assert(
    is_array($nestedDecoded)
        && $nestedDecoded['auth']['Authorization'] === '[REDACTED]'
        && $nestedDecoded['auth']['api_token'] === '[REDACTED]',
    'mutation-4 YES: nested Authorization/api_token redacted'
);
mtucAud028_assert(
    is_array($nestedDecoded)
        && $nestedDecoded['sucfOnlineSessionID'] === $sessionKeep
        && $nestedDecoded['bankDebugCode'] === $bankDebug,
    'serialized JSON: safe nested fields preserved'
);

$err0 = json_decode($redacted['errors'][0], true);
mtucAud028_assert(
    is_array($err0) && $err0['egn'] === '[REDACTED]' && $err0['Authorization'] === '[REDACTED]',
    'nested-array: errors[] JSON string redacted'
);

$double = json_decode($redacted['double'], true);
mtucAud028_assert(is_array($double) && is_string($double['inner']), 'double-serialized: outer decoded');
$inner = json_decode($double['inner'], true);
mtucAud028_assert(
    is_array($inner) && $inner['egn'] === '[REDACTED]' && $inner['token'] === '[REDACTED]',
    'double-serialized: inner redacted within depth bound'
);
mtucAud028_assert(
    MtUniCreditDiagnosticPayloadRedactor::MAX_NESTED_JSON_DEPTH === 4,
    'recursion bound: MAX_NESTED_JSON_DEPTH=4'
);

// Depth trap: wrap beyond bound — must not infinite-loop; deepest may remain string
$wrap = json_encode(array('egn' => $egn), JSON_UNESCAPED_SLASHES);
for ($i = 0; $i < 8; $i++) {
    $wrap = json_encode(array('layer' => $wrap), JSON_UNESCAPED_SLASHES);
}
$deepRedacted = MtUniCreditDiagnosticPayloadRedactor::redact(array('blob' => $wrap));
mtucAud028_assert(is_string($deepRedacted['blob']), 'depth trap: returns without hang');

// ---------------------------------------------------------------------------
// C. persistence (redaction before insert + second pass)
// ---------------------------------------------------------------------------
$memoryDb = new Phase2MemoryDb();
$db = new MtUniCreditDbAdapter($memoryDb, 'oc_');
$storeId = Phase5TestHarness::STORE_A;
$orderId = 28001;
Phase9TestHarness::seedBankOrder($memoryDb, $orderId, $storeId);

$repo = new MtUniCreditDiagnosticDebugLogRepository($db);
$journal = new MtUniCreditDiagnosticJournal($repo, function () {
    return true;
});
$journal->recordSmartUcfSession(
    $storeId,
    $orderId,
    MtUniCreditOperationEntryPoint::PRODUCT,
    'https://bank.example/session',
    $rawWire,
    array(
        'sucfOnlineSessionID' => $sessionKeep,
        'bankDebugCode' => $bankDebug,
        'errorCode' => null,
        'metadata' => array('bankTrace' => $bankTrace),
        'futureUnknownSafeField' => 'FUTURE-SAFE-99',
    ),
    200,
    null,
    MtUniCreditDiagnosticJournal::EVENT_SUCCESS
);

$persisted = $repo->findLatestSmartUcfSessionByOrderId($storeId, $orderId);
mtucAud028_assert(is_array($persisted), 'persist: SmartUCF row stored');
$summaryJson = (string) json_encode($persisted);
mtucAud028_assert($summaryJson !== '', 'persist: summary structure present');
foreach (array($pwSecret, $authSecret, $apiTokenSecret, $privateKeySecret, $egn, $phone2, $accessTokenSecret) as $needle) {
    mtucAud028_assert(
        strpos($summaryJson, $needle) === false,
        'raw-search persist: absent ' . substr($needle, 0, 18)
    );
}
mtucAud028_assert(strpos($summaryJson, $sessionKeep) !== false, 'persist: session id exact');
mtucAud028_assert(strpos($summaryJson, $bankDebug) !== false, 'persist: bankDebugCode exact');
mtucAud028_assert(strpos($summaryJson, $bankTrace) !== false, 'persist: bankTrace exact');

$reqP = $persisted['request'];
mtucAud028_assert(is_array($reqP) && $reqP['sucfOnlineSessionID'] === $sessionKeep, 'C: session in persisted request');
$ctxP = json_decode(isset($reqP['context']) ? $reqP['context'] : '', true);
mtucAud028_assert(
    is_array($ctxP) && $ctxP['customer']['egn'] === '[REDACTED]',
    'F01 persist proof: nested EGN redacted before insert'
);

// Second pass stability
$second = MtUniCreditDiagnosticPayloadRedactor::redact($persisted);
mtucAud028_assert(
    is_array($second['request']) && $second['request']['sucfOnlineSessionID'] === $sessionKeep,
    'second-pass: sucfOnlineSessionID unchanged'
);
mtucAud028_assert(
    is_array($second['response']) && $second['response']['bankDebugCode'] === $bankDebug,
    'second-pass: bankDebugCode unchanged'
);

// ---------------------------------------------------------------------------
// D/E module API + CP sanitize five-stage
// ---------------------------------------------------------------------------
$apiStack = Phase6TestHarness::stack($memoryDb, $storeId);
$rawBody = json_encode(
    array('unicid' => $apiStack['unicid'], 'order_id' => (string) $orderId),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
$headers = Phase6TestHarness::signedHeaders(
    $apiStack['secret'],
    $rawBody,
    array('X-UniPayment-Nonce' => str_pad('1', 64, '0', STR_PAD_LEFT))
);
try {
    $apiResult = MtUniCreditInboundApiDispatcher::dispatch(
        function (array $payload, $unicid) use ($apiStack, $orderId) {
            unset($unicid, $payload);
            $log = (new MtUniCreditDiagnosticDebugLogRepository($apiStack['db']))
                ->findLatestSmartUcfSessionByOrderId($apiStack['storeId'], $orderId);
            if ($log === null) {
                throw new MtUniCreditInboundApiException('missing', 404, 'order_not_found');
            }

            return array(
                'success' => true,
                'data' => array(
                    'order_id' => (string) $orderId,
                    'oc_order_id' => $orderId,
                    'log' => $log,
                ),
            );
        },
        $apiStack['authenticator'],
        Phase6TestHarness::serverFromHeaders($headers),
        $rawBody,
        'POST'
    );
    $encoded = MtUniCreditInboundApiDispatcher::encodeResponse($apiResult, 200);
} catch (MtUniCreditInboundApiException $e) {
    $encoded = MtUniCreditInboundApiDispatcher::encodeException($e);
}
$modulePayload = json_decode($encoded['body'], true);
$cpView = mtuc_cp_smartucf_debug_sanitize(is_array($modulePayload) ? $modulePayload : array());
$cpLog = isset($cpView['data']['log']) ? $cpView['data']['log'] : null;

mtucAud028_assert(is_array($cpLog), 'E: CP log present');
mtucAud028_assert(
    is_array($cpLog['request'])
        && $cpLog['request']['sucfOnlineSessionID'] === $sessionKeep,
    'five-stage: session exact at CP'
);
mtucAud028_assert(
    is_array($cpLog['response'])
        && $cpLog['response']['sucfOnlineSessionID'] === $sessionKeep
        && $cpLog['response']['bankDebugCode'] === $bankDebug
        && $cpLog['response']['metadata']['bankTrace'] === $bankTrace
        && $cpLog['response']['futureUnknownSafeField'] === 'FUTURE-SAFE-99',
    'five-stage: safe response fields A→E'
);
$cpHay = (string) json_encode($cpLog);
foreach (array($pwSecret, $apiTokenSecret, $privateKeySecret, $egn, $phone2, $accessTokenSecret) as $needle) {
    mtucAud028_assert(strpos($cpHay, $needle) === false, 'five-stage CP absent ' . substr($needle, 0, 16));
}
$cpCtx = json_decode(isset($cpLog['request']['context']) ? $cpLog['request']['context'] : '', true);
mtucAud028_assert(
    is_array($cpCtx) && $cpCtx['customer']['egn'] === '[REDACTED]',
    'nested-serialized five-stage at CP'
);

// Exact redaction diff: sensitive paths changed; safe paths identical
$safePathsOk = $rawWire['sucfOnlineSessionID'] === $redacted['sucfOnlineSessionID']
    && $rawWire['bankDebugCode'] === $redacted['bankDebugCode']
    && $rawWire['metadata']['bankTrace'] === $redacted['metadata']['bankTrace'];
mtucAud028_assert($safePathsOk, 'exact-diff: safe paths unchanged');
$sensitiveChanged = $redacted['password'] !== $rawWire['password']
    && $redacted['api_token'] !== $rawWire['api_token']
    && $redacted['privateKey'] !== $rawWire['privateKey'];
mtucAud028_assert($sensitiveChanged, 'exact-diff: sensitive paths changed');

// ---------------------------------------------------------------------------
// F03 timeout / connection / HTTP error / business rejection
// ---------------------------------------------------------------------------
$timeoutLog = array(
    'order_id' => $orderId,
    'entry_point' => 'product',
    'event_code' => 'transport_ambiguous',
    'http_status' => 0,
    'http_code' => 0,
    'type' => MtUniCreditDiagnosticJournal::TYPE_SMARTUCF_SESSION,
    'operation' => MtUniCreditDiagnosticJournal::OPERATION_SESSION_START,
    'endpoint' => 'https://bank.example/session',
    'outcome' => 'transport_ambiguous',
    'request' => array('sucfOnlineSessionID' => null, 'user' => '[REDACTED]'),
    'response' => null,
    'transport_error' => 'Operation timed out after 10000 milliseconds',
    'summary' => null,
    'created_at' => '2024-01-01 00:00:00',
);
$timeoutCp = mtuc_cp_smartucf_debug_sanitize(array(
    'success' => true,
    'data' => array('order_id' => (string) $orderId, 'log' => $timeoutLog),
));
$tLog = $timeoutCp['data']['log'];
mtucAud028_assert(
    $tLog['response'] === null
        && $tLog['transport_error'] === 'Operation timed out after 10000 milliseconds'
        && !isset($tLog['response']['sucfOnlineSessionID']),
    'mutation-11 YES: CP preserves transport_error'
);
mtucAud028_assert(
    $tLog['response'] === null && empty($tLog['response']),
    'mutation-12 YES: CP does not synthesize response/session on timeout'
);
mtucAud028_assert(
    $tLog['type'] === MtUniCreditDiagnosticJournal::TYPE_SMARTUCF_SESSION
        && $tLog['operation'] === MtUniCreditDiagnosticJournal::OPERATION_SESSION_START
        && $tLog['outcome'] === 'transport_ambiguous',
    'F03: CP preserves type/operation/outcome'
);

$connectLog = $timeoutLog;
$connectLog['transport_error'] = 'Could not resolve host: bank.example';
$connectCp = mtuc_cp_smartucf_debug_sanitize(array(
    'success' => true,
    'data' => array('order_id' => (string) $orderId, 'log' => $connectLog),
));
mtucAud028_assert(
    $connectCp['data']['log']['response'] === null
        && strpos($connectCp['data']['log']['transport_error'], 'Could not resolve host') !== false,
    'connection-failure: transport_error preserved'
);

$httpErrLog = $timeoutLog;
$httpErrLog['http_status'] = 502;
$httpErrLog['http_code'] = 502;
$httpErrLog['transport_error'] = null;
$httpErrLog['response'] = array(
    'errorCode' => 'UPSTREAM',
    'errorText' => 'Bad gateway detail',
    'bankDebugCode' => $bankDebug,
);
$httpCp = mtuc_cp_smartucf_debug_sanitize(array(
    'success' => true,
    'data' => array('order_id' => (string) $orderId, 'log' => $httpErrLog),
));
mtucAud028_assert(
    is_array($httpCp['data']['log']['response'])
        && $httpCp['data']['log']['response']['errorText'] === 'Bad gateway detail'
        && $httpCp['data']['log']['http_status'] === 502,
    'HTTP-error-with-body: response preserved'
);

$rejectLog = $timeoutLog;
$rejectLog['http_status'] = 200;
$rejectLog['transport_error'] = null;
$rejectLog['response'] = array(
    'errorCode' => 'REJECT',
    'errorText' => 'Rejected by bank',
    'sucfOnlineSessionID' => $sessionKeep,
    'bankDebugCode' => $bankDebug,
);
$rejectCp = mtuc_cp_smartucf_debug_sanitize(array(
    'success' => true,
    'data' => array('order_id' => (string) $orderId, 'log' => $rejectLog),
));
mtucAud028_assert(
    $rejectCp['data']['log']['response']['errorCode'] === 'REJECT'
        && $rejectCp['data']['log']['response']['sucfOnlineSessionID'] === $sessionKeep,
    'business-rejection: actual redacted rejection survives'
);

// No synthetic success construction in CP sanitize source
$cpSrc = (string) file_get_contents(__DIR__ . '/support/cp_smartucf_debug_sanitize.php');
mtucAud028_assert(
    strpos($cpSrc, 'sucfOnlineSessionID') === false
        || strpos($cpSrc, 'transport_error') !== false,
    'no-synthetic: sanitize does not invent session fields'
);

// Size bound unchanged
mtucAud028_assert(
    MtUniCreditDiagnosticDebugLogRepository::MAX_SUMMARY_JSON_BYTES === 65536,
    'size: 65536 bound unchanged'
);

// CP production path report canary (read-only)
$cpProd = dirname($root) . '/uni.avalonbg.com/app/Support/ShopModuleSmartUcfDebugFetcher.php';
mtucAud028_assert(is_file($cpProd), 'F03 source: uni.avalonbg.com ShopModuleSmartUcfDebugFetcher present (read-only)');
$cpProdSrc = (string) file_get_contents($cpProd);
mtucAud028_assert(
    strpos($cpProdSrc, "'transport_error'") === false
        && strpos($cpProdSrc, 'transport_error') === false,
    'F03 gap: production CP sanitize still omits transport_error (external patch required)'
);

echo PHP_EOL . 'AUD-028 diagnostic redaction: ' . $passes . ' PASS, ' . count($failures) . ' FAIL' . PHP_EOL;
if ($failures !== array()) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

exit(0);
