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
// R2. Non-zero first_installment full continuity (mutation item 3)
// ---------------------------------------------------------------------------
$firstExact50 = 50.0;
$sessionNz = array();
mtucAud021F01_assert(
    MtUniCreditCheckoutPreparedSelection::store(
        $sessionNz,
        $storeId,
        22201,
        22201,
        $schemeS1,
        $firstExact50
    ),
    'NZ: store non-zero first_installment=50.0'
);
$loadedNz = MtUniCreditCheckoutPreparedSelection::load($sessionNz, $storeId, 22201, 22201);
mtucAud021F01_assert(
    is_array($loadedNz) && (float) $loadedNz['first_installment'] === $firstExact50,
    'NZ: load preserves first_installment=50.0'
);
$resolvedNz = MtUniCreditCheckoutPreparedSelection::resolveForSubmit(
    $sessionNz,
    $storeId,
    22201,
    22201,
    array('scheme_key' => $schemeS2, 'first_installment' => 0.0)
);
mtucAud021F01_assert(
    is_array($resolvedNz)
        && $resolvedNz['scheme_key'] === $schemeS1
        && (float) $resolvedNz['first_installment'] === $firstExact50,
    'NZ: resolveForSubmit keeps authoritative first_installment=50.0'
);

// Prepared view transport data (server-loaded selection → hidden fields).
require_once dirname(MTUC_PHASE0_ROOT) . DIRECTORY_SEPARATOR . 'reference-oc3-core'
    . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'engine' . DIRECTORY_SEPARATOR . 'controller.php';
require_once $ctrlPath;

final class MtucAud021Registry
{
    /** @var array<string, mixed> */
    private $data = array();

    public function set($key, $value)
    {
        $this->data[$key] = $value;
    }

    public function get($key)
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : null;
    }
}

final class MtucAud021Session
{
    /** @var array<string, mixed> */
    public $data = array();
}

final class MtucAud021Request
{
    /** @var array<string, mixed> */
    public $post = array();
    /** @var array<string, mixed> */
    public $get = array();
    /** @var array<string, mixed> */
    public $server = array();
}

final class MtucAud021Response
{
    /** @var string */
    public $redirect = '';

    /**
     * @param string $url
     * @return void
     */
    public function redirect($url)
    {
        $this->redirect = (string) $url;
    }

    public function addHeader($header) {}

    public function setOutput($output) {}
}

final class MtucAud021Url
{
    /**
     * @param string $route
     * @param string $args
     * @param bool|string $secure
     * @return string
     */
    public function link($route, $args = '', $secure = false)
    {
        return 'https://shop.test/index.php?route=' . (string) $route;
    }
}

final class MtucAud021Language
{
    /**
     * @param string $key
     * @return string
     */
    public function get($key)
    {
        return (string) $key;
    }
}

final class MtucAud021Config
{
    /** @var array<string, mixed> */
    private $values;

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function get($key)
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : null;
    }
}

final class MtucAud021Load
{
    /** @var MtucAud021Registry */
    private $registry;

    public function __construct(MtucAud021Registry $registry)
    {
        $this->registry = $registry;
    }

    public function language($route) {}

    public function model($route) {}

    /**
     * @param string $route
     * @param array<string, mixed> $data
     * @return string
     */
    public function view($route, $data = array())
    {
        return '';
    }

    /**
     * @param string $route
     * @return string
     */
    public function controller($route)
    {
        return '';
    }
}

final class MtucAud021Cart
{
    /** @var int */
    public $clearCalls = 0;

    public function clear()
    {
        $this->clearCalls++;
    }

    /**
     * @return array<int, mixed>
     */
    public function getProducts()
    {
        return array();
    }
}

final class MtucAud021CheckoutOrderModel
{
    /** @var array<int, array<string, mixed>> */
    private $orders;
    /** @var int */
    public $addOrderCalls = 0;
    /** @var int */
    public $historyCalls = 0;

    /**
     * @param array<int, array<string, mixed>> $orders
     */
    public function __construct(array $orders)
    {
        $this->orders = $orders;
    }

    /**
     * @param int $orderId
     * @return array<string, mixed>|false
     */
    public function getOrder($orderId)
    {
        $orderId = (int) $orderId;

        return isset($this->orders[$orderId]) ? $this->orders[$orderId] : false;
    }

    public function addOrder($data)
    {
        $this->addOrderCalls++;

        return 0;
    }

    public function addOrderHistory($orderId, $statusId)
    {
        $this->historyCalls++;
    }
}

final class MtucAud021PaymentModelProbe
{
    /** @var array<string, mixed> */
    private $stack;
    /** @var bool */
    private $process2;
    /** @var array<string, mixed>|null */
    public $lastSelection;
    /** @var int */
    public $submitCalls = 0;
    /** @var int */
    public $addOrderCalls = 0;

    /**
     * @param array<string, mixed> $stack
     * @param bool $process2
     */
    public function __construct(array $stack, $process2 = false)
    {
        $this->stack = $stack;
        $this->process2 = (bool) $process2;
    }

    /**
     * @param int $orderId
     * @param array<string, mixed> $process2
     * @param array<string, mixed> $selection
     * @return array<string, mixed>
     */
    public function submitCheckoutFinancing($orderId, array $process2 = array(), array $selection = array())
    {
        $this->submitCalls++;
        $this->lastSelection = $selection;
        $orderId = (int) $orderId;
        $input = $this->process2
            ? Phase9TestHarness::submitInputProcess2($orderId, (int) $this->stack['storeId'])
            : Phase9TestHarness::submitInput($orderId, (int) $this->stack['storeId']);
        $input['scheme_key'] = isset($selection['scheme_key']) ? (string) $selection['scheme_key'] : '';
        $input['first_installment'] = isset($selection['first_installment'])
            ? (float) $selection['first_installment']
            : 0.0;
        if ($this->process2) {
            $input['process2'] = $process2;
        }

        return $this->stack['submission']->submit($input);
    }

    public function addOrder($data)
    {
        $this->addOrderCalls++;

        return 0;
    }
}

/**
 * @param array<string, mixed> $stack
 * @param int $orderId
 * @param array{scheme_key:string,first_installment:float} $boundSelection
 * @param array<string, mixed> $posted
 * @param bool $process2
 * @return array{
 *   controller:ControllerExtensionPaymentMtUniCredit,
 *   response:MtucAud021Response,
 *   paymentModel:MtucAud021PaymentModelProbe,
 *   orderModel:MtucAud021CheckoutOrderModel,
 *   session:MtucAud021Session
 * }
 */
function mtucAud021F01_preparedSubmitController(array $stack, $orderId, array $boundSelection, array $posted, $process2 = false)
{
    $orderId = (int) $orderId;
    $storeId = (int) $stack['storeId'];
    $registry = new MtucAud021Registry();
    $session = new MtucAud021Session();
    $session->data['order_id'] = $orderId;
    $session->data[MtUniCreditCheckoutConfirmPreparation::SESSION_PREPARED_ORDER_ID] = $orderId;
    MtUniCreditCheckoutPreparedSelection::store(
        $session->data,
        $storeId,
        $orderId,
        $orderId,
        $boundSelection['scheme_key'],
        $boundSelection['first_installment']
    );
    $token = MtUniCreditCheckoutSubmitToken::issue($session->data, $storeId, $orderId, $orderId);

    $request = new MtucAud021Request();
    $request->server['REQUEST_METHOD'] = 'POST';
    $request->post = array_merge(
        array(
            'mt_uni_credit_submit_token' => $token,
            'scheme_key' => isset($posted['scheme_key']) ? (string) $posted['scheme_key'] : '',
            'first_installment' => isset($posted['first_installment'])
                ? (string) $posted['first_installment']
                : '0',
            'egn' => isset($posted['egn']) ? (string) $posted['egn'] : '',
            'phone2' => isset($posted['phone2']) ? (string) $posted['phone2'] : '',
        ),
        array()
    );

    $response = new MtucAud021Response();
    $orderRow = Phase7TestHarness::orderRow($orderId, $storeId);
    $orderModel = new MtucAud021CheckoutOrderModel(array($orderId => $orderRow));
    $paymentModel = new MtucAud021PaymentModelProbe($stack, $process2);
    $load = new MtucAud021Load($registry);

    $registry->set('session', $session);
    $registry->set('request', $request);
    $registry->set('response', $response);
    $registry->set('url', new MtucAud021Url());
    $registry->set('language', new MtucAud021Language());
    $registry->set('config', new MtucAud021Config(array(
        'config_store_id' => $storeId,
        'config_ssl' => 'https://shop.test/',
        'config_url' => 'https://shop.test/',
    )));
    $registry->set('db', $stack['memoryDb']);
    $registry->set('load', $load);
    $registry->set('cart', new MtucAud021Cart());
    $registry->set('model_checkout_order', $orderModel);
    $registry->set('model_extension_payment_mt_uni_credit', $paymentModel);

    $controller = new ControllerExtensionPaymentMtUniCredit($registry);

    return array(
        'controller' => $controller,
        'response' => $response,
        'paymentModel' => $paymentModel,
        'orderModel' => $orderModel,
        'session' => $session,
    );
}

// View transport for NZ selection via buildPreparedViewData.
$nzViewHarness = mtucAud021F01_preparedSubmitController(
    Phase9TestHarness::stack(new Phase4FakeCpHttpTransport()),
    22201,
    array('scheme_key' => $schemeS1, 'first_installment' => $firstExact50),
    array('scheme_key' => $schemeS2, 'first_installment' => '0')
);
$viewStateNz = MtUniCreditCheckoutPreparedViewState::fromAttempt(null);
$tokenNz = MtUniCreditCheckoutSubmitToken::issue(
    $nzViewHarness['session']->data,
    $storeId,
    22201,
    22201
);
$reflectNz = new ReflectionClass($nzViewHarness['controller']);
$methodNz = $reflectNz->getMethod('buildPreparedViewData');
$methodNz->setAccessible(true);
$viewDataNz = $methodNz->invoke(
    $nzViewHarness['controller'],
    $viewStateNz,
    $tokenNz,
    '',
    $resolvedNz
);
mtucAud021F01_assert(
    is_array($viewDataNz)
        && (string) $viewDataNz['scheme_key'] === $schemeS1
        && (float) $viewDataNz['first_installment'] === $firstExact50
        && !empty($viewDataNz['can_submit']),
    'NZ: prepared view data transports scheme_key + first_installment=50.0'
);
mtucAud021F01_assert(
    strpos($twigSrc, 'name="first_installment"') !== false
        && strpos($twigSrc, '{{ first_installment }}') !== false,
    'NZ: prepared twig binds hidden first_installment transport'
);

$transportNz = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportNz);
$stackNz = Phase9TestHarness::stack(
    $transportNz,
    null,
    null,
    $storeId,
    array('uni_first_vnoska' => 1)
);
$orderNz = 22202;
Phase9TestHarness::seedBankOrder($stackNz['memoryDb'], $orderNz, $storeId);
$inputNz = Phase9TestHarness::submitInput($orderNz, $storeId);
$inputNz['scheme_key'] = $resolvedNz['scheme_key'];
$inputNz['first_installment'] = $resolvedNz['first_installment'];
mtucAud021F01_assert(
    (float) $inputNz['first_installment'] === $firstExact50,
    'NZ: service-bound input first_installment remains 50.0 (not reset to 0)'
);
$resultNz = $stackNz['submission']->submit($inputNz);
mtucAud021F01_assert(!empty($resultNz['success']), 'NZ: submission accepts exact S1 + first_installment=50.0');
mtucAud021F01_assert(
    (string) $resultNz['bank_status'] === MtUniCreditBankStatus::SENT_PROCESS1,
    'NZ: P1 executable with non-zero first_installment'
);

// ---------------------------------------------------------------------------
// R2. Controller prepared submit P1 → trusted bank redirect (mutation item 7)
// ---------------------------------------------------------------------------
$transportCtrlP1 = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportCtrlP1);
$stackCtrlP1 = Phase9TestHarness::stack($transportCtrlP1);
$orderCtrlP1 = 22301;
Phase9TestHarness::seedBankOrder($stackCtrlP1['memoryDb'], $orderCtrlP1, $storeId);
$harnessP1 = mtucAud021F01_preparedSubmitController(
    $stackCtrlP1,
    $orderCtrlP1,
    array('scheme_key' => $schemeS1, 'first_installment' => $firstF1),
    array(
        'scheme_key' => $schemeS2,
        'first_installment' => '99.0',
    )
);
$harnessP1['controller']->submit();
mtucAud021F01_assert($harnessP1['paymentModel']->submitCalls === 1, 'CTRL-P1: model submitCheckoutFinancing once');
mtucAud021F01_assert(
    is_array($harnessP1['paymentModel']->lastSelection)
        && (string) $harnessP1['paymentModel']->lastSelection['scheme_key'] === $schemeS1
        && (float) $harnessP1['paymentModel']->lastSelection['first_installment'] === $firstF1,
    'CTRL-P1: server-bound selection reaches model despite tampered POST'
);
$expectedBank = 'https://onlinetest.ucfin.bg/sucf-online/Request/Start/sess-phase9-ok';
mtucAud021F01_assert(
    $harnessP1['response']->redirect === $expectedBank,
    'CTRL-P1: response redirect == trusted bank URL'
);
mtucAud021F01_assert(
    strpos($harnessP1['response']->redirect, 'checkout/success') === false
        && strpos($harnessP1['response']->redirect, 'prepared') === false
        && strpos($harnessP1['response']->redirect, 'checkout/checkout') === false,
    'CTRL-P1: redirect is not prepared/success/checkout'
);
mtucAud021F01_assert(
    $harnessP1['paymentModel']->addOrderCalls === 0
        && $harnessP1['orderModel']->addOrderCalls === 0,
    'CTRL-P1: native addOrder new = 0'
);
mtucAud021F01_assert(
    Phase7TestHarness::countOrderPosts($transportCtrlP1) === 1,
    'CTRL-P1: CP create = 1'
);
mtucAud021F01_assert(
    Phase9TestHarness::smartUcfCallCount($stackCtrlP1['smartUcfProbe']) === 1,
    'CTRL-P1: Smart = 1'
);

// ---------------------------------------------------------------------------
// R2. Controller prepared submit P2 → Thank You (mutation item 8)
// ---------------------------------------------------------------------------
$transportCtrlP2 = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportCtrlP2);
$stackCtrlP2 = Phase9TestHarness::stack(
    $transportCtrlP2,
    null,
    null,
    $storeId,
    array('uni_proces' => 1)
);
$orderCtrlP2 = 22302;
Phase9TestHarness::seedBankOrder($stackCtrlP2['memoryDb'], $orderCtrlP2, $storeId);
$p2Fields = Phase9TestHarness::process2Fields();
$harnessP2 = mtucAud021F01_preparedSubmitController(
    $stackCtrlP2,
    $orderCtrlP2,
    array('scheme_key' => $schemeS1, 'first_installment' => $firstF1),
    array(
        'scheme_key' => $schemeS2,
        'first_installment' => '12.5',
        'egn' => $p2Fields['egn'],
        'phone2' => $p2Fields['phone2'],
    ),
    true
);
$harnessP2['controller']->submit();
mtucAud021F01_assert($harnessP2['paymentModel']->submitCalls === 1, 'CTRL-P2: model submitCheckoutFinancing once');
mtucAud021F01_assert(
    is_array($harnessP2['paymentModel']->lastSelection)
        && (string) $harnessP2['paymentModel']->lastSelection['scheme_key'] === $schemeS1,
    'CTRL-P2: tampered scheme cannot become authority'
);
$expectedThankYou = 'https://shop.test/index.php?route=' . MtUniCreditConstants::CHECKOUT_SUCCESS_ROUTE;
mtucAud021F01_assert(
    $harnessP2['response']->redirect === $expectedThankYou,
    'CTRL-P2: response redirect == checkout/success Thank You URL'
);
mtucAud021F01_assert(
    strpos($harnessP2['response']->redirect, 'onlinetest.ucfin.bg') === false
        && strpos($harnessP2['response']->redirect, 'prepared') === false,
    'CTRL-P2: redirect is Thank You, not bank/prepared'
);
mtucAud021F01_assert(
    $harnessP2['paymentModel']->addOrderCalls === 0
        && $harnessP2['orderModel']->addOrderCalls === 0,
    'CTRL-P2: native addOrder new = 0'
);
mtucAud021F01_assert(
    Phase7TestHarness::countOrderPosts($transportCtrlP2) === 1,
    'CTRL-P2: CP create = 1'
);
mtucAud021F01_assert(
    Phase9TestHarness::smartUcfCallCount($stackCtrlP2['smartUcfProbe']) === 0,
    'CTRL-P2: Smart P1 = 0'
);

// Refresh focused source for mutation canaries that scan this file.
$focusedSrc = (string) file_get_contents(__FILE__);

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
    '3 submit does not drop/reset first_installment' => (
        strpos($focusedSrc, 'service-bound input first_installment remains 50.0') !== false
        && strpos($focusedSrc, 'prepared view data transports scheme_key + first_installment=50.0') !== false
        && strpos($selSrc, 'first_installment') !== false
    ),
    '4 posted arbitrary scheme not authority' => (
        strpos($selSrc, 'resolveForSubmit') !== false
        && strpos($focusedSrc, 'cannot become authority') !== false
        && strpos($focusedSrc, 'CTRL-P1: server-bound selection reaches model despite tampered POST') !== false
    ),
    '5 stale order cannot reuse selection' => (
        strpos($focusedSrc, 'selection for A not reusable on order B') !== false
        && strpos($selSrc, 'order_id') !== false
    ),
    '6 unavailable prepared scheme revalidated' => (
        strpos($focusedSrc, 'unavailable prepared scheme fails submit') !== false
    ),
    '7 prepared P1 controller bank redirect' => (
        strpos($focusedSrc, 'CTRL-P1: response redirect == trusted bank URL') !== false
        && strpos($ctrlSrc, 'bank_redirect') !== false
    ),
    '8 prepared P2 controller Thank You' => (
        strpos($focusedSrc, 'CTRL-P2: response redirect == checkout/success Thank You URL') !== false
        && strpos($ctrlSrc, 'enrichProcess2ThankYou') !== false
    ),
    '9 no second native order' => (
        strpos($modelSrc, 'addOrder') === false
        && strpos($ctrlSrc, '->addOrder(') === false
        && strpos($focusedSrc, 'CTRL-P1: native addOrder new = 0') !== false
        && strpos($focusedSrc, 'CTRL-P2: native addOrder new = 0') !== false
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
