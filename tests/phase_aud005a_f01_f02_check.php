<?php

/**
 * AUD-005A F01/F02 — signature case exactness + auth-before-JSON ordering.
 * Run: php tests/phase_aud005a_f01_f02_check.php
 *
 * PHP 7.3 compatible. Offline. No network / real DB mutation.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/support/phase2_memory_db.php';

$failures = array();
$passes = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucAud005a_assert($condition, $message)
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

mtucAud005a_assert(mtuc_phase0_network_isolation_active(), 'isolation: offline guard active');

$stack = Phase6TestHarness::stack();
$secret = $stack['secret'];
$unicid = $stack['unicid'];
$now = Phase6TestHarness::NOW;

$verifier = new MtUniCreditRequestSignatureVerifier(function () use ($now) {
    return (int) $now;
});

/**
 * @param string $rawBody
 * @param string $signature
 * @param string|null $nonce
 * @return array<string, string>
 */
function mtucAud005a_headers($secret, $rawBody, $signature, $nonce = null)
{
    $timestamp = (string) Phase6TestHarness::NOW;
    $nonce = $nonce !== null ? $nonce : str_repeat('b', 64);

    return array(
        MtUniCreditRequestSignatureProtocol::HEADER_TIMESTAMP => $timestamp,
        MtUniCreditRequestSignatureProtocol::HEADER_NONCE => $nonce,
        MtUniCreditRequestSignatureProtocol::HEADER_SIGNATURE => $signature,
    );
}

/**
 * @param MtUniCreditRequestSignatureVerifier $verifier
 * @param string $secret
 * @param string $rawBody
 * @param array<string, string> $headers
 * @return bool true when accepted
 */
function mtucAud005a_verifyAccepted($verifier, $secret, $rawBody, array $headers)
{
    try {
        $verifier->verify($secret, $rawBody, $headers);

        return true;
    } catch (MtUniCreditPersistenceValidationException $exception) {
        return false;
    }
}

// ---------------------------------------------------------------------------
// F01 — exact lowercase 64-hex signature; no normalization
// ---------------------------------------------------------------------------
$rawOk = '{"unicid":"' . $unicid . '","ping":1}';
$nonceF01 = str_repeat('1', 64);
$goodSig = MtUniCreditRequestSignatureProtocol::computeSignature($secret, (string) $now, $nonceF01, $rawOk);
mtucAud005a_assert(preg_match('/^[0-9a-f]{64}$/D', $goodSig) === 1, 'F01: computeSignature returns lowercase 64-hex');

mtucAud005a_assert(
    mtucAud005a_verifyAccepted($verifier, $secret, $rawOk, mtucAud005a_headers($secret, $rawOk, $goodSig, $nonceF01)),
    'F01: lowercase valid signature accepted'
);

$upper = strtoupper($goodSig);
mtucAud005a_assert($upper !== $goodSig, 'F01: uppercase form differs from lowercase');
mtucAud005a_assert(
    !mtucAud005a_verifyAccepted($verifier, $secret, $rawOk, mtucAud005a_headers($secret, $rawOk, $upper, $nonceF01)),
    'F01: uppercase equivalent rejected (no strtolower)'
);

$mixed = substr($goodSig, 0, 32) . strtoupper(substr($goodSig, 32));
mtucAud005a_assert(
    !mtucAud005a_verifyAccepted($verifier, $secret, $rawOk, mtucAud005a_headers($secret, $rawOk, $mixed, $nonceF01)),
    'F01: mixed-case equivalent rejected'
);

mtucAud005a_assert(
    !mtucAud005a_verifyAccepted(
        $verifier,
        $secret,
        $rawOk,
        mtucAud005a_headers($secret, $rawOk, substr($goodSig, 0, 63), $nonceF01)
    ),
    'F01: 63-char signature rejected'
);

mtucAud005a_assert(
    !mtucAud005a_verifyAccepted(
        $verifier,
        $secret,
        $rawOk,
        mtucAud005a_headers($secret, $rawOk, $goodSig . 'a', $nonceF01)
    ),
    'F01: 65-char signature rejected'
);

$nonHex = substr($goodSig, 0, 63) . 'g';
mtucAud005a_assert(
    !mtucAud005a_verifyAccepted($verifier, $secret, $rawOk, mtucAud005a_headers($secret, $rawOk, $nonHex, $nonceF01)),
    'F01: non-hex signature rejected'
);

mtucAud005a_assert(
    !mtucAud005a_verifyAccepted($verifier, $secret, $rawOk, mtucAud005a_headers($secret, $rawOk, '', $nonceF01)),
    'F01: empty signature rejected'
);

$wrongDigest = str_repeat('0', 64);
mtucAud005a_assert($wrongDigest !== $goodSig, 'F01: wrong digest differs');
mtucAud005a_assert(
    !mtucAud005a_verifyAccepted($verifier, $secret, $rawOk, mtucAud005a_headers($secret, $rawOk, $wrongDigest, $nonceF01)),
    'F01: correct-length wrong digest rejected'
);

$verifierSrc = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'request_signature_verifier.php');
mtucAud005a_assert(
    strpos($verifierSrc, 'strtolower($this->requireHeader($headers, MtUniCreditRequestSignatureProtocol::HEADER_SIGNATURE))') === false,
    'F01: signature strtolower normalization removed'
);
mtucAud005a_assert(
    strpos($verifierSrc, "/^[0-9a-f]{64}$/D") !== false,
    'F01: exact lowercase hex regex present'
);

// ---------------------------------------------------------------------------
// Canonicalization regression (unchanged builder)
// ---------------------------------------------------------------------------
$ts = '1700000000';
$n = str_repeat('ab', 32);
$bodyA = "{\"unicid\":\"X\"}";
$bodyB = "{\"unicid\":\"X\"} ";
$bodyC = "{\"unicid\":\"X\"}\n";
$bodyD = "{\"unicid\":\"\\u0058\"}";
$cA = MtUniCreditRequestSignatureProtocol::buildCanonicalString($ts, $n, $bodyA);
mtucAud005a_assert($cA === $ts . "\n" . $n . "\n" . $bodyA, 'canon: timestamp + LF + nonce + LF + raw_body');
mtucAud005a_assert(
    MtUniCreditRequestSignatureProtocol::computeSignature($secret, $ts, $n, $bodyA)
        !== MtUniCreditRequestSignatureProtocol::computeSignature($secret, $ts, $n, $bodyB),
    'canon: trailing whitespace changes HMAC'
);
mtucAud005a_assert(
    MtUniCreditRequestSignatureProtocol::computeSignature($secret, $ts, $n, $bodyA)
        !== MtUniCreditRequestSignatureProtocol::computeSignature($secret, $ts, $n, $bodyC),
    'canon: trailing LF changes HMAC'
);
mtucAud005a_assert(
    MtUniCreditRequestSignatureProtocol::computeSignature($secret, $ts, $n, $bodyA)
        !== MtUniCreditRequestSignatureProtocol::computeSignature($secret, $ts, $n, $bodyD),
    'canon: escaped Unicode bytes differ from literal'
);

// ---------------------------------------------------------------------------
// F02 — authentication before json_decode / payload validation
// ---------------------------------------------------------------------------

/**
 * @param array<string, mixed> $stack
 * @param string $rawBody
 * @param array<string, string> $headerOverrides
 * @param array<int, string> $orderLog
 * @return array{status: int, error: string|null, dispatched: bool}
 */
function mtucAud005a_dispatchRaw(array $stack, $rawBody, array $headerOverrides, array &$orderLog)
{
    $headers = Phase6TestHarness::signedHeaders($stack['secret'], $rawBody, $headerOverrides);
    $dispatched = false;
    $status = 0;
    $error = null;

    $auth = new MtUniCreditRequestAuthenticator(
        $stack['credentials'],
        new MtUniCreditApiNonceRepository(
            $stack['db'],
            new MtUniCreditPersistenceClock(function () {
                return Phase6TestHarness::NOW;
            })
        ),
        $stack['storeId'],
        true,
        new MtUniCreditRequestSignatureVerifier(function () use (&$orderLog) {
            $orderLog[] = 'authenticate_verify_clock';

            return Phase6TestHarness::NOW;
        })
    );

    // Instrument by wrapping through a custom authenticator subclass is unavailable.
    // Use production dispatcher and classify outcomes; also probe method existence order in source.
    try {
        MtUniCreditInboundApiDispatcher::dispatch(
            function ($payload, $unicid) use (&$dispatched, &$orderLog) {
                $orderLog[] = 'handler';
                $dispatched = true;

                return array('ok' => true, 'unicid' => $unicid, 'payload' => $payload);
            },
            $auth,
            Phase6TestHarness::serverFromHeaders($headers),
            $rawBody,
            'POST'
        );
        $status = 200;
    } catch (MtUniCreditInboundApiException $exception) {
        $status = $exception->getStatusCode();
        $error = $exception->getErrorCode();
        $orderLog[] = 'exception:' . (string) $error;
    }

    return array(
        'status' => $status,
        'error' => $error,
        'dispatched' => $dispatched,
    );
}

$dispatcherSrc = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'inbound_api_dispatcher.php');
$authPos = strpos($dispatcherSrc, '$authenticator->authenticate($rawBody, $headers)');
$jsonPos = strpos($dispatcherSrc, 'json_decode($rawBody, true)');
$finalizePos = strpos($dispatcherSrc, 'finalizeAuthenticatedRequest');
mtucAud005a_assert($authPos !== false && $jsonPos !== false && $authPos < $jsonPos, 'F02 structure: authenticate before json_decode');
mtucAud005a_assert($finalizePos !== false && $jsonPos < $finalizePos, 'F02 structure: finalize after json_decode');

// A. malformed JSON + invalid signature → authentication rejection (not JSON-first)
$orderA = array();
$malformed = '{not-json';
$resultA = mtucAud005a_dispatchRaw(
    $stack,
    $malformed,
    array(
        'X-UniPayment-Nonce' => str_repeat('2', 64),
        'X-UniPayment-Signature' => str_repeat('0', 64),
    ),
    $orderA
);
mtucAud005a_assert($resultA['status'] === 401, 'F02-A: malformed+invalid signature → 401');
mtucAud005a_assert($resultA['error'] === 'invalid_signature', 'F02-A: classified as authentication failure');
mtucAud005a_assert($resultA['error'] !== 'malformed_json', 'F02-A: not JSON parse classification first');
mtucAud005a_assert(!$resultA['dispatched'], 'F02-A: handler not reached');

// B. malformed JSON + valid signature → auth first, then JSON parse failure
$orderB = array();
$nonceB = str_repeat('3', 64);
$sigB = MtUniCreditRequestSignatureProtocol::computeSignature($secret, (string) $now, $nonceB, $malformed);
$resultB = mtucAud005a_dispatchRaw(
    $stack,
    $malformed,
    array(
        'X-UniPayment-Nonce' => $nonceB,
        'X-UniPayment-Signature' => $sigB,
    ),
    $orderB
);
mtucAud005a_assert($resultB['status'] === 400, 'F02-B: malformed+valid signature → 400');
mtucAud005a_assert($resultB['error'] === 'malformed_json', 'F02-B: JSON parse failure after auth');
mtucAud005a_assert(!$resultB['dispatched'], 'F02-B: handler not reached');
mtucAud005a_assert(
    in_array('authenticate_verify_clock', $orderB, true),
    'F02-B: authenticator/verifier executed before JSON failure'
);

// C. valid JSON + valid signature → normal dispatch
$orderC = array();
$validBody = json_encode(array('unicid' => $unicid, 'n' => 'c'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$resultC = mtucAud005a_dispatchRaw(
    $stack,
    $validBody,
    array('X-UniPayment-Nonce' => str_repeat('4', 64)),
    $orderC
);
mtucAud005a_assert($resultC['status'] === 200, 'F02-C: valid JSON+signature dispatches');
mtucAud005a_assert($resultC['dispatched'], 'F02-C: handler reached');
mtucAud005a_assert(
    in_array('authenticate_verify_clock', $orderC, true) && in_array('handler', $orderC, true),
    'F02-C: authenticate then handler'
);
$authIdx = array_search('authenticate_verify_clock', $orderC, true);
$handlerIdx = array_search('handler', $orderC, true);
mtucAud005a_assert(
    is_int($authIdx) && is_int($handlerIdx) && $authIdx < $handlerIdx,
    'F02-C: auth observed before handler'
);

// D. valid JSON + invalid signature → auth rejects, no dispatch
$orderD = array();
$resultD = mtucAud005a_dispatchRaw(
    $stack,
    $validBody,
    array(
        'X-UniPayment-Nonce' => str_repeat('5', 64),
        'X-UniPayment-Signature' => str_repeat('f', 64),
    ),
    $orderD
);
mtucAud005a_assert($resultD['status'] === 401, 'F02-D: valid JSON+invalid signature → 401');
mtucAud005a_assert($resultD['error'] === 'invalid_signature', 'F02-D: authentication rejection');
mtucAud005a_assert(!$resultD['dispatched'], 'F02-D: no payload dispatch');

// Raw-body preservation: whitespace variant must use exact bytes for HMAC
$rawWs = '{"unicid":"' . $unicid . '"}' . ' ';
$orderWs = array();
$nonceWs = str_repeat('6', 64);
$sigWs = MtUniCreditRequestSignatureProtocol::computeSignature($secret, (string) $now, $nonceWs, $rawWs);
$resultWs = mtucAud005a_dispatchRaw(
    $stack,
    $rawWs,
    array(
        'X-UniPayment-Nonce' => $nonceWs,
        'X-UniPayment-Signature' => $sigWs,
    ),
    $orderWs
);
mtucAud005a_assert($resultWs['status'] === 200 && $resultWs['dispatched'], 'raw-body: exact whitespace body authenticates');

$sigWsTrimmed = MtUniCreditRequestSignatureProtocol::computeSignature(
    $secret,
    (string) $now,
    $nonceWs,
    rtrim($rawWs)
);
mtucAud005a_assert($sigWs !== $sigWsTrimmed, 'raw-body: trimmed body yields different HMAC');

// HMAC secret regression: empty/missing secret fails closed (no hardcoded fallback)
$emptySecretStack = Phase6TestHarness::stack(new Phase2MemoryDb(), 910001);
$emptySecretStack['settings']->set(910001, MtUniCreditConstants::MODULE_SETTING_SECRET, '');
$emptySecretStack['credentials'] = MtUniCreditBootstrap::credentialsRepositoryFromDb($emptySecretStack['db']);
$emptyAuth = new MtUniCreditRequestAuthenticator(
    $emptySecretStack['credentials'],
    new MtUniCreditApiNonceRepository($emptySecretStack['db']),
    910001,
    true,
    $verifier
);
$emptyBody = json_encode(array('unicid' => Phase4TestHarness::TEST_UNICID), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$threwEmpty = false;
try {
    $emptyAuth->authenticate(
        $emptyBody,
        Phase6TestHarness::signedHeaders('ignored', $emptyBody, array('X-UniPayment-Nonce' => str_repeat('7', 64)))
    );
} catch (MtUniCreditInboundApiException $exception) {
    $threwEmpty = true;
    mtucAud005a_assert($exception->getStatusCode() === 401, 'secret: missing secret → 401 unknown_store/auth');
}
mtucAud005a_assert($threwEmpty, 'secret: no empty-secret fallback authentication');

// Shared dispatcher covers all inbound endpoints (structural)
$apiController = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
    . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
    . DIRECTORY_SEPARATOR . 'api.php';
$apiSrc = (string) file_get_contents($apiController);
$runnerSrc = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'inbound_api_runner.php');
mtucAud005a_assert(strpos($runnerSrc, 'MtUniCreditInboundApiDispatcher::dispatch') !== false, 'endpoints: shared runner uses dispatcher');
mtucAud005a_assert(strpos($apiSrc, 'MtUniCreditInboundApiRunner::run') !== false, 'endpoints: api controller uses shared runner');
mtucAud005a_assert(strpos($apiSrc, 'function shop_cache') !== false, 'endpoints: shop_cache inherits dispatcher');
mtucAud005a_assert(strpos($apiSrc, 'function order_bank_status') !== false, 'endpoints: order_bank_status inherits dispatcher');
mtucAud005a_assert(strpos($apiSrc, 'function smartucf_debug_log') !== false, 'endpoints: smartucf_debug_log inherits dispatcher');

echo PHP_EOL;
if ($failures) {
    echo 'AUD-005A F01/F02: FAIL (' . count($failures) . ' failures, ' . $passes . ' passes)' . PHP_EOL;
    foreach ($failures as $f) {
        echo '  - ' . $f . PHP_EOL;
    }
    exit(1);
}

echo 'AUD-005A F01/F02: PASS (' . $passes . ' passes)' . PHP_EOL;
exit(0);
