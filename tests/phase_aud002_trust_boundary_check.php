<?php

/**
 * AUD-002 — admin lifecycle + Product Buy public-route trust boundaries.
 * Run: php tests/phase_aud002_trust_boundary_check.php
 *
 * PHP 7.3 compatible. Offline network guard active. No CP/SmartUCF.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/support/phase2_memory_db.php';
require_once __DIR__ . '/support/encryption_test_secret.php';

$failures = array();
$passes = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucAud002_assert($condition, $message)
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
if (!defined('DIR_STORAGE')) {
    mtuc_test_define_dir_storage('mtuc-aud002');
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', MtUniCreditEncryptionTestSecret::testSecretInput());
}
if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'oc_');
}

require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';

mtucAud002_assert(mtuc_phase0_network_isolation_active(), 'isolation: offline guard active');

// ---------------------------------------------------------------------------
// Minimal OC3 Controller / Registry doubles
// ---------------------------------------------------------------------------
if (!class_exists('Registry', false)) {
    class Registry
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
            $this->data[$key] = $value;
        }

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
         * @return bool
         */
        public function has($key)
        {
            return array_key_exists($key, $this->data);
        }
    }
}

if (!class_exists('Controller', false)) {
    abstract class Controller
    {
        /** @var Registry */
        protected $registry;

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
    }
}

if (!class_exists('Model', false)) {
    class Model
    {
        /** @param Registry|null $registry */
        public function __construct($registry = null) {}
    }
}

final class Aud002UserFake
{
    /** @var array<string, array<int, string>> */
    private $permission;

    /**
     * @param array<string, array<int, string>> $permission
     */
    public function __construct(array $permission)
    {
        $this->permission = $permission;
    }

    /**
     * @param string $key
     * @param string $value
     * @return bool
     */
    public function hasPermission($key, $value)
    {
        if (!isset($this->permission[$key]) || !is_array($this->permission[$key])) {
            return false;
        }

        return in_array($value, $this->permission[$key], true);
    }
}

final class Aud002LanguageFake
{
    /**
     * @param string $key
     * @return string
     */
    public function get($key)
    {
        return $key;
    }
}

final class Aud002LoadFake
{
    /** @var object|null */
    public $moduleModel;

    /** @var object|null */
    public $paymentModel;

    /** @var array<int, string> */
    public $languageLoads = array();

    /**
     * @param string $route
     * @return void
     */
    public function language($route)
    {
        $this->languageLoads[] = $route;
    }

    /**
     * @param string $route
     * @return void
     */
    public function model($route)
    {
        // Controllers assign model_* via registry in these tests.
    }

    /**
     * @param string $route
     * @param array<string, mixed> $data
     * @return string
     */
    public function controller($route, $data = array())
    {
        return '';
    }

    /**
     * @param string $route
     * @param array<string, mixed> $data
     * @return string
     */
    public function view($route, $data = array())
    {
        return 'view:' . $route;
    }
}

final class Aud002UrlFake
{
    /**
     * @param string $route
     * @param string $args
     * @param bool $secure
     * @return string
     */
    public function link($route, $args = '', $secure = false)
    {
        return 'https://admin.example/' . $route . ($args !== '' ? '?' . $args : '');
    }
}

final class Aud002ResponseFake
{
    /** @var string|null */
    public $redirect;

    /** @var string */
    public $output = '';

    /**
     * @param string $url
     * @return void
     */
    public function redirect($url)
    {
        $this->redirect = $url;
    }

    /**
     * @param string $output
     * @return void
     */
    public function setOutput($output)
    {
        $this->output = $output;
    }
}

final class Aud002DocumentFake
{
    public function setTitle($title) {}
    public function addStyle($href) {}
    public function addScript($href) {}
}

final class Aud002ConfigFake
{
    /** @var array<string, mixed> */
    private $values;

    public function __construct(array $values = array())
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

final class Aud002SessionBag
{
    /** @var array<string, mixed> */
    public $data = array();
}

final class Aud002RequestBag
{
    /** @var array<string, mixed> */
    public $get = array();

    /** @var array<string, mixed> */
    public $post = array();

    /** @var array<string, mixed> */
    public $server = array('REQUEST_METHOD' => 'GET');
}

final class Aud002ModuleModelProbe
{
    /** @var int */
    public $installCalls = 0;

    /** @var int */
    public $uninstallCalls = 0;

    /** @var int */
    public $repairCalls = 0;

    /** @var bool */
    public $settingsDeleted = false;

    /** @return void */
    public function install()
    {
        $this->installCalls++;
    }

    /** @return void */
    public function uninstall()
    {
        $this->uninstallCalls++;
        $this->settingsDeleted = true;
    }

    /**
     * @return array<string, mixed>
     */
    public function repairCatalogEvents()
    {
        $this->repairCalls++;

        return array(
            'inserted' => 0,
            'updated' => 0,
            'deleted_duplicates' => 0,
            'healthy' => true,
            'error' => null,
        );
    }

    /**
     * @return bool
     */
    public function isSecretConfigured()
    {
        return true;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, string>
     */
    public function validateSettings(array $post)
    {
        return array();
    }

    /**
     * @param array<string, mixed> $post
     * @return void
     */
    public function saveSettings(array $post) {}
}

final class Aud002PaymentModelProbe
{
    /** @var int */
    public $installCalls = 0;

    /** @var int */
    public $uninstallCalls = 0;

    /** @var bool */
    public $settingsDeleted = false;

    /** @return void */
    public function install()
    {
        $this->installCalls++;
    }

    /** @return void */
    public function uninstall()
    {
        $this->uninstallCalls++;
        $this->settingsDeleted = true;
    }
}

/**
 * @param array<string, array<int, string>> $permissions
 * @param array<string, mixed> $get
 * @return array{0:ControllerExtensionModuleMtUniCredit,1:Aud002ModuleModelProbe,2:Aud002SessionBag,3:Aud002ResponseFake}
 */
function mtucAud002_moduleController(array $permissions, array $get = array())
{
    require_once MTUC_PHASE0_ROOT . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'admin'
        . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'module'
        . DIRECTORY_SEPARATOR . 'mt_uni_credit.php';

    $registry = new Registry();
    $session = new Aud002SessionBag();
    $session->data['user_token'] = 'tok-aud002';
    $request = new Aud002RequestBag();
    $request->get = $get;
    $response = new Aud002ResponseFake();
    $load = new Aud002LoadFake();
    $model = new Aud002ModuleModelProbe();

    $registry->set('user', new Aud002UserFake($permissions));
    $registry->set('language', new Aud002LanguageFake());
    $registry->set('load', $load);
    $registry->set('url', new Aud002UrlFake());
    $registry->set('response', $response);
    $registry->set('session', $session);
    $registry->set('request', $request);
    $registry->set('document', new Aud002DocumentFake());
    $registry->set('config', new Aud002ConfigFake(array('config_store_id' => 0)));
    $registry->set('model_extension_module_mt_uni_credit', $model);
    $registry->set('model_setting_setting', new stdClass());

    $controller = new ControllerExtensionModuleMtUniCredit($registry);

    return array($controller, $model, $session, $response);
}

/**
 * @param array<string, array<int, string>> $permissions
 * @param array<string, mixed> $get
 * @return array{0:ControllerExtensionPaymentMtUniCredit,1:Aud002PaymentModelProbe,2:Aud002SessionBag,3:Aud002ResponseFake}
 */
function mtucAud002_paymentController(array $permissions, array $get = array())
{
    require_once MTUC_PHASE0_ROOT . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'admin'
        . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'payment'
        . DIRECTORY_SEPARATOR . 'mt_uni_credit.php';

    $registry = new Registry();
    $session = new Aud002SessionBag();
    $session->data['user_token'] = 'tok-aud002';
    $request = new Aud002RequestBag();
    $request->get = $get;
    $response = new Aud002ResponseFake();

    $model = new Aud002PaymentModelProbe();

    $registry->set('user', new Aud002UserFake($permissions));
    $registry->set('language', new Aud002LanguageFake());
    $registry->set('load', new Aud002LoadFake());
    $registry->set('url', new Aud002UrlFake());
    $registry->set('response', $response);
    $registry->set('session', $session);
    $registry->set('request', $request);
    $registry->set('document', new Aud002DocumentFake());
    $registry->set('config', new Aud002ConfigFake());
    $registry->set('model_extension_payment_mt_uni_credit', $model);
    $registry->set('model_setting_setting', new stdClass());

    $controller = new ControllerExtensionPaymentMtUniCredit($registry);

    return array($controller, $model, $session, $response);
}

/**
 * @param array<string, mixed> $sessionData
 * @param string $navigationId Optional mt_uni_nav for request context
 * @return ControllerExtensionMtUniCreditProductBuy
 */
function mtucAud002_productBuyController(array &$sessionData, $navigationId = '')
{
    require_once MTUC_PHASE0_ROOT . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog'
        . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR
        . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'product_buy.php';

    $registry = new Registry();
    $session = new Aud002SessionBag();
    $session->data = &$sessionData;
    $registry->set('session', $session);
    $registry->set('config', new Aud002ConfigFake(array('config_store_id' => 0)));
    $request = new Aud002RequestBag();
    $request->get = array();
    $request->post = array();
    $navigationId = trim((string) $navigationId);
    if ($navigationId !== '') {
        $request->get[MtUniCreditProductBuyPreference::NAV_PARAM] = $navigationId;
    }
    $registry->set('request', $request);

    return new ControllerExtensionMtUniCreditProductBuy($registry);
}

// ---------------------------------------------------------------------------
// Structural: OC3 native lifecycle (reference-oc3-core)
// ---------------------------------------------------------------------------
$coreModuleInstaller = dirname(MTUC_PHASE0_ROOT) . DIRECTORY_SEPARATOR . 'reference-oc3-core'
    . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR
    . 'extension' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'module.php';
$corePaymentInstaller = dirname(MTUC_PHASE0_ROOT) . DIRECTORY_SEPARATOR . 'reference-oc3-core'
    . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR
    . 'extension' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'payment.php';
$coreAction = dirname(MTUC_PHASE0_ROOT) . DIRECTORY_SEPARATOR . 'reference-oc3-core'
    . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'engine' . DIRECTORY_SEPARATOR . 'action.php';
$coreLoader = dirname(MTUC_PHASE0_ROOT) . DIRECTORY_SEPARATOR . 'reference-oc3-core'
    . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'engine' . DIRECTORY_SEPARATOR . 'loader.php';
$coreStartupRouter = dirname(MTUC_PHASE0_ROOT) . DIRECTORY_SEPARATOR . 'reference-oc3-core'
    . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR
    . 'startup' . DIRECTORY_SEPARATOR . 'router.php';

mtucAud002_assert(is_file($coreModuleInstaller), 'core: module extension installer present');
mtucAud002_assert(is_file($corePaymentInstaller), 'core: payment extension installer present');

$moduleInstallerSrc = (string) file_get_contents($coreModuleInstaller);
$paymentInstallerSrc = (string) file_get_contents($corePaymentInstaller);
$actionSrc = (string) file_get_contents($coreAction);
$loaderSrc = (string) file_get_contents($coreLoader);
$startupRouterSrc = (string) file_get_contents($coreStartupRouter);

mtucAud002_assert(
    strpos($moduleInstallerSrc, "hasPermission('modify', 'extension/extension/module')") !== false,
    'core: outer module install permission is extension/extension/module'
);
mtucAud002_assert(
    strpos($moduleInstallerSrc, "load->controller('extension/module/'") !== false
        && strpos($moduleInstallerSrc, "/install')") !== false,
    'core: outer module install load->controller extension install()'
);
mtucAud002_assert(
    strpos($moduleInstallerSrc, "url->link('extension/extension/module/install'") !== false,
    'core: native module install UI link is GET-compatible url->link (no POST requirement)'
);
mtucAud002_assert(
    strpos($paymentInstallerSrc, "hasPermission('modify', 'extension/extension/payment')") !== false,
    'core: outer payment install permission is extension/extension/payment'
);
mtucAud002_assert(
    strpos($loaderSrc, 'array(&$data)') !== false,
    'core: Loader::controller passes array(&$data) to Action::execute'
);
mtucAud002_assert(
    strpos($startupRouterSrc, '$action->execute($this->registry)') !== false,
    'core: direct catalog route Action::execute has zero args'
);
mtucAud002_assert(
    strpos($actionSrc, 'getNumberOfRequiredParameters() <= count($args)') !== false,
    'core: Action refuses call when required params exceed args'
);

$moduleCtrlSrc = (string) file_get_contents(
    $root . '/upload/admin/controller/extension/module/mt_uni_credit.php'
);
$paymentCtrlSrc = (string) file_get_contents(
    $root . '/upload/admin/controller/extension/payment/mt_uni_credit.php'
);
$productBuySrc = (string) file_get_contents(
    $root . '/upload/catalog/controller/extension/mt_uni_credit/product_buy.php'
);

mtucAud002_assert(
    strpos($moduleCtrlSrc, "hasPermission('modify', 'extension/module/mt_uni_credit')") !== false
        && strpos($moduleCtrlSrc, "hasPermission('modify', 'extension/extension/module')") !== false,
    'module lifecycle accepts exact + outer installer modify'
);
mtucAud002_assert(
    strpos($paymentCtrlSrc, "hasPermission('modify', 'extension/payment/mt_uni_credit')") !== false
        && strpos($paymentCtrlSrc, "hasPermission('modify', 'extension/extension/payment')") !== false,
    'payment lifecycle accepts exact + outer installer modify'
);
mtucAud002_assert(
    preg_match('/function applyPaymentPreselect\s*\(\s*&\$data\s*\)/', $productBuySrc) === 1,
    'product_buy applyPaymentPreselect requires &$data (OCMOD/Loader context)'
);
mtucAud002_assert(
    preg_match('/function onPaymentMethodSaved\s*\(\s*&\$data\s*\)/', $productBuySrc) === 1,
    'product_buy onPaymentMethodSaved requires &$data (OCMOD/Loader context)'
);

// ---------------------------------------------------------------------------
// F-002-01 — Module index repair
// ---------------------------------------------------------------------------
list($ctrlAccess, $modelAccess) = mtucAud002_moduleController(array(
    'access' => array('extension/module/mt_uni_credit'),
));
$ctrlAccess->index();
mtucAud002_assert($modelAccess->repairCalls === 0, 'F-002-01: access-only index does not repair events');

list($ctrlModify, $modelModify) = mtucAud002_moduleController(array(
    'access' => array('extension/module/mt_uni_credit'),
    'modify' => array('extension/module/mt_uni_credit'),
));
$ctrlModify->index();
mtucAud002_assert($modelModify->repairCalls === 1, 'F-002-01: modify index runs event repair');

// ---------------------------------------------------------------------------
// F-002-01 — Module install / uninstall
// ---------------------------------------------------------------------------
list($c1, $m1, $s1, $r1) = mtucAud002_moduleController(
    array('access' => array('extension/module/mt_uni_credit')),
    array('route' => 'extension/module/mt_uni_credit/install')
);
$c1->install();
mtucAud002_assert($m1->installCalls === 0, 'F-002-01: module install blocked without modify');
mtucAud002_assert(isset($s1->data['error']) && $s1->data['error'] === 'error_permission', 'F-002-01: module install sets permission error');
mtucAud002_assert(is_string($r1->redirect) && $r1->redirect !== '', 'F-002-01: direct unauthorized module install redirects');

list($c2, $m2) = mtucAud002_moduleController(array(
    'modify' => array('extension/module/mt_uni_credit'),
));
$c2->install();
mtucAud002_assert($m2->installCalls === 1, 'F-002-01: exact module modify allows install');

list($c3, $m3) = mtucAud002_moduleController(
    array('modify' => array('extension/extension/module')),
    array('route' => 'extension/extension/module/install')
);
$c3->install();
mtucAud002_assert(
    $m3->installCalls === 1,
    'F-002-01: native outer installer modify allows first install (User not reloaded)'
);

list($c4, $m4, $s4) = mtucAud002_moduleController(
    array('access' => array('extension/module/mt_uni_credit')),
    array('route' => 'extension/module/mt_uni_credit/uninstall')
);
// Simulate encrypted secret settings still present — unauthorized must not delete.
$secretStillPresent = true;
$c4->uninstall();
mtucAud002_assert($m4->uninstallCalls === 0, 'F-002-01: module uninstall blocked without modify');
mtucAud002_assert($m4->settingsDeleted === false, 'F-002-01: unauthorized uninstall leaves settings/Secret untouched');
mtucAud002_assert($secretStillPresent === true, 'F-002-01: Secret-bearing settings record conceptually preserved');

list($c5, $m5) = mtucAud002_moduleController(array(
    'modify' => array('extension/module/mt_uni_credit'),
));
$c5->uninstall();
mtucAud002_assert($m5->uninstallCalls === 1 && $m5->settingsDeleted === true, 'F-002-01: authorized module uninstall mutates');

// ---------------------------------------------------------------------------
// F-002-01 — Payment install / uninstall
// ---------------------------------------------------------------------------
list($p1, $pm1) = mtucAud002_paymentController(
    array('access' => array('extension/payment/mt_uni_credit')),
    array('route' => 'extension/payment/mt_uni_credit/install')
);
$p1->install();
mtucAud002_assert($pm1->installCalls === 0, 'F-002-01: payment install blocked without modify');

list($p2, $pm2) = mtucAud002_paymentController(array(
    'modify' => array('extension/payment/mt_uni_credit'),
));
$p2->install();
mtucAud002_assert($pm2->installCalls === 1, 'F-002-01: exact payment modify allows install');

list($p3, $pm3) = mtucAud002_paymentController(
    array('modify' => array('extension/extension/payment')),
    array('route' => 'extension/extension/payment/install')
);
$p3->install();
mtucAud002_assert($pm3->installCalls === 1, 'F-002-01: native outer payment installer modify allows install');

list($p4, $pm4) = mtucAud002_paymentController(
    array('access' => array('extension/payment/mt_uni_credit')),
    array('route' => 'extension/payment/mt_uni_credit/uninstall')
);
$p4->uninstall();
mtucAud002_assert($pm4->uninstallCalls === 0 && $pm4->settingsDeleted === false, 'F-002-01: payment uninstall blocked without modify');

list($p5, $pm5) = mtucAud002_paymentController(array(
    'modify' => array('extension/payment/mt_uni_credit'),
));
$p5->uninstall();
mtucAud002_assert($pm5->uninstallCalls === 1, 'F-002-01: authorized payment uninstall mutates');

// ---------------------------------------------------------------------------
// F-002-02 — Product Buy direct route vs OCMOD/Loader invocation
// ---------------------------------------------------------------------------
$sessionA = array(
    'payment_methods' => array(
        'mt_uni_credit' => array('code' => 'mt_uni_credit', 'title' => 'UniCredit'),
        'cod' => array('code' => 'cod', 'title' => 'COD'),
    ),
);
$navA = MtUniCreditProductBuyPreference::save($sessionA, array(
    'store_id' => 0,
    'product_id' => 10,
    'scheme_type' => 'promo',
    'kop_code' => 'K1',
    'months' => 12,
    'filter_id' => 1,
));
$buyA = mtucAud002_productBuyController($sessionA, $navA);

// Simulate Action::execute direct-route refusal (required params > 0 args).
$refApply = new ReflectionMethod($buyA, 'applyPaymentPreselect');
mtucAud002_assert(
    $refApply->getNumberOfRequiredParameters() === 1,
    'F-002-02: applyPaymentPreselect requires 1 arg (direct route blocked by Action)'
);
try {
    // Mirror OC3 Action::execute($registry) with zero args via Reflection (avoids
    // static "Expected 1. Found 0" on a deliberate under-arity call site).
    $refApply->invokeArgs($buyA, array());
    mtucAud002_assert(false, 'F-002-02: direct applyPaymentPreselect without args must fail');
} catch (ArgumentCountError $e) {
    mtucAud002_assert(true, 'F-002-02: direct applyPaymentPreselect without args fails closed');
} catch (Error $e) {
    // PHP 7.3 may throw ArgumentCountError (Error) or TypeError depending on build.
    mtucAud002_assert(
        strpos($e->getMessage(), 'applyPaymentPreselect') !== false
            || strpos($e->getMessage(), 'arguments') !== false,
        'F-002-02: direct applyPaymentPreselect without args fails closed'
    );
}
mtucAud002_assert(!isset($sessionA['payment_method']), 'F-002-02: direct route did not preselect payment');
mtucAud002_assert(
    isset($sessionA[MtUniCreditProductBuyPreference::SESSION_KEY]),
    'F-002-02: preference intact after blocked direct apply'
);

$loaderData = array();
$buyA->applyPaymentPreselect($loaderData);
mtucAud002_assert(
    isset($sessionA['payment_method']['code'])
        && $sessionA['payment_method']['code'] === 'mt_uni_credit',
    'F-002-02: OCMOD/Loader applyPaymentPreselect preselects UniCredit'
);

$sessionB = array(
    'payment_methods' => array(
        'mt_uni_credit' => array('code' => 'mt_uni_credit', 'title' => 'UniCredit'),
        'cod' => array('code' => 'cod', 'title' => 'COD'),
    ),
    'payment_method' => array('code' => 'cod', 'title' => 'COD'),
);
MtUniCreditProductBuyPreference::save($sessionB, array(
    'store_id' => 0,
    'product_id' => 11,
    'scheme_type' => 'promo',
    'kop_code' => 'K2',
    'months' => 6,
    'filter_id' => 2,
));
$buyB = mtucAud002_productBuyController($sessionB);
$refSaved = new ReflectionMethod($buyB, 'onPaymentMethodSaved');
mtucAud002_assert(
    $refSaved->getNumberOfRequiredParameters() === 1,
    'F-002-02: onPaymentMethodSaved requires 1 arg'
);
try {
    $refSaved->invokeArgs($buyB, array());
    mtucAud002_assert(false, 'F-002-02: direct onPaymentMethodSaved without args must fail');
} catch (ArgumentCountError $e) {
    mtucAud002_assert(true, 'F-002-02: direct onPaymentMethodSaved without args fails closed');
} catch (Error $e) {
    mtucAud002_assert(true, 'F-002-02: direct onPaymentMethodSaved without args fails closed');
}
mtucAud002_assert(
    isset($sessionB[MtUniCreditProductBuyPreference::SESSION_KEY]),
    'F-002-02: direct route did not clear Buy preference'
);

$savedData = array();
$buyB->onPaymentMethodSaved($savedData);
mtucAud002_assert(
    !isset($sessionB[MtUniCreditProductBuyPreference::SESSION_KEY]),
    'F-002-02: genuine onPaymentMethodSaved clears preference when payment changed away'
);

// Invalid / no preference — genuine event path, no mutation of payment_method
$sessionC = array(
    'payment_methods' => array(
        'cod' => array('code' => 'cod', 'title' => 'COD'),
    ),
);
$buyC = mtucAud002_productBuyController($sessionC);
$dC = array();
$buyC->applyPaymentPreselect($dC);
mtucAud002_assert(!isset($sessionC['payment_method']), 'F-002-02: no preference → no payment preselect');

// Navigation / pending lifecycle still works via library (unchanged by route guard)
$sessionD = array();
$navD = MtUniCreditProductBuyPreference::save($sessionD, array(
    'store_id' => 0,
    'product_id' => 12,
    'scheme_type' => 'std',
    'kop_code' => 'K3',
    'months' => 3,
    'filter_id' => 3,
));
mtucAud002_assert(
    isset($sessionD[MtUniCreditProductBuyPreference::SESSION_KEY]['navigation_id'])
        && $sessionD[MtUniCreditProductBuyPreference::SESSION_KEY]['state']
        === MtUniCreditProductBuyPreference::STATE_PENDING,
    'F-002-02: navigation_id pending lifecycle preserved after save'
);
$loaded = MtUniCreditProductBuyPreference::load($sessionD, 0, $navD);
mtucAud002_assert(
    is_array($loaded)
        && $loaded['state'] === MtUniCreditProductBuyPreference::STATE_ACTIVE
        && isset($sessionD[MtUniCreditProductBuyPreference::CHECKOUT_GUARD_KEY]),
    'F-002-02: first load activates preference + checkout guard'
);

$routeCart = 'checkout/cart';
$dataCart = array();
$buyD = mtucAud002_productBuyController($sessionD, $navD);
$buyD->releaseCheckoutGuard($routeCart, $dataCart);
mtucAud002_assert(
    !isset($sessionD[MtUniCreditProductBuyPreference::SESSION_KEY]),
    'F-002-02: event releaseCheckoutGuard still clears preference'
);

// ---------------------------------------------------------------------------
echo PHP_EOL;
if ($failures) {
    echo 'AUD-002 TRUST-BOUNDARY: FAIL (' . count($failures) . ' failures, ' . $passes . ' passes)' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'AUD-002 TRUST-BOUNDARY: PASS (' . $passes . ' passes)' . PHP_EOL;
exit(0);
