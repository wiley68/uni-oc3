<?php

require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';

class ControllerExtensionPaymentMtUniCredit extends Controller
{
    public function index()
    {
        $this->load->language('extension/payment/mt_uni_credit');
        $this->load->model('extension/payment/mt_uni_credit');

        $panel = $this->model_extension_payment_mt_uni_credit->presentCheckoutFinancingPanel();
        if ($panel === null) {
            return '';
        }

        return $this->load->view(
            'extension/payment/mt_uni_credit',
            $this->buildCheckoutPanelViewData($panel)
        );
    }

    /**
     * Checkout payment panel view payload (extracted so IDE type inference stays within limits).
     *
     * @param array<string, mixed> $panel
     * @return array<string, mixed>
     */
    private function buildCheckoutPanelViewData(array $panel)
    {
        $calculator = isset($panel['calculator']) && is_array($panel['calculator'])
            ? $panel['calculator']
            : array();
        $modal = isset($panel['modal']) && is_array($panel['modal']) ? $panel['modal'] : array();
        $storeId = (int) $this->config->get('config_store_id');
        $selection = MtUniCreditCheckoutSchemeSelection::resolveInitialSchemeSelection(
            $calculator,
            $this->session->data,
            $storeId
        );
        $initialSchemeKey = isset($selection['key']) ? $selection['key'] : null;
        $calculator = $this->applyCheckoutBuyPreference($calculator, $selection, $initialSchemeKey);

        $assets = $this->resolveCheckoutAssetUrls();
        $process2 = !empty($modal['process2']);
        $consents = isset($modal['consents']) && is_array($modal['consents']) ? $modal['consents'] : array();
        $csrfToken = MtUniCreditStorefrontCsrf::issue($this->session->data);
        $orderId = (int) (isset($panel['order_id']) ? $panel['order_id'] : 0);

        $data = $this->checkoutPanelLanguageData();
        $data['order_id'] = $orderId;
        $data['process2'] = $process2;
        $data['consents'] = $consents;
        $data['show_first_installment'] = !empty($calculator['show_first_installment']);
        $data['checkout_helper'] = $this->language->get(
            $process2 ? 'text_checkout_helper_process2' : 'text_checkout_helper_process1'
        );
        $data['scheme_options'] = MtUniCreditCheckoutSchemeSelection::buildCheckoutSchemeOptions(
            $calculator,
            $initialSchemeKey
        );
        $data['fonts_href'] = $assets['fonts'];
        $data['product_css_href'] = $assets['css'];
        $data['checkout_css_href'] = $assets['checkout_css'];
        $data['script_href'] = $assets['checkout_js'];
        $data['mt_uni_credit_bootstrap_json'] = $this->encodeCheckoutBootstrapJson(
            $orderId,
            $calculator,
            $process2,
            $consents,
            $csrfToken,
            $assets
        );

        return $data;
    }

    /**
     * @param array<string, mixed> $calculator
     * @param array<string, mixed> $selection
     * @param string|null $initialSchemeKey
     * @return array<string, mixed>
     */
    private function applyCheckoutBuyPreference(array $calculator, array $selection, $initialSchemeKey)
    {
        if (empty($selection['buy_matched']) || !$initialSchemeKey) {
            return $calculator;
        }

        foreach (array('standard', 'promo') as $offerType) {
            if (isset($calculator['offers'][$offerType]) && is_array($calculator['offers'][$offerType])) {
                $calculator['offers'][$offerType]['preferred_scheme_key'] = $initialSchemeKey;
            }
        }
        $calculator['buy_preference_scheme_key'] = $initialSchemeKey;

        return $calculator;
    }

    /**
     * @return array{fonts:string,css:string,checkout_css:string,checkout_js:string}
     */
    private function resolveCheckoutAssetUrls()
    {
        $assets = MtUniCreditStorefrontRuntime::assetUrls($this);
        $base = '';
        if (defined('DIR_APPLICATION')) {
            $base = rtrim(DIR_APPLICATION, '/\\') . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'theme'
                . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR . 'template' . DIRECTORY_SEPARATOR
                . 'extension' . DIRECTORY_SEPARATOR . 'payment' . DIRECTORY_SEPARATOR;
        }

        return array(
            'fonts' => $assets['fonts'],
            'css' => $assets['css'],
            'checkout_css' => MtUniCreditStorefrontAssetUrls::versionedUrl(
                $base . 'mt_uni_credit_checkout.css',
                MtUniCreditConstants::CHECKOUT_ASSET_CSS_RELATIVE
            ),
            'checkout_js' => MtUniCreditStorefrontAssetUrls::versionedUrl(
                $base . 'mt_uni_credit_checkout.js',
                MtUniCreditConstants::CHECKOUT_ASSET_JS_RELATIVE
            ),
        );
    }

    /**
     * @return array<string, string>
     */
    private function checkoutPanelLanguageData()
    {
        $keys = array(
            'heading_title',
            'button_confirm',
            'text_loading',
            'text_price',
            'text_months',
            'text_months_short',
            'text_first_installment',
            'text_financed_amount',
            'text_monthly_installment',
            'text_monthly_installment_short',
            'text_total_payable',
            'text_glp',
            'text_gpr',
            'text_consents',
            'text_egn',
            'text_phone2',
            'text_required',
            'text_processing_title',
            'text_processing_message',
            'error_unavailable',
            'error_consent',
        );
        $data = array();
        foreach ($keys as $key) {
            $data[$key] = $this->language->get($key);
        }

        return $data;
    }

    /**
     * @param int $orderId
     * @param array<string, mixed> $calculator
     * @param bool $process2
     * @param array<int, array<string, mixed>> $consents
     * @param string $csrfToken
     * @param array{fonts:string,css:string,checkout_css:string,checkout_js:string} $assets
     * @return string
     */
    private function encodeCheckoutBootstrapJson(
        $orderId,
        array $calculator,
        $process2,
        array $consents,
        $csrfToken,
        array $assets
    ) {
        return json_encode(array(
            'source' => 'checkout',
            'order_id' => (int) $orderId,
            'calculator' => $calculator,
            'modal' => array(
                'process2' => (bool) $process2,
                'consents' => $consents,
            ),
            'csrf_token' => (string) $csrfToken,
            'confirm_url' => $this->url->link('extension/payment/mt_uni_credit/confirm', '', true),
            'calculate_url' => $this->url->link('extension/payment/mt_uni_credit/calculate', '', true),
            'recalculate_url' => $this->url->link('extension/payment/mt_uni_credit/recalculate', '', true),
            'fonts_href' => $assets['fonts'],
            'product_css_href' => $assets['css'],
            'checkout_css_href' => $assets['checkout_css'],
            'script_href' => $assets['checkout_js'],
            'i18n' => array(
                'order_changed' => (string) $this->language->get('error_order_changed'),
                'order_missing' => (string) $this->language->get('error_order'),
                'confirm' => (string) $this->language->get('button_confirm'),
                'processing' => (string) $this->language->get('text_processing_message'),
                'consent' => (string) $this->language->get('error_consent'),
                'unavailable' => (string) $this->language->get('error_unavailable'),
            ),
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function calculate()
    {
        $this->load->language('extension/payment/mt_uni_credit');
        $json = array('success' => false, 'unavailable' => true);

        if (!$this->isPost() || !MtUniCreditStorefrontCsrf::verify($this->session->data, $this->posted('csrf_token'))) {
            $this->respondJson($json);
            return;
        }

        if (!$this->isUniCreditPaymentSelected()) {
            $json['error'] = $this->language->get('error_unavailable');
            $this->respondJson($json);
            return;
        }

        $this->load->model('extension/payment/mt_uni_credit');
        $panel = $this->model_extension_payment_mt_uni_credit->presentCheckoutFinancingPanel();
        if ($panel === null) {
            $this->respondJson($json);
            return;
        }

        $this->respondJson(array(
            'success' => true,
            'sequence' => (int) $this->posted('sequence', 0),
            'calculator' => $panel['calculator'],
        ));
    }

    public function recalculate()
    {
        $this->load->language('extension/payment/mt_uni_credit');
        $json = array(
            'success' => false,
            'message' => $this->language->get('error_unavailable'),
        );

        if (!$this->isPost() || !MtUniCreditStorefrontCsrf::verify($this->session->data, $this->posted('csrf_token'))) {
            $this->respondJson($json);
            return;
        }

        if (!$this->isUniCreditPaymentSelected()) {
            $json['error'] = $this->language->get('error_unavailable');
            $this->respondJson($json);
            return;
        }

        $this->load->model('extension/payment/mt_uni_credit');
        $calculation = $this->model_extension_payment_mt_uni_credit->recalculateCheckoutSelection(
            trim((string) $this->posted('scheme_key', '')),
            (float) str_replace(',', '.', (string) $this->posted('first_installment', '0'))
        );
        if ($calculation === null) {
            $json['unavailable'] = true;
            $this->respondJson($json);
            return;
        }

        $this->respondJson(array(
            'success' => true,
            'sequence' => (int) $this->posted('sequence', 0),
            'calculation' => $calculation,
        ));
    }

    public function confirm()
    {
        $this->load->language('extension/payment/mt_uni_credit');
        $json = array();

        if (!$this->isUniCreditPaymentSelected()) {
            $json['error'] = $this->language->get('error_unavailable');
            $this->respondJson($json);
            return;
        }

        if (!$this->isPost()) {
            $json['error'] = $this->language->get('error_unavailable');
            $this->respondJson($json);
            return;
        }

        $csrf = $this->posted('csrf_token', $this->posted('csrf', ''));
        if (!MtUniCreditStorefrontCsrf::verify($this->session->data, $csrf)) {
            $json['error'] = $this->language->get('error_submit_token');
            $this->respondJson($json);
            return;
        }

        $this->load->model('extension/payment/mt_uni_credit');
        $panel = $this->model_extension_payment_mt_uni_credit->presentCheckoutFinancingPanel();
        if ($panel === null) {
            $json['error'] = $this->language->get('error_unavailable');
            $this->respondJson($json);
            return;
        }

        $shop = isset($panel['shop']) && is_array($panel['shop']) ? $panel['shop'] : array();
        $consents = (new MtUniCreditStorefrontConsentResolver())->normalize($shop);
        $postedConsent = isset($this->request->post['consent']) ? $this->request->post['consent'] : array();
        if ($consents !== array() && !(new MtUniCreditStorefrontConsentResolver())->isSatisfied($shop, $postedConsent)) {
            $json['error'] = $this->language->get('error_consent');
            $json['error_code'] = 'consent';
            $this->respondJson($json);
            return;
        }

        $schemeKey = trim((string) $this->posted('scheme_key', ''));
        if ($schemeKey === '' || MtUniCreditStorefrontCalculatorPresenter::parseSchemeKey($schemeKey) === null) {
            $json['error'] = $this->language->get('error_unavailable');
            $this->respondJson($json);
            return;
        }

        $result = $this->model_extension_payment_mt_uni_credit->prepareCheckoutConfirm();
        if (empty($result['success'])) {
            $json['error'] = $this->mapConfirmError(isset($result['error']) ? (string) $result['error'] : 'request_failed');
            $this->respondJson($json);
            return;
        }

        if (isset($result['prepared_order_id'])) {
            $this->session->data[MtUniCreditCheckoutConfirmPreparation::SESSION_PREPARED_ORDER_ID] = (int) $result['prepared_order_id'];
        }

        $orderId = (int) (isset($this->session->data['order_id']) ? $this->session->data['order_id'] : 0);
        $process2 = array(
            'egn' => (string) $this->posted('egn', ''),
            'phone2' => (string) $this->posted('phone2', ''),
        );
        $selection = array(
            'scheme_key' => $schemeKey,
            'first_installment' => (float) str_replace(',', '.', (string) $this->posted('first_installment', '0')),
        );

        $submit = $this->model_extension_payment_mt_uni_credit->submitCheckoutFinancing(
            $orderId,
            $process2,
            $selection
        );

        // Native status after durable P1/P2 handoff only (OC4 Checkout success boundary).
        // Do not gate on apply_native_order_status alone — localReplay after CP_CREATED
        // suppresses that flag and left successful Checkout orders at status 0.
        $this->maybeApplyCheckoutNativeOrderStatusAfterHandoff($orderId, $submit);

        if (!empty($submit['success'])) {
            // Success-only live cart clear AFTER durable bank handoff (P1/P2).
            // Pass $this->cart directly — never use isset on cart (OC3 Registry trap).
            MtUniCreditFinancingTerminalNavigationSupport::clearCartAfterSuccessfulHandoff(
                $submit,
                $this->cart
            );
        }

        if (!empty($submit['success']) && !empty($submit['bank_redirect']) && !empty($submit['redirect'])) {
            $json['redirect'] = (string) $submit['redirect'];
            $json['success'] = true;
            $json['bank_status'] = isset($submit['bank_status']) ? (string) $submit['bank_status'] : '';
            $this->respondJson($json);
            return;
        }

        if (!empty($submit['success'])) {
            $payload = MtUniCreditFinancingTerminalNavigationSupport::enrichProcess2ThankYou(
                array(
                    'success' => true,
                    'redirect' => isset($submit['redirect']) ? (string) $submit['redirect'] : '',
                    'bank_redirect' => false,
                ),
                $this->session->data,
                $orderId,
                $this->url->link(MtUniCreditConstants::CHECKOUT_SUCCESS_ROUTE, '', true)
            );
            $json['success'] = true;
            $json['redirect'] = (string) $payload['redirect'];
            $json['bank_status'] = isset($submit['bank_status']) ? (string) $submit['bank_status'] : '';
            $this->respondJson($json);
            return;
        }

        $json['error'] = isset($submit['message']) && is_string($submit['message'])
            ? (string) $submit['message']
            : $this->language->get('error_unavailable');
        if (isset($submit['error'])) {
            $json['error_code'] = (string) $submit['error'];
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

        $this->maybeApplyCheckoutNativeOrderStatusAfterHandoff((int) $context['order_id'], $result);

        if (!empty($result['success'])) {
            MtUniCreditFinancingTerminalNavigationSupport::clearCartAfterSuccessfulHandoff(
                $result,
                $this->cart
            );
        }

        if (empty($result['success']) && isset($result['message']) && is_string($result['message'])) {
            $this->session->data['mt_uni_credit_checkout_flash'] = (string) $result['message'];
        }

        if (!empty($result['success']) && !empty($result['bank_redirect']) && !empty($result['redirect'])) {
            $this->response->redirect((string) $result['redirect']);
            return;
        }
        if (!empty($result['success'])) {
            $payload = MtUniCreditFinancingTerminalNavigationSupport::enrichProcess2ThankYou(
                array(
                    'success' => true,
                    'redirect' => isset($result['redirect']) ? (string) $result['redirect'] : '',
                    'bank_redirect' => false,
                ),
                $this->session->data,
                (int) $context['order_id'],
                $this->url->link(MtUniCreditConstants::CHECKOUT_SUCCESS_ROUTE, '', true)
            );
            $this->response->redirect((string) $payload['redirect']);
            return;
        }

        $this->response->redirect($this->url->link(MtUniCreditConstants::CHECKOUT_PREPARED_ROUTE, '', true));
    }

    /**
     * Apply configured payment order status after durable Checkout bank handoff.
     *
     * Root cause of Missing Orders: success with localReplay (CP already created)
     * returned apply_native_order_status=false, so addOrderHistory never ran and
     * the native OC order stayed at status 0.
     *
     * @param int $orderId
     * @param array<string, mixed> $submit
     * @return void
     */
    private function maybeApplyCheckoutNativeOrderStatusAfterHandoff($orderId, array $submit)
    {
        if (!MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff($submit)) {
            return;
        }
        $this->applyPreparedOrderStatus((int) $orderId);
    }

    /**
     * @param int $orderId
     * @return void
     */
    private function applyPreparedOrderStatus($orderId)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            return;
        }
        $this->load->model('checkout/order');
        $statusId = (int) $this->config->get(MtUniCreditConstants::PAYMENT_SETTING_ORDER_STATUS_ID);
        if ($statusId <= 0 || !method_exists($this->model_checkout_order, 'addOrderHistory')) {
            return;
        }

        $order = $this->model_checkout_order->getOrder($orderId);
        if (!is_array($order)) {
            return;
        }
        $current = (int) (isset($order['order_status_id']) ? $order['order_status_id'] : 0);
        // Idempotent: replay / localReplay must not insert a second history row or re-mail.
        if ($current === $statusId) {
            return;
        }

        $this->model_checkout_order->addOrderHistory($orderId, $statusId);
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
     * @return bool
     */
    private function isUniCreditPaymentSelected()
    {
        return isset($this->session->data['payment_method']['code'])
            && $this->session->data['payment_method']['code'] === MtUniCreditConstants::EXTENSION_CODE;
    }

    /**
     * @return bool
     */
    private function isPost()
    {
        $method = isset($this->request->server['REQUEST_METHOD'])
            ? strtoupper((string) $this->request->server['REQUEST_METHOD'])
            : 'GET';

        return $method === 'POST';
    }

    /**
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    private function posted($key, $default = '')
    {
        return isset($this->request->post[$key]) ? $this->request->post[$key] : $default;
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
