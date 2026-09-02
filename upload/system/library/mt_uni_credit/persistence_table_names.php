<?php

/**
 * Module persistence table names (without DB_PREFIX).
 */
final class MtUniCreditPersistenceTableNames
{
    const API_NONCE = 'mt_uni_credit_api_nonce';

    const OPERATION_LOCK = 'mt_uni_credit_operation_lock';

    const SHOP_CACHE = 'mt_uni_credit_shop_cache';

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
}
