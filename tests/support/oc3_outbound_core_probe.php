<?php
/** Execute module integration against actual official OC3 classes in one context/version. */
require_once dirname(__DIR__) . '/bootstrap.php';
$version = $argv[1];
$context = $argv[2];
if (!in_array($version, array('3.0.3.9', '3.0.5.1'), true) || !in_array($context, array('admin', 'catalog'), true)) { exit(2); }
$repo = dirname(__DIR__, 2);
$core = dirname(__DIR__) . '/fixtures/oc3_core/' . $version . '/';
define('DIR_SYSTEM', $repo . '/upload/system/');
define('DIR_APPLICATION', $repo . '/upload/' . $context . '/');
define('DIR_LANGUAGE', DIR_APPLICATION . 'language/');
define('DIR_CONFIG', $core . 'system/config/');
define('DIR_STORAGE', $argv[3] . '/');
define('DIR_SESSION', DIR_STORAGE);
define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
define('DB_PREFIX', 'oc_');
foreach (array('HTTP_SERVER', 'HTTPS_SERVER', 'HTTP_CATALOG', 'HTTPS_CATALOG') as $name) { define($name, 'https://shop.example.com/'); }
foreach (array('DB_DRIVER', 'DB_HOSTNAME', 'DB_USERNAME', 'DB_DATABASE', 'DB_PORT') as $name) { define($name, 'fixture'); }
foreach (array('registry', 'model', 'controller', 'proxy', 'action', 'event', 'loader') as $class) { require_once $core . 'system/engine/' . $class . '.php'; }
foreach (array('config', 'request', 'url', 'session', 'session/file', 'language') as $class) { require_once $core . 'system/library/' . $class . '.php'; }
require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';
require_once __DIR__ . '/cp_outbound_harness.php';
require_once __DIR__ . '/core_compatibility_event_db.php';
// Native installers only use this helper for the ASCII extension code length.
function utf8_strlen($value) { return strlen($value); }
$passed = 0;
$failed = 0;
function coreAssert($ok, $message) {
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
}
$registry = new Registry();
$config = new Config();
$config->load($context);
$config->set('config_store_id', Phase4TestHarness::TEST_STORE_ID);
$config->set('config_ssl', 'https://shop.example.com/');
$config->set('config_url', 'http://shop.example.com/');
$registry->set('config', $config);
$registry->set('request', new Request());
$registry->set('event', new Event($registry));
$registry->set('load', new Loader($registry));
$registry->set('language', new Language('en-gb'));
$registry->set('url', new Url(HTTP_SERVER, HTTPS_SERVER));
$session = new Session('file');
$session->start();
$session->data = array('user_token' => 'fixture-admin-session', 'currency' => 'EUR', 'order_id' => 90001,
    'mt_uni_credit_probe' => array('store_id' => Phase4TestHarness::TEST_STORE_ID, 'order_id' => '90001'));
$session->close();
$nextSession = new Session('file');
$nextSession->start($session->getId());
coreAssert($nextSession->data === $session->data, 'native session adapter preserves module/store/order context');
$registry->set('session', $nextSession);
$request = $registry->get('request');
$normalized = $request->clean(' ' . Phase4TestHarness::TEST_UNICID . ' ');
coreAssert(trim($normalized) === Phase4TestHarness::TEST_UNICID, 'request normalization remains compatible with module trim');
coreAssert($normalized === ($version === '3.0.5.1' ? Phase4TestHarness::TEST_UNICID : ' ' . Phase4TestHarness::TEST_UNICID . ' '), 'known Request::clean whitespace difference is exercised');
$registry->get('load')->language('extension/' . ($context === 'admin' ? 'module' : 'payment') . '/mt_uni_credit');
coreAssert($registry->get('language')->get('heading_title') !== 'heading_title', 'native language loader finds module extension path');
$link = $registry->get('url')->link('extension/module/mt_uni_credit/refreshBankData', 'user_token=fixture-admin-session', true);
coreAssert(strpos($link, 'https://shop.example.com/index.php?route=extension/module/mt_uni_credit/refreshBankData') === 0, 'native URL routing preserves extension/method path');
$memory = Phase4TestHarness::memoryDb();
$registry->set('db', $memory);
$db = new MtUniCreditDbAdapter($memory, 'oc_');
$eventDb = new CoreCompatibilityEventDb();
$events = MtUniCreditInstaller::ensureCatalogEvents($eventDb);
$again = MtUniCreditInstaller::ensureCatalogEvents($eventDb);
coreAssert(!empty($events['healthy']) && !empty($again['healthy']) && $again['inserted'] === 0, 'module event install/reinstall is idempotent');
$definitions = array_values(array_filter(MtUniCreditCatalogEventRegistry::definitions(), function ($row) use ($context) {
    return strpos($row['trigger'], $context . '/') === 0;
}));
$registry->set('model_setting_event', new class($definitions) {
    private $rows;
    public function __construct($rows) { $this->rows = $rows; }
    public function getEvents() { return array_map(function ($row) { return $row + array('status' => 1, 'sort_order' => 0); }, $this->rows); }
});
require_once $core . $context . '/controller/startup/event.php';
(new ControllerStartupEvent($registry))->index();
$eventData = new ReflectionProperty($registry->get('event'), 'data');
$eventData->setAccessible(true);
$registered = $eventData->getValue($registry->get('event'));
coreAssert(count($registered) === count($definitions), 'native startup registers managed context events');
$stripped = true;
foreach ($registered as $row) { if (strpos($row['trigger'], $context . '/') === 0) { $stripped = false; } }
coreAssert($stripped, 'native startup strips admin/catalog prefix for Loader/Router triggers');

if ($context === 'admin') {
    $registry->get('load')->model('extension/module/mt_uni_credit');
    $proxy = $registry->get('model_extension_module_mt_uni_credit');
    coreAssert($proxy instanceof Proxy && is_array($proxy->getDefaultSettings()), 'native Loader and Proxy execute real admin model');
    class CoreRefreshProbe extends ModelExtensionModuleMtUniCredit {
        public $services;
        public function createCpServices() { return $this->services; }
    }
    $model = new CoreRefreshProbe($registry);
    $logger = new class {
        public $lines = array();
        public function write($line) { $this->lines[] = $line; }
    };
    $registry->set('log', $logger);
    coreAssert($model->log === $logger && !isset($model->log), 'actual core demonstrates Registry logger available while isset is false');
    $transport = new Phase4FakeCpHttpTransport();
    $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
    $transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());
    $model->services = CpOutboundTestHarness::services('https://cptest.ucfinonline.bg', $transport, $memory);
    $result = $model->refreshBankData();
    coreAssert(isset($result['success']) && count($transport->requests) === 2, 'admin staging refresh reaches login and shop transport');
    coreAssert(count($logger->lines) === 1 && strpos($logger->lines[0], 'bank_data_refreshed') !== false, 'refresh logger actually called via Registry');
    // ResolveCatalogUrls and the catalog factory must preserve exactly the same Shop.name.
    coreAssert($transport->requests[0]['payload']['name'] === (new MtUniCreditCanonicalShopUrlProvider())->resolve($config->get('config_ssl'), $config->get('config_url')), 'admin/catalog shop identity consistency');
    $invalidTransport = new Phase4FakeCpHttpTransport();
    $model->services = CpOutboundTestHarness::services('https://localhost', $invalidTransport, $memory);
    $result = $model->refreshBankData();
    $line = end($logger->lines);
    coreAssert(isset($result['error']) && $result['error'] === 'shop_snapshot_invalid' && count($invalidTransport->requests) === 0, 'invalid admin destination retains controlled classification/zero transport');
    coreAssert(strpos($line, 'transport_attempted=no') !== false && strpos($line, 'reason_code="destination_unsafe"') !== false
        && strpos($line, Phase4TestHarness::TEST_SECRET) === false, 'admin safe diagnostic distinguishes local rejection');
    $registry->set('log', null);
    $warnings = array();
    set_error_handler(function ($severity, $message) use (&$warnings) { if ($severity & (E_WARNING | E_NOTICE)) { $warnings[] = $message; } return false; });
    $result = $model->refreshBankData();
    restore_error_handler();
    coreAssert(isset($result['error']) && $warnings === array(), 'absent Registry logger: refresh does not warn/crash');
    $coreEvents = $config->get('action_event');
    $expected = $version === '3.0.5.1' ? array(999 => 'event/language', 1000 => 'event/theme') : array(0 => 'event/language');
    coreAssert($coreEvents['view/*/before'] === $expected, 'effective admin language/theme priority difference characterized');
    require_once $core . 'admin/controller/event/language.php';
    $args = array('heading_title' => 'module-owned heading');
    $route = 'extension/module/mt_uni_credit';
    $code = '';
    (new ControllerEventLanguage($registry))->index($route, $args, $code);
    coreAssert($args['heading_title'] === 'module-owned heading', 'core language event does not overwrite module view values');

    // Native installers are real core controllers; presentation getList is excluded.
    require_once $core . 'admin/controller/extension/extension/module.php';
    require_once $core . 'admin/controller/extension/extension/payment.php';
    class CoreModuleInstallerProbe extends ControllerExtensionExtensionModule { protected function getList() {} }
    class CorePaymentInstallerProbe extends ControllerExtensionExtensionPayment { protected function getList() {} }
    $permissionDb = new class {
        public $permissions = array();
        public function addPermission($group, $type, $route) { $this->permissions[$type][$route] = true; }
        public function removePermissions($route) { unset($this->permissions['access'][$route], $this->permissions['modify'][$route]); }
    };
    $user = new class {
        public function getGroupId() { return 1; }
        // Cart\User's in-memory permissions remain stale after native DB grants.
        public function hasPermission($type, $route) { return $type === 'modify' && strpos($route, 'extension/extension/') === 0; }
    };
    $registry->set('user', $user);
    $registry->set('model_user_user_group', $permissionDb);
    $registry->set('model_setting_extension', new class { public function install($type, $code) {} public function uninstall($type, $code) {} });
    $registry->set('model_setting_module', new class { public function deleteModulesByCode($code) {} });
    $registry->set('model_setting_setting', new class($db) {
        private $db;
        public function __construct($db) { $this->db = $db; }
        public function getSettingValue($key, $store = 0) { return (new MtUniCreditSettingStore($this->db, MtUniCreditConstants::MODULE_SETTINGS_CODE))->get($store, $key); }
        public function deleteSetting($code) { $this->db->query("DELETE FROM `oc_setting` WHERE `code` = '" . $this->db->escape($code) . "'"); }
    });
    $lifecycleModel = new class {
        public $installs = 0;
        public $uninstalls = 0;
        public function install() { $this->installs++; }
        public function uninstall() { $this->uninstalls++; }
    };
    // Exercise actual runtime controller authorization/delegation without repeating
    // the dedicated schema suite. Core grants do not reload the Cart\User object.
    $registry->set('model_extension_module_mt_uni_credit', $lifecycleModel);
    $registry->set('model_extension_payment_mt_uni_credit', $lifecycleModel);
    $request->get['extension'] = 'mt_uni_credit';
    foreach (array('module' => new CoreModuleInstallerProbe($registry), 'payment' => new CorePaymentInstallerProbe($registry)) as $type => $installer) {
        $route = 'extension/' . $type . '/mt_uni_credit';
        $installer->install();
        coreAssert(!empty($permissionDb->permissions['access'][$route]) && !empty($permissionDb->permissions['modify'][$route]), $type . ': native install grants permissions despite stale user object');
        $installer->uninstall();
        coreAssert($version === '3.0.3.9' ? !empty($permissionDb->permissions['modify'][$route]) : empty($permissionDb->permissions['modify'][$route]), $type . ': known uninstall permission difference exercised');
        $installer->install();
        coreAssert(!empty($permissionDb->permissions['modify'][$route]), $type . ': reinstall restores usable permission path');
    }
    coreAssert($lifecycleModel->installs === 4 && $lifecycleModel->uninstalls === 2, 'real runtime controllers authorize native install/reinstall with stale permissions');
} else {
    $registry->set('cart', new class { public function hasRecurringProducts() { return true; } });
    $registry->get('load')->model('extension/payment/mt_uni_credit');
    $proxy = $registry->get('model_extension_payment_mt_uni_credit');
    coreAssert($proxy instanceof Proxy && $proxy->getMethod(array(), 500) === array(), 'native catalog Loader/Proxy executes real payment recurring-product gate');
    // Register the real navigation guard on the real wildcard Event engine. Router passes
    // null in 3.0.3.9 and [] in 3.0.5.1; the module must accept both by reference.
    $event = $registry->get('event');
    foreach (array(null, array()) as $data) {
        $route = 'checkout/payment_method';
        $result = $event->trigger('controller/checkout/payment_method/before', array(&$route, &$data));
        coreAssert($result === null && $nextSession->data['mt_uni_credit_probe']['order_id'] === '90001', 'real wildcard guard accepts router data and preserves checkout session');
    }
    require_once $core . 'catalog/controller/startup/router.php';
    $request->get['route'] = 'extension/mt_uni_credit/product_buy/onStorefrontNavigation';
    // Action with required event arguments is intentionally not directly routable.
    $result = (new ControllerStartupRouter($registry))->index();
    coreAssert($result instanceof Exception, 'native router cannot directly invoke callback requiring event arguments');
    $events = $config->get('action_event');
    coreAssert($events['view/*/before'][998] === 'event/language' && $events['view/*/before'][500] === 'event/theme', 'catalog language/event contract preserved');
}
echo $version . ' ' . $context . ': passed=' . $passed . ' failed=' . $failed . PHP_EOL;
exit($failed === 0 ? 0 : 1);
