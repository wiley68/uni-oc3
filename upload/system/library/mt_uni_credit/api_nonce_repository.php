<?php

/**
 * Replay-protection nonce claims — stores sha256(nonce), never raw nonce.
 */
final class MtUniCreditApiNonceRepository
{
    /** @var MtUniCreditDbAdapter */
    private $db;

    /** @var MtUniCreditPersistenceClock */
    private $clock;

    /**
     * @param MtUniCreditDbAdapter $db
     */
    public function __construct(MtUniCreditDbAdapter $db)
    {
        $this->db = $db;
        if (func_num_args() > 1 && func_get_arg(1) instanceof MtUniCreditPersistenceClock) {
            $this->clock = func_get_arg(1);
        } else {
            $this->clock = new MtUniCreditPersistenceClock();
        }
    }

    /**
     * Atomic claim-once. Returns true only when this request inserted the nonce row.
     *
     * @param int $storeId
     * @param string $unicid
     * @param string $nonce 64-char lowercase hex
     * @return bool
     */
    public function claim($storeId, $unicid, $nonce)
    {
        MtUniCreditStoreScope::requireStoreId($storeId);
        $unicid = trim($unicid);
        if ($unicid === '' || !self::isValidNonceFormat($nonce)) {
            throw new MtUniCreditPersistenceValidationException(
                'Nonce claim requires store scope, UNICID and a 64-char lowercase hex nonce.'
            );
        }

        $now = $this->clock->now();
        $nonceHash = hash('sha256', $nonce);
        $usedAt = $this->clock->formatUtc($now);
        $expiresAt = $this->clock->formatUtc($now + MtUniCreditSecurityConstants::NONCE_RETENTION_SECONDS);
        $table = $this->tableName();

        try {
            $this->db->query(
                "INSERT INTO `{$table}` (`store_id`, `unicid`, `nonce_hash`, `used_at`, `expires_at`)"
                    . " VALUES ("
                    . (int) $storeId . ","
                    . " '" . $this->db->escape($unicid) . "',"
                    . " '" . $this->db->escape($nonceHash) . "',"
                    . " '" . $this->db->escape($usedAt) . "',"
                    . " '" . $this->db->escape($expiresAt) . "'"
                    . ")"
            );
        } catch (Exception $exception) {
            if (self::isDuplicateKeyError($exception)) {
                return false;
            }

            throw new MtUniCreditPersistenceException('Nonce claim failed.', 0, $exception);
        }

        if ($this->db->countAffected() === 1) {
            $this->pruneExpiredIfDue();

            return true;
        }

        return false;
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
     * @return void
     */
    private function pruneExpiredIfDue()
    {
        $this->deleteExpiredBatch(10);
    }

    /**
     * @param string $nonce
     * @return string
     */
    public static function hashNonce($nonce)
    {
        return hash('sha256', $nonce);
    }

    /**
     * @param string $nonce
     * @return bool
     */
    public static function isValidNonceFormat($nonce)
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/', (string) $nonce);
    }

    /**
     * @return string
     */
    private function tableName()
    {
        return $this->db->getPrefix() . MtUniCreditPersistenceTableNames::API_NONCE;
    }

    /**
     * @param Exception $exception
     * @return bool
     */
    private static function isDuplicateKeyError(Exception $exception)
    {
        $message = strtolower($exception->getMessage());

        return strpos($message, 'duplicate') !== false
            || strpos($message, '1062') !== false
            || strpos($message, 'unique') !== false;
    }
}
