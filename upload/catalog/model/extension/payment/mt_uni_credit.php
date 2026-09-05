<?php

require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';

class ModelExtensionPaymentMtUniCredit extends Model
{
    /**
     * @param array<string, mixed> $address
     * @param float $total
     * @return array<string, mixed>
     */
    public function getMethod($address, $total)
    {
        $this->load->language('extension/payment/mt_uni_credit');

        if ($this->cart->hasRecurringProducts()) {
            return array();
        }

        $storeId = $this->resolveStoreId();
        $availability = $this->createPaymentAvailability();
        $currency = (string) (isset($this->session->data['currency']) ? $this->session->data['currency'] : $this->config->get('config_currency'));

        if (!$availability->isAvailable(
            $address,
            (float) $total,
            $currency,
            $this->cart->getProducts(),
            $storeId,
            $this->isModuleEnabled(),
            $this->isPaymentEnabled(),
            (int) $this->config->get(MtUniCreditConstants::PAYMENT_SETTING_GEO_ZONE_ID),
            MtUniCreditBootstrap::dbFromModel($this)
        )) {
            return array();
        }

        return array(
            'code' => MtUniCreditConstants::EXTENSION_CODE,
            'title' => $this->language->get('text_title'),
            'terms' => '',
            'sort_order' => (int) $this->config->get(MtUniCreditConstants::PAYMENT_SETTING_SORT_ORDER),
        );
    }

    /**
     * @return array{success?: bool, redirect?: string, error?: string, prepared_order_id?: int}
     */
    public function prepareCheckoutConfirm()
    {
        $orderId = (int) (isset($this->session->data['order_id']) ? $this->session->data['order_id'] : 0);
        $paymentCode = isset($this->session->data['payment_method']['code'])
            ? (string) $this->session->data['payment_method']['code']
            : '';

        $this->load->model('checkout/order');
        $order = $orderId > 0 ? $this->model_checkout_order->getOrder($orderId) : null;
        $orderProducts = $orderId > 0 ? $this->model_checkout_order->getOrderProducts($orderId) : array();
        $checkoutGrandTotal = $this->calculateCheckoutGrandTotal();
        $currency = (string) (isset($this->session->data['currency']) ? $this->session->data['currency'] : $this->config->get('config_currency'));

        $preparation = new MtUniCreditCheckoutConfirmPreparation(
            $this->createPaymentAvailability(),
            new MtUniCreditOperationLockRepository(MtUniCreditBootstrap::dbFromModel($this))
        );

        return $preparation->prepare(array(
            'payment_code' => $paymentCode,
            'order_id' => $orderId,
            'prepared_order_id' => (int) (isset($this->session->data[MtUniCreditCheckoutConfirmPreparation::SESSION_PREPARED_ORDER_ID])
                ? $this->session->data[MtUniCreditCheckoutConfirmPreparation::SESSION_PREPARED_ORDER_ID]
                : 0),
            'order' => is_array($order) ? $order : null,
            'order_products' => is_array($orderProducts) ? $orderProducts : array(),
            'cart_products' => $this->cart->getProducts(),
            'get_order_options' => array($this->model_checkout_order, 'getOrderOptions'),
            'checkout_grand_total' => $checkoutGrandTotal,
            'currency_code' => $currency,
            'store_id' => $this->resolveStoreId(),
            'module_enabled' => $this->isModuleEnabled(),
            'payment_enabled' => $this->isPaymentEnabled(),
            'success_url' => '',
        ));
    }

    /**
     * Phase 7/10: submit/resume CP order lifecycle for the prepared native order.
     *
     * @param int $orderId
     * @param array<string, mixed> $process2
     * @param array<string, mixed> $selection scheme_key / first_installment
     * @return array<string, mixed>
     */
    public function submitCheckoutFinancing($orderId, array $process2 = array(), array $selection = array())
    {
        $orderId = (int) $orderId;
        $storeId = $this->resolveStoreId();
        $this->load->model('checkout/order');
        $order = $orderId > 0 ? $this->model_checkout_order->getOrder($orderId) : null;
        $orderProducts = $orderId > 0 ? $this->model_checkout_order->getOrderProducts($orderId) : array();

        $db = MtUniCreditBootstrap::dbFromModel($this);
        $settings = new MtUniCreditSettingStore($db, MtUniCreditConstants::MODULE_SETTINGS_CODE);
        $stack = MtUniCreditCpServiceFactory::create(
            $db,
            $settings,
            $storeId,
            (string) $this->config->get('config_ssl'),
            (string) $this->config->get('config_url')
        );
        $service = $this->createCheckoutFinancingSubmissionService($db, $stack);

        return $service->submit(array(
            'store_id' => $storeId,
            'order_id' => $orderId,
            'order' => is_array($order) ? $order : null,
            'order_products' => is_array($orderProducts) ? $orderProducts : array(),
            'cart_context' => $this->createCheckoutCartContext(),
            'process2' => is_array($process2) ? $process2 : array(),
            'scheme_key' => isset($selection['scheme_key']) ? (string) $selection['scheme_key'] : '',
            'first_installment' => isset($selection['first_installment'])
                ? (float) $selection['first_installment']
                : 0.0,
        ));
    }

    /**
     * Read-only Checkout financing panel payload (no CP / SmartUCF / mail side effects).
     *
     * @return array{calculator: array<string, mixed>, modal: array<string, mixed>, order_id: int, currency: string}|null
     */
    public function presentCheckoutFinancingPanel()
    {
        $orderId = (int) (isset($this->session->data['order_id']) ? $this->session->data['order_id'] : 0);
        if ($orderId <= 0) {
            return null;
        }

        $this->load->model('checkout/order');
        $order = $this->model_checkout_order->getOrder($orderId);
        if (!is_array($order)) {
            return null;
        }

        $db = MtUniCreditBootstrap::dbFromModel($this);
        $storeId = $this->resolveStoreId();
        $settings = new MtUniCreditSettingStore($db, MtUniCreditConstants::MODULE_SETTINGS_CODE);
        $stack = MtUniCreditCpServiceFactory::create(
            $db,
            $settings,
            $storeId,
            (string) $this->config->get('config_ssl'),
            (string) $this->config->get('config_url')
        );
        $unicid = $stack['credentials']->getUnicid($storeId);
        if ($unicid === '') {
            return null;
        }
        $shop = MtUniCreditBootstrap::shopConfigurationCacheFromDb($db)->getFreshShopData($storeId, $unicid);
        if (!is_array($shop) || $shop === array()) {
            return null;
        }

        $currency = (string) (isset($order['currency_code']) && $order['currency_code'] !== ''
            ? $order['currency_code']
            : (isset($this->session->data['currency'])
                ? $this->session->data['currency']
                : $this->config->get('config_currency')));
        $cart = $this->createCheckoutCartContext();
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
            return null;
        }

        $calculator['source'] = 'checkout';
        $calculator['order_id'] = $orderId;
        $calculator['price'] = round((float) (isset($order['total']) ? $order['total'] : $cart->total), 2);

        $modal = MtUniCreditStorefrontModalPresenter::present($shop, $currency, array());

        return array(
            'calculator' => $calculator,
            'modal' => $modal,
            'order_id' => $orderId,
            'currency' => $currency,
            'shop' => $shop,
        );
    }

    /**
     * @param string $schemeKey
     * @param float $firstInstallment
     * @return array<string, mixed>|null
     */
    public function recalculateCheckoutSelection($schemeKey, $firstInstallment)
    {
        $panel = $this->presentCheckoutFinancingPanel();
        if ($panel === null) {
            return null;
        }

        $parsed = MtUniCreditStorefrontCalculatorPresenter::parseSchemeKey((string) $schemeKey);
        if ($parsed === null) {
            return null;
        }

        $shop = $panel['shop'];
        $cart = $this->createCheckoutCartContext();
        $resolver = new MtUniCreditCartSchemeResolver(new MtUniCreditCalculator());
        $resolution = $resolver->resolve($shop, $cart);
        $presenter = new MtUniCreditStorefrontCalculatorPresenter();
        $scheme = $presenter->findCartScheme($resolution, $shop, $parsed);
        if ($scheme === null) {
            return null;
        }

        $orderTotal = round((float) $panel['calculator']['price'], 2);

        return $presenter->presentSchemeCalculation($shop, $orderTotal, $scheme, (float) $firstInstallment);
    }

    /**
     * @return MtUniCreditCartContext
     */
    private function createCheckoutCartContext()
    {
        $cartFactory = new MtUniCreditOc3CartContextFactory(
            $this->createCategoryLoaderCallable(),
            $this->createTaxCalculatorCallable()
        );

        return $cartFactory->create(
            is_array($this->cart->getProducts()) ? $this->cart->getProducts() : array(),
            $this->calculateCheckoutGrandTotal()
        );
    }

    /**
     * @param object $db
     * @param array<string, mixed> $stack
     * @return MtUniCreditCheckoutFinancingSubmissionService
     */
    private function createCheckoutFinancingSubmissionService($db, array $stack)
    {
        $attempts = new MtUniCreditFinancingAttemptRepository($db);
        $lifecycle = new MtUniCreditControlPanelOrderLifecycleService(
            $attempts,
            new MtUniCreditOperationLockRepository($db),
            $stack['client'],
            null,
            MtUniCreditProcess1ServiceFactory::coordinator($db, null, null, $stack['client']),
            MtUniCreditProcess1ServiceFactory::bankStatuses($db),
            MtUniCreditProcessTwoServiceFactory::coordinator($db, $stack['client'])
        );

        return new MtUniCreditCheckoutFinancingSubmissionService(
            $attempts,
            $lifecycle,
            $stack['credentials'],
            MtUniCreditBootstrap::shopConfigurationCacheFromDb($db)
        );
    }

    /**
     * @return callable
     */
    private function createCategoryLoaderCallable()
    {
        $model = $this;

        return function ($productId) use ($model) {
            $table = DB_PREFIX . 'product_to_category';
            $result = $model->db->query(
                "SELECT `category_id` FROM `{$table}` WHERE `product_id` = " . (int) $productId
            );
            $ids = array();
            if (is_object($result) && isset($result->rows) && is_array($result->rows)) {
                foreach ($result->rows as $row) {
                    $ids[] = (int) $row['category_id'];
                }
            }

            return $ids;
        };
    }

    /**
     * @return callable
     */
    private function createTaxCalculatorCallable()
    {
        $model = $this;

        return function ($price, $taxClassId) use ($model) {
            if (isset($model->tax) && is_object($model->tax) && method_exists($model->tax, 'calculate')) {
                return (float) $model->tax->calculate(
                    (float) $price,
                    (int) $taxClassId,
                    $model->config->get('config_tax')
                );
            }

            return (float) $price;
        };
    }

    /**
     * @return float
     */
    public function calculateCheckoutGrandTotal()
    {
        $totals = array();
        $taxes = $this->cart->getTaxes();
        $total = 0.0;
        $totalData = array(
            'totals' => &$totals,
            'taxes' => &$taxes,
            'total' => &$total,
        );

        $this->load->model('setting/extension');
        $results = $this->model_setting_extension->getExtensions('total');
        $sortOrder = array();
        foreach ($results as $key => $value) {
            $sortOrder[$key] = (int) $this->config->get('total_' . $value['code'] . '_sort_order');
        }
        array_multisort($sortOrder, SORT_ASC, $results);

        foreach ($results as $result) {
            if ($this->config->get('total_' . $result['code'] . '_status')) {
                $this->load->model('extension/total/' . $result['code']);
                $this->{'model_extension_total_' . $result['code']}->getTotal($totalData);
            }
        }

        return round((float) $total, 2);
    }

    /**
     * @return MtUniCreditCheckoutPaymentAvailability
     */
    private function createPaymentAvailability()
    {
        $db = MtUniCreditBootstrap::dbFromModel($this);

        return new MtUniCreditCheckoutPaymentAvailability(
            MtUniCreditBootstrap::shopConfigurationCacheFromDb($db),
            MtUniCreditBootstrap::credentialsRepositoryFromDb($db),
            new MtUniCreditOc3CartContextFactory(
                $this->createCategoryLoaderCallable(),
                $this->createTaxCalculatorCallable()
            )
        );
    }

    /**
     * @return bool
     */
    private function isModuleEnabled()
    {
        return (bool) $this->config->get(MtUniCreditConstants::MODULE_SETTING_STATUS);
    }

    /**
     * @return bool
     */
    private function isPaymentEnabled()
    {
        return (bool) $this->config->get(MtUniCreditConstants::PAYMENT_SETTING_STATUS);
    }

    /**
     * @return int
     */
    private function resolveStoreId()
    {
        return (int) $this->config->get('config_store_id');
    }
}
