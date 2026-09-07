<?php

/**
 * Process 2 post-CP handoff: bank_sent_process2 + leasing mail (no SmartUCF).
 */
final class MtUniCreditProcessTwoLifecycleCoordinator
{
    const ERROR_CP_BANK_STATUS_SYNC_PENDING = 'cp_bank_status_sync_pending';
    const ERROR_LOCAL_BANK_STATUS_FAILED = 'local_bank_status_failed';
    const CUSTOMER_SUCCESS_MESSAGE =
    'Очаквайте контакт за потвърждаване на направената от Вас заявка.';
    const CUSTOMER_FAILED_MESSAGE =
    'Поръчката е създадена, но обработката за Процес 2 не беше завършена успешно.';
    const CUSTOMER_PROCESSING_MESSAGE = 'Заявката се обработва. Моля, изчакайте.';

    /** @var MtUniCreditProcessTwoLifecycleRepository */
    private $lifecycle;

    /** @var MtUniCreditProcessTwoMailRecipientRepository */
    private $mailRecipients;

    /** @var MtUniCreditOrderBankStatusRepository */
    private $bankStatuses;

    /** @var MtUniCreditControlPanelClient */
    private $controlPanel;

    /** @var MtUniCreditProcessTwoSensitiveCipher */
    private $cipher;

    /** @var MtUniCreditProcessTwoMailPort */
    private $mailer;

    /**
     * @param MtUniCreditProcessTwoLifecycleRepository $lifecycle
     * @param MtUniCreditProcessTwoMailRecipientRepository $mailRecipients
     * @param MtUniCreditOrderBankStatusRepository $bankStatuses
     * @param MtUniCreditControlPanelClient $controlPanel
     * @param MtUniCreditProcessTwoSensitiveCipher $cipher
     * @param MtUniCreditProcessTwoMailPort $mailer
     */
    public function __construct(
        MtUniCreditProcessTwoLifecycleRepository $lifecycle,
        MtUniCreditProcessTwoMailRecipientRepository $mailRecipients,
        MtUniCreditOrderBankStatusRepository $bankStatuses,
        MtUniCreditControlPanelClient $controlPanel,
        MtUniCreditProcessTwoSensitiveCipher $cipher,
        MtUniCreditProcessTwoMailPort $mailer
    ) {
        $this->lifecycle = $lifecycle;
        $this->mailRecipients = $mailRecipients;
        $this->bankStatuses = $bankStatuses;
        $this->controlPanel = $controlPanel;
        $this->cipher = $cipher;
        $this->mailer = $mailer;
    }

    /**
     * @param int $attemptId
     * @param int $storeId
     * @param int $localOrderId
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $orderContext
     * @return array{
     *   success: bool,
     *   error?: string,
     *   message?: string,
     *   recoverable?: bool,
     *   process2_state?: string,
     *   replay?: bool
     * }
     */
    public function run($attemptId, $storeId, $localOrderId, array $shop, array $orderContext)
    {
        $attemptId = (int) $attemptId;
        $storeId = (int) $storeId;
        $localOrderId = (int) $localOrderId;

        $row = $this->lifecycle->findByAttempt($attemptId);
        if ($row === null) {
            return array(
                'success' => false,
                'error' => 'process2_failed',
                'message' => self::CUSTOMER_FAILED_MESSAGE,
                'recoverable' => true,
            );
        }

        $state = (string) (isset($row['process2_state'])
            ? $row['process2_state']
            : MtUniCreditProcessTwoLifecycleStates::NOT_STARTED);

        if ($state === MtUniCreditProcessTwoLifecycleStates::PREPARED) {
            $this->reconcileBankStatus($attemptId, $storeId, $localOrderId, false);
            if (!$this->lifecycle->isMailSent($attemptId)) {
                $this->trySendMail($attemptId, $row, $shop, $orderContext);
            }

            return array(
                'success' => true,
                'process2_state' => MtUniCreditProcessTwoLifecycleStates::PREPARED,
                'replay' => true,
                'message' => self::CUSTOMER_SUCCESS_MESSAGE,
            );
        }

        if (
            $state === MtUniCreditProcessTwoLifecycleStates::PREPARING
            && !$this->lifecycle->isStalePreparing($row)
        ) {
            return array(
                'success' => false,
                'error' => 'operation_processing',
                'message' => self::CUSTOMER_PROCESSING_MESSAGE,
                'recoverable' => true,
            );
        }

        $ownerToken = MtUniCreditLockOwnerTokenGenerator::generate();
        if (!$this->lifecycle->claimPreparing($attemptId, $ownerToken)) {
            $fresh = $this->lifecycle->findByAttempt($attemptId);
            if (
                $fresh !== null
                && (string) (isset($fresh['process2_state']) ? $fresh['process2_state'] : '')
                === MtUniCreditProcessTwoLifecycleStates::PREPARED
            ) {
                return $this->run($attemptId, $storeId, $localOrderId, $shop, $orderContext);
            }

            return array(
                'success' => false,
                'error' => 'operation_processing',
                'message' => self::CUSTOMER_PROCESSING_MESSAGE,
                'recoverable' => true,
            );
        }

        $row = $this->lifecycle->findByAttempt($attemptId);
        if ($row === null) {
            return array(
                'success' => false,
                'error' => 'process2_failed',
                'message' => self::CUSTOMER_FAILED_MESSAGE,
                'recoverable' => true,
            );
        }

        try {
            $enc = (string) (isset($row['process2_sensitive_enc']) ? $row['process2_sensitive_enc'] : '');
            if ($enc === '') {
                throw new RuntimeException('Process 2 sensitive payload missing.');
            }
            $this->reconcileBankStatus($attemptId, $storeId, $localOrderId, true);
            $this->lifecycle->markPrepared($attemptId);
            $this->trySendMail($attemptId, $row, $shop, $orderContext);
            $this->lifecycle->redactExpiredSensitiveBatch();
        } catch (Throwable $exception) {
            try {
                $this->lifecycle->markFailed($attemptId);
            } catch (Throwable $ignored) {
            }
            error_log(
                'mt_uni_credit: Process 2 handoff failed attempt_id=' . $attemptId
                    . ' class=' . get_class($exception)
            );

            return array(
                'success' => false,
                'error' => 'process2_failed',
                'message' => self::CUSTOMER_FAILED_MESSAGE,
                'recoverable' => true,
            );
        }

        return array(
            'success' => true,
            'process2_state' => MtUniCreditProcessTwoLifecycleStates::PREPARED,
            'replay' => false,
            'message' => self::CUSTOMER_SUCCESS_MESSAGE,
        );
    }

    /**
     * @param int $attemptId
     * @param array<string, mixed> $row
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $orderContext
     * @return void
     */
    private function trySendMail($attemptId, array $row, array $shop, array $orderContext)
    {
        if ($this->lifecycle->isMailSent($attemptId)) {
            return;
        }

        $recipients = $this->mailer->resolveProcess2Recipients($shop, $orderContext);
        $this->mailRecipients->ensureRecipients($attemptId, $recipients);
        $this->mailRecipients->normalizeStaleSendingToUncertain($attemptId);

        if ($recipients === array() || $this->mailRecipients->areAllRecipientsSent($attemptId)) {
            $this->lifecycle->markMailSent($attemptId);

            return;
        }

        $sensitive = null;
        $enc = (string) (isset($row['process2_sensitive_enc']) ? $row['process2_sensitive_enc'] : '');
        if ($enc !== '') {
            try {
                $sensitive = $this->cipher->decrypt($enc);
            } catch (Throwable $exception) {
                error_log('mt_uni_credit: Process 2 sensitive decrypt failed attempt_id=' . $attemptId);
            }
        }

        try {
            $orderContext = $this->enrichMailContext($attemptId, $row, $orderContext);
        } catch (Throwable $exception) {
            error_log(
                'mt_uni_credit: Process 2 mail context failed attempt_id=' . $attemptId
                    . ' class=' . get_class($exception)
            );

            return;
        }

        foreach ($recipients as $recipient) {
            $key = isset($recipient['recipient_key'])
                ? (string) $recipient['recipient_key']
                : MtUniCreditProcessTwoMailRecipientRepository::normalizeRecipientKey($recipient['email']);
            $existing = $this->mailRecipients->find($attemptId, $key);
            if ($existing !== null) {
                $existingState = (string) (isset($existing['state']) ? $existing['state'] : '');
                if (
                    $existingState === MtUniCreditProcessTwoMailRecipientStates::SENT
                    || $existingState === MtUniCreditProcessTwoMailRecipientStates::UNCERTAIN
                ) {
                    continue;
                }
            }

            $ownerToken = MtUniCreditLockOwnerTokenGenerator::generate();
            if (!$this->mailRecipients->claimForSending($attemptId, $key, $ownerToken)) {
                continue;
            }

            $externalSendSucceeded = false;
            try {
                $ok = $this->mailer->sendProcess2Recipient(
                    $shop,
                    $orderContext,
                    $sensitive,
                    (string) $recipient['audience'],
                    (string) $recipient['email']
                );
                if ($ok) {
                    $externalSendSucceeded = true;
                    // Post-send marker path: never downgrade ambiguity to retryable failed.
                    try {
                        if (!$this->mailRecipients->markSent($attemptId, $key, $ownerToken)) {
                            $this->mailRecipients->markUncertain($attemptId, $key);
                            error_log(
                                'mt_uni_credit: Process 2 mail send-before-marker ambiguous'
                                    . ' attempt_id=' . $attemptId
                                    . ' recipient_key=' . $key
                            );
                        }
                    } catch (Throwable $markerException) {
                        try {
                            $this->mailRecipients->markUncertain($attemptId, $key);
                        } catch (Throwable $ignored) {
                            // Leave durable sending; stale recovery normalizes to uncertain.
                        }
                        error_log(
                            'mt_uni_credit: Process 2 mail send-before-marker ambiguous'
                                . ' attempt_id=' . $attemptId
                                . ' recipient_key=' . $key
                                . ' class=' . get_class($markerException)
                        );
                    }
                } else {
                    $this->mailRecipients->markFailed($attemptId, $key, $ownerToken);
                }
            } catch (Throwable $exception) {
                if ($externalSendSucceeded) {
                    // Should be unreachable: post-send errors are handled above.
                    try {
                        $this->mailRecipients->markUncertain($attemptId, $key);
                    } catch (Throwable $ignored) {
                    }
                    error_log(
                        'mt_uni_credit: Process 2 mail send-before-marker ambiguous'
                            . ' attempt_id=' . $attemptId
                            . ' recipient_key=' . $key
                            . ' class=' . get_class($exception)
                    );
                } else {
                    try {
                        $this->mailRecipients->markFailed($attemptId, $key, $ownerToken);
                    } catch (Throwable $ignored) {
                    }
                    error_log(
                        'mt_uni_credit: Process 2 mail failed attempt_id=' . $attemptId
                            . ' class=' . get_class($exception)
                    );
                }
            }
        }

        if ($this->mailRecipients->areAllRecipientsSent($attemptId)) {
            $this->lifecycle->markMailSent($attemptId);
        }
    }

    /**
     * @param int $attemptId
     * @param array<string, mixed> $row
     * @param array<string, mixed> $orderContext
     * @return array<string, mixed>
     */
    private function enrichMailContext($attemptId, array $row, array $orderContext)
    {
        $status = MtUniCreditBankStatus::process2Sent();
        $orderContext['bank_status_label'] = $status['status_label'];
        $orderContext['control_panel_order_id'] = isset($row['control_panel_order_id'])
            ? (int) $row['control_panel_order_id']
            : null;
        $json = (string) (isset($row['leasing_presentation_json']) ? $row['leasing_presentation_json'] : '');
        if ($json !== '') {
            try {
                $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $orderContext['leasing_snapshot'] = $decoded;
                }
            } catch (Throwable $ignored) {
                error_log('mt_uni_credit: leasing presentation json decode failed attempt_id=' . $attemptId);
            }
        }

        return $orderContext;
    }

    /**
     * @param int $attemptId
     * @param int $storeId
     * @param int $localOrderId
     * @param bool $requireSuccess when true, local+CP failures block prepared/mail
     * @return void
     */
    private function reconcileBankStatus($attemptId, $storeId, $localOrderId, $requireSuccess)
    {
        $status = MtUniCreditBankStatus::process2Sent();
        $shopOrderId = substr((string) $localOrderId, 0, 13);

        try {
            $local = $this->bankStatuses->updateByOrderIdentifier(
                $storeId,
                $shopOrderId,
                $status['status_id'],
                $status['status_label']
            );
        } catch (Throwable $exception) {
            if ($requireSuccess) {
                throw new RuntimeException(self::ERROR_LOCAL_BANK_STATUS_FAILED, 0, $exception);
            }
            $local = null;
        }

        if ($requireSuccess) {
            if ($local === null) {
                throw new RuntimeException(self::ERROR_LOCAL_BANK_STATUS_FAILED);
            }
            $verified = $this->bankStatuses->findByOrderId($storeId, $localOrderId);
            if (
                $verified === null
                || (string) $verified['status_id'] !== (string) $status['status_id']
            ) {
                throw new RuntimeException(self::ERROR_LOCAL_BANK_STATUS_FAILED);
            }
        }

        try {
            $this->controlPanel->updateOrderStatus(
                $shopOrderId,
                $status['status_label'],
                $status['status_id']
            );
        } catch (Throwable $exception) {
            error_log(
                'mt_uni_credit: ' . self::ERROR_CP_BANK_STATUS_SYNC_PENDING
                    . ' attempt_id=' . $attemptId
                    . ' order_id=' . $shopOrderId
                    . ' status_id=' . $status['status_id']
                    . ' class=' . get_class($exception)
            );
            if ($requireSuccess) {
                throw $exception;
            }
        }
    }
}
