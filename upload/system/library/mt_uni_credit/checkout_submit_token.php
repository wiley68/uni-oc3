<?php

/**
 * Checkout submit intent token — bound to prepared store/order (AUD-007-F05).
 *
 * Separate from CSRF. Does not participate in Checkout operation identity.
 */
final class MtUniCreditCheckoutSubmitToken
{
    const SESSION_KEY = 'mt_uni_credit_checkout_submit_intent';

    /** Legacy session key (pre-F05 plain token string). */
    const LEGACY_SESSION_KEY = 'mt_uni_credit_checkout_submit_token';

    const TOKEN_PATTERN = '/^[a-f0-9]{32}$/D';

    /**
     * Issue or reuse a submit token for the current prepared Checkout order.
     *
     * Same store+order → stable token (replay-safe). Different order → fresh token
     * (never rebinds an old token's metadata to a new order).
     *
     * @param array<string, mixed> $sessionData
     * @param int $storeId
     * @param int $orderId
     * @param int|null $preparedOrderId defaults to $orderId
     * @return string
     */
    public static function issue(array &$sessionData, $storeId, $orderId, $preparedOrderId = null)
    {
        $storeId = (int) $storeId;
        $orderId = (int) $orderId;
        $preparedOrderId = $preparedOrderId === null ? $orderId : (int) $preparedOrderId;
        self::dropLegacyPlainToken($sessionData);

        if ($storeId < 0 || $orderId <= 0 || $preparedOrderId !== $orderId) {
            unset($sessionData[self::SESSION_KEY]);

            return '';
        }

        $existing = self::readIntent($sessionData);
        if (
            $existing !== null
            && (int) $existing['store_id'] === $storeId
            && (int) $existing['order_id'] === $orderId
            && (int) $existing['prepared_order_id'] === $preparedOrderId
            && self::isValidTokenFormat($existing['token'])
        ) {
            return (string) $existing['token'];
        }

        $token = bin2hex(random_bytes(16));
        $sessionData[self::SESSION_KEY] = array(
            'token' => $token,
            'store_id' => $storeId,
            'order_id' => $orderId,
            'prepared_order_id' => $preparedOrderId,
        );

        return $token;
    }

    /**
     * @param array<string, mixed> $sessionData
     * @param mixed $provided
     * @param int $storeId
     * @param int $orderId
     * @param int|null $preparedOrderId defaults to $orderId
     * @return bool
     */
    public static function verify(array $sessionData, $provided, $storeId, $orderId, $preparedOrderId = null)
    {
        $storeId = (int) $storeId;
        $orderId = (int) $orderId;
        $preparedOrderId = $preparedOrderId === null ? $orderId : (int) $preparedOrderId;
        $provided = is_string($provided) ? $provided : '';

        if ($storeId < 0 || $orderId <= 0 || $preparedOrderId !== $orderId) {
            return false;
        }
        if (!self::isValidTokenFormat($provided)) {
            return false;
        }

        $intent = self::readIntent($sessionData);
        if ($intent === null) {
            return false;
        }
        if (!self::isValidTokenFormat($intent['token'])) {
            return false;
        }
        if ((int) $intent['store_id'] !== $storeId) {
            return false;
        }
        if ((int) $intent['order_id'] !== $orderId) {
            return false;
        }
        if ((int) $intent['prepared_order_id'] !== $preparedOrderId) {
            return false;
        }

        return hash_equals((string) $intent['token'], $provided);
    }

    /**
     * @param mixed $token
     * @return bool
     */
    public static function isValidTokenFormat($token)
    {
        return is_string($token) && preg_match(self::TOKEN_PATTERN, $token) === 1;
    }

    /**
     * @param array<string, mixed> $sessionData
     * @return array{token:string,store_id:int,order_id:int,prepared_order_id:int}|null
     */
    private static function readIntent(array $sessionData)
    {
        if (!isset($sessionData[self::SESSION_KEY]) || !is_array($sessionData[self::SESSION_KEY])) {
            return null;
        }
        $row = $sessionData[self::SESSION_KEY];
        if (!isset($row['token'], $row['store_id'], $row['order_id'], $row['prepared_order_id'])) {
            return null;
        }

        return array(
            'token' => (string) $row['token'],
            'store_id' => (int) $row['store_id'],
            'order_id' => (int) $row['order_id'],
            'prepared_order_id' => (int) $row['prepared_order_id'],
        );
    }

    /**
     * @param array<string, mixed> $sessionData
     * @return void
     */
    private static function dropLegacyPlainToken(array &$sessionData)
    {
        if (isset($sessionData[self::LEGACY_SESSION_KEY])) {
            unset($sessionData[self::LEGACY_SESSION_KEY]);
        }
    }
}
