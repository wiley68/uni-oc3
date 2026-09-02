<?php

/**
 * Phase 4 outbound CP client and admin configuration checks.
 * Run: php tests/phase4_check.php
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/support/phase2_memory_db.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
}

require_once $lib . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . '/support/phase4_harness.php';

$failures = array();
$passes = 0;

function mtuc4_assert(bool $condition, string $message): void
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

$requiredFiles = array(
    'extension_root.php',
    'cp_http_constants.php',
    'cp_exception.php',
    'cp_http_response.php',
    'cp_http_transport.php',
    'curl_cp_http_transport.php',
    'cp_token_repository.php',
    'canonical_shop_url_provider.php',
    'deployment_environment.php',
    'control_panel_client.php',
    'shop_configuration_service.php',
    'credential_change_handler.php',
    'cp_service_factory.php',
);

foreach ($requiredFiles as $relative) {
    mtuc4_assert(is_file($lib . DIRECTORY_SEPARATOR . $relative), 'required file: ' . $relative);
}

$envConfigPath = $lib . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'environment.php';
$oldEnvConfigPath = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'environment.php';
mtuc4_assert(is_file($envConfigPath), 'extension-owned config/environment.php present');
mtuc4_assert(!is_file($oldEnvConfigPath), 'old upload/config/environment.php absent');

$authContract = mtuc_phase0_load_fixture('cp_auth_contract.json');
$apiEndpoints = mtuc_phase0_load_fixture('cp_api_endpoints.json');
mtuc4_assert($authContract['api_base_path'] === '/api/v1', 'cp_auth_contract api_base_path');
mtuc4_assert($apiEndpoints['base'] === '/api/v1', 'cp_api_endpoints base path');

mtuc4_assert(MtUniCreditCpHttpConstants::CONNECT_TIMEOUT_SECONDS === 5, 'connect timeout 5s');
mtuc4_assert(MtUniCreditCpHttpConstants::TOTAL_TIMEOUT_SECONDS === 15, 'total timeout 15s');
mtuc4_assert(MtUniCreditCpHttpConstants::REFRESH_MARGIN_SECONDS === 60, 'refresh margin 60s');

$curlSource = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'curl_cp_http_transport.php');
mtuc4_assert(strpos($curlSource, 'CURLOPT_SSL_VERIFYPEER') !== false, 'curl transport enables TLS verify peer');
mtuc4_assert(strpos($curlSource, 'CURLOPT_SSL_VERIFYHOST') !== false, 'curl transport enables TLS verify host');
mtuc4_assert(strpos($curlSource, 'CURLOPT_FOLLOWLOCATION') !== false && strpos($curlSource, 'false') !== false, 'curl transport disables redirects');
mtuc4_assert(strpos($curlSource, 'CURLOPT_SSL_VERIFYPEER, false') === false, 'curl transport never disables TLS verify');

$deploymentSource = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'deployment_environment.php');
mtuc4_assert(strpos($deploymentSource, 'upload/config/environment.php') === false, 'loader has no old root config path fallback');
mtuc4_assert(strpos($deploymentSource, 'MtUniCreditExtensionRoot::path()') !== false, 'loader resolves via extension root');

$defaultDeployment = new MtUniCreditDeploymentEnvironment();
$expectedDefaultPath = MtUniCreditExtensionRoot::path() . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'environment.php';
mtuc4_assert($defaultDeployment->configFilePath() === $expectedDefaultPath, 'DeploymentEnvironment resolves extension-owned path');
mtuc4_assert(strpos($defaultDeployment->controlPanelApiBaseUrl(), '/api/v1') !== false, 'deployment environment appends /api/v1');
mtuc4_assert(strpos($defaultDeployment->controlPanelApiBaseUrl(), 'uni.avalonbg.com') !== false, 'CP host semantics unchanged');

$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, array('success' => true, 'data' => array('ok' => true)));
$response = $transport->request('GET', 'https://cp-test.example.com/api/v1/shop', array('Accept' => 'application/json'), null);
mtuc4_assert($response->getStatusCode() === 200, 'fake transport returns queued status');

$http4xx = new MtUniCreditCpHttpException(403, array('error' => 'forbidden'));
$http5xx = new MtUniCreditCpHttpException(503, array());
mtuc4_assert($http4xx->isPermanentAuthOrConfiguration() && !$http4xx->isTransient(), 'HTTP 4xx permanent not transient');
mtuc4_assert($http5xx->isTransient() && !$http5xx->isPermanentAuthOrConfiguration(), 'HTTP 5xx transient');

mtuc4_assert((new MtUniCreditCpConnectionException('x'))->isTransient(), 'connection exception transient');
mtuc4_assert((new MtUniCreditCpTimeoutException('x'))->isTransient(), 'timeout exception transient');

// Credentials
$memoryDb = Phase4TestHarness::memoryDb();
$db = new MtUniCreditDbAdapter($memoryDb, 'oc_');
$settings = new MtUniCreditSettingStore($db, MtUniCreditConstants::MODULE_SETTINGS_CODE);
$credentials = new MtUniCreditCredentialsRepository($settings, Phase4TestHarness::cipher());
$storeId = Phase4TestHarness::TEST_STORE_ID;

mtuc4_assert($credentials->getUnicid($storeId) === '', 'missing UNICID returns empty');
mtuc4_assert($credentials->hasSecret($storeId) === false, 'missing Secret not configured');

Phase4TestHarness::prepareCredentials($settings, $storeId);
$settings->set($storeId, MtUniCreditConstants::MODULE_SETTING_SECRET, 'enc:v1:corrupt');
mtuc4_assert($credentials->hasSecret($storeId) === false, 'corrupt encrypted Secret fails closed');
mtuc4_assert($credentials->isSecretReadable($storeId) === false, 'corrupt encrypted Secret not readable');

// Auth lifecycle
$memoryDb->reset();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$stack = Phase4TestHarness::services($transport, $memoryDb);
$stack['client']->login();
mtuc4_assert($stack['tokens']->hasToken(), 'successful login persists token');
$storedToken = $settings->get($storeId, MtUniCreditCpTokenRepository::ACCESS_TOKEN);
mtuc4_assert(is_string($storedToken) && MtUniCreditSettingCipher::hasEncryptedPrefix($storedToken), 'token stored encrypted');

$memoryDb->reset();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(401, array('error' => 'invalid'));
$stack = Phase4TestHarness::services($transport, $memoryDb);
try {
    $stack['client']->login();
    mtuc4_assert(false, 'failed login throws');
} catch (MtUniCreditCpAuthenticationException $exception) {
    mtuc4_assert(true, 'failed login throws authentication exception');
}
mtuc4_assert($stack['tokens']->hasToken() === false, 'failed login does not persist token');

$memoryDb->reset();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, array('success' => true, 'access_token' => '', 'token_type' => 'Bearer', 'expires_in' => 86400, 'shop' => array()));
$stack = Phase4TestHarness::services($transport, $memoryDb);
try {
    $stack['client']->login();
    mtuc4_assert(false, 'malformed auth response throws');
} catch (MtUniCreditCpInvalidPayloadException $exception) {
    mtuc4_assert(true, 'malformed auth response throws invalid payload');
}
mtuc4_assert($stack['tokens']->hasToken() === false, 'malformed auth response invalidates token');

$memoryDb->reset();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport->enqueueJson(200, array(
    'success' => true,
    'access_token' => str_repeat('b', 64),
    'token_type' => 'Bearer',
    'expires_in' => 86400,
    'shop' => array('id' => 1, 'name' => Phase4TestHarness::TEST_SHOP_URL, 'unicid' => Phase4TestHarness::TEST_UNICID),
));
$stack = Phase4TestHarness::services($transport, $memoryDb, $storeId, 1700000000);
$stack['client']->login();
$stack['client']->refreshToken();
mtuc4_assert($stack['tokens']->getAccessToken() === str_repeat('b', 64), 'refresh rotates token');

$memoryDb->reset();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport->enqueueJson(401, array('error' => 'expired'));
$stack = Phase4TestHarness::services($transport, $memoryDb);
$stack['client']->login();
try {
    $stack['client']->refreshToken();
    mtuc4_assert(false, 'refresh failure throws');
} catch (MtUniCreditCpAuthenticationException $exception) {
    mtuc4_assert(true, 'refresh failure throws');
}
mtuc4_assert($stack['tokens']->hasToken() === false, 'refresh failure clears token');

$memoryDb->reset();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport->enqueueJson(200, array('success' => true));
$stack = Phase4TestHarness::services($transport, $memoryDb);
$stack['client']->login();
$stack['client']->logout();
mtuc4_assert($stack['tokens']->hasToken() === false, 'logout invalidates local token');

$memoryDb->reset();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport->enqueueJson(401, array('error' => 'expired'));
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport->enqueueJson(401, array('error' => 'expired again'));
$stack = Phase4TestHarness::services($transport, $memoryDb, $storeId, 1700000000 + 86400 + 120);
try {
    $stack['client']->getShop();
    mtuc4_assert(false, 'second 401 fails');
} catch (MtUniCreditCpAuthenticationException $exception) {
    mtuc4_assert(true, 'second 401 fails');
}
mtuc4_assert($stack['tokens']->hasToken() === false, 'second 401 clears token');
mtuc4_assert(count($transport->requests) === 4, '401 retry exactly once then fail (4 requests)');

$memoryDb->reset();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport->enqueueJson(401, array('error' => 'expired'));
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());
$stack = Phase4TestHarness::services($transport, $memoryDb, $storeId, 1700000000 + 86400 + 120);
$response = $stack['client']->getShop();
mtuc4_assert(isset($response['data']), '401 retry succeeds after reauth');
mtuc4_assert(count($transport->requests) === 4, '401 retry success uses 4 requests');

// HTTP classification
$memoryDb->reset();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueue(200, '{not-json');
$stack = Phase4TestHarness::services($transport, $memoryDb);
try {
    $stack['client']->login();
    mtuc4_assert(false, 'malformed JSON throws');
} catch (MtUniCreditCpMalformedJsonException $exception) {
    mtuc4_assert(true, 'malformed JSON classified');
}

$memoryDb->reset();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueTimeout();
$stack = Phase4TestHarness::services($transport, $memoryDb);
try {
    $stack['client']->login();
    mtuc4_assert(false, 'timeout throws');
} catch (MtUniCreditCpTimeoutException $exception) {
    mtuc4_assert(true, 'timeout classified');
}

$memoryDb->reset();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueConnectionFailure();
$stack = Phase4TestHarness::services($transport, $memoryDb);
try {
    $stack['client']->login();
    mtuc4_assert(false, 'connection failure throws');
} catch (MtUniCreditCpConnectionException $exception) {
    mtuc4_assert(true, 'connection failure classified');
}

// Shop refresh
$memoryDb->reset();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());
$stack = Phase4TestHarness::services($transport, $memoryDb);
$data = $stack['shopConfiguration']->refreshRemote();
mtuc4_assert($data['unicid'] === Phase4TestHarness::TEST_UNICID, 'valid shop response cached');
$meta = $stack['shopConfiguration']->getMetadata();
mtuc4_assert($meta !== null && !empty($meta['is_fresh']), 'cache metadata fresh after refresh');

$memoryDb->reset();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());
$stack = Phase4TestHarness::services($transport, $memoryDb);
$stack['shopConfiguration']->refreshRemote();
$bad = mtuc4_valid_shop_snapshot();
unset($bad['kop']);
$transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload($bad));
try {
    $stack['shopConfiguration']->refreshRemote();
    mtuc4_assert(false, 'invalid snapshot rejected');
} catch (MtUniCreditShopSnapshotValidationException $exception) {
    mtuc4_assert(true, 'invalid snapshot rejected');
}
mtuc4_assert($stack['shopConfiguration']->getMetadata() !== null, 'invalid snapshot preserves old cache');

$memoryDb->reset();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());
$stack = Phase4TestHarness::services($transport, $memoryDb);
$stack['shopConfiguration']->refreshRemote();
$transport->enqueueJson(503, array('error' => 'down'));
try {
    $stack['shopConfiguration']->refreshRemote();
    mtuc4_assert(false, 'HTTP failure on refresh throws');
} catch (MtUniCreditCpHttpException $exception) {
    mtuc4_assert($exception->isTransient(), 'transient HTTP failure');
}
mtuc4_assert($stack['shopConfiguration']->getMetadata() !== null, 'HTTP failure preserves cache');
mtuc4_assert($stack['tokens']->hasToken(), 'transient HTTP failure preserves token');

$memoryDb->reset();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());
$stack = Phase4TestHarness::services($transport, $memoryDb);
$stack['shopConfiguration']->refreshRemote();
$transport->enqueueJson(403, array('error' => 'forbidden'));
try {
    $stack['shopConfiguration']->refreshRemote();
    mtuc4_assert(false, 'auth failure on refresh throws');
} catch (MtUniCreditCpHttpException $exception) {
    mtuc4_assert(true, 'permanent HTTP failure on refresh');
}
mtuc4_assert($stack['shopConfiguration']->getMetadata() === null, 'auth failure purges cache');
mtuc4_assert($stack['tokens']->hasToken() === false, 'auth failure purges token');

// Store scope
$memoryDb->reset();
$transportA = new Phase4FakeCpHttpTransport();
$transportA->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transportA->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());
$stackA = Phase4TestHarness::services($transportA, $memoryDb, Phase4TestHarness::TEST_STORE_ID);
$stackA['shopConfiguration']->refreshRemote();

$settingsB = new MtUniCreditSettingStore($db, MtUniCreditConstants::MODULE_SETTINGS_CODE);
Phase4TestHarness::prepareCredentials($settingsB, Phase4TestHarness::TEST_STORE_ID_B);
$cacheRepo = new MtUniCreditShopCacheRepository($db);
mtuc4_assert($cacheRepo->findLatest(Phase4TestHarness::TEST_STORE_ID_B, Phase4TestHarness::TEST_UNICID) === null, 'store B has no cache from store A refresh');

$transportB = new Phase4FakeCpHttpTransport();
$transportB->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transportB->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());
$stackB = Phase4TestHarness::services($transportB, $memoryDb, Phase4TestHarness::TEST_STORE_ID_B);
$stackB['shopConfiguration']->refreshRemote();
mtuc4_assert($cacheRepo->findLatest(Phase4TestHarness::TEST_STORE_ID, Phase4TestHarness::TEST_UNICID) !== null, 'store A cache unchanged by store B');
mtuc4_assert($cacheRepo->findLatest(Phase4TestHarness::TEST_STORE_ID_B, Phase4TestHarness::TEST_UNICID) !== null, 'store B cache isolated');

// Credential change invalidates token + cache
$memoryDb->reset();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());
$stack = Phase4TestHarness::services($transport, $memoryDb);
$stack['shopConfiguration']->refreshRemote();
mtuc4_assert($stack['tokens']->hasToken(), 'token present before credential change');
$stack['credentialChange']->onCredentialsChanged(Phase4TestHarness::TEST_UNICID, 'new-unicid-after-change');
mtuc4_assert($stack['tokens']->hasToken() === false, 'credential change invalidates token');
mtuc4_assert($cacheRepo->findLatest($storeId, Phase4TestHarness::TEST_UNICID) === null, 'credential change clears scoped cache');

// Admin route contract (static source inspection)
$controllerPath = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'module' . DIRECTORY_SEPARATOR . 'mt_uni_credit.php';
$modelPath = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'model' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'module' . DIRECTORY_SEPARATOR . 'mt_uni_credit.php';
$controllerSource = (string) file_get_contents($controllerPath);
$modelSource = (string) file_get_contents($modelPath);

mtuc4_assert(strpos($controllerSource, 'function refreshBankData') !== false, 'admin controller refreshBankData exists');
mtuc4_assert(strpos($controllerSource, "REQUEST_METHOD'] ?? '') !== 'POST'") !== false, 'refreshBankData POST-only');
mtuc4_assert(strpos($controllerSource, "hasPermission('modify'") !== false, 'refreshBankData requires modify permission');
mtuc4_assert(strpos($controllerSource, 'text_bank_data_phase1_unavailable') === false, 'placeholder message removed from controller');
mtuc4_assert(strpos($controllerSource, 'text_bank_data_refreshed') !== false, 'success flash mapping present');
mtuc4_assert(strpos($controllerSource, 'error_bank_') !== false, 'error flash mapping present');

mtuc4_assert(strpos($modelSource, 'function refreshBankData') !== false, 'admin model refreshBankData exists');
mtuc4_assert(strpos($modelSource, 'shop_snapshot_invalid') !== false, 'admin model maps invalid snapshot');
mtuc4_assert(strpos($modelSource, 'config_store_id') !== false, 'admin model uses config_store_id');

$bgLang = (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'language' . DIRECTORY_SEPARATOR . 'bg-bg' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'module' . DIRECTORY_SEPARATOR . 'mt_uni_credit.php');
mtuc4_assert(strpos($bgLang, 'error_bank_authentication_failed') !== false, 'BG bank error messages present');

// Login payload contract
$memoryDb->reset();
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
$stack = Phase4TestHarness::services($transport, $memoryDb);
$stack['client']->login();
$loginRequest = $transport->requests[0];
mtuc4_assert(isset($loginRequest['payload']['unicid']), 'login payload includes unicid');
mtuc4_assert(isset($loginRequest['payload']['name']), 'login payload includes name');
mtuc4_assert(isset($loginRequest['payload']['secret']), 'login payload includes secret');
mtuc4_assert(strpos($loginRequest['url'], '/auth/login') !== false, 'login endpoint path');
mtuc4_assert(strpos($loginRequest['url'], 'cp-test.example.com/api/v1') !== false, 'login uses frozen test CP host');
$storedSecret = $settings->get($storeId, MtUniCreditConstants::MODULE_SETTING_SECRET);
mtuc4_assert(strpos((string) $storedSecret, Phase4TestHarness::TEST_SECRET) === false, 'secret not persisted plaintext after login');

$packageScript = (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'package.ps1');
mtuc4_assert(strpos($packageScript, 'upload/system/library/mt_uni_credit/config/environment.php') !== false, 'package script expects extension-owned environment.php');
mtuc4_assert(strpos($packageScript, 'forbiddenEntries') !== false, 'package script forbids old config path');
mtuc4_assert(strpos($packageScript, "'upload/config/environment.php'") !== false, 'package script lists forbidden upload/config/environment.php');

$packagePath = $root . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'CC_OpenCartv.3.x_UNI_v.2.0.2.ocmod.zip';
if (is_file($packagePath)) {
    $zip = new ZipArchive();
    mtuc4_assert($zip->open($packagePath) === true, 'distributable package opens');
    if ($zip->status === ZipArchive::ER_OK || $zip->numFiles > 0) {
        mtuc4_assert($zip->locateName('upload/system/library/mt_uni_credit/config/environment.php') !== false, 'package contains extension-owned environment.php');
        mtuc4_assert($zip->locateName('upload/config/environment.php') === false, 'package lacks old upload/config/environment.php');
        $zip->close();
    }
}

echo PHP_EOL . 'Phase 4 summary: ' . $passes . ' passed, ' . count($failures) . ' failed' . PHP_EOL;

if ($failures) {
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'PHASE 4 STOP GATE: PASS — LOCAL' . PHP_EOL;
exit(0);
