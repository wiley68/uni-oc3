<?php

/**
 * Phase 10 Process 2 / EGN / leasing mail / privacy offline checks.
 * Run: php tests/phase10_check.php
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/support/phase2_memory_db.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DIR_STORAGE')) {
    $storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mtuc-phase10-storage';
    if (!is_dir($storage)) {
        @mkdir($storage, 0770, true);
    }
    if (!is_dir($storage . DIRECTORY_SEPARATOR . 'mt_uni_credit')) {
        @mkdir($storage . DIRECTORY_SEPARATOR . 'mt_uni_credit', 0770, true);
    }
    define('DIR_STORAGE', rtrim($storage, '/\\') . DIRECTORY_SEPARATOR);
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

$failures = array();
$passes = 0;

function mtuc10_assert(bool $condition, string $message): void
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

function mtuc10_read(string $path): string
{
    $body = @file_get_contents($path);
    return is_string($body) ? $body : '';
}

// ---------------------------------------------------------------------------
// Required files + PHP 7.3 surface
// ---------------------------------------------------------------------------
$required = array(
    'process_two_lifecycle_states.php',
    'process_two_sensitive_data.php',
    'process_two_sensitive_cipher.php',
    'process_two_lifecycle_repository.php',
    'process_two_lifecycle_coordinator.php',
    'process_two_submission_support.php',
    'process_two_service_factory.php',
    'process_two_mail_port.php',
    'php_mail_process_two_mailer.php',
    'recording_process_two_mailer.php',
    'process_two_leasing_mail_presenter.php',
    'financing_leasing_presenter.php',
    'financing_presentation_snapshot.php',
    'financing_presentation_audience.php',
);
foreach ($required as $file) {
    mtuc10_assert(is_file($lib . DIRECTORY_SEPARATOR . $file), 'required file: ' . $file);
}

$phase10Sql = MtUniCreditPersistenceSchema::createPhase10AlterStatements('oc_');
mtuc10_assert(count($phase10Sql) >= 4, 'phase 10 schema alter statements present');
mtuc10_assert(strpos(implode("\n", $phase10Sql), 'process2_sensitive_enc') !== false, 'phase 10 adds process2_sensitive_enc');
mtuc10_assert(strpos(implode("\n", $phase10Sql), 'process2_mail_sent') !== false, 'phase 10 adds process2_mail_sent');

$forbiddenTokens = array(
    'str_contains',
    'str_starts_with',
    '?' . '->',
    '#' . '[',
    'public ' . 'string $',
    'fn' . '(',
);
foreach ($required as $file) {
    $src = mtuc10_read($lib . DIRECTORY_SEPARATOR . $file);
    foreach ($forbiddenTokens as $forbidden) {
        mtuc10_assert(strpos($src, $forbidden) === false, 'PHP 7.3: ' . $file . ' free of ' . $forbidden);
    }
    mtuc10_assert(
        !preg_match('/(?<![\w$])match\s*\(/', $src),
        'PHP 7.3: ' . $file . ' free of match expression'
    );
}

// ---------------------------------------------------------------------------
// A. Process 2 success fixture
// ---------------------------------------------------------------------------
$transportOk = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportOk);
$stackOk = Phase9TestHarness::stack($transportOk, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$orderOk = 10101;
Phase9TestHarness::seedBankOrder($stackOk['memoryDb'], $orderOk, $stackOk['storeId']);
$resultOk = $stackOk['submission']->submit(Phase9TestHarness::submitInputProcess2($orderOk, $stackOk['storeId']));
mtuc10_assert(!empty($resultOk['success']), 'P2 success: overall success');
mtuc10_assert(Phase9TestHarness::smartUcfCallCount($stackOk['smartUcfProbe']) === 0, 'P2 success: SmartUCF calls = 0');
mtuc10_assert(Phase7TestHarness::countOrderPosts($transportOk) === 1, 'P2 success: CP create = 1');
mtuc10_assert(
    Phase9TestHarness::bankStatusId($stackOk, $orderOk) === MtUniCreditBankStatus::SENT_PROCESS2,
    'P2 success: local bank_sent_process2'
);
mtuc10_assert(Phase9TestHarness::countStatusPatches($transportOk) === 1, 'P2 success: CP status PATCH = 1');
$patchOk = Phase9TestHarness::lastStatusPatchPayload($transportOk);
mtuc10_assert(
    is_array($patchOk) && (string) $patchOk['status_id'] === MtUniCreditBankStatus::SENT_PROCESS2,
    'P2 success: CP PATCH status = bank_sent_process2'
);
mtuc10_assert(count($stackOk['process2Mailer']->sent) >= 1, 'P2 success: Process 2 mail/handoff >= 1');
$adminMails = array_values(array_filter(
    $stackOk['process2Mailer']->sent,
    static function ($row) {
        return isset($row['audience']) && $row['audience'] === 'admin';
    }
));
$customerMails = array_values(array_filter(
    $stackOk['process2Mailer']->sent,
    static function ($row) {
        return isset($row['audience']) && $row['audience'] === 'customer';
    }
));
mtuc10_assert(count($adminMails) >= 1, 'P2 success: admin/bank mail sent');
mtuc10_assert(!empty($adminMails[0]['has_egn']), 'P2 success: admin mail includes EGN');
mtuc10_assert(count($customerMails) >= 1, 'P2 success: customer mail sent');
mtuc10_assert(empty($customerMails[0]['has_egn']), 'P2 success: customer mail excludes EGN');
mtuc10_assert(
    strpos((string) $customerMails[0]['html'], Phase9TestHarness::process2Fields()['egn']) === false,
    'P2 success: raw EGN absent from customer HTML'
);

// ---------------------------------------------------------------------------
// B. EGN privacy — CP create payload + Process 1 isolation
// ---------------------------------------------------------------------------
$cpCreatePayload = null;
foreach ($transportOk->requests as $request) {
    if (strtoupper((string) $request['method']) === 'POST' && substr((string) $request['url'], -7) === '/orders') {
        $cpCreatePayload = $request['payload'];
    }
}
mtuc10_assert(is_array($cpCreatePayload), 'privacy: CP create payload captured');
$encodedCreate = json_encode($cpCreatePayload);
mtuc10_assert(
    is_string($encodedCreate) && strpos($encodedCreate, '1990010112') === false,
    'privacy: EGN absent from CP create payload'
);
mtuc10_assert(
    is_string($encodedCreate) && stripos($encodedCreate, 'egn') === false,
    'privacy: egn key absent from CP create payload'
);

$transportP1 = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportP1);
$stackP1 = Phase9TestHarness::stack($transportP1);
$orderP1 = 10102;
Phase9TestHarness::seedBankOrder($stackP1['memoryDb'], $orderP1, $stackP1['storeId']);
$resultP1 = $stackP1['submission']->submit(Phase9TestHarness::submitInput($orderP1, $stackP1['storeId']));
mtuc10_assert(!empty($resultP1['success']) && !empty($resultP1['bank_redirect']), 'P1 regression: SmartUCF success + redirect');
mtuc10_assert(
    Phase9TestHarness::bankStatusId($stackP1, $orderP1) === MtUniCreditBankStatus::SENT_PROCESS1,
    'P1 regression: local bank_sent_process1'
);
mtuc10_assert(Phase9TestHarness::countStatusPatches($transportP1) === 1, 'P1 regression: CP bank_sent_process1 PATCH');
mtuc10_assert(count($stackP1['process2Mailer']->sent) === 0, 'P1 regression: no Process 2 mail');

$payloadBuilderSrc = mtuc10_read($lib . DIRECTORY_SEPARATOR . 'smart_ucf_payload_builder.php');
mtuc10_assert(
    preg_match('/egn|phone2/i', $payloadBuilderSrc) === 1,
    'P1 isolation: SmartUCF payload builder guards egn/phone2 keys'
);

// ---------------------------------------------------------------------------
// C. Mail failure after bank status — recoverable; no duplicate create/SmartUCF
// ---------------------------------------------------------------------------
$transportMailFail = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportMailFail);
$stackMailFail = Phase9TestHarness::stack(
    $transportMailFail,
    null,
    null,
    Phase5TestHarness::STORE_A,
    array('uni_proces' => 1)
);
$stackMailFail['process2Mailer']->forceFailure = true;
$orderMailFail = 10103;
Phase9TestHarness::seedBankOrder($stackMailFail['memoryDb'], $orderMailFail, $stackMailFail['storeId']);
$resultMailFail = $stackMailFail['submission']->submit(
    Phase9TestHarness::submitInputProcess2($orderMailFail, $stackMailFail['storeId'])
);
mtuc10_assert(!empty($resultMailFail['success']), 'mail fail: overall success (bank status independent)');
mtuc10_assert(
    Phase9TestHarness::bankStatusId($stackMailFail, $orderMailFail) === MtUniCreditBankStatus::SENT_PROCESS2,
    'mail fail: bank_sent_process2 still set'
);
$p2Row = (new MtUniCreditProcessTwoLifecycleRepository($stackMailFail['db'], $stackMailFail['clock']))
    ->findByAttempt((int) $stackMailFail['attempts']->findByStoreOrder($stackMailFail['storeId'], $orderMailFail)['attempt_id']);
mtuc10_assert(
    is_array($p2Row) && (string) $p2Row['process2_state'] === MtUniCreditProcessTwoLifecycleStates::PREPARED,
    'mail fail: process2_prepared persisted'
);
mtuc10_assert(empty($p2Row['process2_mail_sent']), 'mail fail: process2_mail_sent remains 0');
mtuc10_assert(Phase7TestHarness::countOrderPosts($transportMailFail) === 1, 'mail fail: no duplicate CP create');

$stackMailFail['process2Mailer']->forceFailure = false;
$resultMailRetry = $stackMailFail['submission']->submit(
    Phase9TestHarness::submitInputProcess2($orderMailFail, $stackMailFail['storeId'])
);
mtuc10_assert(!empty($resultMailRetry['success']), 'mail retry: success');
mtuc10_assert(Phase7TestHarness::countOrderPosts($transportMailFail) === 1, 'mail retry: still one CP create');
mtuc10_assert(Phase9TestHarness::smartUcfCallCount($stackMailFail['smartUcfProbe']) === 0, 'mail retry: still 0 SmartUCF');
$p2RowRetry = (new MtUniCreditProcessTwoLifecycleRepository($stackMailFail['db'], $stackMailFail['clock']))
    ->findByAttempt((int) $stackMailFail['attempts']->findByStoreOrder($stackMailFail['storeId'], $orderMailFail)['attempt_id']);
mtuc10_assert(!empty($p2RowRetry['process2_mail_sent']), 'mail retry: process2_mail_sent = 1');

// ---------------------------------------------------------------------------
// D. Replay after Process 2 success — no duplicate mails/creates
// ---------------------------------------------------------------------------
$mailCountBeforeReplay = count($stackOk['process2Mailer']->sent);
$resultReplay = $stackOk['submission']->submit(Phase9TestHarness::submitInputProcess2($orderOk, $stackOk['storeId']));
mtuc10_assert(!empty($resultReplay['success']), 'replay: success');
mtuc10_assert(Phase7TestHarness::countOrderPosts($transportOk) === 1, 'replay: CP create additional = 0');
mtuc10_assert(
    count($stackOk['process2Mailer']->sent) === $mailCountBeforeReplay,
    'replay: Process2 mail additional = 0'
);

// ---------------------------------------------------------------------------
// E. Missing/invalid EGN — no bank_sent_process2
// ---------------------------------------------------------------------------
$transportBad = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportBad);
$stackBad = Phase9TestHarness::stack($transportBad, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$orderBad = 10104;
Phase9TestHarness::seedBankOrder($stackBad['memoryDb'], $orderBad, $stackBad['storeId']);
$badInput = Phase9TestHarness::submitInputProcess2($orderBad, $stackBad['storeId']);
$badInput['process2']['egn'] = '123';
$resultBad = $stackBad['submission']->submit($badInput);
mtuc10_assert(empty($resultBad['success']), 'invalid EGN: submit fails');
mtuc10_assert(
    Phase9TestHarness::bankStatusId($stackBad, $orderBad) !== MtUniCreditBankStatus::SENT_PROCESS2,
    'invalid EGN: no bank_sent_process2'
);
mtuc10_assert(Phase7TestHarness::countOrderPosts($transportBad) === 0, 'invalid EGN: no CP create before validation');

// ---------------------------------------------------------------------------
// F. Product / Cart / Checkout shared Process 2 wiring
// ---------------------------------------------------------------------------
$storefrontRuntime = mtuc10_read($lib . DIRECTORY_SEPARATOR . 'storefront_runtime.php');
$checkoutModel = mtuc10_read(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'model' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'payment'
        . DIRECTORY_SEPARATOR . 'mt_uni_credit.php'
);
$lifecycleSrc = mtuc10_read($lib . DIRECTORY_SEPARATOR . 'control_panel_order_lifecycle_service.php');
mtuc10_assert(
    strpos($storefrontRuntime, 'MtUniCreditProcessTwoServiceFactory::coordinator') !== false,
    'wiring: storefront runtime injects Process 2 coordinator'
);
mtuc10_assert(
    strpos($checkoutModel, 'MtUniCreditProcessTwoServiceFactory::coordinator') !== false,
    'wiring: checkout model injects Process 2 coordinator'
);
mtuc10_assert(
    strpos($lifecycleSrc, 'continueProcess2AfterCp') !== false,
    'wiring: lifecycle routes Process 2 after cp_created'
);

$transportSf = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportSf);
$stackSf = Phase9TestHarness::stack($transportSf, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$sfOrder = 10105;
$sfResult = $stackSf['storefront']->submit(Phase9TestHarness::productStorefrontInput($stackSf, $sfOrder));
mtuc10_assert(!empty($sfResult['success']), 'storefront Product P2: success');
mtuc10_assert(
    Phase9TestHarness::bankStatusId($stackSf, $sfOrder) === MtUniCreditBankStatus::SENT_PROCESS2,
    'storefront Product P2: bank_sent_process2'
);

// ---------------------------------------------------------------------------
// G. Diagnostic redactor still covers egn/phone2
// ---------------------------------------------------------------------------
$redactorSrc = mtuc10_read($lib . DIRECTORY_SEPARATOR . 'diagnostic_payload_redactor.php');
mtuc10_assert(strpos($redactorSrc, "'egn'") !== false, 'redactor: egn forbidden');
mtuc10_assert(strpos($redactorSrc, "'phone2'") !== false, 'redactor: phone2 forbidden');
$redacted = MtUniCreditDiagnosticPayloadRedactor::redact(array(
    'order_id' => 1,
    'egn' => '1990010112',
    'phone2' => '+35988123456',
    'nested' => array('clientEGN' => '1990010112'),
));
$redactedJson = json_encode($redacted);
mtuc10_assert(
    is_string($redactedJson) && strpos($redactedJson, '1990010112') === false,
    'redactor: EGN digits removed from diagnostic payload'
);

// ---------------------------------------------------------------------------
// H. Validator parity
// ---------------------------------------------------------------------------
$validator = new MtUniCreditStorefrontProcessTwoFieldValidator();
$okFields = $validator->validate(Phase9TestHarness::process2Fields());
mtuc10_assert(!empty($okFields['ok']), 'validator: valid EGN/phone2 accepted');
$badDate = $validator->validate(array('egn' => '1990139912', 'phone2' => '+35988'));
mtuc10_assert(empty($badDate['ok']), 'validator: invalid EGN date rejected');

echo PHP_EOL . 'Phase 10 checks: ' . $passes . ' passed';
if ($failures) {
    echo ', ' . count($failures) . ' failed' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    echo 'PHASE 10 PROCESS 2 / MAIL / PRIVACY STOP GATE: BLOCKED' . PHP_EOL;
    exit(1);
}

echo ', 0 failed' . PHP_EOL;
echo 'PHASE 10 PROCESS 2 / MAIL / PRIVACY STOP GATE: PASS — LOCAL' . PHP_EOL;
exit(0);
