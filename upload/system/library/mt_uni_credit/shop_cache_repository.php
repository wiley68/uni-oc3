<?php

/**
 * Validated Control Panel shop snapshot persistence (no live CP fetch in Phase 3).
 */
final class MtUniCreditShopCacheRepository
{
    /** No row exists for the exact (store_id, UNICID) scope. */
    const STATE_MISSING = 'missing';

    /** Row exists but stored JSON is not a usable snapshot (never replaces known-good). */
    const STATE_CORRUPT = 'corrupt';

    /** now < expires_at — local-only usable without any CP GET. */
    const STATE_FRESH = 'fresh';

    /** expires_at <= now <= expires_at + LKG — presentation-only LKG candidate. */
    const STATE_STALE = 'stale';

    /** now > expires_at + LKG — purge-eligible, never usable. */
    const STATE_TOO_OLD = 'too_old';

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
     * Exact-scope lifecycle inspection used by the shared shop configuration resolver.
     *
     * Returns the decoded snapshot together with the frozen lifecycle state. Stale rows are
     * NOT storefront-usable by themselves: callers must run the presentation/submission
     * decision algorithm (structural validation, LKG eligibility, refresh attempt).
     *
     * states: missing | corrupt | fresh | stale | too_old
     *
     * @param int $storeId
     * @param string $unicid
     * @return array{
     *   state: string,
     *   row_present: bool,
     *   shop_data: array<string, mixed>|null,
     *   fetched_at: string,
     *   expires_at: string,
     *   usable_until: string,
     *   stale_seconds: int,
     *   lkg_eligible: bool
     * }
     */
    public function inspectScope($storeId, $unicid)
    {
        MtUniCreditStoreScope::requireStoreId($storeId);
        $unicid = trim((string) $unicid);
        if ($unicid === '') {
            return $this->inspection(MtUniCreditShopCacheRepository::STATE_MISSING);
        }

        $table = $this->tableName();
        $result = $this->db->query(
            "SELECT `shop_data`, `fetched_at`, `expires_at` FROM `{$table}`"
                . " WHERE `store_id` = " . (int) $storeId
                . " AND `unicid` = '" . $this->db->escape($unicid) . "'"
                . " LIMIT 1"
        );

        if (!is_object($result) || !isset($result->num_rows) || (int) $result->num_rows !== 1) {
            return $this->inspection(MtUniCreditShopCacheRepository::STATE_MISSING);
        }

        $fetchedAt = isset($result->row['fetched_at']) ? (string) $result->row['fetched_at'] : '';
        $expiresAt = isset($result->row['expires_at']) ? (string) $result->row['expires_at'] : '';
        $now = $this->clock->now();
        $expiresTimestamp = $this->parseUtc($expiresAt);
        $usableUntilTimestamp = $expiresTimestamp !== null
            ? $expiresTimestamp + (int) MtUniCreditSecurityConstants::SHOP_CACHE_LKG_SECONDS
            : null;

        if (!isset($result->row['shop_data']) || !is_string($result->row['shop_data'])) {
            return $this->inspection(
                MtUniCreditShopCacheRepository::STATE_CORRUPT,
                true,
                $fetchedAt,
                $expiresAt,
                $usableUntilTimestamp
            );
        }

        $decoded = json_decode($result->row['shop_data'], true);
        if (!is_array($decoded) || $decoded === array()) {
            return $this->inspection(
                MtUniCreditShopCacheRepository::STATE_CORRUPT,
                true,
                $fetchedAt,
                $expiresAt,
                $usableUntilTimestamp
            );
        }

        if ($expiresTimestamp === null) {
            return $this->inspection(
                MtUniCreditShopCacheRepository::STATE_CORRUPT,
                true,
                $fetchedAt,
                $expiresAt,
                null
            );
        }

        if ($now < $expiresTimestamp) {
            return $this->inspection(
                MtUniCreditShopCacheRepository::STATE_FRESH,
                true,
                $fetchedAt,
                $expiresAt,
                $usableUntilTimestamp,
                $decoded,
                0
            );
        }

        // Exact usable_until boundary is retained: now <= expires_at + LKG is still eligible.
        if ($now <= $usableUntilTimestamp) {
            return $this->inspection(
                MtUniCreditShopCacheRepository::STATE_STALE,
                true,
                $fetchedAt,
                $expiresAt,
                $usableUntilTimestamp,
                $decoded,
                (int) ($now - $expiresTimestamp)
            );
        }

        return $this->inspection(
            MtUniCreditShopCacheRepository::STATE_TOO_OLD,
            true,
            $fetchedAt,
            $expiresAt,
            $usableUntilTimestamp,
            $decoded,
            (int) ($now - $expiresTimestamp)
        );
    }

    /**
     * Frozen purge boundary — a row is purge-eligible only strictly after usable_until.
     *
     * @param int $timestamp
     * @return string UTC datetime above which rows are purge-eligible
     */
    private function purgeEligibleCutoffUtc($timestamp)
    {
        return $this->clock->formatUtc(
            (int) $timestamp - (int) MtUniCreditSecurityConstants::SHOP_CACHE_LKG_SECONDS
        );
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
        // Ordinary cleanup retains the whole usable_until window: delete only when
        // expires_at < now - LKG (i.e. strictly after expires_at + LKG).
        $cutoff = $this->purgeEligibleCutoffUtc($this->clock->now());
        $this->db->query(
            "DELETE FROM `{$table}` WHERE `expires_at` < '" . $this->db->escape($cutoff) . "' LIMIT " . (int) $limit
        );

        return $this->db->countAffected();
    }

    /**
     * @param string $state
     * @param bool $rowPresent
     * @param string $fetchedAt
     * @param string $expiresAt
     * @param int|null $usableUntilTimestamp
     * @param array<string, mixed>|null $shopData
     * @param int $staleSeconds
     * @return array<string, mixed>
     */
    private function inspection(
        $state,
        $rowPresent = false,
        $fetchedAt = '',
        $expiresAt = '',
        $usableUntilTimestamp = null,
        $shopData = null,
        $staleSeconds = 0
    ) {
        $state = (string) $state;

        return array(
            'state' => $state,
            'row_present' => (bool) $rowPresent,
            'shop_data' => $state === MtUniCreditShopCacheRepository::STATE_FRESH
                || $state === MtUniCreditShopCacheRepository::STATE_STALE
                ? $shopData
                : null,
            'fetched_at' => (string) $fetchedAt,
            'expires_at' => (string) $expiresAt,
            'usable_until' => $usableUntilTimestamp !== null
                ? $this->clock->formatUtc($usableUntilTimestamp)
                : '',
            'stale_seconds' => max(0, (int) $staleSeconds),
            'lkg_eligible' => $state === MtUniCreditShopCacheRepository::STATE_STALE,
        );
    }

    /**
     * @param string $utc
     * @return int|null
     */
    private function parseUtc($utc)
    {
        $utc = trim((string) $utc);
        if ($utc === '') {
            return null;
        }

        $parsed = strtotime($utc . ' UTC');
        if (!is_int($parsed)) {
            return null;
        }

        return $parsed;
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
