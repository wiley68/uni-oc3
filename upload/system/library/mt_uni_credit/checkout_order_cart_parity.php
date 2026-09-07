<?php

/**
 * Canonical comparison of native checkout order lines vs live cart lines.
 */
final class MtUniCreditCheckoutOrderCartParity
{
    /** Absolute tolerance for OC3 currency_value (DECIMAL-scale exchange rate). */
    const CURRENCY_VALUE_EPSILON = 1.0e-8;

    /**
     * @param int $orderId
     * @param array<int, array<string, mixed>> $orderProducts
     * @param callable $getOptions callable(int $orderId, int $orderProductId): array
     * @return string
     */
    public static function structuralKeyFromOrderProducts($orderId, array $orderProducts, $getOptions)
    {
        $lines = array();
        foreach ($orderProducts as $product) {
            if (!is_array($product)) {
                continue;
            }
            $orderProductId = (int) (isset($product['order_product_id']) ? $product['order_product_id'] : 0);
            $optionTokens = array();
            if ($orderProductId > 0) {
                foreach (call_user_func($getOptions, (int) $orderId, $orderProductId) as $option) {
                    $token = self::optionIdentityToken($option);
                    if ($token !== '') {
                        $optionTokens[] = $token;
                    }
                }
            }
            $optionTokens = array_values(array_unique($optionTokens));
            sort($optionTokens, SORT_STRING);
            $lines[] = array(
                'product_id' => (int) (isset($product['product_id']) ? $product['product_id'] : 0),
                'options' => $optionTokens,
                'quantity' => (int) (isset($product['quantity']) ? $product['quantity'] : 0),
            );
        }

        return self::encodeLines($lines);
    }

    /**
     * @param array<int, array<string, mixed>> $cartProducts
     * @return string
     */
    public static function structuralKeyFromCartProducts(array $cartProducts)
    {
        $lines = array();
        foreach ($cartProducts as $product) {
            if (!is_array($product)) {
                continue;
            }
            $optionTokens = array();
            if (isset($product['option']) && is_array($product['option'])) {
                foreach ($product['option'] as $option) {
                    $token = self::optionIdentityToken($option);
                    if ($token !== '') {
                        $optionTokens[] = $token;
                    }
                }
            }
            $optionTokens = array_values(array_unique($optionTokens));
            sort($optionTokens, SORT_STRING);
            $lines[] = array(
                'product_id' => (int) (isset($product['product_id']) ? $product['product_id'] : 0),
                'options' => $optionTokens,
                'quantity' => (int) (isset($product['quantity']) ? $product['quantity'] : 0),
            );
        }

        return self::encodeLines($lines);
    }

    /**
     * @param array<string, mixed> $order
     * @param array<int, array<string, mixed>> $orderProducts
     * @param callable $getOptions
     * @param array<int, array<string, mixed>> $cartProducts
     * @param float $checkoutGrandTotal
     * @param string $sessionCurrency
     * @param float|null $sessionCurrencyValue Active OC3 currency conversion value (null = unavailable)
     * @return bool
     */
    public static function matchesCurrentCart(
        array $order,
        array $orderProducts,
        $getOptions,
        array $cartProducts,
        $checkoutGrandTotal,
        $sessionCurrency,
        $sessionCurrencyValue = null
    ) {
        $orderId = (int) (isset($order['order_id']) ? $order['order_id'] : 0);
        if ($orderId <= 0 || $cartProducts === array()) {
            return false;
        }

        $orderCurrency = strtoupper(trim((string) (isset($order['currency_code']) ? $order['currency_code'] : '')));
        $cartCurrency = strtoupper(trim((string) $sessionCurrency));
        if ($orderCurrency === '' || $cartCurrency === '' || $orderCurrency !== $cartCurrency) {
            return false;
        }

        if (array_key_exists('currency_value', $order)) {
            if ($sessionCurrencyValue === null || !is_numeric($sessionCurrencyValue)) {
                return false;
            }
            $orderRate = (float) $order['currency_value'];
            $sessionRate = (float) $sessionCurrencyValue;
            if (abs($orderRate - $sessionRate) > self::CURRENCY_VALUE_EPSILON) {
                return false;
            }
        }

        $orderTotal = round((float) (isset($order['total']) ? $order['total'] : 0.0), 2);
        $checkoutGrandTotal = round((float) $checkoutGrandTotal, 2);
        if (abs($orderTotal - $checkoutGrandTotal) > 0.001) {
            return false;
        }

        $orderKey = self::structuralKeyFromOrderProducts($orderId, $orderProducts, $getOptions);
        $cartKey = self::structuralKeyFromCartProducts($cartProducts);

        return hash_equals($orderKey, $cartKey);
    }

    /**
     * Stable option identity for enumerated and free-text/custom OC3 options.
     *
     * Enumerated (product_option_value_id > 0): e:{product_option_id}:{product_option_value_id}
     * Free-text/custom (pov == 0): c:{product_option_id}:{type}:{normalized_value}
     *
     * @param mixed $option
     * @return string Empty when the row has no semantic identity
     */
    public static function optionIdentityToken($option)
    {
        if (!is_array($option)) {
            return '';
        }
        $productOptionId = (int) (isset($option['product_option_id']) ? $option['product_option_id'] : 0);
        $productOptionValueId = (int) (isset($option['product_option_value_id']) ? $option['product_option_value_id'] : 0);
        if ($productOptionValueId > 0) {
            return 'e:' . $productOptionId . ':' . $productOptionValueId;
        }

        $type = strtolower(trim((string) (isset($option['type']) ? $option['type'] : '')));
        $value = self::normalizeOptionValue(isset($option['value']) ? $option['value'] : '');
        if ($productOptionId <= 0 && $value === '') {
            return '';
        }
        if ($type === '') {
            $type = 'text';
        }

        return 'c:' . $productOptionId . ':' . $type . ':' . $value;
    }

    /**
     * @param mixed $value
     * @return string
     */
    public static function normalizeOptionValue($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $collapsed = preg_replace('/\s+/u', ' ', $value);

        return is_string($collapsed) ? $collapsed : $value;
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return string
     */
    private static function encodeLines(array $lines)
    {
        usort($lines, function ($a, $b) {
            $left = array($a['product_id'], $a['options'], $a['quantity']);
            $right = array($b['product_id'], $b['options'], $b['quantity']);

            return $left < $right ? -1 : ($left > $right ? 1 : 0);
        });

        return hash('sha256', json_encode($lines, JSON_THROW_ON_ERROR));
    }
}
