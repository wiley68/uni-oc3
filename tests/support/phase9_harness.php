<?php

require_once __DIR__ . '/phase2_memory_db.php';
require_once __DIR__ . '/phase4_harness.php';
require_once __DIR__ . '/phase5_harness.php';
require_once __DIR__ . '/phase7_harness.php';
require_once dirname(__DIR__) . '/fixtures/cp_shop_snapshot.php';

/**
 * Phase 9 Process 1 / SmartUCF offline harness.
 */
final class Phase9TestHarness
{
    const ORDER_ID = 9001;

    const NOW = 1700000000;

    const SESSION_ID = 'sess-phase9-ok';

    /**
     * Build checkout + Process 1 stack with injectable SmartUCF HTTP executor.
     *
     * @param Phase4FakeCpHttpTransport $transport
     * @param callable|null $httpExecutor function(array $curlOptions): array{body:string,error:string,http_code:int}
     * @param Phase2MemoryDb|null $memoryDb
     * @param int $storeId
     * @param array<string, mixed> $shopOverrides
     * @return array<string, mixed>
     */
    public static function stack(
        Phase4FakeCpHttpTransport $transport,
        $httpExecutor = null,
        $memoryDb = null,
        $storeId = Phase5TestHarness::STORE_A,
        array $shopOverrides = array()
    ): array {
        $memoryDb = $memoryDb !== null ? $memoryDb : new Phase2MemoryDb();
        $services = Phase4TestHarness::services($transport, $memoryDb, $storeId, self::NOW);
        $db = new MtUniCreditDbAdapter($memoryDb, 'oc_');
        $clock = new MtUniCreditPersistenceClock(function (): int {
            return self::NOW;
        });
        self::seedFreshCache($memoryDb, $storeId, self::NOW, $shopOverrides);

        $attempts = new MtUniCreditFinancingAttemptRepository($db, $clock);
        $locks = new MtUniCreditOperationLockRepository($db, $clock);
        $smartUcfProbe = (object) array('calls' => array());
        $defaultExecutor = $httpExecutor;
        $smartUcfClient = new MtUniCreditSmartUcfSessionClient(
            null,
            null,
            function (array $options) use ($smartUcfProbe, $defaultExecutor): array {
                $smartUcfProbe->calls[] = $options;
                if (is_callable($defaultExecutor)) {
                    $result = call_user_func($defaultExecutor, $options);
                    if (!is_array($result)) {
                        return array('body' => '', 'error' => 'invalid executor result', 'http_code' => 0);
                    }

                    return array(
                        'body' => isset($result['body']) ? (string) $result['body'] : '',
                        'error' => isset($result['error']) ? (string) $result['error'] : '',
                        'http_code' => isset($result['http_code']) ? (int) $result['http_code'] : 0,
                    );
                }

                return array(
                    'body' => self::successBody(),
                    'error' => '',
                    'http_code' => 200,
                );
            }
        );
        $process1 = MtUniCreditProcess1ServiceFactory::coordinator($db, $smartUcfClient, $clock);
        $bankStatuses = MtUniCreditProcess1ServiceFactory::bankStatuses($db, $clock);
        $smartUcfLifecycle = new MtUniCreditSmartUcfLifecycleRepository($db, $clock);
        $lifecycle = new MtUniCreditControlPanelOrderLifecycleService(
            $attempts,
            $locks,
            $services['client'],
            null,
            $process1,
            $bankStatuses
        );
        $submission = new MtUniCreditCheckoutFinancingSubmissionService(
            $attempts,
            $lifecycle,
            $services['credentials'],
            new MtUniCreditShopConfigurationCache(
                new MtUniCreditShopCacheRepository($db, $clock),
                null,
                MtUniCreditBootstrap::shopCachePersistenceFromDb($db)
            )
        );

        $storefront = new MtUniCreditStorefrontFinancingSubmissionService(
            $attempts,
            $locks,
            $lifecycle,
            $services['credentials'],
            new MtUniCreditShopConfigurationCache(
                new MtUniCreditShopCacheRepository($db, $clock),
                null,
                MtUniCreditBootstrap::shopCachePersistenceFromDb($db)
            )
        );

        return array(
            'memoryDb' => $memoryDb,
            'db' => $db,
            'transport' => $transport,
            'client' => $services['client'],
            'attempts' => $attempts,
            'locks' => $locks,
            'lifecycle' => $lifecycle,
            'submission' => $submission,
            'storefront' => $storefront,
            'storeId' => $storeId,
            'smartUcfProbe' => $smartUcfProbe,
            'process1' => $process1,
            'bankStatuses' => $bankStatuses,
            'smartUcfLifecycle' => $smartUcfLifecycle,
            'clock' => $clock,
        );
    }

    /**
     * Product Step 2 equivalent input for MtUniCreditStorefrontFinancingSubmissionService.
     *
     * @param array<string, mixed> $stack
     * @param int $orderId
     * @return array<string, mixed>
     */
    public static function productStorefrontInput(array $stack, $orderId)
    {
        $storeId = (int) $stack['storeId'];
        $orderId = (int) $orderId;
        $line = new MtUniCreditProductLine(
            42,
            'Example',
            'EX',
            array(7),
            1,
            500.0,
            500.0,
            500.0,
            0,
            array(),
            0
        );

        return array(
            'entry_point' => MtUniCreditOperationEntryPoint::PRODUCT,
            'store_id' => $storeId,
            'currency_code' => 'BGN',
            'scheme_key' => 'standard|KOPSTD|12|0',
            'product_line' => $line,
            'customer' => array(
                'firstname' => 'Example',
                'lastname' => 'Customer',
                'email' => 'example.customer@example.test',
                'telephone' => '+359880000000',
                'address_1' => 'ul. Example 1',
                'city' => 'Sofia',
                'postcode' => '1000',
                'country' => 'Bulgaria',
                'country_id' => 33,
                'zone' => 'Sofia',
                'zone_id' => 1,
            ),
            'session' => array(),
            'invoice_prefix' => 'INV',
            'store_name' => 'Test',
            'store_url' => 'https://example.test/',
            'language_id' => 1,
            'currency_id' => 1,
            'currency_value' => 1.0,
            'add_order' => function ($orderData) use ($stack, $orderId, $storeId) {
                $stack['memoryDb']->seedOrder($orderId, $storeId, MtUniCreditConstants::EXTENSION_CODE);

                return $orderId;
            },
            'load_order' => function ($loadedId) use ($storeId) {
                return Phase7TestHarness::orderRow((int) $loadedId, $storeId);
            },
        );
    }

    /**
     * @param Phase2MemoryDb $memoryDb
     * @param int $storeId
     * @param int $now
     * @param array<string, mixed> $shopOverrides
     * @return void
     */
    public static function seedFreshCache(
        Phase2MemoryDb $memoryDb,
        int $storeId,
        int $now = self::NOW,
        array $shopOverrides = array()
    ): void {
        $db = new MtUniCreditDbAdapter($memoryDb, 'oc_');
        $clock = new MtUniCreditPersistenceClock(function () use ($now): int {
            return (int) $now;
        });
        $cache = new MtUniCreditShopCacheRepository($db, $clock);
        $cache->replaceValidated(
            $storeId,
            Phase4TestHarness::TEST_UNICID,
            mtuc4_valid_shop_snapshot($shopOverrides)
        );
    }

    /**
     * 500 BGN / KOPSTD / 12 months calculation using the shop fixture.
     *
     * @param array<string, mixed>|null $shop
     * @return MtUniCreditCalculationResult
     */
    public static function calculation($shop = null): MtUniCreditCalculationResult
    {
        $shop = is_array($shop) ? $shop : mtuc4_valid_shop_snapshot();

        return (new MtUniCreditCalculator())->calculateScheme(
            $shop,
            500.0,
            new MtUniCreditAvailableScheme(
                'standard',
                'KOPSTD',
                12,
                0,
                array(),
                array(
                    'coeff' => 1.05,
                    'interestPercent' => 5.5,
                    'installmentCount' => 12,
                    'onlineProductCode' => 'KOPSTD',
                )
            ),
            0.0
        );
    }

    /**
     * @return string
     */
    public static function successBody(): string
    {
        return '{"sucfOnlineSessionID":"sess-phase9-ok"}';
    }

    /**
     * @return string
     */
    public static function rejectBody(): string
    {
        return '{"errorCode":"E1","errorText":"Rejected by bank"}';
    }

    /**
     * Seed OC order row so bank-status ownership resolves.
     *
     * @param Phase2MemoryDb $memoryDb
     * @param int $orderId
     * @param int $storeId
     * @return void
     */
    public static function seedBankOrder(Phase2MemoryDb $memoryDb, int $orderId, int $storeId): void
    {
        $memoryDb->seedOrder($orderId, $storeId, MtUniCreditConstants::EXTENSION_CODE);
    }

    /**
     * @param array<string, mixed> $stack
     * @param int $orderId
     * @return string|null
     */
    public static function bankStatusId(array $stack, int $orderId): ?string
    {
        $row = $stack['bankStatuses']->findByOrderId((int) $stack['storeId'], $orderId);
        if ($row === null) {
            return null;
        }

        return (string) $row['status_id'];
    }

    /**
     * @param object $probe
     * @return int
     */
    public static function smartUcfCallCount($probe): int
    {
        return isset($probe->calls) && is_array($probe->calls) ? count($probe->calls) : 0;
    }

    /**
     * Decode CURLOPT_POSTFIELDS JSON from the last SmartUCF probe call.
     *
     * @param object $probe
     * @param int $index
     * @return array<string, mixed>|null
     */
    public static function smartUcfPayloadAt($probe, int $index = 0): ?array
    {
        if (!isset($probe->calls[$index]) || !is_array($probe->calls[$index])) {
            return null;
        }
        $options = $probe->calls[$index];
        $raw = isset($options[CURLOPT_POSTFIELDS]) ? (string) $options[CURLOPT_POSTFIELDS] : '';
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param int $orderId
     * @param int $storeId
     * @return array<string, mixed>
     */
    public static function submitInput(int $orderId, int $storeId): array
    {
        return array(
            'store_id' => $storeId,
            'order_id' => $orderId,
            'order' => Phase7TestHarness::orderRow($orderId, $storeId),
            'order_products' => Phase7TestHarness::orderProducts(),
            'cart_context' => Phase7TestHarness::cartContext(),
        );
    }

    /**
     * Enqueue CP login + order create success responses.
     *
     * @param Phase4FakeCpHttpTransport $transport
     * @return void
     */
    public static function enqueueCpCreateSuccess(Phase4FakeCpHttpTransport $transport): void
    {
        $payloads = Phase7TestHarness::loginAndOrderSuccessPayloads();
        $transport->enqueueJson(200, $payloads['login']);
        $transport->enqueueJson(201, $payloads['order']);
    }
}
