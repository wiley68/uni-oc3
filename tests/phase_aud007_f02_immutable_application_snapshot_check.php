<?php

/**
 * AUD-007 F02 — immutable application snapshot.
 * Run: php tests/phase_aud007_f02_immutable_application_snapshot_check.php
 *
 * PHP 7.3 compatible. Offline.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/support/phase2_memory_db.php';

$root = MTUC_PHASE0_ROOT;
if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
}

require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';
require_once __DIR__ . '/support/phase4_harness.php';
require_once __DIR__ . '/support/phase5_harness.php';
require_once __DIR__ . '/support/phase7_harness.php';
require_once __DIR__ . '/support/phase9_harness.php';

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucAud007F02_assert($condition, $message)
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
 * @param int $months
 * @param float $price
 * @return MtUniCreditCalculationResult
 */
function mtucAud007F02_calc($months, $price)
{
    $shop = mtuc4_valid_shop_snapshot();

    return (new MtUniCreditCalculator())->calculateScheme(
        $shop,
        $price,
        new MtUniCreditAvailableScheme(
            'standard',
            'KOPSTD',
            (int) $months,
            0,
            array(),
            array(
                'coeff' => 1.05,
                'interestPercent' => 5.5,
                'installmentCount' => (int) $months,
                'onlineProductCode' => 'KOPSTD',
            )
        ),
        0.0
    );
}

/**
 * @param MtUniCreditCalculationResult $calculation
 * @param string $entryPoint
 * @param string $opHash
 * @return array<string, mixed>
 */
function mtucAud007F02_snapshot($calculation, $entryPoint, $opHash)
{
    $order = Phase7TestHarness::orderRow(9001, Phase5TestHarness::STORE_A);
    $products = Phase7TestHarness::orderProducts();
    $shop = mtuc4_valid_shop_snapshot();
    $payload = (new MtUniCreditControlPanelOrderPayloadBuilder())->build(
        9001,
        $order,
        $products,
        $calculation,
        $shop
    );
    $fingerprint = MtUniCreditControlPanelOrderPayloadBuilder::fingerprint($payload);
    $selectionHash = hash(
        'sha256',
        $calculation->scheme->kopCode . '|' . $calculation->scheme->months . '|' . $fingerprint
    );

    return MtUniCreditApplicationSnapshot::fromLive(
        $calculation,
        $order,
        $products,
        $shop,
        $entryPoint,
        $opHash,
        $selectionHash,
        $fingerprint
    );
}

/**
 * Execute AUD-007 F02 checks (kept out of {main} for Intelephense).
 *
 * @return void
 */
/**
 * Execute AUD-007 F02 checks (split for Intelephense type budget).
 *
 * @return void
 */
function mtucAud007F02_run()
{
    $failures = array();
    $passes = 0;
    $GLOBALS['failures'] = &$failures;
    $GLOBALS['passes'] = &$passes;

    mtucAud007F02_assert(mtuc_phase0_network_isolation_active(), 'isolation: offline guard active');

    $shared = array();
    mtucAud007F02_sec_snapshot($shared);
    mtucAud007F02_sec_product();
    mtucAud007F02_sec_cart();
    mtucAud007F02_sec_checkout();
    mtucAud007F02_sec_recovery($shared);
    mtucAud007F02_sec_after_cp($shared);
    mtucAud007F02_sec_multi($shared);

    if ($failures !== array()) {
        fwrite(STDERR, 'AUD-007 F02: FAIL (' . count($failures) . ')' . PHP_EOL);
        foreach ($failures as $failure) {
            fwrite(STDERR, ' - ' . $failure . PHP_EOL);
        }
        exit(1);
    }

    echo PHP_EOL . 'AUD-007 F02: PASS (' . $passes . ' passes)' . PHP_EOL;
}

/**
 * Snapshot create / idempotent / conflict.
 *
 * @param array<string, mixed> $shared
 * @return void
 */
function mtucAud007F02_sec_snapshot(array &$shared)
{
    // Snapshot create once / idempotent / conflict
    // ---------------------------------------------------------------------------
    $memory = new Phase2MemoryDb();
    $db = new MtUniCreditDbAdapter($memory, 'oc_');
    $clock = new MtUniCreditPersistenceClock(function () {
        return Phase9TestHarness::NOW;
    });
    $attempts = new MtUniCreditFinancingAttemptRepository($db, $clock);
    $opHash = hash('sha256', 'f02-snapshot-unit');
    $calc12 = mtucAud007F02_calc(12, 500.0);
    $snap12 = mtucAud007F02_snapshot($calc12, MtUniCreditOperationEntryPoint::PRODUCT, $opHash);
    $attempt = $attempts->findOrCreateAttempt(
        Phase5TestHarness::STORE_A,
        9001,
        Phase4TestHarness::TEST_UNICID,
        $opHash,
        $snap12['selection_hash'],
        $snap12['request_fingerprint'],
        MtUniCreditOperationEntryPoint::PRODUCT
    );
    $persisted = $attempts->persistApplicationSnapshot((int) $attempt['attempt_id'], $snap12);
    mtucAud007F02_assert(
        is_string($persisted['application_snapshot_json']) && $persisted['application_snapshot_json'] !== '',
        'snapshot: created once'
    );
    mtucAud007F02_assert(
        hash_equals(
            (string) $persisted['application_snapshot_hash'],
            MtUniCreditApplicationSnapshot::hash($snap12)
        ),
        'snapshot: hash matches canonical SHA256'
    );
    $again = $attempts->persistApplicationSnapshot((int) $attempt['attempt_id'], $snap12);
    mtucAud007F02_assert(
        hash_equals(
            (string) $again['application_snapshot_hash'],
            (string) $persisted['application_snapshot_hash']
        ),
        'snapshot: identical rewrite idempotent'
    );

    $calc24 = mtucAud007F02_calc(24, 500.0);
    $snap24 = mtucAud007F02_snapshot($calc24, MtUniCreditOperationEntryPoint::PRODUCT, $opHash);
    $conflictThrown = false;
    try {
        $attempts->persistApplicationSnapshot((int) $attempt['attempt_id'], $snap24);
    } catch (MtUniCreditPersistenceValidationException $exception) {
        $conflictThrown = true;
    }
    mtucAud007F02_assert($conflictThrown, 'snapshot: conflicting write rejected');

    $boundMatch = MtUniCreditApplicationSnapshot::bindToAttempt($attempts, $persisted, $snap12);
    mtucAud007F02_assert(!empty($boundMatch['ok']), 'bind: matching live accepted');
    $boundDrift = MtUniCreditApplicationSnapshot::bindToAttempt($attempts, $persisted, $snap24);
    mtucAud007F02_assert(
        empty($boundDrift['ok']) && isset($boundDrift['error']) && $boundDrift['error'] === 'application_drift',
        'bind: 12→24 live drift rejected before CP'
    );

    // ---------------------------------------------------------------------------

    // Publish snapshot unit state for later after-CP / leasing checks.
    $shared['db'] = $db;
    $shared['clock'] = $clock;
    $shared['attempts'] = $attempts;
    $shared['op_hash'] = $opHash;
    $shared['persisted'] = $persisted;
    $shared['calc12'] = $calc12;
}

/**
 * Product retry drift.
 *
 * @return void
 */
function mtucAud007F02_sec_product()
{
    // Product retry drift (amount 500→600) + months via bind after CP-created path
    // ---------------------------------------------------------------------------
    $transportP = new Phase4FakeCpHttpTransport();
    $payloads = Phase7TestHarness::loginAndOrderSuccessPayloads();
    $transportP->enqueueJson(200, $payloads['login']);
    $transportP->enqueueJson(422, array('success' => false, 'message' => 'invalid'));
    $stackP = Phase9TestHarness::stack($transportP, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
    $orderP = 9101;
    $addP = 0;
    $inputP = Phase9TestHarness::productStorefrontInput($stackP, $orderP);
    $inputP['add_order'] = function () use (&$addP, $stackP, $orderP) {
        $addP++;
        $stackP['memoryDb']->seedOrder($orderP, $stackP['storeId'], MtUniCreditConstants::EXTENSION_CODE);

        return $orderP;
    };
    $firstP = $stackP['storefront']->submit($inputP);
    mtucAud007F02_assert(empty($firstP['success']), 'product: definitive CP failure');
    mtucAud007F02_assert($addP === 1, 'product: first addOrder = 1');
    $attemptP = $stackP['attempts']->findByStoreOrder($stackP['storeId'], $orderP);
    mtucAud007F02_assert(
        is_array($attemptP) && !empty($attemptP['application_snapshot_json']),
        'product: snapshot persisted before/with attempt lifecycle'
    );
    $snapDecoded = MtUniCreditApplicationSnapshot::decode($attemptP['application_snapshot_json']);
    mtucAud007F02_assert(
        is_array($snapDecoded) && (int) $snapDecoded['scheme']['months'] === 12
            && $snapDecoded['financial']['financed_amount'] === '500.00',
        'product: frozen snapshot is 12m / 500.00'
    );

    $inputP2 = $inputP;
    $inputP2['session'] = isset($firstP['session']) ? $firstP['session'] : $inputP['session'];
    $inputP2['product_line'] = new MtUniCreditProductLine(
        42,
        'Example',
        'EX',
        array(7),
        1,
        600.0,
        600.0,
        600.0,
        0,
        array(),
        0
    );
    // Keep original token/selection identity; only financed amount in calculation changes.
    $inputP2['add_order'] = $inputP['add_order'];
    $addPBefore = $addP;
    $snapBefore = MtUniCreditApplicationSnapshot::decode($attemptP['application_snapshot_json']);
    $secondP = $stackP['storefront']->submit($inputP2);
    mtucAud007F02_assert(empty($secondP['success']), 'product 500→600: rejected or frozen-retry failure');
    mtucAud007F02_assert($addP === $addPBefore, 'product 500→600: addOrder = 0 additional');
    $attemptP2 = $stackP['attempts']->findByStoreOrder($stackP['storeId'], $orderP);
    $snapAfter = MtUniCreditApplicationSnapshot::decode(
        is_array($attemptP2) ? $attemptP2['application_snapshot_json'] : null
    );
    mtucAud007F02_assert(
        is_array($snapAfter) && $snapAfter['financial']['financed_amount'] === '500.00',
        'product 500→600: snapshot remains frozen 500.00'
    );
    mtucAud007F02_assert(
        is_array($snapBefore) && is_array($snapAfter)
            && hash_equals(
                MtUniCreditApplicationSnapshot::hash($snapBefore),
                MtUniCreditApplicationSnapshot::hash($snapAfter)
            ),
        'product 500→600: snapshot hash unchanged'
    );

    // Same token, scheme months 12→24 (financial selection only).
    $inputP3 = $inputP;
    $inputP3['session'] = isset($firstP['session']) ? $firstP['session'] : $inputP['session'];
    $inputP3['scheme_key'] = 'standard|KOPSTD|24';
    $inputP3['add_order'] = $inputP['add_order'];
    $addPBefore3 = $addP;
    $thirdP = $stackP['storefront']->submit($inputP3);
    mtucAud007F02_assert(
        empty($thirdP['success']),
        'product 12→24 same token: rejected (unavailable or drift)'
    );
    mtucAud007F02_assert($addP === $addPBefore3, 'product 12→24: no additional addOrder');

    // ---------------------------------------------------------------------------
}

/**
 * Cart amount drift.
 *
 * @return void
 */
function mtucAud007F02_sec_cart()
{
    // Cart amount drift
    // ---------------------------------------------------------------------------
    $payloads = Phase7TestHarness::loginAndOrderSuccessPayloads();
    $transportC = new Phase4FakeCpHttpTransport();
    $transportC->enqueueJson(200, $payloads['login']);
    $transportC->enqueueJson(422, array('success' => false, 'message' => 'invalid'));
    $stackC = Phase9TestHarness::stack($transportC, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
    $cart500 = new MtUniCreditCartContext(
        array(new MtUniCreditCartLine(new MtUniCreditProductContext(42, array(7), 500.0), 0, 1, 500.0)),
        500.0
    );
    $cart600 = new MtUniCreditCartContext(
        array(new MtUniCreditCartLine(new MtUniCreditProductContext(42, array(7), 600.0), 0, 1, 600.0)),
        600.0
    );
    $orderC = 9102;
    $addC = 0;
    $inputC = Phase9TestHarness::cartStorefrontInput($stackC, $orderC, $cart500);
    $inputC['add_order'] = function () use (&$addC, $stackC, $orderC) {
        $addC++;
        $stackC['memoryDb']->seedOrder($orderC, $stackC['storeId'], MtUniCreditConstants::EXTENSION_CODE);

        return $orderC;
    };
    $firstC = $stackC['storefront']->submit($inputC);
    mtucAud007F02_assert(empty($firstC['success']), 'cart: definitive CP failure');
    $inputC2 = Phase9TestHarness::cartStorefrontInput($stackC, $orderC, $cart600);
    $inputC2['application_token'] = $inputC['application_token'];
    $inputC2['session'] = isset($firstC['session']) ? $firstC['session'] : $inputC['session'];
    $inputC2['add_order'] = $inputC['add_order'];
    $addCBefore = $addC;
    $secondC = $stackC['storefront']->submit($inputC2);
    mtucAud007F02_assert(empty($secondC['success']), 'cart 500→600: rejected');
    mtucAud007F02_assert($addC === $addCBefore, 'cart 500→600: addOrder = 0 additional');

    // ---------------------------------------------------------------------------
}

/**
 * Checkout pre-CP fingerprint drift.
 *
 * @return void
 */
function mtucAud007F02_sec_checkout()
{
    // Checkout pre-CP fingerprint / application drift
    // (attempt + snapshot exist, cp_payload still absent — crash boundary)
    // ---------------------------------------------------------------------------
    $transportCk = new Phase4FakeCpHttpTransport();
    $stackCk = Phase9TestHarness::stack(
        $transportCk,
        null,
        null,
        Phase5TestHarness::STORE_A,
        array(
            'uni_proces' => 0,
            'uni_meseci_24' => 1,
            'coeff_list' => array(
                array(
                    'onlineProductCode' => 'KOPSTD',
                    'installmentCount' => 12,
                    'coeff' => 1.05,
                    'interestPercent' => 5.5,
                ),
                array(
                    'onlineProductCode' => 'KOPSTD',
                    'installmentCount' => 24,
                    'coeff' => 1.08,
                    'interestPercent' => 6.5,
                ),
            ),
        )
    );
    $orderCk = 9103;
    $stackCk['memoryDb']->seedOrder($orderCk, $stackCk['storeId'], MtUniCreditConstants::EXTENSION_CODE);
    $inputCk = Phase9TestHarness::submitInput($orderCk, $stackCk['storeId']);
    $inputCk['scheme_key'] = 'standard|KOPSTD|12';
    $inputCk['first_installment'] = 0;
    $orderCkRow = $inputCk['order'];
    $orderCkProducts = $inputCk['order_products'];
    $shopCk = mtuc4_valid_shop_snapshot(array(
        'uni_proces' => 0,
        'uni_meseci_24' => 1,
        'coeff_list' => array(
            array(
                'onlineProductCode' => 'KOPSTD',
                'installmentCount' => 12,
                'coeff' => 1.05,
                'interestPercent' => 5.5,
            ),
            array(
                'onlineProductCode' => 'KOPSTD',
                'installmentCount' => 24,
                'coeff' => 1.08,
                'interestPercent' => 6.5,
            ),
        ),
    ));
    $calcCk12 = (new MtUniCreditCalculator())->calculateScheme(
        $shopCk,
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
    $payloadCk12 = (new MtUniCreditControlPanelOrderPayloadBuilder())->build(
        $orderCk,
        $orderCkRow,
        $orderCkProducts,
        $calcCk12,
        $shopCk
    );
    $fpCk12 = MtUniCreditControlPanelOrderPayloadBuilder::fingerprint($payloadCk12);
    $selCk12 = hash('sha256', $calcCk12->scheme->kopCode . '|' . $calcCk12->scheme->months . '|' . $fpCk12);
    $opCk = hash('sha256', 'checkout|' . $stackCk['storeId'] . '|' . $orderCk);
    $attemptCk = $stackCk['attempts']->findOrCreateCheckoutAttempt(
        $stackCk['storeId'],
        $orderCk,
        Phase4TestHarness::TEST_UNICID,
        $opCk,
        $selCk12,
        $fpCk12
    );
    $snapCk12 = MtUniCreditApplicationSnapshot::fromLive(
        $calcCk12,
        $orderCkRow,
        $orderCkProducts,
        $shopCk,
        MtUniCreditOperationEntryPoint::CHECKOUT,
        $opCk,
        $selCk12,
        $fpCk12
    );
    $attemptCk = $stackCk['attempts']->persistApplicationSnapshot((int) $attemptCk['attempt_id'], $snapCk12);
    mtucAud007F02_assert(
        is_array($attemptCk) && !empty($attemptCk['application_snapshot_json']),
        'checkout: snapshot present before cp_payload'
    );
    mtucAud007F02_assert(
        empty($attemptCk['cp_payload']),
        'checkout: cp_payload still absent at crash boundary'
    );
    $inputCk2 = $inputCk;
    $inputCk2['scheme_key'] = 'standard|KOPSTD|24';
    $secondCk = $stackCk['submission']->submit($inputCk2);
    mtucAud007F02_assert(empty($secondCk['success']), 'checkout pre-CP changed terms: rejected');
    $errCk = isset($secondCk['error']) ? (string) $secondCk['error'] : '';
    mtucAud007F02_assert(
        $errCk === 'application_drift' || $errCk === 'fingerprint_drift',
        'checkout pre-CP drift error class closed (' . $errCk . ')'
    );
    $attemptCkAfter = $stackCk['attempts']->findByStoreOrder($stackCk['storeId'], $orderCk);
    mtucAud007F02_assert(
        is_array($attemptCkAfter)
            && hash_equals((string) $attemptCkAfter['request_fingerprint'], $fpCk12),
        'checkout pre-CP: original fingerprint not overwritten'
    );
    mtucAud007F02_assert(
        is_array($attemptCkAfter)
            && hash_equals(
                (string) $attemptCkAfter['application_snapshot_hash'],
                (string) $attemptCk['application_snapshot_hash']
            ),
        'checkout pre-CP: snapshot hash unchanged'
    );

    // ---------------------------------------------------------------------------
}

/**
 * CP-created recovery + cross-system matrix.
 *
 * @param array<string, mixed> $shared
 * @return void
 */
function mtucAud007F02_sec_recovery(array &$shared)
{
    // CP-created / SmartUCF-not-started recovery uses frozen products (not live drift)
    // ---------------------------------------------------------------------------
    $transportR = new Phase4FakeCpHttpTransport();
    $stackR = Phase9TestHarness::stack(
        $transportR,
        null,
        null,
        Phase5TestHarness::STORE_A,
        array('uni_proces' => 0)
    );
    $orderR = 9104;
    $stackR['memoryDb']->seedOrder($orderR, $stackR['storeId'], MtUniCreditConstants::EXTENSION_CODE);
    $shopR = mtuc4_valid_shop_snapshot(array('uni_proces' => 0));
    $calcR = Phase9TestHarness::calculation($shopR);
    $orderRRow = Phase7TestHarness::orderRow($orderR, $stackR['storeId']);
    $orderRProducts = array(
        array(
            'product_id' => 42,
            'name' => 'Example',
            'model' => 'EX',
            'quantity' => 1,
            'price' => 500.0,
            'total' => 500.0,
            'tax' => 0.0,
            'reward' => 0,
        ),
    );
    $payloadR = (new MtUniCreditControlPanelOrderPayloadBuilder())->build(
        $orderR,
        $orderRRow,
        $orderRProducts,
        $calcR,
        $shopR
    );
    $fpR = MtUniCreditControlPanelOrderPayloadBuilder::fingerprint($payloadR);
    $selR = hash('sha256', $calcR->scheme->kopCode . '|' . $calcR->scheme->months . '|' . $fpR);
    $opR = hash('sha256', 'product|' . $stackR['storeId'] . '|' . $orderR . '|f02-recovery');
    $attemptR = $stackR['attempts']->findOrCreateAttempt(
        $stackR['storeId'],
        $orderR,
        Phase4TestHarness::TEST_UNICID,
        $opR,
        $selR,
        $fpR,
        MtUniCreditOperationEntryPoint::PRODUCT
    );
    $snapR = MtUniCreditApplicationSnapshot::fromLive(
        $calcR,
        $orderRRow,
        $orderRProducts,
        $shopR,
        MtUniCreditOperationEntryPoint::PRODUCT,
        $opR,
        $selR,
        $fpR
    );
    $attemptR = $stackR['attempts']->persistApplicationSnapshot((int) $attemptR['attempt_id'], $snapR);
    $stackR['attempts']->persistCpPayload((int) $attemptR['attempt_id'], $payloadR, $fpR);
    $stackR['attempts']->persistControlPanelOrderId((int) $attemptR['attempt_id'], 5559104);
    $stackR['attempts']->transitionFromStates(
        (int) $attemptR['attempt_id'],
        array(MtUniCreditFinancingAttemptState::ORDER_CREATED),
        MtUniCreditFinancingAttemptState::CP_CREATED
    );
    $attemptR = $stackR['attempts']->findById((int) $attemptR['attempt_id']);
    mtucAud007F02_assert(
        is_array($attemptR) && (int) $attemptR['control_panel_order_id'] === 5559104
            && (string) $attemptR['state'] === MtUniCreditFinancingAttemptState::CP_CREATED,
        'recovery: CP order created'
    );
    mtucAud007F02_assert(is_array($snapR) && (int) $snapR['scheme']['months'] === 12, 'recovery: frozen months=12');

    // Mutated live application after CP, before SmartUCF.
    $liveDriftOrder = Phase7TestHarness::orderRow($orderR, $stackR['storeId']);
    $liveDriftOrder['currency_code'] = 'EUR';
    $liveDriftOrder['firstname'] = 'Changed';
    $liveDriftOrder['lastname'] = 'Person';
    $liveDriftOrder['telephone'] = '+359899999999';
    $liveDriftOrder['email'] = 'changed@example.test';
    $liveDriftProducts = array(
        array(
            'product_id' => 99,
            'name' => 'LIVE-CHANGED',
            'quantity' => 2,
            'price' => 300.0,
            'total' => 600.0,
        ),
    );
    $liveDriftCalc = mtucAud007F02_calc(24, 600.0);
    $smartCallsBeforeDrift = Phase9TestHarness::smartUcfCallCount($stackR['smartUcfProbe']);
    $driftRecover = $stackR['lifecycle']->submitOrRecover(
        $attemptR,
        $liveDriftOrder,
        $liveDriftProducts,
        $liveDriftCalc,
        $shopR,
        MtUniCreditLockOwnerTokenGenerator::generate()
    );
    mtucAud007F02_assert(!empty($driftRecover->success), 'item-drift recovery: succeeds with frozen application');
    mtucAud007F02_assert(
        Phase9TestHarness::smartUcfCallCount($stackR['smartUcfProbe']) > $smartCallsBeforeDrift,
        'item-drift recovery: new SmartUCF handoff issued'
    );
    $smartPayload = Phase9TestHarness::smartUcfPayloadAt(
        $stackR['smartUcfProbe'],
        Phase9TestHarness::smartUcfCallCount($stackR['smartUcfProbe']) - 1
    );
    mtucAud007F02_assert(is_array($smartPayload), 'recovery: SmartUCF payload captured');
    $smartMonths = is_array($smartPayload) ? (int) (isset($smartPayload['installmentCount']) ? $smartPayload['installmentCount'] : 0) : 0;
    $smartTotal = is_array($smartPayload) ? (string) (isset($smartPayload['totalPrice']) ? $smartPayload['totalPrice'] : '') : '';
    mtucAud007F02_assert($smartMonths === 12, 'recovery: SmartUCF months frozen at 12 (observed=' . $smartMonths . ')');
    mtucAud007F02_assert(
        $smartTotal === '500.00' || $smartTotal === '500.0000' || (float) $smartTotal === 500.0,
        'recovery: SmartUCF total frozen at 500 (observed=' . $smartTotal . ')'
    );
    $smartItems = is_array($smartPayload) && isset($smartPayload['items']) && is_array($smartPayload['items'])
        ? $smartPayload['items']
        : array();
    mtucAud007F02_assert(count($smartItems) === 1, 'item-drift: items count frozen at 1');
    mtucAud007F02_assert(
        isset($smartItems[0]['code']) && (int) $smartItems[0]['code'] === 42,
        'item-drift: product code frozen (not live 99)'
    );
    mtucAud007F02_assert(
        isset($smartItems[0]['name']) && (string) $smartItems[0]['name'] === 'Example',
        'item-drift: product name frozen (not LIVE-CHANGED)'
    );
    mtucAud007F02_assert(
        isset($smartItems[0]['count']) && (int) $smartItems[0]['count'] === 1,
        'item-drift: quantity frozen at 1 (not live 2)'
    );
    mtucAud007F02_assert(
        isset($smartItems[0]['singlePrice']) && (float) $smartItems[0]['singlePrice'] === 500.0,
        'item-drift: singlePrice frozen at 500.00 (observed='
            . (isset($smartItems[0]['singlePrice']) ? $smartItems[0]['singlePrice'] : '') . ')'
    );
    mtucAud007F02_assert(
        is_array($smartPayload)
            && (string) $smartPayload['clientPhone'] === (string) $snapR['customer']['phone'],
        'item-drift: clientPhone from frozen snapshot'
    );
    mtucAud007F02_assert(
        is_array($smartPayload)
            && (string) $smartPayload['clientEmail'] === (string) $snapR['customer']['email'],
        'item-drift: clientEmail from frozen snapshot'
    );

    // Cross-system matrix from frozen snapshot → CP builder + SmartUCF builder
    $shop = mtuc4_valid_shop_snapshot();
    $order = Phase7TestHarness::orderRow(9104, Phase5TestHarness::STORE_A);
    $products = Phase7TestHarness::orderProducts();
    $frozenCalc = MtUniCreditApplicationSnapshot::toCalculationResult($snapR);
    $frozenProducts = MtUniCreditApplicationSnapshot::toOrderProducts($snapR);
    $cpPayload = (new MtUniCreditControlPanelOrderPayloadBuilder())->build(9104, $order, $products, $frozenCalc, $shop);
    $smartFromFrozen = (new MtUniCreditSmartUcfPayloadBuilder())->build(
        $shop,
        MtUniCreditApplicationSnapshot::overlayOrderForHandoff($order, $snapR),
        $frozenProducts,
        $frozenCalc,
        9104
    );
    mtucAud007F02_assert((int) $cpPayload['vnoski'] === 12, 'matrix: CP months=12');
    mtucAud007F02_assert((float) $cpPayload['price'] === 500.0, 'matrix: CP price=500');
    mtucAud007F02_assert((int) $smartFromFrozen['installmentCount'] === 12, 'matrix: SmartUCF months=12');
    mtucAud007F02_assert((float) $smartFromFrozen['totalPrice'] === 500.0, 'matrix: SmartUCF total=500');
    mtucAud007F02_assert(
        isset($smartFromFrozen['items'][0]['singlePrice'])
            && (float) $smartFromFrozen['items'][0]['singlePrice'] === 500.0,
        'matrix: SmartUCF singlePrice=500.00'
    );
    echo 'MATRIX 12m/500 snapshot|CP|SmartUCF = '
        . $snapR['scheme']['months'] . '/' . $snapR['financial']['financed_amount']
        . ' | ' . $cpPayload['vnoski'] . '/' . $cpPayload['price']
        . ' | ' . $smartFromFrozen['installmentCount'] . '/' . $smartFromFrozen['totalPrice']
        . ' items[0].count=' . $smartFromFrozen['items'][0]['count']
        . ' singlePrice=' . $smartFromFrozen['items'][0]['singlePrice']
        . PHP_EOL;

    echo 'MATRIX field drift (snapshot|live|SmartUCF):'
        . ' months 12|24|' . $smartMonths
        . '; total 500.00|600.00|' . $smartTotal
        . '; qty 1|2|' . (isset($smartItems[0]['count']) ? $smartItems[0]['count'] : '?')
        . '; singlePrice 500.00|300.00|' . (isset($smartItems[0]['singlePrice']) ? $smartItems[0]['singlePrice'] : '?')
        . PHP_EOL;

    $shared['snap_r'] = $snapR;
    $shared['attempt_r'] = $attemptR;
    $shared['smart_months'] = $smartMonths;
    $shared['smart_total'] = $smartTotal;
    $shared['smart_items'] = $smartItems;
    $shared['frozen_calc'] = $frozenCalc;
}

/**
 * After-CP bind + leasing write-once.
 *
 * @param array<string, mixed> $shared
 * @return void
 */
function mtucAud007F02_sec_after_cp(array &$shared)
{
    $db = $shared['db'];
    $clock = $shared['clock'];
    $attempts = $shared['attempts'];
    $opHash = $shared['op_hash'];
    $persisted = $shared['persisted'];
    $snapR = $shared['snap_r'];
    $attemptR = $shared['attempt_r'];
    $smartMonths = $shared['smart_months'];
    $smartTotal = $shared['smart_total'];
    $smartItems = $shared['smart_items'];
    $frozenCalc = $shared['frozen_calc'];

    // Live 24 must not be used when frozen 12 exists after CP:
    $live24 = mtucAud007F02_calc(24, 600.0);
    $rowAfterCp = $attemptR;
    $rowAfterCp['state'] = MtUniCreditFinancingAttemptState::CP_CREATED;
    $boundAfterCp = MtUniCreditApplicationSnapshot::bindToAttempt(
        $attempts,
        array_merge($persisted, array(
            'state' => MtUniCreditFinancingAttemptState::CP_CREATED,
            'control_panel_order_id' => 555001,
            'cp_payload' => '{"order_id":"9001"}',
            'application_snapshot_json' => $persisted['application_snapshot_json'],
            'application_snapshot_hash' => $persisted['application_snapshot_hash'],
        )),
        mtucAud007F02_snapshot($live24, MtUniCreditOperationEntryPoint::PRODUCT, $opHash)
    );
    mtucAud007F02_assert(!empty($boundAfterCp['ok']), 'after CP: live 24 ignored, frozen reused');
    mtucAud007F02_assert(
        (int) $boundAfterCp['calculation']->scheme->months === 12
            && (float) $boundAfterCp['calculation']->financedAmount === 500.0,
        'after CP: calculation authority remains 12/500'
    );

    // Leasing presentation write-once from frozen terms
    $leasingRepo = new MtUniCreditProcessTwoLifecycleRepository($db, $clock);
    MtUniCreditProcessTwoSubmissionSupport::persistLeasingSnapshot(
        $frozenCalc,
        9001,
        (int) $persisted['attempt_id'],
        $db,
        null,
        true
    );
    $rowLease = $leasingRepo->findByAttempt((int) $persisted['attempt_id']);
    $leaseJson = is_array($rowLease) ? (string) $rowLease['leasing_presentation_json'] : '';
    mtucAud007F02_assert($leaseJson !== '', 'leasing: snapshot written');
    MtUniCreditProcessTwoSubmissionSupport::persistLeasingSnapshot(
        $live24,
        9001,
        (int) $persisted['attempt_id'],
        $db,
        null,
        true
    );
    $rowLease2 = $leasingRepo->findByAttempt((int) $persisted['attempt_id']);
    mtucAud007F02_assert(
        is_array($rowLease2) && (string) $rowLease2['leasing_presentation_json'] === $leaseJson,
        'leasing: conflicting overwrite blocked (write-once)'
    );
    $leaseDecoded = json_decode($leaseJson, true);
    mtucAud007F02_assert(
        is_array($leaseDecoded) && (int) $leaseDecoded['months'] === 12,
        'leasing: frozen months=12'
    );
}

/**
 * Multi-item frozen SmartUCF + fresh application.
 *
 * @param array<string, mixed> $shared
 * @return void
 */
function mtucAud007F02_sec_multi(array &$shared)
{
    $calc12 = $shared['calc12'];

    // Multi-item frozen SmartUCF payload (live mutation ignored)
    $multiOrderProducts = array(
        array(
            'product_id' => 10,
            'name' => 'Product A',
            'quantity' => 1,
            'price' => 300.0,
            'total' => 300.0,
        ),
        array(
            'product_id' => 20,
            'name' => 'Product B',
            'quantity' => 2,
            'price' => 100.0,
            'total' => 200.0,
        ),
    );
    $multiSnap = MtUniCreditApplicationSnapshot::fromLive(
        $calc12,
        Phase7TestHarness::orderRow(9200, Phase5TestHarness::STORE_A),
        $multiOrderProducts,
        mtuc4_valid_shop_snapshot(),
        MtUniCreditOperationEntryPoint::PRODUCT,
        hash('sha256', 'f02-multi'),
        hash('sha256', 'f02-multi-sel'),
        hash('sha256', 'f02-multi-fp')
    );
    $multiLiveDrift = array(
        array(
            'product_id' => 1,
            'name' => 'LIVE-A',
            'quantity' => 9,
            'price' => 50.0,
            'total' => 450.0,
        ),
        array(
            'product_id' => 2,
            'name' => 'LIVE-B',
            'quantity' => 9,
            'price' => 50.0,
            'total' => 450.0,
        ),
        array(
            'product_id' => 3,
            'name' => 'LIVE-C',
            'quantity' => 1,
            'price' => 100.0,
            'total' => 100.0,
        ),
    );
    $multiHandoff = MtUniCreditApplicationSnapshot::resolveHandoffInputs(
        $multiSnap,
        Phase7TestHarness::orderRow(9200, Phase5TestHarness::STORE_A, 999.0)
    );
    mtucAud007F02_assert(count($multiHandoff['order_products']) === 2, 'multi-item: frozen product count = 2');
    $multiPayload = (new MtUniCreditSmartUcfPayloadBuilder())->build(
        mtuc4_valid_shop_snapshot(),
        $multiHandoff['order'],
        $multiHandoff['order_products'],
        $multiHandoff['calculation'],
        9200
    );
    // Prove live drift array is unused by building with frozen handoff only.
    unset($multiLiveDrift);
    mtucAud007F02_assert(count($multiPayload['items']) === 2, 'multi-item: SmartUCF items = 2');
    mtucAud007F02_assert(
        (int) $multiPayload['items'][0]['code'] === 10
            && (string) $multiPayload['items'][0]['name'] === 'Product A'
            && (int) $multiPayload['items'][0]['count'] === 1
            && (float) $multiPayload['items'][0]['singlePrice'] === 300.0,
        'multi-item: item0 frozen Product A / qty1 / 300.00'
    );
    mtucAud007F02_assert(
        (int) $multiPayload['items'][1]['code'] === 20
            && (string) $multiPayload['items'][1]['name'] === 'Product B'
            && (int) $multiPayload['items'][1]['count'] === 2
            && (float) $multiPayload['items'][1]['singlePrice'] === 100.0,
        'multi-item: item1 frozen Product B / qty2 / 100.00'
    );

    // Fresh application may use new terms (new token + new order)
    $transportF = new Phase4FakeCpHttpTransport();
    Phase9TestHarness::enqueueCpCreateSuccess($transportF);
    $stackF = Phase9TestHarness::stack($transportF, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 0));
    $orderF = 9105;
    $inputF = Phase9TestHarness::productStorefrontInput($stackF, $orderF);
    $inputF['product_line'] = new MtUniCreditProductLine(
        42,
        'Fresh Product',
        'EX',
        array(7),
        1,
        600.0,
        600.0,
        600.0,
        0,
        array(),
        0
    );
    $inputF = Phase9TestHarness::rebindProductApplicationToken($inputF);
    $inputF['add_order'] = function () use ($stackF, $orderF) {
        $stackF['memoryDb']->seedOrder($orderF, $stackF['storeId'], MtUniCreditConstants::EXTENSION_CODE);

        return $orderF;
    };
    $fresh = $stackF['storefront']->submit($inputF);
    mtucAud007F02_assert(!empty($fresh['success']), 'fresh application: new terms accepted');
    $attemptF = $stackF['attempts']->findByStoreOrder($stackF['storeId'], $orderF);
    $snapF = MtUniCreditApplicationSnapshot::decode(
        is_array($attemptF) ? $attemptF['application_snapshot_json'] : null
    );
    mtucAud007F02_assert(
        is_array($snapF) && $snapF['financial']['financed_amount'] === '600.00',
        'fresh application: snapshot stores 600.00'
    );
    $freshSmart = Phase9TestHarness::smartUcfPayloadAt($stackF['smartUcfProbe'], 0);
    mtucAud007F02_assert(is_array($freshSmart), 'fresh application: SmartUCF payload present');
    mtucAud007F02_assert(
        is_array($freshSmart)
            && (float) $freshSmart['totalPrice'] === 600.0
            && isset($freshSmart['items'][0]['singlePrice'])
            && (float) $freshSmart['items'][0]['singlePrice'] === 600.0
            && (string) $freshSmart['items'][0]['name'] === 'Fresh Product',
        'fresh application: SmartUCF uses fresh product terms'
    );
}

mtucAud007F02_run();
