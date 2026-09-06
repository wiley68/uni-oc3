<?php

/**
 * Deterministic operation / cart fingerprints for Product and Cart storefront flows.
 */
final class MtUniCreditStorefrontOperationIdentity
{
    const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * @param int $storeId
     * @param int $productId
     * @param array<int|string, mixed> $options Order-option rows, or legacy map product_option_id => value
     * @param int $quantity
     * @param string $currency
     * @return string
     */
    public static function productHash($storeId, $productId, array $options, $quantity, $currency)
    {
        $payload = self::productPayload($storeId, $productId, $options, $quantity, $currency);

        return hash('sha256', self::encodeJson($payload));
    }

    /**
     * @param int $storeId
     * @param int $productId
     * @param array<int|string, mixed> $options
     * @param int $quantity
     * @param string $currency
     * @return array<string, mixed>
     */
    public static function productPayload($storeId, $productId, array $options, $quantity, $currency)
    {
        return array(
            'store_id' => (int) $storeId,
            'product_id' => (int) $productId,
            'options' => self::canonicalizeOptions($options),
            'quantity' => max(1, (int) $quantity),
            'currency' => strtoupper(trim((string) $currency)),
        );
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
     * @param array<int, array<string, mixed>> $lines Minimal line rows: product_id, quantity, total|price, options?
     * @param float|int|string $total
     * @param string $currency
     * @return string
     */
    public static function cartFingerprint(array $lines, $total, $currency)
    {
        $payload = self::cartFingerprintPayload($lines, $total, $currency);

        return hash('sha256', self::encodeJson($payload));
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @param float|int|string $total
     * @param string $currency
     * @return array<string, mixed>
     */
    public static function cartFingerprintPayload(array $lines, $total, $currency)
    {
        $normalized = array();
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $options = array();
            if (isset($line['options']) && is_array($line['options'])) {
                $options = $line['options'];
            } elseif (isset($line['option_value_ids']) && is_array($line['option_value_ids'])) {
                foreach ($line['option_value_ids'] as $optionValueId) {
                    $optionValueId = (int) $optionValueId;
                    if ($optionValueId > 0) {
                        $options[] = array(
                            'product_option_id' => 0,
                            'product_option_value_id' => $optionValueId,
                            'value' => '',
                        );
                    }
                }
            }

            $normalized[] = array(
                'product_id' => (int) (isset($line['product_id']) ? $line['product_id'] : 0),
                'options' => self::canonicalizeOptions($options),
                'quantity' => (int) (isset($line['quantity']) ? $line['quantity'] : 0),
                'total' => self::decimalString(
                    isset($line['total'])
                        ? $line['total']
                        : (isset($line['price']) ? $line['price'] : 0),
                    4
                ),
            );
        }

        usort($normalized, array(__CLASS__, 'compareCartLines'));

        return array(
            'lines' => $normalized,
            'total' => self::decimalString($total, 2),
            'currency' => strtoupper(trim((string) $currency)),
        );
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
            $options = array();
            if (isset($line->options) && is_array($line->options) && $line->options !== array()) {
                $options = $line->options;
            } else {
                foreach ($line->optionValueIds as $optionValueId) {
                    $options[] = array(
                        'product_option_id' => 0,
                        'product_option_value_id' => (int) $optionValueId,
                        'value' => '',
                    );
                }
            }
            $lines[] = array(
                'product_id' => $line->product->productId,
                'quantity' => $line->quantity,
                'total' => $line->lineTotal,
                'options' => $options,
            );
        }

        return self::cartFingerprint($lines, $cart->total, $currency);
    }

    /**
     * Canonical option list for Product/Cart identity.
     *
     * Accepts order-option / cart-option rows, or a legacy associative map
     * product_option_id => scalar|list-of-value-ids.
     *
     * @param array<int|string, mixed> $options
     * @return array<int, array{option_id:int,value_id:int|null,value:string|null}>
     */
    public static function canonicalizeOptions(array $options)
    {
        $records = array();

        if (self::isLegacyOptionMap($options)) {
            foreach ($options as $productOptionId => $value) {
                $productOptionId = (int) $productOptionId;
                if ($productOptionId <= 0) {
                    continue;
                }
                if (is_array($value)) {
                    foreach ($value as $productOptionValueId) {
                        $record = self::optionRecord($productOptionId, $productOptionValueId, null);
                        if ($record !== null) {
                            $records[] = $record;
                        }
                    }
                    continue;
                }
                if (is_numeric($value) && (string) (int) $value === trim((string) $value)) {
                    $record = self::optionRecord($productOptionId, (int) $value, null);
                } else {
                    $record = self::optionRecord($productOptionId, null, $value);
                }
                if ($record !== null) {
                    $records[] = $record;
                }
            }
        } else {
            foreach ($options as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $productOptionId = 0;
                if (isset($option['product_option_id'])) {
                    $productOptionId = (int) $option['product_option_id'];
                } elseif (isset($option['option_id'])) {
                    $productOptionId = (int) $option['option_id'];
                }
                if ($productOptionId < 0) {
                    continue;
                }
                // product_option_id may be 0 when reconstructing from value-id-only cart lines.
                $valueId = null;
                if (array_key_exists('product_option_value_id', $option)) {
                    $raw = $option['product_option_value_id'];
                    if ($raw !== '' && $raw !== null && (int) $raw > 0) {
                        $valueId = (int) $raw;
                    }
                } elseif (array_key_exists('value_id', $option)) {
                    $raw = $option['value_id'];
                    if ($raw !== '' && $raw !== null && (int) $raw > 0) {
                        $valueId = (int) $raw;
                    }
                }
                $text = array_key_exists('value', $option) ? $option['value'] : null;
                $record = self::optionRecord($productOptionId, $valueId, $text);
                if ($record !== null) {
                    $records[] = $record;
                }
            }
        }

        usort($records, array(__CLASS__, 'compareOptionRecords'));

        return array_values($records);
    }

    /**
     * Locale-independent fixed-scale decimal string for identity serialization.
     *
     * @param mixed $value
     * @param int $scale
     * @return string
     */
    public static function decimalString($value, $scale)
    {
        $scale = max(0, (int) $scale);
        $rounded = round((float) $value, $scale);

        return sprintf('%.' . $scale . 'F', $rounded);
    }

    /**
     * @param array<string, mixed> $payload
     * @return string
     */
    public static function encodeJson(array $payload)
    {
        return (string) json_encode($payload, self::JSON_FLAGS);
    }

    /**
     * @param array<int|string, mixed> $options
     * @return bool
     */
    private static function isLegacyOptionMap(array $options)
    {
        if ($options === array()) {
            return false;
        }

        $keys = array_keys($options);
        $isList = $keys === range(0, count($options) - 1);
        if ($isList) {
            $first = reset($options);
            if (is_array($first) && (
                array_key_exists('product_option_id', $first)
                || array_key_exists('option_id', $first)
                || array_key_exists('value_id', $first)
                || array_key_exists('product_option_value_id', $first)
            )) {
                return false;
            }
        }

        foreach ($options as $value) {
            if (is_array($value)) {
                if ($value === array()) {
                    continue;
                }
                if (self::isListOfScalars($value)) {
                    return true;
                }
                if (
                    array_key_exists('product_option_id', $value)
                    || array_key_exists('option_id', $value)
                    || array_key_exists('product_option_value_id', $value)
                    || array_key_exists('value_id', $value)
                ) {
                    return false;
                }

                return true;
            }

            return true;
        }

        return !$isList;
    }

    /**
     * @param array<int|string, mixed> $value
     * @return bool
     */
    private static function isListOfScalars(array $value)
    {
        foreach ($value as $item) {
            if (is_array($item) || is_object($item)) {
                return false;
            }
        }

        $keys = array_keys($value);

        return $keys === range(0, count($value) - 1);
    }

    /**
     * @param int $optionId
     * @param mixed $valueId
     * @param mixed $value
     * @return array{option_id:int,value_id:int|null,value:string|null}|null
     */
    private static function optionRecord($optionId, $valueId, $value)
    {
        $optionId = (int) $optionId;
        $normalizedValueId = null;
        if ($valueId !== null && $valueId !== '') {
            $asInt = (int) $valueId;
            if ($asInt > 0) {
                $normalizedValueId = $asInt;
            }
        }

        $normalizedValue = null;
        if ($normalizedValueId === null) {
            if ($value === null) {
                return null;
            }
            $asString = (string) $value;
            if ($asString === '') {
                return null;
            }
            $normalizedValue = $asString;
        }

        return array(
            'option_id' => $optionId,
            'value_id' => $normalizedValueId,
            'value' => $normalizedValue,
        );
    }

    /**
     * @param array{option_id:int,value_id:int|null,value:string|null} $left
     * @param array{option_id:int,value_id:int|null,value:string|null} $right
     * @return int
     */
    private static function compareOptionRecords(array $left, array $right)
    {
        if ($left['option_id'] !== $right['option_id']) {
            return $left['option_id'] < $right['option_id'] ? -1 : 1;
        }

        $leftValueId = $left['value_id'] === null ? -1 : (int) $left['value_id'];
        $rightValueId = $right['value_id'] === null ? -1 : (int) $right['value_id'];
        if ($leftValueId !== $rightValueId) {
            return $leftValueId < $rightValueId ? -1 : 1;
        }

        $leftValue = $left['value'] === null ? '' : (string) $left['value'];
        $rightValue = $right['value'] === null ? '' : (string) $right['value'];
        if ($leftValue === $rightValue) {
            return 0;
        }

        return strcmp($leftValue, $rightValue);
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @return int
     */
    private static function compareCartLines(array $left, array $right)
    {
        if ($left['product_id'] !== $right['product_id']) {
            return $left['product_id'] < $right['product_id'] ? -1 : 1;
        }

        $leftOptions = self::encodeJson($left['options']);
        $rightOptions = self::encodeJson($right['options']);
        if ($leftOptions !== $rightOptions) {
            return strcmp($leftOptions, $rightOptions);
        }

        if ($left['quantity'] !== $right['quantity']) {
            return $left['quantity'] < $right['quantity'] ? -1 : 1;
        }

        if ($left['total'] !== $right['total']) {
            return strcmp((string) $left['total'], (string) $right['total']);
        }

        return 0;
    }
}
