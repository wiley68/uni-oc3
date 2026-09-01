<?php

require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';

class ModelExtensionModuleMtUniCredit extends Model
{
    /**
     * Module extension install owns module-wide defaults only.
     *
     * @return void
     */
    public function install()
    {
        MtUniCreditInstaller::ensureDefaults(
            $this,
            MtUniCreditConstants::MODULE_SETTINGS_CODE,
            MtUniCreditConstants::defaultModuleSettings()
        );
        MtUniCreditInstaller::migrateLegacyDebugSetting(
            $this,
            MtUniCreditConstants::MODULE_SETTINGS_CODE
        );
    }

    /**
     * Remove module settings only. Payment settings and financing evidence remain untouched.
     *
     * @return void
     */
    public function uninstall()
    {
        $this->load->model('setting/setting');
        $this->model_setting_setting->deleteSetting(MtUniCreditConstants::MODULE_SETTINGS_CODE);
    }

    /**
     * @return array<string, string>
     */
    public function getDefaultSettings()
    {
        return MtUniCreditConstants::defaultModuleSettings();
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, string>
     */
    public function validateSettings(array $post)
    {
        $errors = array();

        $unicid = isset($post[MtUniCreditConstants::MODULE_SETTING_UNICID])
            ? trim((string) $post[MtUniCreditConstants::MODULE_SETTING_UNICID])
            : '';

        if ($unicid === '') {
            $errors['unicid'] = 'unicid_required';
        } elseif (strlen($unicid) > 36) {
            $errors['unicid'] = 'unicid_max_length';
        }

        if (array_key_exists(MtUniCreditConstants::MODULE_SETTING_PRODUCT_BUTTON_ACTION, $post)) {
            $action = trim((string) $post[MtUniCreditConstants::MODULE_SETTING_PRODUCT_BUTTON_ACTION]);
            if (!in_array($action, MtUniCreditLocalSettings::productButtonActions(), true)) {
                $errors['product_button_action'] = 'invalid_product_button_action';
            }
        }

        if (array_key_exists(MtUniCreditConstants::MODULE_SETTING_BUTTON_TOP_SPACING, $post)) {
            $spacing = $post[MtUniCreditConstants::MODULE_SETTING_BUTTON_TOP_SPACING];
            if (!is_numeric($spacing)) {
                $errors['button_top_spacing'] = 'invalid_button_top_spacing';
            } else {
                $value = (int) $spacing;
                if ($value < 0 || $value > MtUniCreditConstants::MAX_BUTTON_TOP_SPACING) {
                    $errors['button_top_spacing'] = 'invalid_button_top_spacing';
                }
            }
        }

        if (array_key_exists(MtUniCreditConstants::MODULE_SETTING_SECRET, $post)) {
            $secret = trim((string) $post[MtUniCreditConstants::MODULE_SETTING_SECRET]);

            if ($secret !== '') {
                if (strlen($secret) > 64) {
                    $errors['secret'] = 'secret_max_length';
                } else {
                    $errors['secret'] = 'secret_phase2_required';
                }
            } elseif (!$this->isSecretConfigured()) {
                $errors['secret'] = 'secret_required';
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $post
     * @return void
     */
    public function saveSettings(array $post)
    {
        $payload = array();

        foreach (MtUniCreditConstants::phase1ModulePersistedSettingKeys() as $key) {
            if (!array_key_exists($key, $post)) {
                continue;
            }

            if ($key === MtUniCreditConstants::MODULE_SETTING_UNICID) {
                $payload[$key] = trim((string) $post[$key]);
                continue;
            }

            if ($key === MtUniCreditConstants::MODULE_SETTING_PRODUCT_BUTTON_ACTION) {
                $payload[$key] = MtUniCreditLocalSettings::normalizeProductButtonAction($post[$key]);
                continue;
            }

            if ($key === MtUniCreditConstants::MODULE_SETTING_BUTTON_TOP_SPACING) {
                $payload[$key] = MtUniCreditLocalSettings::normalizeButtonTopSpacing($post[$key]);
                continue;
            }

            $payload[$key] = MtUniCreditLocalSettings::normalizeFlag($post[$key]);
        }

        if ($payload) {
            $this->load->model('setting/setting');
            $existing = $this->model_setting_setting->getSetting(MtUniCreditConstants::MODULE_SETTINGS_CODE);
            $merged = array_merge($existing, $payload);

            if (!empty($existing[MtUniCreditConstants::MODULE_SETTING_SECRET])) {
                $merged[MtUniCreditConstants::MODULE_SETTING_SECRET] = $existing[MtUniCreditConstants::MODULE_SETTING_SECRET];
            }

            $this->model_setting_setting->editSetting(MtUniCreditConstants::MODULE_SETTINGS_CODE, $merged);
        }
    }

    /**
     * @return bool
     */
    public function isSecretConfigured()
    {
        if (!isset($this->config) || !is_object($this->config) || !method_exists($this->config, 'get')) {
            return false;
        }

        $stored = $this->config->get(MtUniCreditConstants::MODULE_SETTING_SECRET);

        return is_string($stored) && trim($stored) !== '';
    }

    /**
     * Internal readiness helper; not rendered in Phase 1 admin UI.
     *
     * @return array<string, mixed>
     */
    public function getHealthReport()
    {
        return MtUniCreditHealth::evaluate(array(
            'secret_configured' => $this->isSecretConfigured(),
            'unicid' => (string) $this->config->get(MtUniCreditConstants::MODULE_SETTING_UNICID),
            'protected_root' => MtUniCreditBootstrap::resolveProtectedRoot(),
        ));
    }

    /**
     * Phase 1 placeholder export for journal download parity.
     *
     * @param int $storeId
     * @return array<string, mixed>
     */
    public function buildPhase1JournalExport($storeId = 0)
    {
        return array(
            'module' => MtUniCreditConstants::EXTENSION_CODE,
            'version' => MtUniCreditConstants::VERSION,
            'store_id' => (int) $storeId,
            'generated_at' => gmdate('c'),
            'entries' => array(),
        );
    }
}
