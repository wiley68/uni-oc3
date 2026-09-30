<?php

/** OC3 native money is in store base units; its saved currency value selects EUR. */
final class MtUniCreditEurAmount
{
    public static function isEur($code)
    {
        return is_string($code) && strtoupper(trim($code)) === 'EUR';
    }

    public static function validFactor($value)
    {
        return (is_int($value) || is_float($value) || is_string($value))
            && is_numeric($value) && is_finite((float) $value) && (float) $value > 0.0;
    }

    public static function validMoney($value)
    {
        return (is_int($value) || is_float($value) || is_string($value))
            && is_numeric($value) && is_finite((float) $value) && (float) $value >= 0.0;
    }

    public static function validCurrencyId($value)
    {
        return (is_int($value) && $value > 0)
            || (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1);
    }

    /** Multiply before rounding. OC3 currency_value is the selected/base factor. */
    public static function fromBase($amount, $factor, $places = 2)
    {
        if (!self::validMoney($amount) || !self::validFactor($factor)) {
            throw new InvalidArgumentException('EUR amount provenance is invalid.');
        }
        $converted = (float) $amount * (float) $factor;
        if (!is_finite($converted)) {
            throw new InvalidArgumentException('EUR amount is not finite.');
        }

        return round($converted, (int) $places);
    }

    /** Return the persisted factor only for the exact native EUR order. */
    public static function orderFactor(array $order, $storeId, $orderId)
    {
        $boundId = MtUniCreditShopOrderId::tryNormalize($orderId);
        $nativeId = MtUniCreditShopOrderId::tryNormalize(isset($order['order_id']) ? $order['order_id'] : null);
        if ($boundId === null || $nativeId !== $boundId
            || (int) (isset($order['store_id']) ? $order['store_id'] : -1) !== (int) $storeId
            || !self::isEur(isset($order['currency_code']) ? $order['currency_code'] : null)
            || !self::validCurrencyId(isset($order['currency_id']) ? $order['currency_id'] : null)
            || !self::validFactor(isset($order['currency_value']) ? $order['currency_value'] : null)
            || !self::validMoney(isset($order['total']) ? $order['total'] : null)) {
            return null;
        }

        return (float) $order['currency_value'];
    }

    /** Preserve line selection identity while giving scheme/calculator EUR amounts. */
    public static function cartContext(MtUniCreditCartContext $native, $factor)
    {
        if (!self::validFactor($factor)) {
            throw new InvalidArgumentException('EUR cart factor is invalid.');
        }
        $lines = array();
        foreach ($native->lines as $line) {
            $converted = clone $line;
            $converted->product = clone $line->product;
            $converted->product->price = self::fromBase($line->product->price, $factor, 6);
            $converted->lineTotal = self::fromBase($line->lineTotal, $factor, 6);
            $lines[] = $converted;
        }

        // CartContext constructor rounds to cents. Keep conversion precision until
        // the existing calculator applies its own financing rounding.
        $eur = new MtUniCreditCartContext($lines, $native->total, $native->checkoutState);
        $eur->total = self::fromBase($native->total, $factor, 6);

        return $eur;
    }

    /** Frozen calculation must describe this native order in EUR. */
    public static function matchesCalculation(array $order, MtUniCreditCalculationResult $calculation)
    {
        if (!self::validFactor(isset($order['currency_value']) ? $order['currency_value'] : null)
            || !self::validMoney(isset($order['total']) ? $order['total'] : null)) {
            return false;
        }

        return abs(self::fromBase($order['total'], $order['currency_value']) - (float) $calculation->price) <= 0.011;
    }

    public static function matchesSnapshot($snapshot, array $order)
    {
        if (!is_array($snapshot) || !isset($snapshot['financial']) || !is_array($snapshot['financial'])) {
            return false;
        }
        $financial = $snapshot['financial'];
        if (!self::isEur(isset($financial['currency']) ? $financial['currency'] : null)
            || !self::validMoney(isset($financial['price']) ? $financial['price'] : null)
            || !self::validFactor(isset($order['currency_value']) ? $order['currency_value'] : null)
            || !self::validMoney(isset($order['total']) ? $order['total'] : null)) {
            return false;
        }

        return abs(self::fromBase($order['total'], $order['currency_value']) - (float) $financial['price']) <= 0.011;
    }
}
