<?php

/**
 * AUD-008 F03 — CP status PATCH success must confirm identity and state.
 * Run: php tests/phase_aud008_f03_patch_success_confirmation_check.php
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
function mtucAud008F03_assert($condition, $message)
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
function mtucAud008F03_catch(callable $callback)
{
    try {
        $callback();

        return null;
    } catch (Exception $exception) {
        return $exception;
    }
}

/**
 * Exact CP success shape from ShopAuthController::updateOrderStatus.
 *
 * @param string $orderId
 * @param string $status
 * @param string $statusId
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function mtucAud008F03_validPatchSuccess($orderId, $status, $statusId, array $overrides = array())
{
    $payload = array(
        'success' => true,
        'message' => 'Статусът на поръчката е обновен успешно',
        'data' => array(
            'id' => 42,
            'order_id' => $orderId,
            'shop_id' => 7,
            'status' => $status,
            'status_id' => $statusId,
            'updated_at' => '2024-01-01 12:00:00',
        ),
    );

    foreach ($overrides as $key => $value) {
        if ($key === 'data' && is_array($value)) {
            $payload['data'] = array_merge($payload['data'], $value);
        } else {
            $payload[$key] = $value;
        }
    }

    return $payload;
}

/**
 * @param Phase4FakeCpHttpTransport $transport
 * @param Phase2MemoryDb|null $memoryDb
 * @return MtUniCreditControlPanelClient
 */
function mtucAud008F03_client(Phase4FakeCpHttpTransport $transport, $memoryDb = null)
{
    $stack = Phase4TestHarness::services($transport, $memoryDb);

    return $stack['client'];
}

/**
 * @param Phase4FakeCpHttpTransport $transport
 * @return int
 */
function mtucAud008F03_countMethod(Phase4FakeCpHttpTransport $transport, $method, $pathNeedle)
{
    $count = 0;
    foreach ($transport->requests as $request) {
        if (
            strtoupper((string) $request['method']) === strtoupper((string) $method)
            && strpos((string) $request['url'], (string) $pathNeedle) !== false
        ) {
            $count++;
        }
    }

    return $count;
}

$root = MTUC_PHASE0_ROOT;
if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
}

require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';
require_once __DIR__ . '/support/phase4_harness.php';

mtucAud008F03_assert(mtuc_phase0_network_isolation_active(), 'isolation: offline guard active');

$orderId = '100';
$status = 'Изпратен Банка - Процес 1';
$statusId = 'bank_sent_process1';

// ---------------------------------------------------------------------------
// Exact valid PATCH success
// ---------------------------------------------------------------------------
$memoryDb = Phase4TestHarness::memoryDb();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport->enqueueJson(200, mtucAud008F03_validPatchSuccess($orderId, $status, $statusId));
$client = mtucAud008F03_client($transport, $memoryDb);
$ok = mtucAud008F03_catch(function () use ($client, $orderId, $status, $statusId) {
    $client->updateOrderStatus($orderId, $status, $statusId);
});
mtucAud008F03_assert($ok === null, 'exact valid PATCH success accepted');
mtucAud008F03_assert(mtucAud008F03_countMethod($transport, 'PATCH', '/orders/status') === 1, 'valid PATCH call count = 1');

// ---------------------------------------------------------------------------
// Malformed nominal success fixtures
// ---------------------------------------------------------------------------
$rejectFixtures = array(
    'success=true without data rejected' => array('success' => true),
    'empty data rejected' => array('success' => true, 'data' => array()),
    'missing order_id rejected' => mtucAud008F03_validPatchSuccess($orderId, $status, $statusId, array(
        'data' => array('order_id' => null),
    )),
    'wrong order_id rejected' => mtucAud008F03_validPatchSuccess('101', $status, $statusId),
    'missing status rejected' => mtucAud008F03_validPatchSuccess($orderId, $status, $statusId, array(
        'data' => array('status' => null),
    )),
    'wrong status rejected' => mtucAud008F03_validPatchSuccess($orderId, 'bank_sent_process2', $statusId),
    'status_id mismatch rejected' => mtucAud008F03_validPatchSuccess($orderId, $status, 'bank_sent_process2'),
    'missing status_id rejected' => array(
        'success' => true,
        'data' => array(
            'id' => 42,
            'order_id' => $orderId,
            'shop_id' => 7,
            'status' => $status,
            'updated_at' => '2024-01-01 12:00:00',
        ),
    ),
    'order_id wrong type rejected' => mtucAud008F03_validPatchSuccess($orderId, $status, $statusId, array(
        'data' => array('order_id' => 'abc'),
    )),
    'status wrong type rejected' => array(
        'success' => true,
        'data' => array(
            'order_id' => $orderId,
            'status' => array(),
            'status_id' => $statusId,
        ),
    ),
    'status_id wrong type rejected' => array(
        'success' => true,
        'data' => array(
            'order_id' => $orderId,
            'status' => $status,
            'status_id' => array('x' => 1),
        ),
    ),
    'data string rejected' => array(
        'success' => true,
        'data' => 'not-an-object',
    ),
    'success=1 rejected' => mtucAud008F03_validPatchSuccess($orderId, $status, $statusId, array(
        'success' => 1,
    )),
    'success="true" rejected' => mtucAud008F03_validPatchSuccess($orderId, $status, $statusId, array(
        'success' => 'true',
    )),
);

// Fix order_id wrong type fixture: use non-matching string "abc" vs submitted "100"
// Already set. Also add numeric order_id type rejection:
$rejectFixtures['order_id numeric type rejected'] = array(
    'success' => true,
    'data' => array(
        'order_id' => 100,
        'status' => $status,
        'status_id' => $statusId,
    ),
);

foreach ($rejectFixtures as $label => $body) {
    // Special-case: "missing order_id" with null merge may leave key — unset properly
    if ($label === 'missing order_id rejected') {
        $body = mtucAud008F03_validPatchSuccess($orderId, $status, $statusId);
        unset($body['data']['order_id']);
    }
    if ($label === 'missing status rejected') {
        $body = mtucAud008F03_validPatchSuccess($orderId, $status, $statusId);
        unset($body['data']['status']);
    }
    if ($label === 'order_id wrong type rejected') {
        // Keep intentional junk string that does not match submitted order id either,
        // but primary assert is InvalidPayload (type/identity failure).
        $body = array(
            'success' => true,
            'data' => array(
                'order_id' => 'abc',
                'status' => $status,
                'status_id' => $statusId,
            ),
        );
    }

    $memoryDb = Phase4TestHarness::memoryDb();
    $transport = new Phase4FakeCpHttpTransport();
    $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
    $transport->enqueueJson(200, $body);
    $client = mtucAud008F03_client($transport, $memoryDb);
    $exception = mtucAud008F03_catch(function () use ($client, $orderId, $status, $statusId) {
        $client->updateOrderStatus($orderId, $status, $statusId);
    });
    $isPayload = $exception instanceof MtUniCreditCpInvalidPayloadException;
    mtucAud008F03_assert($isPayload, $label);
    mtucAud008F03_assert(
        mtucAud008F03_countMethod($transport, 'PATCH', '/orders/status') === 1,
        $label . ' — PATCH calls = 1'
    );
    mtucAud008F03_assert(
        mtucAud008F03_countMethod($transport, 'POST', '/auth/login') === 1,
        $label . ' — no auth replay'
    );
}

// Malformed JSON
$memoryDb = Phase4TestHarness::memoryDb();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport->enqueue(200, '{not-json');
$client = mtucAud008F03_client($transport, $memoryDb);
$malformed = mtucAud008F03_catch(function () use ($client, $orderId, $status, $statusId) {
    $client->updateOrderStatus($orderId, $status, $statusId);
});
mtucAud008F03_assert($malformed instanceof MtUniCreditCpMalformedJsonException, 'malformed JSON rejected');
mtucAud008F03_assert(mtucAud008F03_countMethod($transport, 'PATCH', '/orders/status') === 1, 'malformed JSON — PATCH = 1');
mtucAud008F03_assert(mtucAud008F03_countMethod($transport, 'POST', '/auth/login') === 1, 'malformed JSON — no auth replay');

// Empty body / list / success missing / success false
$topLevelRejects = array(
    'empty body rejected' => '',
    'list instead of object rejected' => '[{"success":true}]',
    'success missing rejected' => json_encode(array('data' => array('order_id' => $orderId))),
    'success false rejected' => json_encode(array('success' => false, 'data' => array(
        'order_id' => $orderId,
        'status' => $status,
        'status_id' => $statusId,
    ))),
);
foreach ($topLevelRejects as $label => $body) {
    $memoryDb = Phase4TestHarness::memoryDb();
    $transport = new Phase4FakeCpHttpTransport();
    $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
    $transport->enqueue(200, $body);
    $client = mtucAud008F03_client($transport, $memoryDb);
    $exception = mtucAud008F03_catch(function () use ($client, $orderId, $status, $statusId) {
        $client->updateOrderStatus($orderId, $status, $statusId);
    });
    mtucAud008F03_assert(
        $exception instanceof MtUniCreditCpInvalidPayloadException
            || $exception instanceof MtUniCreditCpMalformedJsonException,
        $label
    );
    mtucAud008F03_assert(
        mtucAud008F03_countMethod($transport, 'PATCH', '/orders/status') === 1,
        $label . ' — no PATCH replay'
    );
}

// Malformed 2xx must not trigger auth recovery / PATCH replay
$memoryDb = Phase4TestHarness::memoryDb();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport->enqueueJson(200, array('success' => true));
$client = mtucAud008F03_client($transport, $memoryDb);
$malformedSuccess = mtucAud008F03_catch(function () use ($client, $orderId, $status, $statusId) {
    $client->updateOrderStatus($orderId, $status, $statusId);
});
mtucAud008F03_assert($malformedSuccess instanceof MtUniCreditCpInvalidPayloadException, 'malformed 2xx causes controlled invalid-response failure');
mtucAud008F03_assert(mtucAud008F03_countMethod($transport, 'PATCH', '/orders/status') === 1, 'malformed 2xx PATCH calls = 1');
mtucAud008F03_assert(mtucAud008F03_countMethod($transport, 'POST', '/auth/login') === 1, 'malformed 2xx login calls = 1 (no additional)');
mtucAud008F03_assert(mtucAud008F03_countMethod($transport, 'PATCH', '/orders/status') === 1, 'malformed 2xx PATCH replay = 0');

// ---------------------------------------------------------------------------
// 401 → relogin → PATCH success
// ---------------------------------------------------------------------------
$memoryDb = Phase4TestHarness::memoryDb();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport->enqueueJson(401, array('error' => 'expired'));
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport->enqueueJson(200, mtucAud008F03_validPatchSuccess($orderId, $status, $statusId));
$client = mtucAud008F03_client($transport, $memoryDb);
$authOk = mtucAud008F03_catch(function () use ($client, $orderId, $status, $statusId) {
    $client->updateOrderStatus($orderId, $status, $statusId);
});
mtucAud008F03_assert($authOk === null, 'valid 401→relogin→PATCH success still works');
mtucAud008F03_assert(mtucAud008F03_countMethod($transport, 'PATCH', '/orders/status') === 2, '401 success: PATCH attempted twice');
mtucAud008F03_assert(mtucAud008F03_countMethod($transport, 'POST', '/auth/login') === 2, '401 success: login + relogin');

// ---------------------------------------------------------------------------
// Second 401 stops (no third PATCH)
// ---------------------------------------------------------------------------
$memoryDb = Phase4TestHarness::memoryDb();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport->enqueueJson(401, array('error' => 'expired'));
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport->enqueueJson(401, array('error' => 'expired'));
$client = mtucAud008F03_client($transport, $memoryDb);
$second401 = mtucAud008F03_catch(function () use ($client, $orderId, $status, $statusId) {
    $client->updateOrderStatus($orderId, $status, $statusId);
});
mtucAud008F03_assert($second401 instanceof MtUniCreditCpAuthenticationException, 'second 401 still stops');
mtucAud008F03_assert(mtucAud008F03_countMethod($transport, 'PATCH', '/orders/status') === 2, 'second 401: no third PATCH');
mtucAud008F03_assert(mtucAud008F03_countMethod($transport, 'POST', '/auth/login') === 2, 'second 401: login + one relogin only');

// ---------------------------------------------------------------------------
// Lifecycle confirmation: malformed PATCH must not report sync success
// ---------------------------------------------------------------------------
$memoryDb = Phase4TestHarness::memoryDb();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport->enqueueJson(200, array('success' => true));
$client = mtucAud008F03_client($transport, $memoryDb);
$lifecycleFail = mtucAud008F03_catch(function () use ($client, $orderId, $status, $statusId) {
    $client->updateOrderStatus($orderId, $status, $statusId);
});
mtucAud008F03_assert(
    $lifecycleFail instanceof MtUniCreditCpInvalidPayloadException,
    'lifecycle: malformed PATCH success does not return confirmed success'
);

echo PHP_EOL;
if ($failures) {
    echo 'AUD-008 F03 PATCH SUCCESS CONFIRMATION: FAIL (' . count($failures) . ' failed, ' . $passes . ' passed)' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'AUD-008 F03 PATCH SUCCESS CONFIRMATION: PASS (' . $passes . ' assertions)' . PHP_EOL;
exit(0);
