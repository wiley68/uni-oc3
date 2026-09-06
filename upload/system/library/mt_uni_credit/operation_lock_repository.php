<?php

/**
 * Short-lived mutex for Product, Cart and Checkout financing operations.
 */
final class MtUniCreditOperationLockRepository
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
     * @param int $storeId
     * @param string $entryPoint
     * @param string $operationKeyHash
     * @param string $ownerToken
     * @return bool
     */
    public function acquire($storeId, $entryPoint, $operationKeyHash, $ownerToken)
    {
        $this->requireIdentity($storeId, $entryPoint, $operationKeyHash, $ownerToken);

        $now = $this->clock->now();
        $expiresAt = $this->clock->formatUtc($now + MtUniCreditSecurityConstants::OPERATION_LOCK_TTL_SECONDS);
        $createdAt = $this->clock->formatUtc($now);
        $table = $this->tableName();

        $this->db->query(
            "INSERT IGNORE INTO `{$table}`"
                . " (`store_id`, `entry_point`, `operation_key_hash`, `owner_token`, `expires_at`, `created_at`, `updated_at`)"
                . " VALUES ("
                . (int) $storeId . ","
                . " '" . $this->db->escape($entryPoint) . "',"
                . " '" . $this->db->escape($operationKeyHash) . "',"
                . " '" . $this->db->escape($ownerToken) . "',"
                . " '" . $this->db->escape($expiresAt) . "',"
                . " '" . $this->db->escape($createdAt) . "',"
                . " '" . $this->db->escape($createdAt) . "'"
                . ")"
        );

        if ($this->db->countAffected() === 1) {
            $this->pruneExpiredIfDue();

            return true;
        }

        // Re-entrant: same owner already holds a non-expired lock (e.g. storefront + lifecycle).
        $nowSql = $this->db->escape($this->clock->formatUtc($now));
        $held = $this->db->query(
            "SELECT `owner_token` FROM `{$table}`"
                . " WHERE `store_id` = " . (int) $storeId
                . " AND `entry_point` = '" . $this->db->escape($entryPoint) . "'"
                . " AND `operation_key_hash` = '" . $this->db->escape($operationKeyHash) . "'"
                . " AND `expires_at` > '" . $nowSql . "'"
                . " LIMIT 1"
        );
        if (
            is_object($held)
            && isset($held->num_rows)
            && (int) $held->num_rows === 1
            && isset($held->row['owner_token'])
            && hash_equals((string) $held->row['owner_token'], $ownerToken)
        ) {
            // Re-entrant hold: extend lease so nested product/cart + lifecycle work stays owned.
            return $this->renew($storeId, $entryPoint, $operationKeyHash, $ownerToken);
        }

        $this->db->query(
            "UPDATE `{$table}` SET"
                . " `owner_token` = '" . $this->db->escape($ownerToken) . "',"
                . " `expires_at` = '" . $this->db->escape($expiresAt) . "',"
                . " `updated_at` = '" . $this->db->escape($createdAt) . "'"
                . " WHERE `store_id` = " . (int) $storeId
                . " AND `entry_point` = '" . $this->db->escape($entryPoint) . "'"
                . " AND `operation_key_hash` = '" . $this->db->escape($operationKeyHash) . "'"
                . " AND `expires_at` <= '" . $nowSql . "'"
        );

        $acquired = $this->db->countAffected() === 1;
        if ($acquired) {
            $this->pruneExpiredIfDue();
        }

        return $acquired;
    }

    /**
     * Extend an active lease for the current owner only.
     *
     * Renewal requires expires_at > now. A stale or foreign owner cannot renew.
     *
     * Under second-precision DATETIME, a same-second renew may leave column values
     * unchanged so MySQL reports 0 changed rows. In that case ownership is proven
     * with a narrow active-owner SELECT (not treated as ownership loss).
     *
     * @param int $storeId
     * @param string $entryPoint
     * @param string $operationKeyHash
     * @param string $ownerToken
     * @return bool true when the caller still owns an active lease
     */
    public function renew($storeId, $entryPoint, $operationKeyHash, $ownerToken)
    {
        $this->requireIdentity($storeId, $entryPoint, $operationKeyHash, $ownerToken);

        $now = $this->clock->now();
        $nowSql = $this->db->escape($this->clock->formatUtc($now));
        $expiresAt = $this->clock->formatUtc($now + MtUniCreditSecurityConstants::OPERATION_LOCK_TTL_SECONDS);
        $updatedAt = $this->clock->formatUtc($now);
        $table = $this->tableName();

        $this->db->query(
            "UPDATE `{$table}` SET"
                . " `expires_at` = '" . $this->db->escape($expiresAt) . "',"
                . " `updated_at` = '" . $this->db->escape($updatedAt) . "'"
                . " WHERE `store_id` = " . (int) $storeId
                . " AND `entry_point` = '" . $this->db->escape($entryPoint) . "'"
                . " AND `operation_key_hash` = '" . $this->db->escape($operationKeyHash) . "'"
                . " AND `owner_token` = '" . $this->db->escape($ownerToken) . "'"
                . " AND `expires_at` > '" . $nowSql . "'"
        );

        if ($this->db->countAffected() === 1) {
            return true;
        }

        // Zero changed rows: either no matching active-owner row, or a same-second no-op UPDATE.
        return $this->hasActiveOwnership($storeId, $entryPoint, $operationKeyHash, $ownerToken, $nowSql);
    }

    /**
     * @param int $storeId
     * @param string $entryPoint
     * @param string $operationKeyHash
     * @param string $ownerToken
     * @param string $nowSql Escaped UTC datetime already prepared for SQL
     * @return bool
     */
    private function hasActiveOwnership($storeId, $entryPoint, $operationKeyHash, $ownerToken, $nowSql)
    {
        $table = $this->tableName();
        $held = $this->db->query(
            "SELECT `owner_token` FROM `{$table}`"
                . " WHERE `store_id` = " . (int) $storeId
                . " AND `entry_point` = '" . $this->db->escape($entryPoint) . "'"
                . " AND `operation_key_hash` = '" . $this->db->escape($operationKeyHash) . "'"
                . " AND `owner_token` = '" . $this->db->escape($ownerToken) . "'"
                . " AND `expires_at` > '" . $nowSql . "'"
                . " LIMIT 1"
        );

        return is_object($held)
            && isset($held->num_rows)
            && (int) $held->num_rows === 1;
    }

    /**
     * @param int $storeId
     * @param string $entryPoint
     * @param string $operationKeyHash
     * @param string $ownerToken
     * @return bool
     */
    public function release($storeId, $entryPoint, $operationKeyHash, $ownerToken)
    {
        $this->requireIdentity($storeId, $entryPoint, $operationKeyHash, $ownerToken);
        $table = $this->tableName();

        $this->db->query(
            "DELETE FROM `{$table}`"
                . " WHERE `store_id` = " . (int) $storeId
                . " AND `entry_point` = '" . $this->db->escape($entryPoint) . "'"
                . " AND `operation_key_hash` = '" . $this->db->escape($operationKeyHash) . "'"
                . " AND `owner_token` = '" . $this->db->escape($ownerToken) . "'"
        );

        return $this->db->countAffected() === 1;
    }

    /**
     * @param int $limit
     * @return int
     */
    public function deleteExpiredBatch($limit = MtUniCreditSecurityConstants::CLEANUP_DEFAULT_BATCH_SIZE)
    {
        $limit = max(1, min(1000, (int) $limit));
        $table = $this->tableName();
        $now = $this->clock->formatUtc($this->clock->now());
        $this->db->query(
            "DELETE FROM `{$table}` WHERE `expires_at` <= '" . $this->db->escape($now) . "' LIMIT " . (int) $limit
        );

        return $this->db->countAffected();
    }

    /**
     * @param int $storeId
     * @param string $entryPoint
     * @param string $operationKeyHash
     * @return array<string, mixed>|null
     */
    public function find($storeId, $entryPoint, $operationKeyHash)
    {
        $this->requireStoreAndEntryPoint($storeId, $entryPoint);
        MtUniCreditHashValidator::requireSha256Hex($operationKeyHash, 'operation_key_hash');

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
     * @return void
     */
    private function pruneExpiredIfDue()
    {
        $this->deleteExpiredBatch(10);
    }

    /**
     * @return string
     */
    private function tableName()
    {
        return $this->db->getPrefix() . MtUniCreditPersistenceTableNames::OPERATION_LOCK;
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
        $this->requireStoreAndEntryPoint($storeId, $entryPoint);
        MtUniCreditHashValidator::requireSha256Hex($operationKeyHash, 'operation_key_hash');
        if (!MtUniCreditLockOwnerTokenGenerator::isValidFormat($ownerToken)) {
            throw new MtUniCreditPersistenceValidationException(
                'Lock owner token must be 32 lowercase hex characters.'
            );
        }
    }

    /**
     * @param int $storeId
     * @param string $entryPoint
     * @return void
     */
    private function requireStoreAndEntryPoint($storeId, $entryPoint)
    {
        MtUniCreditStoreScope::requireStoreId($storeId);
        if (!MtUniCreditOperationEntryPoint::isValid($entryPoint)) {
            throw new MtUniCreditPersistenceValidationException('Unsupported operation lock entry point.');
        }
    }
}
