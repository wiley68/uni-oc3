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

    /**
     * GET-only prepared boundary — read attempt state, never mutate / call CP.
     */
    public function prepared()
    {
        $this->load->language('extension/payment/mt_uni_credit');

        $context = $this->resolvePreparedContext();
        if ($context === null) {
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
            return;
        }

        $view = MtUniCreditCheckoutPreparedViewState::fromAttempt($context['attempt']);
        $token = MtUniCreditCheckoutSubmitToken::issue($this->session->data);
        $flash = $this->consumeCheckoutFlash();

        $this->document->setTitle($this->language->get('heading_prepared_title'));
        $data = $this->buildPreparedViewData($view, $token, $flash);
        $this->response->setOutput($this->load->view('extension/payment/mt_uni_credit_prepared', $data));
    }

    /**
     * @return string
     */
    private function consumeCheckoutFlash()
    {
        $flash = '';
        if (
            isset($this->session->data['mt_uni_credit_checkout_flash'])
            && is_string($this->session->data['mt_uni_credit_checkout_flash'])
        ) {
            $flash = (string) $this->session->data['mt_uni_credit_checkout_flash'];
            unset($this->session->data['mt_uni_credit_checkout_flash']);
        }

        return $flash;
    }

    /**
     * @return bool
     */
    private function resolveCheckoutProcess2Flag()
    {
        try {
            $db = MtUniCreditBootstrap::dbFromRegistry($this->db);
            $settings = new MtUniCreditSettingStore($db, MtUniCreditConstants::MODULE_SETTINGS_CODE);
            $storeId = (int) $this->config->get('config_store_id');
            $stack = MtUniCreditCpServiceFactory::create(
                $db,
                $settings,
                $storeId,
                (string) $this->config->get('config_ssl'),
                (string) $this->config->get('config_url')
            );
            $unicid = $stack['credentials']->getUnicid($storeId);
            if ($unicid === '') {
                return false;
            }
            $shop = MtUniCreditBootstrap::shopConfigurationCacheFromDb($db)->getFreshShopData($storeId, $unicid);

            return is_array($shop) && MtUniCreditShopConfigurationFlags::isSecondaryProcess($shop);
        } catch (Exception $ignored) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $view
     * @param string $token
     * @param string $flash
     * @return array<string, mixed>
     */
    private function buildPreparedViewData(array $view, $token, $flash)
    {
        $data = array(
            'breadcrumbs' => array(
                array(
                    'text' => $this->language->get('text_home'),
                    'href' => $this->url->link('common/home'),
                ),
                array(
                    'text' => $this->language->get('text_checkout'),
                    'href' => $this->url->link('checkout/checkout', '', true),
                ),
                array(
                    'text' => $this->language->get('heading_prepared_title'),
                    'href' => $this->url->link(MtUniCreditConstants::CHECKOUT_PREPARED_ROUTE, '', true),
                ),
            ),
            'heading_title' => $this->language->get('heading_prepared_title'),
            'success' => !empty($view['success']),
            'ambiguous' => !empty($view['ambiguous']),
            'can_submit' => !empty($view['can_submit']),
            'mode' => $view['mode'],
            'message' => $flash !== '' ? $flash : $this->language->get($view['message_key']),
            'text_continue_checkout' => $this->language->get('text_continue_checkout'),
            'text_continue_shopping' => $this->language->get('text_continue_shopping'),
            'button_submit_financing' => $this->language->get('button_submit_financing'),
            'button_retry_financing' => $this->language->get('button_retry_financing'),
            'action' => $this->url->link(MtUniCreditConstants::CHECKOUT_SUBMIT_ROUTE, '', true),
            'submit_token' => $token,
            'process2' => $this->resolveCheckoutProcess2Flag(),
            'text_egn' => $this->language->get('text_egn'),
            'text_phone2' => $this->language->get('text_phone2'),
            'text_required' => '*',
            'continue' => !empty($view['success'])
                ? $this->url->link('common/home', '', true)
                : $this->url->link('checkout/checkout', '', true),
            'column_left' => $this->load->controller('common/column_left'),
            'column_right' => $this->load->controller('common/column_right'),
            'content_top' => $this->load->controller('common/content_top'),
            'content_bottom' => $this->load->controller('common/content_bottom'),
            'footer' => $this->load->controller('common/footer'),
            'header' => $this->load->controller('common/header'),
        );

        return $data;
    }

    /**
     * POST-only financing submit — PRG back to prepared.
     */
    public function submit()
    {
        $this->load->language('extension/payment/mt_uni_credit');

        $method = isset($this->request->server['REQUEST_METHOD'])
            ? strtoupper((string) $this->request->server['REQUEST_METHOD'])
            : 'GET';
        if ($method !== 'POST') {
            $this->response->redirect($this->url->link(MtUniCreditConstants::CHECKOUT_PREPARED_ROUTE, '', true));
            return;
        }

        $token = isset($this->request->post['mt_uni_credit_submit_token'])
            ? $this->request->post['mt_uni_credit_submit_token']
            : '';
        if (!MtUniCreditCheckoutSubmitToken::verify($this->session->data, $token)) {
            $this->session->data['mt_uni_credit_checkout_flash'] = $this->language->get('error_submit_token');
            $this->response->redirect($this->url->link(MtUniCreditConstants::CHECKOUT_PREPARED_ROUTE, '', true));
            return;
        }

        $context = $this->resolvePreparedContext();
        if ($context === null) {
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
            return;
        }

        $viewBefore = MtUniCreditCheckoutPreparedViewState::fromAttempt($context['attempt']);
        if (empty($viewBefore['can_submit'])) {
            $this->response->redirect($this->url->link(MtUniCreditConstants::CHECKOUT_PREPARED_ROUTE, '', true));
            return;
        }

        $this->load->model('extension/payment/mt_uni_credit');
        $this->load->model('checkout/order');
        $process2 = array(
            'egn' => isset($this->request->post['egn']) ? (string) $this->request->post['egn'] : '',
            'phone2' => isset($this->request->post['phone2']) ? (string) $this->request->post['phone2'] : '',
        );
        $result = $this->model_extension_payment_mt_uni_credit->submitCheckoutFinancing(
            (int) $context['order_id'],
            $process2
        );

        if (!empty($result['apply_native_order_status'])) {
            $statusId = (int) $this->config->get(MtUniCreditConstants::PAYMENT_SETTING_ORDER_STATUS_ID);
            if ($statusId > 0 && method_exists($this->model_checkout_order, 'addOrderHistory')) {
                $this->model_checkout_order->addOrderHistory((int) $context['order_id'], $statusId);
            }
        }

        if (empty($result['success']) && isset($result['message']) && is_string($result['message'])) {
            $this->session->data['mt_uni_credit_checkout_flash'] = (string) $result['message'];
        }

        if (!empty($result['success']) && !empty($result['bank_redirect']) && !empty($result['redirect'])) {
            $this->response->redirect((string) $result['redirect']);
            return;
        }
        if (!empty($result['success']) && !empty($result['redirect'])) {
            $this->response->redirect((string) $result['redirect']);
            return;
        }

        $this->response->redirect($this->url->link(MtUniCreditConstants::CHECKOUT_PREPARED_ROUTE, '', true));
    }

    /**
     * @return array{order_id: int, prepared_order_id: int, store_id: int, order: array<string, mixed>, attempt: array<string, mixed>|null}|null
     */
    private function resolvePreparedContext()
    {
        $orderId = (int) (isset($this->session->data['order_id']) ? $this->session->data['order_id'] : 0);
        $preparedOrderId = (int) (isset($this->session->data[MtUniCreditCheckoutConfirmPreparation::SESSION_PREPARED_ORDER_ID])
            ? $this->session->data[MtUniCreditCheckoutConfirmPreparation::SESSION_PREPARED_ORDER_ID]
            : 0);
        $storeId = (int) $this->config->get('config_store_id');

        $this->load->model('checkout/order');
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
        if (empty($access['ok']) || !is_array($order)) {
            return null;
        }

        return array(
            'order_id' => $orderId,
            'prepared_order_id' => $preparedOrderId,
            'store_id' => $storeId,
            'order' => $order,
            'attempt' => $attempt,
        );
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
