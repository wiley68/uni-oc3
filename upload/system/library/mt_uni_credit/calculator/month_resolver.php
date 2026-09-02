<?php

final class MtUniCreditMonthResolver
{
    const MIN = 3;

    const MAX = 36;

    /**
     * @param int $months
     * @return bool
     */
    public function isValid($months)
    {
        return $months >= self::MIN && $months <= self::MAX;
    }

    /**
     * @param string $raw
     * @return int[]
     */
    public function parse($raw)
    {
        $result = array();
        foreach (explode('_', str_replace(',', '_', $raw)) as $part) {
            $months = (int) trim($part);
            if ($this->isValid($months)) {
                $result[] = $months;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @param array<string, mixed> $shop
     * @return int[]
     */
    public function enabledMonths(array $shop)
    {
        $enabled = array();
        for ($months = self::MIN; $months <= self::MAX; ++$months) {
            if ($this->isEnabledFlag(isset($shop['uni_meseci_' . $months]) ? $shop['uni_meseci_' . $months] : 0)) {
                $enabled[] = $months;
            }
        }

        return $enabled;
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $shop
     * @return int[]
     */
    public function allowedForFilter(array $filter, array $shop)
    {
        $shopMonths = $this->enabledMonths($shop);
        if (!$this->hasValue(isset($filter['uni_meseci']) ? $filter['uni_meseci'] : null)) {
            return $shopMonths;
        }

        return array_values(array_intersect($shopMonths, $this->parse((string) $filter['uni_meseci'])));
    }

    /**
     * @param array<string, mixed> $byDefault
     * @param float $price
     * @param int[] $candidateMonths
     * @return int[]
     */
    public function defaultPromoMonths(array $byDefault, $price, array $candidateMonths)
    {
        $minimumPrice = (float) (isset($byDefault['uni_promo_price']) ? $byDefault['uni_promo_price'] : 0);
        if ($minimumPrice > 0 && $price < $minimumPrice) {
            return array();
        }

        $operator = strtolower(trim((string) (isset($byDefault['uni_promo_meseci_znak']) ? $byDefault['uni_promo_meseci_znak'] : '')));
        $raw = trim((string) (isset($byDefault['uni_promo_meseci']) ? $byDefault['uni_promo_meseci'] : ''));
        if ($operator === 'eq') {
            return array_values(array_intersect($candidateMonths, $this->parse($raw)));
        }
        if ($operator === 'greateq') {
            $parts = $this->parse($raw);
            $minimum = (int) $raw;
            if (!$this->isValid($minimum)) {
                $minimum = isset($parts[0]) ? $parts[0] : 0;
            }
            if (!$this->isValid($minimum)) {
                return array();
            }

            return array_values(array_filter(
                $candidateMonths,
                function ($months) use ($minimum) {
                    return $months >= $minimum;
                }
            ));
        }

        return array();
    }

    /**
     * @param mixed $value
     * @return bool
     */
    public function isEnabledFlag($value)
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), array('yes', 'on', '1', 'true'), true);
    }

    /**
     * @param mixed $value
     * @return bool
     */
    public function hasValue($value)
    {
        return $value !== null && trim((string) $value) !== '';
    }
}
