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
     * @return string Display label (canonical for named codes)
     */
    public function findBankStatusLabel($storeId, $orderId)
    {
        $row = $this->findBankStatusRow((int) $storeId, (int) $orderId);
        if ($row === null) {
            return '';
        }

        return MtUniCreditBankStatus::resolveLabel($row['status_id'], $row['status_label']);
    }

    /**
     * @param int $storeId
     * @param int $orderId
     * @return string status_id or empty
     */
    public function findBankStatusId($storeId, $orderId)
    {
        $row = $this->findBankStatusRow((int) $storeId, (int) $orderId);

        return $row !== null ? (string) $row['status_id'] : '';
    }

    /**
     * @param int $storeId
     * @param int $orderId
     * @return array{status_id: string, status_label: string}|null
     */
    public function findBankStatusRow($storeId, $orderId)
    {
        $map = $this->batchBankStatusRows((int) $storeId, array((int) $orderId));

        return isset($map[(int) $orderId]) ? $map[(int) $orderId] : null;
    }

    /**
     * @param int $storeId
     * @param array<int, int> $orderIds
     * @return array<int, string> order_id => status_label
     */
    public function batchBankStatusLabels($storeId, array $orderIds)
    {
        $rows = $this->batchBankStatusRows($storeId, $orderIds);
        $map = array();
        foreach ($rows as $orderId => $row) {
            $map[(int) $orderId] = MtUniCreditBankStatus::resolveLabel(
                $row['status_id'],
                $row['status_label']
            );
        }

        return $map;
    }

    /**
     * @param int $storeId
     * @param array<int, int> $orderIds
     * @return array<int, array{status_id: string, status_label: string}>
     */
    public function batchBankStatusRows($storeId, array $orderIds)
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
        $sql = "SELECT `order_id`, `status_id`, `status_label` FROM `{$table}`"
            . " WHERE `store_id` = " . (int) $storeId
            . " AND `order_id` IN (" . implode(',', $ids) . ")";
        $result = $this->db->query($sql);
        $map = array();
        if (is_object($result) && !empty($result->rows) && is_array($result->rows)) {
            foreach ($result->rows as $row) {
                if (!isset($row['order_id'])) {
                    continue;
                }
                $map[(int) $row['order_id']] = array(
                    'status_id' => (string) (isset($row['status_id']) ? $row['status_id'] : ''),
                    'status_label' => (string) (isset($row['status_label']) ? $row['status_label'] : ''),
                );
            }
        }

        return $map;
    }

    /**
     * Batch-resolve native OC3 order store_id values (store 0 is valid).
     *
     * Real admin order-list rows do not include store_id; authority is oc_order.
     *
     * @param array<int, int> $orderIds
     * @return array<int, int> order_id => store_id (may be 0)
     */
    public function batchNativeOrderStoreIds(array $orderIds)
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

        $table = $this->db->getPrefix() . 'order';
        $sql = "SELECT `order_id`, `store_id` FROM `{$table}`"
            . " WHERE `order_id` IN (" . implode(',', $ids) . ")";
        $result = $this->db->query($sql);
        $map = array();
        if (is_object($result) && !empty($result->rows) && is_array($result->rows)) {
            foreach ($result->rows as $row) {
                if (!is_array($row) || !isset($row['order_id'])) {
                    continue;
                }
                $orderId = (int) $row['order_id'];
                if ($orderId <= 0 || !array_key_exists('store_id', $row)) {
                    continue;
                }
                // array_key_exists: store_id=0 is a valid native store.
                $map[$orderId] = (int) $row['store_id'];
            }
        }

        return $map;
    }

    /**
     * Resolve bank-status labels for admin list rows.
     *
     * Prefer each row's explicit store_id when present (repository fixtures).
     * Otherwise resolve store_id from native OC3 order rows — never from admin
     * config_store_id fallback (real OC3 list rows omit store_id).
     *
     * @param array<int, array<string, mixed>> $orders
     * @param int $fallbackStoreId retained for signature compatibility; not used as row authority
     * @return array<int, string> labels aligned with $orders indexes
     */
    public function bankStatusLabelsForOrders(array $orders, $fallbackStoreId)
    {
        $labels = array_fill(0, count($orders), '');
        $needNative = array();
        foreach ($orders as $index => $order) {
            if (!is_array($order)) {
                continue;
            }
            $orderId = (int) (isset($order['order_id']) ? $order['order_id'] : 0);
            if ($orderId <= 0) {
                continue;
            }
            if (!array_key_exists('store_id', $order)) {
                $needNative[$orderId] = $orderId;
            }
        }
        $nativeStores = $needNative !== array()
            ? $this->batchNativeOrderStoreIds(array_values($needNative))
            : array();

        $grouped = array();
        foreach ($orders as $index => $order) {
            if (!is_array($order)) {
                continue;
            }
            $orderId = (int) (isset($order['order_id']) ? $order['order_id'] : 0);
            if ($orderId <= 0) {
                continue;
            }
            if (array_key_exists('store_id', $order)) {
                $storeId = (int) $order['store_id'];
            } elseif (array_key_exists($orderId, $nativeStores)) {
                $storeId = (int) $nativeStores[$orderId];
            } else {
                // No authoritative store identity — leave blank (do not use config_store_id).
                continue;
            }
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
