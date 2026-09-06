<?php

/**
 * AUD-005B F01 — exact lowercase nonce syntax (no normalization).
 * Run: php tests/phase_aud005b_f01_nonce_format_check.php
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
function mtucAud005b_assert($condition, $message)
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

mtucAud005b_assert(mtuc_phase0_network_isolation_active(), 'isolation: offline guard active');

$stack = Phase6TestHarness::stack();
$secret = $stack['secret'];
$unicid = $stack['unicid'];
$now = (string) Phase6TestHarness::NOW;
$rawBody = json_encode(array('unicid' => $unicid, 'n' => 1), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$verifier = new MtUniCreditRequestSignatureVerifier(function () {
    return Phase6TestHarness::NOW;
});

/**
 * @param MtUniCreditRequestSignatureVerifier $verifier
 * @param string $secret
 * @param string $rawBody
 * @param string $nonce
 * @param string|null $signatureOverride
 * @return bool
 */
function mtucAud005b_verifyOk($verifier, $secret, $rawBody, $nonce, $signatureOverride = null)
{
    $timestamp = (string) Phase6TestHarness::NOW;
    $signature = $signatureOverride !== null
        ? $signatureOverride
        : MtUniCreditRequestSignatureProtocol::computeSignature($secret, $timestamp, $nonce, $rawBody);
    $headers = array(
        MtUniCreditRequestSignatureProtocol::HEADER_TIMESTAMP => $timestamp,
        MtUniCreditRequestSignatureProtocol::HEADER_NONCE => $nonce,
        MtUniCreditRequestSignatureProtocol::HEADER_SIGNATURE => $signature,
    );
    try {
        $verifier->verify($secret, $rawBody, $headers);

        return true;
    } catch (MtUniCreditPersistenceValidationException $exception) {
        return false;
    }
}

/**
 * @param array<string, mixed> $stack
 * @param string $rawBody
 * @param string $nonce
 * @return array{status: int, error: string|null, dispatched: bool}
 */
function mtucAud005b_dispatch($stack, $rawBody, $nonce)
{
    $timestamp = (string) Phase6TestHarness::NOW;
    $signature = MtUniCreditRequestSignatureProtocol::computeSignature(
        $stack['secret'],
        $timestamp,
        $nonce,
        $rawBody
    );
    $headers = array(
        'X-UniPayment-Timestamp' => $timestamp,
        'X-UniPayment-Nonce' => $nonce,
        'X-UniPayment-Signature' => $signature,
    );
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
        new MtUniCreditRequestSignatureVerifier(function () {
            return Phase6TestHarness::NOW;
        })
    );

    try {
        MtUniCreditInboundApiDispatcher::dispatch(
            function ($payload, $u) use (&$dispatched) {
                $dispatched = true;

                return array('ok' => true, 'unicid' => $u, 'payload' => $payload);
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
    }

    return array(
        'status' => $status,
        'error' => $error,
        'dispatched' => $dispatched,
    );
}

// ---------------------------------------------------------------------------
// Structural: no nonce case normalization on auth path
// ---------------------------------------------------------------------------
$verifierSrc = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'request_signature_verifier.php');
$authSrc = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'request_authenticator.php');
mtucAud005b_assert(
    strpos($verifierSrc, "/^[0-9a-f]{") !== false
        && strpos($verifierSrc, '}$/D') !== false,
    'structure: nonce regex is lowercase with /D'
);
mtucAud005b_assert(
    strpos($verifierSrc, '[0-9a-fA-F]') === false,
    'structure: case-insensitive nonce class removed'
);
mtucAud005b_assert(
    strpos($verifierSrc, 'strtolower($this->requireHeader($headers, MtUniCreditRequestSignatureProtocol::HEADER_NONCE))') === false,
    'structure: extractNonce no longer strtolower()s nonce'
);
mtucAud005b_assert(
    strpos($authSrc, 'strtolower($this->verifier->extractNonce') === false,
    'structure: authenticator no longer strtolower()s nonce'
);

// ---------------------------------------------------------------------------
// Verifier matrix
// ---------------------------------------------------------------------------
$validNonce = str_repeat('a', 64);
mtucAud005b_assert(
    mtucAud005b_verifyOk($verifier, $secret, $rawBody, $validNonce),
    'matrix: 64 lowercase hex ACCEPT'
);
mtucAud005b_assert(
    !mtucAud005b_verifyOk($verifier, $secret, $rawBody, str_repeat('a', 63)),
    'matrix: 63 lowercase hex REJECT'
);
mtucAud005b_assert(
    !mtucAud005b_verifyOk($verifier, $secret, $rawBody, str_repeat('a', 65)),
    'matrix: 65 lowercase hex REJECT'
);
mtucAud005b_assert(
    !mtucAud005b_verifyOk($verifier, $secret, $rawBody, str_repeat('A', 64)),
    'matrix: 64 uppercase hex REJECT'
);
$mixed = str_repeat('a', 32) . str_repeat('B', 32);
mtucAud005b_assert(
    !mtucAud005b_verifyOk($verifier, $secret, $rawBody, $mixed),
    'matrix: mixed case REJECT'
);
mtucAud005b_assert(
    !mtucAud005b_verifyOk($verifier, $secret, $rawBody, str_repeat('a', 63) . 'g'),
    'matrix: contains g REJECT'
);
mtucAud005b_assert(
    !mtucAud005b_verifyOk($verifier, $secret, $rawBody, str_repeat('a', 63) . '-'),
    'matrix: hyphen REJECT'
);
mtucAud005b_assert(
    !mtucAud005b_verifyOk($verifier, $secret, $rawBody, str_repeat('a', 31) . ' ' . str_repeat('a', 32)),
    'matrix: internal space REJECT'
);
mtucAud005b_assert(
    !mtucAud005b_verifyOk($verifier, $secret, $rawBody, ' ' . str_repeat('a', 64)),
    'matrix: leading space REJECT'
);
mtucAud005b_assert(
    !mtucAud005b_verifyOk($verifier, $secret, $rawBody, str_repeat('a', 64) . ' '),
    'matrix: trailing space REJECT'
);
mtucAud005b_assert(
    !mtucAud005b_verifyOk($verifier, $secret, $rawBody, str_repeat('a', 64) . "\n"),
    'matrix: 64 lowercase + LF REJECT'
);
mtucAud005b_assert(
    !mtucAud005b_verifyOk($verifier, $secret, $rawBody, str_repeat('a', 64) . "\r\n"),
    'matrix: 64 lowercase + CRLF REJECT'
);
mtucAud005b_assert(
    !mtucAud005b_verifyOk($verifier, $secret, $rawBody, '0x' . str_repeat('a', 62)),
    'matrix: 0x prefix REJECT'
);
mtucAud005b_assert(
    !mtucAud005b_verifyOk($verifier, $secret, $rawBody, ''),
    'matrix: empty REJECT'
);

// ---------------------------------------------------------------------------
// Handler boundary: invalid nonce + otherwise valid HMAC over supplied bytes
// ---------------------------------------------------------------------------
$cases = array(
    'uppercase' => str_repeat('C', 64),
    'mixed-case' => str_repeat('d', 32) . str_repeat('E', 32),
    'lowercase+LF' => str_repeat('f', 64) . "\n",
);
foreach ($cases as $label => $nonce) {
    $result = mtucAud005b_dispatch($stack, $rawBody, $nonce);
    mtucAud005b_assert($result['status'] === 401, 'handler: ' . $label . ' → 401');
    mtucAud005b_assert($result['error'] === 'invalid_signature', 'handler: ' . $label . ' → invalid_signature');
    mtucAud005b_assert(!$result['dispatched'], 'handler: ' . $label . ' handler NOT reached');
}

$okDispatch = mtucAud005b_dispatch($stack, $rawBody, str_repeat('9', 64));
mtucAud005b_assert($okDispatch['status'] === 200 && $okDispatch['dispatched'], 'handler: valid lowercase reaches handler');

// ---------------------------------------------------------------------------
// Valid nonce identity: header = HMAC input = extractNonce (no transform)
// ---------------------------------------------------------------------------
$identityNonce = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
$timestamp = $now;
$sig = MtUniCreditRequestSignatureProtocol::computeSignature($secret, $timestamp, $identityNonce, $rawBody);
$headers = array(
    MtUniCreditRequestSignatureProtocol::HEADER_TIMESTAMP => $timestamp,
    MtUniCreditRequestSignatureProtocol::HEADER_NONCE => $identityNonce,
    MtUniCreditRequestSignatureProtocol::HEADER_SIGNATURE => $sig,
);
$verifier->verify($secret, $rawBody, $headers);
$extracted = $verifier->extractNonce($headers);
mtucAud005b_assert($extracted === $identityNonce, 'identity: extractNonce equals original header');
mtucAud005b_assert(
    $sig === MtUniCreditRequestSignatureProtocol::computeSignature($secret, $timestamp, $extracted, $rawBody),
    'identity: extracted nonce reproduces HMAC'
);
mtucAud005b_assert($extracted === $headers[MtUniCreditRequestSignatureProtocol::HEADER_NONCE], 'identity: no transformation');

// ---------------------------------------------------------------------------
// Canonical HMAC regression
// ---------------------------------------------------------------------------
$ts = '1700000000';
$n = str_repeat('ab', 32);
$body = '{"unicid":"X"}';
mtucAud005b_assert(
    MtUniCreditRequestSignatureProtocol::buildCanonicalString($ts, $n, $body) === $ts . "\n" . $n . "\n" . $body,
    'canon: timestamp + LF + nonce + LF + raw_body'
);
mtucAud005b_assert(
    MtUniCreditRequestSignatureProtocol::computeSignature($secret, $ts, $n, $body)
        !== MtUniCreditRequestSignatureProtocol::computeSignature($secret, $ts, strtoupper($n), $body),
    'canon: uppercase nonce bytes change HMAC (and are rejected by verifier)'
);

// ---------------------------------------------------------------------------
// Timestamp regression (unchanged inclusive ±300)
// ---------------------------------------------------------------------------
$base = Phase6TestHarness::NOW;
$tsVerifier = new MtUniCreditRequestSignatureVerifier(function () use ($base) {
    return (int) $base;
});
$nonceTs = str_repeat('1', 64);
foreach (array(-300 => true, 300 => true, -301 => false, 301 => false) as $delta => $expectOk) {
    $tsVal = (string) ($base + $delta);
    $sigTs = MtUniCreditRequestSignatureProtocol::computeSignature($secret, $tsVal, $nonceTs, $rawBody);
    $ok = false;
    try {
        $tsVerifier->verify($secret, $rawBody, array(
            MtUniCreditRequestSignatureProtocol::HEADER_TIMESTAMP => $tsVal,
            MtUniCreditRequestSignatureProtocol::HEADER_NONCE => $nonceTs,
            MtUniCreditRequestSignatureProtocol::HEADER_SIGNATURE => $sigTs,
        ));
        $ok = true;
    } catch (MtUniCreditPersistenceValidationException $exception) {
        $ok = false;
    }
    mtucAud005b_assert($ok === $expectOk, 'timestamp: delta ' . $delta . ' => ' . ($expectOk ? 'ACCEPT' : 'REJECT'));
}

echo PHP_EOL;
if ($failures) {
    echo 'AUD-005B F01: FAIL (' . count($failures) . ' failures, ' . $passes . ' passes)' . PHP_EOL;
    foreach ($failures as $f) {
        echo '  - ' . $f . PHP_EOL;
    }
    exit(1);
}

echo 'AUD-005B F01: PASS (' . $passes . ' passes)' . PHP_EOL;
exit(0);
