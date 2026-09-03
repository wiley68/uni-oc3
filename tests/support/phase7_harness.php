<?php

require_once __DIR__ . '/phase2_memory_db.php';
require_once __DIR__ . '/phase4_harness.php';
require_once __DIR__ . '/phase5_harness.php';
require_once dirname(__DIR__) . '/fixtures/cp_shop_snapshot.php';

/**
 * Phase 7 checkout CP order lifecycle harness.
 */
final class Phase7TestHarness
{
    const ORDER_ID = 7001;

    const NOW = 1700000000;

    /**
     * @param Phase4FakeCpHttpTransport $transport
     * @param Phase2MemoryDb|null $memoryDb
     * @param int $storeId
     * @return array<string, mixed>
     */
    public static function stack(Phase4FakeCpHttpTransport $transport, $memoryDb = null, $storeId = Phase5TestHarness::STORE_A)
    {
        $memoryDb = $memoryDb !== null ? $memoryDb : new Phase2MemoryDb();
        $services = Phase4TestHarness::services($transport, $memoryDb, $storeId, self::NOW);
        $db = new MtUniCreditDbAdapter($memoryDb, 'oc_');
        $clock = new MtUniCreditPersistenceClock(function () {
            return self::NOW;
        });
        Phase5TestHarness::seedFreshCache($memoryDb, $storeId, self::NOW);

        $attempts = new MtUniCreditFinancingAttemptRepository($db, $clock);
        $locks = new MtUniCreditOperationLockRepository($db, $clock);
        $smartUcfProbe = (object) array('calls' => array());
        $smartUcfClient = new MtUniCreditSmartUcfSessionClient(
            null,
            null,
            function (array $options) use ($smartUcfProbe) {
                $smartUcfProbe->calls[] = $options;

                return array(
                    'body' => json_encode(array('sucfOnlineSessionID' => 'phase7-session-abc')),
                    'error' => '',
                    'http_code' => 200,
                );
            }
        );
        $process1 = MtUniCreditProcess1ServiceFactory::coordinator($db, $smartUcfClient, $clock);
        $bankStatuses = MtUniCreditProcess1ServiceFactory::bankStatuses($db, $clock);
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

        return array(
            'memoryDb' => $memoryDb,
            'db' => $db,
            'transport' => $transport,
            'client' => $services['client'],
            'attempts' => $attempts,
            'locks' => $locks,
            'lifecycle' => $lifecycle,
            'submission' => $submission,
            'storeId' => $storeId,
            'smartUcfProbe' => $smartUcfProbe,
            'process1' => $process1,
            'bankStatuses' => $bankStatuses,
        );
    }

    /**
     * @param int $orderId
     * @param int $storeId
     * @param float $total
     * @return array<string, mixed>
     */
    public static function orderRow($orderId = self::ORDER_ID, $storeId = Phase5TestHarness::STORE_A, $total = 500.0)
    {
        $row = Phase5TestHarness::orderRow($orderId, $storeId, $total);
        $row['firstname'] = 'Example';
        $row['lastname'] = 'Customer';
        $row['email'] = 'example.customer@example.test';
        $row['telephone'] = '+359 88 000 0000';
        $row['payment_address_1'] = 'ul. Example 1';
        $row['payment_city'] = 'Sofia';
        $row['payment_postcode'] = '1000';
        $row['payment_country'] = 'Bulgaria';
        $row['shipping_address_1'] = 'ul. Delivery 2';
        $row['shipping_city'] = 'Sofia';
        $row['shipping_postcode'] = '1000';
        $row['shipping_country'] = 'Bulgaria';

        return $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function orderProducts()
    {
        return array(
            array(
                'product_id' => 42,
                'name' => 'Example_Product',
                'quantity' => 1,
                'price' => 500.0,
                'total' => 500.0,
            ),
        );
    }

    /**
     * @param float $total
     * @return MtUniCreditCartContext
     */
    public static function cartContext($total = 500.0)
    {
        $factory = new MtUniCreditOc3CartContextFactory(function () {
            return array(7);
        });

        return $factory->create(Phase5TestHarness::cartProducts(), $total);
    }

    /**
     * @return array<string, mixed>
     */
    public static function loginAndOrderSuccessPayloads()
    {
        return array(
            'login' => Phase4TestHarness::loginSuccessPayload(),
            'order' => array(
                'success' => true,
                'message' => 'created',
                'data' => array('id' => 555001),
            ),
        );
    }

    /**
     * Count POST /orders requests on a transport.
     *
     * @param Phase4FakeCpHttpTransport $transport
     * @return int
     */
    public static function countOrderPosts(Phase4FakeCpHttpTransport $transport)
    {
        $count = 0;
        foreach ($transport->requests as $request) {
            if (
                strtoupper((string) $request['method']) === 'POST'
                && substr((string) $request['url'], -7) === '/orders'
            ) {
                $count++;
            }
        }

        return $count;
    }
}
