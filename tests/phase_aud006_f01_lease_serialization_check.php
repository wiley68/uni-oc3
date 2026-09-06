<?php

/**
 * AUD-006 F01 — long-running lease renewal + durable pre-order claim.
 * Run: php tests/phase_aud006_f01_lease_serialization_check.php
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
function mtucAud006_assert($condition, $message)
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

mtucAud006_assert(mtuc_phase0_network_isolation_active(), 'isolation: offline guard active');

$now = 1800000000;
$clockHolder = array('now' => $now);
$clock = new MtUniCreditPersistenceClock(function () use (&$clockHolder) {
    return (int) $clockHolder['now'];
});

$memory = new Phase2MemoryDb();
$db = new MtUniCreditDbAdapter($memory, 'oc_');
$locks = new MtUniCreditOperationLockRepository($db, $clock);
$claims = new MtUniCreditOperationOrderClaimRepository($db, $clock);

$storeId = 1;
$entry = MtUniCreditOperationEntryPoint::PRODUCT;
$hash = str_repeat('a', 64);
$ownerA = str_repeat('1', 32);
$ownerB = str_repeat('2', 32);

// ---------------------------------------------------------------------------
// Renewal repository matrix
// ---------------------------------------------------------------------------
mtucAud006_assert($locks->acquire($storeId, $entry, $hash, $ownerA), 'renew: A acquires');
$before = $locks->find($storeId, $entry, $hash);
mtucAud006_assert(is_array($before), 'renew: lock row present');
$clockHolder['now'] = $now + 20;
mtucAud006_assert($locks->renew($storeId, $entry, $hash, $ownerA), 'renew: current owner renews active lock');
$after = $locks->find($storeId, $entry, $hash);
mtucAud006_assert(
    is_array($after) && strtotime($after['expires_at']) > strtotime($before['expires_at']),
    'renew: expiry extended'
);
mtucAud006_assert(!$locks->renew($storeId, $entry, $hash, $ownerB), 'renew: wrong owner cannot renew');

$clockHolder['now'] = $now + MtUniCreditSecurityConstants::OPERATION_LOCK_TTL_SECONDS + 30;
mtucAud006_assert(!$locks->renew($storeId, $entry, $hash, $ownerA), 'renew: expired old owner cannot renew');
mtucAud006_assert($locks->acquire($storeId, $entry, $hash, $ownerB), 'renew: B takes over stale lock');
mtucAud006_assert(!$locks->renew($storeId, $entry, $hash, $ownerA), 'renew: old owner cannot renew after takeover');
mtucAud006_assert($locks->renew($storeId, $entry, $hash, $ownerB), 'renew: new owner renews');
mtucAud006_assert(!$locks->release($storeId, $entry, $hash, $ownerA), 'renew: old owner release fails after takeover');
mtucAud006_assert($locks->release($storeId, $entry, $hash, $ownerB), 'renew: B release succeeds');

$failDb = new class {
    /**
     * @param string $sql
     */
    public function query($sql)
    {
        throw new Exception('simulated DB failure');
    }
    /**
     * @param mixed $v
     * @return string
     */
    public function escape($v)
    {
        return addslashes((string) $v);
    }
    public function countAffected()
    {
        return 0;
    }
    public function getPrefix()
    {
        return 'oc_';
    }
};
$threw = false;
try {
    $failLocks = new MtUniCreditOperationLockRepository(new MtUniCreditDbAdapter($failDb, 'oc_'), $clock);
    $failLocks->renew($storeId, $entry, $hash, $ownerA);
} catch (Exception $exception) {
    $threw = true;
}
mtucAud006_assert($threw, 'renew: DB error fails closed');

// ---------------------------------------------------------------------------
// AUD-006-F01-R1: same-second active-owner renew (MySQL 0 changed rows)
// ---------------------------------------------------------------------------
$memory->reset();
$clockHolder['now'] = $now;
$hashR1 = str_repeat('9', 64);
mtucAud006_assert($locks->acquire($storeId, $entry, $hashR1, $ownerA), 'r1: A acquires at T');
$rowR1 = $locks->find($storeId, $entry, $hashR1);
mtucAud006_assert(is_array($rowR1), 'r1: lock present');
// Same second T: UPDATE writes identical expires_at/updated_at → affected=0, still active owner.
mtucAud006_assert($locks->renew($storeId, $entry, $hashR1, $ownerA), 'r1: same-second renew → true');
mtucAud006_assert(
    $locks->acquire($storeId, $entry, $hashR1, $ownerA),
    'r1: same-second re-entrant acquire → true'
);
mtucAud006_assert(!$locks->renew($storeId, $entry, $hashR1, $ownerB), 'r1: wrong owner same-second → false');

$clockHolder['now'] = $now + MtUniCreditSecurityConstants::OPERATION_LOCK_TTL_SECONDS + 1;
mtucAud006_assert(!$locks->renew($storeId, $entry, $hashR1, $ownerA), 'r1: expired owner → false');
mtucAud006_assert($locks->acquire($storeId, $entry, $hashR1, $ownerB), 'r1: B takeover after expiry');
mtucAud006_assert(!$locks->renew($storeId, $entry, $hashR1, $ownerA), 'r1: old owner after takeover → false');

$memory->reset();
$clockHolder['now'] = $now;
$hashR1b = str_repeat('8', 64);
mtucAud006_assert($locks->acquire($storeId, $entry, $hashR1b, $ownerA), 'r1: later-second baseline acquire');
$clockHolder['now'] = $now + 5;
mtucAud006_assert($locks->renew($storeId, $entry, $hashR1b, $ownerA), 'r1: later-second renew → true');

$selectFailDb = new class {
    public $phase = 'update';
    /**
     * @param string $sql
     * @return object
     */
    public function query($sql)
    {
        if ($this->phase === 'update' && stripos(trim($sql), 'UPDATE') === 0) {
            $this->phase = 'select';

            return (object) array('num_rows' => 0, 'row' => array(), 'rows' => array());
        }
        throw new Exception('simulated SELECT failure');
    }
    /**
     * @param mixed $v
     * @return string
     */
    public function escape($v)
    {
        return addslashes((string) $v);
    }
    public function countAffected()
    {
        return 0;
    }
    public function getPrefix()
    {
        return 'oc_';
    }
};
$threwSelect = false;
try {
    $selectFailLocks = new MtUniCreditOperationLockRepository(
        new MtUniCreditDbAdapter($selectFailDb, 'oc_'),
        $clock
    );
    $selectFailLocks->renew($storeId, $entry, $hashR1b, $ownerA);
} catch (Exception $exception) {
    $threwSelect = true;
}
mtucAud006_assert($threwSelect, 'r1: fallback SELECT DB error → exception');

// ---------------------------------------------------------------------------
// Long-running ownership: renew keeps B rejected
// ---------------------------------------------------------------------------
$memory->reset();
$clockHolder['now'] = $now;
$hash2 = str_repeat('b', 64);
mtucAud006_assert($locks->acquire($storeId, $entry, $hash2, $ownerA), 'hold: A acquires');
$clockHolder['now'] = $now + 40;
mtucAud006_assert($locks->renew($storeId, $entry, $hash2, $ownerA), 'hold: A renews near expiry');
mtucAud006_assert(!$locks->acquire($storeId, $entry, $hash2, $ownerB), 'hold: B remains rejected');

$clockHolder['now'] = $now + 40 + MtUniCreditSecurityConstants::OPERATION_LOCK_TTL_SECONDS + 1;
mtucAud006_assert($locks->acquire($storeId, $entry, $hash2, $ownerB), 'takeover: B takes after A expires');
mtucAud006_assert(!$locks->renew($storeId, $entry, $hash2, $ownerA), 'takeover: A renew fails');
mtucAud006_assert(!$locks->release($storeId, $entry, $hash2, $ownerA), 'takeover: A release fails');
$rowB = $locks->find($storeId, $entry, $hash2);
mtucAud006_assert(is_array($rowB) && hash_equals((string) $rowB['owner_token'], $ownerB), 'takeover: B remains owner');

// ---------------------------------------------------------------------------
// Durable pre-order claim
// ---------------------------------------------------------------------------
$memory->reset();
$clockHolder['now'] = $now;
$hash3 = str_repeat('c', 64);
$claimA = $claims->ensureClaim($storeId, $entry, $hash3, $ownerA);
mtucAud006_assert(
    (string) $claimA['state'] === MtUniCreditOperationOrderClaimRepository::STATE_CLAIMED_BEFORE_ORDER,
    'claim: first establishes claimed_before_order'
);
$claimB = $claims->ensureClaim($storeId, $entry, $hash3, $ownerB);
mtucAud006_assert(
    hash_equals((string) $claimB['claim_owner_token'], $ownerA),
    'claim: second cannot steal claim ownership'
);
mtucAud006_assert(
    $claimB['order_id'] === null || $claimB['order_id'] === '',
    'claim: still unbound before addOrder'
);

// ---------------------------------------------------------------------------
// Lease overrun around addOrder (product/cart path seams)
// ---------------------------------------------------------------------------
$memory->reset();
$clockHolder['now'] = $now;
$hash4 = str_repeat('d', 64);
mtucAud006_assert($locks->acquire($storeId, $entry, $hash4, $ownerA), 'overrun: A lock');
$claim = $claims->ensureClaim($storeId, $entry, $hash4, $ownerA);
mtucAud006_assert(hash_equals((string) $claim['claim_owner_token'], $ownerA), 'overrun: A owns claim');

$addOrderCalls = 0;
$simulatedAddOrder = function () use (&$addOrderCalls) {
    $addOrderCalls++;

    return 9001;
};

// Lease expires while A is conceptually inside addOrder; claim still unbound.
$clockHolder['now'] = $now + MtUniCreditSecurityConstants::OPERATION_LOCK_TTL_SECONDS + 5;
mtucAud006_assert($locks->acquire($storeId, $entry, $hash4, $ownerB), 'overrun: B takeover during addOrder window');
$claimForB = $claims->ensureClaim($storeId, $entry, $hash4, $ownerB);
$bMayCreate = hash_equals((string) $claimForB['claim_owner_token'], $ownerB)
    && (string) $claimForB['state'] === MtUniCreditOperationOrderClaimRepository::STATE_CLAIMED_BEFORE_ORDER
    && (!isset($claimForB['order_id']) || $claimForB['order_id'] === null || $claimForB['order_id'] === '');
mtucAud006_assert(!$bMayCreate, 'overrun: B must NOT call addOrder');

// A completes materialization and binds.
$orderId = (int) call_user_func($simulatedAddOrder);
$claims->bindOrderId($storeId, $entry, $hash4, $orderId);
$bound = $claims->find($storeId, $entry, $hash4);
mtucAud006_assert(
    is_array($bound)
        && (int) $bound['order_id'] === 9001
        && (string) $bound['state'] === MtUniCreditOperationOrderClaimRepository::STATE_ORDER_CREATED,
    'overrun: claim binds A order'
);
mtucAud006_assert($addOrderCalls === 1, 'overrun: only one addOrder');

// Later recovery reuses bound order — no second addOrder.
$recovered = $claims->find($storeId, $entry, $hash4);
mtucAud006_assert((int) $recovered['order_id'] === 9001, 'overrun: recovery reuses order');
$addOrderCalls = 0;
if ((int) $recovered['order_id'] > 0) {
    // recovery path does not call addOrder
} else {
    call_user_func($simulatedAddOrder);
}
mtucAud006_assert($addOrderCalls === 0, 'overrun: recovery does not call addOrder');

// ---------------------------------------------------------------------------
// Crash ambiguity: claim without order_id → fail closed for new owner
// ---------------------------------------------------------------------------
$memory->reset();
$clockHolder['now'] = $now;
$hash5 = str_repeat('e', 64);
$claims->ensureClaim($storeId, $entry, $hash5, $ownerA);
$clockHolder['now'] = $now + MtUniCreditSecurityConstants::OPERATION_LOCK_TTL_SECONDS + 2;
mtucAud006_assert($locks->acquire($storeId, $entry, $hash5, $ownerB), 'ambiguous: B lock after crash');
$stuck = $claims->ensureClaim($storeId, $entry, $hash5, $ownerB);
$bMay = hash_equals((string) $stuck['claim_owner_token'], $ownerB)
    && (string) $stuck['state'] === MtUniCreditOperationOrderClaimRepository::STATE_CLAIMED_BEFORE_ORDER;
mtucAud006_assert(!$bMay, 'ambiguous: B blocked from blind second addOrder');

// ---------------------------------------------------------------------------
// Completed order recovery
// ---------------------------------------------------------------------------
$memory->reset();
$clockHolder['now'] = $now;
$hash6 = str_repeat('f', 64);
$claims->ensureClaim($storeId, $entry, $hash6, $ownerA);
$claims->bindOrderId($storeId, $entry, $hash6, 4242);
$again = $claims->ensureClaim($storeId, $entry, $hash6, $ownerB);
mtucAud006_assert((int) $again['order_id'] === 4242, 'completed: new worker sees bound order');
mtucAud006_assert(
    (string) $again['state'] === MtUniCreditOperationOrderClaimRepository::STATE_ORDER_CREATED,
    'completed: state order_created'
);

// ---------------------------------------------------------------------------
// Independence
// ---------------------------------------------------------------------------
$memory->reset();
$clockHolder['now'] = $now;
$hProd = str_repeat('1', 64);
$hCart = str_repeat('2', 64);
$hOther = str_repeat('3', 64);
mtucAud006_assert($locks->acquire(1, MtUniCreditOperationEntryPoint::PRODUCT, $hProd, $ownerA), 'indep: product A');
mtucAud006_assert($locks->acquire(1, MtUniCreditOperationEntryPoint::CART, $hCart, $ownerA), 'indep: cart independent');
mtucAud006_assert($locks->acquire(1, MtUniCreditOperationEntryPoint::PRODUCT, $hOther, $ownerB), 'indep: different op hash');
mtucAud006_assert($locks->acquire(2, MtUniCreditOperationEntryPoint::PRODUCT, $hProd, $ownerB), 'indep: different store');
$claims->ensureClaim(1, MtUniCreditOperationEntryPoint::PRODUCT, $hProd, $ownerA);
$claims->ensureClaim(1, MtUniCreditOperationEntryPoint::CART, $hCart, $ownerA);
$cProd = $claims->find(1, MtUniCreditOperationEntryPoint::PRODUCT, $hProd);
$cCart = $claims->find(1, MtUniCreditOperationEntryPoint::CART, $hCart);
mtucAud006_assert($cProd !== null && $cCart !== null, 'indep: product vs cart claims isolated');

// ---------------------------------------------------------------------------
// Schema marker
// ---------------------------------------------------------------------------
$schema = (string) file_get_contents(DIR_SYSTEM . 'library/mt_uni_credit/persistence_schema.php');
mtucAud006_assert(
    strpos($schema, 'mt_uni_credit_operation_order_claim') !== false
        || strpos($schema, 'OPERATION_ORDER_CLAIM') !== false,
    'schema: operation_order_claim table present'
);
$lockSrc = (string) file_get_contents(DIR_SYSTEM . 'library/mt_uni_credit/operation_lock_repository.php');
mtucAud006_assert(strpos($lockSrc, 'function renew') !== false, 'structure: renew API present');
mtucAud006_assert(
    strpos($lockSrc, 'expires_at` > ') !== false || strpos($lockSrc, "expires_at` > '") !== false,
    'structure: renew requires active lease'
);

echo PHP_EOL;
if ($failures) {
    echo 'AUD-006 F01: FAIL (' . count($failures) . ' failures, ' . $passes . ' passes)' . PHP_EOL;
    foreach ($failures as $f) {
        echo '  - ' . $f . PHP_EOL;
    }
    exit(1);
}

echo 'AUD-006 F01: PASS (' . $passes . ' passes)' . PHP_EOL;
exit(0);
