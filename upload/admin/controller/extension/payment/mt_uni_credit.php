<?php

require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';

class ControllerExtensionPaymentMtUniCredit extends Controller
{
    /** @var array<string, mixed> */
    private $error = array();

    public function index()
    {
        $this->load->language('extension/payment/mt_uni_credit');

        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] === 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('payment_mt_uni_credit', $this->request->post);

            $this->session->data['success'] = $this->language->get('text_success');

            $this->response->redirect($this->url->link(
                'marketplace/extension',
                'user_token=' . $this->session->data['user_token'] . '&type=payment',
                true
            ));
        }

        if (isset($this->error['warning'])) {
            $data['error_warning'] = $this->error['warning'];
        } else {
            $data['error_warning'] = '';
        }

        if (isset($this->error['sort_order'])) {
            $data['error_sort_order'] = $this->error['sort_order'];
        } else {
            $data['error_sort_order'] = '';
        }

        $token = 'user_token=' . $this->session->data['user_token'];

        $data['breadcrumbs'] = array(
            array(
                'text' => $this->language->get('text_home'),
                'href' => $this->url->link('common/dashboard', $token, true),
            ),
            array(
                'text' => $this->language->get('text_extension'),
                'href' => $this->url->link('marketplace/extension', $token . '&type=payment', true),
            ),
            array(
                'text' => $this->language->get('heading_title'),
                'href' => $this->url->link('extension/payment/mt_uni_credit', $token, true),
            ),
        );

        $data['action'] = $this->url->link('extension/payment/mt_uni_credit', $token, true);
        $data['cancel'] = $this->url->link('marketplace/extension', $token . '&type=payment', true);

        $paymentKeys = array(
            MtUniCreditConstants::PAYMENT_SETTING_ORDER_STATUS_ID,
            MtUniCreditConstants::PAYMENT_SETTING_GEO_ZONE_ID,
            MtUniCreditConstants::PAYMENT_SETTING_STATUS,
            MtUniCreditConstants::PAYMENT_SETTING_SORT_ORDER,
        );

        foreach ($paymentKeys as $key) {
            if (isset($this->request->post[$key])) {
                $data[$key] = $this->request->post[$key];
            } else {
                $stored = $this->config->get($key);
                $data[$key] = $stored !== null ? $stored : MtUniCreditConstants::defaultPaymentSettings()[$key];
            }
        }

        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        $this->load->model('localisation/geo_zone');
        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/payment/mt_uni_credit', $data));
    }

    public function install()
    {
        $this->load->model('extension/payment/mt_uni_credit');
        $this->model_extension_payment_mt_uni_credit->install();
    }

    public function uninstall()
    {
        $this->load->model('extension/payment/mt_uni_credit');
        $this->model_extension_payment_mt_uni_credit->uninstall();
    }

    /**
     * @return bool
     */
    protected function validate()
    {
        if (!$this->user->hasPermission('modify', 'extension/payment/mt_uni_credit')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        if (
            isset($this->request->post[MtUniCreditConstants::PAYMENT_SETTING_SORT_ORDER])
            && !preg_match('/^-?\d+$/', (string) $this->request->post[MtUniCreditConstants::PAYMENT_SETTING_SORT_ORDER])
        ) {
            $this->error['sort_order'] = $this->language->get('error_invalid_sort_order');
        }

        return !$this->error;
    }
}
