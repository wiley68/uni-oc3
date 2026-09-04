<?php

/**
 * Shared CP order create/resume lifecycle for checkout (Phase 7).
 *
 * Ambiguous remote outcomes block further POST /orders (no unsafe fresh resend).
 * Definite retryable failures may re-POST the frozen cp_payload only.
 */
final class MtUniCreditControlPanelOrderLifecycleService
{
    const CUSTOMER_FAILURE_MESSAGE = 'Поръчката е създадена, но изпращането към системата за финансиране не беше успешно.';

    const CUSTOMER_SUCCESS_MESSAGE = 'Поръчката е изпратена към системата за финансиране.';

    const CUSTOMER_AMBIGUOUS_MESSAGE = 'Поръчката е създадена локално, но резултатът от изпращането към системата за финансиране не е потвърден. Моля, не изпращайте отново — свържете се с магазина.';

    /** @var MtUniCreditFinancingAttemptRepository */
    private $attempts;

    /** @var MtUniCreditOperationLockRepository */
    private $locks;

    /** @var MtUniCreditControlPanelClient */
    private $client;

    /** @var MtUniCreditControlPanelOrderPayloadBuilder */
    private $payloadBuilder;

    /** @var MtUniCreditSmartUcfSessionCoordinator|null */
    private $process1;

    /** @var MtUniCreditOrderBankStatusRepository|null */
    private $bankStatuses;

    /** @var MtUniCreditPhase9LifecycleLog|null */
    private $phase9Log;

    /**
     * @param MtUniCreditFinancingAttemptRepository $attempts
     * @param MtUniCreditOperationLockRepository $locks
     * @param MtUniCreditControlPanelClient $client
     * @param MtUniCreditControlPanelOrderPayloadBuilder|null $payloadBuilder
     * @param MtUniCreditSmartUcfSessionCoordinator|null $process1
     * @param MtUniCreditOrderBankStatusRepository|null $bankStatuses
     */
    public function __construct(
        MtUniCreditFinancingAttemptRepository $attempts,
        MtUniCreditOperationLockRepository $locks,
        MtUniCreditControlPanelClient $client,
        $payloadBuilder = null,
        $process1 = null,
        $bankStatuses = null
    ) {
        $this->attempts = $attempts;
        $this->locks = $locks;
        $this->client = $client;
        $this->payloadBuilder = $payloadBuilder instanceof MtUniCreditControlPanelOrderPayloadBuilder
            ? $payloadBuilder
            : new MtUniCreditControlPanelOrderPayloadBuilder();
        $this->process1 = $process1 instanceof MtUniCreditSmartUcfSessionCoordinator ? $process1 : null;
        $this->bankStatuses = $bankStatuses instanceof MtUniCreditOrderBankStatusRepository ? $bankStatuses : null;
        $this->phase9Log = null;
    }

    /**
     * @param array<string, mixed> $attempt
     * @param array<string, mixed> $order
     * @param array<int, array<string, mixed>> $orderProducts
     * @param MtUniCreditCalculationResult $calculation
     * @param array<string, mixed> $shop
     * @param string $lockOwnerToken
     * @return MtUniCreditControlPanelOrderSubmissionResult
     */
    public function submitOrRecover(
        array $attempt,
        array $order,
        array $orderProducts,
        MtUniCreditCalculationResult $calculation,
        array $shop,
        $lockOwnerToken
    ) {
        $storeId = (int) $attempt['store_id'];
        $orderId = (int) $attempt['order_id'];
        $operationKeyHash = (string) $attempt['operation_key_hash'];
        $attemptId = (int) $attempt['attempt_id'];

        if ($orderId <= 0 || $attemptId <= 0) {
            return MtUniCreditControlPanelOrderSubmissionResult::fail(
                MtUniCreditControlPanelErrorClass::RECOVERY_FAILED,
                false
            );
        }

        $entryPoint = isset($attempt['entry_point']) ? (string) $attempt['entry_point'] : '';
        if (!MtUniCreditOperationEntryPoint::isValid($entryPoint)) {
            $entryPoint = MtUniCreditOperationEntryPoint::CHECKOUT;
        }

        if (!$this->locks->acquire(
            $storeId,
            $entryPoint,
            $operationKeyHash,
            $lockOwnerToken
        )) {
            return MtUniCreditControlPanelOrderSubmissionResult::fail(
                MtUniCreditControlPanelErrorClass::RECOVERY_FAILED,
                true
            );
        }

        try {
            return $this->runUnderLock($attemptId, $order, $orderProducts, $calculation, $shop);
        } finally {
            $this->locks->release(
                $storeId,
                $entryPoint,
                $operationKeyHash,
                $lockOwnerToken
            );
        }
    }

    /**
     * @param int $attemptId
     * @param array<string, mixed> $order
     * @param array<int, array<string, mixed>> $orderProducts
     * @param MtUniCreditCalculationResult $calculation
     * @param array<string, mixed> $shop
     * @return MtUniCreditControlPanelOrderSubmissionResult
     */
    private function runUnderLock(
        $attemptId,
        array $order,
        array $orderProducts,
        MtUniCreditCalculationResult $calculation,
        array $shop
    ) {
        $row = $this->attempts->findById($attemptId);
        if ($row === null) {
            return MtUniCreditControlPanelOrderSubmissionResult::fail(
                MtUniCreditControlPanelErrorClass::RECOVERY_FAILED,
                false
            );
        }

        $existingCpId = (int) $row['control_panel_order_id'];
        if ($existingCpId > 0 && $row['state'] === MtUniCreditFinancingAttemptState::CP_CREATED) {
            return $this->continueAfterCpCreated(
                $attemptId,
                $existingCpId,
                true,
                $order,
                $orderProducts,
                $calculation,
                $shop
            );
        }

        if ($row['state'] === MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN) {
            return MtUniCreditControlPanelOrderSubmissionResult::fail(
                MtUniCreditControlPanelErrorClass::AMBIGUOUS_BLOCKED,
                false,
                null,
                true
            );
        }

        if ($row['state'] === MtUniCreditFinancingAttemptState::CP_SUBMITTING && $existingCpId <= 0) {
            // Crash window after possible send — treat as ambiguous, do not re-POST.
            $this->attempts->persistFailure(
                $attemptId,
                MtUniCreditControlPanelErrorClass::RECOVERY_FAILED,
                MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN
            );

            return MtUniCreditControlPanelOrderSubmissionResult::fail(
                MtUniCreditControlPanelErrorClass::AMBIGUOUS_BLOCKED,
                false,
                null,
                true
            );
        }

        $payload = $this->resolveFrozenPayload($row, $order, $orderProducts, $calculation, $shop);
        if ($payload === null) {
            $this->attempts->persistFailure(
                $attemptId,
                MtUniCreditControlPanelErrorClass::VALIDATION_FAILED,
                MtUniCreditFinancingAttemptState::CP_FAILED_RETRYABLE
            );

            return MtUniCreditControlPanelOrderSubmissionResult::fail(
                MtUniCreditControlPanelErrorClass::VALIDATION_FAILED,
                true
            );
        }

        $fingerprint = MtUniCreditControlPanelOrderPayloadBuilder::fingerprint($payload);
        if (
            $row['request_fingerprint'] !== ''
            && $row['cp_payload']
            && !hash_equals((string) $row['request_fingerprint'], $fingerprint)
        ) {
            $this->attempts->persistFailure(
                $attemptId,
                MtUniCreditControlPanelErrorClass::CONFLICT,
                MtUniCreditFinancingAttemptState::CP_FAILED_RETRYABLE
            );

            return MtUniCreditControlPanelOrderSubmissionResult::fail(
                MtUniCreditControlPanelErrorClass::CONFLICT,
                false,
                409
            );
        }

        $this->attempts->persistCpPayload($attemptId, $payload, $fingerprint);

        if (!$this->enterSubmitting($attemptId, (string) $row['state'])) {
            $fresh = $this->attempts->findById($attemptId);
            $freshCp = $fresh !== null ? (int) $fresh['control_panel_order_id'] : 0;
            if ($fresh !== null && $freshCp > 0 && $fresh['state'] === MtUniCreditFinancingAttemptState::CP_CREATED) {
                return $this->continueAfterCpCreated(
                    $attemptId,
                    $freshCp,
                    true,
                    $order,
                    $orderProducts,
                    $calculation,
                    $shop
                );
            }
            if ($fresh !== null && $fresh['state'] === MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN) {
                return MtUniCreditControlPanelOrderSubmissionResult::fail(
                    MtUniCreditControlPanelErrorClass::AMBIGUOUS_BLOCKED,
                    false,
                    null,
                    true
                );
            }

            return MtUniCreditControlPanelOrderSubmissionResult::fail(
                MtUniCreditControlPanelErrorClass::RECOVERY_FAILED,
                true
            );
        }

        try {
            $response = $this->client->createOrder($payload);
            $cpId = isset($response['data']['id']) ? (int) $response['data']['id'] : 0;
            if ($cpId <= 0) {
                $this->attempts->persistFailure(
                    $attemptId,
                    MtUniCreditControlPanelErrorClass::INVALID_RESPONSE,
                    MtUniCreditFinancingAttemptState::CP_FAILED_RETRYABLE
                );

                return MtUniCreditControlPanelOrderSubmissionResult::fail(
                    MtUniCreditControlPanelErrorClass::INVALID_RESPONSE,
                    true,
                    200
                );
            }

            if (!$this->attempts->persistControlPanelOrderId($attemptId, $cpId)) {
                $this->attempts->persistFailure(
                    $attemptId,
                    MtUniCreditControlPanelErrorClass::RECOVERY_FAILED,
                    MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN
                );

                return MtUniCreditControlPanelOrderSubmissionResult::fail(
                    MtUniCreditControlPanelErrorClass::RECOVERY_FAILED,
                    false,
                    null,
                    true
                );
            }

            $this->attempts->transitionFromStates(
                $attemptId,
                array(MtUniCreditFinancingAttemptState::CP_SUBMITTING),
                MtUniCreditFinancingAttemptState::CP_CREATED
            );
            $this->attempts->clearLastErrorClass($attemptId);

            return $this->continueAfterCpCreated(
                $attemptId,
                $cpId,
                false,
                $order,
                $orderProducts,
                $calculation,
                $shop
            );
        } catch (MtUniCreditCpAuthenticationException $exception) {
            $this->attempts->persistFailure(
                $attemptId,
                MtUniCreditControlPanelErrorClass::AUTH_FAILED,
                MtUniCreditFinancingAttemptState::CP_FAILED_RETRYABLE
            );

            return MtUniCreditControlPanelOrderSubmissionResult::fail(
                MtUniCreditControlPanelErrorClass::AUTH_FAILED,
                true,
                401
            );
        } catch (MtUniCreditCpTimeoutException $exception) {
            $this->attempts->persistFailure(
                $attemptId,
                MtUniCreditControlPanelErrorClass::TIMEOUT,
                MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN
            );

            return MtUniCreditControlPanelOrderSubmissionResult::fail(
                MtUniCreditControlPanelErrorClass::TIMEOUT,
                false,
                null,
                true
            );
        } catch (MtUniCreditCpConnectionException $exception) {
            $this->attempts->persistFailure(
                $attemptId,
                MtUniCreditControlPanelErrorClass::TRANSPORT_FAILED,
                MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN
            );

            return MtUniCreditControlPanelOrderSubmissionResult::fail(
                MtUniCreditControlPanelErrorClass::TRANSPORT_FAILED,
                false,
                null,
                true
            );
        } catch (MtUniCreditCpHttpException $exception) {
            $status = $exception->getStatusCode();
            if ($status === 409) {
                $this->attempts->persistFailure(
                    $attemptId,
                    MtUniCreditControlPanelErrorClass::CONFLICT,
                    MtUniCreditFinancingAttemptState::CP_FAILED_RETRYABLE
                );

                return MtUniCreditControlPanelOrderSubmissionResult::fail(
                    MtUniCreditControlPanelErrorClass::CONFLICT,
                    false,
                    409
                );
            }
            if ($status >= 400 && $status < 500) {
                $this->attempts->persistFailure(
                    $attemptId,
                    MtUniCreditControlPanelErrorClass::REJECTED,
                    MtUniCreditFinancingAttemptState::CP_FAILED_RETRYABLE
                );

                return MtUniCreditControlPanelOrderSubmissionResult::fail(
                    MtUniCreditControlPanelErrorClass::REJECTED,
                    $status === 422 || $status === 429,
                    $status
                );
            }
            $this->attempts->persistFailure(
                $attemptId,
                MtUniCreditControlPanelErrorClass::TRANSPORT_FAILED,
                MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN
            );

            return MtUniCreditControlPanelOrderSubmissionResult::fail(
                MtUniCreditControlPanelErrorClass::TRANSPORT_FAILED,
                false,
                $status,
                true
            );
        } catch (MtUniCreditCpInvalidPayloadException $exception) {
            $this->attempts->persistFailure(
                $attemptId,
                MtUniCreditControlPanelErrorClass::INVALID_RESPONSE,
                MtUniCreditFinancingAttemptState::CP_FAILED_RETRYABLE
            );

            return MtUniCreditControlPanelOrderSubmissionResult::fail(
                MtUniCreditControlPanelErrorClass::INVALID_RESPONSE,
                true
            );
        } catch (MtUniCreditCpMalformedJsonException $exception) {
            // Response received but unusable — remote side-effect cannot be excluded.
            $this->attempts->persistFailure(
                $attemptId,
                MtUniCreditControlPanelErrorClass::INVALID_RESPONSE,
                MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN
            );

            return MtUniCreditControlPanelOrderSubmissionResult::fail(
                MtUniCreditControlPanelErrorClass::INVALID_RESPONSE,
                false,
                null,
                true
            );
        } catch (Exception $exception) {
            $this->attempts->persistFailure(
                $attemptId,
                MtUniCreditControlPanelErrorClass::TRANSPORT_FAILED,
                MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN
            );

            return MtUniCreditControlPanelOrderSubmissionResult::fail(
                MtUniCreditControlPanelErrorClass::TRANSPORT_FAILED,
                false,
                null,
                true
            );
        }
    }

    /**
     * After definitive CP create success: Process 2 is a no-op; Process 1 runs SmartUCF.
     *
     * @param int $attemptId
     * @param int $cpId
     * @param bool $localReplay
     * @param array<string, mixed> $order
     * @param array<int, array<string, mixed>> $orderProducts
     * @param MtUniCreditCalculationResult $calculation
     * @param array<string, mixed> $shop
     * @return MtUniCreditControlPanelOrderSubmissionResult
     */
    private function continueAfterCpCreated(
        $attemptId,
        $cpId,
        $localReplay,
        array $order,
        array $orderProducts,
        MtUniCreditCalculationResult $calculation,
        array $shop
    ) {
        $row = $this->attempts->findById($attemptId);
        $storeId = $row !== null ? (int) $row['store_id'] : (int) (isset($order['store_id']) ? $order['store_id'] : 0);
        $entryPoint = $row !== null && isset($row['entry_point'])
            ? (string) $row['entry_point']
            : MtUniCreditOperationEntryPoint::CHECKOUT;
        $localOrderId = (int) (isset($order['order_id']) ? $order['order_id'] : 0);
        $rawProces = MtUniCreditShopProcessContext::rawUniProces($shop);
        $normalized = MtUniCreditShopProcessContext::normalized($shop);
        $log = $this->resolvePhase9Log();
        $safeIds = array(
            'order_id' => $localOrderId,
            'attempt_id' => (int) $attemptId,
            'control_panel_order_id' => (int) $cpId,
            'entry_point' => $entryPoint,
        );
        $log->record($storeId, $localOrderId, $entryPoint, MtUniCreditPhase9LifecycleLog::EVENT_PROCESS_RAW, array_merge(
            $safeIds,
            array('uni_proces' => $rawProces)
        ));
        $log->record($storeId, $localOrderId, $entryPoint, MtUniCreditPhase9LifecycleLog::EVENT_PROCESS_NORMALIZED, array_merge(
            $safeIds,
            array('process' => $normalized)
        ));

        if ($normalized === MtUniCreditShopProcessContext::PROCESS_2) {
            $log->record($storeId, $localOrderId, $entryPoint, MtUniCreditPhase9LifecycleLog::EVENT_SKIP, array_merge(
                $safeIds,
                array('reason' => 'process2')
            ));

            return MtUniCreditControlPanelOrderSubmissionResult::ok($cpId, $localReplay);
        }

        $coordinator = $this->resolveProcess1Coordinator();
        if ($coordinator === null) {
            $log->record($storeId, $localOrderId, $entryPoint, MtUniCreditPhase9LifecycleLog::EVENT_SKIP, array_merge(
                $safeIds,
                array('reason' => 'coordinator_unavailable')
            ));

            return MtUniCreditControlPanelOrderSubmissionResult::failAfterCp(
                $cpId,
                $localReplay,
                'smartucf_not_wired',
                true,
                false,
                MtUniCreditSmartUcfSessionCoordinator::CUSTOMER_FAILED
            );
        }

        $shop = MtUniCreditShopProcessContext::hydrateSmartUcfCredentials(
            $shop,
            $storeId,
            $this->resolveSmartUcfCredentials()
        );

        $log->record($storeId, $localOrderId, $entryPoint, MtUniCreditPhase9LifecycleLog::EVENT_ENTER, $safeIds);
        $coordinator->setLifecycleLog($log, $storeId, $localOrderId, $entryPoint, (int) $attemptId, (int) $cpId);

        try {
            $process1 = $coordinator->run(
                $attemptId,
                $shop,
                $order,
                $orderProducts,
                $calculation,
                $localOrderId,
                $cpId,
                $this->resolveBankStatuses()
            );
        } catch (Exception $exception) {
            $log->record($storeId, $localOrderId, $entryPoint, MtUniCreditPhase9LifecycleLog::EVENT_SMARTUCF_RESULT, array_merge(
                $safeIds,
                array('kind' => 'exception')
            ));

            return MtUniCreditControlPanelOrderSubmissionResult::failAfterCp(
                $cpId,
                $localReplay,
                'smartucf_submit_failed',
                true,
                false,
                MtUniCreditSmartUcfSessionCoordinator::CUSTOMER_FAILED
            );
        }
        if ($process1->isProcess2()) {
            $log->record($storeId, $localOrderId, $entryPoint, MtUniCreditPhase9LifecycleLog::EVENT_SKIP, array_merge(
                $safeIds,
                array('reason' => 'process2')
            ));

            return MtUniCreditControlPanelOrderSubmissionResult::ok($cpId, $localReplay);
        }
        if ($process1->isCreated()) {
            return MtUniCreditControlPanelOrderSubmissionResult::ok(
                $cpId,
                $localReplay,
                $process1->redirectUrl()
            );
        }
        if ($process1->isProcessing() || $process1->isOutcomeUnknown()) {
            return MtUniCreditControlPanelOrderSubmissionResult::failAfterCp(
                $cpId,
                $localReplay,
                $process1->isProcessing() ? 'smartucf_processing' : 'smartucf_outcome_unknown',
                false,
                true,
                $process1->customerMessage() !== ''
                    ? $process1->customerMessage()
                    : self::CUSTOMER_AMBIGUOUS_MESSAGE,
                false
            );
        }

        $errorClass = $process1->errorClass() !== '' ? $process1->errorClass() : 'smartucf_submit_failed';
        // OC4 Checkout: definitive SmartUCF remote_reject applies UniCredit payment status
        // so the commerce order leaves Voided/0 — not because CP alone succeeded.
        $applyNative = !$process1->isRetryable()
            && $errorClass === MtUniCreditSmartUcfFailureClassification::CLASS_REMOTE_REJECT;

        return MtUniCreditControlPanelOrderSubmissionResult::failAfterCp(
            $cpId,
            $localReplay,
            $errorClass,
            $process1->isRetryable(),
            false,
            $process1->customerMessage() !== ''
                ? $process1->customerMessage()
                : MtUniCreditSmartUcfSessionCoordinator::CUSTOMER_FAILED,
            $applyNative
        );
    }

    /**
     * @return MtUniCreditSmartUcfSessionCoordinator|null
     */
    private function resolveProcess1Coordinator()
    {
        if ($this->process1 instanceof MtUniCreditSmartUcfSessionCoordinator) {
            return $this->process1;
        }

        try {
            $this->process1 = MtUniCreditProcess1ServiceFactory::coordinator($this->attempts->database());
        } catch (Exception $exception) {
            return null;
        }

        return $this->process1;
    }

    /**
     * @return MtUniCreditOrderBankStatusRepository|null
     */
    private function resolveBankStatuses()
    {
        if ($this->bankStatuses instanceof MtUniCreditOrderBankStatusRepository) {
            return $this->bankStatuses;
        }

        try {
            $this->bankStatuses = MtUniCreditProcess1ServiceFactory::bankStatuses($this->attempts->database());
        } catch (Exception $exception) {
            return null;
        }

        return $this->bankStatuses;
    }

    /**
     * @return MtUniCreditPhase9LifecycleLog
     */
    private function resolvePhase9Log()
    {
        if ($this->phase9Log instanceof MtUniCreditPhase9LifecycleLog) {
            return $this->phase9Log;
        }
        try {
            $this->phase9Log = new MtUniCreditPhase9LifecycleLog($this->attempts->database());
        } catch (Exception $exception) {
            $this->phase9Log = new MtUniCreditPhase9LifecycleLog();
        }

        return $this->phase9Log;
    }

    /**
     * @return MtUniCreditSmartucfCredentialsRepository|null
     */
    private function resolveSmartUcfCredentials()
    {
        try {
            return MtUniCreditBootstrap::smartucfCredentialsRepositoryFromDb($this->attempts->database());
        } catch (Exception $exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $order
     * @param array<int, array<string, mixed>> $orderProducts
     * @param MtUniCreditCalculationResult $calculation
     * @param array<string, mixed> $shop
     * @return array<string, mixed>|null
     */
    private function resolveFrozenPayload(array $row, array $order, array $orderProducts, MtUniCreditCalculationResult $calculation, array $shop)
    {
        $raw = isset($row['cp_payload']) ? $row['cp_payload'] : null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['order_id'])) {
                return $decoded;
            }
        }

        return $this->payloadBuilder->build((int) $row['order_id'], $order, $orderProducts, $calculation, $shop);
    }

    /**
     * @param int $attemptId
     * @param string $currentState
     * @return bool
     */
    private function enterSubmitting($attemptId, $currentState)
    {
        if ($currentState === MtUniCreditFinancingAttemptState::CP_CREATED) {
            return false;
        }
        if ($currentState === MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN) {
            return false;
        }

        return $this->attempts->transitionFromStates(
            $attemptId,
            array(
                MtUniCreditFinancingAttemptState::ORDER_CREATED,
                MtUniCreditFinancingAttemptState::CP_FAILED_RETRYABLE,
            ),
            MtUniCreditFinancingAttemptState::CP_SUBMITTING
        );
    }
}
