<?php

/**
 * Store-scoped diagnostic journal persistence (write + CP/admin read).
 */
final class MtUniCreditDiagnosticDebugLogRepository
{
    const MAX_SUMMARY_JSON_BYTES = 65536;

    const RETENTION_MONTHS = 3;

    /** @var MtUniCreditDbAdapter */
    private $db;

    /** @var MtUniCreditPersistenceClock */
    private $clock;

    /**
     * @param MtUniCreditDbAdapter $db
     * @param MtUniCreditPersistenceClock|null $clock
     */
    public function __construct(MtUniCreditDbAdapter $db, $clock = null)
    {
        $this->db = $db;
        $this->clock = $clock instanceof MtUniCreditPersistenceClock
            ? $clock
            : new MtUniCreditPersistenceClock();
    }

    /**
     * @param int $storeId
     * @param int $orderId
     * @param string $entryPoint
     * @param string $eventCode
     * @param int|null $httpStatus
     * @param array<string, mixed> $summary
     * @return int
     */
    public function insert($storeId, $orderId, $entryPoint, $eventCode, $httpStatus, array $summary)
    {
        MtUniCreditStoreScope::requireStoreId($storeId);
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            throw new MtUniCreditPersistenceValidationException('Diagnostic journal requires a positive order_id.');
        }

        $entryPoint = substr(trim((string) $entryPoint), 0, 16);
        $eventCode = substr(trim((string) $eventCode), 0, 64);
        $summary = MtUniCreditDiagnosticPayloadRedactor::redact($summary);
        $encoded = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            // Invalid UTF-8 / non-encodable values — store a safe stub instead of failing the writer hard.
            $encoded = json_encode(array(
                'message' => 'Diagnostic summary could not be encoded.',
                'outcome' => $eventCode,
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new MtUniCreditPersistenceValidationException('Diagnostic summary cannot be encoded.');
            }
        }
        if (strlen($encoded) > self::MAX_SUMMARY_JSON_BYTES) {
            $encoded = json_encode(array(
                'message' => 'Diagnostic summary truncated to size limit.',
                'outcome' => $eventCode,
                'truncated' => true,
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false || strlen($encoded) > self::MAX_SUMMARY_JSON_BYTES) {
                throw new MtUniCreditPersistenceValidationException('Diagnostic summary exceeds size limit.');
            }
        }

        $this->pruneOld(10);

        $createdAt = $this->clock->formatUtc($this->clock->now());
        $table = $this->tableName();
        $httpSql = ($httpStatus === null || (int) $httpStatus <= 0) ? 'NULL' : (int) $httpStatus;
        $this->db->query(
            "INSERT INTO `{$table}`"
                . " (`store_id`, `order_id`, `entry_point`, `event_code`, `http_status`, `summary_json`, `created_at`)"
                . " VALUES ("
                . (int) $storeId . ","
                . (int) $orderId . ","
                . " '" . $this->db->escape($entryPoint) . "',"
                . " '" . $this->db->escape($eventCode) . "',"
                . $httpSql . ","
                . " '" . $this->db->escape($encoded) . "',"
                . " '" . $this->db->escape($createdAt) . "'"
                . ")"
        );

        return (int) $this->db->getLastId();
    }

    /**
     * @param int $storeId
     * @param int $orderId
     * @return array<string, mixed>|null
     */
    public function findLatestByOrderId($storeId, $orderId)
    {
        MtUniCreditStoreScope::requireStoreId($storeId);
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            return null;
        }

        $table = $this->tableName();
        $result = $this->db->query(
            "SELECT `diagnostic_debug_log_id`, `summary_json`, `entry_point`, `event_code`, `http_status`, `created_at`"
                . " FROM `{$table}`"
                . " WHERE `store_id` = " . (int) $storeId
                . " AND `order_id` = " . (int) $orderId
                . " ORDER BY `diagnostic_debug_log_id` DESC"
                . " LIMIT 1"
        );

        if (!is_object($result) || !isset($result->num_rows) || (int) $result->num_rows !== 1) {
            return null;
        }

        return $this->formatCpPayload($orderId, $result->row);
    }

    /**
     * @param int $storeId
     * @return array<int, array<string, mixed>>
     */
    public function findAllForStore($storeId)
    {
        MtUniCreditStoreScope::requireStoreId($storeId);
        $this->pruneOld(10);

        $table = $this->tableName();
        $result = $this->db->query(
            "SELECT `diagnostic_debug_log_id`, `store_id`, `order_id`, `entry_point`, `event_code`,"
                . " `http_status`, `summary_json`, `created_at`"
                . " FROM `{$table}`"
                . " WHERE `store_id` = " . (int) $storeId
                . " ORDER BY `diagnostic_debug_log_id` ASC"
        );

        if (!is_object($result) || empty($result->rows) || !is_array($result->rows)) {
            return array();
        }

        $entries = array();
        foreach ($result->rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $entries[] = $this->formatExportEntry($row);
        }

        return $entries;
    }

    /**
     * @param int $storeId
     * @return int
     */
    public function countForStore($storeId)
    {
        MtUniCreditStoreScope::requireStoreId($storeId);
        $table = $this->tableName();
        $result = $this->db->query(
            "SELECT COUNT(*) AS `total` FROM `{$table}` WHERE `store_id` = " . (int) $storeId
        );
        if (!is_object($result) || !isset($result->num_rows) || (int) $result->num_rows !== 1) {
            return 0;
        }

        return (int) (isset($result->row['total']) ? $result->row['total'] : 0);
    }

    /**
     * @param int $limit
     * @return int
     */
    public function pruneOld($limit = null)
    {
        $limit = $limit === null
            ? MtUniCreditSecurityConstants::CLEANUP_DEFAULT_BATCH_SIZE
            : (int) $limit;
        $limit = max(1, min(1000, $limit));
        $cutoff = self::retentionCutoff($this->clock->now());
        $table = $this->tableName();
        $this->db->query(
            "DELETE FROM `{$table}`"
                . " WHERE `created_at` < '" . $this->db->escape($cutoff) . "'"
                . " ORDER BY `created_at` ASC"
                . " LIMIT " . (int) $limit
        );

        return $this->db->countAffected();
    }

    /**
     * @param int $now Unix timestamp
     * @return string
     */
    public static function retentionCutoff($now = null)
    {
        $now = $now === null ? time() : (int) $now;

        return gmdate('Y-m-d H:i:s', $now - (self::RETENTION_MONTHS * 30 * 86400));
    }

    /**
     * @param int $orderId
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatCpPayload($orderId, array $row)
    {
        $summary = $this->decodeSummary(isset($row['summary_json']) ? (string) $row['summary_json'] : '');
        $httpStatus = isset($row['http_status']) && $row['http_status'] !== null && $row['http_status'] !== ''
            ? (int) $row['http_status']
            : 0;
        $createdAt = isset($row['created_at']) ? (string) $row['created_at'] : '';
        $eventCode = isset($row['event_code']) ? (string) $row['event_code'] : '';

        return array(
            'order_id' => (int) $orderId,
            'entry_point' => isset($row['entry_point']) ? (string) $row['entry_point'] : '',
            'event_code' => $eventCode,
            'http_status' => $httpStatus > 0 ? $httpStatus : null,
            'http_code' => $httpStatus > 0 ? $httpStatus : null,
            'operation' => isset($summary['operation'])
                ? (string) $summary['operation']
                : MtUniCreditDiagnosticJournal::OPERATION_SESSION_START,
            'endpoint' => isset($summary['endpoint']) ? $summary['endpoint'] : null,
            'outcome' => isset($summary['outcome']) ? (string) $summary['outcome'] : $eventCode,
            'request' => isset($summary['request']) ? $summary['request'] : null,
            'response' => isset($summary['response']) ? $summary['response'] : null,
            'transport_error' => isset($summary['transport_error']) ? $summary['transport_error'] : null,
            'summary' => $summary,
            'created_at' => $createdAt,
            'created_at_gmt' => $createdAt,
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatExportEntry(array $row)
    {
        $summary = $this->decodeSummary(isset($row['summary_json']) ? (string) $row['summary_json'] : '');
        $httpStatus = isset($row['http_status']) && $row['http_status'] !== null && $row['http_status'] !== ''
            ? (int) $row['http_status']
            : 0;
        $eventCode = isset($row['event_code']) ? (string) $row['event_code'] : '';

        return array(
            'id' => isset($row['diagnostic_debug_log_id']) ? (int) $row['diagnostic_debug_log_id'] : 0,
            'store_id' => isset($row['store_id']) ? (int) $row['store_id'] : 0,
            'order_id' => isset($row['order_id']) ? (int) $row['order_id'] : 0,
            'entry_point' => isset($row['entry_point']) ? (string) $row['entry_point'] : '',
            'event_code' => $eventCode,
            'http_code' => $httpStatus,
            'operation' => isset($summary['operation'])
                ? (string) $summary['operation']
                : MtUniCreditDiagnosticJournal::OPERATION_SESSION_START,
            'endpoint' => isset($summary['endpoint']) ? $summary['endpoint'] : null,
            'outcome' => isset($summary['outcome']) ? (string) $summary['outcome'] : $eventCode,
            'request' => isset($summary['request']) ? $summary['request'] : null,
            'response' => isset($summary['response']) ? $summary['response'] : null,
            'transport_error' => isset($summary['transport_error']) ? $summary['transport_error'] : null,
            'message' => isset($summary['message']) ? (string) $summary['message'] : '',
            'created_at_gmt' => isset($row['created_at']) ? (string) $row['created_at'] : '',
        );
    }

    /**
     * @param string $json
     * @return array<string, mixed>
     */
    private function decodeSummary($json)
    {
        if ($json === '') {
            return array();
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return array();
        }

        return MtUniCreditDiagnosticPayloadRedactor::redact($decoded);
    }

    /**
     * @return string
     */
    private function tableName()
    {
        return $this->db->getPrefix() . MtUniCreditPersistenceTableNames::DIAGNOSTIC_DEBUG_LOG;
    }
}
