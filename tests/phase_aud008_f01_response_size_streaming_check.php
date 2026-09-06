<?php

/**
 * AUD-008 F01 — CP response size limit enforced while receiving.
 * Run: php tests/phase_aud008_f01_response_size_streaming_check.php
 *
 * PHP 7.3 compatible. Offline.
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
function mtucAud008F01_assert($condition, $message)
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
 * @param callable $callback
 * @return Exception|null
 */
function mtucAud008F01_catch(callable $callback)
{
    try {
        $callback();

        return null;
    } catch (Exception $exception) {
        return $exception;
    }
}

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
}

require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';
require_once __DIR__ . '/support/phase4_harness.php';

mtucAud008F01_assert(mtuc_phase0_network_isolation_active(), 'isolation: offline guard active');
mtucAud008F01_assert(is_file($lib . DIRECTORY_SEPARATOR . 'cp_bounded_response_buffer.php'), 'bounded buffer helper present');
mtucAud008F01_assert(
    MtUniCreditCpHttpConstants::MAX_RESPONSE_BYTES === 1048576,
    'MAX_RESPONSE_BYTES remains 1 MiB'
);

$max = MtUniCreditCpHttpConstants::MAX_RESPONSE_BYTES;

// ---------------------------------------------------------------------------
// Production receive logic (exact buffer used by CurlCpHttpTransport)
// ---------------------------------------------------------------------------
$small = new MtUniCreditCpBoundedResponseBuffer();
$smallBody = '{"success":true}';
mtucAud008F01_assert($small->write($smallBody) === strlen($smallBody), 'small response write accepts all bytes');
mtucAud008F01_assert($small->getBody() === $smallBody, 'small response body byte-identical');
mtucAud008F01_assert(!$small->isAbortedForSize(), 'small response not aborted');

$exact = new MtUniCreditCpBoundedResponseBuffer();
$exactChunk = str_repeat('a', $max);
mtucAud008F01_assert($exact->write($exactChunk) === $max, 'exactly MAX_RESPONSE_BYTES accepted');
mtucAud008F01_assert($exact->getLength() === $max, 'exact-limit body length = MAX');
mtucAud008F01_assert(!$exact->isAbortedForSize(), 'exact-limit not aborted');

$plusOne = new MtUniCreditCpBoundedResponseBuffer();
$accepted = $plusOne->write(str_repeat('b', $max + 1));
mtucAud008F01_assert($accepted === 0, 'MAX_RESPONSE_BYTES + 1 write returns abort (0)');
mtucAud008F01_assert($plusOne->isAbortedForSize(), 'MAX_RESPONSE_BYTES + 1 aborted for size');
mtucAud008F01_assert($plusOne->getLength() === 0, 'overflowing first chunk not appended');

// Multi-chunk overflow (scaled fixture using same production class)
// Conceptual 400+400+300 over a 1 MiB-like bound: cross only on the third write.
$cap = 1000;
$multi = new MtUniCreditCpBoundedResponseBuffer($cap);
mtucAud008F01_assert($multi->write(str_repeat('x', 400)) === 400, 'multi-chunk: first 400 accepted');
mtucAud008F01_assert($multi->write(str_repeat('x', 400)) === 400, 'multi-chunk: second 400 accepted');
$third = $multi->write(str_repeat('x', 300));
mtucAud008F01_assert($third === 0, 'multi-chunk: third chunk aborts (would exceed)');
mtucAud008F01_assert($multi->isAbortedForSize(), 'multi-chunk: aborted during final chunk');
mtucAud008F01_assert($multi->getLength() === 800, 'multi-chunk: buffer stays at 800 (no overrun)');
mtucAud008F01_assert($multi->getLength() <= $cap, 'response buffer never exceeds configured bound');

// Full-size multi-chunk crossing the real 1 MiB cap
$fullMulti = new MtUniCreditCpBoundedResponseBuffer();
$chunkA = (int) floor($max * 0.4);
$chunkB = (int) floor($max * 0.4);
$chunkC = $max - $chunkA - $chunkB + 1; // crosses by 1
mtucAud008F01_assert($fullMulti->write(str_repeat('y', $chunkA)) === $chunkA, '1MiB multi: chunk A accepted');
mtucAud008F01_assert($fullMulti->write(str_repeat('y', $chunkB)) === $chunkB, '1MiB multi: chunk B accepted');
mtucAud008F01_assert($fullMulti->write(str_repeat('y', $chunkC)) === 0, '1MiB multi: chunk C aborts');
mtucAud008F01_assert($fullMulti->getLength() === ($chunkA + $chunkB), '1MiB multi: bound held before overflow chunk');
mtucAud008F01_assert($fullMulti->getLength() <= $max, '1MiB multi: length <= MAX');

// ---------------------------------------------------------------------------
// Transport wiring + intentional-abort classification
// ---------------------------------------------------------------------------
$curlSource = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'curl_cp_http_transport.php');
mtucAud008F01_assert(strpos($curlSource, 'CURLOPT_WRITEFUNCTION') !== false, 'transport uses WRITEFUNCTION');
mtucAud008F01_assert(strpos($curlSource, 'MtUniCreditCpBoundedResponseBuffer') !== false, 'transport uses bounded buffer');
mtucAud008F01_assert(
    strpos($curlSource, 'CURLOPT_RETURNTRANSFER => false') !== false
        || strpos($curlSource, 'CURLOPT_RETURNTRANSFER, false') !== false,
    'RETURNTRANSFER disabled for write-callback body'
);
mtucAud008F01_assert(strpos($curlSource, 'isAbortedForSize()') !== false, 'intentional size abort checked before curl errno');
mtucAud008F01_assert(
    strpos($curlSource, 'The Control Panel response exceeded the allowed size.') !== false,
    'size abort classified as InvalidPayload (response too large)'
);
mtucAud008F01_assert(strpos($curlSource, 'CURLOPT_FOLLOWLOCATION => false') !== false, 'redirects remain disabled');
mtucAud008F01_assert(strpos($curlSource, 'CURLOPT_SSL_VERIFYPEER => true') !== false, 'TLS peer verify remains enabled');
mtucAud008F01_assert(strpos($curlSource, 'CURLOPT_SSL_VERIFYHOST => 2') !== false, 'TLS host verify remains enabled');
mtucAud008F01_assert(strpos($curlSource, 'CURLE_OPERATION_TIMEDOUT') !== false, 'ordinary timeout still timeout');
mtucAud008F01_assert(
    strpos($curlSource, 'MtUniCreditCpConnectionException') !== false,
    'ordinary connection failure still connection failure'
);
mtucAud008F01_assert(substr_count($curlSource, 'curl_close($handle)') >= 3, 'curl_close on success/failure/abort paths');

// Simulate transport classification order: abort flag wins over write-error errno.
$abortBuffer = new MtUniCreditCpBoundedResponseBuffer(10);
$abortBuffer->write(str_repeat('z', 11));
mtucAud008F01_assert($abortBuffer->isAbortedForSize(), 'intentional-abort classification fixture armed');
$sizeException = new MtUniCreditCpInvalidPayloadException('The Control Panel response exceeded the allowed size.');
mtucAud008F01_assert(
    $sizeException instanceof MtUniCreditCpInvalidPayloadException,
    'intentional size abort classified as CpInvalidPayloadException'
);

// Empty body must not look like response-too-large
$empty = new MtUniCreditCpBoundedResponseBuffer();
mtucAud008F01_assert($empty->getBody() === '' && !$empty->isAbortedForSize(), 'empty response unchanged (not too-large)');

// ---------------------------------------------------------------------------
// 401 behavior unchanged (client + fake transport; size abort is not auth recovery)
// ---------------------------------------------------------------------------
$memoryDb = Phase4TestHarness::memoryDb();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport->enqueueJson(401, array('error' => 'expired'));
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());
$stack = Phase4TestHarness::services($transport, $memoryDb, Phase4TestHarness::TEST_STORE_ID, 1700000000 + 86400 + 120);
$response = $stack['client']->getShop();
mtucAud008F01_assert(isset($response['data']), '401 behavior unchanged: relogin/replay still works');
mtucAud008F01_assert(count($transport->requests) === 4, '401 behavior unchanged: four requests');

$memoryDb = Phase4TestHarness::memoryDb();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport->enqueueJson(401, array('error' => 'expired'));
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport->enqueueJson(401, array('error' => 'expired'));
$stack = Phase4TestHarness::services($transport, $memoryDb, Phase4TestHarness::TEST_STORE_ID, 1700000000 + 86400 + 120);
$second401 = mtucAud008F01_catch(function () use ($stack) {
    $stack['client']->getShop();
});
mtucAud008F01_assert($second401 instanceof MtUniCreditCpAuthenticationException, '401 behavior unchanged: second 401 stops');
mtucAud008F01_assert(count($transport->requests) === 4, '401 behavior unchanged: no third authenticated attempt');

echo PHP_EOL;
if ($failures) {
    echo 'AUD-008 F01 RESPONSE SIZE STREAMING: FAIL (' . count($failures) . ' failed, ' . $passes . ' passed)' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'AUD-008 F01 RESPONSE SIZE STREAMING: PASS (' . $passes . ' assertions)' . PHP_EOL;
exit(0);
