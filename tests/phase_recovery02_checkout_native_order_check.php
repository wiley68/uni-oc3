<?php

/**
 * RECOVERY-02 — Checkout native-order confirm uses OC3 magic registry access.
 * Run: php tests/phase_recovery02_checkout_native_order_check.php
 *
 * Root cause: AUD-013 gated actor/currency resolution on isset($this->customer|/currency).
 * OC3 Model has __get but not __isset, so logged-in ownership collapsed to guest and
 * currency_value became null → prepare failed as ownership (UI: order_missing) or
 * order_changed.
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
function mtucRecovery02_assert($condition, $message)
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
    mtuc_test_define_dir_storage('mtuc-recovery02');
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

mtucRecovery02_assert(mtuc_phase0_network_isolation_active(), 'isolation: offline guard active');

$paymentModelPath = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog'
    . DIRECTORY_SEPARATOR . 'model' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR
    . 'payment' . DIRECTORY_SEPARATOR . 'mt_uni_credit.php';
$paymentModelSrc = file_get_contents($paymentModelPath);
mtucRecovery02_assert(is_string($paymentModelSrc) && $paymentModelSrc !== '', 'payment model readable');
mtucRecovery02_assert(
    is_string($paymentModelSrc) && strpos($paymentModelSrc, 'isset($this->customer)') === false,
    'production: no isset($this->customer) gate'
);
mtucRecovery02_assert(
    is_string($paymentModelSrc) && strpos($paymentModelSrc, 'isset($this->currency)') === false,
    'production: no isset($this->currency) gate'
);
mtucRecovery02_assert(
    is_string($paymentModelSrc) && strpos($paymentModelSrc, '$this->customer') !== false,
    'production: actor still reads $this->customer via magic __get'
);
mtucRecovery02_assert(
    is_string($paymentModelSrc) && strpos($paymentModelSrc, '$this->currency') !== false,
    'production: currency still reads $this->currency via magic __get'
);

/**
 * OC3-like registry host: __get works, isset() does not.
 */
final class MtucRecovery02RegistryHost
{
    /** @var array<string, mixed> */
    private $data = array();

    /**
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set($key, $value)
    {
        $this->data[(string) $key] = $value;
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function __get($key)
    {
        $key = (string) $key;
        if (!array_key_exists($key, $this->data)) {
            throw new RuntimeException('Undefined registry key: ' . $key);
        }

        return $this->data[$key];
    }
}

/**
 * Mirrors fixed production actor resolution (no isset gate).
 *
 * @param object $host
 * @return array{customer_id: int, is_guest: bool, guest_email: string}
 */
function mtucRecovery02_resolveActor($host)
{
    $customerId = 0;
    try {
        $customer = $host->customer;
        if (
            is_object($customer)
            && method_exists($customer, 'isLogged')
            && $customer->isLogged()
            && method_exists($customer, 'getId')
        ) {
            $customerId = (int) $customer->getId();
        }
    } catch (Exception $exception) {
        $customerId = 0;
    }

    if ($customerId > 0) {
        return array(
            'customer_id' => $customerId,
            'is_guest' => false,
            'guest_email' => '',
        );
    }

    $guestEmail = '';
    if (isset($host->session->data['guest']['email'])) {
        $guestEmail = (string) $host->session->data['guest']['email'];
    }

    return array(
        'customer_id' => 0,
        'is_guest' => true,
        'guest_email' => $guestEmail,
    );
}

/**
 * Broken AUD-013 pattern using isset().
 *
 * @param object $host
 * @return array{customer_id: int, is_guest: bool, guest_email: string}
 */
function mtucRecovery02_brokenActor($host)
{
    $customerId = 0;
    if (
        isset($host->customer)
        && is_object($host->customer)
        && method_exists($host->customer, 'isLogged')
        && $host->customer->isLogged()
        && method_exists($host->customer, 'getId')
    ) {
        $customerId = (int) $host->customer->getId();
    }

    if ($customerId > 0) {
        return array(
            'customer_id' => $customerId,
            'is_guest' => false,
            'guest_email' => '',
        );
    }

    return array(
        'customer_id' => 0,
        'is_guest' => true,
        'guest_email' => '',
    );
}

/**
 * @param object $host
 * @param string $code
 * @return float|null
 */
function mtucRecovery02_resolveCurrencyValue($host, $code)
{
    $code = trim((string) $code);
    if ($code === '') {
        return null;
    }
    try {
        $currency = $host->currency;
        if (!is_object($currency) || !method_exists($currency, 'getValue')) {
            return null;
        }
        $value = $currency->getValue($code);
    } catch (Exception $exception) {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }

    return (float) $value;
}

/**
 * @param object $host
 * @param string $code
 * @return float|null
 */
function mtucRecovery02_brokenCurrencyValue($host, $code)
{
    $code = trim((string) $code);
    if (
        $code === ''
        || !isset($host->currency)
        || !is_object($host->currency)
        || !method_exists($host->currency, 'getValue')
    ) {
        return null;
    }

    return (float) $host->currency->getValue($code);
}

$customer = new class {
    public function isLogged()
    {
        return true;
    }

    public function getId()
    {
        return 77;
    }
};
$currency = new class {
    /**
     * @param string $code
     * @return float
     */
    public function getValue($code)
    {
        return $code === 'BGN' ? 1.0 : 0.0;
    }
};
$session = (object) array(
    'data' => array(
        'guest' => array('email' => 'stale@example.com'),
    ),
);

$host = new MtucRecovery02RegistryHost();
$host->set('customer', $customer);
$host->set('currency', $currency);
$host->set('session', $session);

mtucRecovery02_assert(isset($host->customer) === false, 'OC3 trap: isset(customer) is false');
mtucRecovery02_assert(isset($host->currency) === false, 'OC3 trap: isset(currency) is false');
mtucRecovery02_assert(is_object($host->customer), 'OC3 trap: __get(customer) works');
mtucRecovery02_assert(is_object($host->currency), 'OC3 trap: __get(currency) works');

$brokenActor = mtucRecovery02_brokenActor($host);
$fixedActor = mtucRecovery02_resolveActor($host);
mtucRecovery02_assert((int) $brokenActor['customer_id'] === 0, 'broken isset actor collapses logged-in to guest');
mtucRecovery02_assert((int) $fixedActor['customer_id'] === 77, 'fixed actor resolves logged-in customer_id');
mtucRecovery02_assert(empty($fixedActor['is_guest']), 'fixed actor is not guest');

mtucRecovery02_assert(mtucRecovery02_brokenCurrencyValue($host, 'BGN') === null, 'broken isset currency_value is null');
mtucRecovery02_assert(mtucRecovery02_resolveCurrencyValue($host, 'BGN') === 1.0, 'fixed currency_value resolves');

$locksDb = Phase4TestHarness::memoryDb();
$preparation = Phase5TestHarness::confirmPreparation($locksDb, Phase5TestHarness::STORE_A);

$orderLogged = Phase5TestHarness::orderRow(501, Phase5TestHarness::STORE_A);
$orderLogged['customer_id'] = 77;
$orderLogged['email'] = 'customer@example.com';

$baseInput = array(
    'payment_code' => MtUniCreditConstants::EXTENSION_CODE,
    'order_id' => 501,
    'prepared_order_id' => 0,
    'order' => $orderLogged,
    'order_products' => array(array('order_product_id' => 1, 'product_id' => 1, 'quantity' => 1)),
    'cart_products' => Phase5TestHarness::cartProducts(),
    'get_order_options' => function () {
        return array();
    },
    'checkout_grand_total' => 500.0,
    'currency_code' => 'BGN',
    'currency_value' => 1.0,
    'store_id' => Phase5TestHarness::STORE_A,
    'module_enabled' => true,
    'payment_enabled' => true,
);

// Native valid status-0 + matching cart + fixed logged-in actor → PASS
$resultOk = $preparation->prepare(array_merge($baseInput, array(
    'actor' => Phase5TestHarness::loggedInActor(77),
)));
mtucRecovery02_assert(!empty($resultOk['success']), 'valid status-0 logged-in order + matching cart → PASS');

// Broken actor shape against logged-in order → BLOCK (UI historically mapped to error_order)
$resultBrokenActor = $preparation->prepare(array_merge($baseInput, array('actor' => $brokenActor)));
mtucRecovery02_assert(
    isset($resultBrokenActor['error']) && $resultBrokenActor['error'] === 'order_ownership_mismatch',
    'broken isset actor → ownership BLOCK'
);

// Missing session order → BLOCK
$resultMissing = $preparation->prepare(array_merge($baseInput, array(
    'order_id' => 0,
    'order' => null,
    'actor' => Phase5TestHarness::loggedInActor(77),
)));
mtucRecovery02_assert(
    isset($resultMissing['error']) && $resultMissing['error'] === 'order_missing',
    'missing session order → BLOCK'
);

// Stale/different native order id → BLOCK
$resultStale = $preparation->prepare(array_merge($baseInput, array(
    'order_id' => 999,
    'actor' => Phase5TestHarness::loggedInActor(77),
)));
mtucRecovery02_assert(
    isset($resultStale['error']) && $resultStale['error'] === 'order_missing',
    'stale/different native order → BLOCK'
);

// Actually changed cart → BLOCK
$changedCart = Phase5TestHarness::cartProducts();
$changedCart[0]['quantity'] = 9;
$resultChanged = $preparation->prepare(array_merge($baseInput, array(
    'cart_products' => $changedCart,
    'actor' => Phase5TestHarness::loggedInActor(77),
)));
mtucRecovery02_assert(
    isset($resultChanged['error']) && $resultChanged['error'] === 'order_changed',
    'actually changed cart → BLOCK'
);

// currency_value null (broken isset shape) with order.currency_value present → order_changed
$resultCurNull = $preparation->prepare(array_merge($baseInput, array(
    'actor' => Phase5TestHarness::loggedInActor(77),
    'currency_value' => null,
)));
mtucRecovery02_assert(
    isset($resultCurNull['error']) && $resultCurNull['error'] === 'order_changed',
    'null currency_value vs order.currency_value → order_changed'
);

// Failed attempt with broken actor, then fresh matching prepare with fixed actor → PASS
$resultAfterFail = $preparation->prepare(array_merge($baseInput, array(
    'order_id' => 502,
    'order' => array_merge($orderLogged, array('order_id' => 502)),
    'prepared_order_id' => 501,
    'actor' => Phase5TestHarness::loggedInActor(77),
)));
mtucRecovery02_assert(
    !empty($resultAfterFail['success']),
    'after failed attempt: fresh native order + matching cart → PASS'
);
mtucRecovery02_assert(
    isset($resultAfterFail['prepared_order_id']) && (int) $resultAfterFail['prepared_order_id'] === 502,
    'fresh prepared marker tracks new session order_id'
);

$prepSrc = file_get_contents($lib . DIRECTORY_SEPARATOR . 'checkout_confirm_preparation.php');
mtucRecovery02_assert(
    is_string($prepSrc) && strpos($prepSrc, 'ControlPanelClient') === false,
    'prepare path has no ControlPanelClient'
);

echo PHP_EOL;
if ($failures) {
    echo 'RECOVERY-02 CHECKOUT NATIVE ORDER: FAIL (' . count($failures) . ' failed, ' . $passes . ' passed)' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'RECOVERY-02 CHECKOUT NATIVE ORDER: PASS (' . $passes . ' assertions)' . PHP_EOL;
exit(0);
