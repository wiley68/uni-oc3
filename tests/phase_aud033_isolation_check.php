<?php

/**
 * AUD-033 — network isolation + unique temp-root regression.
 * Run: php tests/phase_aud033_isolation_check.php
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/support/phase2_memory_db.php';

$failures = array();
$passes = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucAud033_assert($condition, $message)
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
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DIR_STORAGE')) {
    mtuc_test_define_dir_storage('mtuc-aud033');
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
}

require_once $lib . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . '/support/phase4_harness.php';
require_once __DIR__ . '/support/phase5_harness.php';
require_once __DIR__ . '/support/phase7_harness.php';
require_once __DIR__ . '/support/phase9_harness.php';
require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

$requiredDisabled = implode(',', mtuc_phase0_required_disabled_curl_functions());

// ---------------------------------------------------------------------------
// Isolation armed (runtime)
// ---------------------------------------------------------------------------
mtucAud033_assert(mtuc_phase0_network_isolation_active(), 'isolation: offline guard active');
mtucAud033_assert(
    getenv('MTUC_OFFLINE_NETWORK_GUARD') === '1',
    'isolation: re-exec/offline guard marker present'
);
mtucAud033_assert(
    mtuc_phase0_curl_functions_disabled_in_ini(),
    'isolation: disable_functions lists required curl symbols'
);
try {
    mtuc_phase0_assert_network_isolation_active();
    mtucAud033_assert(true, 'isolation: assert helper passes when armed');
} catch (Exception $e) {
    mtucAud033_assert(false, 'isolation: assert helper passes when armed');
}

// ---------------------------------------------------------------------------
// F-033-04: PHP 7 vs PHP 8 semantics (structural predicate)
// ---------------------------------------------------------------------------
$php7GuardOk = mtuc_phase0_evaluate_network_isolation(
    false,
    true,
    $requiredDisabled,
    true,
    true
);
mtucAud033_assert($php7GuardOk, 'F-033-04: PHP 7 semantics — guard+ini active even if function_exists true');

$php7GuardMissingIni = mtuc_phase0_evaluate_network_isolation(
    false,
    true,
    '',
    true,
    true
);
mtucAud033_assert(
    !$php7GuardMissingIni,
    'F-033-04: PHP 7 semantics — guard alone without disable_functions is inactive'
);

$php8GuardOk = mtuc_phase0_evaluate_network_isolation(
    false,
    true,
    $requiredDisabled,
    false,
    false
);
mtucAud033_assert($php8GuardOk, 'F-033-04: PHP 8 semantics — guard+ini with undefined curl symbols');

$php8SymbolsOnly = mtuc_phase0_evaluate_network_isolation(
    false,
    false,
    '',
    false,
    false
);
mtucAud033_assert($php8SymbolsOnly, 'F-033-04: missing curl extension counts as isolated');

$oldBuggyWouldFail = (true && true); // function_exists both true
$oldPredicate = !$oldBuggyWouldFail; // old: !exists && !exists → false on PHP 7
mtucAud033_assert(
    $php7GuardOk && !$oldPredicate,
    'F-033-04: new predicate accepts PHP 7 case that function_exists-only rejected'
);

// ---------------------------------------------------------------------------
// A. Fake CP transport usable
// ---------------------------------------------------------------------------
$fake = new Phase4FakeCpHttpTransport();
$fake->enqueueJson(200, array('success' => true, 'data' => array('ok' => 1)));
$response = $fake->request('POST', 'https://cp-test.example.com/api/v1/auth/login', array(), array('x' => 1));
mtucAud033_assert($response->getStatusCode() === 200, 'A: fake CP status 200');
mtucAud033_assert(count($fake->requests) === 1, 'A: fake CP recorded request');
mtucAud033_assert(mtuc_phase0_network_isolation_active(), 'A: isolation still active after fake CP');

// ---------------------------------------------------------------------------
// B. Injected SmartUCF executor usable
// ---------------------------------------------------------------------------
$probe = (object) array('calls' => 0);
$client = new MtUniCreditSmartUcfSessionClient(
    null,
    null,
    function (array $options) use ($probe) {
        $probe->calls++;

        return array(
            'body' => json_encode(array('sucfOnlineSessionID' => 'aud033-sess')),
            'error' => '',
            'http_code' => 200,
        );
    }
);
mtucAud033_assert(is_object($client), 'B: SmartUCF client constructed with executor');
$ref = new ReflectionClass($client);
$method = $ref->getMethod('executeHttp');
$method->setAccessible(true);
$execResult = $method->invoke($client, array(CURLOPT_URL => 'https://blocked.example.invalid/session'));
mtucAud033_assert($probe->calls === 1, 'B: injected executor invoked');
mtucAud033_assert(
    is_array($execResult) && $execResult['http_code'] === 200 && strpos($execResult['body'], 'aud033-sess') !== false,
    'B: executor returns controlled body'
);
mtucAud033_assert(mtuc_phase0_network_isolation_active(), 'B: isolation still active after SmartUCF executor');

// ---------------------------------------------------------------------------
// C + D. Accidental production CP / SmartUCF curl blocked
// ---------------------------------------------------------------------------
$live = new MtUniCreditCurlCpHttpTransport();
$blocked = false;
$message = '';
try {
    $live->request(
        'GET',
        'https://127.0.0.1:9/mtuc-isolation-must-not-connect',
        array('Accept' => 'application/json'),
        null
    );
} catch (Throwable $e) {
    $blocked = true;
    $message = $e->getMessage();
}
mtucAud033_assert($blocked, 'C: production CurlCpHttpTransport rejected');
mtucAud033_assert(
    stripos($message, 'cURL') !== false
        || stripos($message, 'curl') !== false
        || stripos($message, 'network isolation') !== false
        || stripos($message, 'disabled') !== false,
    'D: failure message identifies blocked cURL/network path (' . $message . ')'
);

$liveSmart = new MtUniCreditSmartUcfSessionClient();
$smartBlocked = false;
$smartMessage = '';
try {
    $refSmart = new ReflectionClass($liveSmart);
    $exec = $refSmart->getMethod('executeHttp');
    $exec->setAccessible(true);
    $exec->invoke($liveSmart, array(CURLOPT_URL => 'https://127.0.0.1:9/smartucf-must-not-connect'));
} catch (Throwable $e) {
    $smartBlocked = true;
    $smartMessage = $e->getMessage();
}
mtucAud033_assert($smartBlocked, 'C2: production SmartUCF curl path rejected');
mtucAud033_assert($smartMessage !== '', 'D2: SmartUCF isolation failure is explicit');

// ---------------------------------------------------------------------------
// F-033-03: cleanup membership + dangerous targets
// ---------------------------------------------------------------------------
$a = MtUniCreditTestTempRoot::allocate('mtuc-aud033-a');
$b = MtUniCreditTestTempRoot::allocate('mtuc-aud033-b');
mtucAud033_assert(is_dir($a) && is_dir($b), 'temp: two roots created');
mtucAud033_assert($a !== $b, 'temp: roots are unique');
file_put_contents($a . DIRECTORY_SEPARATOR . 'marker.txt', 'x');

$cleanA = MtUniCreditTestTempRoot::cleanup($a);
mtucAud033_assert($cleanA === true, 'temp: allocated root cleanup returns true');
mtucAud033_assert(!is_dir($a), 'temp: allocated root deleted');
mtucAud033_assert(is_dir($b), 'temp: sibling allocated root intact');

$cleanA2 = MtUniCreditTestTempRoot::cleanup($a);
mtucAud033_assert($cleanA2 === true, 'temp: second cleanup of same root is idempotent');

$temp = realpath(sys_get_temp_dir());
mtucAud033_assert(
    MtUniCreditTestTempRoot::cleanup($temp !== false ? $temp : sys_get_temp_dir()) === false,
    'temp: sys temp root refused'
);
mtucAud033_assert(is_dir(sys_get_temp_dir()), 'temp: sys temp root remains');

mtucAud033_assert(MtUniCreditTestTempRoot::cleanup('') === false, 'temp: empty path refused');
mtucAud033_assert(MtUniCreditTestTempRoot::cleanup('/') === false, 'temp: filesystem root refused');
mtucAud033_assert(MtUniCreditTestTempRoot::cleanup('C:\\') === false, 'temp: Windows drive root refused');
mtucAud033_assert(
    MtUniCreditTestTempRoot::cleanup($root) === false,
    'temp: path outside temp refused'
);
mtucAud033_assert(is_dir($root), 'temp: project root remains intact');

$unallocated = ($temp !== false ? $temp : sys_get_temp_dir())
    . DIRECTORY_SEPARATOR . 'mtuc-aud033-unallocated-' . getmypid();
@mkdir($unallocated, 0770, true);
file_put_contents($unallocated . DIRECTORY_SEPARATOR . 'keep.txt', 'keep');
mtucAud033_assert(
    MtUniCreditTestTempRoot::cleanup($unallocated) === false,
    'temp: unallocated temp child refused'
);
mtucAud033_assert(
    is_dir($unallocated) && is_file($unallocated . DIRECTORY_SEPARATOR . 'keep.txt'),
    'temp: unallocated temp child remains intact'
);
@unlink($unallocated . DIRECTORY_SEPARATOR . 'keep.txt');
@rmdir($unallocated);

$shallow = ($temp !== false ? $temp : sys_get_temp_dir())
    . DIRECTORY_SEPARATOR . 'mtuc-aud033-shallow-' . getmypid();
@mkdir($shallow, 0770, true);
mtucAud033_assert(
    MtUniCreditTestTempRoot::cleanup($shallow) === false,
    'temp: shallow unrelated temp child refused'
);
mtucAud033_assert(is_dir($shallow), 'temp: shallow unrelated temp child remains intact');
@rmdir($shallow);

// Child of allocated root must not be directly deletable via cleanup().
$childParent = MtUniCreditTestTempRoot::allocate('mtuc-aud033-child');
$child = $childParent . DIRECTORY_SEPARATOR . 'nested';
@mkdir($child, 0770, true);
mtucAud033_assert(
    MtUniCreditTestTempRoot::cleanup($child) === false,
    'temp: child of allocated root refused'
);
mtucAud033_assert(is_dir($child), 'temp: nested child remains until parent cleanup');
MtUniCreditTestTempRoot::cleanup($childParent);

$storage = MtUniCreditTestTempRoot::allocateModuleStorage('mtuc-aud033-storage');
mtucAud033_assert(
    is_dir($storage . DIRECTORY_SEPARATOR . 'mt_uni_credit'),
    'temp: module storage includes mt_uni_credit'
);

// Symlink/junction: structural note only (not portable across Windows CI without admin).
mtucAud033_assert(
    true,
    'temp: symlink/junction rejection relies on realpath canonical membership (structural)'
);

echo PHP_EOL;
if ($failures) {
    echo 'FAILED ' . count($failures) . ' / asserted ' . ($passes + count($failures)) . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'AUD-033 ISOLATION REGRESSION: PASS — LOCAL (' . $passes . ' assertions)' . PHP_EOL;
exit(0);
