<?php

/**
 * Frozen leasing presentation values for one financing order (no live recalculation).
 */
final class MtUniCreditFinancingPresentationSnapshot
{
    /** @var int */
    public $shopOrderId;

    /** @var int|null */
    public $controlPanelOrderId;

    /** @var bool */
    public $process2;

    /** @var int */
    public $months;

    /** @var string */
    public $kopCode;

    /** @var float */
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

    /**
     * @param int $shopOrderId
     * @param int|null $controlPanelOrderId
     * @param bool $process2
     * @param int $months
     * @param string $kopCode
     * @param float $firstInstallment
     * @param float $financedAmount
     * @param float $monthlyInstallment
     * @param float $totalPayable
     * @param float $glp
     * @param float $gpr
     */
    public function __construct(
        $shopOrderId,
        $controlPanelOrderId,
        $process2,
        $months,
        $kopCode,
        $firstInstallment,
        $financedAmount,
        $monthlyInstallment,
        $totalPayable,
        $glp,
        $gpr
    ) {
        $this->shopOrderId = (int) $shopOrderId;
        $cp = $controlPanelOrderId !== null ? (int) $controlPanelOrderId : 0;
        $this->controlPanelOrderId = $cp > 0 ? $cp : null;
        $this->process2 = (bool) $process2;
        $this->months = (int) $months;
        $this->kopCode = (string) $kopCode;
        $this->firstInstallment = (float) $firstInstallment;
        $this->financedAmount = (float) $financedAmount;
        $this->monthlyInstallment = (float) $monthlyInstallment;
        $this->totalPayable = (float) $totalPayable;
        $this->glp = (float) $glp;
        $this->gpr = (float) $gpr;
    }

    /**
     * @param MtUniCreditCalculationResult $calculation
     * @param int $shopOrderId
     * @param bool $process2
     * @param int|null $controlPanelOrderId
     * @return self
     */
    public static function fromCalculation(
        MtUniCreditCalculationResult $calculation,
        $shopOrderId,
        $process2,
        $controlPanelOrderId = null
    ) {
        $first = 0.0;
        if (isset($calculation->firstInstallment) && is_object($calculation->firstInstallment)) {
            $first = (float) $calculation->firstInstallment->amount;
        }

        return new self(
            $shopOrderId,
            $controlPanelOrderId,
            $process2,
            (int) $calculation->scheme->months,
            (string) $calculation->scheme->kopCode,
            $first,
            (float) $calculation->financedAmount,
            (float) $calculation->monthlyInstallment,
            (float) $calculation->totalPayable,
            (float) $calculation->glp,
            (float) $calculation->gpr
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data)
    {
        $cp = isset($data['control_panel_order_id']) ? (int) $data['control_panel_order_id'] : 0;

        return new self(
            isset($data['shop_order_id']) ? (int) $data['shop_order_id'] : 0,
            $cp > 0 ? $cp : null,
            !empty($data['process2']),
            isset($data['months']) ? (int) $data['months'] : 0,
            isset($data['kop_code']) ? (string) $data['kop_code'] : '',
            isset($data['first_installment']) ? (float) $data['first_installment'] : 0.0,
            isset($data['financed_amount']) ? (float) $data['financed_amount'] : 0.0,
            isset($data['monthly_installment']) ? (float) $data['monthly_installment'] : 0.0,
            isset($data['total_payable']) ? (float) $data['total_payable'] : 0.0,
            isset($data['glp']) ? (float) $data['glp'] : 0.0,
            isset($data['gpr']) ? (float) $data['gpr'] : 0.0
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return array(
            'shop_order_id' => $this->shopOrderId,
            'control_panel_order_id' => $this->controlPanelOrderId,
            'process2' => $this->process2,
            'months' => $this->months,
            'kop_code' => $this->kopCode,
            'first_installment' => $this->firstInstallment,
            'financed_amount' => $this->financedAmount,
            'monthly_installment' => $this->monthlyInstallment,
            'total_payable' => $this->totalPayable,
            'glp' => $this->glp,
            'gpr' => $this->gpr,
        );
    }

    /**
     * @param int $controlPanelOrderId
     * @return self
     */
    public function withControlPanelOrderId($controlPanelOrderId)
    {
        return new self(
            $this->shopOrderId,
            $controlPanelOrderId,
            $this->process2,
            $this->months,
            $this->kopCode,
            $this->firstInstallment,
            $this->financedAmount,
            $this->monthlyInstallment,
            $this->totalPayable,
            $this->glp,
            $this->gpr
        );
    }
}
