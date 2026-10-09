<?php

require_once __DIR__ . '/phase2_memory_db.php';
require_once __DIR__ . '/phase4_harness.php';

final class CpOutboundTestHarness
{
    public static function environment($origin)
    {
        $root = MtUniCreditTestTempRoot::allocate('mtuc-cp-environment');
        $path = $root . '/environment.php';
        file_put_contents($path, "<?php\nreturn array('control_panel_url' => " . var_export($origin, true) . ");\n");

        return new MtUniCreditDeploymentEnvironment($path);
    }

    public static function services($origin, Phase4FakeCpHttpTransport $transport, $memory = null, $storeId = Phase4TestHarness::TEST_STORE_ID)
    {
        $memory = $memory !== null ? $memory : Phase4TestHarness::memoryDb();
        $db = new MtUniCreditDbAdapter($memory, 'oc_');
        $settings = new MtUniCreditSettingStore($db, MtUniCreditConstants::MODULE_SETTINGS_CODE);
        Phase4TestHarness::prepareCredentials($settings, $storeId);
        $environment = self::environment($origin);

        return MtUniCreditCpServiceFactory::create(
            $db, $settings, $storeId, Phase4TestHarness::TEST_SHOP_URL, Phase4TestHarness::TEST_SHOP_URL,
            $transport, function () { return 1700000000; }, Phase4TestHarness::testSecretInput(), $environment->configFilePath()
        );
    }
}
