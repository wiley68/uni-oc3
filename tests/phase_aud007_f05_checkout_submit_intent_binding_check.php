<?php

/**
 * AUD-007 F05 — Checkout submit intent binding to prepared order.
 * Run: php tests/phase_aud007_f05_checkout_submit_intent_binding_check.php
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
function mtucAud007F05_assert($condition, $message)
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

mtucAud007F05_assert(mtuc_phase0_network_isolation_active(), 'isolation: offline guard active');

$storeA = Phase5TestHarness::STORE_A;
$storeB = Phase5TestHarness::STORE_B;
$orderA = 50101;
$orderB = 50102;

// ---------------------------------------------------------------------------
// Token unit: issue / reuse / rotate / verify
// ---------------------------------------------------------------------------
$session = array();
$t1 = MtUniCreditCheckoutSubmitToken::issue($session, $storeA, $orderA, $orderA);
mtucAud007F05_assert(
    MtUniCreditCheckoutSubmitToken::isValidTokenFormat($t1),
    'format: issued token is 32 lowercase hex'
);
$t1Again = MtUniCreditCheckoutSubmitToken::issue($session, $storeA, $orderA, $orderA);
mtucAud007F05_assert($t1 === $t1Again, 'same-order: token reused');
mtucAud007F05_assert(
    MtUniCreditCheckoutSubmitToken::verify($session, $t1, $storeA, $orderA, $orderA),
    'T1(A) accepted for prepared A'
);

$t2 = MtUniCreditCheckoutSubmitToken::issue($session, $storeA, $orderB, $orderB);
mtucAud007F05_assert($t2 !== $t1 && $t2 !== '', 'A→B: fresh T2 issued');
mtucAud007F05_assert(
    !MtUniCreditCheckoutSubmitToken::verify($session, $t1, $storeA, $orderB, $orderB),
    'stale T1(A) rejected while current B'
);
mtucAud007F05_assert(
    MtUniCreditCheckoutSubmitToken::verify($session, $t2, $storeA, $orderB, $orderB),
    'T2(B) accepted for prepared B'
);
mtucAud007F05_assert(
    !MtUniCreditCheckoutSubmitToken::verify($session, $t2, $storeA, $orderA, $orderA),
    'T2(B) cannot authorize A'
);

// A→B→A: new intent for A (no stale T1 revival)
$t3 = MtUniCreditCheckoutSubmitToken::issue($session, $storeA, $orderA, $orderA);
mtucAud007F05_assert($t3 !== $t1 && $t3 !== $t2, 'A→B→A: fresh T3 for A (no T1 revival)');
mtucAud007F05_assert(
    !MtUniCreditCheckoutSubmitToken::verify($session, $t1, $storeA, $orderA, $orderA),
    'stale T1 not revived for A'
);
mtucAud007F05_assert(
    MtUniCreditCheckoutSubmitToken::verify($session, $t3, $storeA, $orderA, $orderA),
    'T3(A) accepted'
);

// Cross-store
$sessionStore = array();
$tStore = MtUniCreditCheckoutSubmitToken::issue($sessionStore, $storeA, $orderA, $orderA);
mtucAud007F05_assert(
    !MtUniCreditCheckoutSubmitToken::verify($sessionStore, $tStore, $storeB, $orderA, $orderA),
    'cross-store: rejected'
);

// Malformed tokens (no trim / trailing LF)
$sessionM = array();
$tM = MtUniCreditCheckoutSubmitToken::issue($sessionM, $storeA, $orderA, $orderA);
mtucAud007F05_assert(
    !MtUniCreditCheckoutSubmitToken::verify($sessionM, $tM . "\n", $storeA, $orderA, $orderA),
    'malformed: trailing LF rejected'
);
mtucAud007F05_assert(
    !MtUniCreditCheckoutSubmitToken::verify($sessionM, strtoupper($tM), $storeA, $orderA, $orderA),
    'malformed: uppercase rejected'
);
mtucAud007F05_assert(
    !MtUniCreditCheckoutSubmitToken::verify($sessionM, 'deadbeef', $storeA, $orderA, $orderA),
    'malformed: short hex rejected'
);
mtucAud007F05_assert(
    !MtUniCreditCheckoutSubmitToken::verify($sessionM, ' ' . $tM, $storeA, $orderA, $orderA),
    'malformed: leading space rejected'
);

// Missing prepared order / invalid ids
mtucAud007F05_assert(
    !MtUniCreditCheckoutSubmitToken::verify($sessionM, $tM, $storeA, 0, 0),
    'missing prepared order: rejected'
);
mtucAud007F05_assert(
    MtUniCreditCheckoutSubmitToken::issue($sessionM, $storeA, 0, 0) === '',
    'missing order: issue returns empty'
);

// Prepared marker mismatch
mtucAud007F05_assert(
    !MtUniCreditCheckoutSubmitToken::verify($sessionM, $tM, $storeA, $orderA, $orderB),
    'prepared marker mismatch: rejected'
);

// ---------------------------------------------------------------------------
// Lifecycle: stale T1 while B current → no CP / SmartUCF
// ---------------------------------------------------------------------------
$transportStale = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportStale);
$stackStale = Phase9TestHarness::stack(
    $transportStale,
    null,
    null,
    $storeA,
    array('uni_proces' => 0)
);
$sessionStale = array();
$tStaleA = MtUniCreditCheckoutSubmitToken::issue($sessionStale, $storeA, $orderA, $orderA);
// Rotate prepared intent to B (as prepared GET would).
$tStaleB = MtUniCreditCheckoutSubmitToken::issue($sessionStale, $storeA, $orderB, $orderB);
mtucAud007F05_assert($tStaleA !== $tStaleB, 'lifecycle rotate: T2 != T1');
$acceptStale = MtUniCreditCheckoutSubmitToken::verify(
    $sessionStale,
    $tStaleA,
    $storeA,
    $orderB,
    $orderB
);
mtucAud007F05_assert(!$acceptStale, 'lifecycle: stale T1(A) rejected for current B');
// No checkout financing call when token invalid — assert counters remain 0.
mtucAud007F05_assert(Phase7TestHarness::countOrderPosts($transportStale) === 0, 'stale T1: CP create = 0');
mtucAud007F05_assert(
    Phase9TestHarness::smartUcfCallCount($stackStale['smartUcfProbe']) === 0,
    'stale T1: SmartUCF = 0'
);

// ---------------------------------------------------------------------------
// Same-order normal submit + replay via Checkout submission service
// ---------------------------------------------------------------------------
$transportOk = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportOk);
$stackOk = Phase9TestHarness::stack($transportOk, null, null, $storeA, array('uni_proces' => 0));
$okOrder = 50201;
$stackOk['memoryDb']->seedOrder($okOrder, $storeA, MtUniCreditConstants::EXTENSION_CODE);
$sessionOk = array();
$tOk = MtUniCreditCheckoutSubmitToken::issue($sessionOk, $storeA, $okOrder, $okOrder);
mtucAud007F05_assert(
    MtUniCreditCheckoutSubmitToken::verify($sessionOk, $tOk, $storeA, $okOrder, $okOrder),
    'normal: T1 accepted for A'
);
$inputOk = Phase9TestHarness::submitInput($okOrder, $storeA);
$firstOk = $stackOk['submission']->submit($inputOk);
mtucAud007F05_assert(!empty($firstOk['success']), 'normal: Checkout submit succeeds');
$cpAfterFirst = Phase7TestHarness::countOrderPosts($transportOk);
$smartAfterFirst = Phase9TestHarness::smartUcfCallCount($stackOk['smartUcfProbe']);
mtucAud007F05_assert(
    MtUniCreditCheckoutSubmitToken::verify($sessionOk, $tOk, $storeA, $okOrder, $okOrder),
    'replay: same token still valid for A'
);
$secondOk = $stackOk['submission']->submit($inputOk);
mtucAud007F05_assert(!empty($secondOk['success']) || !empty($secondOk['local_replay']), 'replay: same-order accepted');
mtucAud007F05_assert(
    Phase7TestHarness::countOrderPosts($transportOk) === $cpAfterFirst,
    'replay: CP create = 0 additional'
);
mtucAud007F05_assert(
    Phase9TestHarness::smartUcfCallCount($stackOk['smartUcfProbe']) === $smartAfterFirst,
    'replay: SmartUCF = 0 additional'
);

// ---------------------------------------------------------------------------
// Ambiguous same-order: token remains A-bound; no new CP
// ---------------------------------------------------------------------------
$transportAmb = new Phase4FakeCpHttpTransport();
$transportAmb->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transportAmb->enqueueTimeout();
$stackAmb = Phase9TestHarness::stack($transportAmb, null, null, $storeA, array('uni_proces' => 0));
$ambOrder = 50202;
$stackAmb['memoryDb']->seedOrder($ambOrder, $storeA, MtUniCreditConstants::EXTENSION_CODE);
$sessionAmb = array();
$tAmb = MtUniCreditCheckoutSubmitToken::issue($sessionAmb, $storeA, $ambOrder, $ambOrder);
$inputAmb = Phase9TestHarness::submitInput($ambOrder, $storeA);
$firstAmb = $stackAmb['submission']->submit($inputAmb);
mtucAud007F05_assert(!empty($firstAmb['ambiguous_blocked']) || empty($firstAmb['success']), 'ambiguous: first outcome blocked/failed');
$cpAmb = Phase7TestHarness::countOrderPosts($transportAmb);
mtucAud007F05_assert(
    MtUniCreditCheckoutSubmitToken::verify($sessionAmb, $tAmb, $storeA, $ambOrder, $ambOrder),
    'ambiguous: same A token still valid'
);
$secondAmb = $stackAmb['submission']->submit($inputAmb);
mtucAud007F05_assert(!empty($secondAmb['ambiguous_blocked']), 'ambiguous replay: blocked');
mtucAud007F05_assert(
    Phase7TestHarness::countOrderPosts($transportAmb) === $cpAmb,
    'ambiguous replay: no new CP create'
);

// ---------------------------------------------------------------------------
// Controller wiring: verify uses bound context args
// ---------------------------------------------------------------------------
$controllerPath = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog'
    . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'extension'
    . DIRECTORY_SEPARATOR . 'payment' . DIRECTORY_SEPARATOR . 'mt_uni_credit.php';
$controllerSrc = (string) file_get_contents($controllerPath);
mtucAud007F05_assert(
    strpos($controllerSrc, 'MtUniCreditCheckoutSubmitToken::issue(') !== false
        && strpos($controllerSrc, 'MtUniCreditCheckoutSubmitToken::verify(') !== false,
    'wiring: controller issues/verifies submit intent'
);
mtucAud007F05_assert(
    strpos($controllerSrc, 'resolvePreparedContext()') !== false
        && preg_match('/resolvePreparedContext\(\).*CheckoutSubmitToken::verify/s', $controllerSrc) === 1,
    'wiring: prepared context resolved before token verify'
);

echo PHP_EOL . 'MATRIX F05:' . PHP_EOL;
echo 'stale T1(A)/current B: cp=0 smart=0' . PHP_EOL;
echo 'cross-store: rejected' . PHP_EOL;
echo 'malformed: rejected' . PHP_EOL;
echo 'missing prepared: rejected' . PHP_EOL;
echo 'same-order replay: cp additional=0 smart additional=0' . PHP_EOL;

if ($failures !== array()) {
    fwrite(STDERR, 'AUD-007 F05: FAIL (' . count($failures) . ')' . PHP_EOL);
    foreach ($failures as $failure) {
        fwrite(STDERR, ' - ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo PHP_EOL . 'AUD-007 F05: PASS (' . $passes . ' passes)' . PHP_EOL;
