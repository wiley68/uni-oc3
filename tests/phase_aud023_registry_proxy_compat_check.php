<?php

/**
 * AUD-023 — OC3 Registry magic + Proxy callability compatibility.
 * Run: php tests/phase_aud023_registry_proxy_compat_check.php
 *
 * Covers:
 *   F01 tax calculator must not gate on isset($model->tax)
 *   F02 checkout_success ownership must not gate on isset($this->customer)
 *   F03 native upload lookup must not gate on method_exists(Proxy, ...)
 *
 * PHP 7.3 compatible. Offline.
 */
require_once __DIR__ . '/bootstrap.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DIR_STORAGE')) {
    mtuc_test_define_dir_storage('mtuc-aud023');
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
}
if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'oc_');
}

if (!class_exists('Registry', false)) {
    final class Registry
    {
        /** @var array<string, mixed> */
        private $data = array();

        /**
         * @param string $key
         * @return mixed
         */
        public function get($key)
        {
            return isset($this->data[$key]) ? $this->data[$key] : null;
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

        /**
         * @param string $key
         * @return bool
         */
        public function has($key)
        {
            return isset($this->data[$key]);
        }
    }
}

if (!class_exists('Model', false)) {
    abstract class Model
    {
        /** @var Registry */
        protected $registry;

        /**
         * @param Registry $registry
         */
        public function __construct($registry)
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
}

if (!class_exists('Controller', false)) {
    abstract class Controller
    {
        /** @var Registry */
        protected $registry;

        /**
         * @param Registry $registry
         */
        public function __construct($registry)
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
}

require_once $lib . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog'
    . DIRECTORY_SEPARATOR . 'model' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR
    . 'payment' . DIRECTORY_SEPARATOR . 'mt_uni_credit.php';
require_once $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog'
    . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR
    . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'checkout_success.php';

$failures = array();
$passes = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucAud023_assert($condition, $message)
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
 * OC3-style Proxy: dynamic callable properties + __call; no declared methods.
 */
final class MtucAud023Oc3Proxy extends stdClass
{
    /**
     * @param string $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this->{$key};
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function __set($key, $value)
    {
        $this->{$key} = $value;
    }

    /**
     * @param string $key
     * @param array<int, mixed> $args
     * @return mixed
     */
    public function __call($key, $args)
    {
        if (isset($this->{$key})) {
            return call_user_func_array($this->{$key}, $args);
        }
        throw new Exception('Undefined Proxy method: ' . $key);
    }
}

final class MtucAud023TaxService
{
    /** @var int */
    public $calls = 0;

    /**
     * @param float $value
     * @param int $taxClassId
     * @param mixed $configTax
     * @return float
     */
    public function calculate($value, $taxClassId, $configTax)
    {
        $this->calls++;
        if (!(bool) $configTax || (int) $taxClassId <= 0) {
            return (float) $value;
        }

        // Non-identity transform so silent raw-price fallback fails the suite.
        return round(((float) $value) * 1.2, 2);
    }
}

final class MtucAud023Config
{
    /** @var array<string, mixed> */
    private $data;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function get($key)
    {
        return isset($this->data[$key]) ? $this->data[$key] : null;
    }
}

final class MtucAud023Customer
{
    /** @var bool */
    private $logged;
    /** @var int */
    private $id;

    /**
     * @param bool $logged
     * @param int $id
     */
    public function __construct($logged, $id)
    {
        $this->logged = (bool) $logged;
        $this->id = (int) $id;
    }

    /**
     * @return bool
     */
    public function isLogged()
    {
        return $this->logged;
    }

    /**
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }
}

final class MtucAud023DbResult
{
    /** @var int */
    public $num_rows;
    /** @var array<string, mixed> */
    public $row;

    /**
     * @param int $numRows
     * @param array<string, mixed> $row
     */
    public function __construct($numRows, array $row)
    {
        $this->num_rows = (int) $numRows;
        $this->row = $row;
    }
}

final class MtucAud023Db
{
    /** @var array<int, array<string, mixed>> */
    private $orders;

    /**
     * @param array<int, array<string, mixed>> $orders
     */
    public function __construct(array $orders)
    {
        $this->orders = $orders;
    }

    /**
     * @param string $sql
     * @return MtucAud023DbResult
     */
    public function query($sql)
    {
        if (preg_match('/order_id`?\s*=\s*(\d+)/', (string) $sql, $m)) {
            $orderId = (int) $m[1];
            if (isset($this->orders[$orderId])) {
                return new MtucAud023DbResult(1, $this->orders[$orderId]);
            }
        }

        return new MtucAud023DbResult(0, array());
    }
}

final class MtucAud023Loader
{
    /** @var Registry */
    private $registry;
    /** @var MtucAud023Oc3Proxy */
    private $uploadProxy;

    /**
     * @param Registry $registry
     * @param MtucAud023Oc3Proxy $uploadProxy
     */
    public function __construct(Registry $registry, MtucAud023Oc3Proxy $uploadProxy)
    {
        $this->registry = $registry;
        $this->uploadProxy = $uploadProxy;
    }

    /**
     * @param string $route
     * @return void
     */
    public function model($route)
    {
        if ($route === 'tool/upload') {
            $this->registry->set('model_tool_upload', $this->uploadProxy);
        }
    }
}

// ---------------------------------------------------------------------------
// Canary: Registry __get without __isset
// ---------------------------------------------------------------------------
$canaryReg = new Registry();
$canaryTax = new MtucAud023TaxService();
$canaryCustomer = new MtucAud023Customer(true, 42);
$canaryReg->set('tax', $canaryTax);
$canaryReg->set('customer', $canaryCustomer);
$canaryHost = new ModelExtensionPaymentMtUniCredit($canaryReg);

mtucAud023_assert(is_object($canaryHost->customer), 'canary: direct customer access works');
mtucAud023_assert(!isset($canaryHost->customer), 'canary: isset(customer) is false without __isset');
mtucAud023_assert(is_object($canaryHost->tax), 'canary: direct tax access works');
mtucAud023_assert(!isset($canaryHost->tax), 'canary: isset(tax) is false without __isset');

// ---------------------------------------------------------------------------
// Source guards (mutation 1,3,5)
// ---------------------------------------------------------------------------
$paymentModelSrc = (string) file_get_contents(
    $root . '/upload/catalog/model/extension/payment/mt_uni_credit.php'
);
$successSrc = (string) file_get_contents(
    $root . '/upload/catalog/controller/extension/mt_uni_credit/checkout_success.php'
);
$runtimeSrc = (string) file_get_contents(
    $root . '/upload/system/library/mt_uni_credit/storefront_runtime.php'
);

mtucAud023_assert(
    strpos($paymentModelSrc, 'isset($model->tax)') === false,
    'F01 source: no isset($model->tax) gate'
);
mtucAud023_assert(
    strpos($paymentModelSrc, "property_exists(\$model, 'tax')") === false
        && strpos($paymentModelSrc, 'property_exists($model, "tax")') === false,
    'F01 source: no property_exists tax gate'
);
mtucAud023_assert(
    preg_match('/createTaxCalculatorCallable\([\s\S]*?\$tax\s*=\s*\$model->tax/s', $paymentModelSrc) === 1,
    'F01 source: resolves $tax = $model->tax'
);
mtucAud023_assert(
    strpos($successSrc, 'isset($this->customer)') === false,
    'F02 source: no isset($this->customer) gate'
);
mtucAud023_assert(
    preg_match('/ownershipChecks\([\s\S]*?\$customer\s*=\s*\$this->customer/s', $successSrc) === 1,
    'F02 source: resolves $customer = $this->customer'
);
mtucAud023_assert(
    preg_match(
        '/lookupNativeUploadByCode\([\s\S]*?method_exists\(\$model,\s*[\'"]getUploadByCode[\'"]\)/s',
        $runtimeSrc
    ) !== 1,
    'F03 source: no method_exists(proxy, getUploadByCode) gate'
);
mtucAud023_assert(
    preg_match(
        '/lookupNativeUploadByCode\([\s\S]*?is_callable\(\s*array\(\s*\$model,\s*[\'"]getUploadByCode[\'"]\s*\)\s*\)/s',
        $runtimeSrc
    ) === 1,
    'F03 source: uses is_callable(array($model, getUploadByCode))'
);

// ---------------------------------------------------------------------------
// F01 — tax calculator actually invoked through Registry magic
// ---------------------------------------------------------------------------
$taxService = new MtucAud023TaxService();
$taxReg = new Registry();
$taxReg->set('tax', $taxService);
$taxReg->set('config', new MtucAud023Config(array('config_tax' => true)));
$paymentModel = new ModelExtensionPaymentMtUniCredit($taxReg);

$refTax = new ReflectionMethod($paymentModel, 'createTaxCalculatorCallable');
$refTax->setAccessible(true);
/** @var callable $taxCallable */
$taxCallable = $refTax->invoke($paymentModel);

$raw = 100.0;
$taxed = (float) call_user_func($taxCallable, $raw, 9);
mtucAud023_assert(is_callable($taxCallable), 'F01: tax callable available');
mtucAud023_assert($taxService->calls === 1, 'F01: Tax::calculate actually invoked');
mtucAud023_assert($taxed === 120.0, 'F01: tax-adjusted price differs from raw (100 → 120)');
mtucAud023_assert($taxed !== $raw, 'F01: result is not silent raw-price fallback');

// Prove isset trap would have skipped tax before the fix.
mtucAud023_assert(
    !isset($paymentModel->tax) && is_object($paymentModel->tax),
    'F01 trap: isset(tax) false while Registry tax resolves'
);

// ---------------------------------------------------------------------------
// F02 — ownershipChecks with Registry-only customer
// ---------------------------------------------------------------------------
/**
 * @param MtucAud023Customer|null $customer
 * @param array<int, array<string, mixed>> $orders
 * @return ControllerExtensionMtUniCreditCheckoutSuccess
 */
function mtucAud023_successController($customer, array $orders)
{
    $reg = new Registry();
    if ($customer !== null) {
        $reg->set('customer', $customer);
    }
    $reg->set('db', new MtucAud023Db($orders));
    $reg->set('config', new MtucAud023Config(array('config_store_id' => 1)));

    return new ControllerExtensionMtUniCreditCheckoutSuccess($reg);
}

/**
 * @param ControllerExtensionMtUniCreditCheckoutSuccess $controller
 * @param int $storeId
 * @param int $orderId
 * @return array{ok:bool,order_exists:bool,store_match:bool}
 */
function mtucAud023_ownership($controller, $storeId, $orderId)
{
    $ref = new ReflectionMethod($controller, 'ownershipChecks');
    $ref->setAccessible(true);

    return $ref->invoke($controller, $storeId, $orderId);
}

$orders = array(
    501 => array('customer_id' => 10, 'store_id' => 1),
    502 => array('customer_id' => 20, 'store_id' => 1),
);

$ownerOk = mtucAud023_ownership(
    mtucAud023_successController(new MtucAud023Customer(true, 10), $orders),
    1,
    501
);
mtucAud023_assert(!empty($ownerOk['ok']), 'F02 A: correct logged-in ownership PASS');

$ownerWrong = mtucAud023_ownership(
    mtucAud023_successController(new MtucAud023Customer(true, 10), $orders),
    1,
    502
);
mtucAud023_assert(empty($ownerWrong['ok']), 'F02 B: wrong logged-in customer FAIL');

$guestOk = mtucAud023_ownership(
    mtucAud023_successController(new MtucAud023Customer(false, 0), $orders),
    1,
    501
);
mtucAud023_assert(!empty($guestOk['ok']), 'F02 C: guest semantics remain PASS');

$missingCustomer = mtucAud023_ownership(
    mtucAud023_successController(null, $orders),
    1,
    501
);
mtucAud023_assert(empty($missingCustomer['ok']), 'F02 D: missing customer dependency fails closed');

$successHost = mtucAud023_successController(new MtucAud023Customer(true, 10), $orders);
mtucAud023_assert(
    !isset($successHost->customer) && is_object($successHost->customer),
    'F02 trap: isset(customer) false while Registry customer resolves'
);

// ---------------------------------------------------------------------------
// F03 — Proxy-style upload model
// ---------------------------------------------------------------------------
$uploadRows = array(
    'native-valid-code' => array(
        'code' => 'native-valid-code',
        'name' => 'photo.jpg',
        'filename' => 'photo.jpg',
    ),
    'malformed-empty-code' => array(
        'name' => 'broken.bin',
        'filename' => 'broken.bin',
    ),
);

$uploadProxy = new MtucAud023Oc3Proxy();
$uploadProxy->getUploadByCode = function ($code) use ($uploadRows) {
    $code = (string) $code;

    return isset($uploadRows[$code]) ? $uploadRows[$code] : array();
};

mtucAud023_assert(
    method_exists($uploadProxy, 'getUploadByCode') === false,
    'F03 Proxy canary: method_exists(getUploadByCode) === false'
);
mtucAud023_assert(
    is_callable(array($uploadProxy, 'getUploadByCode')) === true,
    'F03 Proxy canary: is_callable([proxy, getUploadByCode]) === true'
);
$directDispatch = $uploadProxy->getUploadByCode('native-valid-code');
mtucAud023_assert(
    is_array($directDispatch) && (string) $directDispatch['code'] === 'native-valid-code',
    'F03 Proxy canary: proxy->getUploadByCode succeeds'
);
mtucAud023_assert(
    !($uploadProxy instanceof MtucAud023TaxService)
        && get_class($uploadProxy) === 'MtucAud023Oc3Proxy'
        && !method_exists($uploadProxy, 'getUploadByCode'),
    'F03 evidence: Proxy-style double (not concrete declared method)'
);

$uploadReg = new Registry();
$uploadReg->set('load', new MtucAud023Loader($uploadReg, $uploadProxy));
$uploadHost = new class($uploadReg) {
    /** @var Registry */
    private $registry;

    public function __construct(Registry $registry)
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
};

$validUpload = MtUniCreditStorefrontRuntime::lookupNativeUploadByCode($uploadHost, 'native-valid-code');
mtucAud023_assert(
    is_array($validUpload) && (string) $validUpload['code'] === 'native-valid-code',
    'F03: valid native upload accepted via Proxy'
);

$invalidUpload = MtUniCreditStorefrontRuntime::lookupNativeUploadByCode($uploadHost, 'fabricated-token');
mtucAud023_assert($invalidUpload === null, 'F03: invalid upload code rejected');

$malformedUpload = MtUniCreditStorefrontRuntime::lookupNativeUploadByCode($uploadHost, 'malformed-empty-code');
mtucAud023_assert($malformedUpload === null, 'F03: malformed upload row rejected');

$emptyCode = MtUniCreditStorefrontRuntime::lookupNativeUploadByCode($uploadHost, '');
mtucAud023_assert($emptyCode === null, 'F03: empty upload code rejected');

// ---------------------------------------------------------------------------
// Mutation sensitivity matrix (1–8)
// ---------------------------------------------------------------------------
mtucAud023_assert(
    strpos($paymentModelSrc, 'isset($model->tax)') === false,
    'mutation-1 YES: isset($model->tax) reintroduction detected'
);
mtucAud023_assert(
    $taxed !== $raw && $taxService->calls >= 1,
    'mutation-2 YES: silent raw-price tax bypass detected'
);
mtucAud023_assert(
    strpos($successSrc, 'isset($this->customer)') === false,
    'mutation-3 YES: isset($this->customer) reintroduction detected'
);
mtucAud023_assert(
    empty($ownerWrong['ok']),
    'mutation-4 YES: wrong logged-in customer acceptance detected'
);
mtucAud023_assert(
    preg_match(
        '/lookupNativeUploadByCode\([\s\S]*?method_exists\(\$model,\s*[\'"]getUploadByCode[\'"]\)/s',
        $runtimeSrc
    ) !== 1,
    'mutation-5 YES: method_exists(proxy) reintroduction detected'
);
mtucAud023_assert(
    is_array($validUpload),
    'mutation-6 YES: Proxy upload call removal detected'
);
mtucAud023_assert(
    $invalidUpload === null && $malformedUpload === null,
    'mutation-7 YES: invalid upload acceptance detected'
);
mtucAud023_assert(
    method_exists($uploadProxy, 'getUploadByCode') === false
        && is_callable(array($uploadProxy, 'getUploadByCode')) === true,
    'mutation-8 YES: concrete-model double replacing Proxy evidence detected'
);

echo PHP_EOL . 'AUD-023 registry/proxy compat: ' . $passes . ' PASS, ' . count($failures) . ' FAIL' . PHP_EOL;
if ($failures !== array()) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

exit(0);
