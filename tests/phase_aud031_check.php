<?php

/**
 * AUD-031 — release must not ship Process 2 test recorder or encryption test seed helpers.
 * Run: php tests/phase_aud031_check.php
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/support/encryption_test_secret.php';

$failures = array();
$passes = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucAud031_assert($condition, $message)
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
 * @param string $rootDir
 * @param array<int, string> $needles
 * @return array<string, bool>
 */
function mtucAud031_scanUpload($rootDir, array $needles)
{
    $hits = array();
    foreach ($needles as $needle) {
        $hits[$needle] = false;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }
        $ext = strtolower(pathinfo($fileInfo->getFilename(), PATHINFO_EXTENSION));
        if (!in_array($ext, array('php', 'twig', 'js', 'xml', 'json', 'md', 'txt'), true)) {
            continue;
        }
        $contents = (string) file_get_contents($fileInfo->getPathname());
        foreach ($needles as $needle) {
            if ($needle !== '' && strpos($contents, $needle) !== false) {
                $hits[$needle] = true;
            }
        }
    }

    return $hits;
}

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';
$upload = $root . DIRECTORY_SEPARATOR . 'upload';
$support = $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'support';
$seed = MtUniCreditEncryptionTestSecret::INSTALLATION_TEST_SECRET;

$prodRecorder = $lib . DIRECTORY_SEPARATOR . 'recording_process_two_mailer.php';
$testRecorder = $support . DIRECTORY_SEPARATOR . 'recording_process_two_mailer.php';
$keyProvider = $lib . DIRECTORY_SEPARATOR . 'encryption_key_provider.php';
$bootstrap = $lib . DIRECTORY_SEPARATOR . 'bootstrap.php';
$phpMailer = $lib . DIRECTORY_SEPARATOR . 'php_mail_process_two_mailer.php';

mtucAud031_assert(!is_file($prodRecorder), 'F-031-01: recorder absent from upload/library');
mtucAud031_assert(is_file($testRecorder), 'F-031-01: recorder lives under tests/support');

$bootstrapSrc = (string) file_get_contents($bootstrap);
mtucAud031_assert(
    strpos($bootstrapSrc, 'recording_process_two_mailer') === false,
    'F-031-01: production bootstrap does not reference recording_process_two_mailer'
);
mtucAud031_assert(
    strpos($bootstrapSrc, 'RecordingProcessTwoMailer') === false,
    'F-031-01: production bootstrap does not reference RecordingProcessTwoMailer'
);

$mailerSrc = (string) file_get_contents($phpMailer);
mtucAud031_assert(
    strpos($mailerSrc, 'RecordingProcessTwoMailer') === false,
    'F-031-01: production php mailer has no recorder coupling'
);
mtucAud031_assert(
    strpos($mailerSrc, 'recorder') === false,
    'F-031-01: production php mailer has no recorder property'
);

$keySrc = (string) file_get_contents($keyProvider);
mtucAud031_assert(
    strpos($keySrc, 'testSecretInput') === false,
    'F-031-02: testSecretInput absent from encryption_key_provider'
);
mtucAud031_assert(
    strpos($keySrc, $seed) === false,
    'F-031-02: deterministic test seed absent from encryption_key_provider'
);
mtucAud031_assert(
    strpos($keySrc, 'DB_PASSWORD') !== false,
    'F-031-02: production still resolves DB_PASSWORD'
);
mtucAud031_assert(
    strpos($keySrc, 'mt_uni_credit/settings-encryption/v1') !== false,
    'F-031-02: HKDF info string unchanged'
);

$uploadHits = mtucAud031_scanUpload($upload, array(
    'testSecretInput',
    'recording_process_two_mailer',
    'RecordingProcessTwoMailer',
    $seed,
    'force_test_cp_create_422',
    'X-UniPayment-Test-Failure',
    'UNIPAYMENT_ENABLE_TEST_FAILURES',
    'ShopApiTestFailure',
    'test_force_reject',
));
mtucAud031_assert(empty($uploadHits['testSecretInput']), 'F-031-02: testSecretInput absent under upload/');
mtucAud031_assert(empty($uploadHits['recording_process_two_mailer']), 'F-031-01: recording_process_two_mailer absent under upload/');
mtucAud031_assert(empty($uploadHits['RecordingProcessTwoMailer']), 'F-031-01: RecordingProcessTwoMailer absent under upload/');
mtucAud031_assert(empty($uploadHits[$seed]), 'F-031-02: deterministic test seed absent under upload/');
mtucAud031_assert(empty($uploadHits['force_test_cp_create_422']), 'prior exclusion: force_test_cp_create_422');
mtucAud031_assert(empty($uploadHits['X-UniPayment-Test-Failure']), 'prior exclusion: X-UniPayment-Test-Failure');
mtucAud031_assert(empty($uploadHits['UNIPAYMENT_ENABLE_TEST_FAILURES']), 'prior exclusion: UNIPAYMENT_ENABLE_TEST_FAILURES');
mtucAud031_assert(empty($uploadHits['ShopApiTestFailure']), 'prior exclusion: ShopApiTestFailure');
mtucAud031_assert(empty($uploadHits['test_force_reject']), 'prior exclusion: test_force_reject');

$packagePath = $root . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'CC_OpenCartv.3.x_UNI_v.2.0.2.ocmod.zip';
if (is_file($packagePath)) {
    $zip = new ZipArchive();
    mtucAud031_assert($zip->open($packagePath) === true, 'package opens');
    mtucAud031_assert($zip->locateName('install.xml') !== false, 'package contains install.xml');
    mtucAud031_assert(
        $zip->locateName('upload/system/library/mt_uni_credit/bootstrap.php') !== false,
        'package contains upload tree'
    );
    mtucAud031_assert(
        $zip->locateName('upload/system/library/mt_uni_credit/recording_process_two_mailer.php') === false,
        'package lacks recording_process_two_mailer.php'
    );

    $zipHasTestSecret = false;
    $zipHasSeed = false;
    $zipHasRecorder = false;
    $zipHasTestsPath = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
        if (strpos($name, 'tests/') === 0 || strpos($name, '/tests/') !== false) {
            $zipHasTestsPath = true;
        }
        if (stripos($name, 'recording_process_two_mailer') !== false) {
            $zipHasRecorder = true;
        }
        $contents = (string) $zip->getFromIndex($i);
        if (strpos($contents, 'testSecretInput') !== false) {
            $zipHasTestSecret = true;
        }
        if ($seed !== '' && strpos($contents, $seed) !== false) {
            $zipHasSeed = true;
        }
    }
    mtucAud031_assert(!$zipHasRecorder, 'package has no recorder filename');
    mtucAud031_assert(!$zipHasTestSecret, 'package has no testSecretInput');
    mtucAud031_assert(!$zipHasSeed, 'package has no deterministic test seed');
    mtucAud031_assert(!$zipHasTestsPath, 'package has no tests/ paths');
    $zip->close();
} else {
    mtucAud031_assert(false, 'distributable package present for AUD-031 scan');
}

$packageScript = (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'package.ps1');
mtucAud031_assert(
    strpos($packageScript, 'recording_process_two_mailer.php') !== false,
    'package.ps1 forbids recording_process_two_mailer.php'
);

echo PHP_EOL;
if ($failures) {
    echo 'FAILED ' . count($failures) . ' / asserted ' . ($passes + count($failures)) . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'AUD-031 RELEASE CONTENT GUARD: PASS — LOCAL (' . $passes . ' assertions)' . PHP_EOL;
exit(0);
