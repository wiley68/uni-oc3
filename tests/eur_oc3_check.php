<?php

/** EUR-OC3-002 offline numeric and durable-currency regression. */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/support/phase3_shop_fixture.php';

$root = MTUC_PHASE0_ROOT;
if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'eur-oc3-test-password');
}
require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';
require_once __DIR__ . '/support/phase9_harness.php';

$passes = 0;
$failures = array();
function eur_oc3_assert($condition, $message)
{
    global $passes, $failures;
    if ($condition) {
        $passes++;
        echo 'PASS  ' . $message . PHP_EOL;
    } else {
        $failures[] = $message;
        echo 'FAIL  ' . $message . PHP_EOL;
    }
}

/** Complete another worker's Process 2 claim immediately before this worker's claim SQL. */
final class EurOc3ProcessTwoClaimRaceDb
{
    private $inner;
    private $attemptId;
    public $claimInterceptions = 0;

    public function __construct(Phase2MemoryDb $inner, $attemptId)
    {
        $this->inner = $inner;
        $this->attemptId = (int) $attemptId;
    }

    public function query($sql)
    {
        if ($this->claimInterceptions === 0
            && strpos($sql, "SET `process2_state` = '" . MtUniCreditProcessTwoLifecycleStates::PREPARING . "'") !== false
            && strpos($sql, 'WHERE `attempt_id` = ' . $this->attemptId) !== false) {
            $this->claimInterceptions++;
            $this->inner->query(
                "UPDATE `oc_mt_uni_credit_financing_attempt` SET `process2_state` = '"
                . MtUniCreditProcessTwoLifecycleStates::PREPARED
                . "' WHERE `attempt_id` = " . $this->attemptId
            );
        }

        return $this->inner->query($sql);
    }

    public function escape($value)
    {
        return $this->inner->escape($value);
    }

    public function countAffected()
    {
        return $this->inner->countAffected();
    }

    public function getLastId()
    {
        return $this->inner->getLastId();
    }
}

function eur_oc3_cp_patch_count(Phase4FakeCpHttpTransport $transport)
{
    $count = 0;
    foreach ($transport->requests as $request) {
        if (strtoupper((string) $request['method']) === 'PATCH') {
            $count++;
        }
    }

    return $count;
}

$validator = new MtUniCreditShopConfigurationSnapshotValidator();
foreach (array(null, 3, 0, 1, 2, 99) as $mode) {
    $shop = mtuc4_valid_shop_snapshot();
    if ($mode === null) {
        unset($shop['uni_eur']);
    } else {
        $shop['uni_eur'] = $mode;
    }
    $valid = true;
    try {
        $validator->validate($shop);
    } catch (MtUniCreditShopSnapshotValidationException $exception) {
        $valid = false;
    }
    eur_oc3_assert($valid, 'snapshot ignores optional/historical uni_eur ' . (string) $mode);
}
$gate = new MtUniCreditCurrencyGate();
foreach (array('EUR' => true, 'BGN' => false, 'USD' => false, 'GBP' => false, '' => false) as $iso => $expected) {
    eur_oc3_assert($gate->supports(array('uni_eur' => 3), $iso) === $expected, 'EUR gate ' . $iso);
}
foreach (array(' eur ', 'EUR ', ' eur', 'eur') as $malformedCode) {
    eur_oc3_assert(!$gate->supports(array('uni_eur' => 3), $malformedCode),
        'EUR gate rejects non-canonical ' . json_encode($malformedCode));
}

// Independent expectations: base unit 200 + 20% tax = 240; quantity 2;
// OC3 converter factor 0.5 gives EUR unit 120 and EUR financing total 240.
$resolver = new MtUniCreditOc3ProductLineResolver(
    function ($base, $taxClassId) {
        return $base * 1.2;
    },
    function ($amount, $from, $to) {
        return $amount * 0.5;
    }
);
$line = $resolver->resolve(
    array('product_id' => 42, 'name' => 'Unit', 'model' => 'U', 'price' => 200.0, 'tax_class_id' => 1),
    2,
    array(),
    'BASE',
    'EUR',
    array(7)
);
eur_oc3_assert(abs($line->unitWithTaxBase - 240.0) < 0.001, 'Product native tax-inclusive unit = 240 base');
eur_oc3_assert(abs($line->financingPrice - 240.0) < 0.001, 'Product financing total = 240 EUR');
$draft = (new MtUniCreditStorefrontOrderDraftBuilder())->buildOrderData(array(
    'product_line' => $line,
    'currency_code' => 'EUR',
    'currency_id' => 2,
    'currency_value' => 0.5,
));
eur_oc3_assert(abs($draft['total'] - 480.0) < 0.001, 'Product native draft total = 480 base');
eur_oc3_assert(abs($draft['products'][0]['total'] - 400.0) < 0.001, 'Product native ex-tax line = 400 base');
eur_oc3_assert(abs($draft['products'][0]['tax'] - 40.0) < 0.001, 'Product native unit tax = 40 base');

// Cart values are native base values. Scheme bounds and calculator receive 200 EUR.
$baseCart = Phase7TestHarness::cartContext(400.0);
$eurCart = MtUniCreditEurAmount::cartContext($baseCart, 0.5);
eur_oc3_assert(abs($baseCart->total - 400.0) < 0.001 && abs($eurCart->total - 200.0) < 0.001,
    'Cart base 400 converts to EUR 200 before financing');
$preciseCart = Phase7TestHarness::cartContext(200.01);
$preciseEur = MtUniCreditEurAmount::cartContext($preciseCart, 0.5);
eur_oc3_assert(abs($preciseEur->total - 100.005) < 0.000001,
    'Cart conversion retains pre-calculator precision at EUR 100.005');
$calc = Phase9TestHarness::calculation();
$order = Phase7TestHarness::orderRow(99001, Phase5TestHarness::STORE_A, 500.0);
$order['currency_value'] = 0.5;
$eurCalc = (new MtUniCreditCalculator())->calculateScheme(
    mtuc4_valid_shop_snapshot(),
    250.0,
    $calc->scheme,
    0.0
);
eur_oc3_assert(MtUniCreditEurAmount::orderFactor($order, Phase5TestHarness::STORE_A, 99001) === 0.5,
    'Checkout uses saved order factor 0.5');
$invalidId = $order;
$invalidId['currency_id'] = '1invalid';
$invalidFactor = $order;
$invalidFactor['currency_value'] = 0.0;
eur_oc3_assert(MtUniCreditEurAmount::orderFactor($invalidId, Phase5TestHarness::STORE_A, 99001) === null
    && MtUniCreditEurAmount::orderFactor($invalidFactor, Phase5TestHarness::STORE_A, 99001) === null,
    'Malformed native currency ID or factor fails closed');
eur_oc3_assert(MtUniCreditEurAmount::matchesCalculation($order, $eurCalc), 'Checkout base 500 corresponds to EUR 250');
foreach (array(' eur ', 'EUR ', ' eur', 'eur', '', 'BGN', 'USD') as $invalidCode) {
    $invalidOrder = $order;
    $invalidOrder['currency_code'] = $invalidCode;
    $cpBoundaryRejected = false;
    $bankBoundaryRejected = false;
    try {
        (new MtUniCreditControlPanelOrderPayloadBuilder())->build(
            99001, $invalidOrder, Phase7TestHarness::orderProducts(), $eurCalc, mtuc4_valid_shop_snapshot()
        );
    } catch (InvalidArgumentException $exception) {
        $cpBoundaryRejected = true;
    }
    try {
        (new MtUniCreditSmartUcfPayloadBuilder())->build(
            mtuc4_valid_shop_snapshot(), $invalidOrder, Phase7TestHarness::orderProducts(), $eurCalc, 99001
        );
    } catch (InvalidArgumentException $exception) {
        $bankBoundaryRejected = true;
    }
    eur_oc3_assert(MtUniCreditEurAmount::orderFactor($invalidOrder, Phase5TestHarness::STORE_A, 99001) === null
        && $cpBoundaryRejected && $bankBoundaryRejected,
        'Native order and direct builders reject ' . json_encode($invalidCode));
}
$cp = (new MtUniCreditControlPanelOrderPayloadBuilder())->build(
    99001, $order, Phase7TestHarness::orderProducts(), $eurCalc, mtuc4_valid_shop_snapshot()
);
eur_oc3_assert($cp['currency'] === 'EUR' && abs($cp['price'] - 250.0) < 0.001,
    'CP currency and financed price are EUR');
eur_oc3_assert(abs($cp['parva'] - 0.0) < 0.001 && abs($cp['vnoska'] - 262.50) < 0.001,
    'CP initial EUR 0 and monthly EUR 262.50');
$smart = (new MtUniCreditSmartUcfPayloadBuilder())->build(
    mtuc4_valid_shop_snapshot(), $order, Phase7TestHarness::orderProducts(), $eurCalc, 99001
);
eur_oc3_assert($smart['totalPrice'] === '250.00' && $smart['items'][0]['singlePrice'] === '250.00',
    'SmartUCF aggregate and native ex-tax item convert with historical factor');
$quantityItems = (new MtUniCreditSmartUcfPayloadBuilder())->build(
    mtuc4_valid_shop_snapshot(), $order,
    array(array('product_id' => 42, 'name' => 'Two units', 'quantity' => 2, 'price' => 100.0, 'total' => 200.0)),
    $eurCalc, 99001
);
eur_oc3_assert($quantityItems['items'][0]['singlePrice'] === '50.00',
    'SmartUCF ex-tax base line 200 x 0.5 / 2 = EUR 50 per item');
// Existing OC3 item meaning: order_product.total is ex-tax even when order.total includes tax.
$taxOrder = $order;
$taxOrder['total'] = 480.0;
$taxCalc = (new MtUniCreditCalculator())->calculateScheme(
    mtuc4_valid_shop_snapshot(), 240.0, $calc->scheme, 0.0
);
$taxSmart = (new MtUniCreditSmartUcfPayloadBuilder())->build(
    mtuc4_valid_shop_snapshot(), $taxOrder,
    array(array('product_id' => 42, 'name' => 'Taxed units', 'quantity' => 2,
        'price' => 200.0, 'total' => 400.0, 'tax' => 40.0)),
    $taxCalc, 99001
);
eur_oc3_assert($taxSmart['totalPrice'] === '240.00' && $taxSmart['items'][0]['singlePrice'] === '100.00',
    'SmartUCF preserves OC3 ex-tax item: base 400 x 0.5 / 2 = EUR 100, gross EUR 240');
eur_oc3_assert($smart['initialPayment'] === '0.00' && $smart['monthlyPayment'] === '262.50',
    'SmartUCF initial EUR 0 and monthly EUR 262.50');

$bad = $order;
$bad['currency_code'] = 'BGN';
$cpRejected = false;
$smartRejected = false;
try {
    (new MtUniCreditControlPanelOrderPayloadBuilder())->build(99001, $bad, Phase7TestHarness::orderProducts(), $eurCalc, mtuc4_valid_shop_snapshot());
} catch (InvalidArgumentException $exception) {
    $cpRejected = true;
}
try {
    (new MtUniCreditSmartUcfPayloadBuilder())->build(mtuc4_valid_shop_snapshot(), $bad, Phase7TestHarness::orderProducts(), $eurCalc, 99001);
} catch (InvalidArgumentException $exception) {
    $smartRejected = true;
}
eur_oc3_assert($cpRejected && $smartRejected, 'direct CP and SmartUCF builders reject old BGN order');

$transport = new Phase4FakeCpHttpTransport();
$stack = Phase9TestHarness::stack($transport);
$input = Phase9TestHarness::submitInput(99002, $stack['storeId']);
$input['order']['currency_value'] = 0.5;
$input['currency_value'] = 0.4; // current rate differs from persisted historical order
Phase9TestHarness::seedBankOrder($stack['memoryDb'], 99002, $stack['storeId']);
Phase9TestHarness::enqueueCpCreateSuccess($transport);
$first = $stack['submission']->submit($input);
eur_oc3_assert(!empty($first['success']), 'Checkout submits using persisted 0.5 despite current 0.4');
eur_oc3_assert(Phase7TestHarness::countOrderPosts($transport) === 1, 'Checkout sends one CP order');
$attempt = $stack['attempts']->findByStoreOrder($stack['storeId'], 99002);
$payload = is_array($attempt) ? json_decode((string) $attempt['cp_payload'], true) : null;
eur_oc3_assert(is_array($payload) && $payload['currency'] === 'EUR' && abs($payload['price'] - 250.0) < 0.001,
    'Checkout CP payload uses saved factor, EUR 250');
$frozenSnapshot = MtUniCreditApplicationSnapshot::decode($attempt['application_snapshot_json']);
foreach (array(' eur ', 'EUR ', ' eur', 'eur', '', 'BGN', 'USD') as $invalidCode) {
    $invalidSnapshot = $frozenSnapshot;
    $invalidSnapshot['financial']['currency'] = $invalidCode;
    eur_oc3_assert(!MtUniCreditEurAmount::matchesSnapshot($invalidSnapshot, $input['order']),
        'Application snapshot rejects ' . json_encode($invalidCode));
}
$missingSnapshotCode = $frozenSnapshot;
unset($missingSnapshotCode['financial']['currency']);
eur_oc3_assert(!MtUniCreditEurAmount::matchesSnapshot($missingSnapshotCode, $input['order']),
    'Application snapshot rejects missing currency');
$again = $stack['submission']->submit($input);
eur_oc3_assert(!empty($again['success']) && Phase7TestHarness::countOrderPosts($transport) === 1,
    'Historical checkout replay does not repost CP');
eur_oc3_assert(Phase9TestHarness::smartUcfCallCount($stack['smartUcfProbe']) === 1,
    'Historical checkout replay does not resend SmartUCF');

$bgn = $input;
$bgn['order']['currency_code'] = 'BGN';
$bgn['currency_code'] = 'BGN';
$beforePosts = Phase7TestHarness::countOrderPosts($transport);
$denied = $stack['submission']->submit($bgn);
eur_oc3_assert(empty($denied['success']) && Phase7TestHarness::countOrderPosts($transport) === $beforePosts,
    'Direct checkout service rejects BGN before HTTP');
$bgn['currency_code'] = 'EUR';
$deniedHistorical = $stack['submission']->submit($bgn);
eur_oc3_assert(empty($deniedHistorical['success']) && Phase7TestHarness::countOrderPosts($transport) === $beforePosts,
    'Current EUR session cannot replay a native BGN checkout order');
foreach (array(' eur ', 'EUR ', ' eur', 'eur', '', 'BGN', 'USD') as $invalidCode) {
    $invalidSession = $input;
    $invalidSession['currency_code'] = $invalidCode;
    $deniedSession = $stack['submission']->submit($invalidSession);
    $invalidNative = $input;
    $invalidNative['order']['currency_code'] = $invalidCode;
    $deniedNative = $stack['submission']->submit($invalidNative);
    eur_oc3_assert(empty($deniedSession['success']) && empty($deniedNative['success'])
        && Phase7TestHarness::countOrderPosts($transport) === $beforePosts,
        'Direct checkout rejects session/native ' . json_encode($invalidCode) . ' before HTTP');
}

// Product/Cart materialization: the source cart and draft stay base 500; the
// historical order factor 0.5 makes the financing/CP amount EUR 250.
foreach (array('product', 'cart') as $entry) {
    $transportFlow = new Phase4FakeCpHttpTransport();
    $flow = Phase9TestHarness::stack($transportFlow);
    $flowId = $entry === 'product' ? 99003 : 99004;
    $flowInput = $entry === 'product'
        ? Phase9TestHarness::productStorefrontInput($flow, $flowId)
        : Phase9TestHarness::cartStorefrontInput($flow, $flowId);
    $flowInput['currency_value'] = 0.5;
    if ($entry === 'product') {
        $flowInput['product_line'] = new MtUniCreditProductLine(
            42, 'Example', 'EX', array(7), 1, 500.0, 250.0, 250.0, 0, array(), 0, 500.0
        );
    }
    $nativeDraft = null;
    $originalAdd = $flowInput['add_order'];
    $flowInput['add_order'] = function ($data) use (&$nativeDraft, $originalAdd) {
        $nativeDraft = $data;
        return call_user_func($originalAdd, $data);
    };
    $flowInput['load_order'] = function ($id) use ($flow) {
        $row = Phase7TestHarness::orderRow($id, $flow['storeId'], 500.0);
        $row['currency_value'] = 0.5;
        return $row;
    };
    Phase9TestHarness::enqueueCpCreateSuccess($transportFlow);
    $flowResult = $flow['storefront']->submit($flowInput);
    eur_oc3_assert(!empty($flowResult['success']), $entry . ' submits with base 500 / EUR 250');
    eur_oc3_assert(is_array($nativeDraft) && abs($nativeDraft['total'] - 500.0) < 0.001
        && abs($nativeDraft['products'][0]['total'] - 500.0) < 0.001,
        $entry . ' native draft remains base 500');
    $flowAttempt = $flow['attempts']->findByStoreOrder($flow['storeId'], $flowId);
    $flowPayload = is_array($flowAttempt) ? json_decode((string) $flowAttempt['cp_payload'], true) : null;
    eur_oc3_assert(is_array($flowPayload) && $flowPayload['currency'] === 'EUR'
        && abs($flowPayload['price'] - 250.0) < 0.001, $entry . ' CP receives EUR 250');
    $flowInput['session'] = isset($flowResult['session']) ? $flowResult['session'] : $flowInput['session'];
    $flowInput['currency_value'] = 0.4;
    $flowReplay = $flow['storefront']->submit($flowInput);
    eur_oc3_assert(!empty($flowReplay['success']) && Phase7TestHarness::countOrderPosts($transportFlow) === 1,
        $entry . ' replay uses saved 0.5 and does not repost CP');
    $flowInput['load_order'] = function ($id) use ($flow) {
        $row = Phase7TestHarness::orderRow($id, $flow['storeId'], 500.0);
        $row['currency_code'] = 'BGN';
        $row['currency_value'] = 0.5;
        return $row;
    };
    $staleCurrencyReplay = $flow['storefront']->submit($flowInput);
    eur_oc3_assert(empty($staleCurrencyReplay['success']) && Phase7TestHarness::countOrderPosts($transportFlow) === 1,
        $entry . ' current EUR session cannot replay native BGN order');
}

// A successful CP attempt with corrupted saved payload must not be repaired by reposting.
$table = $stack['db']->getPrefix() . MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT;
foreach (array(' eur ', 'EUR ', ' eur', 'eur', '', 'BGN', 'USD') as $invalidCode) {
    $invalidPayload = $payload;
    $invalidPayload['currency'] = $invalidCode;
    $invalidJson = json_encode($invalidPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $invalidFingerprint = MtUniCreditControlPanelOrderPayloadBuilder::fingerprint($invalidPayload);
    $stack['db']->query(
        "UPDATE `{$table}` SET `cp_payload` = '" . $stack['db']->escape($invalidJson)
        . "', `request_fingerprint` = '" . $stack['db']->escape($invalidFingerprint)
        . "' WHERE `attempt_id` = " . (int) $attempt['attempt_id']
    );
    $invalidReplay = $stack['submission']->submit($input);
    $afterInvalid = $stack['attempts']->findByStoreOrder($stack['storeId'], 99002);
    eur_oc3_assert(empty($invalidReplay['success'])
        && Phase7TestHarness::countOrderPosts($transport) === $beforePosts
        && (string) $afterInvalid['cp_payload'] === $invalidJson
        && $afterInvalid['state'] === MtUniCreditFinancingAttemptState::CP_CREATED,
        'Saved CP payload rejects ' . json_encode($invalidCode) . ' without repair or HTTP');
}
$missingCurrencyPayload = $payload;
unset($missingCurrencyPayload['currency']);
$missingCurrencyJson = json_encode($missingCurrencyPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$missingCurrencyFingerprint = MtUniCreditControlPanelOrderPayloadBuilder::fingerprint($missingCurrencyPayload);
$stack['db']->query(
    "UPDATE `{$table}` SET `cp_payload` = '" . $stack['db']->escape($missingCurrencyJson)
    . "', `request_fingerprint` = '" . $stack['db']->escape($missingCurrencyFingerprint)
    . "' WHERE `attempt_id` = " . (int) $attempt['attempt_id']
);
$missingCurrencyReplay = $stack['submission']->submit($input);
$afterMissingCurrency = $stack['attempts']->findByStoreOrder($stack['storeId'], 99002);
eur_oc3_assert(empty($missingCurrencyReplay['success'])
    && Phase7TestHarness::countOrderPosts($transport) === $beforePosts
    && (string) $afterMissingCurrency['cp_payload'] === $missingCurrencyJson,
    'Saved CP payload rejects missing currency without repair or HTTP');
$originalJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$stack['db']->query(
    "UPDATE `{$table}` SET `cp_payload` = '" . $stack['db']->escape($originalJson)
    . "', `request_fingerprint` = '" . $stack['db']->escape((string) $attempt['request_fingerprint'])
    . "' WHERE `attempt_id` = " . (int) $attempt['attempt_id']
);
$stack['db']->query(
    "UPDATE `{$table}` SET `cp_payload` = '{\"currency\":\"BGN\"}' WHERE `attempt_id` = " . (int) $attempt['attempt_id']
);
$corruptReplay = $stack['submission']->submit($input);
eur_oc3_assert(empty($corruptReplay['success']) && Phase7TestHarness::countOrderPosts($transport) === 1,
    'Saved BGN CP payload fails closed without repost');
$stack['db']->query(
    "UPDATE `{$table}` SET `cp_payload` = NULL WHERE `attempt_id` = " . (int) $attempt['attempt_id']
);
$missingReplay = $stack['submission']->submit($input);
eur_oc3_assert(empty($missingReplay['success']) && Phase7TestHarness::countOrderPosts($transport) === 1,
    'Missing CP-created payload fails closed without repost');
$badOrder = $input['order'];
$badOrder['currency_code'] = 'BGN';
$smartCalls = Phase9TestHarness::smartUcfCallCount($stack['smartUcfProbe']);
$smartReplay = $stack['process1']->run(
    (int) $attempt['attempt_id'], mtuc4_valid_shop_snapshot(), $badOrder,
    Phase7TestHarness::orderProducts(), $eurCalc, 99002,
    (int) $attempt['control_panel_order_id'], $stack['bankStatuses'], Phase4TestHarness::TEST_UNICID
);
eur_oc3_assert(!$smartReplay->isCreated() && Phase9TestHarness::smartUcfCallCount($stack['smartUcfProbe']) === $smartCalls,
    'Existing SmartUCF redirect refuses old BGN order without HTTP');

$newTransport = new Phase4FakeCpHttpTransport();
$newStack = Phase9TestHarness::stack($newTransport);
$newInput = Phase9TestHarness::submitInput(99006, $newStack['storeId']);
$newInput['order']['currency_value'] = 0.4;
$newInput['currency_value'] = 0.4;
Phase9TestHarness::seedBankOrder($newStack['memoryDb'], 99006, $newStack['storeId']);
Phase9TestHarness::enqueueCpCreateSuccess($newTransport);
$newResult = $newStack['submission']->submit($newInput);
$newAttempt = $newStack['attempts']->findByStoreOrder($newStack['storeId'], 99006);
$newPayload = is_array($newAttempt) ? json_decode((string) $newAttempt['cp_payload'], true) : null;
eur_oc3_assert(!empty($newResult['success']) && is_array($newPayload) && abs($newPayload['price'] - 200.0) < 0.001,
    'Genuinely new checkout order uses current 0.4, EUR 200');

$p2Transport = new Phase4FakeCpHttpTransport();
$p2 = Phase9TestHarness::stack($p2Transport, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$p2Input = Phase9TestHarness::submitInputProcess2(99007, $p2['storeId']);
Phase9TestHarness::seedBankOrder($p2['memoryDb'], 99007, $p2['storeId']);
Phase9TestHarness::enqueueCpCreateSuccess($p2Transport);
$p2Result = $p2['submission']->submit($p2Input);
$p2Attempt = $p2['attempts']->findByStoreOrder($p2['storeId'], 99007);
eur_oc3_assert(!empty($p2Result['success']) && Phase9TestHarness::smartUcfCallCount($p2['smartUcfProbe']) === 0,
    'Process 2 EUR success sends no SmartUCF request');
$p2Table = $p2['db']->getPrefix() . MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT;
$p2['db']->query(
    "UPDATE `{$p2Table}` SET `process2_state` = '" . MtUniCreditProcessTwoLifecycleStates::NOT_STARTED
    . "' WHERE `attempt_id` = " . (int) $p2Attempt['attempt_id']
);
$raceDb = new EurOc3ProcessTwoClaimRaceDb($p2['memoryDb'], $p2Attempt['attempt_id']);
$raceAdapter = new MtUniCreditDbAdapter($raceDb, $p2['db']->getPrefix());
$raceCoordinator = MtUniCreditProcessTwoServiceFactory::coordinator(
    $raceAdapter, $p2['client'], $p2['process2Mailer'], $p2['clock'], Phase4TestHarness::testSecretInput()
);
$raceContext = array('order_id' => 99007, 'native_order' => $p2Input['order']);
$raceShop = mtuc4_valid_shop_snapshot(array('uni_proces' => 1));
$patchesBeforeRace = eur_oc3_cp_patch_count($p2Transport);
$postsBeforeRace = Phase7TestHarness::countOrderPosts($p2Transport);
$mailsBeforeRace = count($p2['process2Mailer']->sent);
$raceReplay = $raceCoordinator->run(
    (int) $p2Attempt['attempt_id'], $p2['storeId'], 99007, $raceShop, $raceContext
);
eur_oc3_assert($raceDb->claimInterceptions === 1 && !empty($raceReplay['success'])
    && !empty($raceReplay['replay'])
    && $raceReplay['process2_state'] === MtUniCreditProcessTwoLifecycleStates::PREPARED,
    'Process 2 lost claim actually enters PREPARED replay with native EUR proof');
eur_oc3_assert(Phase7TestHarness::countOrderPosts($p2Transport) === $postsBeforeRace
    && eur_oc3_cp_patch_count($p2Transport) === $patchesBeforeRace
    && Phase9TestHarness::smartUcfCallCount($p2['smartUcfProbe']) === 0
    && count($p2['process2Mailer']->sent) === $mailsBeforeRace,
    'Process 2 lost-claim replay adds no CP create/PATCH, SmartUCF or mail');
$oldP2Order = $p2Input['order'];
$oldP2Order['currency_code'] = 'BGN';
$mailBefore = count($p2['process2Mailer']->sent);
$p2Denied = $p2['process2']->run(
    (int) $p2Attempt['attempt_id'], $p2['storeId'], 99007, mtuc4_valid_shop_snapshot(),
    array('order_id' => 99007, 'native_order' => $oldP2Order)
);
eur_oc3_assert(empty($p2Denied['success']) && Phase9TestHarness::smartUcfCallCount($p2['smartUcfProbe']) === 0
    && count($p2['process2Mailer']->sent) === $mailBefore,
    'Process 2 direct replay rejects BGN before status/mail and never calls SmartUCF');
$p2Missing = $p2['process2']->run(
    (int) $p2Attempt['attempt_id'], $p2['storeId'], 99007, $raceShop,
    array('order_id' => 99007)
);
eur_oc3_assert(empty($p2Missing['success']) && $p2Missing['error'] === 'process2_failed'
    && eur_oc3_cp_patch_count($p2Transport) === $patchesBeforeRace
    && count($p2['process2Mailer']->sent) === $mailBefore,
    'Process 2 rejects missing native order without CP/mail side effects');
$p2CurrentEurOldBgn = $p2['process2']->run(
    (int) $p2Attempt['attempt_id'], $p2['storeId'], 99007, $raceShop,
    array('order_id' => 99007, 'currency_code' => 'EUR', 'native_order' => $oldP2Order)
);
eur_oc3_assert(empty($p2CurrentEurOldBgn['success'])
    && eur_oc3_cp_patch_count($p2Transport) === $patchesBeforeRace,
    'Process 2 ignores current EUR context when durable native order is BGN');

$transportBypass = new Phase4FakeCpHttpTransport();
$bypass = Phase9TestHarness::stack($transportBypass);
$bypassInput = Phase9TestHarness::productStorefrontInput($bypass, 99005);
$bypassInput['currency_code'] = 'BGN';
$bypassInput = Phase9TestHarness::rebindProductApplicationToken($bypassInput);
$bypassResult = $bypass['storefront']->submit($bypassInput);
eur_oc3_assert(empty($bypassResult['success']) && Phase7TestHarness::countOrderPosts($transportBypass) === 0,
    'Direct storefront service rejects BGN before order or HTTP');
foreach (array(' eur ', 'EUR ', ' eur', 'eur') as $invalidCode) {
    $invalidBypass = Phase9TestHarness::productStorefrontInput($bypass, 99005);
    $invalidBypass['currency_code'] = $invalidCode;
    $invalidBypassResult = $bypass['storefront']->submit($invalidBypass);
    eur_oc3_assert(empty($invalidBypassResult['success'])
        && Phase7TestHarness::countOrderPosts($transportBypass) === 0,
        'Direct storefront service rejects ' . json_encode($invalidCode) . ' before HTTP');
}

echo PHP_EOL . 'EUR OC3: ' . $passes . ' passed, ' . count($failures) . ' failed' . PHP_EOL;
exit($failures === array() ? 0 : 1);
