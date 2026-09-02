<?php

final class MtUniCreditFirstInstallmentResolver
{
    /** @var MtUniCreditMonthResolver */
    private $months;

    public function __construct(MtUniCreditMonthResolver $months)
    {
        $this->months = $months;
    }

    /**
     * @param array<string, mixed> $shop
     * @param float $price
     * @param int $months
     * @param float $requested
     * @param array<string, mixed>|null $filter
     * @return MtUniCreditFirstInstallmentState
     */
    public function resolve(array $shop, $price, $months, $requested, $filter)
    {
        $visible = $this->months->isEnabledFlag(isset($shop['uni_first_vnoska']) ? $shop['uni_first_vnoska'] : 0);
        if ($filter !== null && (int) (isset($filter['uni_parva']) ? $filter['uni_parva'] : 0) === 1 && $months > 0) {
            return new MtUniCreditFirstInstallmentState(round($price / $months, 2), true, true);
        }
        if ($visible) {
            return new MtUniCreditFirstInstallmentState(max(0.0, min(round($requested, 2), $price)), false, true);
        }

        return new MtUniCreditFirstInstallmentState(0.0, false, false);
    }
}
