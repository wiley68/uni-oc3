<?php

/**
 * Validates opaque SHA-256 hex hashes persisted by repositories.
 */
final class MtUniCreditHashValidator
{
    /**
     * @param string $value
     * @return bool
     */
    public static function isSha256Hex($value)
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/', (string) $value);
    }

    /**
     * @param string $value
     * @param string $label
     * @return string
     */
    public static function requireSha256Hex($value, $label)
    {
        if (!self::isSha256Hex($value)) {
            throw new MtUniCreditPersistenceValidationException(
                $label . ' must be a 64-character lowercase SHA-256 hex string.'
            );
        }

        return $value;
    }
}
