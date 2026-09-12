<?php

/**
 * Canonical shop order_id for CP create/PATCH and financing ownership (max 13, no truncation).
 *
 * Native OC3 order ids are positive decimals without leading zeros. Overflow is rejected,
 * never shortened via substr.
 */
final class MtUniCreditShopOrderId
{
    const MAX_LENGTH = 13;

    /**
     * Native OpenCart 3 `oc_order.order_id` INT UNSIGNED maximum (decimal string).
     */
    const NATIVE_OC3_ORDER_ID_MAX = '4294967295';

    /**
     * @param mixed $value
     * @return bool
     */
    public static function isValid($value)
    {
        return self::tryNormalize($value) !== null;
    }

    /**
     * Accept already-canonical string or positive int whose decimal form fits MAX_LENGTH.
     * Rejects non-string/non-int, empty, leading zeros, and length > 13 (no truncation).
     *
     * @param mixed $value
     * @return string|null
     */
    public static function tryNormalize($value)
    {
        if (is_int($value)) {
            if ($value <= 0) {
                return null;
            }
            $string = (string) $value;
            if (strlen($string) > self::MAX_LENGTH) {
                return null;
            }

            return $string;
        }

        if (!is_string($value)) {
            return null;
        }

        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            return null;
        }

        if (!preg_match('/^[1-9][0-9]{0,12}$/', $value)) {
            return null;
        }

        return $value;
    }

    /**
     * Strict inbound/resolver boundary: string only (no int/float/array coercion).
     *
     * @param mixed $value
     * @return string|null
     */
    public static function tryNormalizeStrictString($value)
    {
        if (!is_string($value)) {
            return null;
        }

        return self::tryNormalize($value);
    }

    /**
     * @param mixed $value
     * @return string
     */
    public static function requireNormalized($value)
    {
        $normalized = self::tryNormalize($value);
        if ($normalized === null) {
            throw new InvalidArgumentException('order_id must be a canonical shop order id (1–13 digits, no truncation).');
        }

        return $normalized;
    }

    /**
     * SQL literal for a canonical shop order_id (quoted + escaped).
     *
     * @param MtUniCreditDbAdapter $db
     * @param mixed $value
     * @return string e.g. '91001'
     */
    public static function sqlQuoted(MtUniCreditDbAdapter $db, $value)
    {
        return "'" . $db->escape(self::requireNormalized($value)) . "'";
    }

    /**
     * Decimal-string range check: $canonical fits in [1, $maxDecimal] without int/float cast.
     * Both arguments must be non-empty digit strings without leading zeros.
     *
     * @param string $canonical
     * @param string $maxDecimal
     * @return bool
     */
    public static function fitsDecimalMaximum($canonical, $maxDecimal)
    {
        if (!is_string($canonical) || !is_string($maxDecimal)) {
            return false;
        }
        if ($canonical === '' || $maxDecimal === '') {
            return false;
        }
        if (!preg_match('/^[1-9][0-9]*$/', $canonical) || !preg_match('/^[1-9][0-9]*$/', $maxDecimal)) {
            return false;
        }

        $canonicalLength = strlen($canonical);
        $maxLength = strlen($maxDecimal);
        if ($canonicalLength !== $maxLength) {
            return $canonicalLength < $maxLength;
        }

        return strcmp($canonical, $maxDecimal) <= 0;
    }

    /**
     * Native OC3 `oc_order.order_id` hint only — never for UniPayment persistence.
     *
     * Succeeds only when the canonical decimal fits BOTH:
     * - native INT UNSIGNED max (4294967295)
     * - running PHP_INT_MAX
     *
     * Range is proven via decimal-string comparison before any int cast.
     *
     * @param mixed $canonical
     * @return int|null
     */
    public static function tryNativeOc3OrderId($canonical)
    {
        $normalized = self::tryNormalize($canonical);
        if ($normalized === null) {
            return null;
        }
        if (!self::fitsDecimalMaximum($normalized, self::NATIVE_OC3_ORDER_ID_MAX)) {
            return null;
        }
        if (!self::fitsDecimalMaximum($normalized, (string) PHP_INT_MAX)) {
            return null;
        }

        return (int) $normalized;
    }
}
