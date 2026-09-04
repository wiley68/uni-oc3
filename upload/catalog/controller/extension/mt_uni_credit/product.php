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

            $productId = (int) $this->posted('product_id', 0);
            $quantity = max(1, (int) $this->posted('quantity', 1));
            $line = MtUniCreditStorefrontRuntime::resolveProductLine(
                $this,
                $productId,
                $quantity,
                $this->postedOptions()
            );
            if ($line === null) {
                $json['error'] = 'unavailable';
                MtUniCreditStorefrontRuntime::respondJson($this, $json);
                return;
            }

            $currency = isset($this->session->data['currency'])
                ? (string) $this->session->data['currency']
                : (string) $this->config->get('config_currency');

            $result = MtUniCreditStorefrontRuntime::submissionService($this)->submit(
                $this->buildProductSubmitInput($line, $currency)
            );
            $this->respondProductSubmitResult($result, $json);
        } catch (Exception $exception) {
            MtUniCreditStorefrontRuntime::respondJson($this, $json);
        }
    }

    /**
     * @param MtUniCreditProductLine $line
     * @param string $currency
     * @return array<string, mixed>
     */
    private function buildProductSubmitInput(MtUniCreditProductLine $line, $currency)
    {
        $controller = $this;

        return array(
            'entry_point' => MtUniCreditOperationEntryPoint::PRODUCT,
            'store_id' => (int) $this->config->get('config_store_id'),
            'currency_code' => $currency,
            'scheme_key' => (string) $this->posted('scheme_key', ''),
            'product_line' => $line,
            'application_token' => (string) $this->posted('application_token', ''),
            'customer' => $this->customerPayload(),
            'session' => $this->session->data,
            'invoice_prefix' => (string) $this->config->get('config_invoice_prefix'),
            'store_name' => (string) $this->config->get('config_name'),
            'store_url' => MtUniCreditStorefrontRuntime::storeUrl($this),
            'language_id' => (int) $this->config->get('config_language_id'),
            'currency_id' => (int) $this->currency->getId($currency),
            'currency_value' => (float) $this->currency->getValue($currency),
            'ip' => isset($this->request->server['REMOTE_ADDR'])
                ? (string) $this->request->server['REMOTE_ADDR']
                : '',
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
     * @param array<string, mixed> $result
     * @param array<string, mixed> $json
     * @return void
     */
    private function respondProductSubmitResult(array $result, array $json)
    {
        if (isset($result['session']) && is_array($result['session'])) {
            $this->session->data = $result['session'];
        }

        if (!empty($result['success'])) {
            $this->maybeApplyNativeOrderStatus($result);
            $this->session->data[MtUniCreditCheckoutConfirmPreparation::SESSION_PREPARED_ORDER_ID] = (int) $result['order_id'];
            // Process 1 success must navigate to the trusted bank URL — never substitute cart/prepared.
            if (!empty($result['bank_redirect']) && !empty($result['redirect'])) {
                $redirect = (string) $result['redirect'];
                $bankRedirect = true;
            } else {
                $payload = MtUniCreditFinancingTerminalNavigationSupport::enrichProcess2ThankYou(
                    array(
                        'success' => true,
                        'redirect' => isset($result['redirect']) ? (string) $result['redirect'] : '',
                        'bank_redirect' => false,
                    ),
                    $this->session->data,
                    (int) $result['order_id'],
                    $this->url->link(MtUniCreditConstants::CHECKOUT_SUCCESS_ROUTE, '', true)
                );
                $redirect = (string) $payload['redirect'];
                $bankRedirect = !empty($payload['bank_redirect']);
            }
            MtUniCreditStorefrontRuntime::respondJson($this, array(
                'success' => true,
                'order_id' => (int) $result['order_id'],
                'message' => isset($result['message'])
                    ? (string) $result['message']
                    : $this->language->get('text_success'),
                'redirect' => $redirect,
                'bank_redirect' => $bankRedirect,
                'cart_unchanged' => true,
                'apply_native_order_status' => !empty($result['apply_native_order_status']),
            ));

            return;
        }

        // Native status only when lifecycle explicitly authorizes it (not CP-only success).
        $this->maybeApplyNativeOrderStatus($result);

        $json['error'] = isset($result['error']) ? (string) $result['error'] : 'request_failed';
        $json['message'] = isset($result['message']) ? (string) $result['message'] : $json['message'];
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
        $applicationToken = MtUniCreditStorefrontApplicationToken::issue($this->session->data);
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

        $prefill = $this->prefillCustomer();
        $modalMeta = MtUniCreditStorefrontModalPresenter::present($shop, $currency, $prefill);

        $data = array();
        $data['heading'] = $this->language->get('heading_title');
        $data['text_button_financing'] = $this->language->get('heading_title');
        $data['text_apply'] = $this->language->get('button_apply');
        $data['text_submit'] = $this->language->get('button_submit');
        $data['text_secondary'] = $buttonAction === MtUniCreditConstants::BUTTON_ACTION_BUY
            ? $this->language->get('button_buy')
            : $this->language->get('button_add_to_cart');
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
        $data['product_id'] = $productId;
        $data['button_action'] = $buttonAction;
        $data['top_spacing'] = $topSpacing;
        $data['csrf'] = $csrf;
        $data['application_token'] = $applicationToken;
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
        $data['modal'] = $this->load->view('extension/mt_uni_credit/modal', $data);

        return $this->load->view('extension/mt_uni_credit/product_widget', $data);
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

        // Process 1 privacy: never attach EGN / second phone.
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
                // Native getAddresses() is keyed by address_id; normalize to a list.
                $addresses = array_values($raw);
            }
            // Ownership-checked default via getAddress() when address_id is set.
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
                    // Stale customer.address_id — keep book for single-address fallback.
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
            // Process 1 privacy: reject accidental EGN/phone2 leakage as customer fields.
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
