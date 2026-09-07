<?php

/**
 * Connection-scoped MySQL/MariaDB advisory lock for shop_cache credential+cache replacement.
 *
 * Serializes replacements for one (store_id, unicid) without depending on MyISAM/InnoDB
 * transactions. Ownership is the OpenCart DB connection that issues GET_LOCK.
 */
final class MtUniCreditShopCachePersistenceLock
{
    const LOCK_NAME_PREFIX = 'mtuc_sc_';

    /** Hex chars after prefix; prefix(8)+56 = 64 (MySQL GET_LOCK name limit). */
    const LOCK_NAME_HASH_HEX_LENGTH = 56;

    /**
     * Bounded GET_LOCK wait (seconds). Keep in sync with
     * MtUniCreditSecurityConstants::SHOP_CACHE_PERSISTENCE_LOCK_TIMEOUT_SECONDS.
     */
    const ACQUIRE_TIMEOUT_SECONDS = 5;

    const RELEASE_OUTCOME_RELEASED = 'released';

    const RELEASE_OUTCOME_NOT_OWNED = 'not_owned';

    const RELEASE_OUTCOME_MISSING_OR_ERROR = 'missing_or_error';

    const RELEASE_OUTCOME_QUERY_EXCEPTION = 'query_exception';

    /** @var MtUniCreditDbAdapter */
    private $db;

    /**
     * @param MtUniCreditDbAdapter $db Same adapter/connection used for credential+cache writes.
     */
    public function __construct(MtUniCreditDbAdapter $db)
    {
        $this->db = $db;
    }

    /**
     * Deterministic lock identity — hashes store_id|unicid (no raw UNICID in the name).
     *
     * @param int $storeId
     * @param string $unicid
     * @return string
     */
    public function lockName($storeId, $unicid)
    {
        MtUniCreditStoreScope::requireStoreId($storeId);
        $unicid = trim((string) $unicid);
        if ($unicid === '') {
            throw new MtUniCreditPersistenceValidationException('Shop cache lock requires UNICID.');
        }

        $material = (string) ((int) $storeId) . '|' . $unicid;
        $hash = substr(hash('sha256', $material), 0, self::LOCK_NAME_HASH_HEX_LENGTH);

        return self::LOCK_NAME_PREFIX . $hash;
    }

    /**
     * @param int $storeId
     * @param string $unicid
     * @return bool true when acquired
     */
    public function acquire($storeId, $unicid)
    {
        $name = $this->lockName($storeId, $unicid);
        $timeout = (int) self::ACQUIRE_TIMEOUT_SECONDS;
        $sql = "SELECT GET_LOCK('" . $this->db->escape($name) . "', " . $timeout . ") AS `mtuc_lock`";
        $result = $this->db->query($sql);
        $value = $this->scalarLockResult($result);

        if ($value === 1) {
            return true;
        }

        if ($value === 0) {
            return false;
        }

        throw new MtUniCreditPersistenceException('Shop cache persistence lock subsystem failed.');
    }

    /**
     * Release a lock previously acquired on this same DB connection.
     *
     * Never throws: query failures are classified as RELEASE_OUTCOME_QUERY_EXCEPTION.
     *
     * @param int $storeId
     * @param string $unicid
     * @return array{ok: bool, outcome: string, value: int|null}
     */
    public function release($storeId, $unicid)
    {
        try {
            $name = $this->lockName($storeId, $unicid);
            $sql = "SELECT RELEASE_LOCK('" . $this->db->escape($name) . "') AS `mtuc_lock`";
            $result = $this->db->query($sql);
            $value = $this->scalarLockResult($result);

            if ($value === 1) {
                return array(
                    'ok' => true,
                    'outcome' => self::RELEASE_OUTCOME_RELEASED,
                    'value' => 1,
                );
            }

            if ($value === 0) {
                return array(
                    'ok' => false,
                    'outcome' => self::RELEASE_OUTCOME_NOT_OWNED,
                    'value' => 0,
                );
            }

            return array(
                'ok' => false,
                'outcome' => self::RELEASE_OUTCOME_MISSING_OR_ERROR,
                'value' => null,
            );
        } catch (Exception $exception) {
            unset($exception);

            return array(
                'ok' => false,
                'outcome' => self::RELEASE_OUTCOME_QUERY_EXCEPTION,
                'value' => null,
            );
        }
    }

    /**
     * @param mixed $result
     * @return int|null
     */
    private function scalarLockResult($result)
    {
        if (!is_object($result) || !isset($result->row) || !is_array($result->row)) {
            return null;
        }

        if (!array_key_exists('mtuc_lock', $result->row)) {
            return null;
        }

        $raw = $result->row['mtuc_lock'];
        if ($raw === null) {
            return null;
        }

        if (is_int($raw) || is_float($raw) || (is_string($raw) && is_numeric($raw))) {
            return (int) $raw;
        }

        return null;
    }
}
