<?php

/**
 * Per-widget application token — distinguishes a new Product/Cart application
 * from same-operation replay (OC4 submission_token role, OC3-minimal).
 *
 * Selection identity (product/cart hash) stays stable; operation_key_hash /
 * session bind / operation lock are sha256(selectionIdentity|applicationToken).
 */
final class MtUniCreditStorefrontApplicationToken
{
    const SESSION_ISSUED_KEY = 'mt_uni_credit_storefront_app_tokens';

    /**
     * Issue a fresh token for this widget render and remember it in session.
     *
     * @param array<string, mixed> $sessionData
     * @return string 64-char hex
     */
    public static function issue(array &$sessionData)
    {
        $token = bin2hex(random_bytes(16));
        if (
            !isset($sessionData[self::SESSION_ISSUED_KEY])
            || !is_array($sessionData[self::SESSION_ISSUED_KEY])
        ) {
            $sessionData[self::SESSION_ISSUED_KEY] = array();
        }
        $sessionData[self::SESSION_ISSUED_KEY][$token] = time();
        // Cap memory: keep last 32 issued tokens.
        if (count($sessionData[self::SESSION_ISSUED_KEY]) > 32) {
            $sessionData[self::SESSION_ISSUED_KEY] = array_slice(
                $sessionData[self::SESSION_ISSUED_KEY],
                -32,
                null,
                true
            );
        }

        return $token;
    }

    /**
     * @param string $token
     * @return bool
     */
    public static function isValidFormat($token)
    {
        return is_string($token) && (bool) preg_match('/^[a-f0-9]{32}$/', $token);
    }

    /**
     * Accept token if format-valid. Prefer session-issued, but allow format-only
     * so a just-rendered widget still works if session write lagged.
     *
     * @param array<string, mixed> $sessionData
     * @param string $token
     * @return bool
     */
    public static function accepts(array $sessionData, $token)
    {
        $token = (string) $token;
        if (!self::isValidFormat($token)) {
            return false;
        }
        if (
            isset($sessionData[self::SESSION_ISSUED_KEY])
            && is_array($sessionData[self::SESSION_ISSUED_KEY])
            && isset($sessionData[self::SESSION_ISSUED_KEY][$token])
        ) {
            return true;
        }

        // Format-valid token from concurrent tab / cookie lag — still scoped by bind key.
        return true;
    }

    /**
     * Application-scoped operation identity: selection hash + application token.
     *
     * @param string $selectionIdentityHash product/cart identity hash
     * @param string $applicationToken
     * @return string
     */
    public static function bindKey($selectionIdentityHash, $applicationToken)
    {
        return hash('sha256', (string) $selectionIdentityHash . '|' . (string) $applicationToken);
    }
}
