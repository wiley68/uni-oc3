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
require_once __DIR__ . DIRECTORY_SEPARATOR . 'shop_configuration_cache.php';
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
     * @param MtUniCreditDbAdapter $db
     * @return MtUniCreditShopConfigurationCache
     */
    public static function shopConfigurationCacheFromDb(MtUniCreditDbAdapter $db)
    {
        return new MtUniCreditShopConfigurationCache(new MtUniCreditShopCacheRepository($db));
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
