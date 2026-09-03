<?php

require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';

class ControllerExtensionMtUniCreditProduct extends Controller
{
    public function widget()
    {
        try {
            $html = $this->renderWidget();
        } catch (Exception $exception) {
            $html = '';
        }

        return $html;
    }

    public function calculate()
    {
        $this->load->language('extension/mt_uni_credit/product');
        $json = array('success' => false, 'unavailable' => true);

        try {
            if (!$this->isPost() || !MtUniCreditStorefrontCsrf::verify($this->session->data, $this->posted('csrf'))) {
                MtUniCreditStorefrontRuntime::respondJson($this, $json);
                return;
            }

            $productId = (int) $this->posted('product_id', 0);
            $quantity = max(1, (int) $this->posted('quantity', 1));
            $option = $this->postedOptions();
            $sequence = (int) $this->posted('sequence', 0);

            $shop = MtUniCreditStorefrontRuntime::loadFreshShop($this);
            $line = $shop !== null
                ? MtUniCreditStorefrontRuntime::resolveProductLine($this, $productId, $quantity, $option)
                : null;
            $currency = isset($this->session->data['currency'])
                ? (string) $this->session->data['currency']
                : (string) $this->config->get('config_currency');

            $calculator = null;
            if ($shop !== null && $line !== null) {
                $calculator = (new MtUniCreditStorefrontCalculatorPresenter())->presentProduct(
                    $shop,
                    $line,
                    $currency
                );
            }

            if ($calculator === null) {
                MtUniCreditStorefrontRuntime::respondJson($this, array(
                    'success' => false,
                    'unavailable' => true,
                    'sequence' => $sequence,
                ));
                return;
            }

            MtUniCreditStorefrontRuntime::respondJson($this, array(
                'success' => true,
                'sequence' => $sequence,
                'calculator' => $calculator,
            ));
        } catch (Exception $exception) {
            MtUniCreditStorefrontRuntime::respondJson($this, $json);
        }
    }

    public function recalculate()
    {
        $this->load->language('extension/mt_uni_credit/product');
        $json = array(
            'success' => false,
            'message' => $this->language->get('error_recalculate'),
        );

        try {
            if (!$this->isPost() || !MtUniCreditStorefrontCsrf::verify($this->session->data, $this->posted('csrf'))) {
                MtUniCreditStorefrontRuntime::respondJson($this, $json);
                return;
            }

            $productId = (int) $this->posted('product_id', 0);
            $quantity = max(1, (int) $this->posted('quantity', 1));
            $option = $this->postedOptions();
            $schemeKey = trim((string) $this->posted('scheme_key', ''));
            $firstInstallment = (float) str_replace(',', '.', (string) $this->posted('first_installment', '0'));
            $sequence = (int) $this->posted('sequence', 0);

            $shop = MtUniCreditStorefrontRuntime::loadFreshShop($this);
            $line = $shop !== null
                ? MtUniCreditStorefrontRuntime::resolveProductLine($this, $productId, $quantity, $option)
                : null;
            $parsed = MtUniCreditStorefrontCalculatorPresenter::parseSchemeKey($schemeKey);
            if ($shop === null || $line === null || $parsed === null) {
                $json['unavailable'] = true;
                $json['sequence'] = $sequence;
                MtUniCreditStorefrontRuntime::respondJson($this, $json);
                return;
            }

            $presenter = new MtUniCreditStorefrontCalculatorPresenter();
            $scheme = $presenter->findProductScheme($shop, $line->toProductContext(), $parsed);
            if ($scheme === null) {
                $json['unavailable'] = true;
                $json['sequence'] = $sequence;
                MtUniCreditStorefrontRuntime::respondJson($this, $json);
                return;
            }

            $calculation = $presenter->presentSchemeCalculation(
                $shop,
                $line->financingPrice,
                $scheme,
                $firstInstallment
            );

            MtUniCreditStorefrontRuntime::respondJson($this, array(
                'success' => true,
                'sequence' => $sequence,
                'calculation' => $calculation,
            ));
        } catch (Exception $exception) {
            MtUniCreditStorefrontRuntime::respondJson($this, $json);
        }
    }

    public function stashBuyPreference()
    {
        $this->load->language('extension/mt_uni_credit/product');
        $json = array('success' => false);

        try {
            if (!$this->isPost() || !MtUniCreditStorefrontCsrf::verify($this->session->data, $this->posted('csrf'))) {
                MtUniCreditStorefrontRuntime::respondJson($this, $json);
                return;
            }

            $storeId = (int) $this->config->get('config_store_id');
            $schemeKey = trim((string) $this->posted('scheme_key', ''));
            $parsed = MtUniCreditStorefrontCalculatorPresenter::parseSchemeKey($schemeKey);
            MtUniCreditProductBuyPreference::save($this->session->data, array(
                'store_id' => $storeId,
                'product_id' => (int) $this->posted('product_id', 0),
                'scheme_type' => $parsed !== null ? $parsed['type'] : (string) $this->posted('scheme_type', ''),
                'kop_code' => $parsed !== null ? $parsed['kop_code'] : (string) $this->posted('kop_code', ''),
                'months' => $parsed !== null ? $parsed['months'] : (int) $this->posted('months', 0),
                'filter_id' => $parsed !== null ? $parsed['filter_id'] : (int) $this->posted('filter_id', 0),
                'scheme_key' => $schemeKey,
            ));

            MtUniCreditStorefrontRuntime::respondJson($this, array(
                'success' => true,
                'redirect' => $this->url->link('checkout/checkout', '', true),
            ));
        } catch (Exception $exception) {
            MtUniCreditStorefrontRuntime::respondJson($this, $json);
        }
    }

    public function submit()
    {
        $this->load->language('extension/mt_uni_credit/product');
        $json = array(
            'success' => false,
            'message' => $this->language->get('error_generic'),
            'cart_unchanged' => true,
        );

        try {
            if (!$this->isPost() || !MtUniCreditStorefrontCsrf::verify($this->session->data, $this->posted('csrf'))) {
                $json['error'] = 'csrf';
                MtUniCreditStorefrontRuntime::respondJson($this, $json);
                return;
            }

            $consent = $this->posted('consent');
            if (!$this->consentAccepted($consent)) {
                $json['error'] = 'consent';
                $json['message'] = $this->language->get('error_consent');
                MtUniCreditStorefrontRuntime::respondJson($this, $json);
                return;
            }

            $productId = (int) $this->posted('product_id', 0);
            $quantity = max(1, (int) $this->posted('quantity', 1));
            $option = $this->postedOptions();
            $line = MtUniCreditStorefrontRuntime::resolveProductLine($this, $productId, $quantity, $option);
            if ($line === null) {
                $json['error'] = 'unavailable';
                MtUniCreditStorefrontRuntime::respondJson($this, $json);
                return;
            }

            $currency = isset($this->session->data['currency'])
                ? (string) $this->session->data['currency']
                : (string) $this->config->get('config_currency');

            $service = MtUniCreditStorefrontRuntime::submissionService($this);
            $result = $service->submit(array(
                'entry_point' => MtUniCreditOperationEntryPoint::PRODUCT,
                'store_id' => (int) $this->config->get('config_store_id'),
                'currency_code' => $currency,
                'scheme_key' => (string) $this->posted('scheme_key', ''),
                'product_line' => $line,
                'customer' => $this->customerPayload(),
                'session' => $this->session->data,
                'invoice_prefix' => (string) $this->config->get('config_invoice_prefix'),
                'store_name' => (string) $this->config->get('config_name'),
                'store_url' => MtUniCreditStorefrontRuntime::storeUrl($this),
                'language_id' => (int) $this->config->get('config_language_id'),
                'currency_id' => (int) $this->currency->getId($currency),
                'currency_value' => (float) $this->currency->getValue($currency),
                'ip' => isset($this->request->server['REMOTE_ADDR']) ? (string) $this->request->server['REMOTE_ADDR'] : '',
                'forwarded_ip' => isset($this->request->server['HTTP_X_FORWARDED_FOR'])
                    ? (string) $this->request->server['HTTP_X_FORWARDED_FOR']
                    : '',
                'user_agent' => isset($this->request->server['HTTP_USER_AGENT'])
                    ? (string) $this->request->server['HTTP_USER_AGENT']
                    : '',
                'accept_language' => isset($this->request->server['HTTP_ACCEPT_LANGUAGE'])
                    ? (string) $this->request->server['HTTP_ACCEPT_LANGUAGE']
                    : '',
                'add_order' => function ($orderData) {
                    $this->load->model('checkout/order');

                    return (int) $this->model_checkout_order->addOrder($orderData);
                },
                'load_order' => function ($orderId) {
                    $this->load->model('checkout/order');

                    return $this->model_checkout_order->getOrder((int) $orderId);
                },
            ));

            if (isset($result['session']) && is_array($result['session'])) {
                $this->session->data = $result['session'];
            }

            if (!empty($result['success'])) {
                if (!empty($result['apply_native_order_status']) && !empty($result['order_id'])) {
                    $statusId = (int) $this->config->get(MtUniCreditConstants::PAYMENT_SETTING_ORDER_STATUS_ID);
                    if ($statusId > 0) {
                        $this->load->model('checkout/order');
                        $this->model_checkout_order->addOrderHistory((int) $result['order_id'], $statusId);
                    }
                }
                $this->session->data[MtUniCreditCheckoutConfirmPreparation::SESSION_PREPARED_ORDER_ID] = (int) $result['order_id'];
                MtUniCreditStorefrontRuntime::respondJson($this, array(
                    'success' => true,
                    'order_id' => (int) $result['order_id'],
                    'message' => isset($result['message']) ? (string) $result['message'] : $this->language->get('text_success'),
                    'redirect' => $this->url->link(MtUniCreditConstants::CHECKOUT_PREPARED_ROUTE, '', true),
                    'cart_unchanged' => true,
                    'apply_native_order_status' => !empty($result['apply_native_order_status']),
                ));
                return;
            }

            $json['error'] = isset($result['error']) ? (string) $result['error'] : 'request_failed';
            $json['message'] = isset($result['message']) ? (string) $result['message'] : $json['message'];
            if (!empty($result['order_id'])) {
                $json['order_id'] = (int) $result['order_id'];
                $json['redirect'] = $this->url->link(MtUniCreditConstants::CHECKOUT_PREPARED_ROUTE, '', true);
                $this->session->data[MtUniCreditCheckoutConfirmPreparation::SESSION_PREPARED_ORDER_ID] = (int) $result['order_id'];
            }
            MtUniCreditStorefrontRuntime::respondJson($this, $json);
        } catch (Exception $exception) {
            MtUniCreditStorefrontRuntime::respondJson($this, $json);
        }
    }

    /**
     * @return string
     */
    private function renderWidget()
    {
        if (!(int) $this->config->get(MtUniCreditConstants::MODULE_SETTING_STATUS)) {
            return '';
        }
        if (!(int) $this->config->get(MtUniCreditConstants::PAYMENT_SETTING_STATUS)) {
            return '';
        }

        $productId = isset($this->request->get['product_id']) ? (int) $this->request->get['product_id'] : 0;
        if ($productId <= 0) {
            return '';
        }

        $shop = MtUniCreditStorefrontRuntime::loadFreshShop($this);
        if ($shop === null) {
            return '';
        }

        $quantity = 1;
        if (isset($this->request->get['quantity'])) {
            $quantity = max(1, (int) $this->request->get['quantity']);
        }

        $line = MtUniCreditStorefrontRuntime::resolveProductLine($this, $productId, $quantity, array());
        if ($line === null) {
            return '';
        }

        $currency = isset($this->session->data['currency'])
            ? (string) $this->session->data['currency']
            : (string) $this->config->get('config_currency');
        $calculator = (new MtUniCreditStorefrontCalculatorPresenter())->presentProduct($shop, $line, $currency);
        if ($calculator === null) {
            return '';
        }

        $this->load->language('extension/mt_uni_credit/product');
        $assets = MtUniCreditStorefrontRuntime::assetUrls($this);
        $csrf = MtUniCreditStorefrontCsrf::issue($this->session->data);
        $buttonAction = (string) $this->config->get(MtUniCreditConstants::MODULE_SETTING_PRODUCT_BUTTON_ACTION);
        if (
            $buttonAction !== MtUniCreditConstants::BUTTON_ACTION_BUY
            && $buttonAction !== MtUniCreditConstants::BUTTON_ACTION_ADD_TO_CART
        ) {
            $buttonAction = MtUniCreditConstants::DEFAULT_PRODUCT_BUTTON_ACTION;
        }
        $topSpacing = (int) $this->config->get(MtUniCreditConstants::MODULE_SETTING_BUTTON_TOP_SPACING);
        if ($topSpacing < 0) {
            $topSpacing = 0;
        }
        if ($topSpacing > MtUniCreditConstants::MAX_BUTTON_TOP_SPACING) {
            $topSpacing = MtUniCreditConstants::MAX_BUTTON_TOP_SPACING;
        }

        $modalMeta = MtUniCreditStorefrontModalPresenter::present($shop, $currency);

        $data = array();
        $data['heading'] = $this->language->get('heading_title');
        $data['text_apply'] = $this->language->get('button_apply');
        $data['text_submit'] = $this->language->get('button_submit');
        $data['text_secondary'] = $buttonAction === MtUniCreditConstants::BUTTON_ACTION_BUY
            ? $this->language->get('button_buy')
            : $this->language->get('button_add_to_cart');
        $data['text_consent'] = $this->language->get('text_consent');
        $data['text_cancel'] = $this->language->get('button_cancel');
        $data['text_back'] = $this->language->get('button_back');
        $data['text_processing_title'] = $this->language->get('text_processing_title');
        $data['text_processing'] = $this->language->get('text_processing');
        $data['text_modal_title_scheme'] = $this->language->get('text_modal_title_scheme');
        $data['text_modal_title_customer'] = $this->language->get('text_modal_title_customer');
        $data['text_price'] = $this->language->get('text_price');
        $data['text_months'] = $this->language->get('text_months');
        $data['text_first_installment'] = $modalMeta['text_first_installment'];
        $data['text_financed_amount'] = $this->language->get('text_financed_amount');
        $data['text_monthly_installment'] = $this->language->get('text_monthly_installment');
        $data['text_total_payable'] = $this->language->get('text_total_payable');
        $data['text_glp'] = $this->language->get('text_glp');
        $data['text_gpr'] = $this->language->get('text_gpr');
        $data['error_generic'] = $this->language->get('error_generic');
        $data['calculator'] = $calculator;
        $data['modal_meta'] = $modalMeta;
        $data['badge_url'] = $assets['badge'];
        $data['product_id'] = $productId;
        $data['button_action'] = $buttonAction;
        $data['top_spacing'] = $topSpacing;
        $data['csrf'] = $csrf;
        $data['asset_css'] = $assets['css'];
        $data['asset_js'] = $assets['js'];
        $data['asset_fonts'] = $assets['fonts'];
        $data['logo_standard_url'] = $assets['logo_standard'];
        $data['logo_alternative_url'] = $assets['logo_alternative'];
        $data['route_calculate'] = $this->url->link(MtUniCreditConstants::PRODUCT_ROUTE . '/calculate', '', true);
        $data['route_recalculate'] = $this->url->link(MtUniCreditConstants::PRODUCT_ROUTE . '/recalculate', '', true);
        $data['route_submit'] = $this->url->link(MtUniCreditConstants::PRODUCT_ROUTE . '/submit', '', true);
        $data['route_stash'] = $this->url->link(MtUniCreditConstants::PRODUCT_ROUTE . '/stashBuyPreference', '', true);
        $data['checkout_url'] = $this->url->link('checkout/checkout', '', true);
        $data['hide_secondary'] = false;
        $data['entry_point'] = 'product';
        $data['customer'] = $this->prefillCustomer();
        $data['modal'] = $this->load->view('extension/mt_uni_credit/modal', $data);

        return $this->load->view('extension/mt_uni_credit/product_widget', $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function customerPayload()
    {
        return array(
            'customer_id' => $this->customer->isLogged() ? (int) $this->customer->getId() : 0,
            'customer_group_id' => (int) $this->config->get('config_customer_group_id'),
            'firstname' => trim((string) $this->posted('firstname', '')),
            'lastname' => trim((string) $this->posted('lastname', '')),
            'email' => trim((string) $this->posted('email', '')),
            'telephone' => trim((string) $this->posted('telephone', '')),
            'address_1' => trim((string) $this->posted('address_1', '')),
            'city' => trim((string) $this->posted('city', '')),
            'postcode' => trim((string) $this->posted('postcode', '')),
            'country_id' => (int) $this->posted('country_id', 0),
            'zone_id' => (int) $this->posted('zone_id', 0),
        );
    }

    /**
     * @return array<string, string>
     */
    private function prefillCustomer()
    {
        $data = array(
            'firstname' => '',
            'lastname' => '',
            'email' => '',
            'telephone' => '',
            'address_1' => '',
            'city' => '',
            'postcode' => '',
            'country_id' => (int) $this->config->get('config_country_id'),
            'zone_id' => (int) $this->config->get('config_zone_id'),
        );
        if ($this->customer->isLogged()) {
            $data['firstname'] = (string) $this->customer->getFirstName();
            $data['lastname'] = (string) $this->customer->getLastName();
            $data['email'] = (string) $this->customer->getEmail();
            $data['telephone'] = (string) $this->customer->getTelephone();
        }

        return $data;
    }

    /**
     * @param mixed $consent
     * @return bool
     */
    private function consentAccepted($consent)
    {
        if (is_array($consent)) {
            foreach ($consent as $value) {
                if ($value === '1' || $value === 1 || $value === true || $value === 'on') {
                    return true;
                }
            }

            return false;
        }

        return $consent === '1' || $consent === 1 || $consent === true || $consent === 'on' || $consent === 'yes';
    }

    /**
     * @return array<int|string, mixed>
     */
    private function postedOptions()
    {
        $option = $this->posted('option', array());

        return is_array($option) ? $option : array();
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
     * @return bool
     */
    private function isPost()
    {
        $method = isset($this->request->server['REQUEST_METHOD'])
            ? strtoupper((string) $this->request->server['REQUEST_METHOD'])
            : 'GET';

        return $method === 'POST';
    }
}
