<?php

/**
 * Session CSRF for Product/Cart storefront AJAX (guest + logged-in).
 */
final class MtUniCreditStorefrontCsrf
{
    const SESSION_KEY = 'mt_uni_credit_storefront_csrf';

    /**
     * @param array<string, mixed> $sessionData
     * @return string 64-char hex (32 bytes)
     */
    public static function issue(array &$sessionData)
    {
        if (
            !isset($sessionData[self::SESSION_KEY])
            || !is_string($sessionData[self::SESSION_KEY])
            || !self::isValidFormat($sessionData[self::SESSION_KEY])
        ) {
            $sessionData[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return (string) $sessionData[self::SESSION_KEY];
    }

    /**
     * @param array<string, mixed> $sessionData
     * @param mixed $provided
     * @return bool
     */
    public static function verify(array $sessionData, $provided)
    {
        $expected = isset($sessionData[self::SESSION_KEY]) ? (string) $sessionData[self::SESSION_KEY] : '';
        $provided = is_string($provided) ? $provided : '';

        return $expected !== ''
            && $provided !== ''
            && self::isValidFormat($expected)
            && self::isValidFormat($provided)
            && hash_equals($expected, $provided);
    }

    /**
     * @param string $token
     * @return bool
     */
    public static function isValidFormat($token)
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/', (string) $token);
    }
}
