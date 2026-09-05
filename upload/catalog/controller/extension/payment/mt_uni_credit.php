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

        $this->recordCheckoutPreSubmitTrace('confirm_enter', array());

        if (!$this->isUniCreditPaymentSelected()) {
            $this->recordCheckoutPreSubmitTrace('payment_method_gate', array(
                'gate' => 'payment_not_selected',
            ));
            $json['error'] = $this->language->get('error_unavailable');
            $this->respondJson($json);
            return;
        }

        if (!$this->isPost()) {
            $this->recordCheckoutPreSubmitTrace('payment_method_gate', array(
                'gate' => 'not_post',
            ));
            $json['error'] = $this->language->get('error_unavailable');
            $this->respondJson($json);
            return;
        }

        $csrf = $this->posted('csrf_token', $this->posted('csrf', ''));
        if (!MtUniCreditStorefrontCsrf::verify($this->session->data, $csrf)) {
            $this->recordCheckoutPreSubmitTrace('payment_method_gate', array(
                'gate' => 'csrf',
            ));
            $json['error'] = $this->language->get('error_submit_token');
            $this->respondJson($json);
            return;
        }

        $this->load->model('extension/payment/mt_uni_credit');
        $panel = $this->model_extension_payment_mt_uni_credit->presentCheckoutFinancingPanel();
        if ($panel === null) {
            $this->recordCheckoutPreSubmitTrace('panel_present', array(
                'gate' => 'panel_null',
                'panel_available' => false,
            ));
            $json['error'] = $this->language->get('error_unavailable');
            $this->respondJson($json);
            return;
        }

        $this->recordCheckoutPreSubmitTrace('panel_present', array(
            'panel_available' => true,
        ));

        $shop = isset($panel['shop']) && is_array($panel['shop']) ? $panel['shop'] : array();
        $consents = (new MtUniCreditStorefrontConsentResolver())->normalize($shop);
        $postedConsent = isset($this->request->post['consent']) ? $this->request->post['consent'] : array();
        if ($consents !== array() && !(new MtUniCreditStorefrontConsentResolver())->isSatisfied($shop, $postedConsent)) {
            $this->recordCheckoutPreSubmitTrace('panel_present', array(
                'gate' => 'consent',
                'panel_available' => true,
            ));
            $json['error'] = $this->language->get('error_consent');
            $json['error_code'] = 'consent';
            $this->respondJson($json);
            return;
        }

        $schemeKey = trim((string) $this->posted('scheme_key', ''));
        if ($schemeKey === '' || MtUniCreditStorefrontCalculatorPresenter::parseSchemeKey($schemeKey) === null) {
            $this->recordCheckoutPreSubmitTrace('panel_present', array(
                'gate' => 'scheme_key',
                'panel_available' => true,
                'scheme_key_present' => false,
            ));
            $json['error'] = $this->language->get('error_unavailable');
            $this->respondJson($json);
            return;
        }

        $result = $this->model_extension_payment_mt_uni_credit->prepareCheckoutConfirm();
        if (empty($result['success'])) {
            $prepareError = isset($result['error']) ? (string) $result['error'] : 'request_failed';
            $this->recordCheckoutPreSubmitTrace('prepare_confirm', array(
                'gate' => 'prepare_failed',
                'prepare_error' => $prepareError,
                'panel_available' => true,
                'scheme_key_present' => true,
            ));
            $json['error'] = $this->mapConfirmError($prepareError);
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

        $this->recordCheckoutPreSubmitTrace('before_submit', array(
            'panel_available' => true,
            'scheme_key_present' => true,
            'prepared_access_ok' => true,
        ));

        $submit = $this->model_extension_payment_mt_uni_credit->submitCheckoutFinancing(
            $orderId,
            $process2,
            $selection
        );

        // Temporary Phase 11.5C.3 branch isolation — controller-time $submit shape only.
        $this->recordCheckoutCpFailureBranchTrace(
            $orderId,
            $submit,
            MtUniCreditShopConfigurationFlags::isSecondaryProcess($shop)
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

        // Session order is authoritative for Checkout; ensure shared terminal detector sees it.
        if (empty($submit['order_id']) && $orderId > 0) {
            $submit['order_id'] = $orderId;
        }

        // Product/Cart parity: definitive SmartUCF remote_reject → Thank You (not stay Checkout).
        if (MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveRemoteRejectTerminal($submit)) {
            $this->maybeApplyCheckoutNativeOrderStatusOnDefinitiveFailure($orderId, $submit);
            $json = array(
                'success' => false,
                'error' => isset($submit['error']) ? (string) $submit['error'] : 'remote_reject',
                'order_id' => $orderId,
                'cp_succeeded' => !empty($submit['cp_succeeded']),
                'bank_status' => isset($submit['bank_status']) ? (string) $submit['bank_status'] : '',
                'apply_native_order_status' => !empty($submit['apply_native_order_status']),
            );
            if (array_key_exists('recoverable', $submit)) {
                $json['recoverable'] = !empty($submit['recoverable']);
            }
            $json = MtUniCreditFinancingTerminalNavigationSupport::enrichDefinitiveRemoteRejectThankYou(
                $json,
                $this->session->data,
                $orderId,
                $this->url->link(MtUniCreditConstants::CHECKOUT_SUCCESS_ROUTE, '', true)
            );
            $this->respondJson($json);
            return;
        }

        // Woo/PS Checkout parity (not OC4): definitive CP create failure → Thank You.
        if (MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveCheckoutCpFailureTerminal($submit)) {
            $this->maybeApplyCheckoutNativeOrderStatusOnDefinitiveFailure($orderId, $submit);
            $json = array(
                'success' => false,
                'error' => isset($submit['error']) ? (string) $submit['error'] : 'cp_rejected',
                'order_id' => $orderId,
                'cp_succeeded' => false,
                'control_panel_order_id' => isset($submit['control_panel_order_id'])
                    ? (int) $submit['control_panel_order_id']
                    : 0,
                'bank_status' => isset($submit['bank_status']) ? (string) $submit['bank_status'] : '',
                'apply_native_order_status' => !empty($submit['apply_native_order_status']),
            );
            if (array_key_exists('recoverable', $submit)) {
                $json['recoverable'] = !empty($submit['recoverable']);
            }
            if (array_key_exists('ambiguous_blocked', $submit)) {
                $json['ambiguous_blocked'] = !empty($submit['ambiguous_blocked']);
            }
            $json = MtUniCreditFinancingTerminalNavigationSupport::enrichDefinitiveCheckoutCpFailureThankYou(
                $json,
                $this->session->data,
                $orderId,
                $this->url->link(MtUniCreditConstants::CHECKOUT_SUCCESS_ROUTE, '', true)
            );
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

        $preparedOrderId = (int) $context['order_id'];
        if (empty($result['order_id']) && $preparedOrderId > 0) {
            $result['order_id'] = $preparedOrderId;
        }

        if (MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveRemoteRejectTerminal($result)) {
            $this->maybeApplyCheckoutNativeOrderStatusOnDefinitiveFailure($preparedOrderId, $result);
            $payload = MtUniCreditFinancingTerminalNavigationSupport::enrichDefinitiveRemoteRejectThankYou(
                array(
                    'success' => false,
                    'error' => isset($result['error']) ? (string) $result['error'] : 'remote_reject',
                    'order_id' => $preparedOrderId,
                    'cp_succeeded' => !empty($result['cp_succeeded']),
                    'bank_status' => isset($result['bank_status']) ? (string) $result['bank_status'] : '',
                ),
                $this->session->data,
                $preparedOrderId,
                $this->url->link(MtUniCreditConstants::CHECKOUT_SUCCESS_ROUTE, '', true)
            );
            $this->response->redirect((string) $payload['redirect']);
            return;
        }

        if (MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveCheckoutCpFailureTerminal($result)) {
            $this->maybeApplyCheckoutNativeOrderStatusOnDefinitiveFailure($preparedOrderId, $result);
            $payload = MtUniCreditFinancingTerminalNavigationSupport::enrichDefinitiveCheckoutCpFailureThankYou(
                array(
                    'success' => false,
                    'error' => isset($result['error']) ? (string) $result['error'] : 'cp_rejected',
                    'order_id' => $preparedOrderId,
                    'cp_succeeded' => false,
                    'bank_status' => isset($result['bank_status']) ? (string) $result['bank_status'] : '',
                ),
                $this->session->data,
                $preparedOrderId,
                $this->url->link(MtUniCreditConstants::CHECKOUT_SUCCESS_ROUTE, '', true)
            );
            $this->response->redirect((string) $payload['redirect']);
            return;
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
                $preparedOrderId,
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
     * Same setting key / addOrderHistory path as Product/Cart. Idempotency uses a
     * direct SQL status read so status-0 Missing Orders are never skipped.
     *
     * @param int $orderId
     * @param array<string, mixed> $submit
     * @return void
     */
    private function maybeApplyCheckoutNativeOrderStatusAfterHandoff($orderId, array $submit)
    {
        $orderId = (int) $orderId;
        $handoff = MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff($submit);
        if (!$handoff) {
            $this->recordNativeOrderStatusDiagnostic(array(
                'order_id' => $orderId,
                'handoff' => false,
                'success' => !empty($submit['success']),
                'bank_status' => isset($submit['bank_status']) ? (string) $submit['bank_status'] : '',
                'applied' => false,
                'skipped_reason' => 'handoff_gate',
                'history_called' => false,
            ));

            return;
        }
        $this->applyPreparedOrderStatus($orderId, $submit);
    }

    /**
     * Definitive failure finalization after CP success+SmartUCF reject OR Checkout CP create fail.
     *
     * Uses the same payment_mt_uni_credit_order_status_id as Product/Cart and OC4
     * applyCheckoutUniCreditOrderStatus. Must NOT broaden isSuccessfulBankHandoff().
     *
     * @param int $orderId
     * @param array<string, mixed> $submit
     * @return void
     */
    private function maybeApplyCheckoutNativeOrderStatusOnDefinitiveFailure($orderId, array $submit)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            return;
        }
        if (empty($submit['apply_native_order_status'])) {
            $this->recordNativeOrderStatusDiagnostic(array(
                'order_id' => $orderId,
                'handoff' => false,
                'success' => false,
                'bank_status' => isset($submit['bank_status']) ? (string) $submit['bank_status'] : '',
                'applied' => false,
                'skipped_reason' => 'apply_native_not_authorised',
                'history_called' => false,
            ));

            return;
        }
        if (MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff($submit)) {
            return;
        }
        $this->applyPreparedOrderStatus($orderId, $submit);
    }

    /**
     * @param int $orderId
     * @param array<string, mixed> $submit
     * @return void
     */
    private function applyPreparedOrderStatus($orderId, array $submit = array())
    {
        $orderId = (int) $orderId;
        $statusId = MtUniCreditNativeOrderStatusSupport::configuredStatusId($this->config);
        $diag = array(
            'order_id' => $orderId,
            'handoff' => true,
            'success' => !empty($submit['success']),
            'bank_status' => isset($submit['bank_status']) ? (string) $submit['bank_status'] : '',
            'configured_status_id' => $statusId,
            'applied' => false,
            'history_called' => false,
        );

        if ($orderId <= 0) {
            $diag['skipped_reason'] = 'order_id';
            $this->recordNativeOrderStatusDiagnostic($diag);

            return;
        }
        if ($statusId <= 0) {
            $diag['skipped_reason'] = 'configured_status_id';
            $this->recordNativeOrderStatusDiagnostic($diag);

            return;
        }

        $current = MtUniCreditNativeOrderStatusSupport::readOrderStatusId($this->db, $orderId);
        $diag['current_status_id'] = $current;
        if (!MtUniCreditNativeOrderStatusSupport::shouldApplyHistory($current, $statusId)) {
            $diag['skipped_reason'] = $current < 0 ? 'order_missing' : 'already_applied';
            $this->recordNativeOrderStatusDiagnostic($diag);

            return;
        }

        // Product/Cart parity: call addOrderHistory directly (no getOrder / method_exists gate).
        $this->load->model('checkout/order');
        $this->model_checkout_order->addOrderHistory($orderId, $statusId);
        $diag['history_called'] = true;
        $diag['applied'] = true;
        $diag['current_status_id'] = MtUniCreditNativeOrderStatusSupport::readOrderStatusId($this->db, $orderId);
        $this->recordNativeOrderStatusDiagnostic($diag);
    }

    /**
     * Temporary Phase 11.5C.3: pre-submit gate isolation (no PII).
     *
     * @param string $stage
     * @param array<string, mixed> $extra
     * @return void
     */
    private function recordCheckoutPreSubmitTrace($stage, array $extra = array())
    {
        try {
            $storeId = (int) $this->config->get('config_store_id');
            $orderId = (int) (isset($this->session->data['order_id']) ? $this->session->data['order_id'] : 0);
            $preparedOrderId = (int) (isset($this->session->data[MtUniCreditCheckoutConfirmPreparation::SESSION_PREPARED_ORDER_ID])
                ? $this->session->data[MtUniCreditCheckoutConfirmPreparation::SESSION_PREPARED_ORDER_ID]
                : 0);

            $orderAvailable = false;
            $orderStatusId = -1;
            $orderPaymentCode = '';
            $order = null;
            if ($orderId > 0) {
                $this->load->model('checkout/order');
                $order = $this->model_checkout_order->getOrder($orderId);
                if (is_array($order)) {
                    $orderAvailable = true;
                    $orderStatusId = (int) (isset($order['order_status_id']) ? $order['order_status_id'] : 0);
                    $orderPaymentCode = isset($order['payment_code']) ? (string) $order['payment_code'] : '';
                }
            }

            $sessionPaymentCode = isset($this->session->data['payment_method']['code'])
                ? (string) $this->session->data['payment_method']['code']
                : '';

            $db = MtUniCreditBootstrap::dbFromRegistry($this->db);
            $unicid = '';
            $shopCacheAvailable = false;
            $shopCacheFresh = false;
            try {
                $credentials = MtUniCreditBootstrap::credentialsRepositoryFromDb($db);
                $unicid = $credentials->getUnicid($storeId);
                if ($unicid !== '') {
                    $cache = MtUniCreditBootstrap::shopConfigurationCacheFromDb($db);
                    $fresh = $cache->getFreshShopData($storeId, $unicid);
                    $latest = $cache->getLatestShopData($storeId, $unicid);
                    $shopCacheAvailable = is_array($latest) && $latest !== array();
                    $shopCacheFresh = is_array($fresh) && $fresh !== array();
                }
            } catch (Exception $ignored) {
            }

            $cartAvailable = false;
            $cartTotal = 0.0;
            try {
                $products = $this->cart->getProducts();
                $cartAvailable = is_array($products) && $products !== array();
                $cartTotal = round((float) $this->cart->getTotal(), 2);
            } catch (Exception $ignored) {
            }

            $attemptFound = false;
            $attemptState = '';
            if ($orderId > 0) {
                try {
                    $attempt = (new MtUniCreditFinancingAttemptRepository($db))->findByStoreOrder($storeId, $orderId);
                    if (is_array($attempt)) {
                        $attemptFound = true;
                        $attemptState = isset($attempt['state']) ? (string) $attempt['state'] : '';
                    }
                } catch (Exception $ignored) {
                }
            }

            $schemeKey = trim((string) $this->posted('scheme_key', ''));
            $fields = array(
                'stage' => (string) $stage,
                'order_id' => $orderId,
                'prepared_order_id' => $preparedOrderId,
                'store_id' => $storeId,
                'payment_selected' => $this->isUniCreditPaymentSelected(),
                'panel_available' => array_key_exists('panel_available', $extra)
                    ? !empty($extra['panel_available'])
                    : null,
                'shop_cache_available' => $shopCacheAvailable,
                'shop_cache_fresh' => $shopCacheFresh,
                'cart_available' => $cartAvailable,
                'cart_total' => $cartTotal,
                'order_available' => $orderAvailable,
                'order_status_id' => $orderStatusId,
                'order_payment_code' => $orderPaymentCode,
                'session_payment_code' => $sessionPaymentCode,
                'prepared_access_ok' => array_key_exists('prepared_access_ok', $extra)
                    ? !empty($extra['prepared_access_ok'])
                    : null,
                'attempt_found' => $attemptFound,
                'attempt_state' => $attemptState,
                'scheme_key_present' => array_key_exists('scheme_key_present', $extra)
                    ? !empty($extra['scheme_key_present'])
                    : ($schemeKey !== ''),
                'outcome' => 'checkout.pre_submit_trace',
                'message' => 'Checkout pre-submit gate trace.',
            );
            foreach (array('gate', 'prepare_error') as $key) {
                if (array_key_exists($key, $extra)) {
                    $fields[$key] = $extra[$key];
                }
            }

            $log = new MtUniCreditPhase9LifecycleLog($db);
            if ($orderId <= 0) {
                return;
            }
            $log->record(
                $storeId,
                $orderId,
                MtUniCreditOperationEntryPoint::CHECKOUT,
                'checkout.pre_submit_trace',
                $fields
            );
        } catch (Exception $exception) {
            // Diagnostics must never break financing handoff.
        }
    }

    /**
     * Temporary Phase 11.5C.3: capture controller-time submit shape before navigation.
     * Structural / non-PII only. Fail-soft.
     *
     * @param int $orderId
     * @param array<string, mixed> $submit
     * @param bool $process2
     * @return void
     */
    private function recordCheckoutCpFailureBranchTrace($orderId, array $submit, $process2)
    {
        try {
            $probe = $submit;
            $sessionOrderId = (int) $orderId;
            if (empty($probe['order_id']) && $sessionOrderId > 0) {
                $probe['order_id'] = $sessionOrderId;
            }

            $definitiveCp = MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveCheckoutCpFailureTerminal($probe);
            $definitiveSmart = MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveRemoteRejectTerminal($probe);
            $successfulHandoff = MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff($probe);

            $branch = 'generic_error';
            if (!empty($probe['success']) && !empty($probe['bank_redirect']) && !empty($probe['redirect'])) {
                $branch = 'success_bank_redirect';
            } elseif (!empty($probe['success'])) {
                $branch = 'success_thankyou';
            } elseif ($definitiveSmart) {
                $branch = 'smartucf_failure_thankyou';
            } elseif ($definitiveCp) {
                $branch = 'cp_failure_thankyou';
            }

            $attempt = isset($probe['attempt']) && is_array($probe['attempt']) ? $probe['attempt'] : array();
            $lastErrorClass = '';
            if (isset($attempt['last_error_class']) && $attempt['last_error_class'] !== null && $attempt['last_error_class'] !== '') {
                $lastErrorClass = (string) $attempt['last_error_class'];
            } elseif (isset($probe['error'])) {
                $lastErrorClass = (string) $probe['error'];
            }

            $cpOrderRaw = array_key_exists('control_panel_order_id', $probe)
                ? $probe['control_panel_order_id']
                : null;
            $cpOrderType = $cpOrderRaw === null ? 'null' : gettype($cpOrderRaw);

            $fields = array(
                'order_id' => isset($probe['order_id']) ? (int) $probe['order_id'] : 0,
                'success' => !empty($probe['success']),
                'cp_succeeded' => !empty($probe['cp_succeeded']),
                'control_panel_order_id' => $cpOrderRaw === null ? null : (int) $cpOrderRaw,
                'control_panel_order_id_type' => $cpOrderType,
                'bank_status' => isset($probe['bank_status']) ? (string) $probe['bank_status'] : '',
                'error' => isset($probe['error']) ? (string) $probe['error'] : '',
                'last_error_class' => $lastErrorClass,
                'recoverable' => !empty($probe['recoverable']),
                'ambiguous_blocked' => !empty($probe['ambiguous_blocked']),
                'local_replay' => !empty($probe['local_replay']),
                'apply_native_order_status' => !empty($probe['apply_native_order_status']),
                'attempt_state' => isset($attempt['state']) ? (string) $attempt['state'] : '',
                'has_redirect' => !empty($probe['redirect']),
                'bank_redirect' => !empty($probe['bank_redirect']),
                'process2' => !empty($process2),
                'http_status' => array_key_exists('http_status', $probe) && $probe['http_status'] !== null
                    ? (int) $probe['http_status']
                    : null,
                'definitive_cp_detector_result' => $definitiveCp,
                'definitive_smartucf_detector_result' => $definitiveSmart,
                'successful_handoff_detector_result' => $successfulHandoff,
                'controller_branch' => $branch,
                'outcome' => 'checkout.cp_failure_branch_trace',
                'message' => 'Checkout CP failure branch trace (controller-time submit).',
            );

            $db = MtUniCreditBootstrap::dbFromRegistry($this->db);
            $log = new MtUniCreditPhase9LifecycleLog($db);
            $storeId = (int) $this->config->get('config_store_id');
            $logOrderId = isset($fields['order_id']) && (int) $fields['order_id'] > 0
                ? (int) $fields['order_id']
                : $sessionOrderId;
            $log->record(
                $storeId,
                $logOrderId,
                MtUniCreditOperationEntryPoint::CHECKOUT,
                'checkout.cp_failure_branch_trace',
                $fields
            );
        } catch (Exception $exception) {
            // Diagnostics must never break financing handoff.
        }
    }

    /**
     * Safe diagnostic journal row for remote gate tracing (no PII).
     *
     * @param array<string, mixed> $fields
     * @return void
     */
    private function recordNativeOrderStatusDiagnostic(array $fields)
    {
        try {
            $db = MtUniCreditBootstrap::dbFromRegistry($this->db);
            $log = new MtUniCreditPhase9LifecycleLog($db);
            $storeId = (int) $this->config->get('config_store_id');
            $orderId = isset($fields['order_id']) ? (int) $fields['order_id'] : 0;
            $log->record(
                $storeId,
                $orderId,
                MtUniCreditOperationEntryPoint::CHECKOUT,
                MtUniCreditNativeOrderStatusSupport::DIAG_EVENT,
                MtUniCreditNativeOrderStatusSupport::diagnosticSummary($fields)
            );
        } catch (Exception $exception) {
            // Diagnostics must never break financing handoff.
        }
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
