<?php

/**
 * Persists module bank status callbacks scoped by store + local order.
 *
 * AUD-015 F01: transitions enforced by MtUniCreditBankStatusTransitionPolicy via
 * atomic conditional UPDATE (CAS) — not unconditional last-write-wins.
 * Native OpenCart order history / mail are never touched here.
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
     * @param string $statusLabel inbound/display hint; named codes are canonicalized server-side
     * @param string $source MtUniCreditBankStatusTransitionPolicy::SOURCE_*
     * @return array<string, mixed>|null null only when order ownership cannot be resolved
     */
    public function updateByOrderIdentifier(
        $storeId,
        $orderReference,
        $statusId,
        $statusLabel,
        $source = MtUniCreditBankStatusTransitionPolicy::SOURCE_INBOUND_CALLBACK
    ) {
        MtUniCreditStoreScope::requireStoreId($storeId);
        $orderReference = trim($orderReference);
        $statusId = strtolower(trim($statusId));
        $statusLabel = trim((string) $statusLabel);
        $source = MtUniCreditBankStatusTransitionPolicy::normalizeSource($source);
        if ($orderReference === '' || $statusId === '') {
            return null;
        }
        if (!MtUniCreditBankStatusTransitionPolicy::isRecognizedStatusId($statusId)) {
            return null;
        }

        $orderId = $this->ownership->resolveAuthorizedOrderId($storeId, $orderReference);
        if ($orderId === null) {
            return null;
        }

        $canonicalLabel = MtUniCreditBankStatus::resolveLabel($statusId, $statusLabel);
        $updatedAt = $this->clock->formatUtc($this->clock->now());
        $table = $this->tableName();

        $existing = $this->findByOrderId($storeId, $orderId);
        $currentId = $existing !== null ? (string) $existing['status_id'] : '';
        $decision = MtUniCreditBankStatusTransitionPolicy::decide($currentId, $statusId, $source);

        if ($decision === MtUniCreditBankStatusTransitionPolicy::DECISION_REJECT) {
            return $this->resultPayload($orderReference, $orderId, $existing, false);
        }

        if ($decision === MtUniCreditBankStatusTransitionPolicy::DECISION_NOOP) {
            if (
                $existing !== null
                && (string) $existing['status_label'] !== $canonicalLabel
                && MtUniCreditBankStatus::canonicalLabel($statusId) !== null
            ) {
                // Canonicalize stale/mismatched label without changing status_id (AUD-015 F04).
                $this->db->query(
                    "UPDATE `{$table}` SET"
                        . " `status_label` = '" . $this->db->escape($canonicalLabel) . "',"
                        . " `order_reference` = '" . $this->db->escape($orderReference) . "',"
                        . " `updated_at` = '" . $this->db->escape($updatedAt) . "'"
                        . " WHERE `store_id` = " . (int) $storeId
                        . " AND `order_id` = " . (int) $orderId
                        . " AND `status_id` = '" . $this->db->escape($statusId) . "'"
                );
                $existing = $this->findByOrderId($storeId, $orderId);
            }

            return $this->resultPayload($orderReference, $orderId, $existing, false);
        }

        // DECISION_ALLOW
        if ($existing === null) {
            $this->insertNewRow($storeId, $orderId, $orderReference, $statusId, $canonicalLabel, $updatedAt);
            $after = $this->findByOrderId($storeId, $orderId);
            if ($after === null) {
                return null;
            }
            // Concurrent insert may have won with a different status — re-apply policy.
            if ((string) $after['status_id'] !== $statusId) {
                return $this->updateByOrderIdentifier(
                    $storeId,
                    $orderReference,
                    $statusId,
                    $canonicalLabel,
                    $source
                );
            }

            return $this->resultPayload($orderReference, $orderId, $after, true);
        }

        $allowedFrom = MtUniCreditBankStatusTransitionPolicy::allowedFromStatusIds($statusId, $source);
        // Policy already ALLOWed ($currentId → $statusId). Named-only predecessor lists omit
        // arbitrary numeric externals (e.g. "42"); include the exact observed predecessor so
        // CAS can apply without enumerating every numeric code (AUD-015 F01-R1).
        if ($currentId !== '' && !in_array($currentId, $allowedFrom, true)) {
            $allowedFrom[] = $currentId;
        }
        if ($allowedFrom === array()) {
            return $this->resultPayload($orderReference, $orderId, $existing, false);
        }

        $inList = array();
        foreach ($allowedFrom as $fromId) {
            $inList[] = "'" . $this->db->escape($fromId) . "'";
        }

        // Atomic CAS: only mutate when current status is still an allowed predecessor.
        $this->db->query(
            "UPDATE `{$table}` SET"
                . " `order_reference` = '" . $this->db->escape($orderReference) . "',"
                . " `status_id` = '" . $this->db->escape($statusId) . "',"
                . " `status_label` = '" . $this->db->escape($canonicalLabel) . "',"
                . " `updated_at` = '" . $this->db->escape($updatedAt) . "'"
                . " WHERE `store_id` = " . (int) $storeId
                . " AND `order_id` = " . (int) $orderId
                . " AND `status_id` IN (" . implode(',', $inList) . ")"
        );

        $affected = method_exists($this->db, 'countAffected') ? (int) $this->db->countAffected() : 0;
        $after = $this->findByOrderId($storeId, $orderId);
        if ($affected > 0 && $after !== null && (string) $after['status_id'] === $statusId) {
            return $this->resultPayload($orderReference, $orderId, $after, true);
        }

        // Race lost or policy now rejects — return durable current without regression.
        return $this->resultPayload($orderReference, $orderId, $after !== null ? $after : $existing, false);
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
     * @param int $storeId
     * @param int $orderId
     * @param string $orderReference
     * @param string $statusId
     * @param string $statusLabel
     * @param string $updatedAt
     * @return void
     */
    private function insertNewRow($storeId, $orderId, $orderReference, $statusId, $statusLabel, $updatedAt)
    {
        $table = $this->tableName();
        // INSERT only — concurrent winner keeps its row; loser re-reads and CAS-updates.
        // ON DUPLICATE KEY UPDATE is intentionally a no-op so a raced durable row is not clobbered.
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
                . " `order_id` = `order_id`"
        );
    }

    /**
     * @param string $orderReference
     * @param int $orderId
     * @param array<string, mixed>|null $row
     * @param bool $changed
     * @return array<string, mixed>
     */
    private function resultPayload($orderReference, $orderId, $row, $changed)
    {
        $statusId = $row !== null ? (string) $row['status_id'] : '';
        $statusLabel = $row !== null ? (string) $row['status_label'] : '';
        if ($statusId !== '') {
            $statusLabel = MtUniCreditBankStatus::resolveLabel($statusId, $statusLabel);
        }

        return array(
            'order_id' => $orderReference,
            'oc_order_id' => $orderId,
            'status' => $statusLabel,
            'status_id' => $statusId,
            'oc_order_state_changed' => false,
            'applied' => (bool) $changed,
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
