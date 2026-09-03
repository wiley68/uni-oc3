<?php

/**
 * Admin module saveSettings harness — real editSetting wipe semantics on Phase2MemoryDb.
 */

if (!class_exists('Registry', false)) {
    class Registry {}
}

if (!class_exists('Model', false)) {
    class Model
    {
        /** @param Registry|null $registry */
        public function __construct($registry = null) {}
    }
}

final class Phase1SecretSaveHarness
{
    /** @var Phase2MemoryDb */
    private $memoryDb;

    /** @var ModelExtensionModuleMtUniCredit */
    private $model;

    /** @var MtUniCreditCredentialsRepository */
    private $credentials;

    /** @var int */
    private $storeId;

    /**
     * @param int $storeId
     */
    public function __construct($storeId = 0)
    {
        if (!defined('DB_PASSWORD')) {
            define('DB_PASSWORD', MtUniCreditEncryptionKeyProvider::testSecretInput());
        }
        if (!defined('DIR_SYSTEM')) {
            define('DIR_SYSTEM', MTUC_PHASE0_ROOT . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
        }

        $this->storeId = (int) $storeId;
        $this->memoryDb = new Phase2MemoryDb();
        $dbAdapter = new MtUniCreditDbAdapter($this->memoryDb, 'oc_');
        $settings = new MtUniCreditSettingStore($dbAdapter, MtUniCreditConstants::MODULE_SETTINGS_CODE);
        $cipher = new MtUniCreditSettingCipher(
            (new MtUniCreditEncryptionKeyProvider())->resolveDerivedKey(MtUniCreditEncryptionKeyProvider::testSecretInput())
        );
        $this->credentials = new MtUniCreditCredentialsRepository($settings, $cipher);

        require_once MTUC_PHASE0_ROOT . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'admin'
            . DIRECTORY_SEPARATOR . 'model' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'module'
            . DIRECTORY_SEPARATOR . 'mt_uni_credit.php';

        $settingModel = new Phase1OcSettingModelFake($this->memoryDb);
        $config = new Phase1ConfigFake(array(
            'config_store_id' => $this->storeId,
            'config_ssl' => 'https://shop.example/',
            'config_url' => 'http://shop.example/',
        ));

        $this->model = new Phase1ModuleModelFake($this->memoryDb, $settingModel, $config, $dbAdapter);
    }

    /**
     * @return ModelExtensionModuleMtUniCredit
     */
    public function model()
    {
        return $this->model;
    }

    /**
     * @return MtUniCreditCredentialsRepository
     */
    public function credentials()
    {
        return $this->credentials;
    }

    /**
     * @return int
     */
    public function storeId()
    {
        return $this->storeId;
    }
}

final class Phase1ConfigFake
{
    /** @var array<string, mixed> */
    private $values;

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function get($key)
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : null;
    }
}

final class Phase1OcSettingModelFake
{
    /** @var Phase2MemoryDb */
    private $db;

    public function __construct(Phase2MemoryDb $db)
    {
        $this->db = $db;
    }

    /**
     * @param string $code
     * @param int $store_id
     * @return array<string, string>
     */
    public function getSetting($code, $store_id = 0)
    {
        $setting_data = array();
        $query = $this->db->query(
            "SELECT * FROM oc_setting WHERE store_id = '" . (int) $store_id . "' AND `code` = '" . $this->db->escape($code) . "'"
        );
        if (!is_object($query) || empty($query->rows)) {
            return $setting_data;
        }
        foreach ($query->rows as $result) {
            $setting_data[$result['key']] = $result['value'];
        }

        return $setting_data;
    }

    /**
     * @param string $code
     * @param array<string, mixed> $data
     * @param int $store_id
     * @return void
     */
    public function editSetting($code, $data, $store_id = 0)
    {
        $this->db->query(
            "DELETE FROM `oc_setting` WHERE store_id = '" . (int) $store_id . "' AND `code` = '" . $this->db->escape($code) . "'"
        );

        foreach ($data as $key => $value) {
            if (substr($key, 0, strlen($code)) == $code) {
                if (!is_array($value)) {
                    $this->db->query(
                        "INSERT INTO oc_setting SET store_id = '" . (int) $store_id . "', `code` = '" . $this->db->escape($code)
                            . "', `key` = '" . $this->db->escape($key) . "', `value` = '" . $this->db->escape($value) . "'"
                    );
                }
            }
        }
    }
}

final class Phase1LoadFake
{
    /**
     * @param string $route
     * @return void
     */
    public function model($route) {}
}

final class Phase1ModuleModelFake extends ModelExtensionModuleMtUniCredit
{
    /** @var Phase2MemoryDb */
    public $db;

    /** @var Phase1ConfigFake */
    public $config;

    /** @var Phase1LoadFake */
    public $load;

    /** @var Phase1OcSettingModelFake */
    public $model_setting_setting;

    /** @var MtUniCreditDbAdapter */
    private $dbAdapter;

    public function __construct(Phase2MemoryDb $memoryDb, Phase1OcSettingModelFake $settingModel, Phase1ConfigFake $config, MtUniCreditDbAdapter $dbAdapter)
    {
        $this->db = $memoryDb;
        $this->config = $config;
        $this->load = new Phase1LoadFake();
        $this->model_setting_setting = $settingModel;
        $this->dbAdapter = $dbAdapter;
    }

    /**
     * @return array<string, mixed>
     */
    public function createCpServices()
    {
        $storeId = (int) $this->config->get('config_store_id');
        $settings = new MtUniCreditSettingStore($this->dbAdapter, MtUniCreditConstants::MODULE_SETTINGS_CODE);
        $cipher = new MtUniCreditSettingCipher(
            (new MtUniCreditEncryptionKeyProvider())->resolveDerivedKey(MtUniCreditEncryptionKeyProvider::testSecretInput())
        );

        return array(
            'credentials' => new MtUniCreditCredentialsRepository($settings, $cipher),
            'credentialChange' => new MtUniCreditCredentialChangeHandler(
                new MtUniCreditCpTokenRepository($settings, $cipher, $storeId),
                new MtUniCreditShopCacheRepository($this->dbAdapter),
                $storeId
            ),
        );
    }
}
