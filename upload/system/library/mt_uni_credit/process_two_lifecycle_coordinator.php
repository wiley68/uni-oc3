<?php

/**
 * Process 2 post-CP handoff: bank_sent_process2 + leasing mail (no SmartUCF).
 */
final class MtUniCreditProcessTwoLifecycleCoordinator
{
    const ERROR_CP_BANK_STATUS_SYNC_PENDING = 'cp_bank_status_sync_pending';
    const CUSTOMER_SUCCESS_MESSAGE =
    'Очаквайте контакт за потвърждаване на направената от Вас заявка.';
    const CUSTOMER_FAILED_MESSAGE =
    'Поръчката е създадена, но обработката за Процес 2 не беше завършена успешно.';
    const CUSTOMER_PROCESSING_MESSAGE = 'Заявката се обработва. Моля, изчакайте.';

    /** @var MtUniCreditProcessTwoLifecycleRepository */
    private $lifecycle;

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
     * @param MtUniCreditOrderBankStatusRepository $bankStatuses
     * @param MtUniCreditControlPanelClient $controlPanel
     * @param MtUniCreditProcessTwoSensitiveCipher $cipher
     * @param MtUniCreditProcessTwoMailPort $mailer
     */
    public function __construct(
        MtUniCreditProcessTwoLifecycleRepository $lifecycle,
        MtUniCreditOrderBankStatusRepository $bankStatuses,
        MtUniCreditControlPanelClient $controlPanel,
        MtUniCreditProcessTwoSensitiveCipher $cipher,
        MtUniCreditProcessTwoMailPort $mailer
    ) {
        $this->lifecycle = $lifecycle;
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
            $this->reconcileBankStatus($attemptId, $storeId, $localOrderId);
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
            return array(
                'success' => false,
                'error' => 'operation_processing',
                'message' => self::CUSTOMER_PROCESSING_MESSAGE,
                'recoverable' => true,
            );
        }

        if (!$this->lifecycle->claimPreparing($attemptId)) {
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

        try {
            $enc = (string) (isset($row['process2_sensitive_enc']) ? $row['process2_sensitive_enc'] : '');
            if ($enc === '') {
                throw new RuntimeException('Process 2 sensitive payload missing.');
            }
            $this->reconcileBankStatus($attemptId, $storeId, $localOrderId);
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
            $ok = $this->mailer->sendProcess2Notifications($shop, $orderContext, $sensitive);
            if ($ok) {
                $this->lifecycle->markMailSent($attemptId);
            }
        } catch (Throwable $exception) {
            // Bank status already prepared — mail is independent (OC4 PS9 parity).
            error_log(
                'mt_uni_credit: Process 2 mail failed attempt_id=' . $attemptId
                    . ' class=' . get_class($exception)
            );
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
     * @return void
     */
    private function reconcileBankStatus($attemptId, $storeId, $localOrderId)
    {
        $status = MtUniCreditBankStatus::process2Sent();
        $shopOrderId = substr((string) $localOrderId, 0, 13);
        try {
            $this->bankStatuses->updateByOrderIdentifier(
                $storeId,
                $shopOrderId,
                $status['status_id'],
                $status['status_label']
            );
        } catch (Throwable $ignored) {
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
        }
    }
}
