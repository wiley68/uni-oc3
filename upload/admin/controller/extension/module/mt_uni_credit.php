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

        $this->load->model('setting/setting');
        $this->load->model('extension/module/mt_uni_credit');

        if (($this->request->server['REQUEST_METHOD'] === 'POST') && $this->validate()) {
            $this->model_extension_module_mt_uni_credit->saveSettings($this->request->post);

            $this->session->data['success'] = $this->language->get('text_success');

            $this->response->redirect($this->url->link(
                'marketplace/extension',
                'user_token=' . $this->session->data['user_token'] . '&type=module',
                true
            ));
        }

        $data = array();
        $this->assignErrors($data);
        $this->assignBreadcrumbs($data);
        $this->assignFormAction($data);
        $this->assignSettings($data);
        $this->assignHealth($data);
        $this->assignLayout($data);

        $this->response->setOutput($this->load->view('extension/module/mt_uni_credit', $data));
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
    private function assignErrors(array &$data)
    {
        $data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';

        foreach (array('environment', 'secret') as $field) {
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
    private function assignSettings(array &$data)
    {
        $keys = array(
            MtUniCreditConstants::MODULE_SETTING_STATUS,
            MtUniCreditConstants::MODULE_SETTING_ENVIRONMENT,
            MtUniCreditConstants::MODULE_SETTING_DEBUG,
            MtUniCreditConstants::MODULE_SETTING_UNICID,
        );

        foreach ($keys as $key) {
            if (isset($this->request->post[$key])) {
                $data[$key] = $this->request->post[$key];
            } else {
                $stored = $this->config->get($key);
                $data[$key] = $stored !== null ? $stored : MtUniCreditConstants::defaultModuleSettings()[$key];
            }
        }

        $data['module_version'] = MtUniCreditConstants::VERSION;
        $data['module_code'] = MtUniCreditConstants::EXTENSION_CODE;
        $data['has_secret'] = $this->model_extension_module_mt_uni_credit->isSecretConfigured();
        $data['text_secret_keep_current'] = $this->language->get('text_secret_keep_current');
        $data['text_secret_phase2'] = $this->language->get('text_secret_phase2');
    }

    /**
     * @param array<string, mixed> $data
     * @return void
     */
    private function assignHealth(array &$data)
    {
        $health = $this->model_extension_module_mt_uni_credit->getHealthReport();

        $data['health_summary'] = $health['summary'];
        $data['health_checks'] = $health['checks'];
        $data['health_paths'] = $health['paths'];
        $data['health_status_labels'] = array(
            MtUniCreditConstants::HEALTH_READY => $this->language->get('text_health_ready'),
            MtUniCreditConstants::HEALTH_WARNING => $this->language->get('text_health_warning'),
            MtUniCreditConstants::HEALTH_NOT_CONFIGURED => $this->language->get('text_health_not_configured'),
            MtUniCreditConstants::HEALTH_UNAVAILABLE => $this->language->get('text_health_unavailable'),
            MtUniCreditConstants::HEALTH_FUTURE_PHASE => $this->language->get('text_health_future_phase'),
        );
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
