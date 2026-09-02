<?php

require_once __DIR__ . '/phase4_harness.php';
require_once dirname(__DIR__) . '/fixtures/cp_shop_snapshot.php';

/**
 * Phase 5 checkout/payment test wiring.
 */
final class Phase5TestHarness
{
    const STORE_A = 900001;

    const STORE_B = 900002;

    const COUNTRY_ID = 33;

    const ZONE_ID = 5;

    const GEO_ZONE_ID = 77;

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function cartProducts()
    {
        return array(
            array(
                'product_id' => 1,
                'quantity' => 1,
                'price' => 500.0,
                'tax_class_id' => 0,
                'option' => array(),
            ),
        );
    }

    /**
     * @param Phase2MemoryDb $memoryDb
     * @param int $storeId
     * @param int $now
     * @return MtUniCreditCheckoutPaymentAvailability
     */
    public static function availability(Phase2MemoryDb $memoryDb, $storeId = self::STORE_A, $now = 1700000000)
    {
        $db = new MtUniCreditDbAdapter($memoryDb, 'oc_');
        $settings = new MtUniCreditSettingStore($db, MtUniCreditConstants::MODULE_SETTINGS_CODE);
        Phase4TestHarness::prepareCredentials($settings, $storeId);
        self::seedFreshCache($memoryDb, $storeId, $now);

        $clock = new MtUniCreditPersistenceClock(function () use ($now) {
            return (int) $now;
        });

        return new MtUniCreditCheckoutPaymentAvailability(
            new MtUniCreditShopConfigurationCache(new MtUniCreditShopCacheRepository($db, $clock)),
            new MtUniCreditCredentialsRepository($settings, Phase4TestHarness::cipher()),
            new MtUniCreditOc3CartContextFactory(function () {
                return array(7);
            })
        );
    }

    /**
     * @param Phase2MemoryDb $memoryDb
     * @param int $storeId
     * @param int $now
     * @return void
     */
    public static function seedFreshCache(Phase2MemoryDb $memoryDb, $storeId, $now = 1700000000)
    {
        $db = new MtUniCreditDbAdapter($memoryDb, 'oc_');
        $clock = new MtUniCreditPersistenceClock(function () use ($now) {
            return (int) $now;
        });
        $cache = new MtUniCreditShopCacheRepository($db, $clock);
        $cache->replaceValidated($storeId, Phase4TestHarness::TEST_UNICID, mtuc4_valid_shop_snapshot());
    }

    /**
     * @param Phase2MemoryDb $memoryDb
     * @param int $storeId
     * @return MtUniCreditCheckoutConfirmPreparation
     */
    public static function confirmPreparation(Phase2MemoryDb $memoryDb, $storeId = self::STORE_A)
    {
        return new MtUniCreditCheckoutConfirmPreparation(
            self::availability($memoryDb, $storeId),
            new MtUniCreditOperationLockRepository(new MtUniCreditDbAdapter($memoryDb, 'oc_'))
        );
    }

    /**
     * @param int $orderId
     * @param int $storeId
     * @param float $total
     * @return array<string, mixed>
     */
    public static function orderRow($orderId, $storeId, $total = 500.0)
    {
        return array(
            'order_id' => (int) $orderId,
            'store_id' => (int) $storeId,
            'order_status_id' => 0,
            'payment_code' => MtUniCreditConstants::EXTENSION_CODE,
            'currency_code' => 'BGN',
            'total' => (float) $total,
        );
    }
}
