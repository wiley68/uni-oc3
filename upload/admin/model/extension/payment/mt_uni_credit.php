<?php

require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';

class ModelExtensionPaymentMtUniCredit extends Model
{
    /**
     * Idempotent Phase 1 install: defaults only when keys are absent for store 0.
     *
     * @return void
     */
    public function install()
    {
        $this->load->model('setting/setting');

        $storeId = 0;
        $defaults = MtUniCreditConstants::defaultSettings();

        foreach ($defaults as $key => $value) {
            if ($this->model_setting_setting->getSettingValue($key, $storeId) === null) {
                $this->db->query(
                    "INSERT INTO `" . DB_PREFIX . "setting` SET store_id = '" . (int) $storeId
                        . "', `code` = '" . $this->db->escape(MtUniCreditConstants::SETTINGS_CODE)
                        . "', `key` = '" . $this->db->escape($key)
                        . "', `value` = '" . $this->db->escape($value) . "'"
                );
            }
        }
    }

    /**
     * Remove module-managed settings only. Financing evidence tables are not created in Phase 1.
     *
     * @return void
     */
    public function uninstall()
    {
        $this->load->model('setting/setting');
        $this->model_setting_setting->deleteSetting(MtUniCreditConstants::SETTINGS_CODE);
    }

    /**
     * @return array<string, string>
     */
    public function getDefaultSettings()
    {
        return MtUniCreditConstants::defaultSettings();
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, string>
     */
    public function validateSettings(array $post)
    {
        $errors = array();

        if (
            isset($post[MtUniCreditConstants::SETTING_SORT_ORDER])
            && !preg_match('/^-?\d+$/', (string) $post[MtUniCreditConstants::SETTING_SORT_ORDER])
        ) {
            $errors['sort_order'] = 'invalid_sort_order';
        }

        if (isset($post[MtUniCreditConstants::SETTING_ENVIRONMENT])) {
            $environment = (string) $post[MtUniCreditConstants::SETTING_ENVIRONMENT];
            if (!in_array($environment, array(MtUniCreditConstants::ENVIRONMENT_TEST, MtUniCreditConstants::ENVIRONMENT_PRODUCTION), true)) {
                $errors['environment'] = 'invalid_environment';
            }
        }

        if (
            array_key_exists(MtUniCreditConstants::SETTING_SECRET, $post)
            && trim((string) $post[MtUniCreditConstants::SETTING_SECRET]) !== ''
        ) {
            $errors['secret'] = 'secret_phase2_required';
        }

        return $errors;
    }

    /**
     * Persist Phase 1 settings. Plaintext CP secrets are never stored in this phase.
     *
     * @param array<string, mixed> $post
     * @return void
     */
    public function saveSettings(array $post)
    {
        $payload = array();

        foreach (MtUniCreditConstants::phase1PersistedSettingKeys() as $key) {
            if (!array_key_exists($key, $post)) {
                continue;
            }

            if ($key === MtUniCreditConstants::SETTING_UNICID) {
                $payload[$key] = trim((string) $post[$key]);
                continue;
            }

            if ($key === MtUniCreditConstants::SETTING_SORT_ORDER) {
                $payload[$key] = (string) (int) $post[$key];
                continue;
            }

            $payload[$key] = !empty($post[$key]) ? '1' : '0';
        }

        if ($payload) {
            $this->load->model('setting/setting');
            $existing = $this->model_setting_setting->getSetting(MtUniCreditConstants::SETTINGS_CODE);
            $merged = array_merge($existing, $payload);

            if (!empty($existing[MtUniCreditConstants::SETTING_SECRET])) {
                $merged[MtUniCreditConstants::SETTING_SECRET] = $existing[MtUniCreditConstants::SETTING_SECRET];
            }

            $this->model_setting_setting->editSetting(MtUniCreditConstants::SETTINGS_CODE, $merged);
        }
    }

    /**
     * @return bool
     */
    public function isSecretConfigured()
    {
        $stored = $this->config->get(MtUniCreditConstants::SETTING_SECRET);

        return is_string($stored) && trim($stored) !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function getHealthReport()
    {
        return MtUniCreditHealth::evaluate(array(
            'secret_configured' => $this->isSecretConfigured(),
            'unicid' => (string) $this->config->get(MtUniCreditConstants::SETTING_UNICID),
            'protected_root' => MtUniCreditBootstrap::resolveProtectedRoot(),
        ));
    }
}
