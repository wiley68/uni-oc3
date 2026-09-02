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
}
