<?php

/**
 * Frozen security/persistence timing constants (Phase 0 contracts).
 *
 * @see docs/CONTRACTS.md SEC-HMAC-001
 */
final class MtUniCreditSecurityConstants
{
    const NONCE_HEX_LENGTH = 64;

    const NONCE_RETENTION_SECONDS = 900;

    const OPERATION_LOCK_TTL_SECONDS = 45;

    const LOCK_OWNER_TOKEN_BYTES = 16;

    const HASH_HEX_LENGTH = 64;

    const CLEANUP_DEFAULT_BATCH_SIZE = 100;

    const SHOP_CACHE_TTL_SECONDS = 86400;

    /**
     * Presentation-only last-known-good (LKG) window AFTER expires_at.
     *
     * fresh_until = expires_at
     * usable_until = expires_at + SHOP_CACHE_LKG_SECONDS (exact boundary retained)
     *
     * Presentation surfaces may use LKG only when the current refresh attempt failed
     * TRANSIENTLY. Submission surfaces never use LKG.
     *
     * @see docs/CONTRACTS.md CACHE-003
     */
    const SHOP_CACHE_LKG_SECONDS = 21600;

    /**
     * Bounded wait for the single-flight shop configuration refresh advisory lock.
     * Owner: performs at most one remote GET /shop for the exact (store_id, UNICID) scope.
     * Same identity serializes push/manual persistence (REM-OC3-CACHE-001-R1).
     */
    const SHOP_CONFIGURATION_REFRESH_LOCK_TIMEOUT_SECONDS = 5;

    /**
     * Bounded contender wait (milliseconds) before re-reading the exact scope after
     * another connection is already refreshing it. Storefront requests must not block.
     */
    const SHOP_CONFIGURATION_CONTENDER_WAIT_MILLISECONDS = 250;

    /**
     * Contender re-read interval (milliseconds) inside the bounded wait.
     */
    const SHOP_CONFIGURATION_CONTENDER_POLL_MILLISECONDS = 25;
}
