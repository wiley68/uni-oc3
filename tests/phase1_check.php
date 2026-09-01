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
    'upload/admin/controller/extension/payment/mt_uni_credit.php',
    'upload/admin/model/extension/payment/mt_uni_credit.php',
    'upload/admin/language/en-gb/extension/payment/mt_uni_credit.php',
    'upload/admin/language/bg-bg/extension/payment/mt_uni_credit.php',
    'upload/admin/view/template/extension/payment/mt_uni_credit.twig',
    'upload/system/library/mt_uni_credit/bootstrap.php',
    'upload/system/library/mt_uni_credit/constants.php',
    'upload/system/library/mt_uni_credit/health.php',
    'scripts/package.ps1',
);

foreach ($requiredFiles as $relative) {
    mtuc1_assert(is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative)), 'required file: ' . $relative);
}

$forbiddenPhase1 = array(
    'upload/catalog/controller/extension/payment/mt_uni_credit.php',
    'upload/catalog/model/extension/payment/mt_uni_credit.php',
    'upload/catalog/controller/extension/mt_uni_credit/api.php',
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
mtuc1_assert($fileNodes->length === 0, 'Phase 1 install.xml has no OCMOD file operations');

$constantsPath = $root . DIRECTORY_SEPARATOR . 'upload/system/library/mt_uni_credit/constants.php';
require_once $constantsPath;
require_once $root . DIRECTORY_SEPARATOR . 'upload/system/library/mt_uni_credit/health.php';
require_once $root . DIRECTORY_SEPARATOR . 'upload/system/library/mt_uni_credit/bootstrap.php';

mtuc1_assert(MtUniCreditConstants::EXTENSION_CODE === $identity['code'], 'constants extension code');
mtuc1_assert(MtUniCreditConstants::VERSION === $identity['version'], 'constants version');
mtuc1_assert(MtUniCreditConstants::SETTINGS_CODE === $identity['oc3_settings_code'], 'constants settings code');
mtuc1_assert(MtUniCreditConstants::ADMIN_ROUTE === $identity['oc3_admin_route'], 'constants admin route');

$adminControllerPath = $root . DIRECTORY_SEPARATOR . 'upload/admin/controller/extension/payment/mt_uni_credit.php';
$adminController = file_get_contents($adminControllerPath);
mtuc1_assert(strpos($adminController, 'class ControllerExtensionPaymentMtUniCredit') !== false, 'admin controller class naming');
mtuc1_assert(strpos($adminController, "hasPermission('modify', 'extension/payment/mt_uni_credit')") !== false, 'admin permission route');

$adminModelPath = $root . DIRECTORY_SEPARATOR . 'upload/admin/model/extension/payment/mt_uni_credit.php';
$adminModel = file_get_contents($adminModelPath);
mtuc1_assert(strpos($adminModel, 'class ModelExtensionPaymentMtUniCredit') !== false, 'admin model class naming');
mtuc1_assert(strpos($adminModel, 'secret_phase2_required') !== false, 'model rejects plaintext secret persistence');
mtuc1_assert(strpos($adminModel, 'deleteSetting') !== false, 'uninstall removes settings via deleteSetting');

$twigPath = $root . DIRECTORY_SEPARATOR . 'upload/admin/view/template/extension/payment/mt_uni_credit.twig';
$twig = file_get_contents($twigPath);
mtuc1_assert(strpos($twig, 'type="password"') !== false, 'secret field uses password input');
mtuc1_assert(strpos($twig, 'value=""') !== false, 'secret field never repopulated');
mtuc1_assert(strpos($twig, 'health_checks') !== false, 'template renders health checks');

foreach (MtUniCreditConstants::phase1PersistedSettingKeys() as $key) {
    mtuc1_assert(strpos($key, MtUniCreditConstants::SETTINGS_CODE . '_') === 0, 'setting key prefix: ' . $key);
}

$health = MtUniCreditHealth::evaluate(array(
    'secret_configured' => true,
    'unicid' => 'TEST-UNICID',
));
mtuc1_assert(isset($health['summary']['status']), 'health summary present');
foreach ($health['checks'] as $check) {
    mtuc1_assert(!empty($check['id']) && !empty($check['status']), 'health check shape');
    MtUniCreditHealth::assertNoSecretLeak((string) $check['detail']);
}
$encoded = json_encode($health);
mtuc1_assert(stripos($encoded, 'BEGIN PRIVATE KEY') === false, 'health JSON redaction');
mtuc1_assert(stripos($encoded, 'enc:v1:') === false, 'health JSON no ciphertext');

$post = array(
    MtUniCreditConstants::SETTING_STATUS => '1',
    MtUniCreditConstants::SETTING_SORT_ORDER => '5',
    MtUniCreditConstants::SETTING_ENVIRONMENT => MtUniCreditConstants::ENVIRONMENT_TEST,
    MtUniCreditConstants::SETTING_DEBUG => '0',
    MtUniCreditConstants::SETTING_UNICID => 'SHOP-1',
    MtUniCreditConstants::SETTING_SECRET => 'must-not-persist',
);

$errors = array();
if (!class_exists('Model', false)) {
    class Model {}
}
if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
require_once $adminModelPath;
$validationModel = new ModelExtensionPaymentMtUniCredit();
$errors = $validationModel->validateSettings($post);
mtuc1_assert(isset($errors['secret']), 'validate rejects secret submission in Phase 1');

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

    public function installDefaults(): void
    {
        foreach (MtUniCreditConstants::defaultSettings() as $key => $value) {
            $this->insertDefault($key, $value, 0);
        }
    }

    public function getInsertCount(): int
    {
        return $this->insertCount;
    }
}

$installHarness = new Phase1InstallHarness();
$installHarness->installDefaults();
$firstCount = $installHarness->getInsertCount();
$installHarness->installDefaults();
mtuc1_assert($firstCount > 0, 'install inserts default settings');
mtuc1_assert($installHarness->getInsertCount() === $firstCount, 'install twice is idempotent');

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

$packageScript = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'package.ps1';
mtuc1_assert(is_file($packageScript), 'package script exists');

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
