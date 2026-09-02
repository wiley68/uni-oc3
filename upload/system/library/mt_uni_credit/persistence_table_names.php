<?php

/**
 * Module persistence table names (without DB_PREFIX).
 */
final class MtUniCreditPersistenceTableNames
{
    const API_NONCE = 'mt_uni_credit_api_nonce';

    const OPERATION_LOCK = 'mt_uni_credit_operation_lock';

    const SHOP_CACHE = 'mt_uni_credit_shop_cache';

    const ORDER_BANK_STATUS = 'mt_uni_credit_order_bank_status';

    const DIAGNOSTIC_DEBUG_LOG = 'mt_uni_credit_diagnostic_debug_log';

    const FINANCING_ATTEMPT = 'mt_uni_credit_financing_attempt';

    /**
     * Phase 2 foundational tables only.
     *
     * @return array<int, string>
     */
    public static function phase2Tables()
    {
        return array(
            self::API_NONCE,
            self::OPERATION_LOCK,
        );
    }

    /**
     * Phase 3 shop configuration cache table.
     *
     * @return array<int, string>
     */
    public static function phase3Tables()
    {
        return array(
            self::SHOP_CACHE,
        );
    }

    /**
     * Phase 6 inbound bridge tables.
     *
     * @return array<int, string>
     */
    public static function phase6Tables()
    {
        return array(
            self::ORDER_BANK_STATUS,
            self::DIAGNOSTIC_DEBUG_LOG,
        );
    }

    /**
     * Phase 7 financing attempt table.
     *
     * @return array<int, string>
     */
    public static function phase7Tables()
    {
        return array(
            self::FINANCING_ATTEMPT,
        );
    }

    /**
     * @return array<int, string>
     */
    public static function allPersistenceTables()
    {
        return array_merge(
            self::phase2Tables(),
            self::phase3Tables(),
            self::phase6Tables(),
            self::phase7Tables()
        );
    }
}
