<?php

/**
 * AUD-020 F01 — Cart clear must be one-shot per durable attempt.
 * Run: php tests/phase_aud020_f01_cart_clear_oneshot_check.php
 *
 * Critical regression: after successful Cart handoff + clear, replaying the same
 * durable attempt must not clear a newly populated cart with the same fingerprint.
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
mtucAud020F01_assert(
    strpos($cartCtrl, 'clearCartAfterSuccessfulHandoffOnce') !== false,
    'cart controller uses one-shot clear'
);
mtucAud020F01_assert(
    preg_match(
        '/clearCartAfterSuccessfulHandoffOnce\\s*\\(\\s*\\$this->session->data\\s*,\\s*\\$result\\s*,\\s*\\$this->cart\\s*\\)/s',
        $cartCtrl
    ) === 1,
    'cart controller: session + result + $this->cart (no isset cart)'
);
mtucAud020F01_assert(strpos($cartCtrl, 'isset($this->cart)') === false, 'cart: no isset($this->cart)');
mtucAud020F01_assert(
    strpos($productCtrl, 'clearCartAfterSuccessfulHandoff') === false,
    'Product untouched: no cart clear'
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

// ---------------------------------------------------------------------------
// A. first successful P1 — clear once
// ---------------------------------------------------------------------------
$sessionP1 = array();
$transportP1 = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportP1);
$stackP1 = Phase9TestHarness::stack($transportP1);
$orderP1 = 220101;
$inputP1 = mtucAud020F01_cartInput($stackP1, $orderP1);
$resultP1 = $stackP1['storefront']->submit($inputP1);
mtucAud020F01_assert(!empty($resultP1['success']), 'A P1: success');
mtucAud020F01_assert(
    (string) $resultP1['bank_status'] === MtUniCreditBankStatus::SENT_PROCESS1,
    'A P1: bank_sent_process1'
);
mtucAud020F01_assert(empty($resultP1['local_replay']), 'A P1: not local_replay on first success');
$probeP1 = new MtucAud020F01ProbeCart(3);
$clearedP1 = MtUniCreditFinancingTerminalNavigationSupport::clearCartAfterSuccessfulHandoffOnce(
    $sessionP1,
    $resultP1,
    $probeP1
);
mtucAud020F01_assert($clearedP1 === true && $probeP1->count === 0, 'A P1: cart cleared');
mtucAud020F01_assert($probeP1->clearCalls === 1, 'A P1: clear count = 1');

// ---------------------------------------------------------------------------
// B. first successful P2 — clear once
// ---------------------------------------------------------------------------
$sessionP2 = array();
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
mtucAud020F01_assert(!empty($resultP2['success']), 'B P2: success');
mtucAud020F01_assert(
    (string) $resultP2['bank_status'] === MtUniCreditBankStatus::SENT_PROCESS2,
    'B P2: bank_sent_process2'
);
$probeP2 = new MtucAud020F01ProbeCart(2);
$clearedP2 = MtUniCreditFinancingTerminalNavigationSupport::clearCartAfterSuccessfulHandoffOnce(
    $sessionP2,
    $resultP2,
    $probeP2
);
mtucAud020F01_assert($clearedP2 === true && $probeP2->count === 0 && $probeP2->clearCalls === 1, 'B P2: clear once');

// ---------------------------------------------------------------------------
// C. replay after already-cleared cart — clear count unchanged
// ---------------------------------------------------------------------------
$inputReplay = $inputP1;
if (isset($resultP1['session']) && is_array($resultP1['session'])) {
    $inputReplay['session'] = $resultP1['session'];
}
$replayP1 = $stackP1['storefront']->submit($inputReplay);
mtucAud020F01_assert(!empty($replayP1['success']), 'C replay: success');
mtucAud020F01_assert(!empty($replayP1['local_replay']), 'C replay: local_replay');
mtucAud020F01_assert(
    Phase7TestHarness::countOrderPosts($transportP1) === 1,
    'C replay: CP new = 0'
);
mtucAud020F01_assert(
    count($stackP1['smartUcfProbe']->calls) === 1,
    'C replay: Smart new = 0'
);
$clearedReplayEmpty = MtUniCreditFinancingTerminalNavigationSupport::clearCartAfterSuccessfulHandoffOnce(
    $sessionP1,
    $replayP1,
    $probeP1
);
mtucAud020F01_assert($clearedReplayEmpty === false, 'C replay: clear not invoked');
mtucAud020F01_assert($probeP1->clearCalls === 1, 'C replay: clear count unchanged');

// ---------------------------------------------------------------------------
// D. identical fresh cart after successful attempt — MUST remain populated
// ---------------------------------------------------------------------------
$probeFresh = new MtucAud020F01ProbeCart(4);
mtucAud020F01_assert($probeFresh->count === 4, 'D: fresh cart populated');
$clearedFresh = MtUniCreditFinancingTerminalNavigationSupport::clearCartAfterSuccessfulHandoffOnce(
    $sessionP1,
    $replayP1,
    $probeFresh
);
mtucAud020F01_assert($clearedFresh === false, 'D identical-fresh replay: clear skipped');
mtucAud020F01_assert($probeFresh->count === 4, 'D identical-fresh replay: cart remains populated');
mtucAud020F01_assert($probeFresh->clearCalls === 0, 'D identical-fresh replay: clear count unchanged');
mtucAud020F01_assert(
    MtUniCreditFinancingTerminalNavigationSupport::cartClearAuthorizationKey($resultP1)
        === MtUniCreditFinancingTerminalNavigationSupport::cartClearAuthorizationKey($replayP1)
        && MtUniCreditFinancingTerminalNavigationSupport::cartClearAuthorizationKey($resultP1) !== '',
    'D: replay shares attempt clear authorization key (not fingerprint)'
);

// ---------------------------------------------------------------------------
// E. lost-response: terminal attempt, clear not yet applied, cart still populated
// ---------------------------------------------------------------------------
$sessionLost = array();
$transportLost = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportLost);
$stackLost = Phase9TestHarness::stack($transportLost);
$orderLost = 220103;
$inputLost = mtucAud020F01_cartInput($stackLost, $orderLost);
$resultLost = $stackLost['storefront']->submit($inputLost);
mtucAud020F01_assert(!empty($resultLost['success']), 'E lost: first terminal success');
$probeLost = new MtucAud020F01ProbeCart(5);
// Simulate lost browser response: handoff done, clear authorization not consumed yet.
$retryInput = $inputLost;
if (isset($resultLost['session']) && is_array($resultLost['session'])) {
    $retryInput['session'] = $resultLost['session'];
}
$retryLost = $stackLost['storefront']->submit($retryInput);
mtucAud020F01_assert(!empty($retryLost['success']) && !empty($retryLost['local_replay']), 'E lost: retry local_replay');
$clearedLost1 = MtUniCreditFinancingTerminalNavigationSupport::clearCartAfterSuccessfulHandoffOnce(
    $sessionLost,
    $retryLost,
    $probeLost
);
mtucAud020F01_assert($clearedLost1 === true && $probeLost->count === 0, 'E lost: clear exactly once on recovery');
mtucAud020F01_assert($probeLost->clearCalls === 1, 'E lost: clearCalls = 1');
$clearedLost2 = MtUniCreditFinancingTerminalNavigationSupport::clearCartAfterSuccessfulHandoffOnce(
    $sessionLost,
    $retryLost,
    $probeLost
);
mtucAud020F01_assert($clearedLost2 === false && $probeLost->clearCalls === 1, 'E lost: second retry no second clear');

// ---------------------------------------------------------------------------
// F. failure preserves cart (no clear authorization)
// ---------------------------------------------------------------------------
$sessionFail = array();
$transportFail = new Phase4FakeCpHttpTransport();
$payloadsFail = Phase7TestHarness::loginAndOrderSuccessPayloads();
$transportFail->enqueueJson(200, $payloadsFail['login']);
$transportFail->enqueueJson(422, array('success' => false, 'message' => 'invalid'));
$stackFail = Phase9TestHarness::stack($transportFail);
$resultFail = $stackFail['storefront']->submit(mtucAud020F01_cartInput($stackFail, 220104));
mtucAud020F01_assert(empty($resultFail['success']), 'F failure: not success');
$probeFail = new MtucAud020F01ProbeCart(3);
$clearedFail = MtUniCreditFinancingTerminalNavigationSupport::clearCartAfterSuccessfulHandoffOnce(
    $sessionFail,
    $resultFail,
    $probeFail
);
mtucAud020F01_assert($clearedFail === false && $probeFail->count === 3, 'F failure: cart preserved');

// Mutation canaries
$navSrc = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'financing_terminal_navigation_support.php');
mtucAud020F01_assert(
    strpos($navSrc, 'SESSION_CART_CLEAR_APPLIED') !== false
        && strpos($navSrc, 'clearCartAfterSuccessfulHandoffOnce') !== false,
    'mutation: one-shot session marker present'
);
mtucAud020F01_assert(
    strpos($navSrc, 'cartClearAuthorizationKey') !== false,
    'mutation: authorization key helper present'
);

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
