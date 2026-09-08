<?php

/**
 * AUD-020 F01 / R1–R2 — durable Cart clear one-shot per financing attempt.
 * Run: php tests/phase_aud020_f01_cart_clear_oneshot_check.php
 *
 * Authority: financing_attempt.cart_clear_state (not session / fingerprint).
 * applying and applied never re-authorize clear.
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
function mtucAud020F01_assert($condition, $message)
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
if (!defined('DIR_STORAGE')) {
    mtuc_test_define_dir_storage('mtuc-aud020-f01');
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

mtucAud020F01_assert(mtuc_phase0_network_isolation_active(), 'isolation: offline guard active');

$cartCtrl = (string) file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'cart.php'
);
$productCtrl = (string) file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'product.php'
);
$navSrc = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'financing_terminal_navigation_support.php');
$authSrc = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'cart_clear_authorization_repository.php');
$schemaSrc = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'persistence_schema.php');

mtucAud020F01_assert(
    strpos($cartCtrl, 'clearCartAfterSuccessfulHandoffOnce') !== false,
    'cart controller uses one-shot clear'
);
mtucAud020F01_assert(
    preg_match(
        '/clearCartAfterSuccessfulHandoffOnce\\s*\\(\\s*MtUniCreditBootstrap::dbFromRegistry\\(\\s*\\$this->db\\s*\\)\\s*,\\s*\\(int\\)\\s*\\$this->config->get\\(\\s*[\'"]config_store_id[\'"]\\s*\\)\\s*,\\s*\\$result\\s*,\\s*\\$this->cart\\s*\\)/s',
        $cartCtrl
    ) === 1,
    'cart controller: durable db + store_id + $this->cart (no isset cart)'
);
mtucAud020F01_assert(strpos($cartCtrl, 'isset($this->cart)') === false, 'cart: no isset($this->cart)');
mtucAud020F01_assert(
    strpos($productCtrl, 'clearCartAfterSuccessfulHandoff') === false,
    'Product untouched: no cart clear'
);
mtucAud020F01_assert(
    strpos($navSrc, 'SESSION_CART_CLEAR_APPLIED') === false
        && strpos($navSrc, 'mt_uni_credit_cart_clear_applied') === false,
    'session marker removed as clear authority'
);
mtucAud020F01_assert(
    strpos($navSrc, 'claimApplying') !== false
        && strpos($authSrc, 'cart_clear_state') !== false
        && strpos($schemaSrc, 'createAud020AlterStatements') !== false,
    'durable claim + schema present'
);

/**
 * Probe cart with clear counting.
 */
final class MtucAud020F01ProbeCart
{
    /** @var int */
    public $count;

    /** @var int */
    public $clearCalls = 0;

    /**
     * @param int $count
     */
    public function __construct($count)
    {
        $this->count = (int) $count;
    }

    /**
     * @return void
     */
    public function clear()
    {
        $this->clearCalls++;
        $this->count = 0;
    }
}

/**
 * @param array<string, mixed> $stack
 * @param int $orderId
 * @return array<string, mixed>
 */
function mtucAud020F01_cartInput(array $stack, $orderId)
{
    return Phase9TestHarness::cartStorefrontInput($stack, $orderId);
}

/**
 * @param array<string, mixed> $stack
 * @param array<string, mixed> $result
 * @param MtucAud020F01ProbeCart $cart
 * @return bool
 */
function mtucAud020F01_clearOnce(array $stack, array $result, MtucAud020F01ProbeCart $cart)
{
    return MtUniCreditFinancingTerminalNavigationSupport::clearCartAfterSuccessfulHandoffOnce(
        $stack['db'],
        (int) (isset($stack['storeId']) ? $stack['storeId'] : Phase5TestHarness::STORE_A),
        $result,
        $cart
    );
}

/**
 * @param array<string, mixed> $stack
 * @param int $attemptId
 * @return string
 */
function mtucAud020F01_clearState(array $stack, $attemptId)
{
    $auth = new MtUniCreditCartClearAuthorizationRepository($stack['db']);

    return $auth->getState((int) $attemptId);
}

/**
 * @param array<string, mixed> $result
 * @return int
 */
function mtucAud020F01_attemptId(array $result)
{
    if (!isset($result['attempt']) || !is_array($result['attempt'])) {
        return 0;
    }

    return (int) (isset($result['attempt']['attempt_id']) ? $result['attempt']['attempt_id'] : 0);
}

$storeId = Phase5TestHarness::STORE_A;

// ---------------------------------------------------------------------------
// D. Normal first clear (not_applied → claim → clear → applied) — P1
// ---------------------------------------------------------------------------
$transportP1 = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportP1);
$stackP1 = Phase9TestHarness::stack($transportP1);
$orderP1 = 220101;
$inputP1 = mtucAud020F01_cartInput($stackP1, $orderP1);
$resultP1 = $stackP1['storefront']->submit($inputP1);
mtucAud020F01_assert(!empty($resultP1['success']), 'D P1: success');
mtucAud020F01_assert(
    (string) $resultP1['bank_status'] === MtUniCreditBankStatus::SENT_PROCESS1,
    'D P1: bank_sent_process1'
);
mtucAud020F01_assert(empty($resultP1['local_replay']), 'D P1: not local_replay on first success');
$attemptP1 = mtucAud020F01_attemptId($resultP1);
mtucAud020F01_assert($attemptP1 > 0, 'D P1: durable attempt_id');
mtucAud020F01_assert(
    mtucAud020F01_clearState($stackP1, $attemptP1) === MtUniCreditCartClearStates::NOT_APPLIED,
    'D P1: clear_state not_applied before clear'
);
$probeP1 = new MtucAud020F01ProbeCart(3);
$clearedP1 = mtucAud020F01_clearOnce($stackP1, $resultP1, $probeP1);
mtucAud020F01_assert($clearedP1 === true && $probeP1->count === 0, 'D P1: cart cleared');
mtucAud020F01_assert($probeP1->clearCalls === 1, 'D P1: clear count = 1');
mtucAud020F01_assert(
    mtucAud020F01_clearState($stackP1, $attemptP1) === MtUniCreditCartClearStates::APPLIED,
    'D P1: clear_state applied after clear'
);

// ---------------------------------------------------------------------------
// first successful P2 — clear once
// ---------------------------------------------------------------------------
$transportP2 = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportP2);
$stackP2 = Phase9TestHarness::stack(
    $transportP2,
    null,
    null,
    Phase5TestHarness::STORE_A,
    array('uni_proces' => 1)
);
$orderP2 = 220102;
$inputP2 = mtucAud020F01_cartInput($stackP2, $orderP2);
$resultP2 = $stackP2['storefront']->submit($inputP2);
mtucAud020F01_assert(!empty($resultP2['success']), 'P2: success');
mtucAud020F01_assert(
    (string) $resultP2['bank_status'] === MtUniCreditBankStatus::SENT_PROCESS2,
    'P2: bank_sent_process2'
);
$attemptP2 = mtucAud020F01_attemptId($resultP2);
$probeP2 = new MtucAud020F01ProbeCart(2);
$clearedP2 = mtucAud020F01_clearOnce($stackP2, $resultP2, $probeP2);
mtucAud020F01_assert($clearedP2 === true && $probeP2->count === 0 && $probeP2->clearCalls === 1, 'P2: clear once');
mtucAud020F01_assert(
    mtucAud020F01_clearState($stackP2, $attemptP2) === MtUniCreditCartClearStates::APPLIED,
    'P2: clear_state applied'
);

// ---------------------------------------------------------------------------
// E. Applied replay — no clear
// ---------------------------------------------------------------------------
$inputReplay = $inputP1;
if (isset($resultP1['session']) && is_array($resultP1['session'])) {
    $inputReplay['session'] = $resultP1['session'];
}
$replayP1 = $stackP1['storefront']->submit($inputReplay);
mtucAud020F01_assert(!empty($replayP1['success']), 'E replay: success');
mtucAud020F01_assert(!empty($replayP1['local_replay']), 'E replay: local_replay');
mtucAud020F01_assert(
    Phase7TestHarness::countOrderPosts($transportP1) === 1,
    'E replay: CP new = 0'
);
mtucAud020F01_assert(
    count($stackP1['smartUcfProbe']->calls) === 1,
    'E replay: Smart new = 0'
);
$clearedReplayEmpty = mtucAud020F01_clearOnce($stackP1, $replayP1, $probeP1);
mtucAud020F01_assert($clearedReplayEmpty === false, 'E applied replay: clear not invoked');
mtucAud020F01_assert($probeP1->clearCalls === 1, 'E applied replay: clear count unchanged');

// ---------------------------------------------------------------------------
// B. identical fresh cart after applied — MUST remain populated
// ---------------------------------------------------------------------------
$probeFresh = new MtucAud020F01ProbeCart(4);
mtucAud020F01_assert($probeFresh->count === 4, 'B: fresh cart populated');
$clearedFresh = mtucAud020F01_clearOnce($stackP1, $replayP1, $probeFresh);
mtucAud020F01_assert($clearedFresh === false, 'B identical-fresh replay: clear skipped');
mtucAud020F01_assert($probeFresh->count === 4, 'B identical-fresh replay: cart remains populated');
mtucAud020F01_assert($probeFresh->clearCalls === 0, 'B identical-fresh replay: clear count unchanged');
mtucAud020F01_assert(
    mtucAud020F01_attemptId($resultP1) === mtucAud020F01_attemptId($replayP1)
        && mtucAud020F01_attemptId($resultP1) > 0,
    'B: replay shares durable attempt_id (not fingerprint)'
);

// ---------------------------------------------------------------------------
// Lost-response: terminal, clear not yet applied (state still not_applied)
// ---------------------------------------------------------------------------
$transportLost = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportLost);
$stackLost = Phase9TestHarness::stack($transportLost);
$orderLost = 220103;
$inputLost = mtucAud020F01_cartInput($stackLost, $orderLost);
$resultLost = $stackLost['storefront']->submit($inputLost);
mtucAud020F01_assert(!empty($resultLost['success']), 'lost: first terminal success');
$attemptLost = mtucAud020F01_attemptId($resultLost);
mtucAud020F01_assert(
    mtucAud020F01_clearState($stackLost, $attemptLost) === MtUniCreditCartClearStates::NOT_APPLIED,
    'lost: still not_applied before recovery clear'
);
$probeLost = new MtucAud020F01ProbeCart(5);
$retryInput = $inputLost;
if (isset($resultLost['session']) && is_array($resultLost['session'])) {
    $retryInput['session'] = $resultLost['session'];
}
$retryLost = $stackLost['storefront']->submit($retryInput);
mtucAud020F01_assert(!empty($retryLost['success']) && !empty($retryLost['local_replay']), 'lost: retry local_replay');
$clearedLost1 = mtucAud020F01_clearOnce($stackLost, $retryLost, $probeLost);
mtucAud020F01_assert($clearedLost1 === true && $probeLost->count === 0, 'lost: clear exactly once on recovery');
mtucAud020F01_assert($probeLost->clearCalls === 1, 'lost: clearCalls = 1');
mtucAud020F01_assert(
    mtucAud020F01_clearState($stackLost, $attemptLost) === MtUniCreditCartClearStates::APPLIED,
    'lost: applied after recovery clear'
);
$clearedLost2 = mtucAud020F01_clearOnce($stackLost, $retryLost, $probeLost);
mtucAud020F01_assert($clearedLost2 === false && $probeLost->clearCalls === 1, 'lost: second retry no second clear');

// ---------------------------------------------------------------------------
// C. Crash state applying — never clear again
// ---------------------------------------------------------------------------
$transportCrash = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportCrash);
$stackCrash = Phase9TestHarness::stack($transportCrash);
$resultCrash = $stackCrash['storefront']->submit(mtucAud020F01_cartInput($stackCrash, 220105));
mtucAud020F01_assert(!empty($resultCrash['success']), 'C crash: terminal success');
$attemptCrash = mtucAud020F01_attemptId($resultCrash);
$authCrash = new MtUniCreditCartClearAuthorizationRepository($stackCrash['db']);
mtucAud020F01_assert(
    $authCrash->forceState($attemptCrash, MtUniCreditCartClearStates::APPLYING),
    'C crash: force applying'
);
$probeCrash = new MtucAud020F01ProbeCart(6);
$clearedCrash = mtucAud020F01_clearOnce($stackCrash, $resultCrash, $probeCrash);
mtucAud020F01_assert($clearedCrash === false, 'C applying: clear skipped');
mtucAud020F01_assert($probeCrash->count === 6 && $probeCrash->clearCalls === 0, 'C applying: cart preserved');
mtucAud020F01_assert(
    mtucAud020F01_clearState($stackCrash, $attemptCrash) === MtUniCreditCartClearStates::APPLYING,
    'C applying: state remains applying'
);

// ---------------------------------------------------------------------------
// A. Two different attempts, identical fingerprint — independent clear state
// ---------------------------------------------------------------------------
$fp = '';
$rowA = $stackP1['attempts']->findById($attemptP1);
if (is_array($rowA) && isset($rowA['request_fingerprint'])) {
    $fp = (string) $rowA['request_fingerprint'];
}
mtucAud020F01_assert($fp !== '', 'A: attempt A has fingerprint');

$transportB = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportB);
$stackB = Phase9TestHarness::stack($transportB);
$resultB = $stackB['storefront']->submit(mtucAud020F01_cartInput($stackB, 220106));
mtucAud020F01_assert(!empty($resultB['success']), 'A attempt B: success');
$attemptB = mtucAud020F01_attemptId($resultB);
mtucAud020F01_assert($attemptB > 0, 'A: attempt B has durable attempt_id');
mtucAud020F01_assert($attemptP1 > 0, 'A: attempt A has durable attempt_id');
$stackB['memoryDb']->query(
    "UPDATE `oc_mt_uni_credit_financing_attempt`
     SET `request_fingerprint` = '" . $stackB['memoryDb']->escape($fp) . "'
     WHERE `attempt_id` = " . (int) $attemptB
);
$rowB = $stackB['attempts']->findById($attemptB);
mtucAud020F01_assert(
    is_array($rowB) && (string) $rowB['request_fingerprint'] === $fp,
    'A: attempt B fingerprint forced equal to A'
);
mtucAud020F01_assert(
    mtucAud020F01_clearState($stackP1, $attemptP1) === MtUniCreditCartClearStates::APPLIED,
    'A: attempt A already applied'
);
mtucAud020F01_assert(
    mtucAud020F01_clearState($stackB, $attemptB) === MtUniCreditCartClearStates::NOT_APPLIED,
    'A: attempt B still not_applied (independent of fingerprint)'
);
$probeB = new MtucAud020F01ProbeCart(2);
$clearedB = mtucAud020F01_clearOnce($stackB, $resultB, $probeB);
mtucAud020F01_assert($clearedB === true && $probeB->clearCalls === 1, 'A: attempt B clears independently');
mtucAud020F01_assert(
    mtucAud020F01_clearState($stackB, $attemptB) === MtUniCreditCartClearStates::APPLIED,
    'A: attempt B applied'
);
$probeAAgain = new MtucAud020F01ProbeCart(9);
$clearedAAgain = mtucAud020F01_clearOnce($stackP1, $resultP1, $probeAAgain);
mtucAud020F01_assert(
    $clearedAAgain === false && $probeAAgain->count === 9,
    'A: attempt A replay still blocked despite shared fingerprint'
);

// ---------------------------------------------------------------------------
// F. Concurrent claim — only one wins
// ---------------------------------------------------------------------------
$transportConc = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportConc);
$stackConc = Phase9TestHarness::stack($transportConc);
$resultConc = $stackConc['storefront']->submit(mtucAud020F01_cartInput($stackConc, 220107));
mtucAud020F01_assert(!empty($resultConc['success']), 'F concurrent: terminal success');
$attemptConc = mtucAud020F01_attemptId($resultConc);
$authConc = new MtUniCreditCartClearAuthorizationRepository($stackConc['db']);
$claim1 = $authConc->claimApplying($attemptConc);
$claim2 = $authConc->claimApplying($attemptConc);
mtucAud020F01_assert($claim1 === true, 'F concurrent: first claim wins');
mtucAud020F01_assert($claim2 === false, 'F concurrent: second claim loses');
mtucAud020F01_assert(
    $authConc->getState($attemptConc) === MtUniCreditCartClearStates::APPLYING,
    'F concurrent: state applying after winner'
);
$probeConc = new MtucAud020F01ProbeCart(3);
$clearedConcLoser = mtucAud020F01_clearOnce($stackConc, $resultConc, $probeConc);
mtucAud020F01_assert(
    $clearedConcLoser === false && $probeConc->clearCalls === 0,
    'F concurrent: once-API does not clear when already applying'
);
$authConc->markApplied($attemptConc);
$clearedConcAfter = mtucAud020F01_clearOnce($stackConc, $resultConc, $probeConc);
mtucAud020F01_assert($clearedConcAfter === false, 'F concurrent: applied also blocks clear');

// ---------------------------------------------------------------------------
// Failure preserves cart
// ---------------------------------------------------------------------------
$transportFail = new Phase4FakeCpHttpTransport();
$payloadsFail = Phase7TestHarness::loginAndOrderSuccessPayloads();
$transportFail->enqueueJson(200, $payloadsFail['login']);
$transportFail->enqueueJson(422, array('success' => false, 'message' => 'invalid'));
$stackFail = Phase9TestHarness::stack($transportFail);
$resultFail = $stackFail['storefront']->submit(mtucAud020F01_cartInput($stackFail, 220104));
mtucAud020F01_assert(empty($resultFail['success']), 'failure: not success');
$probeFail = new MtucAud020F01ProbeCart(3);
$clearedFail = mtucAud020F01_clearOnce($stackFail, $resultFail, $probeFail);
mtucAud020F01_assert($clearedFail === false && $probeFail->count === 3, 'failure: cart preserved');

// ---------------------------------------------------------------------------
// Mutation-sensitivity matrix (all must be YES)
// ---------------------------------------------------------------------------
$mutation = array(
    '1 durable state not fingerprint key' => (
        strpos($navSrc, 'request_fingerprint') === false
        && strpos($authSrc, 'cart_clear_state') !== false
        && strpos($navSrc, 'claimApplying') !== false
    ),
    '2 applying must not clear again' => (
        strpos($authSrc, 'MtUniCreditCartClearStates::NOT_APPLIED') !== false
        && strpos($navSrc, 'claimApplying') !== false
        && strpos($navSrc, 'if (!$auth->claimApplying') !== false
    ),
    '3 applied must not clear again' => (
        strpos($authSrc, 'MtUniCreditCartClearStates::NOT_APPLIED') !== false
        && strpos($authSrc, 'MtUniCreditCartClearStates::APPLIED') !== false
        && strpos($navSrc, 'claimApplying') !== false
    ),
    '4 atomic claim present' => (
        strpos($authSrc, 'AND `cart_clear_state`') !== false
        && strpos($authSrc, 'countAffected() === 1') !== false
    ),
    '5 first valid P1 clears' => strpos($navSrc, 'clearCartAfterSuccessfulHandoffOnce') !== false,
    '6 first valid P2 clears' => strpos($navSrc, 'isSuccessfulBankHandoff') !== false,
    '7 failure path does not clear' => strpos($navSrc, 'isSuccessfulBankHandoff') !== false,
    '8 no isset cart gate' => (
        strpos($cartCtrl, 'isset($this->cart)') === false
        && preg_match('/\\$this->cart\\s*\\)/s', $cartCtrl) === 1
    ),
);

foreach ($mutation as $label => $ok) {
    mtucAud020F01_assert($ok === true, 'mutation YES: ' . $label);
}

echo PHP_EOL;
if ($failures) {
    echo 'AUD-020 F01 CART CLEAR ONE-SHOT: FAIL (' . count($failures) . ' failed, ' . $passes . ' passed)' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'AUD-020 F01 CART CLEAR ONE-SHOT: PASS (' . $passes . ' assertions)' . PHP_EOL;
exit(0);
