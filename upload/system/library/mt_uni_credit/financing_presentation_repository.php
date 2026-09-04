<?php

/**
 * Loads persisted leasing presentation snapshot for a store-scoped OC order.
 */
final class MtUniCreditFinancingPresentationRepository
{
    /** @var MtUniCreditDbAdapter */
    private $db;

    /**
     * @param MtUniCreditDbAdapter $db
     */
    public function __construct(MtUniCreditDbAdapter $db)
    {
        $this->db = $db;
    }

    /**
     * @param int $storeId
     * @param int $orderId
     * @return MtUniCreditFinancingPresentationSnapshot|null
     */
    public function findByOrderId($storeId, $orderId)
    {
        $row = $this->findAttemptRowByOrderId($storeId, $orderId);
        if ($row === null) {
            return null;
        }
        $json = (string) (isset($row['leasing_presentation_json']) ? $row['leasing_presentation_json'] : '');
        if ($json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }
        try {
            return MtUniCreditFinancingPresentationSnapshot::fromArray($decoded);
        } catch (Throwable $ignored) {
            return null;
        }
    }

    /**
     * @param int $storeId
     * @param int $orderId
     * @return string
     */
    public function findBankStatusLabel($storeId, $orderId)
    {
        $table = $this->db->getPrefix() . MtUniCreditPersistenceTableNames::ORDER_BANK_STATUS;
        $sql = "SELECT `status_id` FROM `{$table}`"
            . " WHERE `store_id` = " . (int) $storeId
            . " AND `order_id` = " . (int) $orderId
            . " LIMIT 1";
        $result = $this->db->query($sql);
        if (!is_object($result) || empty($result->num_rows) || !isset($result->row['status_id'])) {
            return '';
        }
        $statusId = (string) $result->row['status_id'];
        if ($statusId === MtUniCreditBankStatus::SENT_PROCESS2) {
            return MtUniCreditBankStatus::LABEL_SENT_PROCESS2;
        }
        if ($statusId === MtUniCreditBankStatus::SENT_PROCESS1) {
            return MtUniCreditBankStatus::LABEL_SENT_PROCESS1;
        }
        if ($statusId === MtUniCreditBankStatus::SEND_FAILED_SMARTUCF) {
            return MtUniCreditBankStatus::LABEL_SEND_FAILED_SMARTUCF;
        }

        return $statusId;
    }

    /**
     * @param int $storeId
     * @param int $orderId
     * @return array<string, mixed>|null
     */
    public function findAttemptRowByOrderId($storeId, $orderId)
    {
        $table = $this->db->getPrefix() . MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT;
        $sql = "SELECT `attempt_id`, `leasing_presentation_json`, `process2_sensitive_enc`, `control_panel_order_id`"
            . " FROM `{$table}`"
            . " WHERE `store_id` = " . (int) $storeId
            . " AND `order_id` = " . (int) $orderId
            . " ORDER BY `attempt_id` DESC LIMIT 1";
        $result = $this->db->query($sql);
        if (!is_object($result) || empty($result->num_rows) || !is_array($result->row)) {
            return null;
        }

        return $result->row;
    }
}
