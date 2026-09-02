<?php

final class MtUniCreditFinancialCalculator
{
    /**
     * @param int $months
     * @param float $monthlyInstallment
     * @param float $price
     * @return float
     */
    public function calculateGpr($months, $monthlyInstallment, $price)
    {
        if ($months <= 0 || $price <= 0 || $monthlyInstallment <= 0) {
            return 0.0;
        }
        $periodRate = $this->financialRate($months, -$monthlyInstallment, $price);
        $annualRate = ($periodRate * $months) / ($months / 12);

        return abs((pow(1 + $annualRate / 12, 12) - 1) * 100);
    }

    /**
     * @param float $periods
     * @param float $payment
     * @param float $presentValue
     * @return float
     */
    public function financialRate($periods, $payment, $presentValue)
    {
        $rate = 0.1;
        $y = $this->rateValue($periods, $payment, $presentValue, $rate);
        $y0 = $presentValue + $payment * $periods;
        $y1 = $y;
        $x0 = 0.0;
        $x1 = $rate;
        $iterations = 0;
        while (abs($y0 - $y1) > 1.0e-8 && $iterations < 128) {
            $difference = $y1 - $y0;
            if (abs($difference) < 1.0e-12) {
                break;
            }
            $rate = ($y1 * $x0 - $y0 * $x1) / $difference;
            $x0 = $x1;
            $x1 = $rate;
            $y = $this->rateValue($periods, $payment, $presentValue, $rate);
            $y0 = $y1;
            $y1 = $y;
            ++$iterations;
        }

        return $rate;
    }

    /**
     * @param float $periods
     * @param float $payment
     * @param float $presentValue
     * @param float $rate
     * @return float
     */
    private function rateValue($periods, $payment, $presentValue, $rate)
    {
        if (abs($rate) < 1.0e-8) {
            return $presentValue * (1 + $periods * $rate) + $payment * $periods;
        }
        $factor = exp($periods * log(1 + $rate));

        return $presentValue * $factor + $payment * (1 / $rate) * ($factor - 1);
    }
}
