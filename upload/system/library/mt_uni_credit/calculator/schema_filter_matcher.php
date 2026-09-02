<?php

final class MtUniCreditSchemaFilterMatcher
{
    /** @var MtUniCreditMonthResolver */
    private $months;

    /** @var string */
    private $today;

    /**
     * @param MtUniCreditMonthResolver $months
     * @param string|null $today
     */
    public function __construct(MtUniCreditMonthResolver $months, $today = null)
    {
        $this->months = $months;
        $this->today = $today !== null ? $today : date('Y-m-d');
    }

    /**
     * @param array<string, mixed> $filter
     * @param MtUniCreditProductContext $product
     * @return bool
     */
    public function matches(array $filter, MtUniCreditProductContext $product)
    {
        $hasCategory = $this->months->hasValue(isset($filter['category_id']) ? $filter['category_id'] : null);
        $hasProduct = $this->months->hasValue(isset($filter['product_id']) ? $filter['product_id'] : null);
        if ($hasCategory && $hasProduct) {
            return false;
        }
        if ($hasCategory && !in_array((int) $filter['category_id'], $product->categoryIds, true)) {
            return false;
        }
        if ($hasProduct && (int) $filter['product_id'] !== $product->productId) {
            return false;
        }
        if (
            $this->months->hasValue(isset($filter['uni_price_from']) ? $filter['uni_price_from'] : null)
            && $product->price < (float) $filter['uni_price_from']
        ) {
            return false;
        }
        if (
            $this->months->hasValue(isset($filter['uni_price_to']) ? $filter['uni_price_to'] : null)
            && $product->price > (float) $filter['uni_price_to']
        ) {
            return false;
        }

        return $this->matchesDate($filter);
    }

    /**
     * @param array<string, mixed> $filter
     * @return bool
     */
    public function matchesDate(array $filter)
    {
        if (
            $this->months->hasValue(isset($filter['uni_date_from']) ? $filter['uni_date_from'] : null)
            && $this->today < substr(trim((string) $filter['uni_date_from']), 0, 10)
        ) {
            return false;
        }
        if (
            $this->months->hasValue(isset($filter['uni_date_to']) ? $filter['uni_date_to'] : null)
            && $this->today > substr(trim((string) $filter['uni_date_to']), 0, 10)
        ) {
            return false;
        }

        return true;
    }
}
