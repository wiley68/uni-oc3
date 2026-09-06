<?php

/**
 * AUD-003 F-003-01 — credential change / CP token invalidation consistency.
 * Run: php tests/phase_aud003_f00301_credential_token_check.php
 *
 * PHP 7.3 compatible. Offline network guard. No CP/SmartUCF.
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
function mtucF00301_assert($condition, $message)
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
    mtuc_test_define_dir_storage('mtuc-aud003-f00301');
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', MtUniCreditEncryptionTestSecret::testSecretInput());
}
if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'oc_');
}

require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';

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

require_once $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'admin'
    . DIRECTORY_SEPARATOR . 'model' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'module'
    . DIRECTORY_SEPARATOR . 'mt_uni_credit.php';
require_once __DIR__ . '/support/phase1_secret_save_harness.php';
require_once __DIR__ . '/support/phase4_harness.php';

mtucF00301_assert(mtuc_phase0_network_isolation_active(), 'isolation: offline guard active');

// ---------------------------------------------------------------------------
// Structural: OC3 DB has no transaction API; chosen strategy is fail-closed order
// ---------------------------------------------------------------------------
$oc3Db = dirname($root) . DIRECTORY_SEPARATOR . 'reference-oc3-core'
    . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'library' . DIRECTORY_SEPARATOR . 'db.php';
$oc3DbSrc = is_file($oc3Db) ? (string) file_get_contents($oc3Db) : '';
mtucF00301_assert($oc3DbSrc !== '', 'OC3 DB class readable');
mtucF00301_assert(
    strpos($oc3DbSrc, 'function begin') === false
        && strpos($oc3DbSrc, 'function commit') === false
        && strpos($oc3DbSrc, 'function rollback') === false
        && strpos($oc3DbSrc, 'START TRANSACTION') === false,
    'OC3 DB abstraction exposes no transaction API'
);

$modelSrc = (string) file_get_contents(
    $root . '/upload/admin/model/extension/module/mt_uni_credit.php'
);
$handlerSrc = (string) file_get_contents(
    $root . '/upload/system/library/mt_uni_credit/credential_change_handler.php'
);
mtucF00301_assert(
    strpos($handlerSrc, 'function invalidateAuthTokens') !== false,
    'handler exposes invalidateAuthTokens for pre-write fail-closed path'
);
mtucF00301_assert(
    strpos($modelSrc, 'invalidateAuthTokens()') !== false
        && strpos($modelSrc, 'credentialsChanged') !== false,
    'saveSettings calls invalidateAuthTokens on credential identity change'
);
$invalidatePos = strpos($modelSrc, 'invalidateAuthTokens()');
$editPos = strpos($modelSrc, '->editSetting(');
$saveSecretPos = strpos($modelSrc, '->saveSecret(');
mtucF00301_assert(
    $invalidatePos !== false && $editPos !== false && $invalidatePos < $editPos,
    'fail-closed order: invalidateAuthTokens before editSetting'
);
mtucF00301_assert(
    $editPos !== false && $saveSecretPos !== false && $editPos < $saveSecretPos,
    'editSetting still precedes saveSecret'
);
mtucF00301_assert(
    strpos($modelSrc, 'MtUniCreditCpTokenRepository::ACCESS_TOKEN') !== false,
    'credential change strips token keys from editSetting payload'
);

/**
 * DB proxy that can inject failures on matching SQL.
 */
final class Aud003FailingDb
{
    /** @var Phase2MemoryDb */
    public $inner;

    /** @var string|null */
    public $failDeleteKeyContains;

    /** @var string|null */
    public $failUpdateKeyContains;

    /** @var string|null */
    public $failInsertKeyContains;

    /** @var string|null */
    public $failDeleteTableContains;

    /** @var int */
    public $deleteFailAfterSuccesses = 0;

    /** @var int */
    private $matchingDeleteSuccesses = 0;

    public function __construct(Phase2MemoryDb $inner)
    {
        $this->inner = $inner;
    }

    /**
     * @param string $sql
     * @return mixed
     */
    public function query($sql)
    {
        $sql = (string) $sql;
        if (
            $this->failDeleteKeyContains !== null
            && stripos($sql, 'DELETE FROM') === 0
            && strpos($sql, 'setting') !== false
            && strpos($sql, $this->failDeleteKeyContains) !== false
        ) {
            if ($this->matchingDeleteSuccesses < $this->deleteFailAfterSuccesses) {
                $this->matchingDeleteSuccesses++;

                return $this->inner->query($sql);
            }
            throw new Exception('Error: injected setting delete failure');
        }
        if (
            $this->failDeleteTableContains !== null
            && stripos($sql, 'DELETE FROM') === 0
            && strpos($sql, $this->failDeleteTableContains) !== false
        ) {
            throw new Exception('Error: injected table delete failure');
        }
        if (
            $this->failUpdateKeyContains !== null
            && stripos($sql, 'UPDATE') === 0
            && strpos($sql, 'setting') !== false
            && strpos($sql, $this->failUpdateKeyContains) !== false
        ) {
            throw new Exception('Error: injected setting update failure');
        }
        if (
            $this->failInsertKeyContains !== null
            && stripos($sql, 'INSERT INTO') === 0
            && strpos($sql, 'setting') !== false
            && strpos($sql, $this->failInsertKeyContains) !== false
        ) {
            throw new Exception('Error: injected setting insert failure');
        }

        return $this->inner->query($sql);
    }

    /**
     * @param string $value
     * @return string
     */
    public function escape($value)
    {
        return $this->inner->escape($value);
    }

    /**
     * @return int
     */
    public function countAffected()
    {
        return $this->inner->countAffected();
    }

    /**
     * @return int
     */
    public function getLastId()
    {
        return $this->inner->getLastId();
    }
}

/**
 * editSetting surface that can fail independently of SettingStore token deletes.
 */
final class Aud003EditableSettingModel
{
    /** @var Phase1OcSettingModelFake */
    private $inner;

    /** @var bool */
    public $failEdit = false;

    public function __construct(Phase1OcSettingModelFake $inner)
    {
        $this->inner = $inner;
    }

    /**
     * @param string $code
     * @param int $store_id
     * @return array<string, string>
     */
    public function getSetting($code, $store_id = 0)
    {
        return $this->inner->getSetting($code, $store_id);
    }

    /**
     * @param string $code
     * @param array<string, mixed> $data
     * @param int $store_id
     * @return void
     */
    public function editSetting($code, $data, $store_id = 0)
    {
        if ($this->failEdit) {
            throw new Exception('Error: injected editSetting failure');
        }
        $this->inner->editSetting($code, $data, $store_id);
    }
}

/**
 * @param int $storeId
 * @param Aud003FailingDb|null $failingDb
 * @return array<string, mixed>
 */
function mtucF00301_harness($storeId = 0, $failingDb = null)
{
    $memory = ($failingDb !== null) ? $failingDb->inner : new Phase2MemoryDb();
    $dbSurface = ($failingDb !== null) ? $failingDb : $memory;
    $dbAdapter = new MtUniCreditDbAdapter($dbSurface, 'oc_');
    $settings = new MtUniCreditSettingStore($dbAdapter, MtUniCreditConstants::MODULE_SETTINGS_CODE);
    $cipher = new MtUniCreditSettingCipher(
        (new MtUniCreditEncryptionKeyProvider())->resolveDerivedKey(MtUniCreditEncryptionTestSecret::testSecretInput())
    );
    $credentials = new MtUniCreditCredentialsRepository($settings, $cipher);
    $tokens = new MtUniCreditCpTokenRepository($settings, $cipher, (int) $storeId);
    $cache = new MtUniCreditShopCacheRepository($dbAdapter);
    $credentialChange = new MtUniCreditCredentialChangeHandler($tokens, $cache, (int) $storeId);

    $innerSettingModel = new Phase1OcSettingModelFake($memory);
    $settingModel = new Aud003EditableSettingModel($innerSettingModel);
    $config = new Phase1ConfigFake(array(
        'config_store_id' => (int) $storeId,
        'config_ssl' => 'https://shop.example/',
        'config_url' => 'http://shop.example/',
    ));

    $model = new Aud003ModuleModel(
        $memory,
        $settingModel,
        $config,
        $dbAdapter,
        $credentials,
        $credentialChange,
        $tokens,
        $settings,
        $cipher
    );

    return array(
        'model' => $model,
        'memory' => $memory,
        'credentials' => $credentials,
        'tokens' => $tokens,
        'settings' => $settings,
        'cipher' => $cipher,
        'cache' => $cache,
        'dbAdapter' => $dbAdapter,
        'credentialChange' => $credentialChange,
        'settingModel' => $settingModel,
    );
}

final class Aud003ModuleModel extends ModelExtensionModuleMtUniCredit
{
    /** @var Phase2MemoryDb */
    public $db;

    /** @var Phase1ConfigFake */
    public $config;

    /** @var Phase1LoadFake */
    public $load;

    /** @var Aud003EditableSettingModel */
    public $model_setting_setting;

    /** @var MtUniCreditCredentialsRepository */
    private $credentialsRepo;

    /** @var MtUniCreditCredentialChangeHandler */
    private $credentialChangeHandler;

    /** @var MtUniCreditCpTokenRepository */
    private $tokenRepo;

    /** @var MtUniCreditSettingStore */
    private $settingStore;

    /** @var MtUniCreditSettingCipher */
    private $cipher;

    public function __construct(
        Phase2MemoryDb $memoryDb,
        Aud003EditableSettingModel $settingModel,
        Phase1ConfigFake $config,
        MtUniCreditDbAdapter $dbAdapter,
        MtUniCreditCredentialsRepository $credentials,
        MtUniCreditCredentialChangeHandler $credentialChange,
        MtUniCreditCpTokenRepository $tokens,
        MtUniCreditSettingStore $settings,
        MtUniCreditSettingCipher $cipher
    ) {
        $this->db = $memoryDb;
        $this->config = $config;
        $this->load = new Phase1LoadFake();
        $this->model_setting_setting = $settingModel;
        $this->credentialsRepo = $credentials;
        $this->credentialChangeHandler = $credentialChange;
        $this->tokenRepo = $tokens;
        $this->settingStore = $settings;
        $this->cipher = $cipher;
    }

    /**
     * @return array<string, mixed>
     */
    public function createCpServices()
    {
        return array(
            'credentials' => $this->credentialsRepo,
            'credentialChange' => $this->credentialChangeHandler,
            'tokens' => $this->tokenRepo,
            'settings' => $this->settingStore,
            'cipher' => $this->cipher,
        );
    }
}

/**
 * @param array<string, mixed> $h
 * @param int $storeId
 * @param string $unicid
 * @param string $secret
 * @param string $token
 * @return void
 */
function mtucF00301_seedIdentity(array $h, $storeId, $unicid, $secret, $token)
{
    $h['settings']->set($storeId, MtUniCreditConstants::MODULE_SETTING_UNICID, $unicid);
    $h['credentials']->saveSecret($storeId, $secret);
    $h['tokens']->save($token, 'Bearer', time() + 3600);
    $h['cache']->replaceValidated($storeId, $unicid, array(
        'unicid' => $unicid,
        'coeff_list' => array(),
    ));
}

/**
 * @param array<string, mixed> $base
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function mtucF00301_post(array $base, array $overrides = array())
{
    return array_merge($base, $overrides);
}

$baseFlags = array(
    MtUniCreditConstants::MODULE_SETTING_STATUS => '1',
    MtUniCreditConstants::MODULE_SETTING_ADVERTISING => '0',
    MtUniCreditConstants::MODULE_SETTING_DEBUG => '0',
    MtUniCreditConstants::MODULE_SETTING_PRODUCT_BUTTON_ACTION => MtUniCreditConstants::BUTTON_ACTION_ADD_TO_CART,
    MtUniCreditConstants::MODULE_SETTING_BUTTON_TOP_SPACING => '0',
);

// ---------------------------------------------------------------------------
// Happy path — Secret + UNICID change clears old token
// ---------------------------------------------------------------------------
$hOk = mtucF00301_harness(0);
mtucF00301_seedIdentity($hOk, 0, 'UNICID-A', 'secret-A-value', 'token-TA-unexpired');
mtucF00301_assert($hOk['tokens']->getAccessToken() === 'token-TA-unexpired', 'happy: seeded token readable');
$hOk['model']->saveSettings(mtucF00301_post($baseFlags, array(
    MtUniCreditConstants::MODULE_SETTING_UNICID => 'UNICID-B',
    MtUniCreditConstants::MODULE_SETTING_SECRET => 'secret-B-value',
)));
mtucF00301_assert($hOk['credentials']->getUnicid(0) === 'UNICID-B', 'happy: new UNICID stored');
mtucF00301_assert($hOk['credentials']->getSecret(0) === 'secret-B-value', 'happy: new Secret stored');
mtucF00301_assert($hOk['tokens']->getAccessToken() === null, 'happy: old token removed');
mtucF00301_assert($hOk['tokens']->hasToken() === false, 'happy: hasToken false');
mtucF00301_assert(
    $hOk['settings']->get(0, MtUniCreditCpTokenRepository::TOKEN_TYPE) === null
        && $hOk['settings']->get(0, MtUniCreditCpTokenRepository::EXPIRES_AT) === null,
    'happy: token metadata removed'
);
mtucF00301_assert(
    $hOk['cache']->findLatest(0, 'UNICID-A') === null,
    'happy: old UNICID cache invalidated'
);

// ---------------------------------------------------------------------------
// Unchanged credentials — blank Secret + unrelated setting preserves token
// ---------------------------------------------------------------------------
$hBlank = mtucF00301_harness(0);
mtucF00301_seedIdentity($hBlank, 0, 'UNICID-A', 'secret-A-value', 'token-keep');
$envBefore = $hBlank['credentials']->getStoredSecretEnvelope(0);
$hBlank['model']->saveSettings(mtucF00301_post($baseFlags, array(
    MtUniCreditConstants::MODULE_SETTING_UNICID => 'UNICID-A',
    MtUniCreditConstants::MODULE_SETTING_SECRET => '',
    MtUniCreditConstants::MODULE_SETTING_BUTTON_TOP_SPACING => '8',
)));
mtucF00301_assert(
    $hBlank['credentials']->getStoredSecretEnvelope(0) === $envBefore,
    'blank Secret: envelope preserved byte-for-byte'
);
mtucF00301_assert($hBlank['tokens']->getAccessToken() === 'token-keep', 'blank Secret: existing token preserved');
mtucF00301_assert($hBlank['credentials']->getUnicid(0) === 'UNICID-A', 'blank Secret: UNICID unchanged');

// ---------------------------------------------------------------------------
// A. Token invalidation fails before credential persistence
// ---------------------------------------------------------------------------
$memA = new Phase2MemoryDb();
$failA = new Aud003FailingDb($memA);
$failA->failDeleteKeyContains = MtUniCreditCpTokenRepository::ACCESS_TOKEN;
$hA = mtucF00301_harness(0, $failA);
mtucF00301_seedIdentity($hA, 0, 'UNICID-A', 'secret-A-value', 'token-TA');
$envA = $hA['credentials']->getStoredSecretEnvelope(0);
$threwA = false;
try {
    $hA['model']->saveSettings(mtucF00301_post($baseFlags, array(
        MtUniCreditConstants::MODULE_SETTING_UNICID => 'UNICID-B',
        MtUniCreditConstants::MODULE_SETTING_SECRET => 'secret-B-value',
    )));
} catch (Exception $e) {
    $threwA = true;
}
mtucF00301_assert($threwA, 'A: operation fails when token delete fails');
mtucF00301_assert($hA['credentials']->getUnicid(0) === 'UNICID-A', 'A: UNICID not committed');
mtucF00301_assert($hA['credentials']->getStoredSecretEnvelope(0) === $envA, 'A: Secret envelope unchanged');
mtucF00301_assert($hA['tokens']->getAccessToken() === 'token-TA', 'A: old token remains (invalidate aborted)');

// ---------------------------------------------------------------------------
// B. New Secret persistence fails after token invalidated
// ---------------------------------------------------------------------------
$memB = new Phase2MemoryDb();
$failB = new Aud003FailingDb($memB);
$failB->failUpdateKeyContains = MtUniCreditConstants::MODULE_SETTING_SECRET;
$hB = mtucF00301_harness(0, $failB);
mtucF00301_seedIdentity($hB, 0, 'UNICID-A', 'secret-A-value', 'token-TA');
$threwB = false;
try {
    $hB['model']->saveSettings(mtucF00301_post($baseFlags, array(
        MtUniCreditConstants::MODULE_SETTING_UNICID => 'UNICID-A',
        MtUniCreditConstants::MODULE_SETTING_SECRET => 'secret-B-value',
    )));
} catch (Exception $e) {
    $threwB = true;
}
mtucF00301_assert($threwB, 'B: Secret update failure surfaces');
mtucF00301_assert($hB['tokens']->getAccessToken() === null, 'B: token already invalidated (fail-closed)');
mtucF00301_assert(
    !($hB['credentials']->getSecret(0) === 'secret-B-value' && $hB['tokens']->getAccessToken() === 'token-TA'),
    'B: no new-Secret + old-token mixed authenticated state'
);
// Secret write failed — old envelope may remain after editSetting preserved it.
mtucF00301_assert($hB['credentials']->getSecret(0) === 'secret-A-value', 'B: old Secret remains when replace fails');

// ---------------------------------------------------------------------------
// C. UNICID / settings persistence fails after token invalidate
// ---------------------------------------------------------------------------
$memC = new Phase2MemoryDb();
$failC = new Aud003FailingDb($memC);
$hC = mtucF00301_harness(0, $failC);
mtucF00301_seedIdentity($hC, 0, 'UNICID-A', 'secret-A-value', 'token-TA');
$hC['settingModel']->failEdit = true;
$threwC = false;
try {
    $hC['model']->saveSettings(mtucF00301_post($baseFlags, array(
        MtUniCreditConstants::MODULE_SETTING_UNICID => 'UNICID-B',
        MtUniCreditConstants::MODULE_SETTING_SECRET => '',
    )));
} catch (Exception $e) {
    $threwC = true;
}
mtucF00301_assert($threwC, 'C: UNICID/settings persistence failure surfaces');
mtucF00301_assert($hC['tokens']->getAccessToken() === null, 'C: old token unavailable after pre-write invalidate');
$unicidC = $hC['credentials']->getUnicid(0);
$tokenC = $hC['tokens']->getAccessToken();
mtucF00301_assert($unicidC === 'UNICID-A', 'C: UNICID remains previous identity');
mtucF00301_assert(
    !($unicidC === 'UNICID-B' && $tokenC === 'token-TA'),
    'C: no new-UNICID + old-token combination'
);

// ---------------------------------------------------------------------------
// D. Token metadata deletion partially fails (access token deleted, type fails)
// ---------------------------------------------------------------------------
$memD = new Phase2MemoryDb();
$failD = new Aud003FailingDb($memD);
$failD->failDeleteKeyContains = MtUniCreditCpTokenRepository::TOKEN_TYPE;
$failD->deleteFailAfterSuccesses = 0; // fail immediately on TOKEN_TYPE delete
// But ACCESS_TOKEN delete happens first and does not match TOKEN_TYPE filter — succeeds.
$hD = mtucF00301_harness(0, $failD);
mtucF00301_seedIdentity($hD, 0, 'UNICID-A', 'secret-A-value', 'token-TA');
$threwD = false;
try {
    $hD['model']->saveSettings(mtucF00301_post($baseFlags, array(
        MtUniCreditConstants::MODULE_SETTING_UNICID => 'UNICID-B',
        MtUniCreditConstants::MODULE_SETTING_SECRET => 'secret-B-value',
    )));
} catch (Exception $e) {
    $threwD = true;
}
mtucF00301_assert($threwD, 'D: partial token metadata delete fails operation');
mtucF00301_assert($hD['tokens']->getAccessToken() === null, 'D: access token already gone — not usable');
mtucF00301_assert($hD['credentials']->getUnicid(0) === 'UNICID-A', 'D: credential identity not advanced');
mtucF00301_assert($hD['tokens']->hasToken() === false, 'D: hasToken false despite leftover metadata');

// ---------------------------------------------------------------------------
// E. Cache invalidation fails after credentials written — stale cache independent
// ---------------------------------------------------------------------------
$memE = new Phase2MemoryDb();
$failE = new Aud003FailingDb($memE);
$hE = mtucF00301_harness(0, $failE);
mtucF00301_seedIdentity($hE, 0, 'UNICID-A', 'secret-A-value', 'token-TA');
$failE->failDeleteTableContains = 'shop_cache';
$threwE = false;
try {
    $hE['model']->saveSettings(mtucF00301_post($baseFlags, array(
        MtUniCreditConstants::MODULE_SETTING_UNICID => 'UNICID-B',
        MtUniCreditConstants::MODULE_SETTING_SECRET => 'secret-B-value',
    )));
} catch (Exception $e) {
    $threwE = true;
}
mtucF00301_assert($threwE, 'E: cache delete failure surfaces after credential write');
mtucF00301_assert($hE['credentials']->getUnicid(0) === 'UNICID-B', 'E: new UNICID already durable');
mtucF00301_assert($hE['credentials']->getSecret(0) === 'secret-B-value', 'E: new Secret already durable');
mtucF00301_assert($hE['tokens']->getAccessToken() === null, 'E: token cleared — no stale authenticated state');

// ---------------------------------------------------------------------------
// Explicit stale-token regression: never ensureToken-equivalent readable T(A) under B
// ---------------------------------------------------------------------------
$memS = new Phase2MemoryDb();
$failS = new Aud003FailingDb($memS);
$failS->failUpdateKeyContains = MtUniCreditConstants::MODULE_SETTING_SECRET;
$hS = mtucF00301_harness(0, $failS);
mtucF00301_seedIdentity($hS, 0, 'UNICID-A', 'secret-A-value', 'token-TA');
try {
    $hS['model']->saveSettings(mtucF00301_post($baseFlags, array(
        MtUniCreditConstants::MODULE_SETTING_UNICID => 'UNICID-B',
        MtUniCreditConstants::MODULE_SETTING_SECRET => 'secret-B-value',
    )));
} catch (Exception $e) {
    // expected
}
$credsAreB = (
    $hS['credentials']->getUnicid(0) === 'UNICID-B'
    || $hS['credentials']->getSecret(0) === 'secret-B-value'
);
$tokenIsTA = ($hS['tokens']->getAccessToken() === 'token-TA');
mtucF00301_assert(
    !($credsAreB && $tokenIsTA),
    'stale-token: never current identity B with readable token T(A)'
);

// ---------------------------------------------------------------------------
// Store isolation — store 1 failure does not touch store 0
// ---------------------------------------------------------------------------
$memI = new Phase2MemoryDb();
$failI = new Aud003FailingDb($memI);
// Shared memory; separate harnesses per store.
$h0 = mtucF00301_harness(0, $failI);
mtucF00301_seedIdentity($h0, 0, 'UNICID-S0', 'secret-S0', 'token-S0');
$env0 = $h0['credentials']->getStoredSecretEnvelope(0);
$token0 = $h0['tokens']->getAccessToken();

$h1 = mtucF00301_harness(1, $failI);
mtucF00301_seedIdentity($h1, 1, 'UNICID-S1', 'secret-S1', 'token-S1');
$failI->failUpdateKeyContains = MtUniCreditConstants::MODULE_SETTING_SECRET;
try {
    $h1['model']->saveSettings(mtucF00301_post($baseFlags, array(
        MtUniCreditConstants::MODULE_SETTING_UNICID => 'UNICID-S1-NEW',
        MtUniCreditConstants::MODULE_SETTING_SECRET => 'secret-S1-NEW',
    )));
} catch (Exception $e) {
    // expected
}
mtucF00301_assert($h0['credentials']->getUnicid(0) === 'UNICID-S0', 'isolation: store0 UNICID untouched');
mtucF00301_assert($h0['credentials']->getStoredSecretEnvelope(0) === $env0, 'isolation: store0 Secret envelope untouched');
mtucF00301_assert($h0['tokens']->getAccessToken() === $token0, 'isolation: store0 token untouched');

// ---------------------------------------------------------------------------
// ControlPanelClient: absent token → login path (no unsafe use of old token)
// ---------------------------------------------------------------------------
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$clientServices = Phase4TestHarness::services($transport);
/** @var MtUniCreditControlPanelClient $client */
$client = $clientServices['client'];
$clientServices['tokens']->invalidate();
mtucF00301_assert($clientServices['tokens']->getAccessToken() === null, 'CP client: token absent before request');
$ref = new ReflectionClass($client);
$method = $ref->getMethod('ensureToken');
$method->setAccessible(true);
$got = $method->invoke($client);
mtucF00301_assert(is_string($got) && $got !== '', 'CP client: ensureToken logs in when token absent');
mtucF00301_assert(count($transport->requests) >= 1, 'CP client: login request recorded on fake transport');
mtucF00301_assert($clientServices['tokens']->getAccessToken() === $got, 'CP client: stored token matches ensureToken result');

echo PHP_EOL;
if ($failures) {
    echo 'AUD-003 F-003-01: FAIL (' . count($failures) . ' failures, ' . $passes . ' passes)' . PHP_EOL;
    foreach ($failures as $f) {
        echo '  - ' . $f . PHP_EOL;
    }
    exit(1);
}

echo 'AUD-003 F-003-01: PASS (' . $passes . ' passes)' . PHP_EOL;
exit(0);
