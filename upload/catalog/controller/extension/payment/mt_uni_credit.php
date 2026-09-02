<?php

require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';

class ControllerExtensionPaymentMtUniCredit extends Controller
{
    public function index()
    {
        $this->load->language('extension/payment/mt_uni_credit');

        $data = array(
            'text_instruction' => $this->language->get('text_instruction'),
            'button_confirm' => $this->language->get('button_confirm'),
            'text_loading' => $this->language->get('text_loading'),
            'error_unavailable' => $this->language->get('error_unavailable'),
        );

        return $this->load->view('extension/payment/mt_uni_credit', $data);
    }

    public function confirm()
    {
        $this->load->language('extension/payment/mt_uni_credit');
        $json = array();

        if (
            !isset($this->session->data['payment_method']['code'])
            || $this->session->data['payment_method']['code'] !== MtUniCreditConstants::EXTENSION_CODE
        ) {
            $json['error'] = $this->language->get('error_unavailable');
            $this->respondJson($json);
            return;
        }

        $this->load->model('extension/payment/mt_uni_credit');
        $result = $this->model_extension_payment_mt_uni_credit->prepareCheckoutConfirm();

        if (!empty($result['success'])) {
            if (isset($result['prepared_order_id'])) {
                $this->session->data[MtUniCreditCheckoutConfirmPreparation::SESSION_PREPARED_ORDER_ID] = (int) $result['prepared_order_id'];
            }
            $continuationRoute = isset($result['continuation_route'])
                ? (string) $result['continuation_route']
                : MtUniCreditConstants::CHECKOUT_PREPARED_ROUTE;
            $json['redirect'] = $this->url->link($continuationRoute, '', true);
        } else {
            $json['error'] = $this->mapConfirmError(isset($result['error']) ? (string) $result['error'] : 'request_failed');
        }

        $this->respondJson($json);
    }

    public function prepared()
    {
        $this->load->language('extension/payment/mt_uni_credit');

        $orderId = (int) (isset($this->session->data['order_id']) ? $this->session->data['order_id'] : 0);
        $preparedOrderId = (int) (isset($this->session->data[MtUniCreditCheckoutConfirmPreparation::SESSION_PREPARED_ORDER_ID])
            ? $this->session->data[MtUniCreditCheckoutConfirmPreparation::SESSION_PREPARED_ORDER_ID]
            : 0);
        $storeId = (int) $this->config->get('config_store_id');

        $this->load->model('checkout/order');
        $this->load->model('extension/payment/mt_uni_credit');
        $order = $orderId > 0 ? $this->model_checkout_order->getOrder($orderId) : null;

        $attempt = null;
        if ($orderId > 0) {
            $db = MtUniCreditBootstrap::dbFromRegistry($this->db);
            $attempt = (new MtUniCreditFinancingAttemptRepository($db))->findByStoreOrder($storeId, $orderId);
        }

        $access = MtUniCreditCheckoutPreparedBoundary::validateAccess(
            $orderId,
            $preparedOrderId,
            $order,
            $storeId,
            $attempt
        );
        if (empty($access['ok'])) {
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
            return;
        }

        $result = $this->model_extension_payment_mt_uni_credit->submitCheckoutFinancing($orderId);

        if (!empty($result['success']) && !empty($result['apply_native_order_status'])) {
            $statusId = (int) $this->config->get(MtUniCreditConstants::PAYMENT_SETTING_ORDER_STATUS_ID);
            if ($statusId > 0 && method_exists($this->model_checkout_order, 'addOrderHistory')) {
                $this->model_checkout_order->addOrderHistory($orderId, $statusId);
            }
        }

        $this->document->setTitle($this->language->get('heading_prepared_title'));

        $data = array();
        $data['breadcrumbs'] = array();
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/home'),
        );
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_checkout'),
            'href' => $this->url->link('checkout/checkout', '', true),
        );
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_prepared_title'),
            'href' => $this->url->link(MtUniCreditConstants::CHECKOUT_PREPARED_ROUTE, '', true),
        );

        $data['heading_title'] = $this->language->get('heading_prepared_title');
        $data['success'] = !empty($result['success']);
        $data['ambiguous'] = !empty($result['ambiguous_blocked']);
        $data['message'] = isset($result['message'])
            ? (string) $result['message']
            : $this->language->get('text_prepared_not_submitted');
        $data['text_continue_checkout'] = $this->language->get('text_continue_checkout');
        $data['text_continue_shopping'] = $this->language->get('text_continue_shopping');
        $data['continue'] = !empty($result['success'])
            ? $this->url->link('common/home', '', true)
            : $this->url->link('checkout/checkout', '', true);

        $data['column_left'] = $this->load->controller('common/column_left');
        $data['column_right'] = $this->load->controller('common/column_right');
        $data['content_top'] = $this->load->controller('common/content_top');
        $data['content_bottom'] = $this->load->controller('common/content_bottom');
        $data['footer'] = $this->load->controller('common/footer');
        $data['header'] = $this->load->controller('common/header');

        $this->response->setOutput($this->load->view('extension/payment/mt_uni_credit_prepared', $data));
    }

    /**
     * @param string $code
     * @return string
     */
    private function mapConfirmError($code)
    {
        $map = array(
            'order_missing' => 'error_order',
            'order_changed' => 'error_order_changed',
            'order_store_mismatch' => 'error_order',
            'order_already_processed' => 'error_unavailable',
            'payment_method_mismatch' => 'error_unavailable',
            'unavailable' => 'error_unavailable',
            'duplicate_request' => 'error_duplicate_request',
        );

        $languageKey = isset($map[$code]) ? $map[$code] : 'error_unavailable';

        return $this->language->get($languageKey);
    }

    /**
     * @param array<string, mixed> $json
     * @return void
     */
    private function respondJson(array $json)
    {
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }
}
