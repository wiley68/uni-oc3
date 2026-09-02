<?php

final class MtUniCreditCalculationResult
{
    /** @var MtUniCreditAvailableScheme */
    public $scheme;

    /** @var float */
    public $price;

    /** @var MtUniCreditFirstInstallmentState */
    public $firstInstallment;

    /** @var float */
    public $financedAmount;

    /** @var float */
    public $monthlyInstallment;

    /** @var float */
    public $totalPayable;

    /** @var float */
    public $glp;

    /** @var float */
    public $gpr;

    public function __construct(
        MtUniCreditAvailableScheme $scheme,
        float $price,
        MtUniCreditFirstInstallmentState $firstInstallment,
        float $financedAmount,
        float $monthlyInstallment,
        float $totalPayable,
        float $glp,
        float $gpr
    ) {
        $this->scheme = $scheme;
        $this->price = $price;
        $this->firstInstallment = $firstInstallment;
        $this->financedAmount = $financedAmount;
        $this->monthlyInstallment = $monthlyInstallment;
        $this->totalPayable = $totalPayable;
        $this->glp = $glp;
        $this->gpr = $gpr;
    }
}
