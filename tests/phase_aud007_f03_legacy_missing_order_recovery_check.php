<?php

/**
 * AUD-007 F03 — legacy missing-order recovery must fail closed.
 * Run: php tests/phase_aud007_f03_legacy_missing_order_recovery_check.php
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
function mtucAud007F03_assert($condition, $message)
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
if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
}

require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';
require_once __DIR__ . '/support/phase4_harness.php';
require_once __DIR__ . '/support/phase5_harness.php';
require_once __DIR__ . '/support/phase7_harness.php';
require_once __DIR__ . '/support/phase9_harness.php';

mtucAud007F03_assert(mtuc_phase0_network_isolation_active(), 'isolation: offline guard active');

/**
 * @param array<string, mixed> $stack
 * @param int $orderId
 * @param string $opHash
 * @param string $state
 * @return array<string, mixed>
 */
function mtucAud007F03_seedAttempt(array $stack, $orderId, $opHash, $state)
{
    $attempt = $stack['attempts']->findOrCreateAttempt(
        $stack['storeId'],
        (int) $orderId,
        Phase4TestHarness::TEST_UNICID,
        $opHash,
        hash('sha256', 'f03-sel|' . $orderId),
        hash('sha256', 'f03-fp|' . $orderId),
        MtUniCreditOperationEntryPoint::PRODUCT
    );
    $attemptId = (int) $attempt['attempt_id'];
    if ($state === MtUniCreditFinancingAttemptState::ORDER_CREATED) {
        return $stack['attempts']->findById($attemptId);
    }
    if ($state === MtUniCreditFinancingAttemptState::CP_SUBMITTING) {
        $stack['attempts']->transitionFromStates(
            $attemptId,
            array(MtUniCreditFinancingAttemptState::ORDER_CREATED),
            MtUniCreditFinancingAttemptState::CP_SUBMITTING
        );
    } elseif ($state === MtUniCreditFinancingAttemptState::CP_CREATED) {
        $stack['attempts']->persistControlPanelOrderId($attemptId, 700000 + (int) $orderId);
        $stack['attempts']->transitionFromStates(
            $attemptId,
            array(MtUniCreditFinancingAttemptState::ORDER_CREATED),
            MtUniCreditFinancingAttemptState::CP_CREATED
        );
    } elseif ($state === MtUniCreditFinancingAttemptState::CP_FAILED_RETRYABLE) {
        $stack['attempts']->persistFailure(
            $attemptId,
            MtUniCreditControlPanelErrorClass::VALIDATION_FAILED,
            MtUniCreditFinancingAttemptState::CP_FAILED_RETRYABLE
        );
    } elseif ($state === MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN) {
        $stack['attempts']->persistFailure(
            $attemptId,
            MtUniCreditControlPanelErrorClass::RECOVERY_FAILED,
            MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN
        );
    }

    return $stack['attempts']->findById($attemptId);
}

/**
 * @param array<string, mixed> $stack
 * @param int $missingOrderId
 * @param string|null $attemptState null = no attempt
 * @param string|null $attemptOpHash null = use live H
 * @param int|null $wrongStoreId
 * @return array<string, mixed>
 */
function mtucAud007F03_runLegacyMissing(array $stack, $missingOrderId, $attemptState = null, $attemptOpHash = null, $wrongStoreId = null)
{
    $add = 0;
    $input = Phase9TestHarness::productStorefrontInput($stack, 99001);
    $selectionHash = MtUniCreditStorefrontOperationIdentity::productHash(
        (int) $stack['storeId'],
        42,
        array(7),
        1,
        'BGN'
    );
    $opHash = MtUniCreditStorefrontApplicationToken::bindKey(
        $selectionHash,
        (string) $input['application_token']
    );
    if ($attemptState !== null) {
        $hashForAttempt = $attemptOpHash !== null ? (string) $attemptOpHash : $opHash;
        mtucAud007F03_seedAttempt($stack, $missingOrderId, $hashForAttempt, $attemptState);
    }
    $input['session'][MtUniCreditStorefrontFinancingSubmissionService::SESSION_ORDER_BIND_KEY] = array(
        $opHash => (int) $missingOrderId,
    );
    $input['load_order'] = function ($orderId) use ($stack, $missingOrderId, $wrongStoreId) {
        if ((int) $orderId !== (int) $missingOrderId) {
            return Phase7TestHarness::orderRow((int) $orderId, (int) $stack['storeId']);
        }
        if ($wrongStoreId !== null) {
            return Phase7TestHarness::orderRow((int) $orderId, (int) $wrongStoreId);
        }

        return null;
    };
    $input['add_order'] = function () use (&$add, $stack) {
        $add++;
        $id = 88000 + $add;
        $stack['memoryDb']->seedOrder($id, $stack['storeId'], MtUniCreditConstants::EXTENSION_CODE);

        return $id;
    };
    $result = $stack['storefront']->submit($input);

    return array(
        'result' => $result,
        'add' => $add,
        'opHash' => $opHash,
        'input' => $input,
        'cp' => Phase7TestHarness::countOrderPosts($stack['transport']),
        'smart' => Phase9TestHarness::smartUcfCallCount($stack['smartUcfProbe']),
    );
}

// ---------------------------------------------------------------------------
// Primary: missing A + cp_outcome_unknown
// ---------------------------------------------------------------------------
$transportU = new Phase4FakeCpHttpTransport();
$stackU = Phase9TestHarness::stack($transportU, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 0));
$orderA = 30101;
$runU = mtucAud007F03_runLegacyMissing(
    $stackU,
    $orderA,
    MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN
);
$attemptU = $stackU['attempts']->findByStoreOrder($stackU['storeId'], $orderA);
mtucAud007F03_assert(empty($runU['result']['success']), 'unknown: fail closed');
mtucAud007F03_assert($runU['add'] === 0, 'unknown: addOrder = 0');
mtucAud007F03_assert($runU['cp'] === 0, 'unknown: CP create = 0');
mtucAud007F03_assert($runU['smart'] === 0, 'unknown: SmartUCF = 0');
mtucAud007F03_assert(
    is_array($attemptU)
        && (string) $attemptU['state'] === MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN,
    'unknown: attempt A preserved unresolved'
);
mtucAud007F03_assert(
    isset($runU['result']['error'])
        && (string) $runU['result']['error'] === 'legacy_binding_unresolved',
    'unknown: error=legacy_binding_unresolved'
);

// ---------------------------------------------------------------------------
// cp_submitting
// ---------------------------------------------------------------------------
$transportS = new Phase4FakeCpHttpTransport();
$stackS = Phase9TestHarness::stack($transportS, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 0));
$runS = mtucAud007F03_runLegacyMissing(
    $stackS,
    30102,
    MtUniCreditFinancingAttemptState::CP_SUBMITTING
);
mtucAud007F03_assert(empty($runS['result']['success']), 'submitting: fail closed');
mtucAud007F03_assert($runS['add'] === 0 && $runS['cp'] === 0 && $runS['smart'] === 0, 'submitting: no side effects');
$attemptS = $stackS['attempts']->findByStoreOrder($stackS['storeId'], 30102);
mtucAud007F03_assert(
    is_array($attemptS)
        && (string) $attemptS['state'] === MtUniCreditFinancingAttemptState::CP_SUBMITTING,
    'submitting: attempt preserved'
);

// ---------------------------------------------------------------------------
// Completed attempt (cp_created)
// ---------------------------------------------------------------------------
$transportC = new Phase4FakeCpHttpTransport();
$stackC = Phase9TestHarness::stack($transportC, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 0));
$runC = mtucAud007F03_runLegacyMissing(
    $stackC,
    30103,
    MtUniCreditFinancingAttemptState::CP_CREATED
);
mtucAud007F03_assert(empty($runC['result']['success']), 'completed: fail closed');
mtucAud007F03_assert($runC['add'] === 0 && $runC['cp'] === 0 && $runC['smart'] === 0, 'completed: no side effects');

// ---------------------------------------------------------------------------
// Definitive failed attempt
// ---------------------------------------------------------------------------
$transportF = new Phase4FakeCpHttpTransport();
$stackF = Phase9TestHarness::stack($transportF, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 0));
$runF = mtucAud007F03_runLegacyMissing(
    $stackF,
    30104,
    MtUniCreditFinancingAttemptState::CP_FAILED_RETRYABLE
);
mtucAud007F03_assert(empty($runF['result']['success']), 'failed: fail closed');
mtucAud007F03_assert($runF['add'] === 0 && $runF['cp'] === 0 && $runF['smart'] === 0, 'failed: no side effects');

// ---------------------------------------------------------------------------
// Missing A + no attempt
// ---------------------------------------------------------------------------
$transportN = new Phase4FakeCpHttpTransport();
$stackN = Phase9TestHarness::stack($transportN, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 0));
$runN = mtucAud007F03_runLegacyMissing($stackN, 30105, null);
mtucAud007F03_assert(empty($runN['result']['success']), 'no-attempt: fail closed');
mtucAud007F03_assert($runN['add'] === 0 && $runN['cp'] === 0 && $runN['smart'] === 0, 'no-attempt: no side effects');

// Conflicting attempt hash (order present)
$transportX2 = new Phase4FakeCpHttpTransport();
$stackX2 = Phase9TestHarness::stack($transportX2, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 0));
$orderX = 30116;
$addX = 0;
$foreignHash = hash('sha256', 'foreign-operation-x');
$inputX = Phase9TestHarness::productStorefrontInput($stackX2, 99002);
$selX = MtUniCreditStorefrontOperationIdentity::productHash(
    (int) $stackX2['storeId'],
    42,
    array(7),
    1,
    'BGN'
);
$opHX = MtUniCreditStorefrontApplicationToken::bindKey($selX, (string) $inputX['application_token']);
$stackX2['memoryDb']->seedOrder($orderX, $stackX2['storeId'], MtUniCreditConstants::EXTENSION_CODE);
mtucAud007F03_seedAttempt(
    $stackX2,
    $orderX,
    $foreignHash,
    MtUniCreditFinancingAttemptState::ORDER_CREATED
);
$inputX['session'][MtUniCreditStorefrontFinancingSubmissionService::SESSION_ORDER_BIND_KEY] = array(
    $opHX => $orderX,
);
$inputX['load_order'] = function ($id) use ($stackX2) {
    return Phase7TestHarness::orderRow((int) $id, (int) $stackX2['storeId']);
};
$inputX['add_order'] = function () use (&$addX) {
    $addX++;

    return 77777;
};
$resultX = $stackX2['storefront']->submit($inputX);
mtucAud007F03_assert(empty($resultX['success']), 'conflict: reject');
mtucAud007F03_assert($addX === 0, 'conflict: addOrder = 0');
mtucAud007F03_assert(Phase7TestHarness::countOrderPosts($transportX2) === 0, 'conflict: CP = 0');
mtucAud007F03_assert(Phase9TestHarness::smartUcfCallCount($stackX2['smartUcfProbe']) === 0, 'conflict: SmartUCF = 0');
mtucAud007F03_assert(
    isset($resultX['error']) && (string) $resultX['error'] === 'conflict',
    'conflict: error=conflict'
);

// ---------------------------------------------------------------------------
// Wrong-store legacy binding
// ---------------------------------------------------------------------------
$transportW = new Phase4FakeCpHttpTransport();
$stackW = Phase9TestHarness::stack($transportW, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 0));
$runW = mtucAud007F03_runLegacyMissing($stackW, 30107, null, null, Phase5TestHarness::STORE_B);
mtucAud007F03_assert(empty($runW['result']['success']), 'wrong-store: reject');
mtucAud007F03_assert($runW['add'] === 0 && $runW['cp'] === 0 && $runW['smart'] === 0, 'wrong-store: no side effects');

// ---------------------------------------------------------------------------
// Valid legacy adoption + durable claim bind
// ---------------------------------------------------------------------------
$transportA = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportA);
$stackA = Phase9TestHarness::stack($transportA, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 0));
$orderAdopt = 30201;
$addA = 0;
$inputA = Phase9TestHarness::productStorefrontInput($stackA, $orderAdopt);
$selA = MtUniCreditStorefrontOperationIdentity::productHash(
    (int) $stackA['storeId'],
    42,
    array(7),
    1,
    'BGN'
);
$opHA = MtUniCreditStorefrontApplicationToken::bindKey($selA, (string) $inputA['application_token']);
$stackA['memoryDb']->seedOrder($orderAdopt, $stackA['storeId'], MtUniCreditConstants::EXTENSION_CODE);
$inputA['session'][MtUniCreditStorefrontFinancingSubmissionService::SESSION_ORDER_BIND_KEY] = array(
    $opHA => $orderAdopt,
);
$inputA['load_order'] = function ($id) use ($stackA) {
    return Phase7TestHarness::orderRow((int) $id, (int) $stackA['storeId']);
};
$inputA['add_order'] = function () use (&$addA) {
    $addA++;

    return 99901;
};
$resultA = $stackA['storefront']->submit($inputA);
mtucAud007F03_assert(!empty($resultA['success']), 'adoption: success');
mtucAud007F03_assert($addA === 0, 'adoption: addOrder = 0');
mtucAud007F03_assert((int) $resultA['order_id'] === $orderAdopt, 'adoption: reuse A');
$claimsA = new MtUniCreditOperationOrderClaimRepository($stackA['db'], $stackA['clock']);
$claimA = $claimsA->find($stackA['storeId'], MtUniCreditOperationEntryPoint::PRODUCT, $opHA);
mtucAud007F03_assert(is_array($claimA), 'adoption: claim row present');
mtucAud007F03_assert(
    is_array($claimA)
        && (int) $claimA['store_id'] === (int) $stackA['storeId']
        && (string) $claimA['entry_point'] === MtUniCreditOperationEntryPoint::PRODUCT
        && hash_equals((string) $claimA['operation_key_hash'], $opHA)
        && (int) $claimA['order_id'] === $orderAdopt
        && (string) $claimA['state'] === MtUniCreditOperationOrderClaimRepository::STATE_ORDER_CREATED,
    'adoption: durable claim bound to A'
);

// Replay without session order bind — durable claim recovers A
$inputA2 = $inputA;
$inputA2['session'] = isset($resultA['session']) ? $resultA['session'] : array();
unset($inputA2['session'][MtUniCreditStorefrontFinancingSubmissionService::SESSION_ORDER_BIND_KEY]);
$inputA2['application_token'] = $inputA['application_token'];
$inputA2['add_order'] = function () use (&$addA) {
    $addA++;

    return 99902;
};
$resultA2 = $stackA['storefront']->submit($inputA2);
mtucAud007F03_assert(!empty($resultA2['success']), 'adoption replay: success');
mtucAud007F03_assert($addA === 0, 'adoption replay: addOrder = 0');
mtucAud007F03_assert((int) $resultA2['order_id'] === $orderAdopt, 'adoption replay: durable recovers A');

// ---------------------------------------------------------------------------
// Fresh token after failed stale H
// ---------------------------------------------------------------------------
$transportT = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportT);
$stackT = Phase9TestHarness::stack($transportT, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 0));
$runT = mtucAud007F03_runLegacyMissing(
    $stackT,
    30108,
    MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN
);
mtucAud007F03_assert(empty($runT['result']['success']), 'fresh-prep: stale H blocked');
$inputT2 = Phase9TestHarness::productStorefrontInput($stackT, 30301);
$inputT2 = Phase9TestHarness::rebindProductApplicationToken($inputT2);
$addT = 0;
$inputT2['add_order'] = function () use (&$addT, $stackT) {
    $addT++;
    $stackT['memoryDb']->seedOrder(30301, $stackT['storeId'], MtUniCreditConstants::EXTENSION_CODE);

    return 30301;
};
$resultT2 = $stackT['storefront']->submit($inputT2);
mtucAud007F03_assert(!empty($resultT2['success']), 'fresh-token: new application allowed');
mtucAud007F03_assert($addT === 1, 'fresh-token: addOrder = 1 for H2');
mtucAud007F03_assert((int) $resultT2['order_id'] === 30301, 'fresh-token: new order');

echo PHP_EOL . 'MATRIX F03 call counts:' . PHP_EOL;
echo 'unknown: add=' . $runU['add'] . ' cp=' . $runU['cp'] . ' smart=' . $runU['smart'] . PHP_EOL;
echo 'submitting: add=' . $runS['add'] . ' cp=' . $runS['cp'] . ' smart=' . $runS['smart'] . PHP_EOL;
echo 'completed: add=' . $runC['add'] . ' cp=' . $runC['cp'] . ' smart=' . $runC['smart'] . PHP_EOL;
echo 'failed: add=' . $runF['add'] . ' cp=' . $runF['cp'] . ' smart=' . $runF['smart'] . PHP_EOL;
echo 'no-attempt: add=' . $runN['add'] . ' cp=' . $runN['cp'] . ' smart=' . $runN['smart'] . PHP_EOL;
echo 'conflict: add=' . $addX . ' cp=' . Phase7TestHarness::countOrderPosts($transportX2)
    . ' smart=' . Phase9TestHarness::smartUcfCallCount($stackX2['smartUcfProbe']) . PHP_EOL;
echo 'wrong-store: add=' . $runW['add'] . ' cp=' . $runW['cp'] . ' smart=' . $runW['smart'] . PHP_EOL;
echo 'adoption: add=' . $addA . ' (expected 0 across adopt+replay)' . PHP_EOL;

if ($failures !== array()) {
    fwrite(STDERR, 'AUD-007 F03: FAIL (' . count($failures) . ')' . PHP_EOL);
    foreach ($failures as $failure) {
        fwrite(STDERR, ' - ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo PHP_EOL . 'AUD-007 F03: PASS (' . $passes . ' passes)' . PHP_EOL;
