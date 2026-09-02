<?php

/**
 * Phase 2 persistence/security checks. Run: php tests/phase2_check.php
 *
 * PHP 7.3 compatible. No PHPUnit. No live network.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/support/phase2_memory_db.php';

$failures = array();
$passes = 0;

function mtuc2_assert(bool $condition, string $message): void
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
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

$requiredFiles = array(
    'security_constants.php',
    'persistence_table_names.php',
    'persistence_exceptions.php',
    'store_scope.php',
    'persistence_clock.php',
    'hash_validator.php',
    'db_adapter.php',
    'encryption_key_provider.php',
    'setting_cipher.php',
    'setting_store.php',
    'credentials_repository.php',
    'persistence_schema.php',
    'api_nonce_repository.php',
    'operation_entry_point.php',
    'lock_owner_token.php',
    'operation_lock_repository.php',
    'request_signature_protocol.php',
    'request_signature_verifier.php',
    'deployment_paths.php',
);

foreach ($requiredFiles as $file) {
    mtuc2_assert(is_file($lib . DIRECTORY_SEPARATOR . $file), 'required library file: ' . $file);
}

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase2-local-test-db-password-secret');
}

require_once $lib . DIRECTORY_SEPARATOR . 'bootstrap.php';

$hkdfFixture = mtuc_phase0_load_fixture('crypto_hkdf_vector.json');
$hmacFixture = mtuc_phase0_load_fixture('hmac_callback_vector.json');
$provider = new MtUniCreditEncryptionKeyProvider();
$derivedKey = $provider->resolveDerivedKey($hkdfFixture['secret_input']);
mtuc2_assert(
    bin2hex($derivedKey) === $hkdfFixture['expected_derived_key_hex'],
    'HKDF derived key matches OC4 fixture'
);
mtuc2_assert(strlen($derivedKey) === 32, 'HKDF key length is 32 bytes');

$cipher = new MtUniCreditSettingCipher($derivedKey);
$plaintext = 'test-shared-secret-value';
$encrypted = $cipher->encrypt($plaintext);
$encryptedAgain = $cipher->encrypt($plaintext);
mtuc2_assert(strncmp($encrypted, MtUniCreditSettingCipher::encryptedPrefix(), 7) === 0, 'encrypted envelope uses enc:v1: prefix');
mtuc2_assert($cipher->decrypt($encrypted) === $plaintext, 'encrypt/decrypt roundtrip');
mtuc2_assert($encrypted !== $encryptedAgain, 'same plaintext yields different ciphertext (random IV)');
mtuc2_assert(strpos($encrypted, $plaintext) === false, 'ciphertext does not contain plaintext');

$wrongCipher = new MtUniCreditSettingCipher($provider->resolveDerivedKey('different-db-password'));
try {
    $wrongCipher->decrypt($encrypted);
    mtuc2_assert(false, 'wrong DB password fails closed');
} catch (RuntimeException $exception) {
    mtuc2_assert(true, 'wrong DB password fails closed');
}

$payload = substr($encrypted, strlen(MtUniCreditSettingCipher::encryptedPrefix()));
$raw = base64_decode($payload, true);
mtuc2_assert(is_string($raw) && $raw !== '', 'encrypted payload decodes');
$raw[0] = ($raw[0] === 'A') ? 'B' : 'A';
$tamperedCipher = MtUniCreditSettingCipher::encryptedPrefix() . base64_encode($raw);
try {
    $cipher->decrypt($tamperedCipher);
    mtuc2_assert(false, 'modified ciphertext fails authentication');
} catch (RuntimeException $exception) {
    mtuc2_assert(true, 'modified ciphertext fails authentication');
}

try {
    $cipher->decrypt('not-an-envelope');
    mtuc2_assert(false, 'malformed envelope fails');
} catch (RuntimeException $exception) {
    mtuc2_assert(true, 'malformed envelope fails');
}

$memoryDb = new Phase2MemoryDb();
$db = new MtUniCreditDbAdapter($memoryDb, 'oc_');
$store = new MtUniCreditSettingStore($db, MtUniCreditConstants::MODULE_SETTINGS_CODE);
$credentials = new MtUniCreditCredentialsRepository($store, $cipher);
$storeId = 0;

$credentials->saveSecret($storeId, 'first-secret-value');
$stored = $credentials->getStoredSecretEnvelope($storeId);
mtuc2_assert(is_string($stored) && MtUniCreditSettingCipher::hasEncryptedPrefix($stored), 'first secret save stores encrypted envelope');
mtuc2_assert($credentials->getSecret($storeId) === 'first-secret-value', 'first secret decrypts correctly');
mtuc2_assert(strpos((string) $stored, 'first-secret-value') === false, 'stored secret is not plaintext');

$beforeBlank = $stored;
$credentials->saveSecret($storeId, '');
mtuc2_assert($credentials->getStoredSecretEnvelope($storeId) === $beforeBlank, 'blank submission preserves encrypted secret');

$credentials->saveSecret($storeId, 'replacement-secret');
$replaced = $credentials->getStoredSecretEnvelope($storeId);
mtuc2_assert($replaced !== $beforeBlank, 'replacement secret changes encrypted value');
mtuc2_assert($credentials->getSecret($storeId) === 'replacement-secret', 'replacement secret decrypts');

$store->set($storeId, MtUniCreditConstants::MODULE_SETTING_SECRET, 'enc:v1:corrupt');
mtuc2_assert($credentials->getSecret($storeId) === null, 'corrupt stored secret fails closed');
mtuc2_assert($credentials->hasSecret($storeId) === false, 'corrupt stored secret is not configured');

$vector = $hmacFixture['vector'];
$signature = MtUniCreditRequestSignatureProtocol::computeSignature(
    $vector['secret'],
    $vector['timestamp'],
    $vector['nonce'],
    $vector['raw_body']
);
mtuc2_assert(hash_equals($vector['expected_sha256_hmac'], $signature), 'HMAC known vector passes');

$verifier = new MtUniCreditRequestSignatureVerifier(function () use ($vector) {
    return (int) $vector['timestamp'];
});
$headers = array(
    MtUniCreditRequestSignatureProtocol::HEADER_TIMESTAMP => $vector['timestamp'],
    MtUniCreditRequestSignatureProtocol::HEADER_NONCE => $vector['nonce'],
    MtUniCreditRequestSignatureProtocol::HEADER_SIGNATURE => $signature,
);
try {
    $verifier->verify($vector['secret'], $vector['raw_body'], $headers);
    mtuc2_assert(true, 'HMAC verifier accepts valid vector');
} catch (Exception $exception) {
    mtuc2_assert(false, 'HMAC verifier accepts valid vector');
}

$badHeaders = $headers;
$badHeaders[MtUniCreditRequestSignatureProtocol::HEADER_TIMESTAMP] = '1';
try {
    $verifier->verify($vector['secret'], $vector['raw_body'], $badHeaders);
    mtuc2_assert(false, 'HMAC rejects stale timestamp');
} catch (Exception $exception) {
    mtuc2_assert(true, 'HMAC rejects stale timestamp');
}

$badHeaders = $headers;
$badHeaders[MtUniCreditRequestSignatureProtocol::HEADER_NONCE] = str_repeat('f', 63);
try {
    $verifier->verify($vector['secret'], $vector['raw_body'], $badHeaders);
    mtuc2_assert(false, 'HMAC rejects invalid nonce');
} catch (Exception $exception) {
    mtuc2_assert(true, 'HMAC rejects invalid nonce');
}

$badHeaders = $headers;
$badHeaders[MtUniCreditRequestSignatureProtocol::HEADER_SIGNATURE] = str_repeat('0', 64);
try {
    $verifier->verify($vector['secret'], $vector['raw_body'], $badHeaders);
    mtuc2_assert(false, 'HMAC rejects modified signature');
} catch (Exception $exception) {
    mtuc2_assert(true, 'HMAC rejects modified signature');
}

try {
    $verifier->verify($vector['secret'], $vector['raw_body'] . ' ', $headers);
    mtuc2_assert(false, 'HMAC rejects modified body');
} catch (Exception $exception) {
    mtuc2_assert(true, 'HMAC rejects modified body');
}

$memoryDb->reset();
$clock = new MtUniCreditPersistenceClock(function () {
    return 1700000000;
});
$nonces = new MtUniCreditApiNonceRepository($db, $clock);
$nonce = str_repeat('a', 64);
mtuc2_assert($nonces->claim(0, 'SHOP-1', $nonce), 'first nonce accepted');
mtuc2_assert(!$nonces->claim(0, 'SHOP-1', $nonce), 'duplicate nonce rejected');
mtuc2_assert($nonces->claim(1, 'SHOP-1', $nonce), 'same nonce allowed for different store');
mtuc2_assert($nonces->claim(0, 'SHOP-2', $nonce), 'same nonce allowed for different UNICID');

$expiredClock = new MtUniCreditPersistenceClock(function () {
    return 1700000000 + MtUniCreditSecurityConstants::NONCE_RETENTION_SECONDS + 10;
});
$expiredRepo = new MtUniCreditApiNonceRepository($db, $expiredClock);
$deleted = $expiredRepo->deleteExpiredBatch(100);
mtuc2_assert($deleted >= 1, 'expired nonce cleanup removes rows');

$memoryDb->reset();
$locks = new MtUniCreditOperationLockRepository($db, $clock);
$operationHash = hash('sha256', 'checkout-session-1');
$tokenA = MtUniCreditLockOwnerTokenGenerator::generate();
$tokenB = MtUniCreditLockOwnerTokenGenerator::generate();
mtuc2_assert(
    $locks->acquire(0, MtUniCreditOperationEntryPoint::CHECKOUT, $operationHash, $tokenA),
    'lock acquire succeeds'
);
mtuc2_assert(
    !$locks->acquire(0, MtUniCreditOperationEntryPoint::CHECKOUT, $operationHash, $tokenB),
    'duplicate lock acquire rejected while active'
);
mtuc2_assert(
    !$locks->release(0, MtUniCreditOperationEntryPoint::CHECKOUT, $operationHash, $tokenB),
    'wrong token cannot release lock'
);
mtuc2_assert(
    $locks->release(0, MtUniCreditOperationEntryPoint::CHECKOUT, $operationHash, $tokenA),
    'correct token releases lock'
);

$memoryDb->reset();
$staleClock = new MtUniCreditPersistenceClock(function () {
    return 1700000000;
});
$staleLocks = new MtUniCreditOperationLockRepository($db, $staleClock);
$staleToken = MtUniCreditLockOwnerTokenGenerator::generate();
mtuc2_assert(
    $staleLocks->acquire(0, MtUniCreditOperationEntryPoint::CART, $operationHash, $staleToken),
    'initial stale-test lock acquired'
);
$recoverClock = new MtUniCreditPersistenceClock(function () {
    return 1700000000 + MtUniCreditSecurityConstants::OPERATION_LOCK_TTL_SECONDS + 5;
});
$recoverLocks = new MtUniCreditOperationLockRepository($db, $recoverClock);
$recoverToken = MtUniCreditLockOwnerTokenGenerator::generate();
mtuc2_assert(
    $recoverLocks->acquire(0, MtUniCreditOperationEntryPoint::CART, $operationHash, $recoverToken),
    'stale lock can be recovered after TTL'
);

$statements = MtUniCreditPersistenceSchema::createTableStatements('oc_');
mtuc2_assert(count($statements) === 2, 'Phase 2 schema creates two tables');
foreach (MtUniCreditPersistenceTableNames::phase2Tables() as $table) {
    mtuc2_assert(strpos(implode("\n", $statements), 'oc_' . $table) !== false, 'schema includes table: ' . $table);
    mtuc2_assert(strpos(implode("\n", $statements), 'CREATE TABLE IF NOT EXISTS') !== false, 'schema uses idempotent CREATE TABLE IF NOT EXISTS');
}
mtuc2_assert(strpos(implode("\n", $statements), 'DROP TABLE') === false, 'schema has no destructive DROP');

$moduleModelPath = $root . DIRECTORY_SEPARATOR . 'upload/admin/model/extension/module/mt_uni_credit.php';
$moduleModel = file_get_contents($moduleModelPath);
mtuc2_assert(strpos($moduleModel, 'MtUniCreditCredentialsRepository') !== false || strpos($moduleModel, 'credentialsRepositoryFromModel') !== false, 'module model uses encrypted credentials repository');
mtuc2_assert(strpos($moduleModel, 'secret_phase2_required') === false, 'module model no longer blocks secret save');
mtuc2_assert(strpos($moduleModel, 'installPersistenceSchema') !== false, 'module install runs persistence schema');

$moduleTwig = file_get_contents($root . DIRECTORY_SEPARATOR . 'upload/admin/view/template/extension/module/mt_uni_credit.twig');
mtuc2_assert(strpos($moduleTwig, 'text_secret_phase2') === false, 'module twig removes Phase 1 secret placeholder text');

$traversal = MtUniCreditDeploymentPaths::resolveUnderRoot(
    $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'tmp-protected',
    '../outside.pem'
);
mtuc2_assert($traversal === null, 'deployment path resolution blocks traversal');

echo PHP_EOL . 'Phase 2 checks: ' . $passes . ' passed';
if ($failures) {
    echo ', ' . count($failures) . ' failed' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo ', 0 failed' . PHP_EOL;
exit(0);
