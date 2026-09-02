<?php

final class MtUniCreditAvailableScheme
{
    /** @var string */
    public $type;

    /** @var string */
    public $kopCode;

    /** @var int */
    public $months;

    /** @var int */
    public $filterId;

    /** @var array<string, mixed>|null */
    public $filter;

    /** @var array<string, mixed> */
    public $coefficient;

    /** @var bool */
    public $firstInstallmentAmbiguous;

    /**
     * @param string $type
     * @param string $kopCode
     * @param int $months
     * @param int $filterId
     * @param array<string, mixed>|null $filter
     * @param array<string, mixed> $coefficient
     * @param bool $firstInstallmentAmbiguous
     */
    public function __construct(
        $type,
        $kopCode,
        $months,
        $filterId,
        $filter,
        array $coefficient,
        $firstInstallmentAmbiguous = false
    ) {
        $this->type = (string) $type;
        $this->kopCode = (string) $kopCode;
        $this->months = (int) $months;
        $this->filterId = (int) $filterId;
        $this->filter = $filter;
        $this->coefficient = $coefficient;
        $this->firstInstallmentAmbiguous = (bool) $firstInstallmentAmbiguous;
    }

    /**
     * @return string
     */
    public function identityKey()
    {
        return $this->type . '|' . $this->kopCode . '|' . $this->months;
    }
}
