<?php

final class MtUniCreditOfferFactory
{
    /** @var MtUniCreditFinancialCalculator */
    private $financial;

    public function __construct(MtUniCreditFinancialCalculator $financial)
    {
        $this->financial = $financial;
    }

    /**
     * @param string $type
     * @param string $kopCode
     * @param int $months
     * @param float $amount
     * @param array<string, mixed> $coefficient
     * @param int $filterId
     * @return MtUniCreditOffer|null
     */
    public function create($type, $kopCode, $months, $amount, array $coefficient, $filterId = 0)
    {
        $kimb = (float) (isset($coefficient['coeff']) ? $coefficient['coeff'] : 0);
        if ($kimb <= 0 || $amount <= 0 || $months <= 0) {
            return null;
        }
        $monthly = round($amount * $kimb, 2);

        return new MtUniCreditOffer(
            $type,
            $kopCode,
            $months,
            $monthly,
            round((float) (isset($coefficient['interestPercent']) ? $coefficient['interestPercent'] : 0), 2),
            round($this->financial->calculateGpr($months, $monthly, $amount), 2),
            round($amount, 2),
            $kimb,
            $filterId
        );
    }
}
