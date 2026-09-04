<?php

require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';

class ModelExtensionModuleMtUniCredit extends Model
{
    /**
     * Module extension install owns module-wide defaults and Phase 2 schema.
     *
     * @return void
     */
    public function install()
    {
        MtUniCreditBootstrap::installPersistenceSchema($this);

        MtUniCreditInstaller::ensureDefaults(
            $this,
            MtUniCreditConstants::MODULE_SETTINGS_CODE,
            MtUniCreditConstants::defaultModuleSettings()
        );
        MtUniCreditInstaller::migrateLegacyDebugSetting(
            $this,
            MtUniCreditConstants::MODULE_SETTINGS_CODE
        );
        MtUniCreditInstaller::ensureCatalogEvents($this->db);
    }

    /**
     * Remove module settings only. Payment settings and durable extension tables remain untouched.
     *
     * @return void
     */
    public function uninstall()
    {
        MtUniCreditInstaller::removeCatalogEvents($this->db);
        $this->load->model('setting/setting');
        $this->model_setting_setting->deleteSetting(MtUniCreditConstants::MODULE_SETTINGS_CODE);
    }

    /**
     * Idempotent presentation-event repair (install / Save / Module admin open).
     *
     * @return array{inserted:int,updated:int,deleted_duplicates:int,healthy:bool,error:?string}
     */
    public function repairCatalogEvents()
    {
        return MtUniCreditInstaller::ensureCatalogEvents($this->db);
    }

    /**
     * Safe event health for admin diagnostics (no customer PII).
     *
     * @return array<string, mixed>
     */
    public function getPresentationEventHealth()
    {
        $prefix = defined('DB_PREFIX') ? DB_PREFIX : 'oc_';

        return MtUniCreditCatalogEventHealth::report($this->db, $prefix);
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
        $storeId = $this->resolveStoreId();
        $services = $this->createCpServices();
        $previousUnicid = $services['credentials']->getUnicid($storeId);

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

        // Never let a blank POST Secret overwrite encrypted storage via editSetting().
        unset($payload[MtUniCreditConstants::MODULE_SETTING_SECRET]);

        $this->load->model('setting/setting');
        $existing = $this->model_setting_setting->getSetting(MtUniCreditConstants::MODULE_SETTINGS_CODE, $storeId);
        if (!is_array($existing)) {
            $existing = array();
        }

        // editSetting() deletes all rows for the module code — preserve secret/tokens/other keys.
        foreach ($existing as $key => $value) {
            if (!array_key_exists($key, $payload)) {
                $payload[$key] = $value;
            }
        }

        $this->model_setting_setting->editSetting(
            MtUniCreditConstants::MODULE_SETTINGS_CODE,
            $payload,
            $storeId
        );

        // Repair catalog events on every Module save (dev 2.0.2 reinstall/repair path).
        MtUniCreditInstaller::ensureCatalogEvents($this->db);

        // Write/replace Secret AFTER editSetting so DELETE+reinsert cannot drop a fresh envelope.
        $secretChanged = false;
        if (array_key_exists(MtUniCreditConstants::MODULE_SETTING_SECRET, $post)) {
            $secret = trim((string) $post[MtUniCreditConstants::MODULE_SETTING_SECRET]);
            if ($secret !== '') {
                try {
                    $services['credentials']->saveSecret($storeId, $secret);
                    $secretChanged = true;
                } catch (RuntimeException $exception) {
                    throw new MtUniCreditSecretPersistException('error_secret_encrypt_failed');
                }
            }
        }

        $newUnicid = trim((string) (isset($payload[MtUniCreditConstants::MODULE_SETTING_UNICID])
            ? $payload[MtUniCreditConstants::MODULE_SETTING_UNICID]
            : $previousUnicid));
        if ($newUnicid !== $previousUnicid || $secretChanged) {
            $services['credentialChange']->onCredentialsChanged($previousUnicid, $newUnicid);
        }
    }

    /**
     * Operator-facing bank data refresh — credentials validated, CP auth transparent.
     *
     * @return array<string, mixed>
     */
    public function refreshBankData()
    {
        $storeId = $this->resolveStoreId();

        try {
            list($sslUrl, $plainUrl) = $this->resolveCatalogUrls();
            $shopName = (new MtUniCreditCanonicalShopUrlProvider())->resolve($sslUrl, $plainUrl);
            if ($shopName === '') {
                $this->writeRefreshLog('shop_url_missing');

                return array('error' => 'shop_url_missing');
            }

            $services = $this->createCpServices();
            $credentials = $services['credentials'];

            if ($credentials->getUnicid($storeId) === '') {
                $this->writeRefreshLog('unicid_missing');

                return array('error' => 'unicid_missing');
            }
            if (!$credentials->hasSecret($storeId)) {
                $this->writeRefreshLog('secret_missing');

                return array('error' => 'secret_missing');
            }
            if (!$credentials->isSecretReadable($storeId)) {
                $this->writeRefreshLog('secret_unreadable');

                return array('error' => 'secret_unreadable');
            }

            $shop = $services['shopConfiguration']->refreshRemote();
            $meta = $services['shopConfiguration']->getMetadata();
            $schemeCount = 0;
            if (isset($shop['coeff_list']) && is_array($shop['coeff_list'])) {
                $schemeCount = count($shop['coeff_list']);
            }

            $this->writeRefreshLog('bank_data_refreshed');

            return array(
                'success' => 'bank_data_refreshed',
                'fetched_at' => isset($meta['fetched_at']) ? (string) $meta['fetched_at'] : null,
                'scheme_count' => $schemeCount,
                'cache_fresh' => (bool) (isset($meta['is_fresh']) ? $meta['is_fresh'] : true),
            );
        } catch (MtUniCreditCpAuthenticationException $exception) {
            $this->writeRefreshLog('authentication_failed');

            return array('error' => 'authentication_failed');
        } catch (MtUniCreditShopSnapshotValidationException $exception) {
            $this->writeRefreshLog('shop_snapshot_invalid');

            return array('error' => 'shop_snapshot_invalid');
        } catch (MtUniCreditCpException $exception) {
            $code = $exception->isTransient() ? 'transient_failure' : 'request_failed';
            $this->writeRefreshLog($code);

            return array('error' => $code);
        } catch (Exception $exception) {
            $this->writeRefreshLog('request_failed');

            return array('error' => 'request_failed');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function createCpServices()
    {
        $storeId = $this->resolveStoreId();
        $db = MtUniCreditBootstrap::dbFromModel($this);
        $settings = new MtUniCreditSettingStore($db, MtUniCreditConstants::MODULE_SETTINGS_CODE);
        list($sslUrl, $plainUrl) = $this->resolveCatalogUrls();

        return MtUniCreditCpServiceFactory::create(
            $db,
            $settings,
            $storeId,
            $sslUrl,
            $plainUrl
        );
    }

    /**
     * OpenCart default store often has empty config_ssl/config_url in oc_setting.
     * Fall back to admin HTTP(S)_CATALOG constants so CP login `name` is never blank.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveCatalogUrls()
    {
        $sslUrl = trim((string) $this->config->get('config_ssl'));
        $plainUrl = trim((string) $this->config->get('config_url'));

        if ($sslUrl === '' && defined('HTTPS_CATALOG')) {
            $sslUrl = (string) HTTPS_CATALOG;
        }
        if ($plainUrl === '' && defined('HTTP_CATALOG')) {
            $plainUrl = (string) HTTP_CATALOG;
        }
        if ($sslUrl === '' && $plainUrl !== '') {
            $sslUrl = $plainUrl;
        }

        return array($sslUrl, $plainUrl);
    }

    /**
     * @param string $classification
     * @return void
     */
    private function writeRefreshLog($classification)
    {
        if (!isset($this->log) || !is_object($this->log) || !method_exists($this->log, 'write')) {
            return;
        }

        $this->log->write('mt_uni_credit.refreshBankData classification=' . $classification);
    }

    /**
     * @return bool
     */
    public function isSecretConfigured()
    {
        // OC3 Registry properties: use __get, never isset() (no __isset on Model).
        $db = $this->db;
        if (!is_object($db) || !method_exists($db, 'query')) {
            $config = $this->config;
            if (is_object($config) && method_exists($config, 'get')) {
                $stored = $config->get(MtUniCreditConstants::MODULE_SETTING_SECRET);

                return is_string($stored) && MtUniCreditSettingCipher::hasEncryptedPrefix($stored);
            }

            return false;
        }

        try {
            return MtUniCreditBootstrap::credentialsRepositoryFromModel($this)->hasSecret($this->resolveStoreId());
        } catch (RuntimeException $exception) {
            return false;
        }
    }

    /**
     * @return int
     */
    private function resolveStoreId()
    {
        $config = $this->config;
        if (is_object($config) && method_exists($config, 'get')) {
            return (int) $config->get('config_store_id');
        }

        return 0;
    }

    /**
     * Internal readiness helper; not rendered in Phase 1 admin UI.
     *
     * @return array<string, mixed>
     */
    public function getHealthReport()
    {
        $secretConfigured = false;
        $db = $this->db;
        if (is_object($db) && method_exists($db, 'query')) {
            try {
                $secretConfigured = MtUniCreditBootstrap::credentialsRepositoryFromModel($this)
                    ->hasSecret($this->resolveStoreId());
            } catch (RuntimeException $exception) {
                $secretConfigured = false;
            }
        }

        return MtUniCreditHealth::evaluate(array(
            'secret_configured' => $secretConfigured,
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
