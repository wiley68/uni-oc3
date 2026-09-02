<?php

/**
 * Cryptographically random operation lock owner tokens (32 lowercase hex).
 */
final class MtUniCreditLockOwnerTokenGenerator
{
    /**
     * @return string
     */
    public static function generate()
    {
        return bin2hex(random_bytes(MtUniCreditSecurityConstants::LOCK_OWNER_TOKEN_BYTES));
    }

    /**
     * @param string $token
     * @return bool
     */
    public static function isValidFormat($token)
    {
        return (bool) preg_match('/^[a-f0-9]{32}$/', (string) $token);
    }
}
