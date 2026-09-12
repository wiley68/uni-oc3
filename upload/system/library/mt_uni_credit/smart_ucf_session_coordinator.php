<?php

/**
 * Coordinates Process 1 SmartUCF session create with durable lifecycle transitions.
 *
 * After confirmed SmartUCF success (or definitive remote reject), admits a durable CP
 * status-sync target, writes local bank status, then PATCHes Control Panel `/orders/status`
 * via the status sync service (shop order_id). CP PATCH failure after SmartUCF success is
 * recoverable — SmartUCF stays created and the pending target is retained.
 */
final class MtUniCreditSmartUcfSessionCoordinator
{
    const ERROR_CERTIFICATE_INVALID = 'smartucf_certificate_invalid';
    const ERROR_CREDENTIALS_SYNC_FAILED = 'smartucf_credentials_sync_failed';
    const ERROR_CREDENTIALS_INCOMPLETE = 'smartucf_credentials_incomplete';

    /** Recoverable: SmartUCF already succeeded; CP PATCH /orders/status did not. */
    const ERROR_CP_BANK_STATUS_SYNC_PENDING = 'cp_bank_status_sync_pending';

    const CUSTOMER_OUTCOME_UNKNOWN =
    'Поръчката е създадена, но потвърждението от банковата система не беше получено. Не изпращайте заявката повторно.';
    const CUSTOMER_PROCESSING = 'Заявката към банката се обработва. Моля, изчакайте.';
    const CUSTOMER_FAILED =
    'Поръчката и заявката в Контролния панел са създадени, но изпращането към банковата система не беше успешно.';

    /** @var MtUniCreditSmartUcfLifecycleRepository */
    private $lifecycle;

    /** @var object */
    private $client;

    /** @var MtUniCreditSmartUcfFailureClassifier */
    private $classifier;

    /** @var MtUniCreditCertificateLocalPaths */
    private $certificatePaths;

    /** @var MtUniCreditMtlsPrivateKeyPassphraseProvider */
    private $passphrases;

    /** @var MtUniCreditCertificatePairValidator */
    private $certificateValidator;

    /** @var MtUniCreditSmartUcfPayloadBuilder */
    private $payloadBuilder;

    /** @var MtUniCreditCertificateSynchronizer|null */
    private $certificateSynchronizer;

    /** @var MtUniCreditControlPanelClient|null */
    private $controlPanel;

    /** @var MtUniCreditControlPanelStatusSyncService|null */
    private $statusSync;

    /** @var MtUniCreditPhase9LifecycleLog|null */
    private $phase9Log;

    /** @var MtUniCreditDiagnosticJournal|null */
    private $diagnosticJournal;

    /** @var int */
    private $logStoreId = 0;

    /** @var string Canonical shop order id for diagnostics (empty when unset) */
    private $logOrderId = '';

    /** @var string */
    private $logEntryPoint = '';

    /** @var int */
    private $logAttemptId = 0;

    /** @var int */
    private $logCpOrderId = 0;

    /**
     * @param MtUniCreditSmartUcfLifecycleRepository $lifecycle
     * @param object $client Must provide createSession()
     * @param MtUniCreditSmartUcfFailureClassifier|null $classifier
     * @param MtUniCreditCertificateLocalPaths|null $certificatePaths
     * @param MtUniCreditMtlsPrivateKeyPassphraseProvider|null $passphrases
     * @param MtUniCreditCertificatePairValidator|null $certificateValidator
     * @param MtUniCreditSmartUcfPayloadBuilder|null $payloadBuilder
     * @param MtUniCreditCertificateSynchronizer|null $certificateSynchronizer
     * @param MtUniCreditControlPanelClient|null $controlPanel
     * @param MtUniCreditControlPanelStatusSyncService|null $statusSync
     */
    public function __construct(
        MtUniCreditSmartUcfLifecycleRepository $lifecycle,
        $client,
        $classifier = null,
        $certificatePaths = null,
        $passphrases = null,
        $certificateValidator = null,
        $payloadBuilder = null,
        $certificateSynchronizer = null,
        $controlPanel = null,
        $statusSync = null
    ) {
        if (!is_object($client) || !method_exists($client, 'createSession')) {
            throw new InvalidArgumentException('SmartUCF client must provide createSession().');
        }
        $this->lifecycle = $lifecycle;
        $this->client = $client;
        $this->classifier = $classifier instanceof MtUniCreditSmartUcfFailureClassifier
            ? $classifier
            : new MtUniCreditSmartUcfFailureClassifier();
        $this->certificatePaths = $certificatePaths instanceof MtUniCreditCertificateLocalPaths
            ? $certificatePaths
            : new MtUniCreditCertificateLocalPaths();
        $this->passphrases = $passphrases instanceof MtUniCreditMtlsPrivateKeyPassphraseProvider
            ? $passphrases
            : new MtUniCreditMtlsPrivateKeyPassphraseProvider();
        $this->certificateValidator = $certificateValidator instanceof MtUniCreditCertificatePairValidator
            ? $certificateValidator
            : new MtUniCreditCertificatePairValidator();
        $this->payloadBuilder = $payloadBuilder instanceof MtUniCreditSmartUcfPayloadBuilder
            ? $payloadBuilder
            : new MtUniCreditSmartUcfPayloadBuilder();
        $this->certificateSynchronizer = $certificateSynchronizer instanceof MtUniCreditCertificateSynchronizer
            ? $certificateSynchronizer
            : null;
        $this->controlPanel = $controlPanel instanceof MtUniCreditControlPanelClient
            ? $controlPanel
            : null;
        $this->statusSync = $statusSync instanceof MtUniCreditControlPanelStatusSyncService
            ? $statusSync
            : null;
        $this->phase9Log = null;
        $this->diagnosticJournal = null;
    }

    /**
     * Attach safe lifecycle diagnostics for the current attempt.
     *
     * @param MtUniCreditPhase9LifecycleLog $log
     * @param int $storeId
     * @param int $orderId
     * @param string $entryPoint
     * @param int $attemptId
     * @param int $cpOrderId
     * @param MtUniCreditDiagnosticJournal|null $journal
     * @return void
     */
    public function setLifecycleLog($log, $storeId, $orderId, $entryPoint, $attemptId, $cpOrderId, $journal = null)
    {
        if ($log instanceof MtUniCreditPhase9LifecycleLog) {
            $this->phase9Log = $log;
        }
        if ($journal instanceof MtUniCreditDiagnosticJournal) {
            $this->diagnosticJournal = $journal;
        }
        $this->logStoreId = (int) $storeId;
        $canonicalLogOrder = MtUniCreditShopOrderId::tryNormalize($orderId);
        $this->logOrderId = $canonicalLogOrder !== null ? $canonicalLogOrder : '';
        $this->logEntryPoint = (string) $entryPoint;
        $this->logAttemptId = (int) $attemptId;
        $this->logCpOrderId = (int) $cpOrderId;
    }

    /**
     * @param int $attemptId
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $order
     * @param array<int, array<string, mixed>> $orderProducts
     * @param MtUniCreditCalculationResult $calculation
     * @param int|string $localOrderId
     * @param int|string $cpOrderId Control Panel internal id (diagnostics only; PATCH uses shop order_id)
     * @param MtUniCreditOrderBankStatusRepository|null $bankStatuses
     * @param string $authoritativeShopUnicid Current shop module UNICID (credentials), never attempt-row UNICID
     * @return MtUniCreditSmartUcfCoordinationResult
     */
    public function run(
        $attemptId,
        array $shop,
        array $order,
        array $orderProducts,
        MtUniCreditCalculationResult $calculation,
        $localOrderId,
        $cpOrderId,
        $bankStatuses = null,
        $authoritativeShopUnicid = ''
    ) {
        $attemptId = (int) $attemptId;
        $storeId = (int) (isset($order['store_id']) ? $order['store_id'] : 0);
        $authoritativeShopUnicid = is_string($authoritativeShopUnicid)
            ? trim($authoritativeShopUnicid)
            : '';

        if (MtUniCreditShopConfigurationFlags::isSecondaryProcess($shop)) {
            $this->logEvent(MtUniCreditPhase9LifecycleLog::EVENT_SKIP, array('reason' => 'process2'));

            return MtUniCreditSmartUcfCoordinationResult::process2();
        }

        $row = $this->lifecycle->readAndNormalize($attemptId);
        if ($row === null) {
            return MtUniCreditSmartUcfCoordinationResult::failed(
                self::CUSTOMER_FAILED,
                true,
                MtUniCreditSmartUcfFailureClassification::CLASS_PRE_SEND
            );
        }
        $known = $this->resultFromState($row);
        if ($known !== null) {
            // Replay of proven SmartUCF success: never re-create session; restore local then PATCH.
            if ($known->isCreated()) {
                $this->reconcileProcess1BankStatusOnReplay(
                    $attemptId,
                    $storeId,
                    $localOrderId,
                    $authoritativeShopUnicid,
                    $bankStatuses
                );
            }

            return $known;
        }

        // Fail closed before certificate work or claim when SmartUCF credentials are incomplete.
        if (!$this->shopHasCompleteSmartUcfCredentials($shop)) {
            $errorClass = self::ERROR_CREDENTIALS_INCOMPLETE;
            $this->logEvent(MtUniCreditPhase9LifecycleLog::EVENT_SMARTUCF_RESULT, array(
                'kind' => 'failed',
                'error_class' => $errorClass,
            ));
            try {
                $this->lifecycle->markFailed($attemptId, $errorClass, true);
            } catch (Throwable $ignored) {
            }

            return MtUniCreditSmartUcfCoordinationResult::failed(
                self::CUSTOMER_FAILED,
                true,
                $errorClass
            );
        }

        $certPath = null;
        $keyPath = null;
        $passphrase = '';
        $lease = null;
        if (MtUniCreditShopConfigurationFlags::usesSmartUcfCertificate($shop)) {
            try {
                if ($this->certificateSynchronizer instanceof MtUniCreditCertificateSynchronizer) {
                    $lease = $this->certificateSynchronizer->ensureCurrent();
                    $certPath = $lease->certificatePath();
                    $keyPath = $lease->privateKeyPath();
                    $passphrase = $lease->password();
                } else {
                    $certPath = $this->certificatePaths->certificatePath();
                    $keyPath = $this->certificatePaths->privateKeyPath();
                    $passphrase = $this->passphrases->requirePassphrase($this->certificatePaths->passphrasePath());
                    $validation = $this->certificateValidator->validate($certPath, $keyPath, $passphrase);
                    if (empty($validation['ok'])) {
                        throw new RuntimeException('SmartUCF certificate pair validation failed.');
                    }
                }
            } catch (MtUniCreditCertificateSyncException $exception) {
                $errorClass = self::ERROR_CREDENTIALS_SYNC_FAILED . ':' . $exception->reason();
                $this->logEvent(MtUniCreditPhase9LifecycleLog::EVENT_SMARTUCF_RESULT, array(
                    'kind' => 'failed',
                    'error_class' => $errorClass,
                ));
                $this->recordSupportEvent(
                    MtUniCreditDiagnosticJournal::EVENT_CERTIFICATE_SYNC_FAILED,
                    null,
                    array('error_class' => $errorClass)
                );
                try {
                    $this->lifecycle->markFailed($attemptId, $errorClass, true);
                } catch (Throwable $ignored) {
                }

                return MtUniCreditSmartUcfCoordinationResult::failed(
                    self::CUSTOMER_FAILED,
                    true,
                    $errorClass
                );
            } catch (Throwable $exception) {
                try {
                    $this->lifecycle->markFailed($attemptId, self::ERROR_CERTIFICATE_INVALID, true);
                } catch (Throwable $ignored) {
                }
                $this->recordSupportEvent(
                    MtUniCreditDiagnosticJournal::EVENT_CERTIFICATE_SYNC_FAILED,
                    null,
                    array('error_class' => self::ERROR_CERTIFICATE_INVALID)
                );

                // Local cert failure: retryable failed — do NOT write bank_send_failed_smartucf.
                return MtUniCreditSmartUcfCoordinationResult::failed(
                    self::CUSTOMER_FAILED,
                    true,
                    self::ERROR_CERTIFICATE_INVALID
                );
            }
        }

        $claimed = $this->lifecycle->claimForSubmitting($attemptId);
        if ($claimed === null) {
            $latest = $this->lifecycle->readAndNormalize($attemptId);
            if ($latest === null) {
                return MtUniCreditSmartUcfCoordinationResult::processing(self::CUSTOMER_PROCESSING);
            }
            $fromLatest = $this->resultFromState($latest);
            if ($fromLatest !== null) {
                if ($fromLatest->isCreated()) {
                    $this->reconcileProcess1BankStatusOnReplay(
                        $attemptId,
                        $storeId,
                        $localOrderId,
                        $authoritativeShopUnicid,
                        $bankStatuses
                    );
                }

                return $fromLatest;
            }

            return MtUniCreditSmartUcfCoordinationResult::processing(self::CUSTOMER_PROCESSING);
        }

        try {
            $this->logEvent(MtUniCreditPhase9LifecycleLog::EVENT_SMARTUCF_BEGIN, array());
            // Payload build is best-effort for diagnostics; client prepares authoritatively.
            try {
                $this->payloadBuilder->build($shop, $order, $orderProducts, $calculation, $localOrderId);
            } catch (Throwable $ignored) {
            }

            $session = $this->client->createSession(
                $shop,
                $order,
                $orderProducts,
                $calculation,
                $localOrderId,
                $certPath,
                $keyPath,
                $passphrase
            );
        } catch (Throwable $exception) {
            if ($lease instanceof MtUniCreditCertificateConsumerLease) {
                $lease->release();
            }
            return $this->handleFailure($attemptId, $storeId, $localOrderId, $exception, $bankStatuses);
        }

        if ($lease instanceof MtUniCreditCertificateConsumerLease) {
            $lease->release();
        }

        $bodies = MtUniCreditSmartUcfSessionClient::diagnosticBodiesFromSessionResult($session);

        try {
            $this->lifecycle->markCreated(
                $attemptId,
                (string) $session['session_id'],
                (string) $session['redirect_url'],
                (int) (isset($session['http_code']) ? $session['http_code'] : 0)
            );
        } catch (Throwable $exception) {
            try {
                $this->lifecycle->markOutcomeUnknown(
                    $attemptId,
                    MtUniCreditSmartUcfFailureClassification::CLASS_TRANSPORT_AMBIGUOUS,
                    (int) (isset($session['http_code']) ? $session['http_code'] : 0)
                );
            } catch (Throwable $ignored) {
            }
            $this->recordSmartUcfSupport(
                MtUniCreditDiagnosticJournal::EVENT_TRANSPORT_AMBIGUOUS,
                $bodies['endpoint'],
                $bodies['request'],
                $bodies['response'],
                $bodies['http_code'],
                'markCreated failed after SmartUCF response'
            );

            return MtUniCreditSmartUcfCoordinationResult::outcomeUnknown(self::CUSTOMER_OUTCOME_UNKNOWN);
        }

        $this->logEvent(MtUniCreditPhase9LifecycleLog::EVENT_SMARTUCF_RESULT, array(
            'kind' => 'created',
            'bank_status' => MtUniCreditBankStatus::SENT_PROCESS1,
        ));
        $this->recordSmartUcfSupport(
            MtUniCreditDiagnosticJournal::EVENT_SUCCESS,
            $bodies['endpoint'],
            $bodies['request'],
            $bodies['response'],
            $bodies['http_code'],
            null
        );
        $this->persistProcess1BankStatus($attemptId, $storeId, $localOrderId, $bankStatuses);

        return MtUniCreditSmartUcfCoordinationResult::created(
            (string) $session['redirect_url'],
            (string) $session['session_id']
        );
    }

    /**
     * Complete decrypted SmartUCF pair required before any session network call.
     *
     * @param array<string, mixed> $shop
     * @return bool
     */
    private function shopHasCompleteSmartUcfCredentials(array $shop)
    {
        $user = isset($shop['uni_user']) && is_string($shop['uni_user']) ? trim($shop['uni_user']) : '';
        $password = isset($shop['uni_password']) && is_string($shop['uni_password'])
            ? trim($shop['uni_password'])
            : '';

        return $user !== '' && $password !== '';
    }

    /**
     * @param string $eventCode
     * @param array<string, mixed> $summary
     * @return void
     */
    private function logEvent($eventCode, array $summary)
    {
        if (!$this->phase9Log instanceof MtUniCreditPhase9LifecycleLog) {
            return;
        }
        $this->phase9Log->record(
            $this->logStoreId,
            $this->logOrderId,
            $this->logEntryPoint !== '' ? $this->logEntryPoint : MtUniCreditOperationEntryPoint::CHECKOUT,
            $eventCode,
            array_merge(
                array(
                    'order_id' => $this->logOrderId,
                    'attempt_id' => $this->logAttemptId,
                    'control_panel_order_id' => $this->logCpOrderId,
                    'entry_point' => $this->logEntryPoint,
                ),
                $summary
            )
        );
    }

    /**
     * @param string $eventCode
     * @param int|null $httpStatus
     * @param array<string, mixed> $extra
     * @return void
     */
    private function recordSupportEvent($eventCode, $httpStatus, array $extra = array())
    {
        if (!$this->diagnosticJournal instanceof MtUniCreditDiagnosticJournal || $this->logOrderId === '') {
            return;
        }
        $this->diagnosticJournal->record(
            $this->logStoreId,
            $this->logOrderId,
            $this->logEntryPoint !== '' ? $this->logEntryPoint : MtUniCreditOperationEntryPoint::CHECKOUT,
            $eventCode,
            $httpStatus,
            array_merge(
                array(
                    'order_id' => $this->logOrderId,
                    'attempt_id' => $this->logAttemptId,
                    'control_panel_order_id' => $this->logCpOrderId,
                ),
                $extra
            )
        );
    }

    /**
     * @param string $eventCode
     * @param string $endpoint
     * @param mixed $request
     * @param mixed $response
     * @param int $httpStatus
     * @param string|null $transportError
     * @return void
     */
    private function recordSmartUcfSupport($eventCode, $endpoint, $request, $response, $httpStatus, $transportError)
    {
        if (!$this->diagnosticJournal instanceof MtUniCreditDiagnosticJournal || $this->logOrderId === '') {
            return;
        }
        $this->diagnosticJournal->recordSmartUcfSession(
            $this->logStoreId,
            $this->logOrderId,
            $this->logEntryPoint !== '' ? $this->logEntryPoint : MtUniCreditOperationEntryPoint::CHECKOUT,
            $endpoint,
            $request,
            $response,
            $httpStatus,
            $transportError,
            $eventCode
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return MtUniCreditSmartUcfCoordinationResult|null
     */
    private function resultFromState(array $row)
    {
        $state = (string) (isset($row['smartucf_state'])
            ? $row['smartucf_state']
            : MtUniCreditSmartUcfLifecycleStates::NOT_STARTED);
        if ($state === MtUniCreditSmartUcfLifecycleStates::CREATED) {
            $redirect = (string) (isset($row['smartucf_redirect_url']) ? $row['smartucf_redirect_url'] : '');
            $session = (string) (isset($row['smartucf_session_id']) ? $row['smartucf_session_id'] : '');
            if ($redirect !== '' && (new MtUniCreditSmartUcfEndpointPolicy())->isTrustedApplicationRedirect($redirect)) {
                return MtUniCreditSmartUcfCoordinationResult::created($redirect, $session);
            }

            return MtUniCreditSmartUcfCoordinationResult::outcomeUnknown(self::CUSTOMER_OUTCOME_UNKNOWN);
        }
        if ($state === MtUniCreditSmartUcfLifecycleStates::SUBMITTING) {
            return MtUniCreditSmartUcfCoordinationResult::processing(self::CUSTOMER_PROCESSING);
        }
        if ($state === MtUniCreditSmartUcfLifecycleStates::OUTCOME_UNKNOWN) {
            return MtUniCreditSmartUcfCoordinationResult::outcomeUnknown(self::CUSTOMER_OUTCOME_UNKNOWN);
        }
        if ($state === MtUniCreditSmartUcfLifecycleStates::FAILED && empty($row['smartucf_retryable'])) {
            return MtUniCreditSmartUcfCoordinationResult::failed(
                self::CUSTOMER_FAILED,
                false,
                (string) (isset($row['smartucf_error_class']) ? $row['smartucf_error_class'] : '')
            );
        }

        return null;
    }

    /**
     * @param int $attemptId
     * @param int $storeId
     * @param int|string $localOrderId
     * @param Throwable $exception
     * @param MtUniCreditOrderBankStatusRepository|null $bankStatuses
     * @return MtUniCreditSmartUcfCoordinationResult
     */
    private function handleFailure($attemptId, $storeId, $localOrderId, $exception, $bankStatuses)
    {
        $classification = $this->classifier->classifyThrowable($exception);
        $supportEvent = $classification->targetState() === MtUniCreditSmartUcfLifecycleStates::OUTCOME_UNKNOWN
            ? MtUniCreditDiagnosticJournal::EVENT_TRANSPORT_AMBIGUOUS
            : MtUniCreditDiagnosticJournal::EVENT_REMOTE_REJECT;

        $transportError = null;
        $httpCode = $classification->httpCode();
        $requestBody = null;
        $responseBody = null;
        $endpoint = '';
        if ($exception instanceof MtUniCreditSmartUcfSessionException) {
            $httpCode = $exception->httpCode() > 0 ? $exception->httpCode() : $httpCode;
            $endpoint = $exception->endpoint();
            $sentRequest = $exception->requestBody();
            if ($sentRequest !== '') {
                $requestBody = $sentRequest;
            }
            $rawResponse = $exception->rawResponse();
            if (
                $classification->targetState() === MtUniCreditSmartUcfLifecycleStates::OUTCOME_UNKNOWN
                || $exception->failureKind() === MtUniCreditSmartUcfSessionException::KIND_TRANSPORT
            ) {
                $transportError = $exception->getMessage();
                // Timeout / ambiguous transport: keep response null when body is empty (shared CP contract).
                if ($rawResponse !== '') {
                    $responseBody = $rawResponse;
                }
            } elseif ($rawResponse !== '') {
                $responseBody = $rawResponse;
            }
        }

        $this->logEvent(MtUniCreditPhase9LifecycleLog::EVENT_SMARTUCF_RESULT, array(
            'kind' => $classification->targetState() === MtUniCreditSmartUcfLifecycleStates::OUTCOME_UNKNOWN
                ? 'outcome_unknown'
                : 'failed',
            'error_class' => $classification->errorClass(),
        ));
        $this->recordSmartUcfSupport(
            $supportEvent,
            $endpoint,
            $requestBody,
            $responseBody,
            $httpCode,
            $transportError
        );

        if ($classification->targetState() === MtUniCreditSmartUcfLifecycleStates::OUTCOME_UNKNOWN) {
            try {
                $this->lifecycle->markOutcomeUnknown(
                    $attemptId,
                    $classification->errorClass(),
                    $classification->httpCode()
                );
            } catch (Throwable $ignored) {
            }

            return MtUniCreditSmartUcfCoordinationResult::outcomeUnknown(self::CUSTOMER_OUTCOME_UNKNOWN);
        }

        try {
            $this->lifecycle->markFailed(
                $attemptId,
                $classification->errorClass(),
                $classification->isRetryable(),
                $classification->httpCode()
            );
        } catch (Throwable $ignored) {
        }
        if ($classification->errorClass() === MtUniCreditSmartUcfFailureClassification::CLASS_REMOTE_REJECT) {
            $this->persistFailureBankStatus($attemptId, $storeId, $localOrderId, $bankStatuses);
        }

        return MtUniCreditSmartUcfCoordinationResult::failed(
            self::CUSTOMER_FAILED,
            $classification->isRetryable(),
            $classification->errorClass()
        );
    }

    /**
     * After proven SmartUCF Process 1 success:
     * admit durable pending target → local bank_sent_process1 → PATCH from target.
     * On CONFLICT: stop with no local mutation and no PATCH.
     * Local write failure: log and keep pending target (do not swallow silently).
     *
     * @param int $attemptId
     * @param int $storeId
     * @param int|string $localOrderId
     * @param MtUniCreditOrderBankStatusRepository|null $bankStatuses
     * @return void
     */
    private function persistProcess1BankStatus($attemptId, $storeId, $localOrderId, $bankStatuses)
    {
        if (!$this->statusSync instanceof MtUniCreditControlPanelStatusSyncService) {
            return;
        }

        $status = MtUniCreditBankStatus::process1Sent();
        $shopOrderId = MtUniCreditShopOrderId::tryNormalize($localOrderId);
        if ($shopOrderId === null) {
            error_log(
                'mt_uni_credit: Process 1 durable target blocked by non-canonical order_id'
                    . ' attempt_id=' . (int) $attemptId
                    . ' order_id=' . (string) $localOrderId
            );

            return;
        }
        $attemptId = (int) $attemptId;
        $storeId = (int) $storeId;

        $decision = $this->statusSync->admitTarget(
            $attemptId,
            $status['status_id'],
            $status['status_label']
        );
        if (
            $decision === MtUniCreditControlPanelStatusSyncService::CONFLICT
            || $decision === MtUniCreditControlPanelStatusSyncService::REJECT
        ) {
            error_log(
                'mt_uni_credit: Process 1 durable target admission blocked'
                    . ' decision=' . $decision
                    . ' attempt_id=' . $attemptId
                    . ' status_id=' . $status['status_id']
            );

            return;
        }

        if ($decision === MtUniCreditControlPanelStatusSyncService::SAME) {
            $existing = $this->statusSync->readPersistedTarget($attemptId);
            if (
                $existing === null
                || !$this->isExactCanonicalProcess1Target(
                    isset($existing['status_id']) ? $existing['status_id'] : null,
                    isset($existing['status']) ? $existing['status'] : null
                )
            ) {
                error_log(
                    'mt_uni_credit: Process 1 durable SAME admission without exact P1 target'
                        . ' attempt_id=' . $attemptId
                );

                return;
            }
        }

        try {
            $this->writeLocalBankStatus($storeId, $localOrderId, $status, $bankStatuses);
        } catch (Throwable $exception) {
            error_log(
                'mt_uni_credit: Process 1 local bank status write failed'
                    . ' attempt_id=' . $attemptId
                    . ' store_id=' . $storeId
                    . ' order_id=' . $shopOrderId
                    . ' class=' . get_class($exception)
                    . ' (pending CP target retained)'
            );

            return;
        }

        $syncState = $this->statusSync->retryPending($attemptId, $shopOrderId);
        if (
            $syncState === MtUniCreditControlPanelStatusSyncStates::PENDING
            || $syncState === MtUniCreditControlPanelStatusSyncStates::TERMINAL_FAILED
        ) {
            error_log(
                'mt_uni_credit: ' . self::ERROR_CP_BANK_STATUS_SYNC_PENDING
                    . ' attempt_id=' . $attemptId
                    . ' store_id=' . $storeId
                    . ' order_id=' . $shopOrderId
                    . ' status_id=' . $status['status_id']
                    . ' sync_state=' . $syncState
            );
            $this->recordSupportEvent(
                MtUniCreditDiagnosticJournal::EVENT_CP_STATUS_PATCH_FAILED,
                null,
                array(
                    'bank_status' => $status['status_id'],
                    'sync_state' => $syncState,
                )
            );
        } else {
            $this->recordSupportEvent(
                MtUniCreditDiagnosticJournal::EVENT_CP_STATUS_PATCH_SUCCESS,
                null,
                array('bank_status' => $status['status_id'])
            );
        }
    }

    /**
     * @param int $attemptId
     * @param int $storeId
     * @param int|string $localOrderId
     * @param MtUniCreditOrderBankStatusRepository|null $bankStatuses
     * @return void
     */
    private function persistFailureBankStatus($attemptId, $storeId, $localOrderId, $bankStatuses)
    {
        if (!$this->statusSync instanceof MtUniCreditControlPanelStatusSyncService) {
            return;
        }

        $status = MtUniCreditBankStatus::smartUcfFailure();
        $shopOrderId = MtUniCreditShopOrderId::tryNormalize($localOrderId);
        if ($shopOrderId === null) {
            return;
        }
        $attemptId = (int) $attemptId;

        $decision = $this->statusSync->admitTarget(
            $attemptId,
            $status['status_id'],
            $status['status_label']
        );
        if (
            $decision === MtUniCreditControlPanelStatusSyncService::CONFLICT
            || $decision === MtUniCreditControlPanelStatusSyncService::REJECT
        ) {
            error_log(
                'mt_uni_credit: SmartUCF failure durable target admission blocked'
                    . ' decision=' . $decision
                    . ' attempt_id=' . $attemptId
            );

            return;
        }

        try {
            $this->writeLocalBankStatus($storeId, $localOrderId, $status, $bankStatuses);
        } catch (Throwable $exception) {
            error_log(
                'mt_uni_credit: SmartUCF failure local bank status write failed'
                    . ' attempt_id=' . $attemptId
                    . ' class=' . get_class($exception)
                    . ' (pending CP target retained)'
            );

            return;
        }

        $this->statusSync->retryPending($attemptId, $shopOrderId);
    }

    /**
     * Replay after SmartUCF created: persisted CP target is the sole authority.
     *
     * Requires an exact canonical Process 1 target (status_id + status text).
     * Ordinary replay never synthesizes a missing target.
     * When sync is not_needed, a separately guarded explicit recovery may run.
     * Local terminal fact must exist before any PATCH.
     *
     * @param int $attemptId
     * @param int $storeId
     * @param int|string $localOrderId
     * @param string $authoritativeUnicid
     * @param MtUniCreditOrderBankStatusRepository|null $bankStatuses
     * @return void
     */
    private function reconcileProcess1BankStatusOnReplay(
        $attemptId,
        $storeId,
        $localOrderId,
        $authoritativeUnicid,
        $bankStatuses
    ) {
        if (!$this->statusSync instanceof MtUniCreditControlPanelStatusSyncService) {
            return;
        }

        $canonical = MtUniCreditBankStatus::process1Sent();
        $shopOrderId = MtUniCreditShopOrderId::tryNormalize($localOrderId);
        if ($shopOrderId === null) {
            error_log(
                'mt_uni_credit: Process 1 replay blocked by non-canonical order_id'
                    . ' attempt_id=' . (int) $attemptId
                    . ' order_id=' . (string) $localOrderId
            );

            return;
        }
        $attemptId = (int) $attemptId;
        $storeId = (int) $storeId;
        $target = $this->statusSync->readPersistedTarget($attemptId);

        if ($target === null) {
            return;
        }

        $state = (string) (isset($target['state'])
            ? $target['state']
            : MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED);
        $statusId = isset($target['status_id']) ? $target['status_id'] : null;
        $statusText = isset($target['status']) ? $target['status'] : null;

        // Ordinary replay never synthesizes from not_needed — explicit recovery is separate.
        if ($state === MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED || $state === '') {
            error_log(
                'mt_uni_credit: Process 1 ordinary replay detected missing CP sync target'
                    . ' attempt_id=' . $attemptId
                    . ' store_id=' . $storeId
                    . ' order_id=' . $shopOrderId
                    . '; invoking guarded post-SmartUCF recovery'
            );
            $this->recoverMissingProcess1TargetAfterCreatedSmartUcf(
                $attemptId,
                $storeId,
                $localOrderId,
                $authoritativeUnicid,
                $bankStatuses
            );

            return;
        }

        if ($state === MtUniCreditControlPanelStatusSyncStates::TERMINAL_FAILED) {
            error_log(
                'mt_uni_credit: Process 1 replay blocked by terminal_failed sync target'
                    . ' attempt_id=' . $attemptId
                    . ' status_id=' . (string) $statusId
                    . ' error_class=' . (string) (isset($target['error_class']) ? $target['error_class'] : '')
            );

            return;
        }

        if (!$this->isExactCanonicalProcess1Target($statusId, $statusText)) {
            error_log(
                'mt_uni_credit: Process 1 replay blocked by incomplete/conflicting durable target'
                    . ' attempt_id=' . $attemptId
                    . ' status_id=' . (string) $statusId
                    . ' status=' . (string) $statusText
            );

            return;
        }

        if (
            $state !== MtUniCreditControlPanelStatusSyncStates::PENDING
            && $state !== MtUniCreditControlPanelStatusSyncStates::CONFIRMED
        ) {
            return;
        }

        try {
            $this->writeLocalBankStatus($storeId, $localOrderId, $canonical, $bankStatuses);
        } catch (Throwable $exception) {
            error_log(
                'mt_uni_credit: Process 1 replay local bank status write failed'
                    . ' attempt_id=' . $attemptId
                    . ' store_id=' . $storeId
                    . ' order_id=' . $shopOrderId
                    . ' class=' . get_class($exception)
                    . ' (pending CP target retained; PATCH skipped)'
            );

            return;
        }

        if ($state === MtUniCreditControlPanelStatusSyncStates::CONFIRMED) {
            // Local restored; CP already confirmed — never send a second PATCH.
            return;
        }

        $syncState = $this->statusSync->retryPending($attemptId, $shopOrderId);
        if (
            $syncState === MtUniCreditControlPanelStatusSyncStates::PENDING
            || $syncState === MtUniCreditControlPanelStatusSyncStates::TERMINAL_FAILED
        ) {
            error_log(
                'mt_uni_credit: ' . self::ERROR_CP_BANK_STATUS_SYNC_PENDING
                    . ' attempt_id=' . $attemptId
                    . ' store_id=' . $storeId
                    . ' order_id=' . $shopOrderId
                    . ' status_id=' . $canonical['status_id']
                    . ' sync_state=' . $syncState
            );
        }
    }

    /**
     * Explicit post-SmartUCF / pre-target crash recovery.
     *
     * Invoked from production created-state replay only after ordinary path detects not_needed.
     * Admits a missing P1 target only when durable SmartUCF success + exact ownership are proven.
     *
     * @param int $attemptId
     * @param int $storeId
     * @param int|string $localOrderId
     * @param string $authoritativeUnicid
     * @param MtUniCreditOrderBankStatusRepository|null $bankStatuses
     * @return bool true when a P1 target was admitted and local fact restored (PATCH may still be pending)
     */
    public function recoverMissingProcess1TargetAfterCreatedSmartUcf(
        $attemptId,
        $storeId,
        $localOrderId,
        $authoritativeUnicid,
        $bankStatuses = null
    ) {
        if (!$this->statusSync instanceof MtUniCreditControlPanelStatusSyncService) {
            return false;
        }

        $attemptId = (int) $attemptId;
        $storeId = (int) $storeId;
        $row = $this->lifecycle->findByAttempt($attemptId);
        if ($row === null) {
            return false;
        }
        if (
            (string) (isset($row['smartucf_state']) ? $row['smartucf_state'] : '')
            !== MtUniCreditSmartUcfLifecycleStates::CREATED
        ) {
            return false;
        }
        if ((int) (isset($row['store_id']) ? $row['store_id'] : -1) !== $storeId) {
            return false;
        }

        $storedOrder = MtUniCreditShopOrderId::tryNormalize(isset($row['order_id']) ? $row['order_id'] : null);
        $localOrder = MtUniCreditShopOrderId::tryNormalize($localOrderId);
        if ($storedOrder === null || $localOrder === null || $storedOrder !== $localOrder) {
            error_log(
                'mt_uni_credit: Process 1 missing-target recovery blocked by order identity mismatch'
                    . ' attempt_id=' . $attemptId
                    . ' stored_order_id=' . (string) (isset($row['order_id']) ? $row['order_id'] : '')
                    . ' local_order_id=' . (string) $localOrderId
            );

            return false;
        }

        $attemptUnicid = trim((string) (isset($row['unicid']) ? $row['unicid'] : ''));
        $ownedUnicid = trim((string) $authoritativeUnicid);
        if ($attemptUnicid === '' || $ownedUnicid === '' || !hash_equals($attemptUnicid, $ownedUnicid)) {
            error_log(
                'mt_uni_credit: Process 1 missing-target recovery blocked by UNICID ownership'
                    . ' attempt_id=' . $attemptId
            );

            return false;
        }

        $process2State = (string) (isset($row['process2_state'])
            ? $row['process2_state']
            : MtUniCreditProcessTwoLifecycleStates::NOT_STARTED);
        if (
            $process2State !== MtUniCreditProcessTwoLifecycleStates::NOT_STARTED
            && $process2State !== ''
        ) {
            error_log(
                'mt_uni_credit: Process 1 missing-target recovery blocked by Process 2 lifecycle state'
                    . ' attempt_id=' . $attemptId
                    . ' process2_state=' . $process2State
            );

            return false;
        }

        $target = $this->statusSync->readPersistedTarget($attemptId);
        if ($target === null) {
            return false;
        }

        $state = (string) (isset($target['state'])
            ? $target['state']
            : MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED);
        $statusId = isset($target['status_id']) ? $target['status_id'] : null;
        $statusText = isset($target['status']) ? $target['status'] : null;

        // Only the exact post-SmartUCF / pre-admission crash window.
        if (
            $state !== MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED
            || $statusId !== null
            || $statusText !== null
        ) {
            return false;
        }

        if ($bankStatuses instanceof MtUniCreditOrderBankStatusRepository) {
            $local = $bankStatuses->findByOrderId($storeId, $localOrderId);
            $localStatusId = is_array($local) ? (string) (isset($local['status_id']) ? $local['status_id'] : '') : '';
            if ($localStatusId === MtUniCreditBankStatus::SENT_PROCESS2) {
                error_log(
                    'mt_uni_credit: Process 1 missing-target recovery blocked by local Process 2 fact'
                        . ' attempt_id=' . $attemptId
                        . ' order_id=' . (string) $localOrderId
                );

                return false;
            }
        }

        $this->persistProcess1BankStatus($attemptId, $storeId, $localOrderId, $bankStatuses);

        $after = $this->statusSync->readPersistedTarget($attemptId);
        if ($after === null) {
            return false;
        }

        return $this->isExactCanonicalProcess1Target(
            isset($after['status_id']) ? $after['status_id'] : null,
            isset($after['status']) ? $after['status'] : null
        );
    }

    /**
     * Canonical shop order id for identity binding (positive decimal, max 13 digits, no truncation).
     *
     * @param mixed $value
     * @return string|null
     */
    private function canonicalOrderId($value)
    {
        return MtUniCreditShopOrderId::tryNormalize($value);
    }

    /**
     * @param string|null $statusId
     * @param string|null $statusText
     * @return bool
     */
    private function isExactCanonicalProcess1Target($statusId, $statusText)
    {
        $canonical = MtUniCreditBankStatus::process1Sent();

        return $statusId === $canonical['status_id']
            && $statusText === $canonical['status_label'];
    }

    /**
     * Local bank status write for proven module handoffs. Failures must not be swallowed
     * on the target-first path — callers catch and skip PATCH while retaining pending target.
     *
     * @param int $storeId
     * @param int|string $localOrderId
     * @param array{status_id: string, status_label: string} $status
     * @param MtUniCreditOrderBankStatusRepository|null $bankStatuses
     * @return void
     */
    private function writeLocalBankStatus($storeId, $localOrderId, array $status, $bankStatuses)
    {
        if (!$bankStatuses instanceof MtUniCreditOrderBankStatusRepository) {
            throw new RuntimeException('Process 1 local bank status repository unavailable.');
        }

        $updated = $bankStatuses->upsertAuthorizedLocal(
            (int) $storeId,
            $localOrderId,
            $status['status_id'],
            $status['status_label'],
            MtUniCreditBankStatusTransitionPolicy::SOURCE_LOCAL_LIFECYCLE
        );
        if ($updated === null) {
            throw new RuntimeException('Process 1 local bank status write returned null.');
        }

        $verified = $bankStatuses->findByOrderId((int) $storeId, $localOrderId);
        if (
            $verified === null
            || (string) $verified['status_id'] !== (string) $status['status_id']
        ) {
            throw new RuntimeException('Process 1 local bank status write not durable.');
        }
    }
}
