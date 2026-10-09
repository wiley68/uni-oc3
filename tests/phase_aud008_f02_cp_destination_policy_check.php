<?php
/** Structural CP policy: environment.php is the sole destination authority. Offline. */
require_once __DIR__ . '/bootstrap.php';
define('DIR_SYSTEM', dirname(__DIR__) . '/upload/system/');
define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';
require_once __DIR__ . '/support/cp_outbound_harness.php';
$passed = 0;
$failed = 0;
function policyAssert($ok, $message) {
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
}
$policy = new MtUniCreditCpDestinationPolicy();
// Each URL is supplied only through the environment fixture. No policy injection.
foreach (array('uni.avalonbg.com', 'cptest.ucfinonline.bg', 'cp.ucfinonline.bg', 'future-cp.newdeployment.com', 'cp-test.example.com') as $host) {
    $transport = new Phase4FakeCpHttpTransport();
    $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
    $transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());
    $services = CpOutboundTestHarness::services('https://' . $host, $transport);
    $services['client']->getShop();
    policyAssert(count($transport->requests) === 2, $host . ': login and shop transport reached');
    policyAssert($transport->requests[0]['url'] === 'https://' . $host . '/api/v1/auth/login'
        && $transport->requests[1]['url'] === 'https://' . $host . '/api/v1/shop', $host . ': configured authority preserved');
}
policyAssert($policy->assertTrustedOrigin(' https://CP.PUBLIC-DOMAIN.COM:443/ ') === 'https://cp.public-domain.com', 'origin canonicalization');
policyAssert($policy->assertTrustedApiBase('https://cp.public-domain.com/api/v1/') === 'https://cp.public-domain.com/api/v1', 'API base canonicalization');
policyAssert($policy->assertTrustedOrigin('https://cp.xn--p1ai') === 'https://cp.xn--p1ai', 'valid ASCII IDN hostname accepted');
$unsafe = array('http://cp.public-domain.com', 'not-a-url', '', 'https://user:secret@cp.public-domain.com',
    'https://cp.public-domain.com#fragment', 'https://cp.public-domain.com?', 'https://cp.public-domain.com:8443',
    'https://127.0.0.1', 'https://[::1]', 'https://2130706433', 'https://0x7f000001', 'https://127.1',
    'https://localhost', 'https://cp.localhost', 'https://cp.local', 'https://cp.internal', 'https://cp.home.arpa',
    'https://cp.test', 'https://-bad.public-domain.com', 'https://bad_.public-domain.com',
    'https://cp.public-domain.com.', 'https://cp.public-domain.com/path', 'https://cp.public-domain.com/api/v1',
    "https://cp.public-domain.com/\npath", 'https://cp.public-domain.com\\@127.0.0.1');
foreach ($unsafe as $index => $origin) {
    $transport = new Phase4FakeCpHttpTransport();
    $client = CpOutboundTestHarness::services($origin, $transport)['client'];
    $exception = null;
    try { $client->getShop(); } catch (Throwable $caught) { $exception = $caught; }
    policyAssert($exception instanceof MtUniCreditCpConfigurationException && count($transport->requests) === 0, 'unsafe destination ' . $index . ': controlled, zero transport');
    $diagnostic = $client->getOutboundDiagnostic($exception);
    policyAssert($diagnostic['transport_attempted'] === false && $diagnostic['http_status'] === null
        && $diagnostic['destination_hostname'] === null, 'unsafe destination ' . $index . ': no false HTTP/validated host');
    policyAssert(strpos(json_encode($diagnostic), 'secret') === false, 'unsafe destination ' . $index . ': no userinfo/message leak');
}
foreach (array('0.0.0.0', '10.1.2.3', '100.64.1.1', '127.0.0.1', '169.254.169.254', '172.16.0.1', '192.168.1.1', '192.0.0.1',
    '192.0.2.1', '198.18.0.1', '198.51.100.1', '203.0.113.1', '224.0.0.1', '255.255.255.255',
    '::1', '::ffff:127.0.0.1', 'fc00::1', 'fe80::1', 'ff02::1', '2001:db8::1', '2002:7f00:1::1', '3fff::1') as $address) {
    $exception = null;
    try { $policy->assertPublicAddress($address); } catch (Throwable $caught) { $exception = $caught; }
    policyAssert($exception instanceof MtUniCreditCpDestinationException, 'reserved/private address rejected: ' . $address);
}
foreach (array('93.184.216.34', '8.8.8.8', '2606:4700:4700::1111', '2001:4860:4860::8888') as $address) {
    policyAssert($policy->assertPublicAddress($address) === $address, 'public address accepted: ' . $address);
}
$public = $policy->resolvePublicAddresses('future-cp.newdeployment.com', function ($host) {
    return array(array('ip' => '93.184.216.34'), array('ipv6' => '2606:4700:4700::1111'));
});
policyAssert(count($public) === 2, 'resolver accepts public IPv4/IPv6 without CP host enumeration');
$aliases = $policy->resolvePublicAddresses('cp.public-domain.com', function ($host) {
    return $host === 'cp.public-domain.com' ? array(array('type' => 'CNAME', 'target' => 'edge.public-domain.com.')) : array(array('ip' => '93.184.216.34'));
});
policyAssert($aliases === array('93.184.216.34'), 'public CNAME is resolved and pinned without changing destination authority');
$exception = null;
try { $policy->resolvePublicAddresses('cp.public-domain.com', function () { return array(array('type' => 'CNAME', 'target' => 'cp.public-domain.com.')); }); }
catch (Throwable $caught) { $exception = $caught; }
policyAssert($exception instanceof MtUniCreditCpConnectionException, 'CNAME loop fails closed');
$exception = null;
try { $policy->resolvePublicAddresses('cp.public-domain.com', function () { return array(array('type' => 'CNAME', 'target' => 'metadata.local.')); }); }
catch (Throwable $caught) { $exception = $caught; }
policyAssert($exception instanceof MtUniCreditCpDestinationException, 'private CNAME namespace rejected');
$exception = null;
try { $policy->resolvePublicAddresses('cp.public-domain.com', function () { return array(array('ip' => '93.184.216.34'), array('ip' => '127.0.0.1')); }); }
catch (Throwable $caught) { $exception = $caught; }
policyAssert($exception instanceof MtUniCreditCpDestinationException, 'mixed public/private DNS answers fail closed');
$exception = null;
try { $policy->resolvePublicAddresses('cp.public-domain.com', function () { return false; }); }
catch (Throwable $caught) { $exception = $caught; }
policyAssert($exception instanceof MtUniCreditCpConnectionException && $exception->getDiagnosticReason() === 'dns_failure'
    && $exception->wasTransportAttempted() === false, 'DNS failure before HTTP is explicit');
$curlSource = file_get_contents(DIR_SYSTEM . 'library/mt_uni_credit/curl_cp_http_transport.php');
foreach (array('CURLOPT_FOLLOWLOCATION => false', 'CURLOPT_SSL_VERIFYPEER => true', 'CURLOPT_SSL_VERIFYHOST => 2',
    '$options[CURLOPT_RESOLVE]', "$" . "options[CURLOPT_PROXY] = ''") as $contract) {
    policyAssert(strpos($curlSource, $contract) !== false, 'transport guard: ' . $contract);
}
// Scan the entire runtime module, including static assets/templates. CP hostname knowledge
// belongs only to environment.php; generic network ranges and SmartUCF endpoints are distinct.
$occurrences = array();
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/upload', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile()) { continue; }
    $content = file_get_contents($file->getPathname());
    if (preg_match('/uni\.avalonbg\.com|cptest\.ucfinonline\.bg|cp\.ucfinonline\.bg/', $content)) {
        $occurrences[] = str_replace('\\', '/', $file->getPathname());
    }
}
policyAssert(count($occurrences) === 1 && basename($occurrences[0]) === 'environment.php', 'all runtime CP hostname occurrences: environment.php only');
$policySource = file_get_contents(DIR_SYSTEM . 'library/mt_uni_credit/cp_destination_policy.php');
policyAssert(!preg_match('/allowedHosts|trustedHosts|HOST_PRODUCTION|HOST_STAGING|HOST_OFFLINE_TEST/', $policySource), 'no static CP host allowlist/constants');
$authority = CpOutboundTestHarness::services('https://future-cp.newdeployment.com', new Phase4FakeCpHttpTransport());
$override = new MtUniCreditControlPanelClient($authority['credentials'], $authority['tokens'], new Phase4FakeCpHttpTransport(), Phase4TestHarness::TEST_SHOP_URL, Phase4TestHarness::TEST_STORE_ID, 'https://another.public-domain.com/api/v1');
$exception = null;
try { $override->login(); } catch (Throwable $caught) { $exception = $caught; }
policyAssert($exception instanceof MtUniCreditCpConfigurationException, 'raw URL constructor override cannot introduce a second authority');
echo 'CP DESTINATION POLICY: passed=' . $passed . ' failed=' . $failed . PHP_EOL;
exit($failed === 0 ? 0 : 1);
