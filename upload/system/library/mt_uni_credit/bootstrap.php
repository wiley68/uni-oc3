<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'constants.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'local_settings.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'security_constants.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'persistence_table_names.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'persistence_exceptions.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'store_scope.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'persistence_clock.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'hash_validator.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_adapter.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'encryption_key_provider.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'setting_cipher.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'setting_store.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'credentials_repository.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'persistence_schema.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'api_nonce_repository.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'operation_entry_point.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lock_owner_token.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'operation_lock_repository.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'request_signature_protocol.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'request_signature_verifier.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'deployment_paths.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'shop_snapshot_validation_exception.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'shop_configuration_snapshot_validator.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'shop_cache_repository.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'shop_snapshot_sanitizer.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'smartucf_credentials_repository.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'shop_cache_persistence.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'shop_configuration_cache.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'inbound_api_exception.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'inbound_api_dispatcher.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'request_authenticator.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'inbound_bank_status_vocabulary.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'payment_identity.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'order_ownership_resolver.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'order_bank_status_repository.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'diagnostic_payload_redactor.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'diagnostic_debug_log_repository.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'inbound_api_runner.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'financing_attempt_state.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'control_panel_error_class.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'control_panel_order_submission_result.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'control_panel_order_payload_builder.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'financing_attempt_repository.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'control_panel_order_lifecycle_service.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'checkout_financing_submission_service.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'checkout_submit_token.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'checkout_prepared_view_state.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'checkout_live_grand_total.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'checkout_order_cart_parity.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'oc3_cart_context_factory.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'checkout_financing_eligibility.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'checkout_payment_availability.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'checkout_confirm_preparation.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'checkout_prepared_boundary.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'extension_root.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'cp_http_constants.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'cp_exception.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'cp_http_response.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'cp_http_transport.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'curl_cp_http_transport.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'cp_token_repository.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'canonical_shop_url_provider.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'deployment_environment.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'control_panel_client.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'shop_configuration_service.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'credential_change_handler.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'cp_service_factory.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'calculator' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'health.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'installer.php';

/**
 * Phase 2 bootstrap helpers for mt_uni_credit.
 */
final class MtUniCreditBootstrap
{
    /**
     * @return bool
     */
    public static function ensureLoaded()
    {
        return class_exists('MtUniCreditConstants', false)
            && class_exists('MtUniCreditSettingCipher', false);
    }

    /**
     * @param object $model OpenCart Model with db property
     * @return MtUniCreditDbAdapter
     */
    public static function dbFromModel($model)
    {
        return new MtUniCreditDbAdapter($model->db);
    }

    /**
     * @param object $model
     * @return MtUniCreditCredentialsRepository
     */
    public static function credentialsRepositoryFromModel($model)
    {
        return self::credentialsRepositoryFromDb(self::dbFromModel($model));
    }

    /**
     * @param MtUniCreditDbAdapter $db
     * @return MtUniCreditCredentialsRepository
     */
    public static function credentialsRepositoryFromDb(MtUniCreditDbAdapter $db)
    {
        $provider = new MtUniCreditEncryptionKeyProvider();
        $cipher = new MtUniCreditSettingCipher($provider->resolveDerivedKey());
        $store = new MtUniCreditSettingStore($db, MtUniCreditConstants::MODULE_SETTINGS_CODE);

        return new MtUniCreditCredentialsRepository($store, $cipher);
    }

    /**
     * @param object $model
     * @return void
     */
    public static function installPersistenceSchema($model)
    {
        MtUniCreditPersistenceSchema::installAll(self::dbFromModel($model));
    }

    /**
     * @param object $db OpenCart DB registry object
     * @return MtUniCreditDbAdapter
     */
    public static function dbFromRegistry($db)
    {
        return new MtUniCreditDbAdapter($db);
    }

    /**
     * @param MtUniCreditDbAdapter $db
     * @return MtUniCreditSmartucfCredentialsRepository
     */
    public static function smartucfCredentialsRepositoryFromDb(MtUniCreditDbAdapter $db)
    {
        $provider = new MtUniCreditEncryptionKeyProvider();
        $cipher = new MtUniCreditSettingCipher($provider->resolveDerivedKey());
        $store = new MtUniCreditSettingStore($db, MtUniCreditConstants::MODULE_SETTINGS_CODE);

        return new MtUniCreditSmartucfCredentialsRepository($store, $cipher);
    }

    /**
     * @param MtUniCreditDbAdapter $db
     * @return MtUniCreditShopCachePersistence
     */
    public static function shopCachePersistenceFromDb(MtUniCreditDbAdapter $db)
    {
        return new MtUniCreditShopCachePersistence(
            new MtUniCreditShopCacheRepository($db),
            new MtUniCreditShopConfigurationSnapshotValidator(),
            self::smartucfCredentialsRepositoryFromDb($db)
        );
    }

    /**
     * @param MtUniCreditDbAdapter $db
     * @return MtUniCreditShopConfigurationCache
     */
    public static function shopConfigurationCacheFromDb(MtUniCreditDbAdapter $db)
    {
        return new MtUniCreditShopConfigurationCache(
            new MtUniCreditShopCacheRepository($db),
            null,
            self::shopCachePersistenceFromDb($db)
        );
    }

    /**
     * Candidate protected roots for deployment secrets and certificates.
     *
     * @return array<int, string>
     */
    public static function protectedRootCandidates()
    {
        $candidates = array();

        if (defined('DIR_STORAGE')) {
            $candidates[] = rtrim(DIR_STORAGE, '/\\') . DIRECTORY_SEPARATOR . 'mt_uni_credit';
        }

        if (defined('DIR_SYSTEM')) {
            $candidates[] = dirname(DIR_SYSTEM) . DIRECTORY_SEPARATOR . 'mt_uni_credit';
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Resolve the first existing protected root or null when none is configured.
     *
     * @return string|null
     */
    public static function resolveProtectedRoot()
    {
        foreach (self::protectedRootCandidates() as $root) {
            if ($root !== '' && is_dir($root)) {
                return $root;
            }
        }

        return null;
    }

    /**
     * @param string|null $protectedRoot
     * @return array<string, string>
     */
    public static function deploymentRelativePaths($protectedRoot = null)
    {
        return array(
            'passphrase' => MtUniCreditConstants::RELATIVE_PASSPHRASE,
            'certificate' => MtUniCreditConstants::RELATIVE_CERTIFICATE,
            'private_key' => MtUniCreditConstants::RELATIVE_PRIVATE_KEY,
            'protected_root' => $protectedRoot === null ? '' : $protectedRoot,
        );
    }
}
