<?php

require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';

class ControllerExtensionModuleMtUniCredit extends Controller
{
    /** @var array<string, mixed> */
    private $error = array();

    public function index()
    {
        $this->load->language('extension/module/mt_uni_credit');

        $this->document->setTitle($this->language->get('heading_title'));
        $this->document->addStyle('view/stylesheet/mt_uni_credit_module.css');
        $this->document->addScript('view/javascript/mt_uni_credit_module.js');

        $this->load->model('setting/setting');
        $this->load->model('extension/module/mt_uni_credit');

        // Self-heal presentation events on every Module admin open (no Save required).
        $this->model_extension_module_mt_uni_credit->repairCatalogEvents();

        if (($this->request->server['REQUEST_METHOD'] === 'POST') && $this->validate()) {
            try {
                $this->model_extension_module_mt_uni_credit->saveSettings($this->request->post);
            } catch (MtUniCreditSecretPersistException $exception) {
                $this->error['warning'] = $this->language->get($exception->getLanguageKey());
            }

            if (!$this->error) {
                $this->session->data['success'] = $this->language->get('text_success');

                $this->response->redirect($this->url->link(
                    'extension/module/mt_uni_credit',
                    'user_token=' . $this->session->data['user_token'],
                    true
                ));
            }
        }

        $data = array();
        $this->assignFlashMessages($data);
        $this->assignErrors($data);
        $this->assignBreadcrumbs($data);
        $this->assignFormAction($data);
        $this->assignOperationalActions($data);
        $this->assignSettings($data);
        $this->assignEventHealth($data);
        $this->assignLayout($data);

        $this->response->setOutput($this->load->view('extension/module/mt_uni_credit', $data));
    }

    public function refreshBankData()
    {
        $this->load->language('extension/module/mt_uni_credit');

        $token = 'user_token=' . $this->session->data['user_token'];
        $redirect = $this->url->link('extension/module/mt_uni_credit', $token, true);

        if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->response->redirect($redirect);
            return;
        }

        if (!$this->user->hasPermission('modify', 'extension/module/mt_uni_credit')) {
            $this->session->data['error'] = $this->language->get('error_permission');
            $this->response->redirect($redirect);
            return;
        }

        $this->load->model('extension/module/mt_uni_credit');
        $result = $this->model_extension_module_mt_uni_credit->refreshBankData();

        if (isset($result['success'])) {
            $message = $this->language->get('text_bank_data_refreshed');
            if (!empty($result['fetched_at'])) {
                $message .= ' ' . sprintf(
                    $this->language->get('text_bank_data_refreshed_at'),
                    (string) $result['fetched_at']
                );
            }
            if (isset($result['scheme_count']) && is_int($result['scheme_count'])) {
                $message .= ' ' . sprintf(
                    $this->language->get('text_bank_data_scheme_count'),
                    $result['scheme_count']
                );
            }
            $this->session->data['success'] = trim($message);
        } elseif (isset($result['error'])) {
            $errorKey = 'error_bank_' . $result['error'];
            $label = $this->language->get($errorKey);
            $this->session->data['error'] = ($label !== $errorKey)
                ? $label
                : $this->language->get('error_bank_request_failed');
        } else {
            $this->session->data['error'] = $this->language->get('error_bank_request_failed');
        }

        $this->response->redirect($redirect);
    }

    public function downloadJournal()
    {
        $this->load->language('extension/module/mt_uni_credit');

        $token = 'user_token=' . $this->session->data['user_token'];
        $redirect = $this->url->link('extension/module/mt_uni_credit', $token, true);

        if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->response->redirect($redirect);
            return;
        }

        if (!$this->user->hasPermission('modify', 'extension/module/mt_uni_credit')) {
            $this->session->data['error'] = $this->language->get('error_permission');
            $this->response->redirect($redirect);
            return;
        }

        $this->load->model('extension/module/mt_uni_credit');

        $storeId = 0;
        if (isset($this->config) && is_object($this->config) && method_exists($this->config, 'get')) {
            $storeId = (int) $this->config->get('config_store_id');
        }

        $export = $this->model_extension_module_mt_uni_credit->buildPhase1JournalExport($storeId);
        $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            $this->session->data['error'] = $this->language->get('error_journal_download_failed');
            $this->response->redirect($redirect);
            return;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $filename = 'unipayment-smartucf-log-' . gmdate('Ymd-His') . '.json';
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        $this->response->addHeader('Content-Disposition: attachment; filename="' . $filename . '"');
        $this->response->addHeader('Content-Length: ' . strlen($json));
        $this->response->addHeader('Cache-Control: no-store');
        $this->response->addHeader('X-Content-Type-Options: nosniff');
        $this->response->setOutput($json);
    }

    public function install()
    {
        $this->load->model('extension/module/mt_uni_credit');
        $this->model_extension_module_mt_uni_credit->install();
    }

    public function uninstall()
    {
        $this->load->model('extension/module/mt_uni_credit');
        $this->model_extension_module_mt_uni_credit->uninstall();
    }

    /**
     * @param array<string, mixed> $data
     * @return void
     */
    private function assignFlashMessages(array &$data)
    {
        $data['success'] = '';
        if (isset($this->session->data['success'])) {
            $data['success'] = $this->session->data['success'];
            unset($this->session->data['success']);
        }

        if (isset($this->session->data['error'])) {
            $this->error['warning'] = $this->session->data['error'];
            unset($this->session->data['error']);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return void
     */
    private function assignErrors(array &$data)
    {
        $data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';

        foreach (array('secret', 'unicid', 'product_button_action', 'button_top_spacing') as $field) {
            $key = 'error_' . $field;
            $data[$key] = isset($this->error[$field]) ? $this->error[$field] : '';
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return void
     */
    private function assignBreadcrumbs(array &$data)
    {
        $token = 'user_token=' . $this->session->data['user_token'];

        $data['breadcrumbs'] = array(
            array(
                'text' => $this->language->get('text_home'),
                'href' => $this->url->link('common/dashboard', $token, true),
            ),
            array(
                'text' => $this->language->get('text_extension'),
                'href' => $this->url->link('marketplace/extension', $token . '&type=module', true),
            ),
            array(
                'text' => $this->language->get('heading_title'),
                'href' => $this->url->link('extension/module/mt_uni_credit', $token, true),
            ),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return void
     */
    private function assignFormAction(array &$data)
    {
        $token = 'user_token=' . $this->session->data['user_token'];

        $data['action'] = $this->url->link('extension/module/mt_uni_credit', $token, true);
        $data['cancel'] = $this->url->link('marketplace/extension', $token . '&type=module', true);
    }

    /**
     * @param array<string, mixed> $data
     * @return void
     */
    private function assignOperationalActions(array &$data)
    {
        $token = 'user_token=' . $this->session->data['user_token'];

        $data['refresh_bank_data'] = $this->url->link(
            'extension/module/mt_uni_credit/refreshBankData',
            $token,
            true
        );
        $data['download_journal'] = $this->url->link(
            'extension/module/mt_uni_credit/downloadJournal',
            $token,
            true
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return void
     */
    private function assignSettings(array &$data)
    {
        $defaults = MtUniCreditConstants::defaultModuleSettings();

        foreach (array_keys($defaults) as $key) {
            if (isset($this->request->post[$key])) {
                $data[$key] = $this->request->post[$key];
            } else {
                $stored = $this->config->get($key);
                if ($stored === null && $key === MtUniCreditConstants::MODULE_SETTING_DEBUG) {
                    $stored = $this->config->get(MtUniCreditConstants::MODULE_SETTING_DEBUG_LEGACY);
                }
                $data[$key] = $stored !== null ? $stored : $defaults[$key];
            }
        }

        if (isset($data[MtUniCreditConstants::MODULE_SETTING_PRODUCT_BUTTON_ACTION])) {
            $data[MtUniCreditConstants::MODULE_SETTING_PRODUCT_BUTTON_ACTION] = MtUniCreditLocalSettings::normalizeProductButtonAction(
                $data[MtUniCreditConstants::MODULE_SETTING_PRODUCT_BUTTON_ACTION]
            );
        }

        if (isset($data[MtUniCreditConstants::MODULE_SETTING_BUTTON_TOP_SPACING])) {
            $data[MtUniCreditConstants::MODULE_SETTING_BUTTON_TOP_SPACING] = MtUniCreditLocalSettings::normalizeButtonTopSpacing(
                $data[MtUniCreditConstants::MODULE_SETTING_BUTTON_TOP_SPACING]
            );
        }

        $data['has_secret'] = $this->model_extension_module_mt_uni_credit->isSecretConfigured();
        $data[MtUniCreditConstants::MODULE_SETTING_SECRET] = '';
        $data['product_button_actions'] = array(
            array(
                'value' => MtUniCreditConstants::BUTTON_ACTION_ADD_TO_CART,
                'label' => $this->language->get('text_product_button_add_to_cart'),
            ),
            array(
                'value' => MtUniCreditConstants::BUTTON_ACTION_BUY,
                'label' => $this->language->get('text_product_button_buy'),
            ),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return void
     */
    private function assignEventHealth(array &$data)
    {
        $report = $this->model_extension_module_mt_uni_credit->getPresentationEventHealth();
        $data['event_health_ok'] = !empty($report['ok']);
        $data['event_health_summary'] = MtUniCreditCatalogEventHealth::formatSummaryLine($report);
        $data['event_health_rows'] = isset($report['events']) && is_array($report['events'])
            ? $report['events']
            : array();
        $data['text_event_health'] = $this->language->get('text_event_health');
        $data['text_event_health_ok'] = $this->language->get('text_event_health_ok');
        $data['text_event_health_repair'] = $this->language->get('text_event_health_repair');
        $data['column_event_code'] = $this->language->get('column_event_code');
        $data['column_event_trigger'] = $this->language->get('column_event_trigger');
        $data['column_event_registered'] = $this->language->get('column_event_registered');
        $data['column_event_enabled'] = $this->language->get('column_event_enabled');
        $data['column_event_duplicates'] = $this->language->get('column_event_duplicates');
        $data['column_event_healthy'] = $this->language->get('column_event_healthy');
    }

    /**
     * @param array<string, mixed> $data
     * @return void
     */
    private function assignLayout(array &$data)
    {
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
    }

    /**
     * @return bool
     */
    protected function validate()
    {
        if (!$this->user->hasPermission('modify', 'extension/module/mt_uni_credit')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        if (!$this->error) {
            $modelErrors = $this->model_extension_module_mt_uni_credit->validateSettings($this->request->post);

            foreach ($modelErrors as $field => $code) {
                $languageKey = 'error_' . $code;
                $this->error[$field] = $this->language->get($languageKey);
            }
        }

        return !$this->error;
    }
}
