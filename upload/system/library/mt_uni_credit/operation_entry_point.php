<?php

/**
 * Controlled operation lock entry points.
 */
final class MtUniCreditOperationEntryPoint
{
    const PRODUCT = 'product';

    const CART = 'cart';

    const CHECKOUT = 'checkout';

    /**
     * @return array<int, string>
     */
    public static function all()
    {
        return array(self::PRODUCT, self::CART, self::CHECKOUT);
    }

    /**
     * @param string $entryPoint
     * @return bool
     */
    public static function isValid($entryPoint)
    {
        return in_array($entryPoint, self::all(), true);
    }
}
