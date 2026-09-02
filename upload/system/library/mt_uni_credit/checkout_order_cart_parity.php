<?php

/**
 * Canonical comparison of native checkout order lines vs live cart lines.
 */
final class MtUniCreditCheckoutOrderCartParity
{
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
            $optionIds = array();
            if ($orderProductId > 0) {
                foreach (call_user_func($getOptions, (int) $orderId, $orderProductId) as $option) {
                    if (!is_array($option)) {
                        continue;
                    }
                    $pov = (int) (isset($option['product_option_value_id']) ? $option['product_option_value_id'] : 0);
                    if ($pov > 0) {
                        $optionIds[] = $pov;
                    }
                }
            }
            $optionIds = array_values(array_unique($optionIds));
            sort($optionIds);
            $lines[] = array(
                'product_id' => (int) (isset($product['product_id']) ? $product['product_id'] : 0),
                'option_value_ids' => $optionIds,
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
            $optionIds = array();
            if (isset($product['option']) && is_array($product['option'])) {
                foreach ($product['option'] as $option) {
                    if (!is_array($option)) {
                        continue;
                    }
                    $pov = (int) (isset($option['product_option_value_id']) ? $option['product_option_value_id'] : 0);
                    if ($pov > 0) {
                        $optionIds[] = $pov;
                    }
                }
            }
            $optionIds = array_values(array_unique($optionIds));
            sort($optionIds);
            $lines[] = array(
                'product_id' => (int) (isset($product['product_id']) ? $product['product_id'] : 0),
                'option_value_ids' => $optionIds,
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
     * @return bool
     */
    public static function matchesCurrentCart(
        array $order,
        array $orderProducts,
        $getOptions,
        array $cartProducts,
        $checkoutGrandTotal,
        $sessionCurrency
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
     * @param array<int, array<string, mixed>> $lines
     * @return string
     */
    private static function encodeLines(array $lines)
    {
        usort($lines, function ($a, $b) {
            $left = array($a['product_id'], $a['option_value_ids'], $a['quantity']);
            $right = array($b['product_id'], $b['option_value_ids'], $b['quantity']);

            return $left <=> $right;
        });

        return hash('sha256', json_encode($lines, JSON_THROW_ON_ERROR));
    }
}
