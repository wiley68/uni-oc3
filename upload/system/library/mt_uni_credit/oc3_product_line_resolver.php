<?php

/**
 * Builds MtUniCreditProductLine using OC3 cart option +/- price rules.
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
     * @param array<string, mixed> $productRow OC product row (price/special/discount/tax_class_id/name/model/reward)
     * @param int $quantity
     * @param array<int|string, mixed> $requestedOptions option[product_option_id] => value
     * @param string $baseCurrency
     * @param string $displayCurrency
     * @param int[]|null $categories Override categories; otherwise categoryLoader
     * @return MtUniCreditProductLine
     */
    public function resolve(
        array $productRow,
        $quantity,
        array $requestedOptions,
        $baseCurrency,
        $displayCurrency,
        $categories = null
    ) {
        $productId = (int) (isset($productRow['product_id']) ? $productRow['product_id'] : 0);
        $quantity = max(1, (int) $quantity);
        $taxClassId = (int) (isset($productRow['tax_class_id']) ? $productRow['tax_class_id'] : 0);

        $optionData = $this->resolveOptions($requestedOptions);
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
     * @return array{option_price:float,order_options:array<int,array<string,mixed>>,normalized:array<int|string,mixed>}
     */
    private function resolveOptions(array $requestedOptions)
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

            // Text / date / time style options — no price delta from value loader when absent.
            if (!is_numeric($value) && $this->optionValueLoader === null) {
                $normalized[$productOptionId] = (string) $value;
                $orderOptions[] = array(
                    'product_option_id' => $productOptionId,
                    'product_option_value_id' => '',
                    'name' => '',
                    'value' => (string) $value,
                    'type' => 'text',
                );
                continue;
            }

            $row = $this->loadOptionValue($productOptionId, $value);
            if ($row === null) {
                // Non-select option value stored as free text.
                $normalized[$productOptionId] = (string) $value;
                $orderOptions[] = array(
                    'product_option_id' => $productOptionId,
                    'product_option_value_id' => '',
                    'name' => '',
                    'value' => (string) $value,
                    'type' => 'text',
                );
                continue;
            }

            $optionPrice = $this->applyPrefix($optionPrice, $row);
            $orderOptions[] = $this->orderOptionRow($productOptionId, $row, (string) (isset($row['name']) ? $row['name'] : ''));
            $normalized[$productOptionId] = is_numeric($value) ? (int) $value : (string) $value;
        }

        ksort($normalized);

        return array(
            'option_price' => $optionPrice,
            'order_options' => $orderOptions,
            'normalized' => $normalized,
        );
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
}
