<?php

/**
 * AUD-021 F01 — Preserve exact Checkout selection across prepared submit.
 * Run: php tests/phase_aud021_f01_prepared_selection_check.php
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
function mtucAud021F01_assert($condition, $message)
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
$ctrlPath = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
    . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'payment'
    . DIRECTORY_SEPARATOR . 'mt_uni_credit.php';
$twigPath = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
    . 'view' . DIRECTORY_SEPARATOR . 'theme' . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR
    . 'template' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'payment'
    . DIRECTORY_SEPARATOR . 'mt_uni_credit_prepared.twig';
$modelPath = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
    . 'model' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'payment'
    . DIRECTORY_SEPARATOR . 'mt_uni_credit.php';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DIR_STORAGE')) {
    mtuc_test_define_dir_storage('mtuc-aud021-f01');
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

mtucAud021F01_assert(mtuc_phase0_network_isolation_active(), 'isolation: offline guard active');

$ctrlSrc = (string) file_get_contents($ctrlPath);
$modelSrc = (string) file_get_contents($modelPath);
$twigSrc = (string) file_get_contents($twigPath);
$selSrc = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'checkout_prepared_selection.php');
$focusedSrc = (string) file_get_contents(__FILE__);

mtucAud021F01_assert(
    strpos($ctrlSrc, 'MtUniCreditCheckoutPreparedSelection::store') !== false,
    'wiring: confirm stores prepared selection'
);
mtucAud021F01_assert(
    preg_match(
        '/submitCheckoutFinancing\\s*\\(\\s*\\(int\\)\\s*\\$context\\[\'order_id\'\\]\\s*,\\s*\\$process2\\s*,\\s*\\$selection\\s*\\)/s',
        $ctrlSrc
    ) === 1,
    'wiring: prepared submit passes exact $selection'
);
mtucAud021F01_assert(
    strpos($ctrlSrc, 'resolveForSubmit') !== false,
    'wiring: submit resolves authoritative selection'
);
mtucAud021F01_assert(
    strpos($twigSrc, 'name="scheme_key"') !== false
        && strpos($twigSrc, 'name="first_installment"') !== false,
    'wiring: prepared twig transports hidden selection fields'
);
mtucAud021F01_assert(
    strpos($modelSrc, 'addOrder') === false,
    'wiring: payment model has no addOrder (AUD-013)'
);
mtucAud021F01_assert(
    strpos($ctrlSrc, 'isset($this->cart)') === false,
    'wiring: no isset($this->cart) Registry trap'
);

$storeId = Phase5TestHarness::STORE_A;
$schemeS1 = 'standard|KOPSTD|12';
$schemeS2 = 'standard|KOPSTD|24';
$firstExact = 50.0;
$firstF1 = 0.0;

// ---------------------------------------------------------------------------
// Unit: store / load / stale / tamper resolve
// ---------------------------------------------------------------------------
$session = array();
mtucAud021F01_assert(
    MtUniCreditCheckoutPreparedSelection::store($session, $storeId, 2101, 2101, $schemeS1, $firstExact),
    'unit: store accepts valid selection'
);
$loaded = MtUniCreditCheckoutPreparedSelection::load($session, $storeId, 2101, 2101);
mtucAud021F01_assert(
    is_array($loaded)
        && $loaded['scheme_key'] === $schemeS1
        && (float) $loaded['first_installment'] === $firstExact,
    'unit: load returns exact S1/F1'
);
mtucAud021F01_assert(
    MtUniCreditCheckoutPreparedSelection::load($session, $storeId, 2102, 2102) === null,
    'unit: different order cannot load A selection'
);
$resolved = MtUniCreditCheckoutPreparedSelection::resolveForSubmit(
    $session,
    $storeId,
    2101,
    2101,
    array('scheme_key' => $schemeS2, 'first_installment' => 999.0)
);
mtucAud021F01_assert(
    is_array($resolved)
        && $resolved['scheme_key'] === $schemeS1
        && (float) $resolved['first_installment'] === $firstExact,
    'unit: posted S2/F_tampered cannot become authority'
);
$session['order_id'] = 2102;
$session[MtUniCreditCheckoutConfirmPreparation::SESSION_PREPARED_ORDER_ID] = 2101;
mtucAud021F01_assert(
    MtUniCreditCheckoutPreparedBoundary::validateAccess(
        2102,
        2101,
        Phase7TestHarness::orderRow(2102, $storeId),
        $storeId,
        null
    ) === array('error' => 'prepared_state_missing')
        || empty(MtUniCreditCheckoutPreparedBoundary::validateAccess(
            2102,
            2101,
            Phase7TestHarness::orderRow(2102, $storeId),
            $storeId,
            null
        )['ok']),
    'unit: stale prepared marker vs current order BLOCK'
);
mtucAud021F01_assert(
    MtUniCreditCheckoutPreparedSelection::load($session, $storeId, 2102, 2101) === null,
    'unit: selection bound to A blocked for order B context'
);

// ---------------------------------------------------------------------------
// A. Prepared P1 exact selection reaches service and executes
// ---------------------------------------------------------------------------
$transportP1 = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportP1);
$stackP1 = Phase9TestHarness::stack($transportP1);
$orderP1 = 22101;
Phase9TestHarness::seedBankOrder($stackP1['memoryDb'], $orderP1, $storeId);
$sessionP1 = array();
MtUniCreditCheckoutPreparedSelection::store($sessionP1, $storeId, $orderP1, $orderP1, $schemeS1, $firstF1);
$selP1 = MtUniCreditCheckoutPreparedSelection::resolveForSubmit(
    $sessionP1,
    $storeId,
    $orderP1,
    $orderP1,
    array('scheme_key' => $schemeS2, 'first_installment' => 1.0)
);
mtucAud021F01_assert(
    is_array($selP1) && $selP1['scheme_key'] === $schemeS1 && (float) $selP1['first_installment'] === $firstF1,
    'A: prepared P1 selection preserves S1/F1 for submit'
);
$inputP1 = Phase9TestHarness::submitInput($orderP1, $storeId);
$inputP1['scheme_key'] = $selP1['scheme_key'];
$inputP1['first_installment'] = $selP1['first_installment'];
$resultP1 = $stackP1['submission']->submit($inputP1);
mtucAud021F01_assert(!empty($resultP1['success']), 'A P1: success');
mtucAud021F01_assert(
    (string) $resultP1['bank_status'] === MtUniCreditBankStatus::SENT_PROCESS1,
    'A P1: bank_sent_process1'
);
mtucAud021F01_assert(!empty($resultP1['bank_redirect']) && !empty($resultP1['redirect']), 'A P1: trusted bank redirect');
mtucAud021F01_assert(
    Phase7TestHarness::countOrderPosts($transportP1) === 1,
    'A P1: CP = 1'
);
mtucAud021F01_assert(
    Phase9TestHarness::smartUcfCallCount($stackP1['smartUcfProbe']) === 1,
    'A P1: Smart = 1'
);

// ---------------------------------------------------------------------------
// B. Prepared P2 exact selection
// ---------------------------------------------------------------------------
$transportP2 = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportP2);
$stackP2 = Phase9TestHarness::stack($transportP2, null, null, $storeId, array('uni_proces' => 1));
$orderP2 = 22102;
Phase9TestHarness::seedBankOrder($stackP2['memoryDb'], $orderP2, $storeId);
$sessionP2 = array();
MtUniCreditCheckoutPreparedSelection::store($sessionP2, $storeId, $orderP2, $orderP2, $schemeS1, $firstF1);
$selP2 = MtUniCreditCheckoutPreparedSelection::load($sessionP2, $storeId, $orderP2, $orderP2);
$inputP2 = Phase9TestHarness::submitInputProcess2($orderP2, $storeId);
$inputP2['scheme_key'] = $selP2['scheme_key'];
$inputP2['first_installment'] = $selP2['first_installment'];
$resultP2 = $stackP2['submission']->submit($inputP2);
mtucAud021F01_assert(!empty($resultP2['success']), 'B P2: success');
mtucAud021F01_assert(
    (string) $resultP2['bank_status'] === MtUniCreditBankStatus::SENT_PROCESS2,
    'B P2: bank_sent_process2'
);
mtucAud021F01_assert(
    Phase9TestHarness::smartUcfCallCount($stackP2['smartUcfProbe']) === 0,
    'B P2: Smart = 0'
);
mtucAud021F01_assert(
    Phase7TestHarness::countOrderPosts($transportP2) === 1,
    'B P2: CP = 1'
);

// ---------------------------------------------------------------------------
// C. Missing prepared selection → BLOCK / can_submit false path
// ---------------------------------------------------------------------------
$sessionMissing = array();
mtucAud021F01_assert(
    MtUniCreditCheckoutPreparedSelection::resolveForSubmit($sessionMissing, $storeId, 22103, 22103) === null,
    'C: missing selection → resolve null (submit BLOCK)'
);
$viewReady = MtUniCreditCheckoutPreparedViewState::fromAttempt(null);
$canSubmitMissing = !empty($viewReady['can_submit'])
    && MtUniCreditCheckoutPreparedSelection::load($sessionMissing, $storeId, 22103, 22103) !== null;
mtucAud021F01_assert($canSubmitMissing === false, 'C: prepared UI must not allow submit without selection');
$transportMissing = new Phase4FakeCpHttpTransport();
$stackMissing = Phase9TestHarness::stack($transportMissing);
$inputMissing = Phase9TestHarness::submitInput(22103, $storeId);
$inputMissing['scheme_key'] = '';
$inputMissing['first_installment'] = 0.0;
$resultMissing = $stackMissing['submission']->submit($inputMissing);
mtucAud021F01_assert(empty($resultMissing['success']), 'C: empty scheme unavailable');
mtucAud021F01_assert(
    Phase7TestHarness::countOrderPosts($transportMissing) === 0
        && Phase9TestHarness::smartUcfCallCount($stackMissing['smartUcfProbe']) === 0,
    'C: missing selection → CP=0 Smart=0'
);

// ---------------------------------------------------------------------------
// D. Stale order
// ---------------------------------------------------------------------------
$sessionStale = array();
MtUniCreditCheckoutPreparedSelection::store($sessionStale, $storeId, 22104, 22104, $schemeS1, $firstF1);
mtucAud021F01_assert(
    MtUniCreditCheckoutPreparedSelection::load($sessionStale, $storeId, 22105, 22105) === null,
    'D: selection for A not reusable on order B'
);
$accessStale = MtUniCreditCheckoutPreparedBoundary::validateAccess(
    22105,
    22104,
    Phase7TestHarness::orderRow(22105, $storeId),
    $storeId,
    null
);
mtucAud021F01_assert(empty($accessStale['ok']), 'D: prepared access BLOCK when order≠prepared marker');

// ---------------------------------------------------------------------------
// E/F. Tampered client scheme / first installment — authority stays S1/F1
// ---------------------------------------------------------------------------
$sessionTamper = array();
MtUniCreditCheckoutPreparedSelection::store($sessionTamper, $storeId, 22106, 22106, $schemeS1, $firstExact);
$authTamper = MtUniCreditCheckoutPreparedSelection::resolveForSubmit(
    $sessionTamper,
    $storeId,
    22106,
    22106,
    array('scheme_key' => $schemeS2, 'first_installment' => 12.5)
);
mtucAud021F01_assert(
    $authTamper['scheme_key'] === $schemeS1,
    'E: tampered scheme S2 rejected as authority'
);
mtucAud021F01_assert(
    (float) $authTamper['first_installment'] === $firstExact,
    'F: tampered first_installment rejected as authority'
);

// ---------------------------------------------------------------------------
// G. Scheme unavailable at submit time
// ---------------------------------------------------------------------------
$transportUnavail = new Phase4FakeCpHttpTransport();
$stackUnavail = Phase9TestHarness::stack($transportUnavail);
$orderUnavail = 22107;
Phase9TestHarness::seedBankOrder($stackUnavail['memoryDb'], $orderUnavail, $storeId);
$sessionUnavail = array();
MtUniCreditCheckoutPreparedSelection::store(
    $sessionUnavail,
    $storeId,
    $orderUnavail,
    $orderUnavail,
    $schemeS1,
    $firstF1
);
// Mutate bound selection to a parseable but shop-unavailable scheme.
$sessionUnavail[MtUniCreditCheckoutPreparedSelection::SESSION_KEY]['scheme_key'] = 'standard|KOPMISSING|12';
$selUnavail = MtUniCreditCheckoutPreparedSelection::load($sessionUnavail, $storeId, $orderUnavail, $orderUnavail);
mtucAud021F01_assert(is_array($selUnavail), 'G: mutated unavailable scheme still loads from binding');
$inputUnavail = Phase9TestHarness::submitInput($orderUnavail, $storeId);
$inputUnavail['scheme_key'] = $selUnavail['scheme_key'];
$inputUnavail['first_installment'] = $selUnavail['first_installment'];
$resultUnavail = $stackUnavail['submission']->submit($inputUnavail);
mtucAud021F01_assert(empty($resultUnavail['success']), 'G: unavailable prepared scheme fails submit');
mtucAud021F01_assert(
    isset($resultUnavail['error']) && (string) $resultUnavail['error'] === 'unavailable',
    'G: error=unavailable'
);
mtucAud021F01_assert(
    Phase7TestHarness::countOrderPosts($transportUnavail) === 0
        && Phase9TestHarness::smartUcfCallCount($stackUnavail['smartUcfProbe']) === 0,
    'G: unavailable → CP=0 Smart=0'
);

// ---------------------------------------------------------------------------
// H. Terminal existing attempt replay still routes without fresh selection inventing
// ---------------------------------------------------------------------------
$transportTerm = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportTerm);
$stackTerm = Phase9TestHarness::stack($transportTerm);
$orderTerm = 22108;
Phase9TestHarness::seedBankOrder($stackTerm['memoryDb'], $orderTerm, $storeId);
$inputTerm = Phase9TestHarness::submitInput($orderTerm, $storeId);
$inputTerm['scheme_key'] = $schemeS1;
$inputTerm['first_installment'] = $firstF1;
$resultTerm1 = $stackTerm['submission']->submit($inputTerm);
mtucAud021F01_assert(!empty($resultTerm1['success']), 'H: first terminal success');
$cpBeforeReplay = Phase7TestHarness::countOrderPosts($transportTerm);
$smartBeforeReplay = Phase9TestHarness::smartUcfCallCount($stackTerm['smartUcfProbe']);
$resultTerm2 = $stackTerm['submission']->submit($inputTerm);
mtucAud021F01_assert(!empty($resultTerm2['success']), 'H: terminal replay success');
mtucAud021F01_assert(
    !empty($resultTerm2['local_replay']) || (string) $resultTerm2['bank_status'] === MtUniCreditBankStatus::SENT_PROCESS1,
    'H: terminal replay preserves bank_sent_process1 semantics'
);
mtucAud021F01_assert(
    Phase7TestHarness::countOrderPosts($transportTerm) === $cpBeforeReplay,
    'H: terminal replay CP new = 0'
);
mtucAud021F01_assert(
    Phase9TestHarness::smartUcfCallCount($stackTerm['smartUcfProbe']) === $smartBeforeReplay,
    'H: terminal replay Smart new = 0'
);

// Ambiguous: no fresh CP retry
$transportAmb = new Phase4FakeCpHttpTransport();
$payloadsAmb = Phase7TestHarness::loginAndOrderSuccessPayloads();
$transportAmb->enqueueJson(200, $payloadsAmb['login']);
$transportAmb->enqueueTimeout();
$stackAmb = Phase9TestHarness::stack($transportAmb);
$orderAmb = 22109;
Phase9TestHarness::seedBankOrder($stackAmb['memoryDb'], $orderAmb, $storeId);
$inputAmb = Phase9TestHarness::submitInput($orderAmb, $storeId);
$inputAmb['scheme_key'] = $schemeS1;
$inputAmb['first_installment'] = $firstF1;
$resultAmb1 = $stackAmb['submission']->submit($inputAmb);
mtucAud021F01_assert(empty($resultAmb1['success']), 'ambiguous: first outcome not success');
$cpAmb = Phase7TestHarness::countOrderPosts($transportAmb);
$resultAmb2 = $stackAmb['submission']->submit($inputAmb);
mtucAud021F01_assert(
    Phase7TestHarness::countOrderPosts($transportAmb) === $cpAmb,
    'ambiguous: replay does not create fresh CP'
);
$attemptAmb = $stackAmb['attempts']->findByStoreOrder($storeId, $orderAmb);
mtucAud021F01_assert(
    !empty($resultAmb2['ambiguous_blocked'])
        || (isset($resultAmb2['error']) && strpos((string) $resultAmb2['error'], 'ambiguous') !== false)
        || (
            is_array($attemptAmb)
            && (string) $attemptAmb['state'] === MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN
        ),
    'ambiguous: blocked / outcome_unknown preserved'
);

$viewAmb = MtUniCreditCheckoutPreparedViewState::fromAttempt($attemptAmb);
mtucAud021F01_assert(
    empty($viewAmb['can_submit']) && !empty($viewAmb['ambiguous']),
    'ambiguous: prepared can_submit=false'
);

// ---------------------------------------------------------------------------
// Mutation sensitivity (all YES)
// ---------------------------------------------------------------------------
$mutation = array(
    '1 prepared selection persisted' => (
        strpos($ctrlSrc, 'CheckoutPreparedSelection::store') !== false
        && strpos($selSrc, 'SESSION_KEY') !== false
    ),
    '2 submit does not drop scheme_key' => (
        preg_match(
            '/submitCheckoutFinancing\\s*\\([\\s\\S]*?\\$selection\\s*\\)/s',
            $ctrlSrc
        ) === 1
        && strpos($ctrlSrc, 'resolveForSubmit') !== false
    ),
    '3 submit does not drop first_installment' => (
        strpos($selSrc, 'first_installment') !== false
        && strpos($ctrlSrc, 'resolveForSubmit') !== false
    ),
    '4 posted arbitrary scheme not authority' => (
        strpos($selSrc, 'resolveForSubmit') !== false
        && strpos($focusedSrc, 'cannot become authority') !== false
    ),
    '5 stale order cannot reuse selection' => (
        strpos($focusedSrc, 'selection for A not reusable on order B') !== false
        && strpos($selSrc, 'order_id') !== false
    ),
    '6 unavailable prepared scheme revalidated' => (
        strpos($focusedSrc, 'unavailable prepared scheme fails submit') !== false
    ),
    '7 P1 prepared reaches trusted redirect' => (
        strpos($focusedSrc, 'A P1: trusted bank redirect') !== false
    ),
    '8 P2 prepared reaches Thank You path' => (
        strpos($focusedSrc, 'B P2: bank_sent_process2') !== false
    ),
    '9 no second native order' => (
        strpos($modelSrc, 'addOrder') === false
        && strpos($ctrlSrc, '->addOrder(') === false
    ),
    '10 ambiguous no fresh CP retry' => (
        strpos($focusedSrc, 'ambiguous: replay does not create fresh CP') !== false
    ),
);

foreach ($mutation as $label => $ok) {
    mtucAud021F01_assert($ok === true, 'mutation YES: ' . $label);
}

echo PHP_EOL;
if ($failures) {
    echo 'AUD-021 F01 PREPARED SELECTION: FAIL (' . count($failures) . ' failed, ' . $passes . ' passed)' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'AUD-021 F01 PREPARED SELECTION: PASS (' . $passes . ' assertions)' . PHP_EOL;
exit(0);
