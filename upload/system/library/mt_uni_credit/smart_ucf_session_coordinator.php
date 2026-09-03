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

    /**
     * @param MtUniCreditSmartUcfLifecycleRepository $lifecycle
     * @param object $client Must provide createSession()
     * @param MtUniCreditSmartUcfFailureClassifier|null $classifier
     * @param MtUniCreditCertificateLocalPaths|null $certificatePaths
     * @param MtUniCreditMtlsPrivateKeyPassphraseProvider|null $passphrases
     * @param MtUniCreditCertificatePairValidator|null $certificateValidator
     * @param MtUniCreditSmartUcfPayloadBuilder|null $payloadBuilder
     */
    public function __construct(
        MtUniCreditSmartUcfLifecycleRepository $lifecycle,
        $client,
        $classifier = null,
        $certificatePaths = null,
        $passphrases = null,
        $certificateValidator = null,
        $payloadBuilder = null
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
        if (MtUniCreditShopConfigurationFlags::usesSmartUcfCertificate($shop)) {
            try {
                $certPath = $this->certificatePaths->certificatePath();
                $keyPath = $this->certificatePaths->privateKeyPath();
                $passphrase = $this->passphrases->requirePassphrase($this->certificatePaths->passphrasePath());
                $validation = $this->certificateValidator->validate($certPath, $keyPath, $passphrase);
                if (empty($validation['ok'])) {
                    throw new RuntimeException('SmartUCF certificate pair validation failed.');
                }
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
            return $this->handleFailure($attemptId, $storeId, $localOrderId, $exception, $bankStatuses);
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

        return MtUniCreditSmartUcfCoordinationResult::created(
            (string) $session['redirect_url'],
            (string) $session['session_id']
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
