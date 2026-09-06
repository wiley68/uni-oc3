<?php

/**
 * Per-widget application token — distinguishes a new Product/Cart application
 * from same-operation replay (OC4 submission_token role, OC3-minimal).
 *
 * Selection identity (product/cart hash) stays stable; operation_key_hash /
 * session bind / operation lock are sha256(selectionIdentity|applicationToken).
 *
 * Tokens are authoritative only when present in session-issued state and bound to
 * store_id + entry_point + selection_hash. Format-valid unknown tokens are rejected.
 * Retention: last 32 issued entries; eviction permanently invalidates the token.
 */
final class MtUniCreditStorefrontApplicationToken
{
    const SESSION_ISSUED_KEY = 'mt_uni_credit_storefront_app_tokens';

    const MAX_ISSUED = 32;

    /**
     * Issue a fresh token bound to store / entry point / selection identity.
     *
     * @param array<string, mixed> $sessionData
     * @param int $storeId
     * @param string $entryPoint product|cart
     * @param string $selectionHash canonical Product/Cart selection identity (64 hex)
     * @return string 32-char lowercase hex
     */
    public static function issue(array &$sessionData, $storeId, $entryPoint, $selectionHash)
    {
        $storeId = (int) $storeId;
        $entryPoint = (string) $entryPoint;
        $selectionHash = (string) $selectionHash;
        self::assertIssuable($storeId, $entryPoint, $selectionHash);

        $token = bin2hex(random_bytes(16));
        if (
            !isset($sessionData[self::SESSION_ISSUED_KEY])
            || !is_array($sessionData[self::SESSION_ISSUED_KEY])
        ) {
            $sessionData[self::SESSION_ISSUED_KEY] = array();
        }

        $sessionData[self::SESSION_ISSUED_KEY][$token] = array(
            'store_id' => $storeId,
            'entry_point' => $entryPoint,
            'selection_hash' => $selectionHash,
            'issued_at' => time(),
        );

        if (count($sessionData[self::SESSION_ISSUED_KEY]) > self::MAX_ISSUED) {
            $sessionData[self::SESSION_ISSUED_KEY] = array_slice(
                $sessionData[self::SESSION_ISSUED_KEY],
                -self::MAX_ISSUED,
                null,
                true
            );
        }

        return $token;
    }

    /**
     * Reuse preferred token when it already authorizes this exact binding; otherwise issue fresh.
     *
     * @param array<string, mixed> $sessionData
     * @param int $storeId
     * @param string $entryPoint
     * @param string $selectionHash
     * @param string $preferredToken
     * @return string
     */
    public static function issueForSelection(
        array &$sessionData,
        $storeId,
        $entryPoint,
        $selectionHash,
        $preferredToken = ''
    ) {
        $preferredToken = (string) $preferredToken;
        if (
            $preferredToken !== ''
            && self::accepts($sessionData, $preferredToken, $storeId, $entryPoint, $selectionHash)
        ) {
            return $preferredToken;
        }

        return self::issue($sessionData, $storeId, $entryPoint, $selectionHash);
    }

    /**
     * @param string $token
     * @return bool
     */
    public static function isValidFormat($token)
    {
        return is_string($token) && (bool) preg_match('/^[a-f0-9]{32}$/D', $token);
    }

    /**
     * Accept only session-issued tokens bound to the same store, entry point, and selection.
     *
     * @param array<string, mixed> $sessionData
     * @param string $token
     * @param int $storeId
     * @param string $entryPoint
     * @param string $selectionHash
     * @return bool
     */
    public static function accepts(array $sessionData, $token, $storeId, $entryPoint, $selectionHash)
    {
        $token = (string) $token;
        if (!self::isValidFormat($token)) {
            return false;
        }

        $selectionHash = (string) $selectionHash;
        if (!self::isValidSelectionHash($selectionHash)) {
            return false;
        }

        $entryPoint = (string) $entryPoint;
        if (!self::isStorefrontEntryPoint($entryPoint)) {
            return false;
        }

        if (
            !isset($sessionData[self::SESSION_ISSUED_KEY])
            || !is_array($sessionData[self::SESSION_ISSUED_KEY])
            || !isset($sessionData[self::SESSION_ISSUED_KEY][$token])
        ) {
            return false;
        }

        $meta = $sessionData[self::SESSION_ISSUED_KEY][$token];
        if (!is_array($meta)) {
            return false;
        }

        if (!isset($meta['store_id'], $meta['entry_point'], $meta['selection_hash'])) {
            return false;
        }

        if ((int) $meta['store_id'] !== (int) $storeId) {
            return false;
        }

        if ((string) $meta['entry_point'] !== $entryPoint) {
            return false;
        }

        return hash_equals((string) $meta['selection_hash'], $selectionHash);
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

    /**
     * @param string $selectionHash
     * @return bool
     */
    public static function isValidSelectionHash($selectionHash)
    {
        return is_string($selectionHash) && (bool) preg_match('/^[a-f0-9]{64}$/D', $selectionHash);
    }

    /**
     * @param string $entryPoint
     * @return bool
     */
    private static function isStorefrontEntryPoint($entryPoint)
    {
        return $entryPoint === MtUniCreditOperationEntryPoint::PRODUCT
            || $entryPoint === MtUniCreditOperationEntryPoint::CART;
    }

    /**
     * @param int $storeId
     * @param string $entryPoint
     * @param string $selectionHash
     * @return void
     */
    private static function assertIssuable($storeId, $entryPoint, $selectionHash)
    {
        if ($storeId < 0) {
            throw new InvalidArgumentException('application token store_id is invalid');
        }
        if (!self::isStorefrontEntryPoint($entryPoint)) {
            throw new InvalidArgumentException('application token entry_point is invalid');
        }
        if (!self::isValidSelectionHash($selectionHash)) {
            throw new InvalidArgumentException('application token selection_hash is invalid');
        }
    }
}
