<?php
/** Native cURL symbols are disabled by the parent. These stubs cannot open sockets. */
require_once dirname(__DIR__) . '/bootstrap.php';
if (!defined('CURLOPT_RESOLVE')) { echo 'SKIP cURL constants unavailable' . PHP_EOL; exit(2); }
foreach (array('curl_init', 'curl_exec', 'curl_setopt_array', 'curl_close', 'curl_errno', 'curl_error', 'curl_getinfo', 'curl_version') as $function) {
    if (function_exists($function)) { echo 'FAIL native cURL symbol not disabled' . PHP_EOL; exit(2); }
}
$probe = (object) array('options' => array(), 'calls' => 0, 'errno' => 0, 'status' => 200, 'closed' => 0, 'version' => 0x075000);
if (!function_exists('curl_init')) {
function curl_init($url) { return (object) array('url' => $url); }
function curl_setopt_array($handle, $options) { global $probe; $probe->options = $options; return true; }
function curl_exec($handle) { global $probe; $probe->calls++; return $probe->errno === 0; }
function curl_close($handle) { global $probe; $probe->closed++; }
function curl_errno($handle) { global $probe; return $probe->errno; }
function curl_error($handle) { return 'SECRET-LEAK runtime details'; }
function curl_getinfo($handle, $key) { global $probe; return $probe->status; }
function curl_version() { global $probe; return array('version_number' => $probe->version); }
}
define('DIR_SYSTEM', dirname(__DIR__, 2) . '/upload/system/');
define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';
require_once __DIR__ . '/cp_outbound_harness.php';
$passed = 0;
$failed = 0;
function curlProbeAssert($ok, $message) {
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
}
foreach (array('public', 'private', 'mixed', 'dns', 'tls', 'timeout', 'connection', 'payload', 'multi', 'legacy') as $case) {
    $probe->calls = 0;
    $probe->closed = 0;
    $probe->version = $case === 'legacy' ? 0x073A00 : 0x075000;
    $probe->errno = $case === 'tls' ? CURLE_SSL_CONNECT_ERROR : ($case === 'timeout' ? CURLE_OPERATION_TIMEDOUT : ($case === 'connection' ? CURLE_COULDNT_CONNECT : 0));
    $resolver = function ($host) use ($case) {
        if ($case === 'dns') { return false; }
        if ($case === 'private') { return array(array('ip' => '127.0.0.1')); }
        if ($case === 'mixed') { return array(array('ip' => '93.184.216.34'), array('ip' => '10.0.0.1')); }
        if ($case === 'multi' || $case === 'legacy') { return array(array('ip' => '93.184.216.34'), array('ipv6' => '2606:4700:4700::1111')); }
        return array(array('ip' => '93.184.216.34'));
    };
    $transport = new MtUniCreditCurlCpHttpTransport(null, null, $resolver);
    $state = MtUniCreditCpOutboundDiagnostics::begin('POST', '/orders', 'future-cp.newdeployment.com');
    $state['phase'] = 'transport';
    $state['transport_attempted'] = true;
    $exception = null;
    try {
        $transport->request('POST', 'https://future-cp.newdeployment.com/api/v1/orders', array(), array('order_id' => $case === 'payload' ? "\xB1\x31" : '88001'));
    } catch (Throwable $caught) { $exception = $caught; }
    $expectedReason = array('private' => 'destination_unsafe', 'mixed' => 'destination_unsafe', 'dns' => 'dns_failure', 'tls' => 'tls_failure',
        'timeout' => 'timeout', 'connection' => 'transport_failure', 'payload' => 'payload_invalid');
    $attempted = in_array($case, array('public', 'tls', 'timeout', 'connection', 'multi', 'legacy'), true);
    curlProbeAssert($probe->calls === ($attempted ? 1 : 0), $case . ': exact curl_exec count');
    curlProbeAssert($probe->closed === 1, $case . ': handle closed');
    if (in_array($case, array('public', 'multi', 'legacy'), true)) {
        $addressList = '93.184.216.34' . ($case === 'multi' ? ',[2606:4700:4700::1111]' : '');
        curlProbeAssert($exception === null && $probe->options[CURLOPT_RESOLVE] === array('future-cp.newdeployment.com:443:' . $addressList)
            && $probe->options[CURLOPT_PROXY] === '' && $probe->options[CURLOPT_FOLLOWLOCATION] === false, 'actual transport pins validated IP, keeps original TLS host and forbids proxy/redirect');
    } else {
        $diagnostic = MtUniCreditCpOutboundDiagnostics::failure($state, $exception);
        curlProbeAssert($diagnostic['reason_code'] === $expectedReason[$case] && $diagnostic['transport_attempted'] === $attempted
            && strpos(json_encode($diagnostic), 'SECRET-LEAK') === false, $case . ': truthful safe metadata');
    }
}
echo 'CURL TRANSPORT PROBE: passed=' . $passed . ' failed=' . $failed . PHP_EOL;
exit($failed === 0 ? 0 : 1);
