<?php

require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';

/**
 * Native checkout/success hooks: stash order_id, inject customer leasing Thank You block.
 *
 * OC3 event callbacks receive (&$route, &$data) or (&$route, &$data, &$code|&$output)
 * via Action::execute + call_user_func_array — reference params required.
 */
class ControllerExtensionMtUniCreditCheckoutSuccess extends Controller
{
    /**
     * catalog/controller/checkout/success/before — stash before native unset.
     *
     * OC3 Router passes (&$route, &$data) where $data may be uninitialized.
     *
     * @param string $route
     * @param mixed $data
     * @return void
     */
    public function before(&$route, &$data)
    {
        error_log('mt_uni_credit: thankyou_stash event entered route=' . (string) $route);
        if ((string) $route !== 'checkout/success') {
            return;
        }
        $orderId = (int) (isset($this->session->data['order_id']) ? $this->session->data['order_id'] : 0);
        if ($orderId > 0) {
            $this->session->data[MtUniCreditFinancingTerminalNavigationSupport::SESSION_SUCCESS_ORDER_ID] = $orderId;
            error_log('mt_uni_credit: thankyou_stash order_id=' . $orderId);
        } else {
            $existing = (int) (isset($this->session->data[MtUniCreditFinancingTerminalNavigationSupport::SESSION_SUCCESS_ORDER_ID])
                ? $this->session->data[MtUniCreditFinancingTerminalNavigationSupport::SESSION_SUCCESS_ORDER_ID]
                : 0);
            error_log('mt_uni_credit: thankyou_stash no session.order_id existing_success_id=' . $existing);
        }
    }

    /**
     * catalog/view/common/success/before — append leasing inside text_message.
     *
     * @param string $route
     * @param array $data
     * @param string $code
     * @return void
     */
    public function beforeView(&$route, &$data, &$code)
    {
        if ((string) $route !== 'common/success') {
            return;
        }
        if (!is_array($data)) {
            return;
        }

        $html = $this->buildCustomerLeasingBlock();
        if ($html === '') {
            return;
        }
        $existing = (string) (isset($data['text_message']) ? $data['text_message'] : '');
        if (strpos($existing, 'mt-uni-credit-leasing-block') !== false) {
            return;
        }
        $data['text_message'] = $existing . $html;
        error_log('mt_uni_credit: thankyou beforeView injected');
    }

    /**
     * catalog/view/common/success/after — belt-and-suspenders if before refs did not apply.
     *
     * @param string $route
     * @param array $data
     * @param string $output
     * @return void
     */
    public function afterView(&$route, &$data, &$output)
    {
        if ((string) $route !== 'common/success') {
            return;
        }
        if (!is_string($output) || $output === '') {
            return;
        }
        if (strpos($output, 'mt-uni-credit-leasing-block') !== false) {
            return;
        }

        $html = $this->buildCustomerLeasingBlock();
        if ($html === '') {
            return;
        }

        // Prefer insert before continue buttons; otherwise append before footer.
        if (strpos($output, 'class="buttons"') !== false) {
            $output = str_replace('<div class="buttons">', $html . '<div class="buttons">', $output);
        } elseif (strpos($output, '{{ footer }}') === false && strpos($output, '</div>') !== false) {
            $output .= $html;
        } else {
            $output .= $html;
        }
        error_log('mt_uni_credit: thankyou afterView injected');
    }

    /**
     * @return string HTML block or empty
     */
    private function buildCustomerLeasingBlock()
    {
        $orderId = $this->resolveSuccessOrderId();
        error_log('mt_uni_credit: thankyou resolve order_id=' . $orderId);
        if ($orderId <= 0) {
            error_log('mt_uni_credit: thankyou abort reason=order_id_missing');
            return '';
        }

        $storeId = (int) $this->config->get('config_store_id');
        $checks = $this->ownershipChecks($storeId, $orderId);
        error_log(
            'mt_uni_credit: thankyou checks order_exists=' . ($checks['order_exists'] ? 'yes' : 'no')
                . ' store_match=' . ($checks['store_match'] ? 'yes' : 'no')
                . ' ownership=' . ($checks['ok'] ? 'yes' : 'no')
        );
        if (!$checks['ok']) {
            return '';
        }

        try {
            $db = MtUniCreditBootstrap::dbFromRegistry($this->db);
            $repo = new MtUniCreditFinancingPresentationRepository($db);
            $snapshot = $repo->findByOrderId($storeId, $orderId);
            error_log(
                'mt_uni_credit: thankyou presentation store_id=' . $storeId
                    . ' order_id=' . $orderId
                    . ' row_exists=' . ($snapshot !== null ? 'yes' : 'no')
                    . ' snapshot_nonempty=' . ($snapshot !== null ? 'yes' : 'no')
            );
            $service = new MtUniCreditFinancingPresentationService($repo);
            $rows = $service->customerThankYouRows($storeId, $orderId);
            error_log(
                'mt_uni_credit: thankyou row_count=' . count($rows)
                    . ' customer_safe=1 order_id=' . $orderId
            );
            if ($rows === array()) {
                return '';
            }
            $html = $service->renderCustomerThankYouHtml($rows);
            $htmlLen = strlen((string) $html);
            error_log('mt_uni_credit: thankyou html_length=' . $htmlLen);
            if ($html === '' || !$this->isCustomerPresentationSafe($html)) {
                if (!$this->isCustomerPresentationSafe($html)) {
                    error_log('mt_uni_credit: blocked Thank You leasing HTML containing Process 2 sensitive fields');
                }

                return '';
            }

            unset($this->session->data[MtUniCreditFinancingTerminalNavigationSupport::SESSION_SUCCESS_ORDER_ID]);

            return '<div class="mt-uni-credit-checkout-success">' . $html . '</div>';
        } catch (Exception $exception) {
            error_log('mt_uni_credit: thank-you leasing block failed class=' . get_class($exception));

            return '';
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
     * @param int $storeId
     * @param int $orderId
     * @return array{ok:bool,order_exists:bool,store_match:bool}
     */
    private function ownershipChecks($storeId, $orderId)
    {
        // OC3 Registry: never gate customer via isset() — Controller has no __isset().
        if (!is_object($this->customer) || !method_exists($this->customer, 'isLogged')) {
            return array('ok' => true, 'order_exists' => true, 'store_match' => true);
        }
        if (!$this->customer->isLogged()) {
            return array('ok' => true, 'order_exists' => true, 'store_match' => true);
        }
        $customerId = (int) $this->customer->getId();
        if ($customerId <= 0) {
            return array('ok' => false, 'order_exists' => false, 'store_match' => false);
        }

        $result = $this->db->query(
            "SELECT `customer_id`, `store_id` FROM `" . DB_PREFIX . "order`"
                . " WHERE `order_id` = " . (int) $orderId . " LIMIT 1"
        );
        if (!is_object($result) || empty($result->num_rows)) {
            return array('ok' => false, 'order_exists' => false, 'store_match' => false);
        }
        $orderCustomerId = (int) (isset($result->row['customer_id']) ? $result->row['customer_id'] : 0);
        $orderStoreId = (int) (isset($result->row['store_id']) ? $result->row['store_id'] : -1);
        $storeMatch = $orderStoreId === (int) $storeId;

        return array(
            'ok' => $orderCustomerId === $customerId && $storeMatch,
            'order_exists' => true,
            'store_match' => $storeMatch,
        );
    }

    /**
     * @param int $storeId
     * @param int $orderId
     * @return bool
     */
    private function canPresentOrderToCurrentCustomer($storeId, $orderId)
    {
        $checks = $this->ownershipChecks($storeId, $orderId);

        return !empty($checks['ok']);
    }

    /**
     * @param string $html
     * @return bool
     */
    private function isCustomerPresentationSafe($html)
    {
        if ($html === '') {
            return true;
        }
        if (strpos($html, MtUniCreditFinancingLeasingPresenter::LABEL_EGN) !== false) {
            return false;
        }
        if (strpos($html, MtUniCreditFinancingLeasingPresenter::LABEL_PHONE2) !== false) {
            return false;
        }

        return true;
    }
}
