<?php

/**
 * Builds MtUniCreditCartContext from OpenCart cart products.
 *
 * Authoritative financed amount is the checkout grand total passed by the caller.
 */
final class MtUniCreditOc3CartContextFactory
{
    /** @var callable */
    private $categoryLoader;

    /** @var callable */
    private $taxCalculator;

    /**
     * @param callable $categoryLoader callable(int $productId): int[]
     * @param callable|null $taxCalculator callable(float $price, int $taxClassId): float
     */
    public function __construct($categoryLoader, $taxCalculator = null)
    {
        $this->categoryLoader = $categoryLoader;
        $this->taxCalculator = is_callable($taxCalculator)
            ? $taxCalculator
            : function ($price) {
                return (float) $price;
            };
    }

    /**
     * @param array<int, array<string, mixed>> $cartProducts
     * @param float $checkoutGrandTotal
     * @return MtUniCreditCartContext
     */
    public function create(array $cartProducts, $checkoutGrandTotal)
    {
        $lines = array();
        foreach ($cartProducts as $product) {
            $productId = (int) (isset($product['product_id']) ? $product['product_id'] : 0);
            if ($productId <= 0) {
                continue;
            }

            $quantity = max(1, (int) (isset($product['quantity']) ? $product['quantity'] : 1));
            $unit = call_user_func(
                $this->taxCalculator,
                (float) (isset($product['price']) ? $product['price'] : 0.0),
                (int) (isset($product['tax_class_id']) ? $product['tax_class_id'] : 0)
            );
            $lineTotal = round((float) $unit * $quantity, 2);
            $categories = call_user_func($this->categoryLoader, $productId);
            $categories = array_values(array_unique(array_map('intval', is_array($categories) ? $categories : array())));
            sort($categories);

            $optionValueIds = array();
            if (isset($product['option']) && is_array($product['option'])) {
                foreach ($product['option'] as $option) {
                    $optionValueId = (int) (isset($option['product_option_value_id']) ? $option['product_option_value_id'] : 0);
                    if ($optionValueId > 0) {
                        $optionValueIds[] = $optionValueId;
                    }
                }
            }
            $optionValueIds = array_values(array_unique($optionValueIds));
            sort($optionValueIds);
            $attributeId = $optionValueIds === array() ? 0 : max($optionValueIds);

            $lines[] = new MtUniCreditCartLine(
                new MtUniCreditProductContext($productId, $categories, $lineTotal),
                $attributeId,
                $quantity,
                $lineTotal,
                $optionValueIds
            );
        }

        return new MtUniCreditCartContext($lines, round((float) $checkoutGrandTotal, 2));
    }
}
