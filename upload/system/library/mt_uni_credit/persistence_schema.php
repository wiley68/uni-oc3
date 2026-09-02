<?php

/**
 * Idempotent Phase 2 schema installer — no DROP on uninstall.
 *
 * Uninstall policy: Module/Payment uninstall removes oc_setting rows only.
 * Extension-owned tables (nonces, locks, future financing evidence) are preserved.
 */
final class MtUniCreditPersistenceSchema
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
     * @param MtUniCreditDbAdapter $db
     * @return void
     */
    public static function install(MtUniCreditDbAdapter $db)
    {
        $installer = new self($db);
        $installer->installPhase2Tables();
    }

    /**
     * @return void
     */
    public function installPhase2Tables()
    {
        foreach (self::createTableStatements($this->db->getPrefix()) as $sql) {
            $this->db->query($sql);
        }
    }

    /**
     * @param string $prefix
     * @return array<int, string>
     */
    public static function createTableStatements($prefix)
    {
        $apiNonce = $prefix . MtUniCreditPersistenceTableNames::API_NONCE;
        $operationLock = $prefix . MtUniCreditPersistenceTableNames::OPERATION_LOCK;

        return array(
            "CREATE TABLE IF NOT EXISTS `{$apiNonce}` (
                `api_nonce_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_id` INT UNSIGNED NOT NULL,
                `unicid` VARCHAR(64) NOT NULL,
                `nonce_hash` CHAR(64) NOT NULL,
                `used_at` DATETIME NOT NULL,
                `expires_at` DATETIME NOT NULL,
                PRIMARY KEY (`api_nonce_id`),
                UNIQUE KEY `uniq_mt_uni_credit_api_nonce` (`store_id`, `unicid`, `nonce_hash`),
                KEY `idx_mt_uni_credit_api_nonce_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `{$operationLock}` (
                `operation_lock_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_id` INT UNSIGNED NOT NULL,
                `entry_point` VARCHAR(16) NOT NULL,
                `operation_key_hash` CHAR(64) NOT NULL,
                `owner_token` CHAR(32) NOT NULL,
                `expires_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`operation_lock_id`),
                UNIQUE KEY `uniq_mt_uni_credit_operation_lock` (`store_id`, `entry_point`, `operation_key_hash`),
                KEY `idx_mt_uni_credit_operation_lock_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }
}
