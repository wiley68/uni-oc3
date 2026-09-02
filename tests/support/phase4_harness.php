<?php

require_once __DIR__ . '/fake_cp_http_transport.php';
require_once dirname(__DIR__) . '/fixtures/cp_shop_snapshot.php';

/**
 * Phase 4 service wiring for offline tests.
 */
final class Phase4TestHarness
{
    const TEST_SECRET = 'phase4-test-cp-secret-value';

    const TEST_UNICID = '123e4567-e89b-12d3-a456-426614174000';

    const TEST_STORE_ID = 900001;

    const TEST_STORE_ID_B = 900002;

    const TEST_SHOP_URL = 'https://shop.example.com';

    /**
     * @return Phase2MemoryDb
     */
    public static function memoryDb()
    {
        return new Phase2MemoryDb();
    }

    /**
     * @return string
     */
    public static function derivedTestKey()
    {
        return (new MtUniCreditEncryptionKeyProvider())->resolveDerivedKey(MtUniCreditEncryptionKeyProvider::testSecretInput());
    }

    /**
     * @return MtUniCreditSettingCipher
     */
    public static function cipher()
    {
        return new MtUniCreditSettingCipher(self::derivedTestKey());
    }

    /**
     * @param MtUniCreditSettingStore $store
     * @param int $storeId
     * @return void
     */
    public static function prepareCredentials(MtUniCreditSettingStore $store, $storeId = self::TEST_STORE_ID)
    {
        $store->set($storeId, MtUniCreditConstants::MODULE_SETTING_UNICID, self::TEST_UNICID);
        $store->set(
            $storeId,
            MtUniCreditConstants::MODULE_SETTING_SECRET,
            self::cipher()->encrypt(self::TEST_SECRET)
        );
    }

    /**
     * @return string
     */
    public static function environmentConfigPath()
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'cp_test_environment.php';
    }

    /**
     * @param Phase4FakeCpHttpTransport $transport
     * @param Phase2MemoryDb|null $memoryDb
     * @param int $storeId
     * @param int|null $now
     * @return array<string, mixed>
     */
    public static function services(Phase4FakeCpHttpTransport $transport, $memoryDb = null, $storeId = self::TEST_STORE_ID, $now = null)
    {
        if ($now === null) {
            $now = 1700000000;
        }

        $memoryDb = $memoryDb !== null ? $memoryDb : self::memoryDb();
        $db = new MtUniCreditDbAdapter($memoryDb, 'oc_');
        $settings = new MtUniCreditSettingStore($db, MtUniCreditConstants::MODULE_SETTINGS_CODE);
        self::prepareCredentials($settings, $storeId);

        $wallClock = function () use ($now) {
            return (int) $now;
        };

        return MtUniCreditCpServiceFactory::create(
            $db,
            $settings,
            $storeId,
            self::TEST_SHOP_URL,
            self::TEST_SHOP_URL,
            $transport,
            $wallClock,
            MtUniCreditEncryptionKeyProvider::testSecretInput(),
            self::environmentConfigPath()
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function loginSuccessPayload(array $overrides = array())
    {
        return array_merge(array(
            'success' => true,
            'access_token' => str_repeat('a', 64),
            'token_type' => 'Bearer',
            'expires_in' => 86400,
            'shop' => array(
                'id' => 1,
                'name' => self::TEST_SHOP_URL,
                'unicid' => self::TEST_UNICID,
            ),
        ), $overrides);
    }

    /**
     * @param array<string, mixed>|null $snapshot
     * @return array<string, mixed>
     */
    public static function shopSuccessPayload($snapshot = null)
    {
        $data = $snapshot !== null ? $snapshot : mtuc4_valid_shop_snapshot();

        return array(
            'success' => true,
            'message' => 'ok',
            'data' => $data,
        );
    }
}
