<?php

/** Normalized financing offer DTO (immutable by convention). */
final class MtUniCreditOffer
{
    /** @var string */
    public $type;

    /** @var string */
    public $kopCode;

    /** @var int */
    public $months;

    /** @var float */
    public $monthlyInstallment;

    /** @var float */
    public $glp;

    /** @var float */
    public $gpr;

    /** @var float */
    public $financedAmount;

    /** @var float */
    public $coefficient;

    /** @var int */
    public $filterId;

    public function __construct(
        string $type,
        string $kopCode,
        int $months,
        float $monthlyInstallment,
        float $glp,
        float $gpr,
        float $financedAmount,
        float $coefficient,
        int $filterId = 0
    ) {
        $this->type = $type;
        $this->kopCode = $kopCode;
        $this->months = $months;
        $this->monthlyInstallment = $monthlyInstallment;
        $this->glp = $glp;
        $this->gpr = $gpr;
        $this->financedAmount = $financedAmount;
        $this->coefficient = $coefficient;
        $this->filterId = $filterId;
    }

    /**
     * @return string
     */
    public function identityKey()
    {
        return $this->type . '|' . $this->kopCode . '|' . $this->months;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return array(
            'type' => $this->type,
            'visible' => true,
            'kop_code' => $this->kopCode,
            'installment_count' => $this->months,
            'monthly_installment' => $this->monthlyInstallment,
            'glp' => $this->glp,
            'gpr' => $this->gpr,
            'total_amount' => $this->financedAmount,
            'kimb' => $this->coefficient,
            'filter_id' => $this->filterId,
        );
    }
}
