<?php

/**
 * AUD-010 F03-R1 — legacy snapshot hash compatibility with structured live names.
 * Run: php tests/phase_aud010_f03_r1_legacy_snapshot_hash_check.php
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
    mtuc_test_define_dir_storage('mtuc-aud010-f03-r1');
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
require_once __DIR__ . '/support/phase7_harness.php';
require_once __DIR__ . '/support/phase9_harness.php';
require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

$failures = array();
$passes = 0;
$nextOrderId = 920001;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucAud010F03R1_assert($condition, $message)
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
 * @return int
 */
function mtucAud010F03R1_nextOrderId()
{
    global $nextOrderId;

    return $nextOrderId++;
}

/**
 * @param int $months
 * @param float $price
 * @return MtUniCreditCalculationResult
 */
function mtucAud010F03R1_calc($months, $price)
{
    $shop = mtuc4_valid_shop_snapshot();

    return (new MtUniCreditCalculator())->calculateScheme(
        $shop,
        $price,
        new MtUniCreditAvailableScheme(
            'standard',
            'KOPSTD',
            (int) $months,
            0,
            array(),
            array(
                'coeff' => 1.05,
                'interestPercent' => 5.5,
                'installmentCount' => (int) $months,
                'onlineProductCode' => 'KOPSTD',
            )
        ),
        0.0
    );
}

/**
 * @param array<string, mixed> $order
 * @param MtUniCreditCalculationResult|null $calc
 * @param string $entryPoint
 * @return array<string, mixed>
 */
function mtucAud010F03R1_liveSnapshot(array $order, $calc = null, $entryPoint = null)
{
    if ($calc === null) {
        $calc = mtucAud010F03R1_calc(12, 500.0);
    }
    if ($entryPoint === null) {
        $entryPoint = MtUniCreditOperationEntryPoint::PRODUCT;
    }
    $orderId = (int) $order['order_id'];
    $products = Phase7TestHarness::orderProducts();
    $shop = mtuc4_valid_shop_snapshot();
    $payload = (new MtUniCreditControlPanelOrderPayloadBuilder())->build(
        $orderId,
        $order,
        $products,
        $calc,
        $shop
    );
    $fingerprint = MtUniCreditControlPanelOrderPayloadBuilder::fingerprint($payload);
    $selectionHash = hash(
        'sha256',
        $calc->scheme->kopCode . '|' . $calc->scheme->months . '|' . $fingerprint
    );

    return MtUniCreditApplicationSnapshot::fromLive(
        $calc,
        $order,
        $products,
        $shop,
        $entryPoint,
        hash('sha256', 'aud010-f03-r1|' . $orderId),
        $selectionHash,
        $fingerprint
    );
}

/**
 * @param array<string, mixed> $structured
 * @return array<string, mixed>
 */
function mtucAud010F03R1_toLegacy(array $structured)
{
    $legacy = $structured;
    if (isset($legacy['customer']) && is_array($legacy['customer'])) {
        unset($legacy['customer']['firstname'], $legacy['customer']['lastname']);
    }

    return $legacy;
}

/**
 * @param string $firstname
 * @param string $lastname
 * @param int $orderId
 * @return array<string, mixed>
 */
function mtucAud010F03R1_order($firstname, $lastname, $orderId)
{
    $order = Phase7TestHarness::orderRow($orderId, Phase5TestHarness::STORE_A);
    $order['firstname'] = $firstname;
    $order['lastname'] = $lastname;

    return $order;
}

/**
 * @param string $state
 * @param array<string, mixed> $legacySnapshot
 * @param string $opHash
 * @param int $orderId
 * @return array<string, mixed>
 */
function mtucAud010F03R1_persistLegacyAttempt($state, array $legacySnapshot, $opHash, $orderId)
{
    $memory = new Phase2MemoryDb();
    $db = new MtUniCreditDbAdapter($memory, 'oc_');
    $clock = new MtUniCreditPersistenceClock(function () {
        return Phase9TestHarness::NOW;
    });
    $attempts = new MtUniCreditFinancingAttemptRepository($db, $clock);
    $attempt = $attempts->findOrCreateAttempt(
        Phase5TestHarness::STORE_A,
        $orderId,
        Phase4TestHarness::TEST_UNICID,
        $opHash,
        $legacySnapshot['selection_hash'],
        $legacySnapshot['request_fingerprint'],
        MtUniCreditOperationEntryPoint::PRODUCT
    );
    $persisted = $attempts->persistApplicationSnapshot((int) $attempt['attempt_id'], $legacySnapshot);
    if ($state !== MtUniCreditFinancingAttemptState::ORDER_CREATED) {
        $attempts->transitionFromStates(
            (int) $persisted['attempt_id'],
            array(MtUniCreditFinancingAttemptState::ORDER_CREATED),
            $state
        );
        $persisted = $attempts->findById((int) $persisted['attempt_id']);
    }

    return array(
        'attempts' => $attempts,
        'attempt' => $persisted,
        'json_before' => (string) $persisted['application_snapshot_json'],
        'hash_before' => (string) $persisted['application_snapshot_hash'],
    );
}

// -------------------------------------------------------------------------
// Hash determinism
// -------------------------------------------------------------------------
$orderDet = mtucAud010F03R1_order('Анна', 'Иванова', mtucAud010F03R1_nextOrderId());
$liveDet = mtucAud010F03R1_liveSnapshot($orderDet);
$legacyDet = mtucAud010F03R1_toLegacy($liveDet);
mtucAud010F03R1_assert(
    MtUniCreditApplicationSnapshot::hash($legacyDet)
        === MtUniCreditApplicationSnapshot::hash($legacyDet),
    'hash determinism: legacy projection stable'
);
mtucAud010F03R1_assert(
    MtUniCreditApplicationSnapshot::hash($liveDet)
        === MtUniCreditApplicationSnapshot::hash($liveDet),
    'hash determinism: new format stable'
);
mtucAud010F03R1_assert(
    MtUniCreditApplicationSnapshot::hash($liveDet)
        !== MtUniCreditApplicationSnapshot::hash($legacyDet),
    'hash: structured live differs from legacy shape'
);

// -------------------------------------------------------------------------
// order_created + identical live → accepted
// -------------------------------------------------------------------------
$oid1 = mtucAud010F03R1_nextOrderId();
$order1 = mtucAud010F03R1_order('Анна', 'Иванова', $oid1);
$live1 = mtucAud010F03R1_liveSnapshot($order1);
$legacy1 = mtucAud010F03R1_toLegacy($live1);
$ctx1 = mtucAud010F03R1_persistLegacyAttempt(
    MtUniCreditFinancingAttemptState::ORDER_CREATED,
    $legacy1,
    $live1['operation_key_hash'],
    $oid1
);
$bind1 = MtUniCreditApplicationSnapshot::bindToAttempt(
    $ctx1['attempts'],
    $ctx1['attempt'],
    $live1
);
mtucAud010F03R1_assert(!empty($bind1['ok']), 'order_created: legacy + identical live accepted');
mtucAud010F03R1_assert(
    !isset($bind1['error']) || $bind1['error'] !== 'application_drift',
    'order_created: no application_drift'
);
$after1 = $ctx1['attempts']->findById((int) $ctx1['attempt']['attempt_id']);
mtucAud010F03R1_assert(
    (string) $after1['application_snapshot_json'] === $ctx1['json_before'],
    'order_created: stored snapshot immutable'
);
mtucAud010F03R1_assert(
    (string) $after1['application_snapshot_hash'] === $ctx1['hash_before'],
    'order_created: stored hash immutable'
);
mtucAud010F03R1_assert(
    !array_key_exists('firstname', $bind1['snapshot']['customer'])
        && !array_key_exists('lastname', $bind1['snapshot']['customer']),
    'order_created: retained legacy snapshot shape'
);

// -------------------------------------------------------------------------
// cp_failed_retryable + identical live → accepted
// -------------------------------------------------------------------------
$oid2 = mtucAud010F03R1_nextOrderId();
$order2 = mtucAud010F03R1_order('Анна', 'Иванова', $oid2);
$live2 = mtucAud010F03R1_liveSnapshot($order2);
$legacy2 = mtucAud010F03R1_toLegacy($live2);
$ctx2 = mtucAud010F03R1_persistLegacyAttempt(
    MtUniCreditFinancingAttemptState::CP_FAILED_RETRYABLE,
    $legacy2,
    $live2['operation_key_hash'],
    $oid2
);
$bind2 = MtUniCreditApplicationSnapshot::bindToAttempt(
    $ctx2['attempts'],
    $ctx2['attempt'],
    $live2
);
mtucAud010F03R1_assert(!empty($bind2['ok']), 'cp_failed_retryable: legacy + identical live accepted');
mtucAud010F03R1_assert(
    (string) $ctx2['attempts']->findById((int) $ctx2['attempt']['attempt_id'])['application_snapshot_hash']
        === $ctx2['hash_before'],
    'cp_failed_retryable: stored hash immutable'
);

// -------------------------------------------------------------------------
// Compound combined name + matching structured live → accepted
// -------------------------------------------------------------------------
$oid3 = mtucAud010F03R1_nextOrderId();
$order3 = mtucAud010F03R1_order('Анна Мария', 'Иванова', $oid3);
$live3 = mtucAud010F03R1_liveSnapshot($order3);
$legacy3 = mtucAud010F03R1_toLegacy($live3);
mtucAud010F03R1_assert(
    $legacy3['customer']['name'] === 'Анна Мария Иванова',
    'compound: legacy combined name'
);
$ctx3 = mtucAud010F03R1_persistLegacyAttempt(
    MtUniCreditFinancingAttemptState::ORDER_CREATED,
    $legacy3,
    $live3['operation_key_hash'],
    $oid3
);
$bind3 = MtUniCreditApplicationSnapshot::bindToAttempt(
    $ctx3['attempts'],
    $ctx3['attempt'],
    $live3
);
mtucAud010F03R1_assert(!empty($bind3['ok']), 'compound legacy-name: compatible structured live accepted');

// -------------------------------------------------------------------------
// Firstname drift → rejected
// -------------------------------------------------------------------------
$oid4 = mtucAud010F03R1_nextOrderId();
$order4Base = mtucAud010F03R1_order('Анна', 'Иванова', $oid4);
$live4Base = mtucAud010F03R1_liveSnapshot($order4Base);
$legacy4 = mtucAud010F03R1_toLegacy($live4Base);
$ctx4 = mtucAud010F03R1_persistLegacyAttempt(
    MtUniCreditFinancingAttemptState::ORDER_CREATED,
    $legacy4,
    $live4Base['operation_key_hash'],
    $oid4
);
$order4Drift = mtucAud010F03R1_order('Мария', 'Иванова', $oid4);
$live4Drift = mtucAud010F03R1_liveSnapshot($order4Drift);
// Keep selection/op hashes aligned so only customer name differs in snapshot content.
$live4Drift['operation_key_hash'] = $live4Base['operation_key_hash'];
$live4Drift['selection_hash'] = $live4Base['selection_hash'];
$live4Drift['request_fingerprint'] = $live4Base['request_fingerprint'];
$bind4 = MtUniCreditApplicationSnapshot::bindToAttempt(
    $ctx4['attempts'],
    $ctx4['attempt'],
    $live4Drift
);
mtucAud010F03R1_assert(
    empty($bind4['ok']) && isset($bind4['error']) && $bind4['error'] === 'application_drift',
    'firstname drift: application_drift'
);

// -------------------------------------------------------------------------
// Lastname drift → rejected
// -------------------------------------------------------------------------
$oid5 = mtucAud010F03R1_nextOrderId();
$order5Base = mtucAud010F03R1_order('Анна', 'Иванова', $oid5);
$live5Base = mtucAud010F03R1_liveSnapshot($order5Base);
$legacy5 = mtucAud010F03R1_toLegacy($live5Base);
$ctx5 = mtucAud010F03R1_persistLegacyAttempt(
    MtUniCreditFinancingAttemptState::ORDER_CREATED,
    $legacy5,
    $live5Base['operation_key_hash'],
    $oid5
);
$order5Drift = mtucAud010F03R1_order('Анна', 'Петрова', $oid5);
$live5Drift = mtucAud010F03R1_liveSnapshot($order5Drift);
$live5Drift['operation_key_hash'] = $live5Base['operation_key_hash'];
$live5Drift['selection_hash'] = $live5Base['selection_hash'];
$live5Drift['request_fingerprint'] = $live5Base['request_fingerprint'];
$bind5 = MtUniCreditApplicationSnapshot::bindToAttempt(
    $ctx5['attempts'],
    $ctx5['attempt'],
    $live5Drift
);
mtucAud010F03R1_assert(
    empty($bind5['ok']) && isset($bind5['error']) && $bind5['error'] === 'application_drift',
    'lastname drift: application_drift'
);

// -------------------------------------------------------------------------
// Address drift → rejected (name matches)
// -------------------------------------------------------------------------
$oid6 = mtucAud010F03R1_nextOrderId();
$order6 = mtucAud010F03R1_order('Анна', 'Иванова', $oid6);
$live6 = mtucAud010F03R1_liveSnapshot($order6);
$legacy6 = mtucAud010F03R1_toLegacy($live6);
$ctx6 = mtucAud010F03R1_persistLegacyAttempt(
    MtUniCreditFinancingAttemptState::ORDER_CREATED,
    $legacy6,
    $live6['operation_key_hash'],
    $oid6
);
$live6Addr = $live6;
$live6Addr['customer']['address'] = 'ул. Друга 99';
$bind6 = MtUniCreditApplicationSnapshot::bindToAttempt(
    $ctx6['attempts'],
    $ctx6['attempt'],
    $live6Addr
);
mtucAud010F03R1_assert(
    empty($bind6['ok']) && isset($bind6['error']) && $bind6['error'] === 'application_drift',
    'address drift: application_drift'
);

// -------------------------------------------------------------------------
// Financial drift → rejected
// -------------------------------------------------------------------------
$oid7 = mtucAud010F03R1_nextOrderId();
$order7 = mtucAud010F03R1_order('Анна', 'Иванова', $oid7);
$live7 = mtucAud010F03R1_liveSnapshot($order7, mtucAud010F03R1_calc(12, 500.0));
$legacy7 = mtucAud010F03R1_toLegacy($live7);
$ctx7 = mtucAud010F03R1_persistLegacyAttempt(
    MtUniCreditFinancingAttemptState::ORDER_CREATED,
    $legacy7,
    $live7['operation_key_hash'],
    $oid7
);
$live7Fin = mtucAud010F03R1_liveSnapshot($order7, mtucAud010F03R1_calc(24, 500.0));
$live7Fin['operation_key_hash'] = $live7['operation_key_hash'];
$live7Fin['selection_hash'] = $live7['selection_hash'];
$live7Fin['request_fingerprint'] = $live7['request_fingerprint'];
$bind7 = MtUniCreditApplicationSnapshot::bindToAttempt(
    $ctx7['attempts'],
    $ctx7['attempt'],
    $live7Fin
);
mtucAud010F03R1_assert(
    empty($bind7['ok']) && isset($bind7['error']) && $bind7['error'] === 'application_drift',
    'financial drift: application_drift'
);

// -------------------------------------------------------------------------
// New-format snapshot + drift → rejected (no legacy shortcut)
// -------------------------------------------------------------------------
$oid8 = mtucAud010F03R1_nextOrderId();
$order8 = mtucAud010F03R1_order('Анна', 'Иванова', $oid8);
$live8 = mtucAud010F03R1_liveSnapshot($order8);
$memory8 = new Phase2MemoryDb();
$db8 = new MtUniCreditDbAdapter($memory8, 'oc_');
$attempts8 = new MtUniCreditFinancingAttemptRepository(
    $db8,
    new MtUniCreditPersistenceClock(function () {
        return Phase9TestHarness::NOW;
    })
);
$attempt8 = $attempts8->findOrCreateAttempt(
    Phase5TestHarness::STORE_A,
    $oid8,
    Phase4TestHarness::TEST_UNICID,
    $live8['operation_key_hash'],
    $live8['selection_hash'],
    $live8['request_fingerprint'],
    MtUniCreditOperationEntryPoint::PRODUCT
);
$persisted8 = $attempts8->persistApplicationSnapshot((int) $attempt8['attempt_id'], $live8);
$order8Drift = mtucAud010F03R1_order('Мария', 'Иванова', $oid8);
$live8Drift = mtucAud010F03R1_liveSnapshot($order8Drift);
$live8Drift['operation_key_hash'] = $live8['operation_key_hash'];
$live8Drift['selection_hash'] = $live8['selection_hash'];
$live8Drift['request_fingerprint'] = $live8['request_fingerprint'];
$bind8 = MtUniCreditApplicationSnapshot::bindToAttempt($attempts8, $persisted8, $live8Drift);
mtucAud010F03R1_assert(
    empty($bind8['ok']) && isset($bind8['error']) && $bind8['error'] === 'application_drift',
    'new-format drift: application_drift'
);

// -------------------------------------------------------------------------
// Malformed legacy (no customer.name) → fail closed
// -------------------------------------------------------------------------
$oid9 = mtucAud010F03R1_nextOrderId();
$order9 = mtucAud010F03R1_order('Анна', 'Иванова', $oid9);
$live9 = mtucAud010F03R1_liveSnapshot($order9);
$malformed = mtucAud010F03R1_toLegacy($live9);
unset($malformed['customer']['name']);
$ctx9 = mtucAud010F03R1_persistLegacyAttempt(
    MtUniCreditFinancingAttemptState::ORDER_CREATED,
    $malformed,
    $live9['operation_key_hash'],
    $oid9
);
$bind9 = MtUniCreditApplicationSnapshot::bindToAttempt(
    $ctx9['attempts'],
    $ctx9['attempt'],
    $live9
);
mtucAud010F03R1_assert(
    empty($bind9['ok']) && isset($bind9['error']) && $bind9['error'] === 'application_drift',
    'malformed legacy (no name): fail closed'
);

// -------------------------------------------------------------------------
// Partial structured snapshot → fail closed
// -------------------------------------------------------------------------
$oid10 = mtucAud010F03R1_nextOrderId();
$order10 = mtucAud010F03R1_order('Анна', 'Иванова', $oid10);
$live10 = mtucAud010F03R1_liveSnapshot($order10);
$partial = $live10;
unset($partial['customer']['lastname']);
$memory10 = new Phase2MemoryDb();
$db10 = new MtUniCreditDbAdapter($memory10, 'oc_');
$attempts10 = new MtUniCreditFinancingAttemptRepository(
    $db10,
    new MtUniCreditPersistenceClock(function () {
        return Phase9TestHarness::NOW;
    })
);
$attempt10 = $attempts10->findOrCreateAttempt(
    Phase5TestHarness::STORE_A,
    $oid10,
    Phase4TestHarness::TEST_UNICID,
    $live10['operation_key_hash'],
    $live10['selection_hash'],
    $live10['request_fingerprint'],
    MtUniCreditOperationEntryPoint::PRODUCT
);
$persisted10 = $attempts10->persistApplicationSnapshot((int) $attempt10['attempt_id'], $partial);
$bind10 = MtUniCreditApplicationSnapshot::bindToAttempt($attempts10, $persisted10, $live10);
mtucAud010F03R1_assert(
    empty($bind10['ok']) && isset($bind10['error']) && $bind10['error'] === 'application_drift',
    'partial structured snapshot: fail closed'
);

// -------------------------------------------------------------------------
// VERSION unchanged
// -------------------------------------------------------------------------
mtucAud010F03R1_assert(
    MtUniCreditApplicationSnapshot::VERSION === 1,
    'snapshot VERSION remains 1'
);

// -------------------------------------------------------------------------
if (count($failures) > 0) {
    echo 'AUD-010 F03-R1: FAIL (' . count($failures) . ' failures, ' . $passes . ' passes)' . PHP_EOL;
    exit(1);
}

echo 'AUD-010 F03-R1: PASS (' . $passes . ' passes)' . PHP_EOL;
exit(0);
