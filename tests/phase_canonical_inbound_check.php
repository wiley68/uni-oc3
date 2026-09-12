<?php

/**
 * Canonical inbound API: bounded body, operation binding, envelopes.
 * Run: php tests/phase_canonical_inbound_check.php
 */
require_once __DIR__ . '/bootstrap.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DIR_STORAGE')) {
    mtuc_test_define_dir_storage('mtuc-canonical-inbound');
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
}
if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'oc_');
}

require_once $lib . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . '/support/phase2_memory_db.php';
require_once __DIR__ . '/support/phase4_harness.php';
require_once __DIR__ . '/support/phase5_harness.php';
require_once __DIR__ . '/support/phase6_harness.php';
require_once __DIR__ . '/support/phase7_harness.php';
require_once __DIR__ . '/support/canonical_harness.php';


$failures = array();
$passes = 0;

function mtucCanonIn_assert(bool $condition, string $message): void
{
    global $failures, $passes;
    CanonicalTestHarness::assert($condition, $message, $failures, $passes);
}

$max = MtUniCreditBoundedRawBodyReader::MAX_INBOUND_BYTES;

// Bounded reader: MAX bytes OK, MAX+1 oversized.
$streamOk = fopen('php://memory', 'r+b');
fwrite($streamOk, str_repeat('a', $max));
rewind($streamOk);
$readOk = MtUniCreditBoundedRawBodyReader::read($streamOk, $max);
fclose($streamOk);
mtucCanonIn_assert($readOk['oversized'] === false && strlen($readOk['body']) === $max, 'body at MAX accepted');

$streamOver = fopen('php://memory', 'r+b');
fwrite($streamOver, str_repeat('b', $max + 1));
rewind($streamOver);
$readOver = MtUniCreditBoundedRawBodyReader::read($streamOver, $max);
fclose($streamOver);
mtucCanonIn_assert($readOver['oversized'] === true && $readOver['body'] === '', 'body at MAX+1 oversized');

$hintEmpty = MtUniCreditBoundedRawBodyReader::readPhpInput(array(
    'CONTENT_LENGTH' => (string) ($max + 1),
), $max);
// CLI php://input is empty: inflated Content-Length must NOT force oversized.
mtucCanonIn_assert(
    $hintEmpty['oversized'] === false && $hintEmpty['body'] === '',
    'inflated CONTENT_LENGTH alone does not force oversized (actual bytes decide)'
);

$noCl = MtUniCreditBoundedRawBodyReader::readPhpInput(array(), $max);
mtucCanonIn_assert($noCl['oversized'] === false, 'absent CONTENT_LENGTH is safe');

// Misleading Content-Length vs actual stream size: actual bytes decide.
$streamMaxCl = fopen('php://memory', 'r+b');
fwrite($streamMaxCl, str_repeat('c', $max));
rewind($streamMaxCl);
$readMaxDespiteCl = MtUniCreditBoundedRawBodyReader::read($streamMaxCl, $max);
fclose($streamMaxCl);
mtucCanonIn_assert(
    $readMaxDespiteCl['oversized'] === false && strlen($readMaxDespiteCl['body']) === $max,
    'MAX actual body accepted regardless of any Content-Length hint'
);

$streamOverSmallCl = fopen('php://memory', 'r+b');
fwrite($streamOverSmallCl, str_repeat('d', $max + 1));
rewind($streamOverSmallCl);
$readOverSmallCl = MtUniCreditBoundedRawBodyReader::read($streamOverSmallCl, $max);
fclose($streamOverSmallCl);
mtucCanonIn_assert(
    $readOverSmallCl['oversized'] === true,
    'MAX+1 actual body rejected even if Content-Length were understated'
);

// Envelopes: empty data encodes as {}
$success = MtUniCreditInboundApiEnvelope::forJsonEncode(
    MtUniCreditInboundApiEnvelope::success('ok'),
    true
);
$failure = MtUniCreditInboundApiEnvelope::forJsonEncode(
    MtUniCreditInboundApiEnvelope::failure('invalid_payload', 'bad'),
    false
);
$successJson = json_encode($success, JSON_UNESCAPED_UNICODE);
$failureJson = json_encode($failure, JSON_UNESCAPED_UNICODE);
mtucCanonIn_assert(is_string($successJson) && strpos($successJson, '"data":{}') !== false, 'success empty data is {}');
mtucCanonIn_assert(is_string($failureJson) && strpos($failureJson, '"data":{}') !== false, 'failure empty data is {}');
mtucCanonIn_assert($success['error'] === null, 'success error is null');
mtucCanonIn_assert($failure['error'] === 'invalid_payload', 'failure error code preserved');

$stack = Phase6TestHarness::stack();
$memoryDb = $stack['memoryDb'];
$orderId = 88001;
$memoryDb->seedOrder($orderId, $stack['storeId']);
$memoryDb->seedFinancingAttempt(array(
    'store_id' => $stack['storeId'],
    'order_id' => $orderId,
    'unicid' => $stack['unicid'],
    'state' => MtUniCreditFinancingAttemptState::CP_CREATED,
));

$basePayload = array(
    'operation' => MtUniCreditInboundApiOperations::ORDER_BANK_STATUS,
    'order_id' => (string) $orderId,
    'status_id' => MtUniCreditBankStatus::SENT_PROCESS1,
    'status' => 'Банка — изпратена процес 1',
);

$ok = CanonicalTestHarness::invokeOrderBankStatus($basePayload, $stack);
mtucCanonIn_assert($ok['status'] === 200, 'bound operation accepted');
mtucCanonIn_assert(
    is_array($ok['payload'])
        && array_key_exists('error', $ok['payload'])
        && $ok['payload']['error'] === null
        && isset($ok['payload']['data'])
        && is_array($ok['payload']['data']),
    '200 envelope has error=null and object data'
);

$missingOp = $basePayload;
unset($missingOp['operation']);
$missing = CanonicalTestHarness::invokeOrderBankStatus($missingOp, $stack);
mtucCanonIn_assert(
    $missing['status'] === 400
        && is_array($missing['payload'])
        && isset($missing['payload']['error'])
        && $missing['payload']['error'] === 'unsupported_operation',
    'missing operation → 400 unsupported_operation'
);

$wrongOp = $basePayload;
$wrongOp['operation'] = MtUniCreditInboundApiOperations::SHOP_CACHE;
$wrong = CanonicalTestHarness::invokeOrderBankStatus($wrongOp, $stack);
mtucCanonIn_assert(
    $wrong['status'] === 400
        && is_array($wrong['payload'])
        && $wrong['payload']['error'] === 'unsupported_operation',
    'wrong operation → 400 unsupported_operation'
);

$caseOp = $basePayload;
$caseOp['operation'] = 'Order-Bank-Status';
$cased = CanonicalTestHarness::invokeOrderBankStatus($caseOp, $stack);
mtucCanonIn_assert(
    $cased['status'] === 400
        && is_array($cased['payload'])
        && $cased['payload']['error'] === 'unsupported_operation',
    'wrong-case operation → 400 unsupported_operation'
);

$overBody = str_repeat('x', $max + 1);
$over = CanonicalTestHarness::invokeOrderBankStatus(
    $basePayload,
    $stack,
    array(),
    $overBody,
    array('CONTENT_LENGTH' => (string) strlen($overBody))
);
mtucCanonIn_assert(
    $over['status'] === 413
        && is_array($over['payload'])
        && $over['payload']['error'] === 'payload_too_large',
    'oversized body → 413 payload_too_large'
);

$wrongLen = CanonicalTestHarness::invokeOrderBankStatus(
    $basePayload,
    $stack,
    array(),
    null,
    array('CONTENT_LENGTH' => (string) ($max + 50))
);
mtucCanonIn_assert(
    $wrongLen['status'] === 200
        && is_array($wrongLen['payload'])
        && !empty($wrongLen['payload']['success']),
    'inflated CONTENT_LENGTH with valid small body → accept (actual bytes decide)'
);

$understated = CanonicalTestHarness::invokeOrderBankStatus(
    $basePayload,
    $stack,
    array(),
    $overBody,
    array('CONTENT_LENGTH' => '10')
);
mtucCanonIn_assert(
    $understated['status'] === 413
        && is_array($understated['payload'])
        && $understated['payload']['error'] === 'payload_too_large',
    'understated CONTENT_LENGTH with oversized actual body → 413'
);

$bogusCl = CanonicalTestHarness::invokeOrderBankStatus(
    $basePayload,
    $stack,
    array(),
    null,
    array('CONTENT_LENGTH' => 'not-a-number')
);
mtucCanonIn_assert(
    $bogusCl['status'] === 200,
    'bogus CONTENT_LENGTH ignored; valid body accepted'
);

echo PHP_EOL . 'canonical inbound: ' . $passes . ' passed, ' . count($failures) . ' failed' . PHP_EOL;
exit(count($failures) === 0 ? 0 : 1);
