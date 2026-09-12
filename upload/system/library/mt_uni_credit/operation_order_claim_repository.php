<?php

/**
 * Durable storefront operation → local-order materialization claim.
 *
 * Exists BEFORE OpenCart addOrder() so a lease overrun cannot create a second order
 * for the same (store_id, entry_point, operation_key_hash).
 */
final class MtUniCreditOperationOrderClaimRepository
{
    const STATE_CLAIMED_BEFORE_ORDER = 'claimed_before_order';

    const STATE_ORDER_CREATED = 'order_created';

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
     * Atomically ensure a durable claim row exists for the operation identity.
     *
     * INSERT IGNORE + reload. Does not overwrite an existing claim.
     *
     * @param int $storeId
     * @param string $entryPoint
     * @param string $operationKeyHash
     * @param string $ownerToken
     * @return array<string, mixed>
     */
    public function ensureClaim($storeId, $entryPoint, $operationKeyHash, $ownerToken)
    {
        $this->requireIdentity($storeId, $entryPoint, $operationKeyHash, $ownerToken);

        $now = $this->clock->formatUtc($this->clock->now());
        $table = $this->tableName();

        $this->db->query(
            "INSERT IGNORE INTO `{$table}`"
                . " (`store_id`, `entry_point`, `operation_key_hash`, `state`, `order_id`,"
                . " `claim_owner_token`, `created_at`, `updated_at`)"
                . " VALUES ("
                . (int) $storeId . ","
                . " '" . $this->db->escape($entryPoint) . "',"
                . " '" . $this->db->escape($operationKeyHash) . "',"
                . " '" . $this->db->escape(self::STATE_CLAIMED_BEFORE_ORDER) . "',"
                . " NULL,"
                . " '" . $this->db->escape($ownerToken) . "',"
                . " '" . $this->db->escape($now) . "',"
                . " '" . $this->db->escape($now) . "'"
                . ")"
        );

        $claim = $this->find($storeId, $entryPoint, $operationKeyHash);
        if ($claim === null) {
            throw new MtUniCreditPersistenceException(
                'Operation order claim could not be established.'
            );
        }

        return $claim;
    }

    /**
     * Persist order_id onto an existing claim. Fails closed on conflicting order ids.
     *
     * @param int $storeId
     * @param string $entryPoint
     * @param string $operationKeyHash
     * @param int|string $orderId Native OC3 int or canonical string
     * @return array<string, mixed>
     */
    public function bindOrderId($storeId, $entryPoint, $operationKeyHash, $orderId)
    {
        $this->requireStoreEntryHash($storeId, $entryPoint, $operationKeyHash);
        $canonicalOrderId = MtUniCreditShopOrderId::tryNormalize($orderId);
        if ($canonicalOrderId === null) {
            throw new MtUniCreditPersistenceValidationException(
                'Operation order claim requires a positive order_id.'
            );
        }

        $existing = $this->find($storeId, $entryPoint, $operationKeyHash);
        if ($existing === null) {
            throw new MtUniCreditPersistenceException(
                'Operation order claim is missing during order bind.'
            );
        }

        $boundOrderId = isset($existing['order_id']) && $existing['order_id'] !== null && $existing['order_id'] !== ''
            ? MtUniCreditShopOrderId::tryNormalize($existing['order_id'])
            : null;
        if ($boundOrderId !== null && $boundOrderId !== $canonicalOrderId) {
            throw new MtUniCreditPersistenceValidationException(
                'Operation order claim is already bound to a different order.'
            );
        }

        if ($boundOrderId === $canonicalOrderId && (string) $existing['state'] === self::STATE_ORDER_CREATED) {
            return $existing;
        }

        $now = $this->clock->formatUtc($this->clock->now());
        $table = $this->tableName();
        $orderIdSql = MtUniCreditShopOrderId::sqlQuoted($this->db, $canonicalOrderId);
        $this->db->query(
            "UPDATE `{$table}` SET"
                . " `order_id` = " . $orderIdSql . ","
                . " `state` = '" . $this->db->escape(self::STATE_ORDER_CREATED) . "',"
                . " `updated_at` = '" . $this->db->escape($now) . "'"
                . " WHERE `store_id` = " . (int) $storeId
                . " AND `entry_point` = '" . $this->db->escape($entryPoint) . "'"
                . " AND `operation_key_hash` = '" . $this->db->escape($operationKeyHash) . "'"
                . " AND (`order_id` IS NULL OR `order_id` = " . $orderIdSql . ")"
        );

        if ($this->db->countAffected() < 1) {
            // Concurrent bind to a different order or missing row — re-check.
            $reloaded = $this->find($storeId, $entryPoint, $operationKeyHash);
            if (
                $reloaded !== null
                && isset($reloaded['order_id'])
                && MtUniCreditShopOrderId::tryNormalize($reloaded['order_id']) === $canonicalOrderId
            ) {
                return $reloaded;
            }

            throw new MtUniCreditPersistenceValidationException(
                'Operation order claim could not be bound to the created order.'
            );
        }

        $bound = $this->find($storeId, $entryPoint, $operationKeyHash);
        if ($bound === null) {
            throw new MtUniCreditPersistenceException(
                'Operation order claim disappeared after bind.'
            );
        }

        return $bound;
    }

    /**
     * @param int $storeId
     * @param string $entryPoint
     * @param string $operationKeyHash
     * @return array<string, mixed>|null
     */
    public function find($storeId, $entryPoint, $operationKeyHash)
    {
        $this->requireStoreEntryHash($storeId, $entryPoint, $operationKeyHash);
        $table = $this->tableName();
        $result = $this->db->query(
            "SELECT * FROM `{$table}`"
                . " WHERE `store_id` = " . (int) $storeId
                . " AND `entry_point` = '" . $this->db->escape($entryPoint) . "'"
                . " AND `operation_key_hash` = '" . $this->db->escape($operationKeyHash) . "'"
                . " LIMIT 1"
        );

        if (!is_object($result) || !isset($result->num_rows) || (int) $result->num_rows !== 1) {
            return null;
        }

        return $result->row;
    }

    /**
     * @return string
     */
    private function tableName()
    {
        return $this->db->getPrefix() . MtUniCreditPersistenceTableNames::OPERATION_ORDER_CLAIM;
    }

    /**
     * @param int $storeId
     * @param string $entryPoint
     * @param string $operationKeyHash
     * @param string $ownerToken
     * @return void
     */
    private function requireIdentity($storeId, $entryPoint, $operationKeyHash, $ownerToken)
    {
        $this->requireStoreEntryHash($storeId, $entryPoint, $operationKeyHash);
        if (!MtUniCreditLockOwnerTokenGenerator::isValidFormat($ownerToken)) {
            throw new MtUniCreditPersistenceValidationException(
                'Lock owner token must be 32 lowercase hex characters.'
            );
        }
    }

    /**
     * @param int $storeId
     * @param string $entryPoint
     * @param string $operationKeyHash
     * @return void
     */
    private function requireStoreEntryHash($storeId, $entryPoint, $operationKeyHash)
    {
        MtUniCreditStoreScope::requireStoreId($storeId);
        if (
            !in_array(
                $entryPoint,
                array(MtUniCreditOperationEntryPoint::PRODUCT, MtUniCreditOperationEntryPoint::CART),
                true
            )
        ) {
            throw new MtUniCreditPersistenceValidationException(
                'Unsupported operation order claim entry point.'
            );
        }
        MtUniCreditHashValidator::requireSha256Hex($operationKeyHash, 'operation_key_hash');
    }
}
