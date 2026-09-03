<?php

require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';

class ControllerExtensionMtUniCreditCart extends Controller
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
        $this->load->language('extension/mt_uni_credit/cart');
        $json = array('success' => false, 'unavailable' => true);

        try {
            if (!$this->isPost() || !MtUniCreditStorefrontCsrf::verify($this->session->data, $this->posted('csrf'))) {
                MtUniCreditStorefrontRuntime::respondJson($this, $json);
                return;
            }

            $sequence = (int) $this->posted('sequence', 0);
            $shop = MtUniCreditStorefrontRuntime::loadFreshShop($this);
            $cart = $shop !== null ? MtUniCreditStorefrontRuntime::resolveCartContext($this) : null;
            $currency = isset($this->session->data['currency'])
                ? (string) $this->session->data['currency']
                : (string) $this->config->get('config_currency');

            $calculator = null;
            if ($shop !== null && $cart !== null) {
                $resolver = new MtUniCreditCartSchemeResolver(new MtUniCreditCalculator());
                $resolution = $resolver->resolve($shop, $cart);
                $fingerprint = MtUniCreditStorefrontOperationIdentity::cartFingerprintFromContext($cart, $currency);
                $calculator = (new MtUniCreditStorefrontCalculatorPresenter())->presentCart(
                    $shop,
                    $cart,
                    $resolution,
                    $currency,
                    $fingerprint
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

    public function submit()
    {
        $this->load->language('extension/mt_uni_credit/cart');
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

            $cart = MtUniCreditStorefrontRuntime::resolveCartContext($this);
            if ($cart === null) {
                $json['error'] = 'unavailable';
                MtUniCreditStorefrontRuntime::respondJson($this, $json);
                return;
            }

            $currency = isset($this->session->data['currency'])
                ? (string) $this->session->data['currency']
                : (string) $this->config->get('config_currency');
            $fingerprint = (string) $this->posted('cart_fingerprint', '');
            if ($fingerprint === '') {
                $fingerprint = MtUniCreditStorefrontOperationIdentity::cartFingerprintFromContext($cart, $currency);
            }

            $products = array();
            foreach ($this->cart->getProducts() as $product) {
                $tax = (float) $this->tax->calculate(
                    (float) $product['price'],
                    (int) $product['tax_class_id'],
                    (bool) $this->config->get('config_tax')
                ) - (float) $product['price'];
                $products[] = array(
                    'product_id' => (int) $product['product_id'],
                    'name' => (string) $product['name'],
                    'model' => (string) $product['model'],
                    'quantity' => (int) $product['quantity'],
                    'price' => (float) $product['price'],
                    'total' => (float) $product['total'],
                    'tax' => $tax,
                    'reward' => isset($product['reward']) ? (int) $product['reward'] : 0,
                    'option' => isset($product['option']) && is_array($product['option']) ? $product['option'] : array(),
                );
            }

            $service = MtUniCreditStorefrontRuntime::submissionService($this);
            $result = $service->submit(array(
                'entry_point' => MtUniCreditOperationEntryPoint::CART,
                'store_id' => (int) $this->config->get('config_store_id'),
                'currency_code' => $currency,
                'scheme_key' => (string) $this->posted('scheme_key', ''),
                'cart_context' => $cart,
                'cart_fingerprint' => $fingerprint,
                'products' => $products,
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

            // Cart submit never clears the live cart.
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
            $json['cart_unchanged'] = true;
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

        $shop = MtUniCreditStorefrontRuntime::loadFreshShop($this);
        if ($shop === null) {
            return '';
        }

        $cart = MtUniCreditStorefrontRuntime::resolveCartContext($this);
        if ($cart === null) {
            return '';
        }

        $currency = isset($this->session->data['currency'])
            ? (string) $this->session->data['currency']
            : (string) $this->config->get('config_currency');
        $resolver = new MtUniCreditCartSchemeResolver(new MtUniCreditCalculator());
        $resolution = $resolver->resolve($shop, $cart);
        $fingerprint = MtUniCreditStorefrontOperationIdentity::cartFingerprintFromContext($cart, $currency);
        $calculator = (new MtUniCreditStorefrontCalculatorPresenter())->presentCart(
            $shop,
            $cart,
            $resolution,
            $currency,
            $fingerprint
        );
        if ($calculator === null) {
            return '';
        }

        $this->load->language('extension/mt_uni_credit/cart');
        $assets = MtUniCreditStorefrontRuntime::assetUrls($this);
        $csrf = MtUniCreditStorefrontCsrf::issue($this->session->data);

        $data = array();
        $data['heading'] = $this->language->get('heading_title');
        $data['text_apply'] = $this->language->get('button_apply');
        $data['text_submit'] = $this->language->get('button_submit');
        $data['text_secondary'] = '';
        $data['text_consent'] = $this->language->get('text_consent');
        $data['text_cancel'] = $this->language->get('button_cancel');
        $data['text_back'] = $this->language->get('button_back');
        $data['text_processing'] = $this->language->get('text_processing');
        $data['error_generic'] = $this->language->get('error_generic');
        $data['calculator'] = $calculator;
        $data['product_id'] = 0;
        $data['button_action'] = '';
        $data['top_spacing'] = 0;
        $data['csrf'] = $csrf;
        $data['asset_css'] = $assets['css'];
        $data['asset_js'] = $assets['js'];
        $data['asset_fonts'] = $assets['fonts'];
        $data['logo_standard_url'] = $assets['logo_standard'];
        $data['logo_alternative_url'] = $assets['logo_alternative'];
        $data['route_calculate'] = $this->url->link(MtUniCreditConstants::CART_ROUTE . '/calculate', '', true);
        $data['route_submit'] = $this->url->link(MtUniCreditConstants::CART_ROUTE . '/submit', '', true);
        $data['route_stash'] = '';
        $data['checkout_url'] = $this->url->link('checkout/checkout', '', true);
        $data['hide_secondary'] = true;
        $data['entry_point'] = 'cart';
        $data['cart_fingerprint'] = $fingerprint;
        $data['customer'] = $this->prefillCustomer();
        $data['modal'] = $this->load->view('extension/mt_uni_credit/modal', $data);

        return $this->load->view('extension/mt_uni_credit/cart_widget', $data);
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
     * @return array<string, string|int>
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
