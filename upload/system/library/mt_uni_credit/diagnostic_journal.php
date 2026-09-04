<?php

/**
 * Support diagnostic journal writer (OC4 SmartUcfDiagnosticJournal + Woo Mtuc_Debug_Log parity).
 *
 * Compact operational rows are always written when order_id > 0.
 * SmartUCF session rows always include redacted request/response when captured at transport.
 * Journal write failures never alter financing outcomes.
 */
final class MtUniCreditDiagnosticJournal
{
    const OPERATION_SESSION_START = 'sucfOnlineSessionStart';

    /** Woo Mtuc_Debug_Log::TYPE_SMARTUCF parity — used for CP record selection. */
    const TYPE_SMARTUCF_SESSION = 'smartucf_session';

    const EVENT_SUCCESS = 'success';

    const EVENT_REMOTE_REJECT = 'remote_reject';

    const EVENT_TRANSPORT_AMBIGUOUS = 'transport_ambiguous';

    const EVENT_CERTIFICATE_SYNC_FAILED = 'certificate_sync_failed';

    const EVENT_CP_CREATE_SUCCESS = 'cp_create_success';

    const EVENT_CP_CREATE_REJECTED = 'cp_create_rejected';

    const EVENT_CP_CREATE_TIMEOUT = 'cp_create_timeout';

    const EVENT_CP_CREATE_OUTCOME_UNKNOWN = 'cp_create_outcome_unknown';

    const EVENT_CP_STATUS_PATCH_SUCCESS = 'cp_status_patch_success';

    const EVENT_CP_STATUS_PATCH_FAILED = 'cp_status_patch_failed';

    const EVENT_PROCESS2_PREPARED = 'process2_bank_status_prepared';

    const EVENT_PROCESS2_MAIL_FAILED = 'process2_mail_failed';

    /** @var MtUniCreditDiagnosticDebugLogRepository */
    private $repository;

    /** @var callable */
    private $debugGate;

    /**
     * @param MtUniCreditDiagnosticDebugLogRepository $repository
     * @param callable $debugGate function(int $storeId): bool
     */
    public function __construct(MtUniCreditDiagnosticDebugLogRepository $repository, $debugGate)
    {
        $this->repository = $repository;
        $this->debugGate = $debugGate;
    }

    /**
     * @param MtUniCreditDbAdapter $db
     * @param MtUniCreditSettingStore|null $settings
     * @return self
     */
    public static function fromDatabase(MtUniCreditDbAdapter $db, $settings = null)
    {
        if (!$settings instanceof MtUniCreditSettingStore) {
            $settings = new MtUniCreditSettingStore($db, MtUniCreditConstants::MODULE_SETTINGS_CODE);
        }

        return new self(
            new MtUniCreditDiagnosticDebugLogRepository($db),
            function ($storeId) use ($settings) {
                $storeId = (int) $storeId;
                if (!MtUniCreditStoreScope::isValid($storeId)) {
                    return false;
                }
                $raw = $settings->get($storeId, MtUniCreditConstants::MODULE_SETTING_DEBUG);
                if ($raw === null || $raw === '') {
                    $raw = $settings->get($storeId, MtUniCreditConstants::MODULE_SETTING_DEBUG_LEGACY);
                }

                return ((string) $raw === '1' || $raw === 1 || $raw === true);
            }
        );
    }

    /**
     * @param int $storeId
     * @return bool
     */
    public function isDebugEnabled($storeId)
    {
        try {
            return (bool) call_user_func($this->debugGate, (int) $storeId);
        } catch (Exception $exception) {
            return false;
        }
    }

    /**
     * Compact operational row. Safe for CP support without debug mode.
     *
     * @param int $storeId
     * @param int $orderId
     * @param string $entryPoint
     * @param string $eventCode
     * @param int|null $httpStatus
     * @param array<string, mixed> $summary
     * @return bool
     */
    public function record($storeId, $orderId, $entryPoint, $eventCode, $httpStatus, array $summary)
    {
        $storeId = (int) $storeId;
        $orderId = (int) $orderId;
        if ($orderId <= 0 || !MtUniCreditStoreScope::isValid($storeId)) {
            return false;
        }

        try {
            if (!isset($summary['outcome']) || !is_string($summary['outcome']) || $summary['outcome'] === '') {
                $summary['outcome'] = (string) $eventCode;
            }
            if (!isset($summary['message']) || !is_string($summary['message']) || trim($summary['message']) === '') {
                $summary['message'] = self::defaultMessage((string) $eventCode, $httpStatus);
            }
            $this->repository->insert(
                $storeId,
                $orderId,
                (string) $entryPoint,
                (string) $eventCode,
                $httpStatus === null ? null : (int) $httpStatus,
                $summary
            );

            return true;
        } catch (Exception $exception) {
            error_log(
                'mt_uni_credit: diagnostic journal write failed class='
                    . get_class($exception)
                    . ' store_id=' . $storeId
                    . ' order_id=' . $orderId
                    . ' event=' . substr((string) $eventCode, 0, 64)
            );

            return false;
        }
    }

    /**
     * SmartUCF session capture for CP 「Информация за заявка」.
     *
     * Always persists redacted request/response when provided (Woo stores them when
     * journaling; OC3 already writes the session row without a debug gate so bodies
     * must not be omitted or CP shows empty JSON).
     *
     * @param int $storeId
     * @param int $orderId
     * @param string $entryPoint
     * @param string $endpoint
     * @param mixed $request
     * @param mixed $response
     * @param int $httpStatus
     * @param string|null $transportError
     * @param string $eventCode
     * @return bool
     */
    public function recordSmartUcfSession(
        $storeId,
        $orderId,
        $entryPoint,
        $endpoint,
        $request,
        $response,
        $httpStatus,
        $transportError,
        $eventCode
    ) {
        $summary = array(
            'type' => self::TYPE_SMARTUCF_SESSION,
            'operation' => self::OPERATION_SESSION_START,
            'endpoint' => is_string($endpoint) ? $endpoint : '',
            'outcome' => (string) $eventCode,
            'message' => self::defaultMessage((string) $eventCode, $httpStatus),
            'request' => MtUniCreditDiagnosticPayloadRedactor::redactMixed($request),
            'response' => MtUniCreditDiagnosticPayloadRedactor::redactMixed($response),
        );

        if ($transportError !== null && $transportError !== '') {
            $summary['transport_error'] = is_string($transportError)
                ? MtUniCreditDiagnosticPayloadRedactor::redact($transportError)
                : (string) $transportError;
        }

        return $this->record(
            $storeId,
            $orderId,
            $entryPoint,
            $eventCode,
            $httpStatus > 0 ? (int) $httpStatus : null,
            $summary
        );
    }

    /**
     * @param int $storeId
     * @return array<string, mixed>
     */
    public function buildExport($storeId)
    {
        $storeId = (int) $storeId;
        MtUniCreditStoreScope::requireStoreId($storeId);
        $entries = $this->repository->findAllForStore($storeId);

        return array(
            'module' => MtUniCreditConstants::EXTENSION_CODE,
            'module_version' => MtUniCreditConstants::VERSION,
            'exported_at_gmt' => gmdate('c'),
            'store_id' => $storeId,
            'debug_enabled' => $this->isDebugEnabled($storeId),
            'total_entries' => count($entries),
            'entries' => $entries,
        );
    }

    /**
     * @param string $eventCode
     * @param int|null $httpStatus
     * @return string
     */
    public static function defaultMessage($eventCode, $httpStatus)
    {
        switch ((string) $eventCode) {
            case self::EVENT_SUCCESS:
                return 'SmartUCF session created successfully.';
            case self::EVENT_REMOTE_REJECT:
                return 'SmartUCF returned a definitive rejection.';
            case self::EVENT_TRANSPORT_AMBIGUOUS:
                return 'SmartUCF request timed out or returned an ambiguous transport result.';
            case self::EVENT_CERTIFICATE_SYNC_FAILED:
                return 'SmartUCF certificate synchronization failed.';
            case self::EVENT_CP_CREATE_SUCCESS:
                return 'CP create returned success.';
            case self::EVENT_CP_CREATE_REJECTED:
                return $httpStatus !== null && (int) $httpStatus > 0
                    ? ('CP create rejected with HTTP ' . (int) $httpStatus . '.')
                    : 'CP create was rejected.';
            case self::EVENT_CP_CREATE_TIMEOUT:
                return 'CP create timed out.';
            case self::EVENT_CP_CREATE_OUTCOME_UNKNOWN:
                return 'CP create outcome is unknown.';
            case self::EVENT_CP_STATUS_PATCH_SUCCESS:
                return 'CP status PATCH succeeded.';
            case self::EVENT_CP_STATUS_PATCH_FAILED:
                return $httpStatus !== null && (int) $httpStatus > 0
                    ? ('CP status PATCH failed with HTTP ' . (int) $httpStatus . '.')
                    : 'CP status PATCH failed.';
            case self::EVENT_PROCESS2_PREPARED:
                return 'Process 2 bank status prepared locally.';
            case self::EVENT_PROCESS2_MAIL_FAILED:
                return 'Process 2 mail delivery failed.';
            default:
                return 'Diagnostic lifecycle event recorded.';
        }
    }
}
