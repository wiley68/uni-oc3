<?php

/**
 * Durable, idempotent CP status PATCH synchronization after proven P1/P2 handoffs.
 *
 * Local bank_sent_* = business handoff proven.
 * cp_status_sync_* = CP confirmation state with concurrency-safe CAS transitions.
 *
 * bank_sent_process1 and bank_sent_process2 are mutually incompatible terminal
 * targets, not sequential stages.
 */
final class MtUniCreditControlPanelStatusSyncService
{
    const ADMIT = 'admit';
    const SAME = 'same';
    const CONFLICT = 'conflict';
    const REJECT = 'reject';

    /**
     * Positive allowlist of definitive non-retryable CP machine codes.
     *
     * @var array<int, string>
     */
    private static $terminalErrorCodes = array(
        'invalid_payload',
        'semantic_conflict',
        'unsupported_status',
        'order_not_found',
    );

    /**
     * Mutually incompatible CP terminal sent statuses (same lifecycle rank).
     *
     * @var array<int, string>
     */
    private static $terminalSent = array(
        MtUniCreditBankStatus::SENT_PROCESS1,
        MtUniCreditBankStatus::SENT_PROCESS2,
    );

    /** @var MtUniCreditControlPanelStatusSyncStoreInterface */
    private $store;

    /** @var MtUniCreditControlPanelOrderStatusPort */
    private $cpClient;

    /**
     * @param MtUniCreditControlPanelStatusSyncStoreInterface $store
     * @param MtUniCreditControlPanelOrderStatusPort $cpClient
     */
    public function __construct(
        MtUniCreditControlPanelStatusSyncStoreInterface $store,
        MtUniCreditControlPanelOrderStatusPort $cpClient
    ) {
        $this->store = $store;
        $this->cpClient = $cpClient;
    }

    /**
     * Persist pending sync for a proven business handoff, then attempt PATCH.
     *
     * @param int $attemptId
     * @param string $orderReference
     * @param array{status_id: string, status_label: string} $status
     * @return string
     */
    public function synchronizeAfterHandoff($attemptId, $orderReference, array $status)
    {
        $attemptId = (int) $attemptId;
        $orderReference = (string) $orderReference;
        if ($attemptId <= 0 || $orderReference === '') {
            return MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED;
        }

        $statusId = isset($status['status_id']) ? (string) $status['status_id'] : '';
        $statusLabel = isset($status['status_label']) ? (string) $status['status_label'] : '';
        if ($statusId === '' || $statusLabel === '') {
            return MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED;
        }

        $decision = $this->admitTarget($attemptId, $statusId, $statusLabel);
        if ($decision === self::CONFLICT || $decision === self::REJECT) {
            return $this->currentStateOr($attemptId, MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED);
        }

        return $this->attemptPending($attemptId, $orderReference);
    }

    /**
     * Admit a durable pending CP target without PATCHing.
     *
     * Callers that must write local bank fact before PATCH use admitTarget → local → retryPending.
     *
     * @param int $attemptId
     * @param string $statusId
     * @param string $statusLabel
     * @return string self::ADMIT|self::SAME|self::CONFLICT|self::REJECT
     */
    public function admitTarget($attemptId, $statusId, $statusLabel = '')
    {
        $attemptId = (int) $attemptId;
        $statusId = (string) $statusId;
        if ($attemptId <= 0 || $statusId === '') {
            return self::REJECT;
        }

        return $this->admitPendingTarget($attemptId, $statusId, (string) $statusLabel);
    }

    /**
     * Retry a previously persisted pending sync.
     * Persistence remains authority for the target that is sent.
     *
     * @param int $attemptId
     * @param string $orderReference
     * @return string
     */
    public function retryPending($attemptId, $orderReference)
    {
        $attemptId = (int) $attemptId;
        $orderReference = (string) $orderReference;
        if ($attemptId <= 0 || $orderReference === '') {
            return MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED;
        }

        return $this->attemptPending($attemptId, $orderReference);
    }

    /**
     * Read the durable CP sync target authority for crash recovery / replay.
     *
     * Missing/empty fields are returned as null — never defaulted to canonical labels.
     *
     * @param int $attemptId
     * @return array{state: string, status_id: ?string, status: ?string, error_class: ?string}|null
     */
    public function readPersistedTarget($attemptId)
    {
        $attemptId = (int) $attemptId;
        if ($attemptId <= 0) {
            return null;
        }

        $snapshot = $this->store->findByAttempt($attemptId);
        if ($snapshot === null) {
            return null;
        }

        return array(
            'state' => isset($snapshot['cp_status_sync_state'])
                ? (string) $snapshot['cp_status_sync_state']
                : MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED,
            'status_id' => $this->nullableString(
                isset($snapshot['cp_status_sync_status_id']) ? $snapshot['cp_status_sync_status_id'] : null
            ),
            'status' => $this->nullableString(
                isset($snapshot['cp_status_sync_status']) ? $snapshot['cp_status_sync_status'] : null
            ),
            'error_class' => $this->nullableString(
                isset($snapshot['cp_status_sync_error_class']) ? $snapshot['cp_status_sync_error_class'] : null
            ),
        );
    }

    /**
     * @param int $attemptId
     * @param string $statusId
     * @param string $statusLabel
     * @return string
     */
    private function admitPendingTarget($attemptId, $statusId, $statusLabel)
    {
        $snapshot = $this->store->findByAttempt($attemptId);
        if ($snapshot === null) {
            return self::REJECT;
        }

        $currentState = isset($snapshot['cp_status_sync_state'])
            ? (string) $snapshot['cp_status_sync_state']
            : MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED;
        $currentStatusId = $this->nullableString(
            isset($snapshot['cp_status_sync_status_id']) ? $snapshot['cp_status_sync_status_id'] : null
        );
        $currentStatus = $this->nullableString(
            isset($snapshot['cp_status_sync_status']) ? $snapshot['cp_status_sync_status'] : null
        );

        $decision = $this->decideAdmission($currentState, $currentStatusId, $statusId);
        if ($decision !== self::ADMIT) {
            if ($decision === self::CONFLICT) {
                $this->log(
                    'CP status sync conflict: incompatible terminal targets '
                        . (string) $currentStatusId . ' vs ' . $statusId
                );
            }

            return $decision;
        }

        $updated = $this->store->compareAndSetPendingTarget(
            $attemptId,
            $currentState,
            $currentStatusId,
            $currentStatus,
            $statusId,
            $statusLabel
        );
        if ($updated) {
            return self::ADMIT;
        }

        $latest = $this->store->findByAttempt($attemptId);
        if ($latest === null) {
            return self::REJECT;
        }
        $latestState = isset($latest['cp_status_sync_state'])
            ? (string) $latest['cp_status_sync_state']
            : MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED;
        $latestStatusId = $this->nullableString(
            isset($latest['cp_status_sync_status_id']) ? $latest['cp_status_sync_status_id'] : null
        );
        $retryDecision = $this->decideAdmission($latestState, $latestStatusId, $statusId);
        if ($retryDecision !== self::ADMIT) {
            if ($retryDecision === self::CONFLICT) {
                $this->log(
                    'CP status sync conflict after CAS miss: incompatible terminal targets '
                        . (string) $latestStatusId . ' vs ' . $statusId
                );
            }

            return $retryDecision;
        }

        $second = $this->store->compareAndSetPendingTarget(
            $attemptId,
            $latestState,
            $latestStatusId,
            $this->nullableString(
                isset($latest['cp_status_sync_status']) ? $latest['cp_status_sync_status'] : null
            ),
            $statusId,
            $statusLabel
        );
        if ($second) {
            return self::ADMIT;
        }

        // Second CAS miss: never claim ADMIT — reload and classify authoritative state.
        $authoritative = $this->store->findByAttempt($attemptId);
        if ($authoritative === null) {
            return self::REJECT;
        }

        $authDecision = $this->decideAdmission(
            isset($authoritative['cp_status_sync_state'])
                ? (string) $authoritative['cp_status_sync_state']
                : MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED,
            $this->nullableString(
                isset($authoritative['cp_status_sync_status_id'])
                    ? $authoritative['cp_status_sync_status_id']
                    : null
            ),
            $statusId
        );
        if ($authDecision === self::CONFLICT) {
            $this->log(
                'CP status sync conflict after second CAS miss: incompatible terminal targets '
                    . (isset($authoritative['cp_status_sync_status_id'])
                        ? (string) $authoritative['cp_status_sync_status_id']
                        : '') . ' vs ' . $statusId
            );
        }

        return $authDecision === self::ADMIT ? self::REJECT : $authDecision;
    }

    /**
     * @param int $attemptId
     * @param string $orderReference
     * @return string
     */
    private function attemptPending($attemptId, $orderReference)
    {
        $snapshot = $this->store->findByAttempt($attemptId);
        if ($snapshot === null) {
            return MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED;
        }

        $state = isset($snapshot['cp_status_sync_state'])
            ? (string) $snapshot['cp_status_sync_state']
            : MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED;
        if ($state === MtUniCreditControlPanelStatusSyncStates::CONFIRMED) {
            return MtUniCreditControlPanelStatusSyncStates::CONFIRMED;
        }
        if ($state === MtUniCreditControlPanelStatusSyncStates::TERMINAL_FAILED) {
            return MtUniCreditControlPanelStatusSyncStates::TERMINAL_FAILED;
        }
        if ($state !== MtUniCreditControlPanelStatusSyncStates::PENDING) {
            return $state !== '' ? $state : MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED;
        }

        $snapshot = $this->store->findByAttempt($attemptId);
        if ($snapshot === null) {
            return MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED;
        }
        $state = isset($snapshot['cp_status_sync_state'])
            ? (string) $snapshot['cp_status_sync_state']
            : MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED;
        if ($state !== MtUniCreditControlPanelStatusSyncStates::PENDING) {
            return $state !== '' ? $state : MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED;
        }

        $statusId = isset($snapshot['cp_status_sync_status_id'])
            ? (string) $snapshot['cp_status_sync_status_id']
            : '';
        $statusLabel = isset($snapshot['cp_status_sync_status'])
            ? (string) $snapshot['cp_status_sync_status']
            : '';
        if ($statusId === '' || $statusLabel === '') {
            return MtUniCreditControlPanelStatusSyncStates::PENDING;
        }

        $shopOrderId = MtUniCreditShopOrderId::tryNormalize($orderReference);
        if ($shopOrderId === null) {
            return MtUniCreditControlPanelStatusSyncStates::PENDING;
        }

        try {
            $this->cpClient->updateOrderStatus(
                $shopOrderId,
                $statusLabel,
                $statusId
            );
            $confirmed = $this->store->compareAndSetConfirmed($attemptId, $statusId, $statusLabel);
            if ($confirmed) {
                return MtUniCreditControlPanelStatusSyncStates::CONFIRMED;
            }

            return $this->currentStateOr($attemptId, MtUniCreditControlPanelStatusSyncStates::PENDING);
        } catch (Exception $exception) {
            $classification = $this->classifyFailure($exception);
            $newState = !empty($classification['terminal'])
                ? MtUniCreditControlPanelStatusSyncStates::TERMINAL_FAILED
                : MtUniCreditControlPanelStatusSyncStates::PENDING;
            $updated = $this->store->compareAndSetFailure(
                $attemptId,
                $statusId,
                $statusLabel,
                $newState,
                $classification['error_class']
            );
            if (!$updated) {
                return $this->currentStateOr($attemptId, MtUniCreditControlPanelStatusSyncStates::PENDING);
            }

            $this->log(
                !empty($classification['terminal'])
                    ? 'CP status sync terminal failure: ' . $classification['error_class']
                    : 'CP status sync remains pending: ' . $classification['error_class']
            );

            return $newState;
        }
    }

    /**
     * @param int $attemptId
     * @param string $fallback
     * @return string
     */
    private function currentStateOr($attemptId, $fallback)
    {
        $snapshot = $this->store->findByAttempt($attemptId);
        if ($snapshot === null) {
            return $fallback;
        }
        $state = isset($snapshot['cp_status_sync_state'])
            ? (string) $snapshot['cp_status_sync_state']
            : $fallback;

        return $state !== '' ? $state : $fallback;
    }

    /**
     * @param string $currentState
     * @param string|null $currentStatusId
     * @param string $newStatusId
     * @return string
     */
    private function decideAdmission($currentState, $currentStatusId, $newStatusId)
    {
        if ($currentState === MtUniCreditControlPanelStatusSyncStates::NOT_NEEDED || $currentState === '') {
            return self::ADMIT;
        }

        if ($currentStatusId !== null && $currentStatusId === $newStatusId) {
            return self::SAME;
        }

        if ($this->isIncompatibleTerminalSentPair($currentStatusId, $newStatusId)) {
            return self::CONFLICT;
        }

        return self::REJECT;
    }

    /**
     * @param string|null $currentStatusId
     * @param string $newStatusId
     * @return bool
     */
    private function isIncompatibleTerminalSentPair($currentStatusId, $newStatusId)
    {
        if ($currentStatusId === null) {
            return false;
        }

        return in_array($currentStatusId, self::$terminalSent, true)
            && in_array($newStatusId, self::$terminalSent, true)
            && $currentStatusId !== $newStatusId;
    }

    /**
     * @param Exception $exception
     * @return array{terminal: bool, error_class: string}
     */
    private function classifyFailure(Exception $exception)
    {
        if (
            $exception instanceof MtUniCreditCpConnectionException
            || $exception instanceof MtUniCreditCpTimeoutException
        ) {
            return array('terminal' => false, 'error_class' => 'cp_status_transport_ambiguous');
        }
        if (
            $exception instanceof MtUniCreditCpMalformedJsonException
            || $exception instanceof MtUniCreditCpInvalidPayloadException
        ) {
            return array('terminal' => false, 'error_class' => 'cp_status_malformed_response');
        }
        if ($exception instanceof MtUniCreditCpAuthenticationException) {
            return array('terminal' => false, 'error_class' => 'cp_status_auth_retryable');
        }
        if ($exception instanceof MtUniCreditCpHttpException) {
            $status = $exception->getStatusCode();
            $error = '';
            if (method_exists($exception, 'isCanonicalFailure') && $exception->isCanonicalFailure()) {
                $canonical = method_exists($exception, 'getCanonicalError')
                    ? $exception->getCanonicalError()
                    : null;
                $error = $canonical !== null ? (string) $canonical : '';
            } else {
                $response = $exception->getErrorPayload();
                $error = isset($response['error']) && is_string($response['error'])
                    ? $response['error']
                    : '';
            }

            if ($error !== '' && in_array($error, self::$terminalErrorCodes, true)) {
                return array('terminal' => true, 'error_class' => 'cp_status_' . $error);
            }

            if ($error === 'authentication_failed' || $error === 'token_expired') {
                return array('terminal' => false, 'error_class' => 'cp_status_' . $error);
            }
            if ($error === 'rate_limited') {
                return array('terminal' => false, 'error_class' => 'cp_status_rate_limited');
            }
            if ($error === 'internal_error') {
                return array('terminal' => false, 'error_class' => 'cp_status_internal_error');
            }

            return array(
                'terminal' => false,
                'error_class' => $error !== '' ? 'cp_status_' . $error : 'cp_status_http_' . $status,
            );
        }

        return array('terminal' => false, 'error_class' => 'cp_status_' . get_class($exception));
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private function nullableString($value)
    {
        if ($value === null) {
            return null;
        }
        $string = (string) $value;

        return $string === '' ? null : $string;
    }

    /**
     * @param string $message
     * @return void
     */
    private function log($message)
    {
        error_log('mt_uni_credit: ' . (string) $message);
    }
}
