<?php

/**
 * AUD-012 F01–F04 — Process 2 durable lifecycle (bank reconcile, preparing claim, recipient mail).
 * Run: php tests/phase_aud012_process2_lifecycle_check.php
 *
 * PHP 7.3 compatible. Offline. Production lifecycle/repository paths only.
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
    mtuc_test_define_dir_storage('mtuc-aud012');
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
function mtucAud012_assert($condition, $message)
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
 * Bank status port that can refuse durable local writes.
 */
final class MtUniCreditAud012FailClosedBankStatuses
{
    /** @var MtUniCreditOrderBankStatusRepository */
    private $inner;

    /** @var bool */
    public $failLocal = false;

    /**
     * @param MtUniCreditOrderBankStatusRepository $inner
     */
    public function __construct(MtUniCreditOrderBankStatusRepository $inner)
    {
        $this->inner = $inner;
    }

    /**
     * @param int $storeId
     * @param string $orderReference
     * @param string $statusId
     * @param string $statusLabel
     * @return array<string, mixed>|null
     */
    public function updateByOrderIdentifier($storeId, $orderReference, $statusId, $statusLabel)
    {
        if ($this->failLocal) {
            return null;
        }

        return $this->inner->updateByOrderIdentifier($storeId, $orderReference, $statusId, $statusLabel);
    }

    /**
     * @param int $storeId
     * @param int $orderId
     * @return array<string, mixed>|null
     */
    public function findByOrderId($storeId, $orderId)
    {
        return $this->inner->findByOrderId($storeId, $orderId);
    }
}

/**
 * Delegates to production mail-recipient repository; can force markSent() to throw (AUD-012-F03).
 */
final class MtUniCreditAud012ThrowingMarkSentMailRecipients
{
    /** @var MtUniCreditProcessTwoMailRecipientRepository */
    private $inner;

    /** @var bool */
    public $throwOnMarkSent = false;

    /**
     * @param MtUniCreditProcessTwoMailRecipientRepository $inner
     */
    public function __construct(MtUniCreditProcessTwoMailRecipientRepository $inner)
    {
        $this->inner = $inner;
    }

    /**
     * @param int $attemptId
     * @param array<int, array<string, mixed>> $recipients
     * @return void
     */
    public function ensureRecipients($attemptId, array $recipients)
    {
        $this->inner->ensureRecipients($attemptId, $recipients);
    }

    /**
     * @param int $attemptId
     * @return array<int, array<string, mixed>>
     */
    public function listByAttempt($attemptId)
    {
        return $this->inner->listByAttempt($attemptId);
    }

    /**
     * @param int $attemptId
     * @param string $recipientKey
     * @return array<string, mixed>|null
     */
    public function find($attemptId, $recipientKey)
    {
        return $this->inner->find($attemptId, $recipientKey);
    }

    /**
     * @param int $attemptId
     * @param string $recipientKey
     * @param string $ownerToken
     * @return bool
     */
    public function claimForSending($attemptId, $recipientKey, $ownerToken)
    {
        return $this->inner->claimForSending($attemptId, $recipientKey, $ownerToken);
    }

    /**
     * @param int $attemptId
     * @param string $recipientKey
     * @param string $ownerToken
     * @return bool
     */
    public function markSent($attemptId, $recipientKey, $ownerToken)
    {
        if ($this->throwOnMarkSent) {
            throw new RuntimeException('AUD-012 forced markSent persistence failure after external send success');
        }

        return $this->inner->markSent($attemptId, $recipientKey, $ownerToken);
    }

    /**
     * @param int $attemptId
     * @param string $recipientKey
     * @param string $ownerToken
     * @return void
     */
    public function markFailed($attemptId, $recipientKey, $ownerToken)
    {
        $this->inner->markFailed($attemptId, $recipientKey, $ownerToken);
    }

    /**
     * @param int $attemptId
     * @param string $recipientKey
     * @return void
     */
    public function markUncertain($attemptId, $recipientKey)
    {
        $this->inner->markUncertain($attemptId, $recipientKey);
    }

    /**
     * @param int $attemptId
     * @return int
     */
    public function normalizeStaleSendingToUncertain($attemptId)
    {
        return $this->inner->normalizeStaleSendingToUncertain($attemptId);
    }

    /**
     * @param int $attemptId
     * @return bool
     */
    public function areAllRecipientsSent($attemptId)
    {
        return $this->inner->areAllRecipientsSent($attemptId);
    }
}

/**
 * @param array<string, mixed> $stack
 * @param int $orderId
 * @return int
 */
function mtucAud012_attemptId(array $stack, $orderId)
{
    $row = $stack['attempts']->findByStoreOrder($stack['storeId'], (int) $orderId);

    return (int) $row['attempt_id'];
}

/**
 * @param array<string, mixed> $stack
 * @param int $orderId
 * @return array<string, mixed>
 */
function mtucAud012_orderContext(array $stack, $orderId)
{
    $input = Phase9TestHarness::submitInputProcess2((int) $orderId, $stack['storeId']);

    return array(
        'order_id' => (string) $orderId,
        'customer_email' => (string) $input['order']['email'],
        'store_email' => (string) $input['order']['store_email'],
    );
}

// ---------------------------------------------------------------------------
// F01 — local bank status failure blocks prepared/mail
// ---------------------------------------------------------------------------
$transportLocalFail = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportLocalFail);
$mailerLocal = new MtUniCreditRecordingProcessTwoMailer();
$stackLocal = Phase9TestHarness::stack(
    $transportLocalFail,
    null,
    null,
    Phase5TestHarness::STORE_A,
    array('uni_proces' => 1)
);
$failBank = new MtUniCreditAud012FailClosedBankStatuses(
    new MtUniCreditOrderBankStatusRepository($stackLocal['db'], $stackLocal['clock'])
);
$failBank->failLocal = true;
$process2Local = MtUniCreditProcessTwoServiceFactory::coordinator(
    $stackLocal['db'],
    $stackLocal['client'],
    $mailerLocal,
    $stackLocal['clock'],
    Phase4TestHarness::testSecretInput()
);
$ref = new ReflectionClass($process2Local);
$prop = $ref->getProperty('bankStatuses');
$prop->setAccessible(true);
$prop->setValue($process2Local, $failBank);
$refLife = new ReflectionClass($stackLocal['lifecycle']);
$p2Prop = $refLife->getProperty('process2');
$p2Prop->setAccessible(true);
$p2Prop->setValue($stackLocal['lifecycle'], $process2Local);
$stackLocal['process2'] = $process2Local;
$stackLocal['process2Mailer'] = $mailerLocal;

$orderLocal = 20101;
Phase9TestHarness::seedBankOrder($stackLocal['memoryDb'], $orderLocal, $stackLocal['storeId']);
$resultLocal = $stackLocal['submission']->submit(
    Phase9TestHarness::submitInputProcess2($orderLocal, $stackLocal['storeId'])
);
mtucAud012_assert(empty($resultLocal['success']), 'F01 local fail: submit not success');
$attemptLocal = mtucAud012_attemptId($stackLocal, $orderLocal);
$p2Local = (new MtUniCreditProcessTwoLifecycleRepository($stackLocal['db'], $stackLocal['clock']))
    ->findByAttempt($attemptLocal);
mtucAud012_assert(
    is_array($p2Local) && (string) $p2Local['process2_state'] === MtUniCreditProcessTwoLifecycleStates::FAILED,
    'F01 local fail: process2_failed (not prepared)'
);
mtucAud012_assert(count($mailerLocal->sent) === 0, 'F01 local fail: no mail');
mtucAud012_assert(Phase7TestHarness::countOrderPosts($transportLocalFail) === 1, 'F01 local fail: CP create = 1');
mtucAud012_assert(Phase9TestHarness::smartUcfCallCount($stackLocal['smartUcfProbe']) === 0, 'F01 local fail: SmartUCF = 0');

// Recovery after local bank works again
$failBank->failLocal = false;
$resultLocalRetry = $stackLocal['submission']->submit(
    Phase9TestHarness::submitInputProcess2($orderLocal, $stackLocal['storeId'])
);
mtucAud012_assert(!empty($resultLocalRetry['success']), 'F01 local recovery: success');
$p2LocalRetry = (new MtUniCreditProcessTwoLifecycleRepository($stackLocal['db'], $stackLocal['clock']))
    ->findByAttempt($attemptLocal);
mtucAud012_assert(
    is_array($p2LocalRetry)
        && (string) $p2LocalRetry['process2_state'] === MtUniCreditProcessTwoLifecycleStates::PREPARED,
    'F01 local recovery: prepared'
);
mtucAud012_assert(count($mailerLocal->sent) >= 1, 'F01 local recovery: mail sent');
mtucAud012_assert(Phase7TestHarness::countOrderPosts($transportLocalFail) === 1, 'F01 local recovery: no CP recreate');
mtucAud012_assert(Phase9TestHarness::smartUcfCallCount($stackLocal['smartUcfProbe']) === 0, 'F01 local recovery: SmartUCF = 0');

// ---------------------------------------------------------------------------
// F01 — CP PATCH failure blocks prepared/mail; local may remain; recoverable
// ---------------------------------------------------------------------------
$transportPatchFail = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportPatchFail);
$transportPatchFail->failStatusPatch = true;
$mailerPatch = new MtUniCreditRecordingProcessTwoMailer();
$stackPatch = Phase9TestHarness::stack(
    $transportPatchFail,
    null,
    null,
    Phase5TestHarness::STORE_A,
    array('uni_proces' => 1)
);
$process2Patch = MtUniCreditProcessTwoServiceFactory::coordinator(
    $stackPatch['db'],
    $stackPatch['client'],
    $mailerPatch,
    $stackPatch['clock'],
    Phase4TestHarness::testSecretInput()
);
$refLife = new ReflectionClass($stackPatch['lifecycle']);
$p2Prop = $refLife->getProperty('process2');
$p2Prop->setAccessible(true);
$p2Prop->setValue($stackPatch['lifecycle'], $process2Patch);
$stackPatch['process2'] = $process2Patch;
$stackPatch['process2Mailer'] = $mailerPatch;

$orderPatch = 20102;
Phase9TestHarness::seedBankOrder($stackPatch['memoryDb'], $orderPatch, $stackPatch['storeId']);
$resultPatch = $stackPatch['submission']->submit(
    Phase9TestHarness::submitInputProcess2($orderPatch, $stackPatch['storeId'])
);
mtucAud012_assert(empty($resultPatch['success']), 'F01 CP fail: submit not success');
$attemptPatch = mtucAud012_attemptId($stackPatch, $orderPatch);
$p2Patch = (new MtUniCreditProcessTwoLifecycleRepository($stackPatch['db'], $stackPatch['clock']))
    ->findByAttempt($attemptPatch);
mtucAud012_assert(
    is_array($p2Patch) && (string) $p2Patch['process2_state'] === MtUniCreditProcessTwoLifecycleStates::FAILED,
    'F01 CP fail: process2_failed'
);
mtucAud012_assert(count($mailerPatch->sent) === 0, 'F01 CP fail: no mail');
mtucAud012_assert(
    Phase9TestHarness::bankStatusId($stackPatch, $orderPatch) === MtUniCreditBankStatus::SENT_PROCESS2,
    'F01 CP fail: local bank_sent_process2 may remain'
);
mtucAud012_assert(Phase7TestHarness::countOrderPosts($transportPatchFail) === 1, 'F01 CP fail: CP create = 1');
mtucAud012_assert(Phase9TestHarness::smartUcfCallCount($stackPatch['smartUcfProbe']) === 0, 'F01 CP fail: SmartUCF = 0');

$transportPatchFail->failStatusPatch = false;
$resultPatchRetry = $stackPatch['submission']->submit(
    Phase9TestHarness::submitInputProcess2($orderPatch, $stackPatch['storeId'])
);
mtucAud012_assert(!empty($resultPatchRetry['success']), 'F01 CP recovery: success');
$p2PatchRetry = (new MtUniCreditProcessTwoLifecycleRepository($stackPatch['db'], $stackPatch['clock']))
    ->findByAttempt($attemptPatch);
mtucAud012_assert(
    is_array($p2PatchRetry)
        && (string) $p2PatchRetry['process2_state'] === MtUniCreditProcessTwoLifecycleStates::PREPARED,
    'F01 CP recovery: prepared before mail'
);
mtucAud012_assert(count($mailerPatch->sent) >= 1, 'F01 CP recovery: mail after reconcile');
mtucAud012_assert(Phase7TestHarness::countOrderPosts($transportPatchFail) === 1, 'F01 CP recovery: no CP recreate');
mtucAud012_assert(Phase9TestHarness::smartUcfCallCount($stackPatch['smartUcfProbe']) === 0, 'F01 CP recovery: SmartUCF = 0');

// ---------------------------------------------------------------------------
// F04 — active preparing claim blocks second worker
// ---------------------------------------------------------------------------
$transportActive = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportActive);
$stackActive = Phase9TestHarness::stack(
    $transportActive,
    null,
    null,
    Phase5TestHarness::STORE_A,
    array('uni_proces' => 1)
);
$orderActive = 20103;
Phase9TestHarness::seedBankOrder($stackActive['memoryDb'], $orderActive, $stackActive['storeId']);
// Drive CP create then stop before P2 by marking preparing after partial path:
$resultActiveSeed = $stackActive['submission']->submit(
    Phase9TestHarness::submitInputProcess2($orderActive, $stackActive['storeId'])
);
mtucAud012_assert(!empty($resultActiveSeed['success']), 'F04 setup: initial P2 success');
$attemptActive = mtucAud012_attemptId($stackActive, $orderActive);
$lifeActive = new MtUniCreditProcessTwoLifecycleRepository($stackActive['db'], $stackActive['clock']);
// Force preparing claim as if in-flight worker holds it
$ownerA = MtUniCreditLockOwnerTokenGenerator::generate();
$stackActive['db']->query(
    "UPDATE `oc_mt_uni_credit_financing_attempt`
     SET `process2_state` = '" . MtUniCreditProcessTwoLifecycleStates::PREPARING . "',
         `process2_claimed_at` = '" . $stackActive['db']->escape(
        $stackActive['clock']->formatUtc(Phase9TestHarness::NOW)
    ) . "',
         `process2_claim_owner` = '" . $stackActive['db']->escape($ownerA) . "',
         `process2_mail_sent` = 0
     WHERE `attempt_id` = " . $attemptActive
);
$claimBlocked = $lifeActive->claimPreparing($attemptActive, MtUniCreditLockOwnerTokenGenerator::generate());
mtucAud012_assert($claimBlocked === false, 'F04 active: second claim rejected');
$runBlocked = $stackActive['process2']->run(
    $attemptActive,
    $stackActive['storeId'],
    $orderActive,
    mtuc4_valid_shop_snapshot(array('uni_proces' => 1)),
    mtucAud012_orderContext($stackActive, $orderActive)
);
mtucAud012_assert(
    empty($runBlocked['success'])
        && isset($runBlocked['error'])
        && $runBlocked['error'] === 'operation_processing',
    'F04 active: second worker operation_processing'
);
mtucAud012_assert(Phase9TestHarness::smartUcfCallCount($stackActive['smartUcfProbe']) === 0, 'F04 active: SmartUCF = 0');

// ---------------------------------------------------------------------------
// F04 — stale preparing becomes recoverable; no CP recreate / no SmartUCF
// ---------------------------------------------------------------------------
$staleAt = $stackActive['clock']->formatUtc(Phase9TestHarness::NOW - 120);
$stackActive['db']->query(
    "UPDATE `oc_mt_uni_credit_financing_attempt`
     SET `process2_state` = '" . MtUniCreditProcessTwoLifecycleStates::PREPARING . "',
         `process2_claimed_at` = '" . $stackActive['db']->escape($staleAt) . "',
         `process2_claim_owner` = '" . $stackActive['db']->escape($ownerA) . "',
         `process2_mail_sent` = 0
     WHERE `attempt_id` = " . $attemptActive
);
$cpBeforeStale = Phase7TestHarness::countOrderPosts($transportActive);
$mailBeforeStale = count($stackActive['process2Mailer']->sent);
$resultStale = $stackActive['submission']->submit(
    Phase9TestHarness::submitInputProcess2($orderActive, $stackActive['storeId'])
);
mtucAud012_assert(!empty($resultStale['success']), 'F04 stale: recovery success');
$p2Stale = $lifeActive->findByAttempt($attemptActive);
mtucAud012_assert(
    is_array($p2Stale) && (string) $p2Stale['process2_state'] === MtUniCreditProcessTwoLifecycleStates::PREPARED,
    'F04 stale: prepared after recovery'
);
mtucAud012_assert(
    Phase7TestHarness::countOrderPosts($transportActive) === $cpBeforeStale,
    'F04 stale: no CP recreate'
);
mtucAud012_assert(Phase9TestHarness::smartUcfCallCount($stackActive['smartUcfProbe']) === 0, 'F04 stale: SmartUCF = 0');
mtucAud012_assert(
    count($stackActive['process2Mailer']->sent) >= $mailBeforeStale,
    'F04 stale: mail path reachable'
);

// ---------------------------------------------------------------------------
// F02 — partial admin/customer success; only failed recipient retries
// ---------------------------------------------------------------------------
$transportPartial = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportPartial);
$mailerPartial = new MtUniCreditRecordingProcessTwoMailer();
$mailerPartial->forceFailureByEmail = array(
    'admin-b@example.test' => true,
);
$stackPartial = Phase9TestHarness::stack(
    $transportPartial,
    null,
    null,
    Phase5TestHarness::STORE_A,
    array(
        'uni_proces' => 1,
        'uni_email' => 'admin-a@example.test,admin-b@example.test',
    )
);
$process2Partial = MtUniCreditProcessTwoServiceFactory::coordinator(
    $stackPartial['db'],
    $stackPartial['client'],
    $mailerPartial,
    $stackPartial['clock'],
    Phase4TestHarness::testSecretInput()
);
$refLife = new ReflectionClass($stackPartial['lifecycle']);
$p2Prop = $refLife->getProperty('process2');
$p2Prop->setAccessible(true);
$p2Prop->setValue($stackPartial['lifecycle'], $process2Partial);
$stackPartial['process2'] = $process2Partial;
$stackPartial['process2Mailer'] = $mailerPartial;

$orderPartial = 20104;
Phase9TestHarness::seedBankOrder($stackPartial['memoryDb'], $orderPartial, $stackPartial['storeId']);
$resultPartial = $stackPartial['submission']->submit(
    Phase9TestHarness::submitInputProcess2($orderPartial, $stackPartial['storeId'])
);
mtucAud012_assert(!empty($resultPartial['success']), 'F02 partial: prepared success despite mail gap');
$attemptPartial = mtucAud012_attemptId($stackPartial, $orderPartial);
$mailRepo = new MtUniCreditProcessTwoMailRecipientRepository($stackPartial['db'], $stackPartial['clock']);
$rowsPartial = $mailRepo->listByAttempt($attemptPartial);
$statesPartial = array();
foreach ($rowsPartial as $row) {
    $statesPartial[(string) $row['recipient_key']] = (string) $row['state'];
}
mtucAud012_assert(
    isset($statesPartial['admin-a@example.test'])
        && $statesPartial['admin-a@example.test'] === MtUniCreditProcessTwoMailRecipientStates::SENT,
    'F02 partial: admin A sent'
);
mtucAud012_assert(
    isset($statesPartial['admin-b@example.test'])
        && $statesPartial['admin-b@example.test'] === MtUniCreditProcessTwoMailRecipientStates::FAILED,
    'F02 partial: admin B failed'
);
mtucAud012_assert(
    isset($statesPartial['customer@example.test'])
        && $statesPartial['customer@example.test'] === MtUniCreditProcessTwoMailRecipientStates::SENT,
    'F02 partial: customer sent'
);
$sentAfterPartial = count($mailerPartial->sent);
$mailerPartial->forceFailureByEmail = array();
$resultPartialRetry = $stackPartial['submission']->submit(
    Phase9TestHarness::submitInputProcess2($orderPartial, $stackPartial['storeId'])
);
mtucAud012_assert(!empty($resultPartialRetry['success']), 'F02 retry: success');
$sentDelta = array_slice($mailerPartial->sent, $sentAfterPartial);
$resentKeys = array();
foreach ($sentDelta as $item) {
    $resentKeys[] = strtolower((string) $item['to']);
}
mtucAud012_assert(!in_array('admin-a@example.test', $resentKeys, true), 'F02 retry: admin A not resent');
mtucAud012_assert(in_array('admin-b@example.test', $resentKeys, true), 'F02 retry: admin B retried');
mtucAud012_assert(!in_array('customer@example.test', $resentKeys, true), 'F02 retry: customer not resent');
mtucAud012_assert(Phase9TestHarness::smartUcfCallCount($stackPartial['smartUcfProbe']) === 0, 'F02: SmartUCF = 0');
mtucAud012_assert(Phase7TestHarness::countOrderPosts($transportPartial) === 1, 'F02: CP create = 1');

// Audience privacy on partial path
$adminHtml = '';
$customerHtml = '';
foreach ($mailerPartial->sent as $item) {
    if ($item['audience'] === 'admin' && $adminHtml === '') {
        $adminHtml = (string) $item['html'];
    }
    if ($item['audience'] === 'customer') {
        $customerHtml = (string) $item['html'];
    }
}
mtucAud012_assert(strpos($adminHtml, '1990010112') !== false, 'F02 privacy: admin may include EGN');
mtucAud012_assert(strpos($customerHtml, '1990010112') === false, 'F02 privacy: customer excludes EGN');

// ---------------------------------------------------------------------------
// F03 — concurrent recipient claim
// ---------------------------------------------------------------------------
$transportConc = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportConc);
$stackConc = Phase9TestHarness::stack(
    $transportConc,
    null,
    null,
    Phase5TestHarness::STORE_A,
    array('uni_proces' => 1)
);
$orderConc = 20105;
Phase9TestHarness::seedBankOrder($stackConc['memoryDb'], $orderConc, $stackConc['storeId']);
$resultConc = $stackConc['submission']->submit(
    Phase9TestHarness::submitInputProcess2($orderConc, $stackConc['storeId'])
);
mtucAud012_assert(!empty($resultConc['success']), 'F03 concurrency setup: success');
$attemptConc = mtucAud012_attemptId($stackConc, $orderConc);
// Reset one recipient to pending for claim race
$mailRepoConc = new MtUniCreditProcessTwoMailRecipientRepository($stackConc['db'], $stackConc['clock']);
$stackConc['db']->query(
    "UPDATE `oc_mt_uni_credit_process2_mail_recipient`
     SET `state` = '" . MtUniCreditProcessTwoMailRecipientStates::PENDING . "',
         `claim_owner_token` = NULL,
         `claimed_at` = NULL
     WHERE `attempt_id` = " . $attemptConc . "
       AND `recipient_key` = 'bank-admin@example.test'"
);
$stackConc['db']->query(
    "UPDATE `oc_mt_uni_credit_financing_attempt`
     SET `process2_mail_sent` = 0
     WHERE `attempt_id` = " . $attemptConc
);
$token1 = MtUniCreditLockOwnerTokenGenerator::generate();
$token2 = MtUniCreditLockOwnerTokenGenerator::generate();
$claim1 = $mailRepoConc->claimForSending($attemptConc, 'bank-admin@example.test', $token1);
$claim2 = $mailRepoConc->claimForSending($attemptConc, 'bank-admin@example.test', $token2);
mtucAud012_assert($claim1 === true, 'F03 claim: first worker owns recipient');
mtucAud012_assert($claim2 === false, 'F03 claim: second worker blocked');

// ---------------------------------------------------------------------------
// F03 — send-before-marker ambiguity → uncertain (no blind resend)
// ---------------------------------------------------------------------------
$transportAmb = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportAmb);
$mailerAmb = new MtUniCreditRecordingProcessTwoMailer();
$stackAmb = Phase9TestHarness::stack(
    $transportAmb,
    null,
    null,
    Phase5TestHarness::STORE_A,
    array('uni_proces' => 1, 'uni_email' => 'amb-admin@example.test')
);
$mailRepoAmbHolder = new MtUniCreditProcessTwoMailRecipientRepository($stackAmb['db'], $stackAmb['clock']);
$mailerAmb->afterSuccessfulSend = function ($audience, $email) use ($stackAmb) {
    $key = MtUniCreditProcessTwoMailRecipientRepository::normalizeRecipientKey($email);
    // Break ownership so markSent cannot establish durable completion after external success.
    $stackAmb['db']->query(
        "UPDATE `oc_mt_uni_credit_process2_mail_recipient`
         SET `claim_owner_token` = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
         WHERE `recipient_key` = '" . $stackAmb['db']->escape($key) . "'"
    );
};
$process2Amb = MtUniCreditProcessTwoServiceFactory::coordinator(
    $stackAmb['db'],
    $stackAmb['client'],
    $mailerAmb,
    $stackAmb['clock'],
    Phase4TestHarness::testSecretInput()
);
$refLife = new ReflectionClass($stackAmb['lifecycle']);
$p2Prop = $refLife->getProperty('process2');
$p2Prop->setAccessible(true);
$p2Prop->setValue($stackAmb['lifecycle'], $process2Amb);
$stackAmb['process2'] = $process2Amb;
$stackAmb['process2Mailer'] = $mailerAmb;

$orderAmb = 20106;
Phase9TestHarness::seedBankOrder($stackAmb['memoryDb'], $orderAmb, $stackAmb['storeId']);
$resultAmb = $stackAmb['submission']->submit(
    Phase9TestHarness::submitInputProcess2($orderAmb, $stackAmb['storeId'])
);
mtucAud012_assert(!empty($resultAmb['success']), 'F03 ambiguity: prepared still success');
$attemptAmb = mtucAud012_attemptId($stackAmb, $orderAmb);
$mailRepoAmb = new MtUniCreditProcessTwoMailRecipientRepository($stackAmb['db'], $stackAmb['clock']);
$ambRows = $mailRepoAmb->listByAttempt($attemptAmb);
$uncertainFound = false;
foreach ($ambRows as $row) {
    if ((string) $row['state'] === MtUniCreditProcessTwoMailRecipientStates::UNCERTAIN) {
        $uncertainFound = true;
    }
}
mtucAud012_assert($uncertainFound, 'F03 ambiguity: durable uncertain state present');
$sentBeforeAmbReplay = count($mailerAmb->sent);
$resultAmbReplay = $stackAmb['submission']->submit(
    Phase9TestHarness::submitInputProcess2($orderAmb, $stackAmb['storeId'])
);
mtucAud012_assert(!empty($resultAmbReplay['success']), 'F03 ambiguity replay: success');
mtucAud012_assert(
    count($mailerAmb->sent) === $sentBeforeAmbReplay,
    'F03 ambiguity: uncertain recipient not blindly resent'
);
mtucAud012_assert(Phase9TestHarness::smartUcfCallCount($stackAmb['smartUcfProbe']) === 0, 'F03 ambiguity: SmartUCF = 0');

// ---------------------------------------------------------------------------
// F03 residual — markSent() THROWS after external send success → uncertain (not failed)
// ---------------------------------------------------------------------------
$transportThrow = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportThrow);
$mailerThrow = new MtUniCreditRecordingProcessTwoMailer();
$stackThrow = Phase9TestHarness::stack(
    $transportThrow,
    null,
    null,
    Phase5TestHarness::STORE_A,
    array('uni_proces' => 1, 'uni_email' => 'throw-admin@example.test')
);
$throwingMailRecipients = new MtUniCreditAud012ThrowingMarkSentMailRecipients(
    new MtUniCreditProcessTwoMailRecipientRepository($stackThrow['db'], $stackThrow['clock'])
);
$throwingMailRecipients->throwOnMarkSent = true;
$process2Throw = MtUniCreditProcessTwoServiceFactory::coordinator(
    $stackThrow['db'],
    $stackThrow['client'],
    $mailerThrow,
    $stackThrow['clock'],
    Phase4TestHarness::testSecretInput()
);
$refThrow = new ReflectionClass($process2Throw);
$mailRecipientsProp = $refThrow->getProperty('mailRecipients');
$mailRecipientsProp->setAccessible(true);
$mailRecipientsProp->setValue($process2Throw, $throwingMailRecipients);
$refLifeThrow = new ReflectionClass($stackThrow['lifecycle']);
$p2PropThrow = $refLifeThrow->getProperty('process2');
$p2PropThrow->setAccessible(true);
$p2PropThrow->setValue($stackThrow['lifecycle'], $process2Throw);
$stackThrow['process2'] = $process2Throw;
$stackThrow['process2Mailer'] = $mailerThrow;

$orderThrow = 20108;
Phase9TestHarness::seedBankOrder($stackThrow['memoryDb'], $orderThrow, $stackThrow['storeId']);
$resultThrow = $stackThrow['submission']->submit(
    Phase9TestHarness::submitInputProcess2($orderThrow, $stackThrow['storeId'])
);
mtucAud012_assert(!empty($resultThrow['success']), 'F03 markSent throw: prepared still success');
$attemptThrow = mtucAud012_attemptId($stackThrow, $orderThrow);
$mailRepoThrow = new MtUniCreditProcessTwoMailRecipientRepository($stackThrow['db'], $stackThrow['clock']);
$throwRows = $mailRepoThrow->listByAttempt($attemptThrow);
$throwAdminState = null;
$anyFailed = false;
foreach ($throwRows as $row) {
    if ((string) $row['recipient_key'] === 'throw-admin@example.test') {
        $throwAdminState = (string) $row['state'];
    }
    if ((string) $row['state'] === MtUniCreditProcessTwoMailRecipientStates::FAILED) {
        $anyFailed = true;
    }
}
mtucAud012_assert(
    $throwAdminState === MtUniCreditProcessTwoMailRecipientStates::UNCERTAIN
    || $throwAdminState === MtUniCreditProcessTwoMailRecipientStates::SENDING,
    'F03 markSent throw: recipient uncertain (or sending pending stale→uncertain), not failed'
);
mtucAud012_assert($anyFailed === false, 'F03 markSent throw: no recipient downgraded to retryable failed');
mtucAud012_assert(
    $throwAdminState !== MtUniCreditProcessTwoMailRecipientStates::FAILED,
    'F03 markSent throw: admin recipient is not retryable failed'
);

// Disable throw so replay cannot accidentally "fix" markers; uncertain must still block resend.
$throwingMailRecipients->throwOnMarkSent = false;
$sentBeforeThrowReplay = 0;
foreach ($mailerThrow->sent as $item) {
    if (strtolower((string) $item['to']) === 'throw-admin@example.test') {
        $sentBeforeThrowReplay++;
    }
}
mtucAud012_assert($sentBeforeThrowReplay >= 1, 'F03 markSent throw: external admin send occurred once');
$resultThrowReplay = $stackThrow['submission']->submit(
    Phase9TestHarness::submitInputProcess2($orderThrow, $stackThrow['storeId'])
);
mtucAud012_assert(!empty($resultThrowReplay['success']), 'F03 markSent throw replay: success');
$sentAfterThrowReplay = 0;
foreach ($mailerThrow->sent as $item) {
    if (strtolower((string) $item['to']) === 'throw-admin@example.test') {
        $sentAfterThrowReplay++;
    }
}
mtucAud012_assert(
    $sentAfterThrowReplay === $sentBeforeThrowReplay,
    'F03 markSent throw: automatic replay sends 0 additional messages to that recipient'
);
mtucAud012_assert(Phase7TestHarness::countOrderPosts($transportThrow) === 1, 'F03 markSent throw: no CP recreate');
mtucAud012_assert(Phase9TestHarness::smartUcfCallCount($stackThrow['smartUcfProbe']) === 0, 'F03 markSent throw: SmartUCF = 0');

// ---------------------------------------------------------------------------
// F03 — replay after all recipients complete sends zero mail
// ---------------------------------------------------------------------------
$transportDone = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportDone);
$stackDone = Phase9TestHarness::stack(
    $transportDone,
    null,
    null,
    Phase5TestHarness::STORE_A,
    array('uni_proces' => 1)
);
$orderDone = 20107;
Phase9TestHarness::seedBankOrder($stackDone['memoryDb'], $orderDone, $stackDone['storeId']);
$resultDone = $stackDone['submission']->submit(
    Phase9TestHarness::submitInputProcess2($orderDone, $stackDone['storeId'])
);
mtucAud012_assert(!empty($resultDone['success']), 'F03 complete: success');
$mailCountDone = count($stackDone['process2Mailer']->sent);
$resultDoneReplay = $stackDone['submission']->submit(
    Phase9TestHarness::submitInputProcess2($orderDone, $stackDone['storeId'])
);
mtucAud012_assert(!empty($resultDoneReplay['success']), 'F03 complete replay: success');
mtucAud012_assert(
    count($stackDone['process2Mailer']->sent) === $mailCountDone,
    'F03 complete replay: zero additional mail'
);
mtucAud012_assert(Phase7TestHarness::countOrderPosts($transportDone) === 1, 'F03 complete replay: no CP recreate');
mtucAud012_assert(Phase9TestHarness::smartUcfCallCount($stackDone['smartUcfProbe']) === 0, 'F03 complete replay: SmartUCF = 0');

// ---------------------------------------------------------------------------
// PHP 7.3 surface on changed production files
// ---------------------------------------------------------------------------
$changed = array(
    'process_two_lifecycle_coordinator.php',
    'process_two_lifecycle_repository.php',
    'process_two_mail_recipient_repository.php',
    'process_two_mail_recipient_states.php',
    'process_two_mail_port.php',
    'php_mail_process_two_mailer.php',
    'process_two_service_factory.php',
    'persistence_schema.php',
    'persistence_table_names.php',
);
$forbiddenTokens = array('str_contains', 'str_starts_with', '?' . '->', '#' . '[', 'fn' . '(');
foreach ($changed as $file) {
    $src = file_get_contents($lib . DIRECTORY_SEPARATOR . $file);
    mtucAud012_assert(is_string($src) && $src !== '', 'PHP file readable: ' . $file);
    foreach ($forbiddenTokens as $forbidden) {
        mtucAud012_assert(strpos((string) $src, $forbidden) === false, 'PHP 7.3 free of ' . $forbidden . ': ' . $file);
    }
}

echo PHP_EOL . 'AUD-012 checks: ' . $passes . ' passed';
if ($failures) {
    echo ', ' . count($failures) . ' failed' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    exit(1);
}
echo ', 0 failed' . PHP_EOL;
exit(0);
