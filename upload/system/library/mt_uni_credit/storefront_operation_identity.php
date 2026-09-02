<?php

/**
 * Deterministic operation / cart fingerprints for Product and Cart storefront flows.
 */
final class MtUniCreditStorefrontOperationIdentity
{
    /**
     * @param int $storeId
     * @param int $productId
     * @param array<int|string, mixed> $optionsNormalized
     * @param int $quantity
     * @param string $currency
     * @return string
     */
    public static function productHash($storeId, $productId, array $optionsNormalized, $quantity, $currency)
    {
        ksort($optionsNormalized);
        $payload = array(
            'store_id' => (int) $storeId,
            'product_id' => (int) $productId,
            'options' => $optionsNormalized,
            'quantity' => max(1, (int) $quantity),
            'currency' => strtoupper(trim((string) $currency)),
        );

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param int $storeId
     * @param string $currency
     * @param string $fingerprint
     * @return string
     */
    public static function cartHash($storeId, $currency, $fingerprint)
    {
        return hash(
            'sha256',
            (int) $storeId . '|' . strtoupper(trim((string) $currency)) . '|' . (string) $fingerprint
        );
    }

    /**
     * @param array<int, array<string, mixed>> $lines Minimal line rows: product_id, quantity, total|price
     * @param float $total
     * @param string $currency
     * @return string
     */
    public static function cartFingerprint(array $lines, $total, $currency)
    {
        $normalized = array();
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $normalized[] = array(
                'product_id' => (int) (isset($line['product_id']) ? $line['product_id'] : 0),
                'quantity' => (int) (isset($line['quantity']) ? $line['quantity'] : 0),
                'total' => round((float) (isset($line['total'])
                    ? $line['total']
                    : (isset($line['price']) ? $line['price'] : 0)), 4),
            );
        }
        usort($normalized, function ($left, $right) {
            if ($left['product_id'] === $right['product_id']) {
                return $left['quantity'] - $right['quantity'];
            }

            return $left['product_id'] < $right['product_id'] ? -1 : 1;
        });

        $payload = array(
            'lines' => $normalized,
            'total' => round((float) $total, 2),
            'currency' => strtoupper(trim((string) $currency)),
        );

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param MtUniCreditCartContext $cart
     * @param string $currency
     * @return string
     */
    public static function cartFingerprintFromContext(MtUniCreditCartContext $cart, $currency)
    {
        $lines = array();
        foreach ($cart->lines as $line) {
            $lines[] = array(
                'product_id' => $line->product->productId,
                'quantity' => $line->quantity,
                'total' => $line->lineTotal,
            );
        }

        return self::cartFingerprint($lines, $cart->total, $currency);
    }
}
