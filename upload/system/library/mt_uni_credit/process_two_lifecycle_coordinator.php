<?php

/**
 * Process 2 post-CP handoff: durable CP target → local bank_sent_process2 → PATCH → prepared → mail.
 *
 * Canonical sequence after CP create:
 * claimPreparing → admit durable target → local bank fact → PATCH → markPrepared → mail.
 * On CONFLICT after admit: fail without local mutation.
 * Stale preparing reclaim avoids infinite operation_processing loops; when a P2 target
 * is already pending/confirmed, resume without repeating external handoff side effects.
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

    /** @var MtUniCreditControlPanelStatusSyncService */
    private $statusSync;

    /** @var MtUniCreditProcessTwoSensitiveCipher */
    private $cipher;

    /** @var MtUniCreditProcessTwoMailPort */
    private $mailer;

    /**
     * @param MtUniCreditProcessTwoLifecycleRepository $lifecycle
     * @param MtUniCreditProcessTwoMailRecipientRepository $mailRecipients
     * @param MtUniCreditOrderBankStatusRepository $bankStatuses
     * @param MtUniCreditControlPanelStatusSyncService $statusSync
     * @param MtUniCreditProcessTwoSensitiveCipher $cipher
     * @param MtUniCreditProcessTwoMailPort $mailer
     */
    public function __construct(
        MtUniCreditProcessTwoLifecycleRepository $lifecycle,
        MtUniCreditProcessTwoMailRecipientRepository $mailRecipients,
        MtUniCreditOrderBankStatusRepository $bankStatuses,
        MtUniCreditControlPanelStatusSyncService $statusSync,
        MtUniCreditProcessTwoSensitiveCipher $cipher,
        MtUniCreditProcessTwoMailPort $mailer
    ) {
        $this->lifecycle = $lifecycle;
        $this->mailRecipients = $mailRecipients;
        $this->bankStatuses = $bankStatuses;
        $this->statusSync = $statusSync;
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
        $shopOrderId = MtUniCreditShopOrderId::tryNormalize($localOrderId);
        if ($shopOrderId === null) {
            return array(
                'success' => false,
                'error' => 'process2_failed',
                'message' => self::CUSTOMER_FAILED_MESSAGE,
                'recoverable' => false,
            );
        }
        $localOrderId = $shopOrderId;
        $status = MtUniCreditBankStatus::process2Sent();

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
            // Replay: do not re-handoff; only retry pending CP sync + continue mail if needed.
            $this->statusSync->retryPending($attemptId, $shopOrderId);
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

        if ($state === MtUniCreditProcessTwoLifecycleStates::PREPARING) {
            if ($this->hasAdmittedProcess2Target($attemptId)) {
                // Durable target already admitted — resume local + PATCH without repeating handoff.
                return $this->resumeAfterAdmittedTarget(
                    $attemptId,
                    $storeId,
                    $localOrderId,
                    $shopOrderId,
                    $status,
                    $row,
                    $shop,
                    $orderContext
                );
            }

            if (!$this->lifecycle->isStalePreparing($row)) {
                return array(
                    'success' => false,
                    'error' => 'operation_processing',
                    'message' => self::CUSTOMER_PROCESSING_MESSAGE,
                    'recoverable' => true,
                );
            }
            // Stale preparing without admitted P2 target: fall through to claimPreparing reclaim.
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

            $decision = $this->statusSync->admitTarget(
                $attemptId,
                $status['status_id'],
                $status['status_label']
            );
            if (
                $decision === MtUniCreditControlPanelStatusSyncService::CONFLICT
                || $decision === MtUniCreditControlPanelStatusSyncService::REJECT
            ) {
                throw new RuntimeException('Process 2 durable target admission conflict.');
            }

            $this->writeLocalBankStatus($storeId, $localOrderId, $status);

            $syncState = $this->statusSync->retryPending($attemptId, $shopOrderId);
            if ($syncState === MtUniCreditControlPanelStatusSyncStates::PENDING) {
                error_log(
                    'mt_uni_credit: ' . self::ERROR_CP_BANK_STATUS_SYNC_PENDING
                        . ' attempt_id=' . $attemptId
                        . ' order_id=' . $shopOrderId
                        . ' status_id=' . $status['status_id']
                );
            }

            $this->lifecycle->markPrepared($attemptId);
            $this->trySendMail($attemptId, $row, $shop, $orderContext);
            $this->lifecycle->redactExpiredSensitiveBatch();
            $this->lifecycle->cleanupExpiredPresentationBatch();
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
     * @return bool
     */
    private function hasAdmittedProcess2Target($attemptId)
    {
        $target = $this->statusSync->readPersistedTarget((int) $attemptId);
        if ($target === null) {
            return false;
        }

        $syncStatusId = (string) (isset($target['status_id']) ? $target['status_id'] : '');
        $syncState = (string) (isset($target['state']) ? $target['state'] : '');

        return $syncStatusId === MtUniCreditBankStatus::SENT_PROCESS2
            && in_array(
                $syncState,
                array(
                    MtUniCreditControlPanelStatusSyncStates::PENDING,
                    MtUniCreditControlPanelStatusSyncStates::CONFIRMED,
                ),
                true
            );
    }

    /**
     * @param int $attemptId
     * @param int $storeId
     * @param int $localOrderId
     * @param string $shopOrderId
     * @param array{status_id: string, status_label: string} $status
     * @param array<string, mixed> $row
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $orderContext
     * @return array<string, mixed>
     */
    private function resumeAfterAdmittedTarget(
        $attemptId,
        $storeId,
        $localOrderId,
        $shopOrderId,
        array $status,
        array $row,
        array $shop,
        array $orderContext
    ) {
        try {
            $this->writeLocalBankStatus($storeId, $localOrderId, $status);
            $this->statusSync->retryPending($attemptId, $shopOrderId);
            $this->lifecycle->markPrepared($attemptId);
            $this->trySendMail($attemptId, $row, $shop, $orderContext);
        } catch (Throwable $exception) {
            error_log(
                'mt_uni_credit: Process 2 resume after admitted target failed attempt_id=' . $attemptId
                    . ' class=' . get_class($exception)
            );

            return array(
                'success' => false,
                'error' => 'operation_processing',
                'message' => self::CUSTOMER_PROCESSING_MESSAGE,
                'recoverable' => true,
            );
        }

        return array(
            'success' => true,
            'process2_state' => MtUniCreditProcessTwoLifecycleStates::PREPARED,
            'replay' => true,
            'message' => self::CUSTOMER_SUCCESS_MESSAGE,
        );
    }

    /**
     * @param int $storeId
     * @param string $localOrderId Canonical shop order id
     * @param array{status_id: string, status_label: string} $status
     * @return void
     */
    private function writeLocalBankStatus($storeId, $localOrderId, array $status)
    {
        $local = $this->bankStatuses->upsertAuthorizedLocal(
            (int) $storeId,
            $localOrderId,
            $status['status_id'],
            $status['status_label'],
            MtUniCreditBankStatusTransitionPolicy::SOURCE_LOCAL_LIFECYCLE
        );
        if ($local === null) {
            throw new RuntimeException(self::ERROR_LOCAL_BANK_STATUS_FAILED);
        }

        $verified = $this->bankStatuses->findByOrderId((int) $storeId, $localOrderId);
        if (
            $verified === null
            || (string) $verified['status_id'] !== (string) $status['status_id']
        ) {
            throw new RuntimeException(self::ERROR_LOCAL_BANK_STATUS_FAILED);
        }
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

            // Residual SMTP crash window: provider may have accepted the message before
            // markSent; uncertain/sent claims prevent blind re-send after reclaim.
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
}
