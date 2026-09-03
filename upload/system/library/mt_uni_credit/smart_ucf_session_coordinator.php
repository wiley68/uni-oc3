<?php

/**
 * Coordinates Process 1 SmartUCF session create with durable lifecycle transitions.
 *
 * CP status PATCH is best-effort deferred when the local Control Panel client
 * lacks an updateOrderStatus() method — local bank status only for now.
 */
final class MtUniCreditSmartUcfSessionCoordinator
{
    const ERROR_CERTIFICATE_INVALID = 'smartucf_certificate_invalid';
    const ERROR_CREDENTIALS_SYNC_FAILED = 'smartucf_credentials_sync_failed';

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

    /** @var MtUniCreditPhase9LifecycleLog|null */
    private $phase9Log;

    /** @var int */
    private $logStoreId = 0;

    /** @var int */
    private $logOrderId = 0;

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
     */
    public function __construct(
        MtUniCreditSmartUcfLifecycleRepository $lifecycle,
        $client,
        $classifier = null,
        $certificatePaths = null,
        $passphrases = null,
        $certificateValidator = null,
        $payloadBuilder = null,
        $certificateSynchronizer = null
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
        $this->phase9Log = null;
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
     * @return void
     */
    public function setLifecycleLog($log, $storeId, $orderId, $entryPoint, $attemptId, $cpOrderId)
    {
        if ($log instanceof MtUniCreditPhase9LifecycleLog) {
            $this->phase9Log = $log;
        }
        $this->logStoreId = (int) $storeId;
        $this->logOrderId = (int) $orderId;
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
     * @param int|string $cpOrderId Unused locally; reserved for deferred CP status PATCH
     * @param MtUniCreditOrderBankStatusRepository|null $bankStatuses
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
        $bankStatuses = null
    ) {
        $attemptId = (int) $attemptId;
        $storeId = (int) (isset($order['store_id']) ? $order['store_id'] : 0);

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
            if ($known->isCreated()) {
                $this->persistProcess1BankStatus($storeId, $localOrderId, $bankStatuses);
            }

            return $known;
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
                    $this->persistProcess1BankStatus($storeId, $localOrderId, $bankStatuses);
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

            return MtUniCreditSmartUcfCoordinationResult::outcomeUnknown(self::CUSTOMER_OUTCOME_UNKNOWN);
        }

        $this->persistProcess1BankStatus($storeId, $localOrderId, $bankStatuses);
        $this->logEvent(MtUniCreditPhase9LifecycleLog::EVENT_SMARTUCF_RESULT, array(
            'kind' => 'created',
            'bank_status' => MtUniCreditBankStatus::SENT_PROCESS1,
        ));

        return MtUniCreditSmartUcfCoordinationResult::created(
            (string) $session['redirect_url'],
            (string) $session['session_id']
        );
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
            $this->persistLocalBankStatus($storeId, $localOrderId, MtUniCreditBankStatus::smartUcfFailure(), $bankStatuses);
        }

        $this->logEvent(MtUniCreditPhase9LifecycleLog::EVENT_SMARTUCF_RESULT, array(
            'kind' => $classification->targetState() === MtUniCreditSmartUcfLifecycleStates::OUTCOME_UNKNOWN
                ? 'outcome_unknown'
                : 'failed',
            'error_class' => $classification->errorClass(),
        ));

        return MtUniCreditSmartUcfCoordinationResult::failed(
            self::CUSTOMER_FAILED,
            $classification->isRetryable(),
            $classification->errorClass()
        );
    }

    /**
     * @param int $storeId
     * @param int|string $localOrderId
     * @param MtUniCreditOrderBankStatusRepository|null $bankStatuses
     * @return void
     */
    private function persistProcess1BankStatus($storeId, $localOrderId, $bankStatuses)
    {
        $this->persistLocalBankStatus($storeId, $localOrderId, MtUniCreditBankStatus::process1Sent(), $bankStatuses);
        // CP status PATCH is best-effort deferred if the client lacks updateOrderStatus().
    }

    /**
     * @param int $storeId
     * @param int|string $localOrderId
     * @param array{status_id: string, status_label: string} $status
     * @param MtUniCreditOrderBankStatusRepository|null $bankStatuses
     * @return void
     */
    private function persistLocalBankStatus($storeId, $localOrderId, array $status, $bankStatuses)
    {
        if (!$bankStatuses instanceof MtUniCreditOrderBankStatusRepository) {
            return;
        }
        $shopOrderId = substr((string) $localOrderId, 0, 13);
        try {
            $bankStatuses->updateByOrderIdentifier(
                $storeId,
                $shopOrderId,
                $status['status_id'],
                $status['status_label']
            );
        } catch (Throwable $ignored) {
        }
    }
}
