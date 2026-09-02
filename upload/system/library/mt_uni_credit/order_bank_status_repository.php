<?php

/**
 * Persists CP → module bank status callbacks scoped by store + local order.
 */
final class MtUniCreditOrderBankStatusRepository
{
    /** @var MtUniCreditDbAdapter */
    private $db;

    /** @var MtUniCreditPersistenceClock */
    private $clock;

    /** @var MtUniCreditOrderOwnershipResolver */
    private $ownership;

    /**
     * @param MtUniCreditDbAdapter $db
     * @param MtUniCreditPersistenceClock|null $clock
     * @param MtUniCreditOrderOwnershipResolver|null $ownership
     */
    public function __construct(MtUniCreditDbAdapter $db, $clock = null, $ownership = null)
    {
        $this->db = $db;
        $this->clock = $clock instanceof MtUniCreditPersistenceClock
            ? $clock
            : new MtUniCreditPersistenceClock();
        $this->ownership = $ownership instanceof MtUniCreditOrderOwnershipResolver
            ? $ownership
            : new MtUniCreditOrderOwnershipResolver($db);
    }

    /**
     * @param int $storeId
     * @param string $orderReference
     * @param string $statusId
     * @param string $statusLabel
     * @return array<string, mixed>|null
     */
    public function updateByOrderIdentifier($storeId, $orderReference, $statusId, $statusLabel)
    {
        MtUniCreditStoreScope::requireStoreId($storeId);
        $orderReference = trim($orderReference);
        $statusId = strtolower(trim($statusId));
        $statusLabel = trim($statusLabel);
        if ($orderReference === '' || $statusId === '') {
            return null;
        }

        $orderId = $this->ownership->resolveAuthorizedOrderId($storeId, $orderReference);
        if ($orderId === null) {
            return null;
        }

        $existing = $this->findByOrderId($storeId, $orderId);
        if ($existing !== null && $existing['status_id'] === $statusId && $existing['status_label'] === $statusLabel) {
            return array(
                'order_id' => $orderReference,
                'oc_order_id' => $orderId,
                'status' => $statusLabel,
                'status_id' => $statusId,
                'oc_order_state_changed' => false,
            );
        }

        $updatedAt = $this->clock->formatUtc($this->clock->now());
        $table = $this->tableName();
        $this->db->query(
            "INSERT INTO `{$table}`"
                . " (`store_id`, `order_id`, `order_reference`, `status_id`, `status_label`, `updated_at`)"
                . " VALUES ("
                . (int) $storeId . ","
                . (int) $orderId . ","
                . " '" . $this->db->escape($orderReference) . "',"
                . " '" . $this->db->escape($statusId) . "',"
                . " '" . $this->db->escape($statusLabel) . "',"
                . " '" . $this->db->escape($updatedAt) . "'"
                . ")"
                . " ON DUPLICATE KEY UPDATE"
                . " `order_reference` = VALUES(`order_reference`),"
                . " `status_id` = VALUES(`status_id`),"
                . " `status_label` = VALUES(`status_label`),"
                . " `updated_at` = VALUES(`updated_at`)"
        );

        return array(
            'order_id' => $orderReference,
            'oc_order_id' => $orderId,
            'status' => $statusLabel,
            'status_id' => $statusId,
            'oc_order_state_changed' => false,
        );
    }

    /**
     * @param int $storeId
     * @param int $orderId
     * @return array<string, mixed>|null
     */
    public function findByOrderId($storeId, $orderId)
    {
        MtUniCreditStoreScope::requireStoreId($storeId);
        $table = $this->tableName();
        $result = $this->db->query(
            "SELECT `order_id`, `order_reference`, `status_id`, `status_label`, `updated_at`"
                . " FROM `{$table}`"
                . " WHERE `store_id` = " . (int) $storeId
                . " AND `order_id` = " . (int) $orderId
                . " LIMIT 1"
        );

        if (!is_object($result) || !isset($result->num_rows) || (int) $result->num_rows !== 1) {
            return null;
        }

        return array(
            'order_id' => (int) $result->row['order_id'],
            'order_reference' => (string) $result->row['order_reference'],
            'status_id' => (string) $result->row['status_id'],
            'status_label' => (string) $result->row['status_label'],
            'updated_at' => (string) $result->row['updated_at'],
        );
    }

    /**
     * @return string
     */
    private function tableName()
    {
        return $this->db->getPrefix() . MtUniCreditPersistenceTableNames::ORDER_BANK_STATUS;
    }
}
