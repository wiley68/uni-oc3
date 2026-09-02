<?php

/**
 * Durable financing attempt persistence for checkout CP order lifecycle.
 */
final class MtUniCreditFinancingAttemptRepository
{
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
     * @return MtUniCreditDbAdapter
     */
    public function database()
    {
        return $this->db;
    }

    /**
     * Find or create the single attempt for (store_id, order_id) using CHECKOUT entry point.
     *
     * @param int $storeId
     * @param int $orderId
     * @param string $unicid
     * @param string $operationKeyHash
     * @param string $selectionHash
     * @param string $requestFingerprint
     * @return array<string, mixed>
     */
    public function findOrCreateCheckoutAttempt(
        $storeId,
        $orderId,
        $unicid,
        $operationKeyHash,
        $selectionHash,
        $requestFingerprint
    ) {
        return $this->findOrCreateAttempt(
            $storeId,
            $orderId,
            $unicid,
            $operationKeyHash,
            $selectionHash,
            $requestFingerprint,
            MtUniCreditOperationEntryPoint::CHECKOUT
        );
    }

    /**
     * Find or create the single attempt for (store_id, order_id).
     *
     * @param int $storeId
     * @param int $orderId
     * @param string $unicid
     * @param string $operationKeyHash
     * @param string $selectionHash
     * @param string $requestFingerprint
     * @param string $entryPoint
     * @return array<string, mixed>
     */
    public function findOrCreateAttempt(
        $storeId,
        $orderId,
        $unicid,
        $operationKeyHash,
        $selectionHash,
        $requestFingerprint,
        $entryPoint
    ) {
        MtUniCreditStoreScope::requireStoreId($storeId);
        $orderId = (int) $orderId;
        $unicid = trim($unicid);
        $entryPoint = (string) $entryPoint;
        if ($orderId <= 0 || $unicid === '' || !MtUniCreditOperationEntryPoint::isValid($entryPoint)) {
            throw new MtUniCreditPersistenceValidationException('Financing attempt requires store, order, UNICID and entry point.');
        }

        $existing = $this->findByStoreOrder($storeId, $orderId);
        if ($existing !== null) {
            return $existing;
        }

        $now = $this->clock->formatUtc($this->clock->now());
        $table = $this->tableName();
        try {
            $this->db->query(
                "INSERT INTO `{$table}`"
                    . " (`store_id`, `entry_point`, `operation_key_hash`, `selection_hash`, `request_fingerprint`,"
                    . " `state`, `order_id`, `unicid`, `created_at`, `updated_at`)"
                    . " VALUES ("
                    . (int) $storeId . ","
                    . " '" . $this->db->escape($entryPoint) . "',"
                    . " '" . $this->db->escape($operationKeyHash) . "',"
                    . " '" . $this->db->escape($selectionHash) . "',"
                    . " '" . $this->db->escape($requestFingerprint) . "',"
                    . " '" . $this->db->escape(MtUniCreditFinancingAttemptState::ORDER_CREATED) . "',"
                    . (int) $orderId . ","
                    . " '" . $this->db->escape($unicid) . "',"
                    . " '" . $this->db->escape($now) . "',"
                    . " '" . $this->db->escape($now) . "'"
                    . ")"
            );
        } catch (Exception $exception) {
            $existing = $this->findByStoreOrder($storeId, $orderId);
            if ($existing !== null) {
                return $existing;
            }
            throw new MtUniCreditPersistenceException('Unable to create financing attempt.', 0, $exception);
        }

        $created = $this->findByStoreOrder($storeId, $orderId);
        if ($created === null) {
            throw new MtUniCreditPersistenceException('Financing attempt insert did not persist.');
        }

        return $created;
    }

    /**
     * @param int $storeId
     * @param int $orderId
     * @return array<string, mixed>|null
     */
    public function findByStoreOrder($storeId, $orderId)
    {
        MtUniCreditStoreScope::requireStoreId($storeId);
        $table = $this->tableName();
        $result = $this->db->query(
            "SELECT * FROM `{$table}`"
                . " WHERE `store_id` = " . (int) $storeId
                . " AND `order_id` = " . (int) $orderId
                . " LIMIT 1"
        );

        if (!is_object($result) || !isset($result->num_rows) || (int) $result->num_rows !== 1) {
            return null;
        }

        return $this->normalizeRow($result->row);
    }

    /**
     * @param int $attemptId
     * @return array<string, mixed>|null
     */
    public function findById($attemptId)
    {
        $attemptId = (int) $attemptId;
        if ($attemptId <= 0) {
            return null;
        }

        $table = $this->tableName();
        $result = $this->db->query(
            "SELECT * FROM `{$table}` WHERE `attempt_id` = " . (int) $attemptId . " LIMIT 1"
        );
        if (!is_object($result) || !isset($result->num_rows) || (int) $result->num_rows !== 1) {
            return null;
        }

        return $this->normalizeRow($result->row);
    }

    /**
     * @param int $attemptId
     * @param array<int, string> $expectedStates
     * @param string $newState
     * @return bool
     */
    public function transitionFromStates($attemptId, array $expectedStates, $newState)
    {
        $attemptId = (int) $attemptId;
        if ($attemptId <= 0 || $expectedStates === array()) {
            return false;
        }
        foreach ($expectedStates as $state) {
            if (!MtUniCreditFinancingAttemptState::isValid($state)) {
                throw new MtUniCreditPersistenceValidationException('Invalid expected financing attempt state.');
            }
        }
        if (!MtUniCreditFinancingAttemptState::isValid($newState)) {
            throw new MtUniCreditPersistenceValidationException('Invalid target financing attempt state.');
        }

        $escaped = array();
        foreach ($expectedStates as $state) {
            $escaped[] = "'" . $this->db->escape($state) . "'";
        }
        $updatedAt = $this->clock->formatUtc($this->clock->now());
        $table = $this->tableName();
        $this->db->query(
            "UPDATE `{$table}` SET"
                . " `state` = '" . $this->db->escape($newState) . "',"
                . " `updated_at` = '" . $this->db->escape($updatedAt) . "'"
                . " WHERE `attempt_id` = " . (int) $attemptId
                . " AND `state` IN (" . implode(', ', $escaped) . ")"
        );

        return $this->db->countAffected() === 1;
    }

    /**
     * @param int $attemptId
     * @param array<string, mixed> $payload
     * @param string $requestFingerprint
     * @return bool
     */
    public function persistCpPayload($attemptId, array $payload, $requestFingerprint)
    {
        $attemptId = (int) $attemptId;
        if ($attemptId <= 0) {
            return false;
        }
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new MtUniCreditPersistenceValidationException('CP payload cannot be encoded.');
        }

        $updatedAt = $this->clock->formatUtc($this->clock->now());
        $table = $this->tableName();
        $this->db->query(
            "UPDATE `{$table}` SET"
                . " `cp_payload` = '" . $this->db->escape($encoded) . "',"
                . " `request_fingerprint` = '" . $this->db->escape($requestFingerprint) . "',"
                . " `updated_at` = '" . $this->db->escape($updatedAt) . "'"
                . " WHERE `attempt_id` = " . (int) $attemptId
                . " AND (`cp_payload` IS NULL OR `cp_payload` = '')"
        );

        return true;
    }

    /**
     * @param int $attemptId
     * @param int $cpOrderId
     * @return bool
     */
    public function persistControlPanelOrderId($attemptId, $cpOrderId)
    {
        $attemptId = (int) $attemptId;
        $cpOrderId = (int) $cpOrderId;
        if ($attemptId <= 0 || $cpOrderId <= 0) {
            return false;
        }

        $updatedAt = $this->clock->formatUtc($this->clock->now());
        $table = $this->tableName();
        $this->db->query(
            "UPDATE `{$table}` SET"
                . " `control_panel_order_id` = " . (int) $cpOrderId . ","
                . " `updated_at` = '" . $this->db->escape($updatedAt) . "'"
                . " WHERE `attempt_id` = " . (int) $attemptId
                . " AND (`control_panel_order_id` IS NULL OR `control_panel_order_id` = 0)"
        );

        return $this->db->countAffected() === 1;
    }

    /**
     * @param int $attemptId
     * @param string $errorClass
     * @param string $state
     * @return void
     */
    public function persistFailure($attemptId, $errorClass, $state)
    {
        $attemptId = (int) $attemptId;
        if ($attemptId <= 0) {
            return;
        }
        if (!MtUniCreditControlPanelErrorClass::isValid($errorClass)) {
            $errorClass = MtUniCreditControlPanelErrorClass::RECOVERY_FAILED;
        }
        if (!MtUniCreditFinancingAttemptState::isValid($state)) {
            throw new MtUniCreditPersistenceValidationException('Invalid failure state.');
        }

        $updatedAt = $this->clock->formatUtc($this->clock->now());
        $table = $this->tableName();
        $this->db->query(
            "UPDATE `{$table}` SET"
                . " `state` = '" . $this->db->escape($state) . "',"
                . " `last_error_class` = '" . $this->db->escape($errorClass) . "',"
                . " `updated_at` = '" . $this->db->escape($updatedAt) . "'"
                . " WHERE `attempt_id` = " . (int) $attemptId
        );
    }

    /**
     * @param int $attemptId
     * @return void
     */
    public function clearLastErrorClass($attemptId)
    {
        $attemptId = (int) $attemptId;
        if ($attemptId <= 0) {
            return;
        }
        $updatedAt = $this->clock->formatUtc($this->clock->now());
        $table = $this->tableName();
        $this->db->query(
            "UPDATE `{$table}` SET"
                . " `last_error_class` = NULL,"
                . " `updated_at` = '" . $this->db->escape($updatedAt) . "'"
                . " WHERE `attempt_id` = " . (int) $attemptId
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row)
    {
        return array(
            'attempt_id' => (int) $row['attempt_id'],
            'store_id' => (int) $row['store_id'],
            'entry_point' => (string) $row['entry_point'],
            'operation_key_hash' => (string) $row['operation_key_hash'],
            'selection_hash' => (string) $row['selection_hash'],
            'request_fingerprint' => isset($row['request_fingerprint']) ? (string) $row['request_fingerprint'] : '',
            'state' => (string) $row['state'],
            'order_id' => isset($row['order_id']) ? (int) $row['order_id'] : 0,
            'unicid' => isset($row['unicid']) ? (string) $row['unicid'] : '',
            'control_panel_order_id' => isset($row['control_panel_order_id']) && $row['control_panel_order_id'] !== null
                ? (int) $row['control_panel_order_id']
                : 0,
            'cp_payload' => isset($row['cp_payload']) ? $row['cp_payload'] : null,
            'last_error_class' => isset($row['last_error_class']) ? $row['last_error_class'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        );
    }

    /**
     * @return string
     */
    private function tableName()
    {
        return $this->db->getPrefix() . MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT;
    }
}
