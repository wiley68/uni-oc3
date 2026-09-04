<?php

/**
 * Resolve customer/admin leasing rows for an OpenCart order (Thank You / mail).
 */
final class MtUniCreditFinancingPresentationService
{
    /** @var MtUniCreditFinancingPresentationRepository */
    private $repository;

    /** @var MtUniCreditFinancingLeasingPresenter */
    private $presenter;

    /**
     * @param MtUniCreditFinancingPresentationRepository $repository
     * @param MtUniCreditFinancingLeasingPresenter|null $presenter
     */
    public function __construct(
        MtUniCreditFinancingPresentationRepository $repository,
        $presenter = null
    ) {
        $this->repository = $repository;
        $this->presenter = $presenter instanceof MtUniCreditFinancingLeasingPresenter
            ? $presenter
            : new MtUniCreditFinancingLeasingPresenter();
    }

    /**
     * @param int $storeId
     * @param int $orderId
     * @param string $audience
     * @return array<int, array{label: string, value: string}>
     */
    public function rowsForOrder($storeId, $orderId, $audience)
    {
        $snapshot = $this->repository->findByOrderId((int) $storeId, (int) $orderId);
        if ($snapshot === null) {
            return array();
        }
        $status = $this->repository->findBankStatusLabel((int) $storeId, (int) $orderId);
        $sensitive = null;
        // OC4: decrypt only when audience may include EGN/phone2 (ADMIN_EMAIL / ADMIN_PANEL).
        // Customer paths never decrypt process2_sensitive_enc.
        if (
            $audience === MtUniCreditFinancingPresentationAudience::ADMIN_EMAIL
            || $audience === MtUniCreditFinancingPresentationAudience::ADMIN_PANEL
        ) {
            $sensitive = $this->decryptSensitive((int) $storeId, (int) $orderId);
        }

        return $this->presenter->rows($snapshot, $status, (string) $audience, $sensitive);
    }

    /**
     * Customer Thank You / customer native mail rows — never EGN/phone2/CP ids.
     *
     * @param int $storeId
     * @param int $orderId
     * @return array<int, array{label: string, value: string}>
     */
    public function customerThankYouRows($storeId, $orderId)
    {
        return $this->filterCustomerFacingRows(
            $this->rowsForOrder($storeId, $orderId, MtUniCreditFinancingPresentationAudience::CUSTOMER)
        );
    }

    /**
     * @param array<int, array{label: string, value: string}> $rows
     * @return array<int, array{label: string, value: string}>
     */
    public function filterCustomerFacingRows(array $rows)
    {
        $safe = array();
        foreach ($rows as $row) {
            $label = (string) (isset($row['label']) ? $row['label'] : '');
            if (
                $label === MtUniCreditFinancingLeasingPresenter::LABEL_EGN
                || $label === MtUniCreditFinancingLeasingPresenter::LABEL_PHONE2
                || $label === MtUniCreditFinancingLeasingPresenter::LABEL_CP_INTERNAL_ID
                || $label === MtUniCreditFinancingLeasingPresenter::LABEL_CP_SHOP_ORDER_ID
            ) {
                continue;
            }
            $safe[] = $row;
        }

        return $safe;
    }

    /**
     * @param array<int, array{label: string, value: string}> $rows
     * @return string
     */
    public function renderCustomerThankYouHtml(array $rows)
    {
        if ($rows === array()) {
            return '';
        }

        return $this->presenter->renderHtml($rows, MtUniCreditFinancingLeasingPresenter::TITLE);
    }

    /**
     * @param int $storeId
     * @param int $orderId
     * @return string
     */
    public function customerThankYouHtml($storeId, $orderId)
    {
        return $this->renderCustomerThankYouHtml($this->customerThankYouRows($storeId, $orderId));
    }

    /**
     * @param int $storeId
     * @param int $orderId
     * @return MtUniCreditProcessTwoSensitiveData|null
     */
    private function decryptSensitive($storeId, $orderId)
    {
        $row = $this->repository->findAttemptRowByOrderId((int) $storeId, (int) $orderId);
        if ($row === null) {
            return null;
        }
        $enc = (string) (isset($row['process2_sensitive_enc']) ? $row['process2_sensitive_enc'] : '');
        if ($enc === '') {
            return null;
        }
        try {
            return (new MtUniCreditProcessTwoSensitiveCipher())->decrypt($enc);
        } catch (Throwable $ignored) {
            error_log('mt_uni_credit: leasing presentation sensitive decrypt failed order_id=' . (int) $orderId);

            return null;
        }
    }
}
