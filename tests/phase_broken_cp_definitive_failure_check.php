<?php

/**
 * Definitive CP create failure → bank_send_failed_cp + Thank You + no SmartUCF.
 * Covers Product / Cart / Checkout × Process 1 / Process 2, plus true ambiguity.
 *
 * Run: php tests/phase_broken_cp_definitive_failure_check.php
 *
 * PHP 7.3 compatible. Offline. No network.
 */
require_once __DIR__ . '/bootstrap.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DIR_STORAGE')) {
    mtuc_test_define_dir_storage('mtuc-broken-cp-definitive');
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
}
if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'oc_');
}

require_once $lib . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . '/support/phase2_memory_db.php';
require_once __DIR__ . '/support/phase4_harness.php';
require_once __DIR__ . '/support/phase5_harness.php';
require_once __DIR__ . '/support/phase7_harness.php';
require_once __DIR__ . '/support/phase9_harness.php';
require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

$failures = array();
$passes = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucBrokenCp_assert($condition, $message)
{
    global $failures, $passes;
    if ($condition) {
        $passes++;
        echo 'PASS  ' . $message . PHP_EOL;

        return;
    }
    $failures[] = $message;
    echo 'FAIL  ' . $message . PHP_EOL;
}

/**
 * @param Phase4FakeCpHttpTransport $transport
 * @param int $status
 * @param array<string, mixed>|null $body
 * @return void
 */
function mtucBrokenCp_enqueueCreateFailure(Phase4FakeCpHttpTransport $transport, $status, $body = null)
{
    $payloads = Phase7TestHarness::loginAndOrderSuccessPayloads();
    $transport->enqueueJson(200, $payloads['login']);
    if ($body === null) {
        $body = array('error' => 'forbidden');
    }
    $transport->enqueueJson((int) $status, $body);
}

/**
 * @param string $label
 * @param string $entry checkout|product|cart
 * @param bool $process2
 * @param int $httpStatus
 * @return void
 */
function mtucBrokenCp_assertDefinitive($label, $entry, $process2, $httpStatus)
{
    $transport = new Phase4FakeCpHttpTransport();
    mtucBrokenCp_enqueueCreateFailure($transport, $httpStatus);
    $shopOverrides = $process2 ? array('uni_proces' => 1) : array();
    $stack = Phase9TestHarness::stack($transport, null, null, Phase5TestHarness::STORE_A, $shopOverrides);
    $orderId = 880000 + ($process2 ? 1000 : 0) + ($entry === 'checkout' ? 1 : ($entry === 'product' ? 2 : 3))
        + (int) $httpStatus;

    if ($entry === 'checkout') {
        Phase9TestHarness::seedBankOrder($stack['memoryDb'], $orderId, $stack['storeId']);
        $input = $process2
            ? Phase9TestHarness::submitInputProcess2($orderId, $stack['storeId'])
            : Phase9TestHarness::submitInput($orderId, $stack['storeId']);
        $result = $stack['submission']->submit($input);
    } else {
        $input = $entry === 'product'
            ? Phase9TestHarness::productStorefrontInput($stack, $orderId)
            : Phase9TestHarness::cartStorefrontInput($stack, $orderId);
        $result = $stack['storefront']->submit($input);
    }

    mtucBrokenCp_assert(empty($result['success']), $label . ': failure');
    mtucBrokenCp_assert(empty($result['cp_succeeded']), $label . ': cp_succeeded=false');
    mtucBrokenCp_assert(empty($result['ambiguous_blocked']), $label . ': not ambiguous');
    mtucBrokenCp_assert(
        (string) (isset($result['bank_status']) ? $result['bank_status'] : '')
            === MtUniCreditBankStatus::SEND_FAILED_CP,
        $label . ': bank_send_failed_cp'
    );
    mtucBrokenCp_assert(
        MtUniCreditBankStatus::canonicalLabel(MtUniCreditBankStatus::SEND_FAILED_CP)
            === MtUniCreditBankStatus::LABEL_SEND_FAILED_CP,
        $label . ': public label Неуспешно изпратен Банка - КП'
    );
    mtucBrokenCp_assert(
        !empty($result['apply_native_order_status']),
        $label . ': apply_native authorised (standard emails)'
    );
    mtucBrokenCp_assert(
        MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveCheckoutCpFailureTerminal($result),
        $label . ': Thank You detector'
    );
    mtucBrokenCp_assert(
        !MtUniCreditFinancingTerminalNavigationSupport::isCpCreateFailureStayOnPage($result),
        $label . ': not stay-page modal'
    );
    mtucBrokenCp_assert(
        Phase9TestHarness::smartUcfCallCount($stack['smartUcfProbe']) === 0,
        $label . ': SmartUCF NOT CALLED'
    );
    mtucBrokenCp_assert(
        Phase9TestHarness::countStatusPatches($transport) === 0,
        $label . ': CP status PATCH NOT SENT'
    );

    $session = array();
    $json = MtUniCreditFinancingTerminalNavigationSupport::enrichDefinitiveCheckoutCpFailureThankYou(
        array(
            'success' => false,
            'order_id' => (int) $result['order_id'],
            'bank_status' => MtUniCreditBankStatus::SEND_FAILED_CP,
        ),
        $session,
        (int) $result['order_id'],
        'https://shop.example.test/index.php?route=checkout/success'
    );
    mtucBrokenCp_assert(
        !empty($json['redirect'])
            && strpos((string) $json['redirect'], 'checkout/success') !== false,
        $label . ': success redirect used'
    );
    mtucBrokenCp_assert(
        (string) $json['step'] === MtUniCreditFinancingTerminalNavigationSupport::STEP_CP_TERMINAL_FAILED,
        $label . ': step cp_terminal_failed'
    );
    mtucBrokenCp_assert(
        strpos((string) $json['message'], MtUniCreditBankStatus::LABEL_SEND_FAILED_CP) === false
            && strpos((string) $json['message'], 'Поръчката е създадена') !== false,
        $label . ': customer message without internal diagnostics'
    );

    $adapter = new MtUniCreditDbAdapter($stack['memoryDb'], 'oc_');
    $presentation = new MtUniCreditFinancingPresentationService(
        new MtUniCreditFinancingPresentationRepository($adapter)
    );
    $mailRows = $presentation->filterCustomerFacingRows(
        $presentation->rowsForOrder($stack['storeId'], (int) $result['order_id'], MtUniCreditFinancingPresentationAudience::CUSTOMER)
    );
    $bankRow = null;
    foreach ($mailRows as $row) {
        if (
            is_array($row)
            && isset($row['label'])
            && (string) $row['label'] === MtUniCreditFinancingLeasingPresenter::LABEL_BANK_STATUS
        ) {
            $bankRow = $row;
            break;
        }
    }
    mtucBrokenCp_assert(is_array($bankRow), $label . ': standard mail bank-status row present');
    mtucBrokenCp_assert(
        is_array($bankRow)
            && (string) $bankRow['value'] === MtUniCreditBankStatus::LABEL_SEND_FAILED_CP,
        $label . ': mail Статус към банката = Неуспешно изпратен Банка - КП'
    );
}

// Definitive matrix: Process 1/2 × Product/Cart/Checkout × endpoint rejection statuses
foreach (array(false, true) as $process2) {
    $pLabel = $process2 ? 'P2' : 'P1';
    foreach (array('checkout', 'product', 'cart') as $entry) {
        foreach (array(403, 404, 405, 410) as $status) {
            mtucBrokenCp_assertDefinitive(
                $pLabel . '/' . $entry . '/HTTP' . $status,
                $entry,
                $process2,
                $status
            );
        }
    }
}

// Canonical 422 invalid_payload remains definitive (Checkout + Product)
foreach (array('checkout', 'product') as $entry) {
    $transport = new Phase4FakeCpHttpTransport();
    $payloads = Phase7TestHarness::loginAndOrderSuccessPayloads();
    $transport->enqueueJson(200, $payloads['login']);
    $transport->enqueueJson(422, array(
        'success' => false,
        'error' => 'invalid_payload',
        'message' => 'invalid',
        'data' => new stdClass(),
    ));
    $stack = Phase9TestHarness::stack($transport);
    $orderId = $entry === 'checkout' ? 881001 : 881002;
    if ($entry === 'checkout') {
        Phase9TestHarness::seedBankOrder($stack['memoryDb'], $orderId, $stack['storeId']);
        $result = $stack['submission']->submit(Phase9TestHarness::submitInput($orderId, $stack['storeId']));
    } else {
        $result = $stack['storefront']->submit(Phase9TestHarness::productStorefrontInput($stack, $orderId));
    }
    mtucBrokenCp_assert(
        (string) $result['bank_status'] === MtUniCreditBankStatus::SEND_FAILED_CP
            && empty($result['ambiguous_blocked']),
        'canonical 422/' . $entry . ': bank_send_failed_cp'
    );
}

// True ambiguous outcomes must NOT invent bank_send_failed_cp
$ambiguousCases = array(
    'timeout' => function (Phase4FakeCpHttpTransport $t) {
        $payloads = Phase7TestHarness::loginAndOrderSuccessPayloads();
        $t->enqueueJson(200, $payloads['login']);
        $t->enqueueTimeout();
    },
    'connection' => function (Phase4FakeCpHttpTransport $t) {
        $payloads = Phase7TestHarness::loginAndOrderSuccessPayloads();
        $t->enqueueJson(200, $payloads['login']);
        $t->enqueueConnectionFailure();
    },
    'http500' => function (Phase4FakeCpHttpTransport $t) {
        $payloads = Phase7TestHarness::loginAndOrderSuccessPayloads();
        $t->enqueueJson(200, $payloads['login']);
        $t->enqueueJson(500, array(
            'success' => false,
            'error' => 'server_error',
            'message' => 'boom',
            'data' => new stdClass(),
        ));
    },
    'noncanonical422' => function (Phase4FakeCpHttpTransport $t) {
        $payloads = Phase7TestHarness::loginAndOrderSuccessPayloads();
        $t->enqueueJson(200, $payloads['login']);
        $t->enqueueJson(422, array('success' => false, 'message' => 'invalid'));
    },
);

$ambOrder = 882000;
foreach ($ambiguousCases as $name => $enqueue) {
    $transport = new Phase4FakeCpHttpTransport();
    call_user_func($enqueue, $transport);
    $stack = Phase9TestHarness::stack($transport);
    $ambOrder++;
    Phase9TestHarness::seedBankOrder($stack['memoryDb'], $ambOrder, $stack['storeId']);
    $result = $stack['submission']->submit(Phase9TestHarness::submitInput($ambOrder, $stack['storeId']));
    mtucBrokenCp_assert(!empty($result['ambiguous_blocked']), 'ambiguous ' . $name . ': blocked');
    mtucBrokenCp_assert(
        (string) (isset($result['bank_status']) ? $result['bank_status'] : '')
            !== MtUniCreditBankStatus::SEND_FAILED_CP,
        'ambiguous ' . $name . ': no false bank_send_failed_cp'
    );
    mtucBrokenCp_assert(
        !MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveCheckoutCpFailureTerminal($result),
        'ambiguous ' . $name . ': not Thank You terminal'
    );
    mtucBrokenCp_assert(
        Phase9TestHarness::smartUcfCallCount($stack['smartUcfProbe']) === 0,
        'ambiguous ' . $name . ': SmartUCF = 0'
    );
}

// ---------------------------------------------------------------------------
// Pre-order gate: broken CP destination must NOT abort local order materialization
// (manual __broken_cp__ packaging URL → Client construct used to throw → error_generic).
// ---------------------------------------------------------------------------
$transportBrokenDest = new Phase4FakeCpHttpTransport();
$memoryBroken = new Phase2MemoryDb();
$servicesBroken = Phase4TestHarness::services(
    $transportBrokenDest,
    $memoryBroken,
    Phase5TestHarness::STORE_A,
    Phase9TestHarness::NOW
);
$stackBroken = Phase9TestHarness::stack($transportBrokenDest, null, $memoryBroken);
$brokenClient = null;
$constructThrew = false;
try {
    $brokenClient = new MtUniCreditControlPanelClient(
        $servicesBroken['credentials'],
        $servicesBroken['tokens'],
        $transportBrokenDest,
        Phase4TestHarness::TEST_SHOP_URL,
        Phase5TestHarness::STORE_A,
        'https://cp-test.example.com/__broken_cp__',
        function () {
            return Phase9TestHarness::NOW;
        },
        Phase4TestHarness::offlineDestinationPolicy()
    );
} catch (Exception $exception) {
    $constructThrew = true;
}
mtucBrokenCp_assert($constructThrew === false && $brokenClient !== null, 'broken destination: Client construct soft-fails');

$dbBroken = $stackBroken['db'];
$clockBroken = $stackBroken['clock'];
$attemptsBroken = $stackBroken['attempts'];
$locksBroken = $stackBroken['locks'];
$process1Broken = MtUniCreditProcess1ServiceFactory::coordinator(
    $dbBroken,
    null,
    $clockBroken,
    $brokenClient
);
$bankBroken = MtUniCreditProcess1ServiceFactory::bankStatuses($dbBroken, $clockBroken);
$process2Broken = MtUniCreditProcessTwoServiceFactory::coordinator(
    $dbBroken,
    $brokenClient,
    new MtUniCreditRecordingProcessTwoMailer(),
    $clockBroken,
    Phase4TestHarness::testSecretInput()
);
$lifecycleBroken = new MtUniCreditControlPanelOrderLifecycleService(
    $attemptsBroken,
    $locksBroken,
    $brokenClient,
    null,
    $process1Broken,
    $bankBroken,
    $process2Broken
);
$storefrontBroken = new MtUniCreditStorefrontFinancingSubmissionService(
    $attemptsBroken,
    $locksBroken,
    $lifecycleBroken,
    $servicesBroken['credentials'],
    new MtUniCreditShopConfigurationCache(
        new MtUniCreditShopCacheRepository($dbBroken, $clockBroken),
        null,
        MtUniCreditBootstrap::shopCachePersistenceFromDb($dbBroken)
    )
);
$orderBroken = 883001;
$createsBroken = 0;
$inputBroken = Phase9TestHarness::productStorefrontInput($stackBroken, $orderBroken);
$originalAddBroken = $inputBroken['add_order'];
$inputBroken['add_order'] = function ($orderData) use (&$createsBroken, $originalAddBroken) {
    $createsBroken++;

    return (int) call_user_func($originalAddBroken, $orderData);
};
$resultBroken = $storefrontBroken->submit($inputBroken);
mtucBrokenCp_assert($createsBroken === 1, 'broken destination: local order materialization attempted/completed');
mtucBrokenCp_assert((int) $resultBroken['order_id'] === $orderBroken, 'broken destination: local order exists');
mtucBrokenCp_assert(empty($resultBroken['cp_succeeded']), 'broken destination: CP order absent');
mtucBrokenCp_assert(
    (string) (isset($resultBroken['bank_status']) ? $resultBroken['bank_status'] : '')
        === MtUniCreditBankStatus::SEND_FAILED_CP,
    'broken destination: bank_send_failed_cp'
);
mtucBrokenCp_assert(
    MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveCheckoutCpFailureTerminal($resultBroken),
    'broken destination: Thank You terminal'
);
mtucBrokenCp_assert(
    (string) (isset($resultBroken['error']) ? $resultBroken['error'] : '') !== 'unavailable'
        && strpos((string) (isset($resultBroken['message']) ? $resultBroken['message'] : ''), 'не е налично') === false,
    'broken destination: NOT financing-unavailable before order'
);
mtucBrokenCp_assert(count($transportBrokenDest->requests) === 0, 'broken destination: zero CP HTTP (pre-send config)');

// Counter-test: invalid local scheme must not create an order.
$transportInvalid = new Phase4FakeCpHttpTransport();
$stackInvalid = Phase9TestHarness::stack($transportInvalid);
$orderInvalid = 883010;
$createsInvalid = 0;
$inputInvalid = Phase9TestHarness::productStorefrontInput($stackInvalid, $orderInvalid);
$inputInvalid['scheme_key'] = 'not-a-valid-scheme';
$originalAddInvalid = $inputInvalid['add_order'];
$inputInvalid['add_order'] = function ($orderData) use (&$createsInvalid, $originalAddInvalid) {
    $createsInvalid++;

    return (int) call_user_func($originalAddInvalid, $orderData);
};
$resultInvalid = $stackInvalid['storefront']->submit($inputInvalid);
mtucBrokenCp_assert(empty($resultInvalid['success']), 'invalid local: failure');
mtucBrokenCp_assert($createsInvalid === 0, 'invalid local: no order materialization');
mtucBrokenCp_assert((int) (isset($resultInvalid['order_id']) ? $resultInvalid['order_id'] : 0) === 0, 'invalid local: no order_id');

echo PHP_EOL . 'broken-cp definitive failure: ' . $passes . ' passed, ' . count($failures) . ' failed' . PHP_EOL;
if ($failures) {
    foreach ($failures as $failure) {
        echo 'FAIL  ' . $failure . PHP_EOL;
    }
}
exit(count($failures) === 0 ? 0 : 1);
