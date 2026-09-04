<?php

/**
 * Idempotent persistence schema installer — no DROP on uninstall.
 *
 * Uninstall policy: Module/Payment uninstall removes oc_setting rows only.
 * Extension-owned tables (nonces, locks, shop cache, future financing evidence) are preserved.
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
        self::installAll($db);
    }

    /**
     * @param MtUniCreditDbAdapter $db
     * @return void
     */
    public static function installAll(MtUniCreditDbAdapter $db)
    {
        $installer = new self($db);
        $installer->installAllTables();
    }

    /**
     * @return void
     */
    public function installAllTables()
    {
        foreach (self::createAllTableStatements($this->db->getPrefix()) as $sql) {
            $this->db->query($sql);
        }
        $this->ensurePhase9Columns();
        $this->ensurePhase10Columns();
    }

    /**
     * Add SmartUCF lifecycle columns when missing (fresh CREATE already includes them).
     *
     * @return void
     */
    public function ensurePhase9Columns()
    {
        $this->ensureAlterColumns(self::createPhase9AlterStatements($this->db->getPrefix()));
    }

    /**
     * Add Process 2 lifecycle columns when missing.
     *
     * @return void
     */
    public function ensurePhase10Columns()
    {
        $this->ensureAlterColumns(self::createPhase10AlterStatements($this->db->getPrefix()));
    }

    /**
     * @param array<int, string> $statements
     * @return void
     */
    private function ensureAlterColumns(array $statements)
    {
        $table = $this->db->getPrefix() . MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT;
        $existing = array();
        try {
            $result = $this->db->query('SHOW COLUMNS FROM `' . $table . '`');
            if (is_object($result) && isset($result->rows) && is_array($result->rows)) {
                foreach ($result->rows as $row) {
                    if (isset($row['Field'])) {
                        $existing[(string) $row['Field']] = true;
                    }
                }
            }
        } catch (Exception $exception) {
            $existing = array();
        }

        foreach ($statements as $sql) {
            if (preg_match("/ADD COLUMN `([^`]+)`/", $sql, $match)) {
                if (isset($existing[$match[1]])) {
                    continue;
                }
            }
            try {
                $this->db->query($sql);
            } catch (Exception $ignored) {
            }
        }
    }

    /**
     * @return void
     */
    public function installPhase2Tables()
    {
        foreach (self::createPhase2TableStatements($this->db->getPrefix()) as $sql) {
            $this->db->query($sql);
        }
    }

    /**
     * @param string $prefix
     * @return array<int, string>
     */
    public static function createTableStatements($prefix)
    {
        return self::createPhase2TableStatements($prefix);
    }

    /**
     * @param string $prefix
     * @return array<int, string>
     */
    public static function createAllTableStatements($prefix)
    {
        return array_merge(
            self::createPhase2TableStatements($prefix),
            self::createPhase3TableStatements($prefix),
            self::createPhase6TableStatements($prefix),
            self::createPhase7TableStatements($prefix)
        );
    }

    /**
     * Idempotent Phase 9 column upgrades for financing_attempt.
     *
     * @param string $prefix
     * @return array<int, string>
     */
    public static function createPhase9AlterStatements($prefix)
    {
        $financingAttempt = $prefix . MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT;

        return array(
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `smartucf_state` VARCHAR(32) NOT NULL DEFAULT 'not_started'",
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `smartucf_session_id` VARCHAR(128) NULL",
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `smartucf_redirect_url` VARCHAR(768) NULL",
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `smartucf_http_code` INT NULL",
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `smartucf_error_class` VARCHAR(64) NULL",
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `smartucf_retryable` TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `smartucf_claimed_at` DATETIME NULL",
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `smartucf_completed_at` DATETIME NULL",
        );
    }

    /**
     * Idempotent Phase 10 column upgrades for financing_attempt.
     *
     * @param string $prefix
     * @return array<int, string>
     */
    public static function createPhase10AlterStatements($prefix)
    {
        $financingAttempt = $prefix . MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT;

        return array(
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `process2_state` VARCHAR(32) NOT NULL DEFAULT 'not_started'",
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `process2_sensitive_enc` MEDIUMTEXT NULL",
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `process2_mail_sent` TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `leasing_presentation_json` MEDIUMTEXT NULL",
        );
    }

    /**
     * @param string $prefix
     * @return array<int, string>
     */
    public static function createPhase2TableStatements($prefix)
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

    /**
     * @param string $prefix
     * @return array<int, string>
     */
    public static function createPhase3TableStatements($prefix)
    {
        $shopCache = $prefix . MtUniCreditPersistenceTableNames::SHOP_CACHE;

        return array(
            "CREATE TABLE IF NOT EXISTS `{$shopCache}` (
                `shop_cache_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_id` INT UNSIGNED NOT NULL,
                `unicid` VARCHAR(64) NOT NULL,
                `shop_data` LONGTEXT NOT NULL,
                `fetched_at` DATETIME NOT NULL,
                `expires_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`shop_cache_id`),
                UNIQUE KEY `uniq_mt_uni_credit_shop_cache_store_unicid` (`store_id`, `unicid`),
                KEY `idx_mt_uni_credit_shop_cache_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    /**
     * @param string $prefix
     * @return array<int, string>
     */
    public static function createPhase6TableStatements($prefix)
    {
        $orderBankStatus = $prefix . MtUniCreditPersistenceTableNames::ORDER_BANK_STATUS;
        $diagnosticDebugLog = $prefix . MtUniCreditPersistenceTableNames::DIAGNOSTIC_DEBUG_LOG;

        return array(
            "CREATE TABLE IF NOT EXISTS `{$orderBankStatus}` (
                `order_bank_status_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_id` INT UNSIGNED NOT NULL,
                `order_id` INT UNSIGNED NOT NULL,
                `order_reference` VARCHAR(64) NOT NULL,
                `status_id` VARCHAR(255) NOT NULL,
                `status_label` VARCHAR(255) NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`order_bank_status_id`),
                UNIQUE KEY `uniq_mt_uni_credit_order_bank_store_order` (`store_id`, `order_id`),
                KEY `idx_mt_uni_credit_order_bank_reference` (`order_reference`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `{$diagnosticDebugLog}` (
                `diagnostic_debug_log_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_id` INT UNSIGNED NOT NULL,
                `order_id` INT UNSIGNED NOT NULL,
                `entry_point` VARCHAR(16) NOT NULL DEFAULT '',
                `event_code` VARCHAR(64) NOT NULL DEFAULT '',
                `http_status` INT NULL,
                `summary_json` LONGTEXT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`diagnostic_debug_log_id`),
                KEY `idx_mt_uni_credit_diag_store_order` (`store_id`, `order_id`),
                KEY `idx_mt_uni_credit_diag_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    /**
     * @param string $prefix
     * @return array<int, string>
     */
    public static function createPhase7TableStatements($prefix)
    {
        $financingAttempt = $prefix . MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT;

        return array(
            "CREATE TABLE IF NOT EXISTS `{$financingAttempt}` (
                `attempt_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_id` INT UNSIGNED NOT NULL,
                `entry_point` VARCHAR(16) NOT NULL,
                `operation_key_hash` CHAR(64) NOT NULL,
                `selection_hash` CHAR(64) NOT NULL,
                `request_fingerprint` CHAR(64) NOT NULL DEFAULT '',
                `state` VARCHAR(32) NOT NULL,
                `order_id` INT UNSIGNED NULL,
                `unicid` VARCHAR(64) NOT NULL DEFAULT '',
                `control_panel_order_id` BIGINT UNSIGNED NULL,
                `cp_payload` LONGTEXT NULL,
                `last_error_class` VARCHAR(64) NULL,
                `smartucf_state` VARCHAR(32) NOT NULL DEFAULT 'not_started',
                `smartucf_session_id` VARCHAR(128) NULL,
                `smartucf_redirect_url` VARCHAR(768) NULL,
                `smartucf_http_code` INT NULL,
                `smartucf_error_class` VARCHAR(64) NULL,
                `smartucf_retryable` TINYINT(1) NOT NULL DEFAULT 0,
                `smartucf_claimed_at` DATETIME NULL,
                `smartucf_completed_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`attempt_id`),
                UNIQUE KEY `uniq_mt_uni_credit_store_order` (`store_id`, `order_id`),
                KEY `idx_mt_uni_credit_attempt_operation` (`store_id`, `entry_point`, `operation_key_hash`, `state`),
                KEY `idx_mt_uni_credit_attempt_state_updated` (`state`, `updated_at`),
                KEY `idx_mt_uni_credit_attempt_smartucf_state` (`smartucf_state`, `updated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }
}
