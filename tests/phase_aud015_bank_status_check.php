<?php

/**
 * AUD-015 F01–F04 — Bank status vocabulary / transition / SmartUCF reject evidence.
 *
 * Run: php tests/phase_aud015_bank_status_check.php
 *
 * Anti-false-positive: must fail if production reverts to last-write-wins,
 * premature P2 persistence, empty-JSON remote_reject, or label-based presentation.
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
    mtuc_test_define_dir_storage('mtuc-aud015');
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
}
if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'oc_');
}

require_once $lib . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . '/support/phase4_harness.php';
require_once __DIR__ . '/support/phase5_harness.php';
require_once __DIR__ . '/support/phase7_harness.php';
require_once __DIR__ . '/support/phase9_harness.php';
require_once __DIR__ . '/support/recording_process_two_mailer.php';
require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

$failures = array();
$passes = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucAud015_assert($condition, $message)
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
 * @param string $path
 * @return string
 */
function mtucAud015_read($path)
{
    $body = @file_get_contents($path);

    return is_string($body) ? $body : '';
}

/**
 * @param Phase2MemoryDb $memoryDb
 * @param int $storeId
 * @param int $orderId
 * @return MtUniCreditOrderBankStatusRepository
 */
function mtucAud015_repo(Phase2MemoryDb $memoryDb, $storeId, $orderId)
{
    $memoryDb->seedOrder($orderId, $storeId, MtUniCreditConstants::EXTENSION_CODE);
    $db = new MtUniCreditDbAdapter($memoryDb, 'oc_');

    return new MtUniCreditOrderBankStatusRepository($db);
}

/**
 * @param MtUniCreditOrderBankStatusRepository $repo
 * @param int $storeId
 * @param int $orderId
 * @param string $statusId
 * @param string $label
 * @param string $source
 * @return array<string, mixed>|null
 */
function mtucAud015_write($repo, $storeId, $orderId, $statusId, $label, $source)
{
    return $repo->updateByOrderIdentifier(
        $storeId,
        (string) $orderId,
        $statusId,
        $label,
        $source
    );
}

$p2Src = mtucAud015_read($lib . '/process_two_lifecycle_coordinator.php');
$classifierSrc = mtucAud015_read($lib . '/smart_ucf_failure_classifier.php');
$clientSrc = mtucAud015_read($lib . '/smart_ucf_session_client.php');
$presenterSrc = mtucAud015_read($lib . '/financing_presentation_service.php');
$repoSrc = mtucAud015_read($lib . '/order_bank_status_repository.php');

mtucAud015_assert(
    is_file($lib . '/bank_status_transition_policy.php'),
    'F01: BankStatusTransitionPolicy file exists'
);
mtucAud015_assert(
    strpos($repoSrc, 'AND `status_id` IN (') !== false,
    'F01: repository uses atomic CAS UPDATE ... WHERE status_id IN'
);
mtucAud015_assert(
    strpos($repoSrc, 'ON DUPLICATE KEY UPDATE') !== false
        && strpos($repoSrc, 'last-write wins') === false,
    'F01: no unconditional last-write-wins documentation/path'
);
mtucAud015_assert(
    preg_match(
        '/updateOrderStatus\([\s\S]*?updateByOrderIdentifier/s',
        $p2Src
    ) === 1,
    'F02: CP updateOrderStatus precedes local updateByOrderIdentifier'
);
mtucAud015_assert(
    strpos($classifierSrc, 'Absence of success data is not affirmative rejection') !== false
        || strpos($classifierSrc, 'AUD-015 F03') !== false,
    'F03: classifier documents non-reject without evidence'
);
mtucAud015_assert(
    strpos($clientSrc, 'KIND_REMOTE') === false
        || strpos($clientSrc, 'detectFailureKind') !== false,
    'F03: client detectFailureKind present'
);
mtucAud015_assert(
    strpos($presenterSrc, 'findBankStatusId') !== false
        && strpos($presenterSrc, 'SEND_FAILED_SMARTUCF') !== false,
    'F04: presenter branches on status_id'
);

// ---------------------------------------------------------------------------
// F01 transitions (production repository)
// ---------------------------------------------------------------------------
$storeId = Phase5TestHarness::STORE_A;
$inbound = MtUniCreditBankStatusTransitionPolicy::SOURCE_INBOUND_CALLBACK;
$local = MtUniCreditBankStatusTransitionPolicy::SOURCE_LOCAL_LIFECYCLE;

$cases = array(
    array('P1→stale cp_sent', MtUniCreditBankStatus::SENT_PROCESS1, MtUniCreditBankStatus::CP_SENT, false),
    array('P2→stale smartucf_sent', MtUniCreditBankStatus::SENT_PROCESS2, MtUniCreditBankStatus::SMARTUCF_SENT, false),
    array('FS→stale cp_sent', MtUniCreditBankStatus::SEND_FAILED_SMARTUCF, MtUniCreditBankStatus::CP_SENT, false),
    array('FC→stale sent P1', MtUniCreditBankStatus::SEND_FAILED_CP, MtUniCreditBankStatus::SENT_PROCESS1, false),
    array('P1→P2 inbound', MtUniCreditBankStatus::SENT_PROCESS1, MtUniCreditBankStatus::SENT_PROCESS2, false),
    array('P2→P1 inbound', MtUniCreditBankStatus::SENT_PROCESS2, MtUniCreditBankStatus::SENT_PROCESS1, false),
    array('P1→stale FS', MtUniCreditBankStatus::SENT_PROCESS1, MtUniCreditBankStatus::SEND_FAILED_SMARTUCF, false),
    array('FS→stale P1', MtUniCreditBankStatus::SEND_FAILED_SMARTUCF, MtUniCreditBankStatus::SENT_PROCESS1, false),
);

$oid = 15001;
foreach ($cases as $i => $case) {
    $memory = new Phase2MemoryDb();
    $repo = mtucAud015_repo($memory, $storeId, $oid + $i);
    mtucAud015_write(
        $repo,
        $storeId,
        $oid + $i,
        $case[1],
        MtUniCreditBankStatus::resolveLabel($case[1], ''),
        $local
    );
    $after = mtucAud015_write(
        $repo,
        $storeId,
        $oid + $i,
        $case[2],
        MtUniCreditBankStatus::resolveLabel($case[2], 'stale'),
        $inbound
    );
    $row = $repo->findByOrderId($storeId, $oid + $i);
    mtucAud015_assert(
        $row !== null && (string) $row['status_id'] === $case[1] && empty($after['applied']),
        'F01 ' . $case[0] . ' blocked'
    );
}

// Same-status NO-OP
$memory = new Phase2MemoryDb();
$repo = mtucAud015_repo($memory, $storeId, 15100);
mtucAud015_write($repo, $storeId, 15100, MtUniCreditBankStatus::SENT_PROCESS1, 'x', $local);
$noop = mtucAud015_write(
    $repo,
    $storeId,
    15100,
    MtUniCreditBankStatus::SENT_PROCESS1,
    'wrong-label',
    $inbound
);
$row = $repo->findByOrderId($storeId, 15100);
mtucAud015_assert(
    $noop !== null
        && empty($noop['applied'])
        && (string) $row['status_id'] === MtUniCreditBankStatus::SENT_PROCESS1
        && (string) $row['status_label'] === MtUniCreditBankStatus::LABEL_SENT_PROCESS1,
    'F01 P1→P1 NO-OP + canonical label'
);

// Intermediate → durable local ALLOW
$memory = new Phase2MemoryDb();
$repo = mtucAud015_repo($memory, $storeId, 15101);
mtucAud015_write($repo, $storeId, 15101, MtUniCreditBankStatus::CP_SENT, '', $inbound);
$adv = mtucAud015_write(
    $repo,
    $storeId,
    15101,
    MtUniCreditBankStatus::SENT_PROCESS1,
    '',
    $local
);
$row = $repo->findByOrderId($storeId, 15101);
mtucAud015_assert(
    !empty($adv['applied']) && (string) $row['status_id'] === MtUniCreditBankStatus::SENT_PROCESS1,
    'F01 intermediate→P1 local ALLOW'
);

// Concurrent/stale CAS: durable P1 then stale cp_sent UPDATE matches 0 rows
$memory = new Phase2MemoryDb();
$repo = mtucAud015_repo($memory, $storeId, 15102);
mtucAud015_write($repo, $storeId, 15102, MtUniCreditBankStatus::SENT_PROCESS1, '', $local);
$db = new MtUniCreditDbAdapter($memory, 'oc_');
$table = 'oc_' . MtUniCreditPersistenceTableNames::ORDER_BANK_STATUS;
$db->query(
    "UPDATE `{$table}` SET"
        . " `status_id` = 'cp_sent',"
        . " `status_label` = 'x',"
        . " `updated_at` = '2020-01-01 00:00:00'"
        . " WHERE `store_id` = " . (int) $storeId
        . " AND `order_id` = 15102"
        . " AND `status_id` IN ('cp_sent','smartucf_sent','bank_send_failed')"
);
mtucAud015_assert((int) $db->countAffected() === 0, 'F01 concurrent CAS: stale UPDATE affected=0');
$row = $repo->findByOrderId($storeId, 15102);
mtucAud015_assert(
    (string) $row['status_id'] === MtUniCreditBankStatus::SENT_PROCESS1,
    'F01 concurrent CAS: P1 preserved'
);

// ---------------------------------------------------------------------------
// F02 P2 handoff ordering
// ---------------------------------------------------------------------------
$transportOk = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportOk);
$mailerOk = new MtUniCreditRecordingProcessTwoMailer();
$stackOk = Phase9TestHarness::stack(
    $transportOk,
    null,
    null,
    Phase5TestHarness::STORE_A,
    array('uni_proces' => 1)
);
$process2Ok = MtUniCreditProcessTwoServiceFactory::coordinator(
    $stackOk['db'],
    $stackOk['client'],
    $mailerOk,
    $stackOk['clock'],
    Phase4TestHarness::testSecretInput()
);
$refLife = new ReflectionClass($stackOk['lifecycle']);
$p2Prop = $refLife->getProperty('process2');
$p2Prop->setAccessible(true);
$p2Prop->setValue($stackOk['lifecycle'], $process2Ok);
$orderOk = 15201;
Phase9TestHarness::seedBankOrder($stackOk['memoryDb'], $orderOk, $stackOk['storeId']);
$resultOk = $stackOk['submission']->submit(Phase9TestHarness::submitInputProcess2($orderOk, $stackOk['storeId']));
mtucAud015_assert(!empty($resultOk['success']), 'F02 PATCH success: submit success');
mtucAud015_assert(
    Phase9TestHarness::bankStatusId($stackOk, $orderOk) === MtUniCreditBankStatus::SENT_PROCESS2,
    'F02 PATCH success: local bank_sent_process2'
);
$attemptOk = $stackOk['attempts']->findByStoreOrder($stackOk['storeId'], $orderOk);
$p2row = (new MtUniCreditProcessTwoLifecycleRepository($stackOk['db'], $stackOk['clock']))
    ->findByAttempt((int) $attemptOk['attempt_id']);
mtucAud015_assert(
    is_array($p2row) && (string) $p2row['process2_state'] === MtUniCreditProcessTwoLifecycleStates::PREPARED,
    'F02 PATCH success: prepared'
);

$transportFail = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportFail);
$transportFail->failStatusPatch = true;
$mailerFail = new MtUniCreditRecordingProcessTwoMailer();
$stackFail = Phase9TestHarness::stack(
    $transportFail,
    null,
    null,
    Phase5TestHarness::STORE_A,
    array('uni_proces' => 1)
);
$process2Fail = MtUniCreditProcessTwoServiceFactory::coordinator(
    $stackFail['db'],
    $stackFail['client'],
    $mailerFail,
    $stackFail['clock'],
    Phase4TestHarness::testSecretInput()
);
$refLife2 = new ReflectionClass($stackFail['lifecycle']);
$p2Prop2 = $refLife2->getProperty('process2');
$p2Prop2->setAccessible(true);
$p2Prop2->setValue($stackFail['lifecycle'], $process2Fail);
$orderFail = 15202;
Phase9TestHarness::seedBankOrder($stackFail['memoryDb'], $orderFail, $stackFail['storeId']);
$resultFail = $stackFail['submission']->submit(Phase9TestHarness::submitInputProcess2($orderFail, $stackFail['storeId']));
mtucAud015_assert(empty($resultFail['success']), 'F02 PATCH fail: submit not success');
mtucAud015_assert(
    Phase9TestHarness::bankStatusId($stackFail, $orderFail) === null,
    'F02 PATCH fail: local bank_sent_process2 NOT persisted'
);
mtucAud015_assert(count($mailerFail->sent) === 0, 'F02 PATCH fail: mail = 0');
$attemptFail = $stackFail['attempts']->findByStoreOrder($stackFail['storeId'], $orderFail);
$p2fail = (new MtUniCreditProcessTwoLifecycleRepository($stackFail['db'], $stackFail['clock']))
    ->findByAttempt((int) $attemptFail['attempt_id']);
mtucAud015_assert(
    is_array($p2fail) && (string) $p2fail['process2_state'] === MtUniCreditProcessTwoLifecycleStates::FAILED,
    'F02 PATCH fail: process2 failed'
);

// Mail failure after successful handoff keeps P2
$transportMail = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportMail);
$mailerBroken = new MtUniCreditRecordingProcessTwoMailer();
$mailerBroken->forceFailure = true;
$stackMail = Phase9TestHarness::stack(
    $transportMail,
    null,
    null,
    Phase5TestHarness::STORE_A,
    array('uni_proces' => 1)
);
$process2Mail = MtUniCreditProcessTwoServiceFactory::coordinator(
    $stackMail['db'],
    $stackMail['client'],
    $mailerBroken,
    $stackMail['clock'],
    Phase4TestHarness::testSecretInput()
);
$refLife3 = new ReflectionClass($stackMail['lifecycle']);
$p2Prop3 = $refLife3->getProperty('process2');
$p2Prop3->setAccessible(true);
$p2Prop3->setValue($stackMail['lifecycle'], $process2Mail);
$orderMail = 15203;
Phase9TestHarness::seedBankOrder($stackMail['memoryDb'], $orderMail, $stackMail['storeId']);
$resultMail = $stackMail['submission']->submit(Phase9TestHarness::submitInputProcess2($orderMail, $stackMail['storeId']));
mtucAud015_assert(!empty($resultMail['success']), 'F02 mail-fail: handoff still success');
mtucAud015_assert(
    Phase9TestHarness::bankStatusId($stackMail, $orderMail) === MtUniCreditBankStatus::SENT_PROCESS2,
    'F02 mail-fail: bank_sent_process2 remains'
);

// ---------------------------------------------------------------------------
// F03 SmartUCF classifier / client
// ---------------------------------------------------------------------------
$classifier = new MtUniCreditSmartUcfFailureClassifier();

$emptyEx = new MtUniCreditSmartUcfSessionException(
    'SmartUCF did not return a session identifier.',
    false,
    '{}',
    200,
    MtUniCreditSmartUcfSessionException::KIND_TRANSPORT
);
$cEmpty = $classifier->classifyThrowable($emptyEx);
mtucAud015_assert(
    $cEmpty->errorClass() !== MtUniCreditSmartUcfFailureClassification::CLASS_REMOTE_REJECT
        && $cEmpty->targetState() === MtUniCreditSmartUcfLifecycleStates::OUTCOME_UNKNOWN,
    'F03 {} → outcome_unknown / no FS'
);

$unexpectedEx = new MtUniCreditSmartUcfSessionException(
    'SmartUCF did not return a session identifier.',
    false,
    '{"unexpected":"value"}',
    200,
    MtUniCreditSmartUcfSessionException::KIND_TRANSPORT
);
$cUn = $classifier->classifyThrowable($unexpectedEx);
mtucAud015_assert(
    $cUn->errorClass() !== MtUniCreditSmartUcfFailureClassification::CLASS_REMOTE_REJECT,
    'F03 unexpected object → no FS'
);

$badJsonEx = new MtUniCreditSmartUcfSessionException(
    'SmartUCF returned invalid JSON.',
    false,
    'not-json',
    200,
    MtUniCreditSmartUcfSessionException::KIND_TRANSPORT
);
$cBad = $classifier->classifyThrowable($badJsonEx);
mtucAud015_assert(
    $cBad->targetState() === MtUniCreditSmartUcfLifecycleStates::OUTCOME_UNKNOWN,
    'F03 invalid JSON → outcome_unknown'
);

$rejectEx = new MtUniCreditSmartUcfSessionException(
    'rejected',
    false,
    Phase9TestHarness::rejectBody(),
    400,
    MtUniCreditSmartUcfSessionException::KIND_REMOTE
);
$cReject = $classifier->classifyThrowable($rejectEx);
mtucAud015_assert(
    $cReject->errorClass() === MtUniCreditSmartUcfFailureClassification::CLASS_REMOTE_REJECT,
    'F03 explicit reject → remote_reject'
);

// End-to-end: empty JSON must not persist FS
$transportEmpty = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportEmpty);
$stackEmpty = Phase9TestHarness::stack(
    $transportEmpty,
    function (array $options): array {
        return array('body' => '{}', 'error' => '', 'http_code' => 200);
    }
);
$orderEmpty = 15301;
Phase9TestHarness::seedBankOrder($stackEmpty['memoryDb'], $orderEmpty, $stackEmpty['storeId']);
$resultEmpty = $stackEmpty['submission']->submit(Phase9TestHarness::submitInput($orderEmpty, $stackEmpty['storeId']));
mtucAud015_assert(empty($resultEmpty['success']), 'F03 e2e {}: failure');
mtucAud015_assert(
    Phase9TestHarness::bankStatusId($stackEmpty, $orderEmpty) !== MtUniCreditBankStatus::SEND_FAILED_SMARTUCF,
    'F03 e2e {}: no FS bank status'
);

$transportRej = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportRej);
$stackRej = Phase9TestHarness::stack(
    $transportRej,
    function (array $options): array {
        return array(
            'body' => Phase9TestHarness::rejectBody(),
            'error' => '',
            'http_code' => 400,
        );
    }
);
$orderRej = 15302;
Phase9TestHarness::seedBankOrder($stackRej['memoryDb'], $orderRej, $stackRej['storeId']);
$resultRej = $stackRej['submission']->submit(Phase9TestHarness::submitInput($orderRej, $stackRej['storeId']));
mtucAud015_assert(
    Phase9TestHarness::bankStatusId($stackRej, $orderRej) === MtUniCreditBankStatus::SEND_FAILED_SMARTUCF,
    'F03 e2e reject → FS'
);

$transportP1 = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportP1);
$stackP1 = Phase9TestHarness::stack(
    $transportP1,
    function (array $options): array {
        return array(
            'body' => Phase9TestHarness::successBody(),
            'error' => '',
            'http_code' => 200,
        );
    }
);
$orderP1 = 15303;
Phase9TestHarness::seedBankOrder($stackP1['memoryDb'], $orderP1, $stackP1['storeId']);
$resultP1 = $stackP1['submission']->submit(Phase9TestHarness::submitInput($orderP1, $stackP1['storeId']));
mtucAud015_assert(
    !empty($resultP1['success'])
        && Phase9TestHarness::bankStatusId($stackP1, $orderP1) === MtUniCreditBankStatus::SENT_PROCESS1,
    'F03 e2e success → P1'
);

// ---------------------------------------------------------------------------
// F04 canonical labels + presenter by status_id
// ---------------------------------------------------------------------------
$memory = new Phase2MemoryDb();
$repo = mtucAud015_repo($memory, $storeId, 15401);
$mismatch = mtucAud015_write(
    $repo,
    $storeId,
    15401,
    MtUniCreditBankStatus::SEND_FAILED_SMARTUCF,
    MtUniCreditBankStatus::LABEL_SEND_FAILED_CP,
    $inbound
);
$row = $repo->findByOrderId($storeId, 15401);
mtucAud015_assert(
    (string) $row['status_id'] === MtUniCreditBankStatus::SEND_FAILED_SMARTUCF
        && (string) $row['status_label'] === MtUniCreditBankStatus::LABEL_SEND_FAILED_SMARTUCF,
    'F04 FS + wrong KP label → canonical SmartUCF label'
);

$adapter = new MtUniCreditDbAdapter($memory, 'oc_');
$presRepo = new MtUniCreditFinancingPresentationRepository($adapter);
$svc = new MtUniCreditFinancingPresentationService($presRepo);
$ty = $svc->renderCustomerThankYouHtml($svc->customerThankYouRows($storeId, 15401));
mtucAud015_assert(
    strpos($ty, MtUniCreditFinancingLeasingPresenter::SMARTUCF_TERMINAL_FAILURE_MESSAGE) !== false
        && strpos($ty, MtUniCreditFinancingLeasingPresenter::CP_TERMINAL_FAILURE_MESSAGE) === false,
    'F04 presenter: SmartUCF failure by status_id'
);

$memory2 = new Phase2MemoryDb();
$repo2 = mtucAud015_repo($memory2, $storeId, 15402);
mtucAud015_write(
    $repo2,
    $storeId,
    15402,
    MtUniCreditBankStatus::SEND_FAILED_CP,
    MtUniCreditBankStatus::LABEL_SEND_FAILED_SMARTUCF,
    $inbound
);
$row2 = $repo2->findByOrderId($storeId, 15402);
mtucAud015_assert(
    (string) $row2['status_label'] === MtUniCreditBankStatus::LABEL_SEND_FAILED_CP,
    'F04 FC + wrong Smart label → canonical CP label'
);
$adapter2 = new MtUniCreditDbAdapter($memory2, 'oc_');
$svc2 = new MtUniCreditFinancingPresentationService(new MtUniCreditFinancingPresentationRepository($adapter2));
$ty2 = $svc2->renderCustomerThankYouHtml($svc2->customerThankYouRows($storeId, 15402));
mtucAud015_assert(
    strpos($ty2, 'потвърждението за регистрацията') !== false
        && strpos($ty2, MtUniCreditBankStatus::LABEL_SEND_FAILED_CP) !== false
        && strpos($ty2, MtUniCreditFinancingLeasingPresenter::SMARTUCF_TERMINAL_FAILURE_MESSAGE) === false,
    'F04 presenter: CP failure by status_id'
);

$memory3 = new Phase2MemoryDb();
$repo3 = mtucAud015_repo($memory3, $storeId, 15403);
mtucAud015_write($repo3, $storeId, 15403, MtUniCreditBankStatus::SENT_PROCESS1, '', $inbound);
$row3 = $repo3->findByOrderId($storeId, 15403);
mtucAud015_assert(
    (string) $row3['status_label'] === MtUniCreditBankStatus::LABEL_SENT_PROCESS1,
    'F04 missing label → server canonical'
);

// Numeric external label display-only
mtucAud015_assert(
    MtUniCreditBankStatus::canonicalLabel('42') === null,
    'F04 numeric: no canonical label authority'
);
$memory4 = new Phase2MemoryDb();
$repo4 = mtucAud015_repo($memory4, $storeId, 15404);
mtucAud015_write($repo4, $storeId, 15404, '42', 'External display', $inbound);
$row4 = $repo4->findByOrderId($storeId, 15404);
mtucAud015_assert(
    (string) $row4['status_id'] === '42' && (string) $row4['status_label'] === 'External display',
    'F04 numeric keeps display label'
);

// Multishop: store A cannot update store B order
$memoryMs = new Phase2MemoryDb();
$memoryMs->seedOrder(15405, Phase5TestHarness::STORE_B, MtUniCreditConstants::EXTENSION_CODE);
$dbMs = new MtUniCreditDbAdapter($memoryMs, 'oc_');
$repoMs = new MtUniCreditOrderBankStatusRepository($dbMs);
$cross = $repoMs->updateByOrderIdentifier(
    Phase5TestHarness::STORE_A,
    '15405',
    MtUniCreditBankStatus::CP_SENT,
    '',
    $inbound
);
mtucAud015_assert($cross === null, 'multishop: cross-store ownership rejected');

echo PHP_EOL;
if ($failures === array()) {
    echo 'RESULT  PASS (' . $passes . ' assertions)' . PHP_EOL;
    exit(0);
}

echo 'RESULT  FAIL (' . count($failures) . ' failed / ' . $passes . ' passed)' . PHP_EOL;
foreach ($failures as $f) {
    echo '  - ' . $f . PHP_EOL;
}
exit(1);
