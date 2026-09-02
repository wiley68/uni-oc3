<?php

/**
 * Session-bound CSRF/intent token for customer checkout submit POST.
 */
final class MtUniCreditCheckoutSubmitToken
{
    const SESSION_KEY = 'mt_uni_credit_checkout_submit_token';

    /**
     * @param array<string, mixed> $sessionData
     * @return string
     */
    public static function issue(array &$sessionData)
    {
        if (
            !isset($sessionData[self::SESSION_KEY])
            || !is_string($sessionData[self::SESSION_KEY])
            || $sessionData[self::SESSION_KEY] === ''
        ) {
            $sessionData[self::SESSION_KEY] = bin2hex(random_bytes(16));
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

        return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
    }
}
