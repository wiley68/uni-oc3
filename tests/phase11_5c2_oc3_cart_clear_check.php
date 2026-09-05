<?php

/**
 * Phase 11.5C.2 — Real OC3 Registry-backed cart clear (isset trap).
 * Run: php tests/phase11_5c2_oc3_cart_clear_check.php
 *
 * Proves Controller::__get('cart') works while isset($controller->cart) does not,
 * matching reference-oc3-core system/engine/controller.php (no __isset).
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
    $storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mtuc-phase115c2oc3cart-storage';
    if (!is_dir($storage)) {
        @mkdir($storage, 0770, true);
    }
    if (!is_dir($storage . DIRECTORY_SEPARATOR . 'mt_uni_credit')) {
        @mkdir($storage . DIRECTORY_SEPARATOR . 'mt_uni_credit', 0770, true);
    }
    define('DIR_STORAGE', rtrim($storage, '/\\') . DIRECTORY_SEPARATOR);
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
}

require_once $lib . DIRECTORY_SEPARATOR . 'bootstrap.php';

$failures = array();
$passes = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtuc115c2oc3_assert($condition, $message)
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
 * @param string $path
 * @return string
 */
function mtuc115c2oc3_read($path)
{
    $body = @file_get_contents($path);

    return is_string($body) ? $body : '';
}

/**
 * Mirrors reference-oc3-core system/engine/controller.php: __get/__set, no __isset.
 */
final class Mtuc115c2Oc3Registry
{
    /** @var array<string, mixed> */
    private $data = array();

    /**
     * @param string $key
     * @return mixed
     */
    public function get($key)
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : null;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set($key, $value)
    {
        $this->data[$key] = $value;
    }
}

final class Mtuc115c2Oc3Controller
{
    /** @var Mtuc115c2Oc3Registry */
    protected $registry;

    public function __construct(Mtuc115c2Oc3Registry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this->registry->get($key);
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function __set($key, $value)
    {
        $this->registry->set($key, $value);
    }
}

final class Mtuc115c2Oc3CartProbe
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
     * Mirrors OC3 Cart::clear() effect for probe counting.
     *
     * @return void
     */
    public function clear()
    {
        $this->clearCalls++;
        $this->count = 0;
    }
}

$coreController = mtuc115c2oc3_read(
    dirname($root) . DIRECTORY_SEPARATOR . 'reference-oc3-core' . DIRECTORY_SEPARATOR
        . 'system' . DIRECTORY_SEPARATOR . 'engine' . DIRECTORY_SEPARATOR . 'controller.php'
);
if ($coreController === '') {
    // Workspace may mount reference-oc3-core beside uni-oc3.
    $alt = 'c:\\Projects\\reference-oc3-core\\system\\engine\\controller.php';
    $coreController = mtuc115c2oc3_read($alt);
}
mtuc115c2oc3_assert(
    $coreController !== ''
        && strpos($coreController, 'function __get($key)') !== false
        && strpos($coreController, 'function __set($key') !== false
        && strpos($coreController, 'function __isset') === false,
    'OC3 core Controller: __get/__set present, no __isset'
);

$coreCart = mtuc115c2oc3_read(
    'c:\\Projects\\reference-oc3-core\\system\\library\\cart\\cart.php'
);
mtuc115c2oc3_assert(
    $coreCart !== ''
        && preg_match(
            '/function\\s+clear\\s*\\(\\s*\\)\\s*\\{[^}]*DELETE FROM[^}]*customer_id[^}]*session_id/s',
            $coreCart
        ) === 1,
    'OC3 Cart::clear() deletes by customer_id + session_id (logged + guest)'
);

$registry = new Mtuc115c2Oc3Registry();
$controller = new Mtuc115c2Oc3Controller($registry);
$probe = new Mtuc115c2Oc3CartProbe(5);
$controller->cart = $probe;

mtuc115c2oc3_assert(
    is_object($controller->cart) && method_exists($controller->cart, 'clear'),
    'magic __get: $controller->cart is usable'
);
mtuc115c2oc3_assert(
    !isset($controller->cart),
    'OC3 semantics: isset($controller->cart) is NOT a valid availability check'
);

$handoff = array(
    'success' => true,
    'bank_status' => MtUniCreditBankStatus::SENT_PROCESS1,
    'order_id' => 119001,
);

// Old (broken) Cart controller pattern — must NOT clear.
$brokenCart = isset($controller->cart) ? $controller->cart : null;
$brokenCleared = MtUniCreditFinancingTerminalNavigationSupport::clearCartAfterSuccessfulHandoff(
    $handoff,
    $brokenCart
);
mtuc115c2oc3_assert($brokenCart === null, 'old pattern: isset ternary yields null');
mtuc115c2oc3_assert($brokenCleared === false, 'old pattern: clearCartAfterSuccessfulHandoff fails');
mtuc115c2oc3_assert($probe->count === 5 && $probe->clearCalls === 0, 'old pattern: cart remains uncleared');

// Fixed pattern — pass Registry-backed cart directly.
$fixedCleared = MtUniCreditFinancingTerminalNavigationSupport::clearCartAfterSuccessfulHandoff(
    $handoff,
    $controller->cart
);
mtuc115c2oc3_assert($fixedCleared === true, 'fixed pattern: clear invoked via $controller->cart');
mtuc115c2oc3_assert($probe->count === 0 && $probe->clearCalls === 1, 'fixed pattern: cart emptied');

// Failure preservation still holds with real magic cart object.
$failResult = array(
    'success' => false,
    'bank_status' => MtUniCreditBankStatus::SEND_FAILED_SMARTUCF,
    'order_id' => 119002,
);
$probeFail = new Mtuc115c2Oc3CartProbe(3);
$controller->cart = $probeFail;
mtuc115c2oc3_assert(
    MtUniCreditFinancingTerminalNavigationSupport::clearCartAfterSuccessfulHandoff(
        $failResult,
        $controller->cart
    ) === false
        && $probeFail->count === 3,
    'broken SmartUCF: magic cart still NOT cleared'
);

$cpFail = array(
    'success' => false,
    'bank_status' => '',
    'order_id' => 119003,
);
mtuc115c2oc3_assert(
    MtUniCreditFinancingTerminalNavigationSupport::clearCartAfterSuccessfulHandoff(
        $cpFail,
        $controller->cart
    ) === false
        && $probeFail->count === 3,
    'broken CP: magic cart still NOT cleared'
);

// Source wiring — only the Cart clear Registry fix is retained after recovery.
$cartCtrl = mtuc115c2oc3_read(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'cart.php'
);
$productCtrl = mtuc115c2oc3_read(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'product.php'
);

mtuc115c2oc3_assert(
    strpos($cartCtrl, 'isset($this->cart)') === false,
    'cart.php: no isset($this->cart) in clear call'
);
mtuc115c2oc3_assert(
    preg_match(
        '/clearCartAfterSuccessfulHandoff\\s*\\(\\s*\\$result\\s*,\\s*\\$this->cart\\s*\\)/s',
        $cartCtrl
    ) === 1,
    'cart.php: passes $this->cart directly'
);
mtuc115c2oc3_assert(
    strpos($productCtrl, 'clearCartAfterSuccessfulHandoff') === false
        && strpos($productCtrl, 'cart->clear') === false,
    'Product: still no cart clear'
);

echo PHP_EOL . 'Phase 11.5C.2 OC3 cart-clear checks: ' . $passes . ' passed';
if ($failures) {
    echo ', ' . count($failures) . ' failed' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    echo 'PHASE 11.5C.2 REAL OC3 CART CLEAR: BLOCKED' . PHP_EOL;
    exit(1);
}

echo ', 0 failed' . PHP_EOL;
echo 'PHASE 11.5C.2 REAL OC3 CART CLEAR: PASS — LOCAL' . PHP_EOL;
exit(0);
