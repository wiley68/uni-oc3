<?php

/**
 * Canonical required-column / index inventory for module persistence tables.
 * Consumed by MtUniCreditPersistenceSchema completion + verification (AUD-030).
 */
final class MtUniCreditPersistenceSchemaInventory
{
    /**
     * Logical table name (no prefix) => columns + indexes.
     *
     * @return array<string, array{columns: array<string, string>, indexes: array<int, array{name: string, unique: bool, columns: array<int, string>}>}>
     */
    public static function tables()
    {
        return array(
            MtUniCreditPersistenceTableNames::API_NONCE => array(
                'columns' => array(
                    'api_nonce_id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'store_id' => 'INT UNSIGNED NOT NULL',
                    'unicid' => 'VARCHAR(64) NOT NULL',
                    'nonce_hash' => 'CHAR(64) NOT NULL',
                    'used_at' => 'DATETIME NOT NULL',
                    'expires_at' => 'DATETIME NOT NULL',
                ),
                'indexes' => array(
                    array('name' => 'PRIMARY', 'unique' => true, 'columns' => array('api_nonce_id')),
                    array(
                        'name' => 'uniq_mt_uni_credit_api_nonce',
                        'unique' => true,
                        'columns' => array('store_id', 'unicid', 'nonce_hash'),
                    ),
                    array(
                        'name' => 'idx_mt_uni_credit_api_nonce_expires',
                        'unique' => false,
                        'columns' => array('expires_at'),
                    ),
                ),
            ),
            MtUniCreditPersistenceTableNames::OPERATION_LOCK => array(
                'columns' => array(
                    'operation_lock_id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'store_id' => 'INT UNSIGNED NOT NULL',
                    'entry_point' => 'VARCHAR(16) NOT NULL',
                    'operation_key_hash' => 'CHAR(64) NOT NULL',
                    'owner_token' => 'CHAR(32) NOT NULL',
                    'expires_at' => 'DATETIME NOT NULL',
                    'created_at' => 'DATETIME NOT NULL',
                    'updated_at' => 'DATETIME NOT NULL',
                ),
                'indexes' => array(
                    array('name' => 'PRIMARY', 'unique' => true, 'columns' => array('operation_lock_id')),
                    array(
                        'name' => 'uniq_mt_uni_credit_operation_lock',
                        'unique' => true,
                        'columns' => array('store_id', 'entry_point', 'operation_key_hash'),
                    ),
                    array(
                        'name' => 'idx_mt_uni_credit_operation_lock_expires',
                        'unique' => false,
                        'columns' => array('expires_at'),
                    ),
                ),
            ),
            MtUniCreditPersistenceTableNames::SHOP_CACHE => array(
                'columns' => array(
                    'shop_cache_id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'store_id' => 'INT UNSIGNED NOT NULL',
                    'unicid' => 'VARCHAR(64) NOT NULL',
                    'shop_data' => 'LONGTEXT NOT NULL',
                    'fetched_at' => 'DATETIME NOT NULL',
                    'expires_at' => 'DATETIME NOT NULL',
                    'created_at' => 'DATETIME NOT NULL',
                    'updated_at' => 'DATETIME NOT NULL',
                ),
                'indexes' => array(
                    array('name' => 'PRIMARY', 'unique' => true, 'columns' => array('shop_cache_id')),
                    array(
                        'name' => 'uniq_mt_uni_credit_shop_cache_store_unicid',
                        'unique' => true,
                        'columns' => array('store_id', 'unicid'),
                    ),
                    array(
                        'name' => 'idx_mt_uni_credit_shop_cache_expires',
                        'unique' => false,
                        'columns' => array('expires_at'),
                    ),
                ),
            ),
            MtUniCreditPersistenceTableNames::ORDER_BANK_STATUS => array(
                'columns' => array(
                    'order_bank_status_id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'store_id' => 'INT UNSIGNED NOT NULL',
                    'order_id' => 'VARCHAR(13) NOT NULL',
                    'order_reference' => 'VARCHAR(64) NOT NULL',
                    'status_id' => 'VARCHAR(255) NOT NULL',
                    'status_label' => 'VARCHAR(255) NOT NULL',
                    'updated_at' => 'DATETIME NOT NULL',
                ),
                'indexes' => array(
                    array('name' => 'PRIMARY', 'unique' => true, 'columns' => array('order_bank_status_id')),
                    array(
                        'name' => 'uniq_mt_uni_credit_order_bank_store_order',
                        'unique' => true,
                        'columns' => array('store_id', 'order_id'),
                    ),
                    array(
                        'name' => 'idx_mt_uni_credit_order_bank_reference',
                        'unique' => false,
                        'columns' => array('order_reference'),
                    ),
                ),
            ),
            MtUniCreditPersistenceTableNames::DIAGNOSTIC_DEBUG_LOG => array(
                'columns' => array(
                    'diagnostic_debug_log_id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'store_id' => 'INT UNSIGNED NOT NULL',
                    'order_id' => 'VARCHAR(13) NOT NULL',
                    'entry_point' => "VARCHAR(16) NOT NULL DEFAULT ''",
                    'event_code' => "VARCHAR(64) NOT NULL DEFAULT ''",
                    'http_status' => 'INT NULL',
                    'summary_json' => 'LONGTEXT NULL',
                    'created_at' => 'DATETIME NOT NULL',
                ),
                'indexes' => array(
                    array('name' => 'PRIMARY', 'unique' => true, 'columns' => array('diagnostic_debug_log_id')),
                    array(
                        'name' => 'idx_mt_uni_credit_diag_store_order',
                        'unique' => false,
                        'columns' => array('store_id', 'order_id'),
                    ),
                    array(
                        'name' => 'idx_mt_uni_credit_diag_created',
                        'unique' => false,
                        'columns' => array('created_at'),
                    ),
                ),
            ),
            MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT => array(
                'columns' => array(
                    'attempt_id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'store_id' => 'INT UNSIGNED NOT NULL',
                    'entry_point' => 'VARCHAR(16) NOT NULL',
                    'operation_key_hash' => 'CHAR(64) NOT NULL',
                    'selection_hash' => 'CHAR(64) NOT NULL',
                    'request_fingerprint' => "CHAR(64) NOT NULL DEFAULT ''",
                    'state' => 'VARCHAR(32) NOT NULL',
                    'order_id' => 'VARCHAR(13) NULL',
                    'unicid' => "VARCHAR(64) NOT NULL DEFAULT ''",
                    'control_panel_order_id' => 'BIGINT UNSIGNED NULL',
                    'cp_payload' => 'LONGTEXT NULL',
                    'application_snapshot_json' => 'LONGTEXT NULL',
                    'application_snapshot_hash' => 'CHAR(64) NULL',
                    'last_error_class' => 'VARCHAR(64) NULL',
                    'smartucf_state' => "VARCHAR(32) NOT NULL DEFAULT 'not_started'",
                    'smartucf_session_id' => 'VARCHAR(128) NULL',
                    'smartucf_redirect_url' => 'VARCHAR(768) NULL',
                    'smartucf_http_code' => 'INT NULL',
                    'smartucf_error_class' => 'VARCHAR(64) NULL',
                    'smartucf_retryable' => 'TINYINT(1) NOT NULL DEFAULT 0',
                    'smartucf_claimed_at' => 'DATETIME NULL',
                    'smartucf_completed_at' => 'DATETIME NULL',
                    'cart_clear_state' => "VARCHAR(32) NOT NULL DEFAULT 'not_applied'",
                    'cart_clear_claimed_at' => 'DATETIME NULL',
                    'cart_clear_applied_at' => 'DATETIME NULL',
                    'process2_state' => "VARCHAR(32) NOT NULL DEFAULT 'not_started'",
                    'process2_sensitive_enc' => 'MEDIUMTEXT NULL',
                    'process2_mail_sent' => 'TINYINT(1) NOT NULL DEFAULT 0',
                    'leasing_presentation_json' => 'MEDIUMTEXT NULL',
                    'process2_claimed_at' => 'DATETIME NULL',
                    'process2_claim_owner' => 'CHAR(32) NULL',
                    'native_finalize_state' => "VARCHAR(32) NOT NULL DEFAULT 'not_started'",
                    'native_finalize_claim_owner' => 'CHAR(32) NULL',
                    'native_finalize_claimed_at' => 'DATETIME NULL',
                    'native_finalize_applied_at' => 'DATETIME NULL',
                    'native_finalize_target_status' => 'INT UNSIGNED NULL',
                    'native_finalize_outcome' => 'VARCHAR(64) NULL',
                    'process2_sensitive_created_at' => 'DATETIME NULL',
                    'leasing_presentation_created_at' => 'DATETIME NULL',
                    'cp_status_sync_state' => "VARCHAR(32) NOT NULL DEFAULT 'not_needed'",
                    'cp_status_sync_status_id' => 'VARCHAR(255) NULL',
                    'cp_status_sync_status' => 'VARCHAR(255) NULL',
                    'cp_status_sync_error_class' => 'VARCHAR(64) NULL',
                    'cp_status_sync_updated_at' => 'DATETIME NULL',
                    'created_at' => 'DATETIME NOT NULL',
                    'updated_at' => 'DATETIME NOT NULL',
                ),
                'indexes' => array(
                    array('name' => 'PRIMARY', 'unique' => true, 'columns' => array('attempt_id')),
                    array(
                        'name' => 'uniq_mt_uni_credit_store_order',
                        'unique' => true,
                        'columns' => array('store_id', 'order_id'),
                    ),
                    array(
                        'name' => 'idx_mt_uni_credit_attempt_operation',
                        'unique' => false,
                        'columns' => array('store_id', 'entry_point', 'operation_key_hash', 'state'),
                    ),
                    array(
                        'name' => 'idx_mt_uni_credit_attempt_state_updated',
                        'unique' => false,
                        'columns' => array('state', 'updated_at'),
                    ),
                    array(
                        'name' => 'idx_mt_uni_credit_attempt_smartucf_state',
                        'unique' => false,
                        'columns' => array('smartucf_state', 'updated_at'),
                    ),
                    array(
                        'name' => 'idx_mt_uni_credit_attempt_cart_clear',
                        'unique' => false,
                        'columns' => array('cart_clear_state', 'cart_clear_claimed_at'),
                    ),
                    array(
                        'name' => 'idx_mt_uni_credit_attempt_native_finalize',
                        'unique' => false,
                        'columns' => array('native_finalize_state', 'native_finalize_claimed_at'),
                    ),
                    array(
                        'name' => 'idx_mt_uni_credit_attempt_p2_sensitive_created',
                        'unique' => false,
                        'columns' => array('process2_sensitive_created_at'),
                    ),
                    array(
                        'name' => 'idx_mt_uni_credit_attempt_presentation_created',
                        'unique' => false,
                        'columns' => array('leasing_presentation_created_at'),
                    ),
                    array(
                        'name' => 'idx_mt_uni_credit_attempt_cp_status_sync',
                        'unique' => false,
                        'columns' => array('cp_status_sync_state'),
                    ),
                    array(
                        'name' => 'idx_mt_uni_credit_attempt_store_order_unicid',
                        'unique' => false,
                        'columns' => array('store_id', 'order_id', 'unicid'),
                    ),
                ),
            ),

            MtUniCreditPersistenceTableNames::OPERATION_ORDER_CLAIM => array(
                'columns' => array(
                    'operation_order_claim_id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'store_id' => 'INT UNSIGNED NOT NULL',
                    'entry_point' => 'VARCHAR(16) NOT NULL',
                    'operation_key_hash' => 'CHAR(64) NOT NULL',
                    'state' => 'VARCHAR(32) NOT NULL',
                    'order_id' => 'VARCHAR(13) NULL',
                    'claim_owner_token' => 'CHAR(32) NOT NULL',
                    'created_at' => 'DATETIME NOT NULL',
                    'updated_at' => 'DATETIME NOT NULL',
                ),
                'indexes' => array(
                    array('name' => 'PRIMARY', 'unique' => true, 'columns' => array('operation_order_claim_id')),
                    array(
                        'name' => 'uniq_mt_uni_credit_operation_order_claim',
                        'unique' => true,
                        'columns' => array('store_id', 'entry_point', 'operation_key_hash'),
                    ),
                    array(
                        'name' => 'idx_mt_uni_credit_operation_order_claim_order',
                        'unique' => false,
                        'columns' => array('store_id', 'order_id'),
                    ),
                    array(
                        'name' => 'idx_mt_uni_credit_operation_order_claim_state',
                        'unique' => false,
                        'columns' => array('state', 'updated_at'),
                    ),
                ),
            ),
            MtUniCreditPersistenceTableNames::PROCESS2_MAIL_RECIPIENT => array(
                'columns' => array(
                    'process2_mail_recipient_id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'attempt_id' => 'INT UNSIGNED NOT NULL',
                    'audience' => 'VARCHAR(16) NOT NULL',
                    'recipient_key' => 'VARCHAR(255) NOT NULL',
                    'recipient_email' => 'VARCHAR(255) NOT NULL',
                    'state' => "VARCHAR(32) NOT NULL DEFAULT 'pending'",
                    'claim_owner_token' => 'CHAR(32) NULL',
                    'claimed_at' => 'DATETIME NULL',
                    'created_at' => 'DATETIME NOT NULL',
                    'updated_at' => 'DATETIME NOT NULL',
                ),
                'indexes' => array(
                    array('name' => 'PRIMARY', 'unique' => true, 'columns' => array('process2_mail_recipient_id')),
                    array(
                        'name' => 'uniq_mt_uni_credit_p2_mail_recipient',
                        'unique' => true,
                        'columns' => array('attempt_id', 'recipient_key'),
                    ),
                    array(
                        'name' => 'idx_mt_uni_credit_p2_mail_recipient_state',
                        'unique' => false,
                        'columns' => array('state', 'claimed_at'),
                    ),
                ),
            ),
        );
    }
}
