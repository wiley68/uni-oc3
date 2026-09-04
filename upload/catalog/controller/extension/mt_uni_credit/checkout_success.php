<?php

require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';

/**
 * Native checkout/success hooks: stash order_id, inject customer leasing Thank You block.
 */
class ControllerExtensionMtUniCreditCheckoutSuccess extends Controller
{
    /**
     * catalog/controller/checkout/success/before — stash before native unset.
     *
     * @param string $route
     * @param array<string, mixed> $data
     * @return void
     */
    public function before(&$route, &$data)
    {
        if ($route !== 'checkout/success') {
            return;
        }
        $orderId = (int) (isset($this->session->data['order_id']) ? $this->session->data['order_id'] : 0);
        if ($orderId > 0) {
            $this->session->data[MtUniCreditFinancingTerminalNavigationSupport::SESSION_SUCCESS_ORDER_ID] = $orderId;
        }
    }

    /**
     * catalog/view/common/success/before — append leasing inside text_message.
     *
     * @param string $route
     * @param array<string, mixed> $data
     * @param string $code
     * @return void
     */
    public function beforeView(&$route, &$data, &$code)
    {
        if ($route !== 'common/success') {
            return;
        }

        $orderId = $this->resolveSuccessOrderId();
        if ($orderId <= 0) {
            return;
        }

        $storeId = (int) $this->config->get('config_store_id');
        if (!$this->canPresentOrderToCurrentCustomer($storeId, $orderId)) {
            return;
        }

        try {
            $db = MtUniCreditBootstrap::dbFromRegistry($this->db);
            $service = new MtUniCreditFinancingPresentationService(
                new MtUniCreditFinancingPresentationRepository($db)
            );
            $html = $service->customerThankYouHtml($storeId, $orderId);
            if ($html === '') {
                // Missing snapshot: keep native success message only.
                unset(
                    $this->session->data[MtUniCreditFinancingTerminalNavigationSupport::SESSION_SUCCESS_ORDER_ID]
                );

                return;
            }
            if (!$this->isCustomerPresentationSafe($html)) {
                error_log('mt_uni_credit: blocked Thank You leasing HTML containing Process 2 sensitive fields');

                return;
            }

            $block = '<div class="mt-uni-credit-checkout-success">' . $html . '</div>';
            $data['text_message'] = (string) (isset($data['text_message']) ? $data['text_message'] : '') . $block;
            unset(
                $this->session->data[MtUniCreditFinancingTerminalNavigationSupport::SESSION_SUCCESS_ORDER_ID]
            );
        } catch (Exception $exception) {
            error_log('mt_uni_credit: thank-you leasing block failed class=' . get_class($exception));
        }
    }

    /**
     * @return int
     */
    private function resolveSuccessOrderId()
    {
        $orderId = (int) (isset($this->session->data[MtUniCreditFinancingTerminalNavigationSupport::SESSION_SUCCESS_ORDER_ID])
            ? $this->session->data[MtUniCreditFinancingTerminalNavigationSupport::SESSION_SUCCESS_ORDER_ID]
            : 0);
        if ($orderId <= 0) {
            $orderId = (int) (isset($this->session->data['order_id']) ? $this->session->data['order_id'] : 0);
        }

        return $orderId;
    }

    /**
     * Guest: allow session-owned presentation. Logged-in: order customer_id must match.
     *
     * @param int $storeId
     * @param int $orderId
     * @return bool
     */
    private function canPresentOrderToCurrentCustomer($storeId, $orderId)
    {
        if (!isset($this->customer) || !is_object($this->customer) || !method_exists($this->customer, 'isLogged')) {
            return true;
        }
        if (!$this->customer->isLogged()) {
            return true;
        }
        $customerId = (int) $this->customer->getId();
        if ($customerId <= 0) {
            return false;
        }

        $result = $this->db->query(
            "SELECT `customer_id`, `store_id` FROM `" . DB_PREFIX . "order`"
                . " WHERE `order_id` = " . (int) $orderId . " LIMIT 1"
        );
        if (!is_object($result) || empty($result->num_rows)) {
            return false;
        }
        $orderCustomerId = (int) (isset($result->row['customer_id']) ? $result->row['customer_id'] : 0);
        $orderStoreId = (int) (isset($result->row['store_id']) ? $result->row['store_id'] : -1);

        return $orderCustomerId === $customerId && $orderStoreId === (int) $storeId;
    }

    /**
     * @param string $html
     * @return bool
     */
    private function isCustomerPresentationSafe($html)
    {
        if (strpos($html, MtUniCreditFinancingLeasingPresenter::LABEL_EGN) !== false) {
            return false;
        }
        if (strpos($html, MtUniCreditFinancingLeasingPresenter::LABEL_PHONE2) !== false) {
            return false;
        }

        return true;
    }
}
