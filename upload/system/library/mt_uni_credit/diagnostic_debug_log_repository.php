<?php

/**
 * Store-scoped SmartUCF diagnostic log persistence (Phase 6 read bridge; Phase 11 writers).
 */
final class MtUniCreditDiagnosticDebugLogRepository
{
    const MAX_SUMMARY_JSON_BYTES = 65536;

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
        $entryPoint = substr(trim($entryPoint), 0, 16);
        $eventCode = substr(trim($eventCode), 0, 64);
        $summary = MtUniCreditDiagnosticPayloadRedactor::redact($summary);
        $encoded = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new MtUniCreditPersistenceValidationException('Diagnostic summary cannot be encoded.');
        }
        if (strlen($encoded) > self::MAX_SUMMARY_JSON_BYTES) {
            throw new MtUniCreditPersistenceValidationException('Diagnostic summary exceeds size limit.');
        }

        $createdAt = $this->clock->formatUtc($this->clock->now());
        $table = $this->tableName();
        $this->db->query(
            "INSERT INTO `{$table}`"
                . " (`store_id`, `order_id`, `entry_point`, `event_code`, `http_status`, `summary_json`, `created_at`)"
                . " VALUES ("
                . (int) $storeId . ","
                . (int) $orderId . ","
                . " '" . $this->db->escape($entryPoint) . "',"
                . " '" . $this->db->escape($eventCode) . "',"
                . ($httpStatus === null ? 'NULL' : (int) $httpStatus) . ","
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
        $table = $this->tableName();
        $result = $this->db->query(
            "SELECT `summary_json`, `entry_point`, `event_code`, `http_status`, `created_at`"
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
     * @param int $orderId
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatCpPayload($orderId, array $row)
    {
        $summary = array();
        if (isset($row['summary_json']) && is_string($row['summary_json']) && $row['summary_json'] !== '') {
            $decoded = json_decode($row['summary_json'], true);
            if (is_array($decoded)) {
                $summary = MtUniCreditDiagnosticPayloadRedactor::redact($decoded);
            }
        }

        return array(
            'order_id' => (int) $orderId,
            'entry_point' => isset($row['entry_point']) ? (string) $row['entry_point'] : '',
            'event_code' => isset($row['event_code']) ? (string) $row['event_code'] : '',
            'http_status' => isset($row['http_status']) ? (int) $row['http_status'] : null,
            'summary' => $summary,
            'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : '',
        );
    }

    /**
     * @return string
     */
    private function tableName()
    {
        return $this->db->getPrefix() . MtUniCreditPersistenceTableNames::DIAGNOSTIC_DEBUG_LOG;
    }
}
