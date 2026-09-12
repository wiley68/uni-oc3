<?php

/**
 * Manual-release defect — CP → OC3 bank-status sync after local P1.
 *
 * Reproduces: order already at bank_sent_process1; CP pushes SmartUCF operational
 * status (human label "Въвежда се - фаза 1", machine status_id = SmartUCF reqStatusCode).
 *
 * Run: php tests/phase_cp_bank_status_sync_check.php
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

$failures = array();
$passes = 0;

/**
 * Machine status_id for the reproduced CP push.
 *
 * CP SmartBankOrderStatusClient always passes SmartUCF reqStatusCode (non-empty string).
 * Human label "Въвежда се - фаза 1" is reqStatusText — not itself a status_id.
 * Zero-padded numeric form matches proven CP fixtures (05/08/10). Exact bank code for
 * this label is bank-owned passthrough; OC3 accepts ^\d{1,3}$.
 */
define('MTUC_CP_SYNC_PHASE1_STATUS_ID', '07');
define('MTUC_CP_SYNC_PHASE1_STATUS_LABEL', 'Въвежда се - фаза 1');

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucCpSync_assert($condition, $message)
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
function mtucCpSync_nonce()
{
    static $n = 0;
    $n++;

    return str_pad(dechex($n), 64, '0', STR_PAD_LEFT);
}

/**
 * Production inbound path: dispatcher + order_bank_status handler.
 *
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $stack
 * @param array<string, string> $headerOverrides
 * @return array{status: int, body: string, payload: array<string, mixed>|null}
 */
function mtucCpSync_invoke(array $payload, array $stack, array $headerOverrides = array())
{
    $rawBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($rawBody === false) {
        $rawBody = '';
    }
    $headers = Phase6TestHarness::signedHeaders($stack['secret'], $rawBody, $headerOverrides);
    $server = Phase6TestHarness::serverFromHeaders($headers);
    $server['REQUEST_METHOD'] = 'POST';
    $storeId = (int) $stack['storeId'];
    $db = $stack['db'];

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
        $result = (new MtUniCreditOrderBankStatusRepository($db))->upsertAuthorizedLocal($storeId, (int) $orderId, $statusId, $status, MtUniCreditBankStatusTransitionPolicy::SOURCE_INBOUND_CALLBACK);
        if ($result === null) {
            throw new MtUniCreditInboundApiException('Поръчката не е намерена в магазина.', 404, 'order_not_found');
        }

        return array(
            'success' => true,
            'message' => 'Банковият статус е обновен успешно.',
            'data' => $result,
        );
    };

    try {
        $result = MtUniCreditInboundApiDispatcher::dispatch(
            $handler,
            $stack['authenticator'],
            $server,
            $rawBody,
            'POST'
        );
        $encoded = MtUniCreditInboundApiDispatcher::encodeResponse($result, 200);
    } catch (MtUniCreditInboundApiException $exception) {
        $encoded = MtUniCreditInboundApiDispatcher::encodeException($exception);
    } catch (Exception $exception) {
        $encoded = MtUniCreditInboundApiDispatcher::encodeResponse(array(
            'success' => false,
            'message' => $exception->getMessage(),
            'error' => 'internal_error',
        ), 500);
    }

    $decoded = json_decode($encoded['body'], true);

    return array(
        'status' => (int) $encoded['status'],
        'body' => (string) $encoded['body'],
        'payload' => is_array($decoded) ? $decoded : null,
    );
}

// ---------------------------------------------------------------------------
// Positive: after local P1, CP operational callback persists + admin reads it
// ---------------------------------------------------------------------------
$stack = Phase6TestHarness::stack();
$orderId = 9201;
$stack['memoryDb']->seedOrder($orderId, $stack['storeId'], MtUniCreditConstants::EXTENSION_CODE);
$repo = new MtUniCreditOrderBankStatusRepository($stack['db']);

$seed = $repo->upsertAuthorizedLocal($stack['storeId'], (int) $orderId, MtUniCreditBankStatus::SENT_PROCESS1, MtUniCreditBankStatus::LABEL_SENT_PROCESS1, MtUniCreditBankStatusTransitionPolicy::SOURCE_LOCAL_LIFECYCLE);
mtucCpSync_assert(
    $seed !== null && !empty($seed['applied'])
        && (string) $seed['status_id'] === MtUniCreditBankStatus::SENT_PROCESS1,
    'seed: local P1 submission terminal established'
);

$cpPayload = array(
    'unicid' => $stack['unicid'],
    'order_id' => (string) $orderId,
    'status' => MTUC_CP_SYNC_PHASE1_STATUS_LABEL,
    'status_id' => MTUC_CP_SYNC_PHASE1_STATUS_ID,
);
$accepted = mtucCpSync_invoke($cpPayload, $stack, array('X-UniPayment-Nonce' => mtucCpSync_nonce()));
mtucCpSync_assert(
    $accepted['status'] === 200
        && !empty($accepted['payload']['success'])
        && !empty($accepted['payload']['data']['applied'])
        && (string) $accepted['payload']['data']['status_id'] === MTUC_CP_SYNC_PHASE1_STATUS_ID
        && (string) $accepted['payload']['data']['status'] === MTUC_CP_SYNC_PHASE1_STATUS_LABEL,
    'positive: CP callback accepted and applied after P1'
);

$row = $repo->findByOrderId($stack['storeId'], $orderId);
mtucCpSync_assert(
    $row !== null
        && (string) $row['status_id'] === MTUC_CP_SYNC_PHASE1_STATUS_ID
        && (string) $row['status_label'] === MTUC_CP_SYNC_PHASE1_STATUS_LABEL,
    'positive: persistence stores SmartUCF operational status'
);

$labels = (new MtUniCreditFinancingPresentationRepository($stack['db']))->bankStatusLabelsForOrders(
    array(array('order_id' => $orderId, 'store_id' => $stack['storeId'])),
    $stack['storeId']
);
mtucCpSync_assert(
    isset($labels[0]) && $labels[0] === MTUC_CP_SYNC_PHASE1_STATUS_LABEL,
    'positive: admin presenter reads new operational label'
);
mtucCpSync_assert(
    MtUniCreditBankStatus::SENT_PROCESS1 === 'bank_sent_process1'
        && MtUniCreditBankStatus::LABEL_SENT_PROCESS1 === 'Изпратен Банка - Процес 1',
    'positive: named P1 submission vocabulary constants unchanged'
);

// ---------------------------------------------------------------------------
// Idempotent duplicate callback
// ---------------------------------------------------------------------------
$dup = mtucCpSync_invoke($cpPayload, $stack, array('X-UniPayment-Nonce' => mtucCpSync_nonce()));
$rowDup = $repo->findByOrderId($stack['storeId'], $orderId);
mtucCpSync_assert(
    $dup['status'] === 200
        && !empty($dup['payload']['success'])
        && empty($dup['payload']['data']['applied'])
        && (string) $dup['payload']['data']['status_id'] === MTUC_CP_SYNC_PHASE1_STATUS_ID
        && $rowDup !== null
        && (string) $rowDup['status_id'] === MTUC_CP_SYNC_PHASE1_STATUS_ID
        && (string) $rowDup['status_label'] === MTUC_CP_SYNC_PHASE1_STATUS_LABEL,
    'idempotent: duplicate callback accepted without change'
);

// ---------------------------------------------------------------------------
// Invalid / disallowed: unsupported status + stale named regression over P1
// ---------------------------------------------------------------------------
$bad = mtucCpSync_invoke(array(
    'unicid' => $stack['unicid'],
    'order_id' => (string) $orderId,
    'status' => 'bogus',
    'status_id' => 'not_a_real_status',
), $stack, array('X-UniPayment-Nonce' => mtucCpSync_nonce()));
mtucCpSync_assert(
    $bad['status'] === 400
        && isset($bad['payload']['error'])
        && $bad['payload']['error'] === 'unsupported_status',
    'invalid: unsupported status_id rejected'
);
$rowAfterBad = $repo->findByOrderId($stack['storeId'], $orderId);
mtucCpSync_assert(
    $rowAfterBad !== null
        && (string) $rowAfterBad['status_id'] === MTUC_CP_SYNC_PHASE1_STATUS_ID,
    'invalid: persistence unchanged after unsupported status'
);

$staleStack = Phase6TestHarness::stack();
$staleOrder = 9202;
$staleStack['memoryDb']->seedOrder($staleOrder, $staleStack['storeId'], MtUniCreditConstants::EXTENSION_CODE);
$staleRepo = new MtUniCreditOrderBankStatusRepository($staleStack['db']);
$staleRepo->upsertAuthorizedLocal($staleStack['storeId'], (int) $staleOrder, MtUniCreditBankStatus::SENT_PROCESS1, MtUniCreditBankStatus::LABEL_SENT_PROCESS1, MtUniCreditBankStatusTransitionPolicy::SOURCE_LOCAL_LIFECYCLE);
$stale = mtucCpSync_invoke(array(
    'unicid' => $staleStack['unicid'],
    'order_id' => (string) $staleOrder,
    'status' => 'Създаден в КП Банка',
    'status_id' => MtUniCreditBankStatus::CP_SENT,
), $staleStack, array('X-UniPayment-Nonce' => mtucCpSync_nonce()));
$rowStale = $staleRepo->findByOrderId($staleStack['storeId'], $staleOrder);
mtucCpSync_assert(
    $stale['status'] === 200
        && empty($stale['payload']['data']['applied'])
        && $rowStale !== null
        && (string) $rowStale['status_id'] === MtUniCreditBankStatus::SENT_PROCESS1,
    'stale: named cp_sent does not regress durable P1'
);

// ---------------------------------------------------------------------------
// Multistore: store A updates; store B sibling order unchanged; cross-store denied
// ---------------------------------------------------------------------------
$shared = new Phase2MemoryDb();
$stackA = Phase6TestHarness::stack($shared, Phase6TestHarness::STORE_A);
$stackB = Phase6TestHarness::stack($shared, Phase6TestHarness::STORE_B);
$orderA = 9301;
$orderB = 9302;
$shared->seedOrder($orderA, $stackA['storeId'], MtUniCreditConstants::EXTENSION_CODE);
$shared->seedOrder($orderB, $stackB['storeId'], MtUniCreditConstants::EXTENSION_CODE);

$repoA = new MtUniCreditOrderBankStatusRepository($stackA['db']);
$repoB = new MtUniCreditOrderBankStatusRepository($stackB['db']);
$repoA->upsertAuthorizedLocal($stackA['storeId'], (int) $orderA, MtUniCreditBankStatus::SENT_PROCESS1, MtUniCreditBankStatus::LABEL_SENT_PROCESS1, MtUniCreditBankStatusTransitionPolicy::SOURCE_LOCAL_LIFECYCLE);
$repoB->upsertAuthorizedLocal($stackB['storeId'], (int) $orderB, MtUniCreditBankStatus::SENT_PROCESS1, MtUniCreditBankStatus::LABEL_SENT_PROCESS1, MtUniCreditBankStatusTransitionPolicy::SOURCE_LOCAL_LIFECYCLE);

$ms = mtucCpSync_invoke(array(
    'unicid' => $stackA['unicid'],
    'order_id' => (string) $orderA,
    'status' => MTUC_CP_SYNC_PHASE1_STATUS_LABEL,
    'status_id' => MTUC_CP_SYNC_PHASE1_STATUS_ID,
), $stackA, array('X-UniPayment-Nonce' => mtucCpSync_nonce()));
$rowA = $repoA->findByOrderId($stackA['storeId'], $orderA);
$rowB = $repoB->findByOrderId($stackB['storeId'], $orderB);
mtucCpSync_assert(
    $ms['status'] === 200
        && !empty($ms['payload']['data']['applied'])
        && $rowA !== null
        && (string) $rowA['status_id'] === MTUC_CP_SYNC_PHASE1_STATUS_ID
        && $rowB !== null
        && (string) $rowB['status_id'] === MtUniCreditBankStatus::SENT_PROCESS1,
    'multistore: store A updated; store B sibling unchanged'
);

$cross = mtucCpSync_invoke(array(
    'unicid' => $stackB['unicid'],
    'order_id' => (string) $orderA,
    'status' => MTUC_CP_SYNC_PHASE1_STATUS_LABEL,
    'status_id' => MTUC_CP_SYNC_PHASE1_STATUS_ID,
), $stackB, array('X-UniPayment-Nonce' => mtucCpSync_nonce()));
$rowAAfterCross = $repoA->findByOrderId($stackA['storeId'], $orderA);
mtucCpSync_assert(
    $cross['status'] === 404
        && $rowAAfterCross !== null
        && (string) $rowAAfterCross['status_id'] === MTUC_CP_SYNC_PHASE1_STATUS_ID,
    'multistore: store B cannot mutate store A order'
);

// ---------------------------------------------------------------------------
// Auth / replay regressions (same matrix pattern as Phase 11.5A)
// ---------------------------------------------------------------------------
$authStack = Phase6TestHarness::stack();
$authStack['memoryDb']->seedOrder(9401, $authStack['storeId'], MtUniCreditConstants::EXTENSION_CODE);
$authBody = array(
    'unicid' => $authStack['unicid'],
    'order_id' => '9401',
    'status' => MTUC_CP_SYNC_PHASE1_STATUS_LABEL,
    'status_id' => MTUC_CP_SYNC_PHASE1_STATUS_ID,
);
$badSig = mtucCpSync_invoke(
    $authBody,
    $authStack,
    array(
        'X-UniPayment-Nonce' => mtucCpSync_nonce(),
        'X-UniPayment-Signature' => str_repeat('0', 64),
    )
);
mtucCpSync_assert($badSig['status'] === 401, 'auth: bad HMAC rejected');

$replayNonce = mtucCpSync_nonce();
$first = mtucCpSync_invoke($authBody, $authStack, array('X-UniPayment-Nonce' => $replayNonce));
$replay = mtucCpSync_invoke($authBody, $authStack, array('X-UniPayment-Nonce' => $replayNonce));
mtucCpSync_assert($first['status'] === 200, 'auth: first signed request accepted');
mtucCpSync_assert($replay['status'] === 401, 'auth: duplicate nonce rejected');

echo PHP_EOL . 'CP bank-status sync checks: ' . $passes . ' passed';
if ($failures) {
    echo ', ' . count($failures) . ' failed' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo ', 0 failed' . PHP_EOL;
exit(0);
