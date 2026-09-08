<?php

/**
 * Builds MtUniCreditProductLine using OC3 cart option +/- price rules.
 *
 * AUD-019: optional strict Product Apply validation against current getProductOptions().
 */
final class MtUniCreditOc3ProductLineResolver
{
    /** @var callable */
    private $taxCalculator;

    /** @var callable */
    private $currencyConverter;

    /** @var callable|null */
    private $categoryLoader;

    /** @var callable|null */
    private $optionValueLoader;

    /**
     * @param callable $taxCalculator callable(float $unitExTax, int $taxClassId): float unit with tax (base currency)
     * @param callable $currencyConverter callable(float $amount, string $from, string $to): float
     * @param callable|null $categoryLoader callable(int $productId): int[]
     * @param callable|null $optionValueLoader callable(int $productOptionId, int|string|array $value): array|null
     *        Returns option value row with keys: name, price, price_prefix, product_option_value_id, type?, option_id?
     */
    public function __construct(
        $taxCalculator,
        $currencyConverter,
        $categoryLoader = null,
        $optionValueLoader = null
    ) {
        $this->taxCalculator = $taxCalculator;
        $this->currencyConverter = $currencyConverter;
        $this->categoryLoader = is_callable($categoryLoader) ? $categoryLoader : null;
        $this->optionValueLoader = is_callable($optionValueLoader) ? $optionValueLoader : null;
    }

    /**
     * @param array<string, mixed> $productRow OC product row (price/special/discount/tax_class_id/name/model/reward/minimum)
     * @param int $quantity
     * @param array<int|string, mixed> $requestedOptions option[product_option_id] => value
     * @param string $baseCurrency
     * @param string $displayCurrency
     * @param int[]|null $categories Override categories; otherwise categoryLoader
     * @param array<int, array<string, mixed>>|null $productOptions Native getProductOptions() rows
     * @param bool $strict Enforce required options + minimum + no invalid ID→text fallback
     * @return MtUniCreditProductLine
     * @throws MtUniCreditProductLineValidationException
     */
    public function resolve(
        array $productRow,
        $quantity,
        array $requestedOptions,
        $baseCurrency,
        $displayCurrency,
        $categories = null,
        $productOptions = null,
        $strict = false
    ) {
        $productId = (int) (isset($productRow['product_id']) ? $productRow['product_id'] : 0);
        $quantity = (int) $quantity;
        $minimum = max(1, (int) (isset($productRow['minimum']) ? $productRow['minimum'] : 1));
        if ($strict && $quantity < $minimum) {
            throw new MtUniCreditProductLineValidationException(
                MtUniCreditProductLineValidationException::CODE_QUANTITY_BELOW_MINIMUM,
                'quantity_below_minimum'
            );
        }
        $quantity = max(1, $quantity);
        $taxClassId = (int) (isset($productRow['tax_class_id']) ? $productRow['tax_class_id'] : 0);

        if (is_array($productOptions) && $productOptions !== array()) {
            $optionData = $this->resolveOptionsAgainstDefinitions(
                $requestedOptions,
                $productOptions,
                (bool) $strict
            );
        } else {
            $optionData = $this->resolveOptionsLegacy($requestedOptions, (bool) $strict);
        }
        $baseUnit = (float) (
            !empty($productRow['special'])
            ? $productRow['special']
            : (!empty($productRow['discount']) ? $productRow['discount'] : (isset($productRow['price']) ? $productRow['price'] : 0))
        );
        $baseUnit += (float) $optionData['option_price'];

        $unitExTax = (float) $baseUnit;
        $unitWithTaxBase = (float) call_user_func($this->taxCalculator, $unitExTax, $taxClassId);
        $unitWithTaxDisplay = (float) call_user_func(
            $this->currencyConverter,
            $unitWithTaxBase,
            (string) $baseCurrency,
            (string) $displayCurrency
        );
        $financingPrice = round($unitWithTaxDisplay * $quantity, 4);

        if ($categories === null) {
            $categories = array();
            if ($this->categoryLoader !== null && $productId > 0) {
                $loaded = call_user_func($this->categoryLoader, $productId);
                $categories = is_array($loaded) ? $loaded : array();
            }
        }

        return new MtUniCreditProductLine(
            $productId,
            isset($productRow['name']) ? (string) $productRow['name'] : '',
            isset($productRow['model']) ? (string) $productRow['model'] : '',
            $categories,
            $quantity,
            $unitExTax,
            $unitWithTaxDisplay,
            $financingPrice,
            $taxClassId,
            $optionData['order_options'],
            (int) (isset($productRow['reward']) ? $productRow['reward'] : 0)
        );
    }

    /**
     * @param array<int|string, mixed> $requestedOptions
     * @param array<int, array<string, mixed>> $productOptions
     * @param bool $strict
     * @return array{option_price:float,order_options:array<int,array<string,mixed>>,normalized:array<int|string,mixed>}
     * @throws MtUniCreditProductLineValidationException
     */
    private function resolveOptionsAgainstDefinitions(array $requestedOptions, array $productOptions, $strict)
    {
        $defs = array();
        foreach ($productOptions as $option) {
            if (!is_array($option)) {
                continue;
            }
            $productOptionId = (int) (isset($option['product_option_id']) ? $option['product_option_id'] : 0);
            if ($productOptionId <= 0) {
                continue;
            }
            $defs[$productOptionId] = $option;
        }

        foreach ($requestedOptions as $postedOptionId => $postedValue) {
            $postedOptionId = (int) $postedOptionId;
            if ($postedOptionId <= 0) {
                continue;
            }
            if (!isset($defs[$postedOptionId])) {
                if ($strict) {
                    throw new MtUniCreditProductLineValidationException(
                        MtUniCreditProductLineValidationException::CODE_INVALID_OPTION,
                        'invalid_option'
                    );
                }
            }
        }

        $optionPrice = 0.0;
        $orderOptions = array();
        $normalized = array();

        foreach ($defs as $productOptionId => $option) {
            $type = (string) (isset($option['type']) ? $option['type'] : '');
            $isRequired = !empty($option['required']);
            $optionName = isset($option['name']) ? (string) $option['name'] : '';
            $hasPosted = array_key_exists($productOptionId, $requestedOptions);
            $value = $hasPosted ? $requestedOptions[$productOptionId] : null;

            if (!$hasPosted || $this->isEmptyOptionValue($value, $type)) {
                if ($strict && $isRequired) {
                    throw new MtUniCreditProductLineValidationException(
                        MtUniCreditProductLineValidationException::CODE_MISSING_REQUIRED_OPTION,
                        'missing_required_option'
                    );
                }
                continue;
            }

            if ($type === 'checkbox') {
                if (!is_array($value)) {
                    if ($strict) {
                        throw new MtUniCreditProductLineValidationException(
                            MtUniCreditProductLineValidationException::CODE_INVALID_OPTION,
                            'invalid_option'
                        );
                    }
                    continue;
                }
                $normalized[$productOptionId] = array();
                foreach ($value as $productOptionValueId) {
                    $row = $this->requireOptionValueRow($productOptionId, $productOptionValueId, $option, $strict);
                    if ($row === null) {
                        continue;
                    }
                    $optionPrice = $this->applyPrefix($optionPrice, $row);
                    $orderOptions[] = $this->orderOptionRowFromDefinition(
                        $productOptionId,
                        $row,
                        $optionName,
                        $type
                    );
                    $normalized[$productOptionId][] = (int) $productOptionValueId;
                }
                if ($strict && $isRequired && $normalized[$productOptionId] === array()) {
                    throw new MtUniCreditProductLineValidationException(
                        MtUniCreditProductLineValidationException::CODE_MISSING_REQUIRED_OPTION,
                        'missing_required_option'
                    );
                }
                continue;
            }

            if ($type === 'select' || $type === 'radio') {
                if (is_array($value) || !is_numeric($value) || (int) $value <= 0) {
                    if ($strict) {
                        throw new MtUniCreditProductLineValidationException(
                            MtUniCreditProductLineValidationException::CODE_INVALID_OPTION,
                            'invalid_option'
                        );
                    }
                    continue;
                }
                $row = $this->requireOptionValueRow($productOptionId, $value, $option, $strict);
                if ($row === null) {
                    continue;
                }
                $optionPrice = $this->applyPrefix($optionPrice, $row);
                $orderOptions[] = $this->orderOptionRowFromDefinition(
                    $productOptionId,
                    $row,
                    $optionName,
                    $type
                );
                $normalized[$productOptionId] = (int) $value;
                continue;
            }

            // text / textarea / date / datetime / time / file (and unknown scalar types)
            if (is_array($value)) {
                if ($strict) {
                    throw new MtUniCreditProductLineValidationException(
                        MtUniCreditProductLineValidationException::CODE_INVALID_OPTION,
                        'invalid_option'
                    );
                }
                continue;
            }
            $scalar = trim((string) $value);
            if ($scalar === '') {
                if ($strict && $isRequired) {
                    throw new MtUniCreditProductLineValidationException(
                        MtUniCreditProductLineValidationException::CODE_MISSING_REQUIRED_OPTION,
                        'missing_required_option'
                    );
                }
                continue;
            }
            $orderOptions[] = array(
                'product_option_id' => $productOptionId,
                'product_option_value_id' => '',
                'name' => $optionName,
                'value' => $scalar,
                'type' => $type !== '' ? $type : 'text',
            );
            $normalized[$productOptionId] = $scalar;
        }

        ksort($normalized);

        return array(
            'option_price' => $optionPrice,
            'order_options' => $orderOptions,
            'normalized' => $normalized,
        );
    }

    /**
     * Legacy path without product option definitions (tests / partial loaders).
     * Never reinterprets failed numeric POV lookups as free text.
     *
     * @param array<int|string, mixed> $requestedOptions
     * @param bool $strict
     * @return array{option_price:float,order_options:array<int,array<string,mixed>>,normalized:array<int|string,mixed>}
     * @throws MtUniCreditProductLineValidationException
     */
    private function resolveOptionsLegacy(array $requestedOptions, $strict)
    {
        $optionPrice = 0.0;
        $orderOptions = array();
        $normalized = array();

        foreach ($requestedOptions as $productOptionId => $value) {
            $productOptionId = (int) $productOptionId;
            if ($productOptionId <= 0) {
                continue;
            }

            if (is_array($value)) {
                $normalized[$productOptionId] = array();
                foreach ($value as $productOptionValueId) {
                    $row = $this->loadOptionValue($productOptionId, $productOptionValueId);
                    if ($row === null) {
                        if ($strict) {
                            throw new MtUniCreditProductLineValidationException(
                                MtUniCreditProductLineValidationException::CODE_INVALID_OPTION,
                                'invalid_option'
                            );
                        }
                        continue;
                    }
                    $optionPrice = $this->applyPrefix($optionPrice, $row);
                    $orderOptions[] = $this->orderOptionRow($productOptionId, $row, (string) (isset($row['name']) ? $row['name'] : ''));
                    $normalized[$productOptionId][] = (int) $productOptionValueId;
                }
                continue;
            }

            if ($value === '' || $value === null) {
                continue;
            }

            if (is_numeric($value)) {
                $row = $this->loadOptionValue($productOptionId, $value);
                if ($row === null) {
                    if ($strict) {
                        throw new MtUniCreditProductLineValidationException(
                            MtUniCreditProductLineValidationException::CODE_INVALID_OPTION,
                            'invalid_option'
                        );
                    }
                    // Invalid numeric POV must never become free-text.
                    continue;
                }
                $optionPrice = $this->applyPrefix($optionPrice, $row);
                $orderOptions[] = $this->orderOptionRow($productOptionId, $row, (string) (isset($row['name']) ? $row['name'] : ''));
                $normalized[$productOptionId] = (int) $value;
                continue;
            }

            // Non-numeric free-text style values.
            $normalized[$productOptionId] = (string) $value;
            $orderOptions[] = array(
                'product_option_id' => $productOptionId,
                'product_option_value_id' => '',
                'name' => '',
                'value' => (string) $value,
                'type' => 'text',
            );
        }

        ksort($normalized);

        return array(
            'option_price' => $optionPrice,
            'order_options' => $orderOptions,
            'normalized' => $normalized,
        );
    }

    /**
     * @param mixed $value
     * @param string $type
     * @return bool
     */
    private function isEmptyOptionValue($value, $type)
    {
        if ($value === null || $value === '') {
            return true;
        }
        if ($type === 'checkbox') {
            return !is_array($value) || $value === array();
        }
        if (is_array($value)) {
            return $value === array();
        }

        return trim((string) $value) === '';
    }

    /**
     * @param int $productOptionId
     * @param mixed $value
     * @param array<string, mixed> $option
     * @param bool $strict
     * @return array<string, mixed>|null
     * @throws MtUniCreditProductLineValidationException
     */
    private function requireOptionValueRow($productOptionId, $value, array $option, $strict)
    {
        $productOptionValueId = (int) $value;
        if ($productOptionValueId <= 0) {
            if ($strict) {
                throw new MtUniCreditProductLineValidationException(
                    MtUniCreditProductLineValidationException::CODE_INVALID_OPTION,
                    'invalid_option'
                );
            }

            return null;
        }

        // Prefer definition membership (foreign value under another option).
        $allowed = false;
        if (isset($option['product_option_value']) && is_array($option['product_option_value'])) {
            foreach ($option['product_option_value'] as $optionValue) {
                if (!is_array($optionValue)) {
                    continue;
                }
                if (
                    (int) (isset($optionValue['product_option_value_id']) ? $optionValue['product_option_value_id'] : 0)
                    === $productOptionValueId
                ) {
                    $allowed = true;
                    $row = $optionValue;
                    $row['type'] = isset($option['type']) ? (string) $option['type'] : 'select';
                    $row['option_name'] = isset($option['name']) ? (string) $option['name'] : '';
                    if (!isset($row['name'])) {
                        $row['name'] = '';
                    }
                    // Still prefer loader for price when available.
                    $loaded = $this->loadOptionValue($productOptionId, $productOptionValueId);
                    if (is_array($loaded)) {
                        return $loaded;
                    }

                    return $row;
                }
            }
            if (!$allowed) {
                if ($strict) {
                    throw new MtUniCreditProductLineValidationException(
                        MtUniCreditProductLineValidationException::CODE_INVALID_OPTION,
                        'invalid_option'
                    );
                }

                return null;
            }
        }

        $loaded = $this->loadOptionValue($productOptionId, $productOptionValueId);
        if ($loaded === null) {
            if ($strict) {
                throw new MtUniCreditProductLineValidationException(
                    MtUniCreditProductLineValidationException::CODE_INVALID_OPTION,
                    'invalid_option'
                );
            }

            return null;
        }

        return $loaded;
    }

    /**
     * @param int $productOptionId
     * @param mixed $value
     * @return array<string, mixed>|null
     */
    private function loadOptionValue($productOptionId, $value)
    {
        if ($this->optionValueLoader === null) {
            return null;
        }

        $row = call_user_func($this->optionValueLoader, $productOptionId, $value);

        return is_array($row) ? $row : null;
    }

    /**
     * @param float $optionPrice
     * @param array<string, mixed> $row
     * @return float
     */
    private function applyPrefix($optionPrice, array $row)
    {
        $price = (float) (isset($row['price']) ? $row['price'] : 0);
        $prefix = isset($row['price_prefix']) ? (string) $row['price_prefix'] : '+';
        if ($prefix === '-') {
            return $optionPrice - $price;
        }

        return $optionPrice + $price;
    }

    /**
     * @param int $productOptionId
     * @param array<string, mixed> $row
     * @param string $valueName
     * @return array<string, mixed>
     */
    private function orderOptionRow($productOptionId, array $row, $valueName)
    {
        return array(
            'product_option_id' => $productOptionId,
            'product_option_value_id' => isset($row['product_option_value_id']) ? $row['product_option_value_id'] : '',
            'name' => isset($row['option_name']) ? (string) $row['option_name'] : (isset($row['name']) ? (string) $row['name'] : ''),
            'value' => $valueName,
            'type' => isset($row['type']) ? (string) $row['type'] : 'select',
        );
    }

    /**
     * @param int $productOptionId
     * @param array<string, mixed> $row
     * @param string $optionName
     * @param string $type
     * @return array<string, mixed>
     */
    private function orderOptionRowFromDefinition($productOptionId, array $row, $optionName, $type)
    {
        return array(
            'product_option_id' => $productOptionId,
            'product_option_value_id' => isset($row['product_option_value_id']) ? $row['product_option_value_id'] : '',
            'name' => $optionName !== ''
                ? $optionName
                : (isset($row['option_name']) ? (string) $row['option_name'] : ''),
            'value' => isset($row['name']) ? (string) $row['name'] : '',
            'type' => $type !== '' ? $type : (isset($row['type']) ? (string) $row['type'] : 'select'),
        );
    }
}
