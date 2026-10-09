<?php
/** Outbound metadata and lifecycle invariants, using file-based destinations and fake HTTP. */
require_once __DIR__ . '/bootstrap.php';
define('DIR_SYSTEM', dirname(__DIR__) . '/upload/system/');
define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
define('DB_PREFIX', 'oc_');
mtuc_test_define_dir_storage('mtuc-outbound');
require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';
require_once __DIR__ . '/support/cp_outbound_harness.php';
require_once __DIR__ . '/support/phase7_harness.php';
$passed = 0;
$failed = 0;
function outboundAssert($ok, $message) {
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
}
$cases = array('configuration', 'timeout', 'connection', '401', '422', '503', 'malformed', 'success');
foreach ($cases as $index => $case) {
    $transport = new Phase4FakeCpHttpTransport();
    $stack = Phase7TestHarness::stack($transport);
    $services = CpOutboundTestHarness::services($case === 'configuration' ? 'https://localhost' : 'https://cptest.ucfinonline.bg', $transport, $stack['memoryDb'], $stack['storeId']);
    $clientProperty = new ReflectionProperty($stack['lifecycle'], 'client');
    $clientProperty->setAccessible(true);
    $clientProperty->setValue($stack['lifecycle'], $services['client']);
    // A valid token isolates create diagnostics from an authentication sub-operation.
    $services['tokens']->replaceValidated('test-bearer-sensitive-value', 'Bearer', 1700003600);
    if ($case === 'timeout') { $transport->enqueueTimeout(); }
    elseif ($case === 'connection') { $transport->enqueueConnectionFailure(); }
    elseif (in_array($case, array('401', '422', '503'), true)) {
        $transport->enqueueJson((int) $case, array('success' => false, 'error' => $case === '503' ? 'server_error' : 'invalid_payload', 'message' => 'SECRET-LEAK pii@example.test', 'data' => new stdClass()));
    } elseif ($case === 'malformed') { $transport->enqueue(200, '{SECRET-LEAK'); }
    elseif ($case === 'success') { $transport->enqueueJson(200, Phase7TestHarness::loginAndOrderSuccessPayloads()['order']); }
    $orderId = 88000 + $index;
    $input = Phase7TestHarness::submitInput($orderId, $stack['storeId']);
    $result = $stack['submission']->submit($input);
    $attempt = $stack['attempts']->findByStoreOrder($stack['storeId'], $orderId);
    $terminal = in_array($case, array('configuration', '422'), true);
    outboundAssert($attempt !== null && ($case === 'success' ? !empty($result['success']) : empty($result['success'])), $case . ': existing success/failure result');
    outboundAssert($attempt !== null && ($case === 'success' || ($terminal
        ? $attempt['state'] === MtUniCreditFinancingAttemptState::TERMINAL_FAILED
        : $attempt['state'] === MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN)), $case . ': terminal/ambiguous semantics preserved');
    $records = (new MtUniCreditDiagnosticDebugLogRepository($stack['db']))->findAllForStore($stack['storeId']);
    $found = null;
    foreach ($records as $record) {
        if (isset($record['attempt_id']) && $record['attempt_id'] === (int) $attempt['attempt_id'] && $record['operation'] === 'cp_order_create') { $found = $record; }
    }
    outboundAssert($found !== null, $case . ': persisted create metadata and attempt correlation');
    if ($found !== null) {
        outboundAssert($found['transport_attempted'] === ($case !== 'configuration'), $case . ': actual transport flag');
        $expectedStatus = ctype_digit($case) ? (int) $case : (in_array($case, array('malformed', 'success'), true) ? 200 : null);
        outboundAssert($found['http_status'] === $expectedStatus, $case . ': actual HTTP status, no invented local 0/401');
        outboundAssert($found['endpoint'] === '/api/v1/orders' && $found['destination_hostname'] === ($case === 'configuration' ? null : 'cptest.ucfinonline.bg'), $case . ': static endpoint/validated hostname');
        $safe = json_encode($found);
        outboundAssert(strpos($safe, 'SECRET-LEAK') === false && strpos($safe, 'test-bearer-sensitive-value') === false
            && strpos($safe, 'pii@example.test') === false && strpos($safe, Phase4TestHarness::TEST_SECRET) === false, $case . ': no payload/token/exception-message leak');
    }
    $posts = Phase7TestHarness::countOrderPosts($transport);
    $stack['submission']->submit($input);
    outboundAssert(Phase7TestHarness::countOrderPosts($transport) === $posts && $posts === ($case === 'configuration' ? 0 : 1), $case . ': revisit never duplicates create');
}
// Authentication failures must identify the failing login operation, not invent a /orders response.
$transport = new Phase4FakeCpHttpTransport();
$transport->enqueueJson(401, array('success' => false, 'message' => 'SECRET-LEAK'));
$client = CpOutboundTestHarness::services('https://cptest.ucfinonline.bg', $transport)['client'];
$exception = null;
try { $client->createOrder(array('order_id' => '88010')); } catch (Throwable $caught) { $exception = $caught; }
$diagnostic = $client->getOutboundDiagnostic($exception);
outboundAssert($diagnostic['operation'] === 'cp_login' && $diagnostic['endpoint'] === '/api/v1/auth/login'
    && $diagnostic['http_status'] === 401 && $diagnostic['transport_attempted'] === true, 'login failure retains actual sub-operation/HTTP evidence');
foreach (array('dns_failure', 'tls_failure', 'transport_failure', 'payload_invalid') as $reason) {
    $attempted = $reason !== 'payload_invalid';
    $state = MtUniCreditCpOutboundDiagnostics::begin('POST', '/orders', 'cp.public-domain.com');
    $state['transport_attempted'] = true;
    $diagnostic = MtUniCreditCpOutboundDiagnostics::failure($state, new MtUniCreditCpConnectionException('SECRET-LEAK', 0, null, $attempted, $reason));
    outboundAssert($diagnostic['reason_code'] === $reason && $diagnostic['transport_attempted'] === $attempted
        && strpos(json_encode($diagnostic), 'SECRET-LEAK') === false, 'typed transport metadata: ' . $reason);
}
$environment = new MtUniCreditDeploymentEnvironment(__DIR__ . '/fixtures/nonexistent-environment.php');
$exception = null;
try { $environment->controlPanelUrl(); } catch (Throwable $caught) { $exception = $caught; }
outboundAssert($exception instanceof MtUniCreditCpConfigurationException && $exception->getDiagnosticReason() === 'environment_unreadable', 'missing environment fixed reason');
$disabled = ini_get('disable_functions') . ',curl_init,curl_exec,curl_setopt_array,curl_close,curl_errno,curl_error,curl_getinfo,curl_version';
$command = escapeshellarg(PHP_BINARY) . ' -d ' . escapeshellarg('disable_functions=' . $disabled)
    . ' ' . escapeshellarg(__DIR__ . '/support/cp_curl_transport_probe.php');
passthru($command, $code);
outboundAssert($code === 0, 'offline actual cURL transport probe');
echo 'CP OUTBOUND REMEDIATION: passed=' . $passed . ' failed=' . $failed . PHP_EOL;
exit($failed === 0 ? 0 : 1);
