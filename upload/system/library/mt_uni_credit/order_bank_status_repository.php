<?php

/**
 * Persists module bank status callbacks scoped by store + local order.
 *
 * Inbound path: FinancingOrderResolver (UNICID financing ownership, no payment_code fallback).
 * Local lifecycle: upsertAuthorizedLocal for already-authorized attempt handoffs.
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

    /** @var MtUniCreditFinancingOrderResolver */
    private $resolver;

    /**
     * @param MtUniCreditDbAdapter $db
     * @param MtUniCreditPersistenceClock|null $clock
     * @param MtUniCreditFinancingOrderResolver|null $resolver
     */
    public function __construct(MtUniCreditDbAdapter $db, $clock = null, $resolver = null)
    {
        $this->db = $db;
        $this->clock = $clock instanceof MtUniCreditPersistenceClock
            ? $clock
            : new MtUniCreditPersistenceClock();
        $this->resolver = $resolver instanceof MtUniCreditFinancingOrderResolver
            ? $resolver
            : new MtUniCreditFinancingOrderResolver($db, $this->clock);
    }

    /**
     * CP inbound bank-status update (UNICID + financing ownership).
     *
     * @param int $storeId
     * @param string $unicid
     * @param string $orderReference
     * @param string $statusId
     * @param string $statusLabel inbound/display hint; named codes are canonicalized server-side
     * @param string $source MtUniCreditBankStatusTransitionPolicy::SOURCE_*
     * @return array<string, mixed>|null null only when order ownership cannot be resolved
     */
    public function updateByOrderIdentifier(
        $storeId,
        $unicid,
        $orderReference,
        $statusId,
        $statusLabel,
        $source = MtUniCreditBankStatusTransitionPolicy::SOURCE_INBOUND_CALLBACK
    ) {
        MtUniCreditStoreScope::requireStoreId($storeId);
        $orderReference = trim((string) $orderReference);
        $statusId = strtolower(trim((string) $statusId));
        $statusLabel = trim((string) $statusLabel);
        $source = MtUniCreditBankStatusTransitionPolicy::normalizeSource($source);
        if ($orderReference === '' || $statusId === '' || $statusLabel === '') {
            return null;
        }
        if (!MtUniCreditBankStatusTransitionPolicy::isRecognizedStatusId($statusId)) {
            return null;
        }

        $resolved = $this->resolver->resolve($storeId, (string) $unicid, $orderReference);
        if ($resolved === null) {
            return null;
        }

        $canonicalOrderId = (string) $resolved['order_id'];

        return $this->applyStatusWrite(
            $storeId,
            $canonicalOrderId,
            $canonicalOrderId,
            $statusId,
            $statusLabel,
            $source
        );
    }

    /**
     * Local-only bank status write for proven module handoffs (already authorized by attempt).
     *
     * @param int $storeId
     * @param int|string $orderId Native OC3 int or canonical string
     * @param string $statusId
     * @param string $statusLabel
     * @param string $source
     * @return array<string, mixed>|null
     */
    public function upsertAuthorizedLocal(
        $storeId,
        $orderId,
        $statusId,
        $statusLabel,
        $source = MtUniCreditBankStatusTransitionPolicy::SOURCE_LOCAL_LIFECYCLE
    ) {
        MtUniCreditStoreScope::requireStoreId($storeId);
        $canonicalOrderId = MtUniCreditShopOrderId::tryNormalize($orderId);
        $statusId = strtolower(trim((string) $statusId));
        $statusLabel = trim((string) $statusLabel);
        $source = MtUniCreditBankStatusTransitionPolicy::normalizeSource($source);
        if ($canonicalOrderId === null || $statusId === '' || $statusLabel === '') {
            return null;
        }
        if (!MtUniCreditBankStatusTransitionPolicy::isRecognizedStatusId($statusId)) {
            return null;
        }

        return $this->applyStatusWrite(
            $storeId,
            $canonicalOrderId,
            $canonicalOrderId,
            $statusId,
            $statusLabel,
            $source
        );
    }

    /**
     * @param int $storeId
     * @param string $orderId Canonical shop order id
     * @param string $orderReference Same canonical string (VARCHAR(64) column)
     * @param string $statusId
     * @param string $statusLabel
     * @param string $source
     * @return array<string, mixed>|null
     */
    private function applyStatusWrite($storeId, $orderId, $orderReference, $statusId, $statusLabel, $source)
    {
        $canonicalLabel = MtUniCreditBankStatus::resolveLabel($statusId, $statusLabel);
        $updatedAt = $this->clock->formatUtc($this->clock->now());
        $table = $this->tableName();
        $orderIdSql = MtUniCreditShopOrderId::sqlQuoted($this->db, $orderId);

        $existing = $this->findByOrderId($storeId, $orderId);
        $currentId = $existing !== null ? (string) $existing['status_id'] : '';
        $decision = MtUniCreditBankStatusTransitionPolicy::decide($currentId, $statusId, $source);

        if ($decision === MtUniCreditBankStatusTransitionPolicy::DECISION_REJECT) {
            if ($this->isIncompatibleTerminalSentPair($currentId, $statusId)) {
                throw new MtUniCreditOrderBankStatusSemanticConflictException(
                    'Incompatible terminal bank status progression.'
                );
            }

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
                        . " AND `order_id` = " . $orderIdSql
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
                return $this->applyStatusWrite(
                    $storeId,
                    $orderId,
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
                . " AND `order_id` = " . $orderIdSql
                . " AND `status_id` IN (" . implode(',', $inList) . ")"
        );

        $affected = method_exists($this->db, 'countAffected') ? (int) $this->db->countAffected() : 0;
        $after = $this->findByOrderId($storeId, $orderId);
        if ($affected > 0 && $after !== null && (string) $after['status_id'] === $statusId) {
            return $this->resultPayload($orderReference, $orderId, $after, true);
        }

        // Race lost — if durable P1↔P2 conflict won, surface semantic conflict.
        $persistedId = $after !== null ? (string) $after['status_id'] : $currentId;
        if ($this->isIncompatibleTerminalSentPair($persistedId, $statusId) && $persistedId !== $statusId) {
            throw new MtUniCreditOrderBankStatusSemanticConflictException(
                'Incompatible terminal bank status progression.'
            );
        }

        // Race lost or policy now rejects — return durable current without regression.
        return $this->resultPayload($orderReference, $orderId, $after !== null ? $after : $existing, false);
    }

    /**
     * @param int $storeId
     * @param int|string $orderId
     * @return array<string, mixed>|null
     */
    public function findByOrderId($storeId, $orderId)
    {
        MtUniCreditStoreScope::requireStoreId($storeId);
        $canonicalOrderId = MtUniCreditShopOrderId::tryNormalize($orderId);
        if ($canonicalOrderId === null) {
            return null;
        }
        $table = $this->tableName();
        $result = $this->db->query(
            "SELECT `order_id`, `order_reference`, `status_id`, `status_label`, `updated_at`"
                . " FROM `{$table}`"
                . " WHERE `store_id` = " . (int) $storeId
                . " AND `order_id` = " . MtUniCreditShopOrderId::sqlQuoted($this->db, $canonicalOrderId)
                . " LIMIT 1"
        );

        if (!is_object($result) || !isset($result->num_rows) || (int) $result->num_rows !== 1) {
            return null;
        }

        $rowOrderId = MtUniCreditShopOrderId::tryNormalize(
            isset($result->row['order_id']) ? $result->row['order_id'] : null
        );

        return array(
            'order_id' => $rowOrderId !== null ? $rowOrderId : $canonicalOrderId,
            'order_reference' => (string) $result->row['order_reference'],
            'status_id' => (string) $result->row['status_id'],
            'status_label' => (string) $result->row['status_label'],
            'updated_at' => (string) $result->row['updated_at'],
        );
    }

    /**
     * Alias used by inbound debug ownership checks.
     *
     * @param int $storeId
     * @param int|string $orderId
     * @return array<string, mixed>|null
     */
    public function findCurrentStatus($storeId, $orderId)
    {
        return $this->findByOrderId($storeId, $orderId);
    }

    /**
     * @param string $currentStatusId
     * @param string $newStatusId
     * @return bool
     */
    private function isIncompatibleTerminalSentPair($currentStatusId, $newStatusId)
    {
        if ($currentStatusId === '') {
            return false;
        }

        $terminals = array(
            MtUniCreditBankStatus::SENT_PROCESS1,
            MtUniCreditBankStatus::SENT_PROCESS2,
        );

        return in_array($currentStatusId, $terminals, true)
            && in_array($newStatusId, $terminals, true)
            && $currentStatusId !== $newStatusId;
    }

    /**
     * @param int $storeId
     * @param string $orderId
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
                . " " . MtUniCreditShopOrderId::sqlQuoted($this->db, $orderId) . ","
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
     * @param string $orderId Canonical shop order id
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

        $nativeHint = MtUniCreditShopOrderId::tryNativeOc3OrderId($orderId);

        return array(
            'order_id' => (string) $orderId,
            'oc_order_id' => $nativeHint,
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
