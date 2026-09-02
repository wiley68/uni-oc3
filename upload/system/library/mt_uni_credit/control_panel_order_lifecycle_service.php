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

    /**
     * @param MtUniCreditFinancingAttemptRepository $attempts
     * @param MtUniCreditOperationLockRepository $locks
     * @param MtUniCreditControlPanelClient $client
     * @param MtUniCreditControlPanelOrderPayloadBuilder|null $payloadBuilder
     */
    public function __construct(
        MtUniCreditFinancingAttemptRepository $attempts,
        MtUniCreditOperationLockRepository $locks,
        MtUniCreditControlPanelClient $client,
        $payloadBuilder = null
    ) {
        $this->attempts = $attempts;
        $this->locks = $locks;
        $this->client = $client;
        $this->payloadBuilder = $payloadBuilder instanceof MtUniCreditControlPanelOrderPayloadBuilder
            ? $payloadBuilder
            : new MtUniCreditControlPanelOrderPayloadBuilder();
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

        if (!$this->locks->acquire(
            $storeId,
            MtUniCreditOperationEntryPoint::CHECKOUT,
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
                MtUniCreditOperationEntryPoint::CHECKOUT,
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
            return MtUniCreditControlPanelOrderSubmissionResult::ok($existingCpId, true);
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
                return MtUniCreditControlPanelOrderSubmissionResult::ok($freshCp, true);
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

            return MtUniCreditControlPanelOrderSubmissionResult::ok($cpId);
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
