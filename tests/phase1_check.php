<?php

/**
 * Phase 1 skeleton checks. Run: php tests/phase1_check.php
 *
 * PHP 7.3 compatible. No PHPUnit. No live network.
 */
require_once __DIR__ . '/bootstrap.php';

$failures = array();
$passes = 0;

function mtuc1_assert(bool $condition, string $message): void
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
$identity = mtuc_phase0_load_fixture('extension_identity.json');
$forbiddenSyntax = mtuc_phase0_load_fixture('forbidden_php_syntax.json');

$requiredFiles = array(
    'install.xml',
    'upload/admin/controller/extension/module/mt_uni_credit.php',
    'upload/admin/model/extension/module/mt_uni_credit.php',
    'upload/admin/language/en-gb/extension/module/mt_uni_credit.php',
    'upload/admin/language/bg-bg/extension/module/mt_uni_credit.php',
    'upload/admin/view/template/extension/module/mt_uni_credit.twig',
    'upload/admin/view/stylesheet/mt_uni_credit_module.css',
    'upload/admin/controller/extension/payment/mt_uni_credit.php',
    'upload/admin/model/extension/payment/mt_uni_credit.php',
    'upload/admin/language/en-gb/extension/payment/mt_uni_credit.php',
    'upload/admin/language/bg-bg/extension/payment/mt_uni_credit.php',
    'upload/admin/view/template/extension/payment/mt_uni_credit.twig',
    'upload/admin/view/image/payment/uni_logo.svg',
    'upload/system/library/mt_uni_credit/bootstrap.php',
    'upload/system/library/mt_uni_credit/constants.php',
    'upload/system/library/mt_uni_credit/health.php',
    'upload/system/library/mt_uni_credit/local_settings.php',
    'upload/system/library/mt_uni_credit/installer.php',
    'upload/catalog/controller/extension/mt_uni_credit/api.php',
    'scripts/package.ps1',
);

foreach ($requiredFiles as $relative) {
    mtuc1_assert(is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative)), 'required file: ' . $relative);
}

$forbiddenPhase1 = array(
    'upload/catalog/controller/extension/mt_uni_credit/api/shop_cache.php',
);

foreach ($forbiddenPhase1 as $relative) {
    mtuc1_assert(!is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative)), 'Phase 1 must not create: ' . $relative);
}

$installXmlPath = $root . DIRECTORY_SEPARATOR . 'install.xml';
$installXml = file_get_contents($installXmlPath);
mtuc1_assert($installXml !== false && trim($installXml) !== '', 'install.xml is non-empty');
$dom = new DOMDocument();
mtuc1_assert(@$dom->loadXML($installXml), 'install.xml parses as XML');
$codeNode = $dom->getElementsByTagName('code')->item(0);
$versionNode = $dom->getElementsByTagName('version')->item(0);
mtuc1_assert($codeNode && $codeNode->textContent === $identity['code'], 'install.xml code matches fixture');
mtuc1_assert($versionNode && $versionNode->textContent === $identity['version'], 'install.xml version matches fixture');
$fileNodes = $dom->getElementsByTagName('file');
mtuc1_assert($fileNodes->length >= 4, 'Phase 8 install.xml has product+cart OCMOD file operations');
$installXmlText = (string) $installXml;
mtuc1_assert(strpos($installXmlText, 'mt_uni_credit:product') !== false, 'install.xml has product marker');
mtuc1_assert(strpos($installXmlText, 'mt_uni_credit:cart') !== false, 'install.xml has cart marker');
mtuc1_assert(strpos($installXmlText, '$data[\'products\'] = array();') !== false, 'install.xml product controller anchor');
mtuc1_assert(strpos($installXmlText, '{{ content_bottom }}</div>') !== false, 'install.xml cart template anchor');
mtuc1_assert(strpos($installXmlText, 'error="skip"') !== false, 'install.xml theme files use error=skip');
mtuc1_assert(!preg_match('/<search[^>]*>[^<]*\\.\\*/', $installXmlText), 'install.xml has no broad .* regex in search');

$constantsPath = $root . DIRECTORY_SEPARATOR . 'upload/system/library/mt_uni_credit/constants.php';
require_once $constantsPath;
require_once $root . DIRECTORY_SEPARATOR . 'upload/system/library/mt_uni_credit/health.php';
require_once $root . DIRECTORY_SEPARATOR . 'upload/system/library/mt_uni_credit/installer.php';
require_once $root . DIRECTORY_SEPARATOR . 'upload/system/library/mt_uni_credit/local_settings.php';
require_once $root . DIRECTORY_SEPARATOR . 'upload/system/library/mt_uni_credit/bootstrap.php';

foreach ($identity['oc3_module_defaults'] as $settingKey => $expectedDefault) {
    mtuc1_assert(
        MtUniCreditConstants::defaultModuleSettings()[$settingKey] === $expectedDefault,
        'module default matches OC4 authority: ' . $settingKey
    );
}
mtuc1_assert(
    MtUniCreditConstants::MODULE_SETTING_ADVERTISING === $identity['oc3_module_setting_advertising'],
    'constants advertising setting key'
);
mtuc1_assert(
    in_array(MtUniCreditConstants::DEFAULT_PRODUCT_BUTTON_ACTION, $identity['oc3_module_product_button_actions'], true),
    'default product button action in fixture choices'
);

mtuc1_assert(MtUniCreditConstants::EXTENSION_CODE === $identity['code'], 'constants extension code');
mtuc1_assert(MtUniCreditConstants::VERSION === $identity['version'], 'constants version');
mtuc1_assert(MtUniCreditConstants::MODULE_SETTINGS_CODE === $identity['oc3_module_settings_code'], 'constants module settings code');
mtuc1_assert(MtUniCreditConstants::PAYMENT_SETTINGS_CODE === $identity['oc3_payment_settings_code'], 'constants payment settings code');
mtuc1_assert(MtUniCreditConstants::MODULE_ADMIN_ROUTE === $identity['oc3_module_admin_route'], 'constants module admin route');
mtuc1_assert(MtUniCreditConstants::PAYMENT_ADMIN_ROUTE === $identity['oc3_payment_admin_route'], 'constants payment admin route');

mtuc1_assert(MtUniCreditConstants::MODULE_DISPLAY_NAME === $identity['display_name'], 'constants module display name matches fixture');
mtuc1_assert(MtUniCreditConstants::MODULE_SETTING_DEBUG === $identity['oc3_module_setting_debug'], 'constants debug setting key normalized');

$bgModuleLangPath = $root . DIRECTORY_SEPARATOR . 'upload/admin/language/bg-bg/extension/module/mt_uni_credit.php';
$bgModuleLang = file_get_contents($bgModuleLangPath);
mtuc1_assert(strpos($bgModuleLang, "'heading_title'] = 'УниКредит покупки на Кредит'") !== false, 'BG module heading_title is established title');

$bgPaymentLangPath = $root . DIRECTORY_SEPARATOR . 'upload/admin/language/bg-bg/extension/payment/mt_uni_credit.php';
$bgPaymentLang = file_get_contents($bgPaymentLangPath);
mtuc1_assert(strpos($bgPaymentLang, "'heading_title'] = 'УниКредит покупки на Кредит'") !== false, 'BG payment heading_title is established title');
mtuc1_assert(strpos($bgPaymentLang, "text_mt_uni_credit']") !== false, 'BG payment listing logo language key present');
mtuc1_assert(strpos($bgPaymentLang, $identity['oc3_payment_listing_image_style']) !== false, 'BG payment listing logo max-width style');

$paymentImagePath = $root . DIRECTORY_SEPARATOR . 'upload/admin/view/image/payment/uni_logo.svg';
mtuc1_assert(is_file($paymentImagePath), 'payment listing logo asset exists');
mtuc1_assert(filesize($paymentImagePath) > 0, 'payment listing logo asset is non-empty');

$moduleControllerPath = $root . DIRECTORY_SEPARATOR . 'upload/admin/controller/extension/module/mt_uni_credit.php';
$moduleController = file_get_contents($moduleControllerPath);
mtuc1_assert(strpos($moduleController, 'class ControllerExtensionModuleMtUniCredit') !== false, 'module controller class naming');
mtuc1_assert(strpos($moduleController, "hasPermission('modify', 'extension/module/mt_uni_credit')") !== false, 'module permission route');

$moduleModelPath = $root . DIRECTORY_SEPARATOR . 'upload/admin/model/extension/module/mt_uni_credit.php';
$moduleModel = file_get_contents($moduleModelPath);
mtuc1_assert(strpos($moduleModel, 'class ModelExtensionModuleMtUniCredit') !== false, 'module model class naming');
mtuc1_assert(strpos($moduleModel, 'MODULE_SETTINGS_CODE') !== false, 'module install uses module settings code');
mtuc1_assert(strpos($moduleModel, 'credentialsRepositoryFromModel') !== false, 'module uses encrypted credentials repository');
mtuc1_assert(strpos($moduleModel, 'getHealthReport') !== false, 'module model owns health');

mtuc1_assert(strpos($moduleModel, 'unicid_required') !== false, 'module validates UNICID required');
mtuc1_assert(strpos($moduleModel, 'unicid_max_length') !== false, 'module validates UNICID max length');
mtuc1_assert(strpos($moduleModel, 'secret_required') !== false, 'module validates secret required when missing');
mtuc1_assert(strpos($moduleModel, 'MtUniCreditLocalSettings::normalizeFlag') !== false, 'module model delegates flag normalization to local settings');
mtuc1_assert(strpos($moduleModel, 'invalid_product_button_action') !== false, 'module validates product button action');
mtuc1_assert(strpos($moduleModel, 'invalid_button_top_spacing') !== false, 'module validates button top spacing range');
mtuc1_assert(strpos($moduleModel, 'buildPhase1JournalExport') !== false, 'module model provides Phase 1 journal placeholder');

$moduleTwigPath = $root . DIRECTORY_SEPARATOR . 'upload/admin/view/template/extension/module/mt_uni_credit.twig';
$moduleTwig = file_get_contents($moduleTwigPath);
mtuc1_assert(strpos($moduleTwig, 'module_mt_uni_credit_environment') === false, 'module twig has no environment field');
mtuc1_assert(strpos($moduleTwig, 'health_checks') === false, 'module twig has no visible health panel');
mtuc1_assert(strpos($moduleTwig, 'text_health') === false, 'module twig has no health heading');
mtuc1_assert(strpos($moduleTwig, 'class="mt-uni-credit-module"') !== false || strpos($moduleTwig, 'mt-uni-credit-module') !== false, 'module twig scopes toggle container');
mtuc1_assert(strpos($moduleTwig, 'mt-uni-toggle') !== false, 'module twig uses mt-uni-toggle class');
mtuc1_assert(strpos($moduleTwig, 'mt-uni-toggle__input') !== false, 'module twig keeps native checkbox input');
mtuc1_assert(strpos($moduleTwig, 'mt-uni-toggle__track') !== false, 'module twig renders toggle track');
mtuc1_assert(substr_count($moduleTwig, 'mt-uni-toggle__input') === 3, 'module twig has three toggle inputs');
mtuc1_assert(strpos($moduleController, 'mt_uni_credit_module.css') !== false, 'module controller loads scoped admin CSS only');
$toggleCssPath = $root . DIRECTORY_SEPARATOR . 'upload/admin/view/stylesheet/mt_uni_credit_module.css';
$toggleCss = file_get_contents($toggleCssPath);
mtuc1_assert(strpos($toggleCss, '.mt-uni-credit-module') !== false, 'toggle CSS scoped to module container');
mtuc1_assert(strpos($toggleCss, '.mt-uni-toggle') !== false, 'toggle CSS defines mt-uni-toggle');
mtuc1_assert(stripos($toggleCss, 'bootstrap') === false, 'toggle CSS has no Bootstrap dependency');
mtuc1_assert(strpos($moduleTwig, 'module_mt_uni_credit_advertising_enabled') !== false, 'module twig has advertising toggle');
mtuc1_assert(strpos($moduleTwig, 'module_mt_uni_credit_product_button_action') !== false, 'module twig has product button action');
mtuc1_assert(strpos($moduleTwig, 'module_mt_uni_credit_button_top_spacing') !== false, 'module twig has button top spacing');
mtuc1_assert(strpos($moduleTwig, 'button_refresh_bank_data') !== false, 'module twig has refresh bank data button');
mtuc1_assert(strpos($moduleTwig, 'button_download_journal') !== false, 'module twig has download journal button');
mtuc1_assert(strpos($moduleTwig, 'refresh_bank_data') !== false, 'module twig wires refresh bank data route');
mtuc1_assert(strpos($moduleTwig, 'download_journal') !== false, 'module twig wires download journal route');
mtuc1_assert(strpos($moduleTwig, 'module_mt_uni_credit_unicid') !== false, 'module twig has UNICID');
mtuc1_assert(strpos($moduleTwig, 'maxlength="36"') !== false, 'module twig UNICID maxlength 36');
mtuc1_assert(strpos($moduleTwig, 'module_mt_uni_credit_debug_enabled') !== false, 'module twig uses normalized debug key');
mtuc1_assert(strpos($moduleTwig, 'type="hidden" name="module_mt_uni_credit_status" value="0"') !== false, 'module status hidden false value');
mtuc1_assert(strpos($moduleTwig, 'type="checkbox" name="module_mt_uni_credit_status"') !== false, 'module status checkbox control');
mtuc1_assert(strpos($moduleTwig, 'type="hidden" name="module_mt_uni_credit_debug_enabled" value="0"') !== false, 'module debug hidden false value');
mtuc1_assert(strpos($moduleTwig, 'type="checkbox" name="module_mt_uni_credit_debug_enabled"') !== false, 'module debug checkbox control');
mtuc1_assert(strpos($moduleTwig, 'type="hidden" name="module_mt_uni_credit_advertising_enabled" value="0"') !== false, 'module advertising hidden false value');
mtuc1_assert(strpos($moduleTwig, 'type="checkbox" name="module_mt_uni_credit_advertising_enabled"') !== false, 'module advertising checkbox control');
mtuc1_assert(strpos($moduleTwig, 'name="module_mt_uni_credit_secret" value=""') !== false, 'module secret never renders stored value');
mtuc1_assert(strpos($moduleTwig, 'maxlength="64"') !== false, 'module twig secret maxlength 64');
mtuc1_assert(strpos($moduleTwig, 'type="password"') !== false, 'module secret uses password input');
mtuc1_assert(strpos($moduleTwig, '<select name="module_mt_uni_credit_status"') === false, 'module status is not a select menu');
mtuc1_assert(strpos($moduleTwig, 'text_product_button_add_to_cart') !== false || strpos($moduleTwig, 'product_button_actions') !== false, 'module twig renders product button choices');
mtuc1_assert(substr_count($moduleTwig, 'product_button_actions') >= 1, 'module twig iterates product button actions');
mtuc1_assert(strpos($moduleTwig, 'min="0"') !== false && strpos($moduleTwig, 'max="200"') !== false, 'module twig spacing min/max attributes');

mtuc1_assert(strpos($moduleController, 'function refreshBankData') !== false, 'module controller defines refreshBankData');
mtuc1_assert(strpos($moduleController, 'function downloadJournal') !== false, 'module controller defines downloadJournal');
mtuc1_assert(strpos($moduleController, 'refreshBankData') !== false && strpos($moduleController, 'model_extension_module_mt_uni_credit->refreshBankData()') !== false, 'refresh bank data delegates to model');
mtuc1_assert(strpos($moduleController, 'text_bank_data_refreshed') !== false, 'refresh bank data maps success flash');
mtuc1_assert(strpos($moduleController, 'assignHealth') === false, 'module controller does not assign visible health panel');
mtuc1_assert(strpos($moduleController, 'MODULE_SETTING_ENVIRONMENT') === false, 'module controller does not reference environment setting');

$paymentControllerPath = $root . DIRECTORY_SEPARATOR . 'upload/admin/controller/extension/payment/mt_uni_credit.php';
$paymentController = file_get_contents($paymentControllerPath);
mtuc1_assert(strpos($paymentController, 'class ControllerExtensionPaymentMtUniCredit') !== false, 'payment controller class naming');
mtuc1_assert(strpos($paymentController, "hasPermission('modify', 'extension/payment/mt_uni_credit')") !== false, 'payment permission route');
mtuc1_assert(strpos($paymentController, 'extension/module/mt_uni_credit') === false, 'payment controller does not reference module route');

$paymentModelPath = $root . DIRECTORY_SEPARATOR . 'upload/admin/model/extension/payment/mt_uni_credit.php';
$paymentModel = file_get_contents($paymentModelPath);
mtuc1_assert(strpos($paymentModel, 'PAYMENT_SETTINGS_CODE') !== false, 'payment install uses payment settings code');
mtuc1_assert(strpos($paymentModel, 'resolveProcessingOrderStatusId') !== false, 'payment install resolves Processing order status');
mtuc1_assert(strpos($paymentModel, 'getHealthReport') === false, 'payment model has no health');
mtuc1_assert(strpos($paymentModel, 'MODULE_SETTINGS_CODE') === false, 'payment model does not touch module settings code');

$paymentTwigPath = $root . DIRECTORY_SEPARATOR . 'upload/admin/view/template/extension/payment/mt_uni_credit.twig';
$paymentTwig = file_get_contents($paymentTwigPath);
mtuc1_assert(strpos($paymentTwig, 'payment_mt_uni_credit_order_status_id') !== false, 'payment twig has order status');
mtuc1_assert(strpos($paymentTwig, 'payment_mt_uni_credit_geo_zone_id') !== false, 'payment twig has geo zone');
mtuc1_assert(strpos($paymentTwig, 'module_mt_uni_credit_unicid') === false, 'payment twig has no UNICID');
mtuc1_assert(strpos($paymentTwig, 'health_checks') === false, 'payment twig has no health panel');
mtuc1_assert(strpos($paymentTwig, 'type="password"') === false, 'payment twig has no secret field');

foreach (MtUniCreditConstants::phase1ModulePersistedSettingKeys() as $key) {
    mtuc1_assert(strpos($key, MtUniCreditConstants::MODULE_SETTINGS_CODE . '_') === 0, 'module setting key prefix: ' . $key);
    mtuc1_assert(strpos($key, 'environment') === false, 'phase1 module keys exclude environment: ' . $key);
}
foreach (MtUniCreditConstants::paymentPersistedSettingKeys() as $key) {
    mtuc1_assert(strpos($key, MtUniCreditConstants::PAYMENT_SETTINGS_CODE . '_') === 0, 'payment setting key prefix: ' . $key);
}

$health = MtUniCreditHealth::evaluate(array(
    'secret_configured' => true,
    'unicid' => 'TEST-UNICID',
));
mtuc1_assert(isset($health['summary']['status']), 'health summary present');
foreach ($health['checks'] as $check) {
    MtUniCreditHealth::assertNoSecretLeak((string) $check['detail']);
}

$post = array(
    MtUniCreditConstants::MODULE_SETTING_STATUS => '1',
    MtUniCreditConstants::MODULE_SETTING_ADVERTISING => '0',
    MtUniCreditConstants::MODULE_SETTING_DEBUG => '0',
    MtUniCreditConstants::MODULE_SETTING_PRODUCT_BUTTON_ACTION => MtUniCreditConstants::BUTTON_ACTION_ADD_TO_CART,
    MtUniCreditConstants::MODULE_SETTING_BUTTON_TOP_SPACING => '0',
    MtUniCreditConstants::MODULE_SETTING_UNICID => 'SHOP-1',
    MtUniCreditConstants::MODULE_SETTING_SECRET => '',
);

if (!class_exists('Registry', false)) {
    class Registry {}
}
if (!class_exists('Model', false)) {
    class Model
    {
        /** @param Registry|null $registry */
        public function __construct($registry = null) {}
    }
}
if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
require_once $moduleModelPath;
$validationModel = new ModelExtensionModuleMtUniCredit(new Registry());
$errors = $validationModel->validateSettings($post);
mtuc1_assert(isset($errors['secret']), 'module validate requires secret when none stored');

$postSecretPersist = array(
    MtUniCreditConstants::MODULE_SETTING_STATUS => '1',
    MtUniCreditConstants::MODULE_SETTING_ADVERTISING => '0',
    MtUniCreditConstants::MODULE_SETTING_DEBUG => '0',
    MtUniCreditConstants::MODULE_SETTING_PRODUCT_BUTTON_ACTION => MtUniCreditConstants::BUTTON_ACTION_BUY,
    MtUniCreditConstants::MODULE_SETTING_BUTTON_TOP_SPACING => '10',
    MtUniCreditConstants::MODULE_SETTING_UNICID => 'SHOP-1',
    MtUniCreditConstants::MODULE_SETTING_SECRET => 'must-not-persist',
);
$errors = $validationModel->validateSettings($postSecretPersist);
mtuc1_assert(!isset($errors['secret']), 'module validate accepts secret submission for encrypted persistence');

$postMissingUnicid = array(
    MtUniCreditConstants::MODULE_SETTING_UNICID => '',
    MtUniCreditConstants::MODULE_SETTING_SECRET => '',
);
$errors = $validationModel->validateSettings($postMissingUnicid);
mtuc1_assert(isset($errors['unicid']), 'module validate rejects empty UNICID');

$postLongUnicid = array(
    MtUniCreditConstants::MODULE_SETTING_UNICID => str_repeat('A', 37),
    MtUniCreditConstants::MODULE_SETTING_SECRET => '',
);
$errors = $validationModel->validateSettings($postLongUnicid);
mtuc1_assert(isset($errors['unicid']), 'module validate rejects UNICID over 36 chars');

mtuc1_assert(
    MtUniCreditLocalSettings::normalizeFlag('') === '0',
    'local settings normalizeFlag handles checkbox semantics'
);

$duplicateChecked = array();
parse_str('module_mt_uni_credit_status=0&module_mt_uni_credit_status=1', $duplicateChecked);
mtuc1_assert(
    isset($duplicateChecked['module_mt_uni_credit_status'])
        && $duplicateChecked['module_mt_uni_credit_status'] === '1'
        && MtUniCreditLocalSettings::normalizeFlag($duplicateChecked['module_mt_uni_credit_status']) === '1',
    'hidden+checkbox duplicate name resolves to checked value in PHP'
);
$duplicateUnchecked = array();
parse_str('module_mt_uni_credit_status=0', $duplicateUnchecked);
mtuc1_assert(
    MtUniCreditLocalSettings::normalizeFlag($duplicateUnchecked['module_mt_uni_credit_status']) === '0',
    'hidden-only duplicate name resolves to unchecked value in PHP'
);
mtuc1_assert(
    MtUniCreditLocalSettings::normalizeProductButtonAction('buy') === MtUniCreditConstants::BUTTON_ACTION_BUY,
    'local settings accepts buy action'
);
mtuc1_assert(
    MtUniCreditLocalSettings::normalizeProductButtonAction('invalid') === MtUniCreditConstants::DEFAULT_PRODUCT_BUTTON_ACTION,
    'local settings falls back to add_to_cart'
);
mtuc1_assert(
    MtUniCreditLocalSettings::normalizeButtonTopSpacing('250') === '200',
    'local settings clamps button top spacing to 200'
);
mtuc1_assert(
    MtUniCreditLocalSettings::normalizeButtonTopSpacing('-5') === '0',
    'local settings clamps negative button top spacing to 0'
);

$postInvalidSpacing = array(
    MtUniCreditConstants::MODULE_SETTING_BUTTON_TOP_SPACING => '999',
    MtUniCreditConstants::MODULE_SETTING_UNICID => 'SHOP-1',
    MtUniCreditConstants::MODULE_SETTING_SECRET => '',
);
$errors = $validationModel->validateSettings($postInvalidSpacing);
mtuc1_assert(isset($errors['button_top_spacing']), 'module validate rejects spacing over 200');

$postInvalidAction = array(
    MtUniCreditConstants::MODULE_SETTING_PRODUCT_BUTTON_ACTION => 'invalid-action',
    MtUniCreditConstants::MODULE_SETTING_UNICID => 'SHOP-1',
    MtUniCreditConstants::MODULE_SETTING_SECRET => '',
);
$errors = $validationModel->validateSettings($postInvalidAction);
mtuc1_assert(isset($errors['product_button_action']), 'module validate rejects invalid product button action');

class Phase1InstallHarness
{
    /** @var array<int, array<string, string>> */
    private $values = array();

    /** @var int */
    private $insertCount = 0;

    public function getSettingValue(string $key, int $storeId = 0): ?string
    {
        if (!isset($this->values[$storeId][$key])) {
            return null;
        }

        return $this->values[$storeId][$key];
    }

    public function insertDefault(string $key, string $value, int $storeId = 0): void
    {
        if ($this->getSettingValue($key, $storeId) === null) {
            if (!isset($this->values[$storeId])) {
                $this->values[$storeId] = array();
            }
            $this->values[$storeId][$key] = $value;
            $this->insertCount++;
        }
    }

    public function installDefaults(array $defaults): void
    {
        foreach ($defaults as $key => $value) {
            $this->insertDefault($key, $value, 0);
        }
    }

    public function getInsertCount(): int
    {
        return $this->insertCount;
    }
}

$moduleHarness = new Phase1InstallHarness();
$moduleHarness->installDefaults(MtUniCreditConstants::defaultModuleSettings());
$moduleFirst = $moduleHarness->getInsertCount();
$moduleHarness->installDefaults(MtUniCreditConstants::defaultModuleSettings());
mtuc1_assert($moduleFirst > 0, 'module install inserts default settings');
mtuc1_assert($moduleHarness->getInsertCount() === $moduleFirst, 'module install twice is idempotent');

$paymentHarness = new Phase1InstallHarness();
$paymentDefaults = MtUniCreditConstants::defaultPaymentSettings();
$paymentDefaults[MtUniCreditConstants::PAYMENT_SETTING_ORDER_STATUS_ID] = '2';
$paymentHarness->installDefaults($paymentDefaults);
$paymentFirst = $paymentHarness->getInsertCount();
mtuc1_assert(
    $paymentHarness->getSettingValue(MtUniCreditConstants::PAYMENT_SETTING_ORDER_STATUS_ID) === '2',
    'payment install target uses resolved Processing id not placeholder zero'
);
$paymentHarness->installDefaults($paymentDefaults);
mtuc1_assert($paymentHarness->getInsertCount() === $paymentFirst, 'payment install twice is idempotent');

$paymentExistingHarness = new Phase1InstallHarness();
$paymentExistingHarness->insertDefault(MtUniCreditConstants::PAYMENT_SETTING_ORDER_STATUS_ID, '7');
$paymentExistingHarness->installDefaults($paymentDefaults);
mtuc1_assert(
    $paymentExistingHarness->getSettingValue(MtUniCreditConstants::PAYMENT_SETTING_ORDER_STATUS_ID) === '7',
    'existing saved payment order status is preserved on reinstall defaults'
);
mtuc1_assert(
    MtUniCreditConstants::defaultPaymentSettings()[MtUniCreditConstants::PAYMENT_SETTING_ORDER_STATUS_ID] === '0',
    'static payment defaults remain placeholder until install resolver runs'
);

$implementationPhp = array();
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . DIRECTORY_SEPARATOR . 'upload', FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
        $implementationPhp[] = $file->getPathname();
    }
}

foreach ($implementationPhp as $path) {
    $src = file_get_contents($path);
    mtuc1_assert(!mtuc_phase0_contains_live_remote_host($src), 'no live network in ' . str_replace($root . DIRECTORY_SEPARATOR, '', $path));
    foreach ($forbiddenSyntax['patterns'] as $pattern) {
        if (preg_match('/' . $pattern['regex'] . '/m', $src)) {
            mtuc1_assert(false, 'forbidden syntax ' . $pattern['id'] . ' in ' . str_replace($root . DIRECTORY_SEPARATOR, '', $path));
        }
    }
}

$workspaceParent = dirname($root);
foreach (array('reference-uni-oc4', 'reference-jet-oc3', 'reference-oc3-core', 'reference-oc3-store') as $ref) {
    $refPath = $workspaceParent . DIRECTORY_SEPARATOR . $ref;
    if (!is_dir($refPath)) {
        $refPath = dirname($workspaceParent) . DIRECTORY_SEPARATOR . $ref;
    }
    mtuc1_assert(is_dir($refPath), 'reference directory untouched/readable: ' . $ref);
}

mtuc1_assert(is_file($root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'package.ps1'), 'package script exists');

$packageScript = file_get_contents($root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'package.ps1');
mtuc1_assert(strpos($packageScript, 'CC_OpenCartv.3.x_UNI_v.$Version.ocmod.zip') !== false, 'package script uses approved filename convention');
mtuc1_assert(strpos($packageScript, 'upload/admin/view/image/payment/uni_logo.svg') !== false, 'package script verifies payment logo entry');

echo PHP_EOL;
if ($failures) {
    echo 'FAILED ' . count($failures) . ' / ' . ($passes + count($failures)) . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'OK ' . $passes . ' checks' . PHP_EOL;
exit(0);
