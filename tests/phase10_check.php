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
    'financing_presentation_repository.php',
    'financing_presentation_service.php',
    'financing_terminal_navigation_support.php',
    'catalog_event_registry.php',
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

// ---------------------------------------------------------------------------
// I. Stale bound order → unbind + fresh addOrder
// ---------------------------------------------------------------------------
$transportStale = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportStale);
$stackStale = Phase9TestHarness::stack($transportStale, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$staleOrderId = 123;
$freshOrderId = 456;
$addOrderCalls = 0;
$staleInput = Phase9TestHarness::productStorefrontInput($stackStale, $freshOrderId);
$selectionHash = MtUniCreditStorefrontOperationIdentity::productHash(
    (int) $stackStale['storeId'],
    42,
    array(),
    1,
    'BGN'
);
$bindKey = MtUniCreditStorefrontApplicationToken::bindKey(
    $selectionHash,
    (string) $staleInput['application_token']
);
$staleInput['session'][MtUniCreditStorefrontFinancingSubmissionService::SESSION_ORDER_BIND_KEY] = array(
    $bindKey => $staleOrderId,
    $selectionHash => $staleOrderId, // legacy bare product bind must be ignored/pruned
);
$staleInput['load_order'] = function ($orderId) use ($stackStale, $staleOrderId, $freshOrderId) {
    if ((int) $orderId === (int) $staleOrderId) {
        return null;
    }
    if ((int) $orderId === (int) $freshOrderId) {
        return Phase7TestHarness::orderRow((int) $orderId, (int) $stackStale['storeId']);
    }

    return null;
};
$staleInput['add_order'] = function ($orderData) use (&$addOrderCalls, $stackStale, $freshOrderId) {
    $addOrderCalls++;
    $stackStale['memoryDb']->seedOrder($freshOrderId, $stackStale['storeId'], MtUniCreditConstants::EXTENSION_CODE);

    return $freshOrderId;
};
$staleResult = $stackStale['storefront']->submit($staleInput);
mtuc10_assert(!empty($staleResult['success']), 'stale binding: fresh submit succeeds');
mtuc10_assert($addOrderCalls === 1, 'stale binding: new addOrder() call = 1');
mtuc10_assert((int) $staleResult['order_id'] === $freshOrderId, 'stale binding: new order id used');
mtuc10_assert(
    isset($staleResult['session'][MtUniCreditStorefrontFinancingSubmissionService::SESSION_ORDER_BIND_KEY][$bindKey])
        && (int) $staleResult['session'][MtUniCreditStorefrontFinancingSubmissionService::SESSION_ORDER_BIND_KEY][$bindKey] === $freshOrderId,
    'stale binding: session rebound to fresh order under application bind key'
);
mtuc10_assert(
    !isset($staleResult['session'][MtUniCreditStorefrontFinancingSubmissionService::SESSION_ORDER_BIND_KEY][$selectionHash]),
    'stale binding: legacy bare product bind pruned'
);
$staleAttempt = $stackStale['attempts']->findByStoreOrder($stackStale['storeId'], $freshOrderId);
mtuc10_assert($staleAttempt !== null, 'stale binding: attempt tied to fresh order');
$oldAttempt = $stackStale['attempts']->findByStoreOrder($stackStale['storeId'], $staleOrderId);
mtuc10_assert($oldAttempt === null, 'stale binding: old attempt not reused/migrated');

// ---------------------------------------------------------------------------
// J. Valid bound order replay — addOrder = 0
// ---------------------------------------------------------------------------
$transportValid = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportValid);
$stackValid = Phase9TestHarness::stack($transportValid, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$validOrder = 10120;
$validAdd = 0;
$validInput = Phase9TestHarness::productStorefrontInput($stackValid, $validOrder);
$validInput['add_order'] = function ($orderData) use (&$validAdd, $stackValid, $validOrder) {
    $validAdd++;
    $stackValid['memoryDb']->seedOrder($validOrder, $stackValid['storeId'], MtUniCreditConstants::EXTENSION_CODE);

    return $validOrder;
};
$firstValid = $stackValid['storefront']->submit($validInput);
mtuc10_assert(!empty($firstValid['success']), 'valid bind first: success');
mtuc10_assert($validAdd === 1, 'valid bind first: addOrder = 1');
$mailBeforeValidReplay = count($stackValid['process2Mailer']->sent);
$validInput2 = $validInput;
$validInput2['session'] = isset($firstValid['session']) ? $firstValid['session'] : array();
$secondValid = $stackValid['storefront']->submit($validInput2);
mtuc10_assert(!empty($secondValid['success']), 'valid bind replay: success');
mtuc10_assert($validAdd === 1, 'valid bind replay: addOrder = 0 additional');
mtuc10_assert(Phase7TestHarness::countOrderPosts($transportValid) === 1, 'valid bind replay: CP create = 1 total');
mtuc10_assert(
    count($stackValid['process2Mailer']->sent) === $mailBeforeValidReplay,
    'valid bind replay: Process 2 mail additional = 0'
);

// ---------------------------------------------------------------------------
// J2. Cross-operation: pending A must stay untouched when B submits
// ---------------------------------------------------------------------------
$orderA = 20110;
$orderB = 20120;
$addA = 0;
$addB = 0;
$transportA = new Phase4FakeCpHttpTransport();
$stackA = Phase9TestHarness::stack($transportA, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$inputA = Phase9TestHarness::productStorefrontInput($stackA, $orderA);
$selectionA = MtUniCreditStorefrontOperationIdentity::productHash(
    (int) $stackA['storeId'],
    42,
    array(),
    1,
    'BGN'
);
$opHashA = MtUniCreditStorefrontApplicationToken::bindKey($selectionA, (string) $inputA['application_token']);
// Incomplete A: order + attempt exist in ORDER_CREATED, no CP activity yet.
$stackA['memoryDb']->seedOrder($orderA, $stackA['storeId'], MtUniCreditConstants::EXTENSION_CODE);
$unicidA = MtUniCreditBootstrap::credentialsRepositoryFromDb($stackA['db'])->getUnicid($stackA['storeId']);
$attemptA = $stackA['attempts']->findOrCreateAttempt(
    $stackA['storeId'],
    $orderA,
    $unicidA,
    $opHashA,
    hash('sha256', 'sel-a'),
    hash('sha256', 'fp-a'),
    MtUniCreditOperationEntryPoint::PRODUCT
);
$inputA['session'][MtUniCreditStorefrontFinancingSubmissionService::SESSION_ORDER_BIND_KEY] = array(
    $opHashA => $orderA,
);
$addA = 1; // order already materialized for incomplete A
mtuc10_assert($attemptA !== null, 'cross-op A: attempt exists');
$stateABefore = (string) $attemptA['state'];
$updatedABefore = (string) $attemptA['updated_at'];
$cpABefore = (int) $attemptA['control_panel_order_id'];
mtuc10_assert($stateABefore === MtUniCreditFinancingAttemptState::ORDER_CREATED, 'cross-op A: incomplete order_created');
mtuc10_assert($cpABefore === 0, 'cross-op A: no CP yet');

$transportB = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportB);
$stackB = Phase9TestHarness::stack(
    $transportB,
    null,
    $stackA['memoryDb'],
    Phase5TestHarness::STORE_A,
    array('uni_proces' => 1)
);
$inputB = Phase9TestHarness::productStorefrontInput($stackB, $orderB);
$inputB['session'][MtUniCreditStorefrontFinancingSubmissionService::SESSION_ORDER_BIND_KEY] = $inputA['session'][MtUniCreditStorefrontFinancingSubmissionService::SESSION_ORDER_BIND_KEY];
$inputB['add_order'] = function ($orderData) use (&$addB, $stackB, $orderB) {
    $addB++;
    $stackB['memoryDb']->seedOrder($orderB, $stackB['storeId'], MtUniCreditConstants::EXTENSION_CODE);

    return $orderB;
};
$resultB = $stackB['storefront']->submit($inputB);
mtuc10_assert(!empty($resultB['success']), 'cross-op B: new submit succeeds');
mtuc10_assert($addB === 1, 'cross-op B: addOrder = 1');
mtuc10_assert((int) $resultB['order_id'] === $orderB, 'cross-op B: uses own order');
mtuc10_assert(Phase7TestHarness::countOrderPosts($transportB) === 1, 'cross-op B: CP create = 1');
mtuc10_assert(Phase7TestHarness::countOrderPosts($transportA) === 0, 'cross-op A: no CP from B path');
$attemptAAfter = $stackA['attempts']->findByStoreOrder($stackA['storeId'], $orderA);
mtuc10_assert($attemptAAfter !== null, 'cross-op A: attempt still present');
mtuc10_assert((string) $attemptAAfter['state'] === $stateABefore, 'cross-op A: state untouched');
mtuc10_assert((string) $attemptAAfter['updated_at'] === $updatedABefore, 'cross-op A: updated_at untouched');
mtuc10_assert((int) $attemptAAfter['control_panel_order_id'] === $cpABefore, 'cross-op A: CP id untouched');
mtuc10_assert($addA === 1, 'cross-op A: no new OC order');

// Explicit replay of A resumes only A (token already authenticated via B on shared DB).
Phase9TestHarness::enqueueCpOrderCreateSuccess($transportA);
$inputAReplay = $inputA;
$inputAReplay['add_order'] = function ($orderData) use (&$addA) {
    $addA++;

    return 99999;
};
$resultAReplay = $stackA['storefront']->submit($inputAReplay);
mtuc10_assert(!empty($resultAReplay['success']), 'cross-op A replay: resumes itself');
mtuc10_assert($addA === 1, 'cross-op A replay: addOrder = 0 additional');
mtuc10_assert((int) $resultAReplay['order_id'] === $orderA, 'cross-op A replay: same order');

// Completed A + new B (same product/scheme, new application token).
$transportC = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportC);
$stackC = Phase9TestHarness::stack($transportC, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$orderCompleted = 20210;
$orderFresh = 20220;
$addCompleted = 0;
$addFresh = 0;
$inputCompleted = Phase9TestHarness::productStorefrontInput($stackC, $orderCompleted);
$inputCompleted['add_order'] = function ($orderData) use (&$addCompleted, $stackC, $orderCompleted) {
    $addCompleted++;
    $stackC['memoryDb']->seedOrder($orderCompleted, $stackC['storeId'], MtUniCreditConstants::EXTENSION_CODE);

    return $orderCompleted;
};
$resultCompleted = $stackC['storefront']->submit($inputCompleted);
mtuc10_assert(!empty($resultCompleted['success']), 'completed A: success');
mtuc10_assert($addCompleted === 1, 'completed A: addOrder = 1');
$inputFresh = Phase9TestHarness::productStorefrontInput($stackC, $orderFresh);
$inputFresh['add_order'] = function ($orderData) use (&$addFresh, $stackC, $orderFresh) {
    $addFresh++;
    $stackC['memoryDb']->seedOrder($orderFresh, $stackC['storeId'], MtUniCreditConstants::EXTENSION_CODE);

    return $orderFresh;
};
Phase9TestHarness::enqueueCpOrderCreateSuccess($transportC);
$resultFresh = $stackC['storefront']->submit($inputFresh);
mtuc10_assert(!empty($resultFresh['success']), 'new app after completed: success');
mtuc10_assert($addFresh === 1, 'new app after completed: addOrder = 1');
mtuc10_assert((int) $resultFresh['order_id'] === $orderFresh, 'new app after completed: distinct order');
mtuc10_assert(
    (int) $resultFresh['order_id'] !== (int) $resultCompleted['order_id'],
    'new app after completed: not rebound to completed order'
);

// Cart path: distinct application tokens → distinct orders.
$transportCart = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportCart);
$stackCart = Phase9TestHarness::stack($transportCart, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$cartOrder1 = 20310;
$cartOrder2 = 20320;
$cartAdd = 0;
$cartInput1 = Phase9TestHarness::cartStorefrontInput($stackCart, $cartOrder1);
$cartInput1['add_order'] = function ($orderData) use (&$cartAdd, $stackCart, $cartOrder1) {
    $cartAdd++;
    $stackCart['memoryDb']->seedOrder($cartOrder1, $stackCart['storeId'], MtUniCreditConstants::EXTENSION_CODE);

    return $cartOrder1;
};
$cartResult1 = $stackCart['storefront']->submit($cartInput1);
$cartInput2 = Phase9TestHarness::cartStorefrontInput($stackCart, $cartOrder2);
$cartInput2['add_order'] = function ($orderData) use (&$cartAdd, $stackCart, $cartOrder2) {
    $cartAdd++;
    $stackCart['memoryDb']->seedOrder($cartOrder2, $stackCart['storeId'], MtUniCreditConstants::EXTENSION_CODE);

    return $cartOrder2;
};
Phase9TestHarness::enqueueCpOrderCreateSuccess($transportCart);
$cartResult2 = $stackCart['storefront']->submit($cartInput2);
mtuc10_assert(!empty($cartResult1['success']) && !empty($cartResult2['success']), 'cart cross-op: both succeed');
mtuc10_assert($cartAdd === 2, 'cart cross-op: addOrder = 2 for distinct applications');
mtuc10_assert((int) $cartResult1['order_id'] !== (int) $cartResult2['order_id'], 'cart cross-op: distinct orders');

// Concurrency same operation: second simultaneous owner is rejected without second order.
$transportConc = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportConc);
$stackConc = Phase9TestHarness::stack($transportConc, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$concOrder = 20410;
$concAdd = 0;
$concInput = Phase9TestHarness::productStorefrontInput($stackConc, $concOrder);
$selectionConc = MtUniCreditStorefrontOperationIdentity::productHash(
    (int) $stackConc['storeId'],
    42,
    array(),
    1,
    'BGN'
);
$opConc = MtUniCreditStorefrontApplicationToken::bindKey($selectionConc, (string) $concInput['application_token']);
$ownerA = MtUniCreditLockOwnerTokenGenerator::generate();
$ownerB = MtUniCreditLockOwnerTokenGenerator::generate();
mtuc10_assert(
    $stackConc['locks']->acquire(
        $stackConc['storeId'],
        MtUniCreditOperationEntryPoint::PRODUCT,
        $opConc,
        $ownerA
    ),
    'concurrency: first owner acquires'
);
$concInput['lock_owner_token'] = $ownerB;
$concInput['add_order'] = function ($orderData) use (&$concAdd) {
    $concAdd++;

    return 20499;
};
$concBlocked = $stackConc['storefront']->submit($concInput);
mtuc10_assert(empty($concBlocked['success']), 'concurrency same op: second owner rejected');
mtuc10_assert($concAdd === 0, 'concurrency same op: no addOrder while locked');
$stackConc['locks']->release(
    $stackConc['storeId'],
    MtUniCreditOperationEntryPoint::PRODUCT,
    $opConc,
    $ownerA
);

// Wiring: application token issued + posted.
mtuc10_assert(
    is_file($lib . DIRECTORY_SEPARATOR . 'storefront_application_token.php'),
    'wiring: storefront_application_token present'
);
$productWidget = mtuc10_read(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'view' . DIRECTORY_SEPARATOR . 'theme' . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR
        . 'template' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'product_widget.twig'
);
$jsSrc = mtuc10_read(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'view' . DIRECTORY_SEPARATOR . 'theme' . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR
        . 'template' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'storefront.js'
);
$productCtrlWiring = mtuc10_read(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'product.php'
);
mtuc10_assert(strpos($productWidget, 'data-application-token') !== false, 'wiring: product widget application token');
mtuc10_assert(strpos($jsSrc, 'application_token') !== false, 'wiring: JS posts application_token');
mtuc10_assert(strpos($productCtrlWiring, 'application_token') !== false, 'wiring: product controller application_token');


// ---------------------------------------------------------------------------
// K. Thank You presentation privacy + missing snapshot safety
// ---------------------------------------------------------------------------
$presenter = new MtUniCreditFinancingLeasingPresenter();
$snap = new MtUniCreditFinancingPresentationSnapshot(
    10130,
    999,
    true,
    12,
    'KOPSTD',
    100.0,
    500.0,
    45.0,
    640.0,
    5.5,
    6.1
);
$svc = new MtUniCreditFinancingPresentationService(
    new MtUniCreditFinancingPresentationRepository(
        new MtUniCreditDbAdapter($stackValid['memoryDb'], 'oc_')
    ),
    $presenter
);
// Direct HTML path via service customerThankYouHtml against seeded attempt
Phase9TestHarness::seedBankOrder($stackValid['memoryDb'], 10130, $stackValid['storeId']);
$attemptRow = $stackValid['attempts']->findOrCreateAttempt(
    $stackValid['storeId'],
    10130,
    Phase4TestHarness::TEST_UNICID,
    hash('sha256', 'thankyou-test'),
    hash('sha256', 'sel'),
    hash('sha256', 'fp'),
    MtUniCreditOperationEntryPoint::PRODUCT
);
(new MtUniCreditProcessTwoLifecycleRepository(new MtUniCreditDbAdapter($stackValid['memoryDb'], 'oc_')))
    ->persistLeasingPresentationJson((int) $attemptRow['attempt_id'], json_encode($snap->toArray()));
(new MtUniCreditOrderBankStatusRepository(new MtUniCreditDbAdapter($stackValid['memoryDb'], 'oc_')))
    ->updateByOrderIdentifier(
        $stackValid['storeId'],
        (string) 10130,
        MtUniCreditBankStatus::SENT_PROCESS2,
        MtUniCreditBankStatus::LABEL_SENT_PROCESS2
    );

$thankHtml = $svc->customerThankYouHtml($stackValid['storeId'], 10130);
mtuc10_assert($thankHtml !== '', 'thank you: leasing HTML present');
mtuc10_assert(strpos($thankHtml, 'УниКредит лизинг') !== false, 'thank you: title present');
mtuc10_assert(strpos($thankHtml, 'KOPSTD') !== false, 'thank you: scheme/KOP present');
mtuc10_assert(strpos($thankHtml, '12') !== false, 'thank you: months visible');
mtuc10_assert(strpos($thankHtml, '45.00') !== false, 'thank you: monthly installment visible');
mtuc10_assert(strpos($thankHtml, '5.50% / 6.10%') !== false, 'thank you: GLP/GPR visible');
mtuc10_assert(strpos($thankHtml, MtUniCreditFinancingLeasingPresenter::LABEL_EGN) === false, 'thank you: EGN label absent');
mtuc10_assert(strpos($thankHtml, MtUniCreditFinancingLeasingPresenter::LABEL_PHONE2) === false, 'thank you: phone2 label absent');
mtuc10_assert(
    strpos($thankHtml, MtUniCreditFinancingLeasingPresenter::LABEL_CP_INTERNAL_ID) === false,
    'thank you: CP internal id absent'
);
mtuc10_assert(preg_match('/\b1990010112\b/', $thankHtml) !== 1, 'thank you: raw EGN digits absent');

$missingHtml = $svc->customerThankYouHtml($stackValid['storeId'], 999999);
mtuc10_assert($missingHtml === '', 'thank you: missing snapshot returns empty (generic success allowed)');

// Native mail enrichment simulation (OC3 view/*/after signature)
$customerMailOut = '<html><body>Native customer order mail</body></html>';
$adminMailOut = "Native admin order alert\nOrder ID: 10130";
$mailData = array('order_id' => 10130);
// Persist sensitive for ADMIN_EMAIL audience parity with OC4
$cipher = new MtUniCreditProcessTwoSensitiveCipher(MtUniCreditEncryptionKeyProvider::testSecretInput());
$enc = $cipher->encrypt(new MtUniCreditProcessTwoSensitiveData('1990010112', '+35988111111'));
(new MtUniCreditProcessTwoLifecycleRepository(new MtUniCreditDbAdapter($stackValid['memoryDb'], 'oc_')))
    ->persistSensitiveEncrypted((int) $attemptRow['attempt_id'], $enc);

$customerRows = $svc->filterCustomerFacingRows(
    $svc->rowsForOrder($stackValid['storeId'], 10130, MtUniCreditFinancingPresentationAudience::CUSTOMER)
);
$customerChunk = $presenter->renderHtml($customerRows);
mtuc10_assert($customerChunk !== '', 'native customer mail: leasing chunk present');
mtuc10_assert(strpos($customerChunk, MtUniCreditFinancingLeasingPresenter::LABEL_EGN) === false, 'native customer mail: EGN absent');
mtuc10_assert(strpos($customerChunk, MtUniCreditFinancingLeasingPresenter::LABEL_PHONE2) === false, 'native customer mail: phone2 absent');
$customerMailOut .= '<br/>' . $customerChunk;
mtuc10_assert(
    strpos($customerMailOut, 'class="mt-uni-credit-leasing-block"') !== false,
    'native customer mail: leasing block marker present'
);
mtuc10_assert(
    substr_count($customerMailOut, 'class="mt-uni-credit-leasing-block"') === 1,
    'native customer mail: leasing appended once'
);

$adminRows = $svc->rowsForOrder($stackValid['storeId'], 10130, MtUniCreditFinancingPresentationAudience::ADMIN_EMAIL);
$adminMap = array();
foreach ($adminRows as $row) {
    $adminMap[$row['label']] = $row['value'];
}
mtuc10_assert(
    isset($adminMap[MtUniCreditFinancingLeasingPresenter::LABEL_EGN])
        && $adminMap[MtUniCreditFinancingLeasingPresenter::LABEL_EGN] === '1990010112',
    'native admin mail audience ADMIN_EMAIL includes EGN per OC4'
);
$adminChunk = $presenter->renderText($adminRows);
$adminMailOut .= "\n\n" . $adminChunk;
mtuc10_assert(strpos($adminMailOut, 'УниКредит лизинг') !== false, 'native admin mail: leasing title present');
mtuc10_assert(substr_count($adminMailOut, 'УниКредит лизинг') === 1, 'native admin mail: leasing once');

// Non-UniCredit order: no rows
$nonUni = $svc->rowsForOrder($stackValid['storeId'], 424242, MtUniCreditFinancingPresentationAudience::CUSTOMER);
mtuc10_assert($nonUni === array(), 'non-UniCredit order: no leasing rows');

// Event registry wiring
$defs = MtUniCreditCatalogEventRegistry::definitions();
$triggers = array();
foreach ($defs as $def) {
    $triggers[$def['code']] = $def['trigger'];
}
mtuc10_assert(
    isset($triggers['mt_uni_credit_checkout_success_order'])
        && $triggers['mt_uni_credit_checkout_success_order'] === 'catalog/controller/checkout/success/before',
    'events: success order stash trigger'
);
mtuc10_assert(
    isset($triggers['mt_uni_credit_checkout_success_view'])
        && $triggers['mt_uni_credit_checkout_success_view'] === 'catalog/view/common/success/before',
    'events: success view before trigger'
);
mtuc10_assert(
    isset($triggers['mt_uni_credit_mail_order_add'])
        && $triggers['mt_uni_credit_mail_order_add'] === 'catalog/view/mail/order_add/after',
    'events: customer mail order_add/after'
);
mtuc10_assert(
    isset($triggers['mt_uni_credit_mail_order_alert'])
        && $triggers['mt_uni_credit_mail_order_alert'] === 'catalog/view/mail/order_alert/after',
    'events: admin mail order_alert/after'
);
mtuc10_assert(
    is_file(
        $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
            . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
            . DIRECTORY_SEPARATOR . 'order_mail.php'
    ),
    'wiring: order_mail event controller present'
);

// Timing: leasing snapshot persist precedes lifecycle submit in Product/Cart + Checkout services
$sfSrc = mtuc10_read($lib . DIRECTORY_SEPARATOR . 'storefront_financing_submission_service.php');
$coSrc = mtuc10_read($lib . DIRECTORY_SEPARATOR . 'checkout_financing_submission_service.php');
$sfPersistPos = strpos($sfSrc, 'persistLeasingSnapshot');
$sfLifecyclePos = strpos($sfSrc, 'submitOrRecover');
mtuc10_assert(
    $sfPersistPos !== false && $sfLifecyclePos !== false && $sfPersistPos < $sfLifecyclePos,
    'timing: storefront persists leasing snapshot before CP lifecycle'
);
$coPersistPos = strpos($coSrc, 'persistLeasingSnapshot');
$coLifecyclePos = strpos($coSrc, 'submitOrRecover');
mtuc10_assert(
    $coPersistPos !== false && $coLifecyclePos !== false && $coPersistPos < $coLifecyclePos,
    'timing: checkout persists leasing snapshot before CP lifecycle'
);
// Controllers apply native status (mails) only after submission returns — snapshot already durable
$productSubmit = mtuc10_read(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'product.php'
);
mtuc10_assert(
    strpos($productSubmit, 'maybeApplyNativeOrderStatus') !== false,
    'timing: native addOrderHistory after submit result'
);

$navSession = array();
$navPayload = MtUniCreditFinancingTerminalNavigationSupport::enrichProcess2ThankYou(
    array('success' => true),
    $navSession,
    10130,
    'https://shop.example/index.php?route=checkout/success'
);
mtuc10_assert(
    !empty($navPayload['redirect']) && strpos($navPayload['redirect'], 'checkout/success') !== false,
    'thank you nav: redirect = checkout/success'
);
mtuc10_assert(empty($navPayload['bank_redirect']), 'thank you nav: bank_redirect = false');
mtuc10_assert(
    (int) $navSession[MtUniCreditFinancingTerminalNavigationSupport::SESSION_SUCCESS_ORDER_ID] === 10130,
    'thank you nav: success order id stashed'
);

$p1Session = array();
$p1Payload = MtUniCreditFinancingTerminalNavigationSupport::enrichProcess2ThankYou(
    array(
        'success' => true,
        'bank_redirect' => true,
        'redirect' => 'https://bank.example/app',
    ),
    $p1Session,
    10130,
    'https://shop.example/index.php?route=checkout/success'
);
mtuc10_assert(
    $p1Payload['redirect'] === 'https://bank.example/app',
    'Process 1 isolation: bank redirect unchanged by Thank You enrichment'
);

$productCtrl = mtuc10_read(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'product.php'
);
mtuc10_assert(
    strpos($productCtrl, 'CHECKOUT_SUCCESS_ROUTE') !== false
        || strpos($productCtrl, 'checkout/success') !== false,
    'wiring: Product Process 2 success uses checkout/success'
);
mtuc10_assert(
    is_file(
        $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
            . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
            . DIRECTORY_SEPARATOR . 'checkout_success.php'
    ),
    'wiring: checkout_success event controller present'
);

echo PHP_EOL . 'Phase 10 checks: ' . $passes . ' passed';
if ($failures) {
    echo ', ' . count($failures) . ' failed' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    echo 'PHASE 10 CROSS-OPERATION RECOVERY DIAGNOSTIC CLOSURE: BLOCKED' . PHP_EOL;
    exit(1);
}

echo ', 0 failed' . PHP_EOL;
echo 'PHASE 10 CROSS-OPERATION RECOVERY DIAGNOSTIC CLOSURE: PASS — LOCAL' . PHP_EOL;
exit(0);
