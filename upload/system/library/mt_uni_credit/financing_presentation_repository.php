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
     * @return string Persisted status_label (local + inbound CP vocabulary)
     */
    public function findBankStatusLabel($storeId, $orderId)
    {
        $map = $this->batchBankStatusLabels((int) $storeId, array((int) $orderId));

        return isset($map[(int) $orderId]) ? $map[(int) $orderId] : '';
    }

    /**
     * @param int $storeId
     * @param array<int, int> $orderIds
     * @return array<int, string> order_id => status_label
     */
    public function batchBankStatusLabels($storeId, array $orderIds)
    {
        $ids = array();
        foreach ($orderIds as $orderId) {
            $id = (int) $orderId;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === array()) {
            return array();
        }

        $table = $this->db->getPrefix() . MtUniCreditPersistenceTableNames::ORDER_BANK_STATUS;
        $sql = "SELECT `order_id`, `status_label` FROM `{$table}`"
            . " WHERE `store_id` = " . (int) $storeId
            . " AND `order_id` IN (" . implode(',', $ids) . ")";
        $result = $this->db->query($sql);
        $map = array();
        if (is_object($result) && !empty($result->rows) && is_array($result->rows)) {
            foreach ($result->rows as $row) {
                if (!isset($row['order_id'])) {
                    continue;
                }
                $map[(int) $row['order_id']] = (string) (isset($row['status_label']) ? $row['status_label'] : '');
            }
        }

        return $map;
    }

    /**
     * Resolve bank-status labels for admin list rows using each row's own store_id.
     *
     * @param array<int, array<string, mixed>> $orders
     * @param int $fallbackStoreId
     * @return array<int, string> labels aligned with $orders indexes
     */
    public function bankStatusLabelsForOrders(array $orders, $fallbackStoreId)
    {
        $labels = array_fill(0, count($orders), '');
        $grouped = array();
        foreach ($orders as $index => $order) {
            if (!is_array($order)) {
                continue;
            }
            $orderId = (int) (isset($order['order_id']) ? $order['order_id'] : 0);
            if ($orderId <= 0) {
                continue;
            }
            $storeId = array_key_exists('store_id', $order)
                ? (int) $order['store_id']
                : (int) $fallbackStoreId;
            $grouped[$storeId][$index] = $orderId;
        }
        foreach ($grouped as $storeId => $indexToOrderId) {
            $map = $this->batchBankStatusLabels((int) $storeId, array_values($indexToOrderId));
            foreach ($indexToOrderId as $index => $orderId) {
                $labels[$index] = isset($map[$orderId]) ? $map[$orderId] : '';
            }
        }

        return $labels;
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
