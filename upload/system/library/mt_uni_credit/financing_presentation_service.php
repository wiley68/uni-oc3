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

        return $this->presenter->rows($snapshot, $status, (string) $audience, null);
    }

    /**
     * Customer Thank You HTML — never EGN/phone2; omit CP internal ids from page body.
     *
     * @param int $storeId
     * @param int $orderId
     * @return string
     */
    public function customerThankYouHtml($storeId, $orderId)
    {
        $rows = $this->rowsForOrder($storeId, $orderId, MtUniCreditFinancingPresentationAudience::CUSTOMER);
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
        if ($safe === array()) {
            return '';
        }

        return $this->presenter->renderHtml($safe, MtUniCreditFinancingLeasingPresenter::TITLE);
    }
}
