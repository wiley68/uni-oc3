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

    public function recalculate()
    {
        $this->load->language('extension/mt_uni_credit/cart');
        $json = array(
            'success' => false,
            'message' => $this->language->get('error_recalculate'),
        );

        try {
            if (!$this->isPost() || !MtUniCreditStorefrontCsrf::verify($this->session->data, $this->posted('csrf'))) {
                MtUniCreditStorefrontRuntime::respondJson($this, $json);
                return;
            }

            $schemeKey = trim((string) $this->posted('scheme_key', ''));
            $firstInstallment = (float) str_replace(',', '.', (string) $this->posted('first_installment', '0'));
            $sequence = (int) $this->posted('sequence', 0);
            $postedFingerprint = trim((string) $this->posted('cart_fingerprint', ''));

            $shop = MtUniCreditStorefrontRuntime::loadFreshShop($this);
            $cart = $shop !== null ? MtUniCreditStorefrontRuntime::resolveCartContext($this) : null;
            $currency = isset($this->session->data['currency'])
                ? (string) $this->session->data['currency']
                : (string) $this->config->get('config_currency');
            $parsed = MtUniCreditStorefrontCalculatorPresenter::parseSchemeKey($schemeKey);

            if ($shop === null || $cart === null || $parsed === null) {
                $json['unavailable'] = true;
                $json['sequence'] = $sequence;
                MtUniCreditStorefrontRuntime::respondJson($this, $json);
                return;
            }

            $fingerprint = MtUniCreditStorefrontOperationIdentity::cartFingerprintFromContext($cart, $currency);
            if ($postedFingerprint !== '' && !hash_equals($fingerprint, $postedFingerprint)) {
                $json['unavailable'] = true;
                $json['cart_changed'] = true;
                $json['sequence'] = $sequence;
                MtUniCreditStorefrontRuntime::respondJson($this, $json);
                return;
            }

            $resolver = new MtUniCreditCartSchemeResolver(new MtUniCreditCalculator());
            $resolution = $resolver->resolve($shop, $cart);
            $presenter = new MtUniCreditStorefrontCalculatorPresenter();
            $scheme = $presenter->findCartScheme($resolution, $shop, $parsed);
            if ($scheme === null) {
                $json['unavailable'] = true;
                $json['sequence'] = $sequence;
                MtUniCreditStorefrontRuntime::respondJson($this, $json);
                return;
            }

            $calculation = $presenter->presentSchemeCalculation(
                $shop,
                $cart->total,
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

    public function submit()
    {
        $this->load->language('extension/mt_uni_credit/cart');
        $json = array(
            'success' => false,
            'message' => $this->language->get('error_generic'),
            'cart_unchanged' => true,
        );

        try {
            $this->runCartSubmit($json);
        } catch (Exception $exception) {
            MtUniCreditStorefrontRuntime::respondJson($this, $json);
        }
    }

    /**
     * Cart financing submit body (extracted so IDE type inference stays within limits).
     *
     * @param array<string, mixed> $json
     * @return void
     */
    private function runCartSubmit(array $json)
    {
        if (!$this->isPost() || !MtUniCreditStorefrontCsrf::verify($this->session->data, $this->posted('csrf'))) {
            $json['error'] = 'csrf';
            MtUniCreditStorefrontRuntime::respondJson($this, $json);
            return;
        }

        $shop = MtUniCreditStorefrontRuntime::loadFreshShop($this);
        $consent = isset($this->request->post['consent']) ? $this->request->post['consent'] : array();
        if (!$this->consentAccepted($consent, $shop)) {
            $json['error'] = 'consent';
            $json['message'] = $this->language->get('error_consent');
            MtUniCreditStorefrontRuntime::respondJson($this, $json);
            return;
        }

        $customerValidation = $this->validateStep2Customer($shop);
        if (!$customerValidation['ok']) {
            $json['error'] = 'validation';
            $json['message'] = $customerValidation['message'];
            $json['errors'] = $customerValidation['errors'];
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

        $service = MtUniCreditStorefrontRuntime::submissionService($this);
        $result = $service->submit($this->buildCartSubmissionInput($cart, $currency, $fingerprint));
        $this->respondCartSubmitResult($result, $json);
    }

    /**
     * @param MtUniCreditCartContext $cart
     * @param string $currency
     * @param string $fingerprint
     * @return array<string, mixed>
     */
    private function buildCartSubmissionInput($cart, $currency, $fingerprint)
    {
        $controller = $this;

        return array(
            'entry_point' => MtUniCreditOperationEntryPoint::CART,
            'store_id' => (int) $this->config->get('config_store_id'),
            'currency_code' => $currency,
            'scheme_key' => (string) $this->posted('scheme_key', ''),
            'cart_context' => $cart,
            'cart_fingerprint' => $fingerprint,
            'products' => $this->cartOrderProductRows(),
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
            'add_order' => function ($orderData) use ($controller) {
                $controller->load->model('checkout/order');

                return (int) $controller->model_checkout_order->addOrder($orderData);
            },
            'load_order' => function ($orderId) use ($controller) {
                $controller->load->model('checkout/order');

                return $controller->model_checkout_order->getOrder((int) $orderId);
            },
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cartOrderProductRows()
    {
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

        return $products;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $json
     * @return void
     */
    private function respondCartSubmitResult(array $result, array $json)
    {
        if (isset($result['session']) && is_array($result['session'])) {
            $this->session->data = $result['session'];
        }

        // Cart submit never clears the live cart.
        if (!empty($result['success'])) {
            $this->maybeApplyNativeOrderStatus($result);
            $this->session->data[MtUniCreditCheckoutConfirmPreparation::SESSION_PREPARED_ORDER_ID] = (int) $result['order_id'];
            if (!empty($result['bank_redirect']) && !empty($result['redirect'])) {
                $redirect = (string) $result['redirect'];
            } elseif (!empty($result['redirect'])) {
                $redirect = (string) $result['redirect'];
            } else {
                $redirect = $this->url->link(MtUniCreditConstants::CHECKOUT_PREPARED_ROUTE, '', true);
            }
            MtUniCreditStorefrontRuntime::respondJson($this, array(
                'success' => true,
                'order_id' => (int) $result['order_id'],
                'message' => isset($result['message']) ? (string) $result['message'] : $this->language->get('text_success'),
                'redirect' => $redirect,
                'bank_redirect' => !empty($result['bank_redirect']),
                'cart_unchanged' => true,
                'apply_native_order_status' => !empty($result['apply_native_order_status']),
            ));
            return;
        }

        $this->maybeApplyNativeOrderStatus($result);

        $json['error'] = isset($result['error']) ? (string) $result['error'] : 'request_failed';
        $json['message'] = isset($result['message']) ? (string) $result['message'] : $json['message'];
        $json['cart_unchanged'] = true;
        if (!empty($result['order_id'])) {
            $json['order_id'] = (int) $result['order_id'];
            $json['redirect'] = $this->url->link(MtUniCreditConstants::CHECKOUT_PREPARED_ROUTE, '', true);
            $this->session->data[MtUniCreditCheckoutConfirmPreparation::SESSION_PREPARED_ORDER_ID] = (int) $result['order_id'];
        }
        MtUniCreditStorefrontRuntime::respondJson($this, $json);
    }

    /**
     * @param array<string, mixed> $result
     * @return void
     */
    private function maybeApplyNativeOrderStatus(array $result)
    {
        if (empty($result['apply_native_order_status']) || empty($result['order_id'])) {
            return;
        }
        $statusId = (int) $this->config->get(MtUniCreditConstants::PAYMENT_SETTING_ORDER_STATUS_ID);
        if ($statusId <= 0) {
            return;
        }
        $this->load->model('checkout/order');
        $this->model_checkout_order->addOrderHistory((int) $result['order_id'], $statusId);
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

        $prefill = $this->prefillCustomer();
        $modalMeta = MtUniCreditStorefrontModalPresenter::present($shop, $currency, $prefill);

        $data = array();
        $data['heading'] = $this->language->get('heading_title');
        $data['text_button_financing'] = $this->language->get('heading_title');
        $data['text_apply'] = $this->language->get('button_apply');
        $data['text_submit'] = $this->language->get('button_submit');
        $data['text_secondary'] = '';
        $data['text_consent'] = $this->language->get('text_consent');
        $data['text_consents'] = $this->language->get('text_consents');
        $data['text_firstname'] = $this->language->get('text_firstname');
        $data['text_lastname'] = $this->language->get('text_lastname');
        $data['text_address'] = $this->language->get('text_address');
        $data['text_telephone'] = $this->language->get('text_telephone');
        $data['text_email'] = $this->language->get('text_email');
        $data['text_phone2'] = $this->language->get('text_phone2');
        $data['text_egn'] = $this->language->get('text_egn');
        $data['text_required'] = $this->language->get('text_required');
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
        $data['customer'] = $prefill;
        $data['badge_url'] = $assets['badge'];
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
        $data['route_recalculate'] = $this->url->link(MtUniCreditConstants::CART_ROUTE . '/recalculate', '', true);
        $data['route_submit'] = $this->url->link(MtUniCreditConstants::CART_ROUTE . '/submit', '', true);
        $data['route_stash'] = '';
        $data['checkout_url'] = $this->url->link('checkout/checkout', '', true);
        $data['hide_secondary'] = true;
        $data['entry_point'] = 'cart';
        $data['cart_fingerprint'] = $fingerprint;
        $data['modal'] = $this->load->view('extension/mt_uni_credit/modal', $data);

        return $this->load->view('extension/mt_uni_credit/cart_widget', $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function customerPayload()
    {
        $shop = MtUniCreditStorefrontRuntime::loadFreshShop($this);
        $shopData = is_array($shop) ? $shop : array();
        $process2 = ((int) (isset($shopData['uni_proces']) ? $shopData['uni_proces'] : 0)) === 1;
        $normalized = (new MtUniCreditStorefrontPopupFormNormalizer())->normalize(
            $this->request->post,
            $this->storeAddressDefaults()
        );

        $firstname = trim((string) (isset($normalized['firstname']) ? $normalized['firstname'] : ''));
        $lastname = trim((string) (isset($normalized['lastname']) ? $normalized['lastname'] : ''));
        $email = trim((string) (isset($normalized['email']) ? $normalized['email'] : ''));
        $telephone = trim((string) (isset($normalized['telephone']) ? $normalized['telephone'] : ''));
        $address1 = trim((string) (isset($normalized['address_1']) ? $normalized['address_1'] : ''));

        $payload = array(
            'customer_id' => $this->customer->isLogged() ? (int) $this->customer->getId() : 0,
            'customer_group_id' => (int) $this->config->get('config_customer_group_id'),
            'firstname' => $firstname,
            'lastname' => $lastname,
            'email' => $email,
            'telephone' => $telephone,
            'address_1' => $address1,
            'city' => trim((string) (isset($normalized['city']) ? $normalized['city'] : '')),
            'postcode' => trim((string) (isset($normalized['postcode']) ? $normalized['postcode'] : '')),
            'country_id' => (int) (isset($normalized['country_id']) ? $normalized['country_id'] : 0),
            'zone_id' => (int) (isset($normalized['zone_id']) ? $normalized['zone_id'] : 0),
            'country' => trim((string) (isset($normalized['country']) ? $normalized['country'] : '')),
            'zone' => trim((string) (isset($normalized['zone']) ? $normalized['zone'] : '')),
        );

        if ($process2) {
            $p2 = (new MtUniCreditStorefrontProcessTwoFieldValidator())->validate($this->request->post);
            if ($p2['ok']) {
                $payload['phone2'] = $p2['phone2'];
                $payload['egn'] = $p2['egn'];
            }
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function prefillCustomer()
    {
        $addresses = array();
        $defaultAddressId = 0;
        $customerRow = array(
            'firstname' => '',
            'lastname' => '',
            'email' => '',
            'telephone' => '',
        );
        if ($this->customer->isLogged()) {
            $customerRow['firstname'] = (string) $this->customer->getFirstName();
            $customerRow['lastname'] = (string) $this->customer->getLastName();
            $customerRow['email'] = (string) $this->customer->getEmail();
            $customerRow['telephone'] = (string) $this->customer->getTelephone();
            $defaultAddressId = (int) $this->customer->getAddressId();
            // OC3 Controller has __get but no __isset — never gate model access with isset().
            $this->load->model('account/address');
            $raw = $this->model_account_address->getAddresses();
            if (is_array($raw)) {
                $addresses = array_values($raw);
            }
            if ($defaultAddressId > 0) {
                $owned = $this->model_account_address->getAddress($defaultAddressId);
                if (is_array($owned) && (int) (isset($owned['address_id']) ? $owned['address_id'] : 0) === $defaultAddressId) {
                    $found = false;
                    foreach ($addresses as $row) {
                        if ((int) (isset($row['address_id']) ? $row['address_id'] : 0) === $defaultAddressId) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $addresses[] = $owned;
                    }
                } else {
                    $defaultAddressId = 0;
                }
            }
        }

        return (new MtUniCreditStorefrontCustomerPrefill())->present(
            (bool) $this->customer->isLogged(),
            $customerRow,
            $addresses,
            $defaultAddressId
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function storeAddressDefaults()
    {
        $countryId = (int) $this->config->get('config_country_id');
        $zoneId = (int) $this->config->get('config_zone_id');
        $country = '';
        $zone = '';
        $this->load->model('localisation/country');
        $this->load->model('localisation/zone');
        if (isset($this->model_localisation_country) && method_exists($this->model_localisation_country, 'getCountry')) {
            $row = $this->model_localisation_country->getCountry($countryId);
            if (is_array($row) && isset($row['name'])) {
                $country = (string) $row['name'];
            }
        }
        if (isset($this->model_localisation_zone) && method_exists($this->model_localisation_zone, 'getZone')) {
            $row = $this->model_localisation_zone->getZone($zoneId);
            if (is_array($row) && isset($row['name'])) {
                $zone = (string) $row['name'];
            }
        }

        return array(
            'country_id' => $countryId > 0 ? $countryId : 33,
            'zone_id' => $zoneId > 0 ? $zoneId : 0,
            'country' => $country !== '' ? $country : 'Bulgaria',
            'zone' => $zone,
            'city' => $zone !== '' ? $zone : 'Sofia',
            'postcode' => '1000',
        );
    }

    /**
     * @param array<string, mixed>|null $shop
     * @return array{ok:bool,message:string,errors:array<string,string>}
     */
    private function validateStep2Customer($shop)
    {
        $shopData = is_array($shop) ? $shop : array();
        $process2 = ((int) (isset($shopData['uni_proces']) ? $shopData['uni_proces'] : 0)) === 1;
        $normalized = (new MtUniCreditStorefrontPopupFormNormalizer())->normalize(
            $this->request->post,
            $this->storeAddressDefaults()
        );
        $errors = array();
        $firstname = trim((string) (isset($normalized['firstname']) ? $normalized['firstname'] : ''));
        $lastname = trim((string) (isset($normalized['lastname']) ? $normalized['lastname'] : ''));
        $email = trim((string) (isset($normalized['email']) ? $normalized['email'] : ''));
        $telephone = trim((string) (isset($normalized['telephone']) ? $normalized['telephone'] : ''));
        $address1 = trim((string) (isset($normalized['address_1']) ? $normalized['address_1'] : ''));

        if ($firstname === '') {
            $errors['firstname'] = 'Полето е задължително.';
        }
        if ($lastname === '') {
            $errors['lastname'] = 'Полето е задължително.';
        }
        if ($address1 === '') {
            $errors['address'] = 'Полето е задължително.';
        }
        if ($telephone === '') {
            $errors['phone'] = 'Полето е задължително.';
        } elseif (!(new MtUniCreditStorefrontProcessTwoFieldValidator())->isValidPhone($telephone)) {
            $errors['phone'] = 'Въведете валиден телефонен номер.';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Въведете валиден e-mail адрес.';
        }

        if ($process2) {
            $p2 = (new MtUniCreditStorefrontProcessTwoFieldValidator())->validate($this->request->post);
            foreach ($p2['errors'] as $key => $message) {
                $errors[$key] = $message;
            }
        } else {
            if (trim((string) $this->posted('egn', '')) !== '' || trim((string) $this->posted('phone2', '')) !== '') {
                $errors['privacy'] = 'Невалидни полета за Process 1.';
            }
        }

        return array(
            'ok' => $errors === array(),
            'message' => $errors === array() ? '' : 'Моля, коригирайте данните.',
            'errors' => $errors,
        );
    }

    /**
     * @param mixed $consent
     * @param array<string, mixed>|null $shop
     * @return bool
     */
    private function consentAccepted($consent, $shop = null)
    {
        $shopData = is_array($shop) ? $shop : array();

        return (new MtUniCreditStorefrontConsentResolver())->isSatisfied($shopData, $consent);
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
