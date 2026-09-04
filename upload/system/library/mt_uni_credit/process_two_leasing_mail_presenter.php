<?php

/**
 * Builds Process 2 leasing email bodies via shared FinancingLeasingPresenter.
 */
final class MtUniCreditProcessTwoLeasingMailPresenter
{
    const CUSTOMER_CONFIRMATION = MtUniCreditFinancingLeasingPresenter::PROCESS2_MESSAGE;

    /** @var MtUniCreditFinancingLeasingPresenter */
    private $presenter;

    /**
     * @param MtUniCreditFinancingLeasingPresenter|null $presenter
     */
    public function __construct($presenter = null)
    {
        $this->presenter = $presenter instanceof MtUniCreditFinancingLeasingPresenter
            ? $presenter
            : new MtUniCreditFinancingLeasingPresenter();
    }

    /**
     * @param array<string, mixed> $orderContext
     * @param MtUniCreditProcessTwoSensitiveData|null $sensitive
     * @return array<int, array{label: string, value: string}>
     */
    public function adminRows(array $orderContext, $sensitive)
    {
        return $this->rows($orderContext, MtUniCreditFinancingPresentationAudience::ADMIN_EMAIL, $sensitive);
    }

    /**
     * @param array<string, mixed> $orderContext
     * @return array<int, array{label: string, value: string}>
     */
    public function customerRows(array $orderContext)
    {
        return $this->rows($orderContext, MtUniCreditFinancingPresentationAudience::CUSTOMER, null);
    }

    /**
     * @param array<int, array{label: string, value: string}> $rows
     * @return string
     */
    public function renderHtml(array $rows)
    {
        return $this->presenter->renderHtml($rows, MtUniCreditFinancingLeasingPresenter::TITLE);
    }

    /**
     * @param array<int, array{label: string, value: string}> $rows
     * @return string
     */
    public function renderText(array $rows)
    {
        return $this->presenter->renderText($rows, MtUniCreditFinancingLeasingPresenter::TITLE);
    }

    /**
     * @param array<string, mixed> $orderContext
     * @param string $audience
     * @param MtUniCreditProcessTwoSensitiveData|null $sensitive
     * @return array<int, array{label: string, value: string}>
     */
    private function rows(array $orderContext, $audience, $sensitive)
    {
        $snapshotData = isset($orderContext['leasing_snapshot']) ? $orderContext['leasing_snapshot'] : null;
        if (is_array($snapshotData)) {
            $snapshot = MtUniCreditFinancingPresentationSnapshot::fromArray($snapshotData);
        } else {
            $snapshot = new MtUniCreditFinancingPresentationSnapshot(
                isset($orderContext['order_id']) ? (int) $orderContext['order_id'] : 0,
                isset($orderContext['control_panel_order_id']) ? (int) $orderContext['control_panel_order_id'] : null,
                true,
                isset($orderContext['months']) ? (int) $orderContext['months'] : 0,
                isset($orderContext['kop_code']) ? (string) $orderContext['kop_code'] : '',
                isset($orderContext['first_installment']) ? (float) $orderContext['first_installment'] : 0.0,
                isset($orderContext['financed_amount']) ? (float) $orderContext['financed_amount'] : 0.0,
                isset($orderContext['monthly_amount'])
                    ? (float) $orderContext['monthly_amount']
                    : (isset($orderContext['monthly_installment']) ? (float) $orderContext['monthly_installment'] : 0.0),
                isset($orderContext['total_payable']) ? (float) $orderContext['total_payable'] : 0.0,
                isset($orderContext['glp']) ? (float) $orderContext['glp'] : 0.0,
                isset($orderContext['gpr']) ? (float) $orderContext['gpr'] : 0.0
            );
        }
        $status = isset($orderContext['bank_status_label'])
            ? (string) $orderContext['bank_status_label']
            : MtUniCreditBankStatus::LABEL_SENT_PROCESS2;

        return $this->presenter->rows($snapshot, $status, $audience, $sensitive);
    }
}
