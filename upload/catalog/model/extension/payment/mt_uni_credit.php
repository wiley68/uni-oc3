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
     * Phase 7: submit/resume CP order lifecycle for the prepared native order.
     *
     * @param int $orderId
     * @return array<string, mixed>
     */
    public function submitCheckoutFinancing($orderId)
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

        $categoryLoader = function ($productId) {
            $table = DB_PREFIX . 'product_to_category';
            $result = $this->db->query(
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
        $taxCalculator = function ($price, $taxClassId) {
            if (isset($this->tax) && is_object($this->tax) && method_exists($this->tax, 'calculate')) {
                return (float) $this->tax->calculate(
                    (float) $price,
                    (int) $taxClassId,
                    $this->config->get('config_tax')
                );
            }

            return (float) $price;
        };
        $cartFactory = new MtUniCreditOc3CartContextFactory($categoryLoader, $taxCalculator);
        $currency = (string) (isset($this->session->data['currency']) ? $this->session->data['currency'] : $this->config->get('config_currency'));
        $cartContext = $cartFactory->create(
            is_array($this->cart->getProducts()) ? $this->cart->getProducts() : array(),
            $this->calculateCheckoutGrandTotal()
        );

        $attempts = new MtUniCreditFinancingAttemptRepository($db);
        $lifecycle = new MtUniCreditControlPanelOrderLifecycleService(
            $attempts,
            new MtUniCreditOperationLockRepository($db),
            $stack['client'],
            null,
            MtUniCreditProcess1ServiceFactory::coordinator($db, null, null, $stack['client']),
            MtUniCreditProcess1ServiceFactory::bankStatuses($db)
        );
        $service = new MtUniCreditCheckoutFinancingSubmissionService(
            $attempts,
            $lifecycle,
            $stack['credentials'],
            MtUniCreditBootstrap::shopConfigurationCacheFromDb($db)
        );

        return $service->submit(array(
            'store_id' => $storeId,
            'order_id' => $orderId,
            'order' => is_array($order) ? $order : null,
            'order_products' => is_array($orderProducts) ? $orderProducts : array(),
            'cart_context' => $cartContext,
        ));
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
        $model = $this;
        $categoryLoader = function ($productId) use ($model) {
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
        $taxCalculator = function ($price, $taxClassId) use ($model) {
            if (isset($model->tax) && is_object($model->tax) && method_exists($model->tax, 'calculate')) {
                return (float) $model->tax->calculate(
                    (float) $price,
                    (int) $taxClassId,
                    $model->config->get('config_tax')
                );
            }

            return (float) $price;
        };

        return new MtUniCreditCheckoutPaymentAvailability(
            MtUniCreditBootstrap::shopConfigurationCacheFromDb($db),
            MtUniCreditBootstrap::credentialsRepositoryFromDb($db),
            new MtUniCreditOc3CartContextFactory($categoryLoader, $taxCalculator)
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
