<?php

/**
 * Validated Control Panel shop snapshot persistence (no live CP fetch in Phase 3).
 */
final class MtUniCreditShopCacheRepository
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
     * @param string $unicid
     * @return array<string, mixed>|null
     */
    public function findMetadata($storeId, $unicid)
    {
        MtUniCreditStoreScope::requireStoreId($storeId);
        $unicid = trim($unicid);
        if ($unicid === '') {
            return null;
        }

        $table = $this->tableName();
        $result = $this->db->query(
            "SELECT `fetched_at`, `expires_at` FROM `{$table}`"
                . " WHERE `store_id` = " . (int) $storeId
                . " AND `unicid` = '" . $this->db->escape($unicid) . "'"
                . " LIMIT 1"
        );

        if (!is_object($result) || !isset($result->num_rows) || (int) $result->num_rows !== 1) {
            return null;
        }

        $now = $this->clock->formatUtc($this->clock->now());
        $expiresAt = (string) $result->row['expires_at'];

        return array(
            'fetched_at' => (string) $result->row['fetched_at'],
            'expires_at' => $expiresAt,
            'is_fresh' => $expiresAt > $now,
        );
    }

    /**
     * @param int $storeId
     * @param string $unicid
     * @return array<string, mixed>|null
     */
    public function findLatest($storeId, $unicid)
    {
        return $this->findRow($storeId, $unicid, false);
    }

    /**
     * @param int $storeId
     * @param string $unicid
     * @return array<string, mixed>|null
     */
    public function findFresh($storeId, $unicid)
    {
        return $this->findRow($storeId, $unicid, true);
    }

    /**
     * @param int $storeId
     * @param string $unicid
     * @return string|null
     */
    public function findEncodedShopData($storeId, $unicid)
    {
        MtUniCreditStoreScope::requireStoreId($storeId);
        $unicid = trim($unicid);
        if ($unicid === '') {
            return null;
        }

        $table = $this->tableName();
        $result = $this->db->query(
            "SELECT `shop_data` FROM `{$table}`"
                . " WHERE `store_id` = " . (int) $storeId
                . " AND `unicid` = '" . $this->db->escape($unicid) . "'"
                . " LIMIT 1"
        );

        if (!is_object($result) || !isset($result->num_rows) || (int) $result->num_rows !== 1) {
            return null;
        }

        return isset($result->row['shop_data']) && is_string($result->row['shop_data'])
            ? $result->row['shop_data']
            : null;
    }

    /**
     * @param int $storeId
     * @param string $unicid
     * @param array<string, mixed> $shopData
     * @return void
     */
    public function replaceValidated($storeId, $unicid, array $shopData)
    {
        MtUniCreditStoreScope::requireStoreId($storeId);
        $unicid = trim($unicid);
        if ($unicid === '' || $shopData === array()) {
            throw new MtUniCreditPersistenceValidationException(
                'Shop cache snapshot requires store scope, UNICID and non-empty shop data.'
            );
        }

        $encoded = json_encode($shopData, JSON_UNESCAPED_UNICODE);
        if ($encoded === false || $encoded === '' || $encoded === '[]' || $encoded === '{}') {
            throw new MtUniCreditPersistenceValidationException('Shop cache snapshot cannot be encoded as JSON.');
        }

        $now = $this->clock->now();
        $fetchedAt = $this->clock->formatUtc($now);
        $expiresAt = $this->clock->formatUtc($now + MtUniCreditSecurityConstants::SHOP_CACHE_TTL_SECONDS);
        $table = $this->tableName();

        $this->db->query(
            "INSERT INTO `{$table}`"
                . " (`store_id`, `unicid`, `shop_data`, `fetched_at`, `expires_at`, `created_at`, `updated_at`)"
                . " VALUES ("
                . (int) $storeId . ","
                . " '" . $this->db->escape($unicid) . "',"
                . " '" . $this->db->escape($encoded) . "',"
                . " '" . $this->db->escape($fetchedAt) . "',"
                . " '" . $this->db->escape($expiresAt) . "',"
                . " '" . $this->db->escape($fetchedAt) . "',"
                . " '" . $this->db->escape($fetchedAt) . "'"
                . ")"
                . " ON DUPLICATE KEY UPDATE"
                . " `shop_data` = VALUES(`shop_data`),"
                . " `fetched_at` = VALUES(`fetched_at`),"
                . " `expires_at` = VALUES(`expires_at`),"
                . " `updated_at` = VALUES(`updated_at`)"
        );
        $this->deleteExpiredBatch(10);
    }

    /**
     * @param int $storeId
     * @param string $unicid
     * @return bool
     */
    public function deleteScoped($storeId, $unicid)
    {
        MtUniCreditStoreScope::requireStoreId($storeId);
        $unicid = trim($unicid);
        if ($unicid === '') {
            return true;
        }

        $table = $this->tableName();
        $this->db->query(
            "DELETE FROM `{$table}`"
                . " WHERE `store_id` = " . (int) $storeId
                . " AND `unicid` = '" . $this->db->escape($unicid) . "'"
        );

        return true;
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
     * @param string $unicid
     * @param bool $freshOnly
     * @return array<string, mixed>|null
     */
    private function findRow($storeId, $unicid, $freshOnly)
    {
        MtUniCreditStoreScope::requireStoreId($storeId);
        $unicid = trim($unicid);
        if ($unicid === '') {
            return null;
        }

        $table = $this->tableName();
        $sql = "SELECT `shop_data`, `fetched_at`, `expires_at` FROM `{$table}`"
            . " WHERE `store_id` = " . (int) $storeId
            . " AND `unicid` = '" . $this->db->escape($unicid) . "'";
        if ($freshOnly) {
            $now = $this->clock->formatUtc($this->clock->now());
            $sql .= " AND `expires_at` > '" . $this->db->escape($now) . "'";
        }
        $sql .= " LIMIT 1";

        $result = $this->db->query($sql);
        if (!is_object($result) || !isset($result->num_rows) || (int) $result->num_rows !== 1) {
            return null;
        }

        if (!isset($result->row['shop_data']) || !is_string($result->row['shop_data'])) {
            return null;
        }

        $decoded = json_decode($result->row['shop_data'], true);
        if (!is_array($decoded) || $decoded === array()) {
            return null;
        }

        return array(
            'shop_data' => $decoded,
            'fetched_at' => (string) $result->row['fetched_at'],
            'expires_at' => (string) $result->row['expires_at'],
        );
    }

    /**
     * @return string
     */
    private function tableName()
    {
        return $this->db->getPrefix() . MtUniCreditPersistenceTableNames::SHOP_CACHE;
    }
}
