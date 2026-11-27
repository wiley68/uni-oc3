<?php

/** Offline PRICE-001 proof, retry and submission checks. */
require_once __DIR__ . '/bootstrap.php';
define('DIR_SYSTEM', MTUC_PHASE0_ROOT . '/upload/system/');
mtuc_test_define_dir_storage('mtuc-cp-gross-price');
define('DB_PASSWORD', 'offline-price-fixture');
require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';
require_once __DIR__ . '/support/phase9_harness.php';

function priceAssert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$GLOBALS['priceAssertions'];
}

function priceStack($process = 1, $transport = null)
{
    return Phase9TestHarness::stack($transport ?? new Phase4FakeCpHttpTransport(), null, null,
        Phase5TestHarness::STORE_A, array('uni_first_vnoska' => 1, 'uni_proces' => $process - 1));
}

function priceInput(array $stack, array $fixture, $bound = true)
{
    $id = (int) $fixture['order_id'];
    if ($fixture['entry_point'] === 'checkout') {
        $input = Phase9TestHarness::submitInput($id, $stack['storeId']);
    } else {
        $method = $fixture['entry_point'] . 'StorefrontInput';
        if ($fixture['entry_point'] === 'cart') {
            $cart = new MtUniCreditCartContext(array(
                new MtUniCreditCartLine(new MtUniCreditProductContext(42, array(7), 300.0), 0, 1, 300.0),
                new MtUniCreditCartLine(new MtUniCreditProductContext(99, array(7), 200.0), 0, 1, 200.0),
            ), 500.0);
            $input = Phase9TestHarness::cartStorefrontInput($stack, $id, $cart);
            $input['products'][0] = array_replace($input['products'][0],
                array('name' => 'First_Product_name', 'price' => 300.0, 'total' => 300.0));
            $input['products'][] = array_replace($input['products'][0],
                array('product_id' => 99, 'name' => 'Втори_продукт', 'price' => 200.0, 'total' => 200.0));
        } else {
            $input = Phase9TestHarness::$method($stack, $id);
        }
        $key = MtUniCreditStorefrontApplicationToken::SESSION_ISSUED_KEY;
        $binding = $input['session'][$key][$input['application_token']];
        $input['application_token'] = str_repeat($fixture['entry_point'] === 'product' ? '1' : '2', 32);
        $input['session'][$key] = array($input['application_token'] => $binding);
        if ($bound) {
            $input['session'][MtUniCreditStorefrontFinancingSubmissionService::SESSION_ORDER_BIND_KEY] =
                array($fixture['operation_key_hash'] => $id);
            $input['add_order'] = function () {
                throw new RuntimeException('Bound order was recreated');
            };
        }
    }
    $input['first_installment'] = 100.0;
    if ($bound || $fixture['entry_point'] === 'checkout') {
        Phase9TestHarness::seedBankOrder($stack['memoryDb'], $id, $stack['storeId']);
    }
    return $input;
}

function priceSubmit(array $stack, array $fixture, array $input)
{
    return $stack[$fixture['entry_point'] === 'checkout' ? 'submission' : 'storefront']->submit($input);
}

function priceRow(array $stack, array $fixture)
{
    return $stack['attempts']->findByStoreOrder($stack['storeId'], $fixture['order_id']);
}

function priceSeed(array $stack, array $fixture, $state, $saved = true)
{
    $row = $fixture;
    $row['state'] = $state;
    $row['cp_payload'] = $saved ? $fixture['cp_payload'] : null;
    $row['control_panel_order_id'] = $state === MtUniCreditFinancingAttemptState::CP_CREATED ? 555001 : null;
    $stack['memoryDb']->seedFinancingAttempt($row);
}

function pricePosts(array $stack)
{
    return array_values(array_filter($stack['transport']->requests, function ($request) {
        return $request['method'] === 'POST' && substr($request['url'], -7) === '/orders';
    }));
}

function priceOriginal(array $stack, array $fixture)
{
    $row = priceRow($stack, $fixture);
    foreach (array('attempt_id', 'store_id', 'order_id', 'operation_key_hash', 'selection_hash',
        'request_fingerprint', 'application_snapshot_json', 'application_snapshot_hash', 'cp_payload') as $key) {
        priceAssert($row[$key] === $fixture[$key], 'Original ' . $key . ' changed');
    }
}

$priceAssertions = 0;
$fixtures = json_decode(file_get_contents(__DIR__ . '/fixtures/oc3_cp_principal_price.json'), true, 512, JSON_THROW_ON_ERROR);
$created = MtUniCreditFinancingAttemptState::CP_CREATED;
$preSend = MtUniCreditFinancingAttemptState::ORDER_CREATED;
$unknown = MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN;

foreach ($fixtures as $name => $fixture) {
    foreach (array(1, 2) as $process) {
        foreach (array(false, true) as $saved) {
            $stack = priceStack($process);
            $input = priceInput($stack, $fixture);
            priceSeed($stack, $fixture, $preSend, $saved);
            Phase9TestHarness::enqueueCpCreateSuccess($stack['transport']);
            $result = priceSubmit($stack, $fixture, $input);
            $label = "$name P$process " . ($saved ? 'saved' : 'reconstructed');
            priceAssert(!empty($result['success']), "$label " . ($result['error'] ?? 'failed'));
            $posts = pricePosts($stack);
            priceAssert(count($posts) === 1, "$label one POST");
            priceAssert(json_encode($posts[0]['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) === $fixture['cp_payload'], "$label exact historical payload");
            priceOriginal($stack, $fixture);
            $input['first_installment'] = 300.0;
            $replay = priceSubmit($stack, $fixture, $input);
            priceAssert(!empty($replay['success']) && count(pricePosts($stack)) === 1, "$label local replay");
            priceAssert(Phase9TestHarness::smartUcfCallCount($stack['smartUcfProbe']) === ($process === 1 ? 1 : 0), "$label session count");
            priceOriginal($stack, $fixture);
        }
    }
}
echo "PASS historical principal v1/v2, missing JSON, saved JSON, P1/P2 and changed-browser replay\n";

foreach (array('product', 'cart', 'checkout') as $entry) {
    $fixture = $fixtures[$entry];
    foreach (array(1, 2) as $process) {
        foreach (array($created, $unknown, MtUniCreditFinancingAttemptState::CP_SUBMITTING) as $state) {
            foreach (array(false, true) as $saved) {
                $stack = priceStack($process);
                $input = priceInput($stack, $fixture);
                priceSeed($stack, $fixture, $state, $saved);
                $result = priceSubmit($stack, $fixture, $input);
                $label = "$entry P$process $state saved=" . (int) $saved;
                priceAssert(!empty($result['success']) === ($state === $created && $saved), "$label lifecycle");
                priceAssert(count(pricePosts($stack)) === 0, "$label no create");
                priceAssert(Phase9TestHarness::smartUcfCallCount($stack['smartUcfProbe']) ===
                    ($state === $created && $saved && $process === 1 ? 1 : 0), "$label session count");
                if ($saved) {
                    priceOriginal($stack, $fixture);
                }
            }
        }
    }
}
echo "PASS confirmed handoff/completion and blocked unknown/submitting states\n";

$fixture = $fixtures['checkout'];
foreach (array('price', 'parva', 'vnoska', 'gpr', 'vnoski', 'currency', 'version', 'order_id', 'name',
    'products_name', 'request_fingerprint', 'selection_hash', 'application_snapshot_hash') as $field) {
    $stack = priceStack();
    $input = priceInput($stack, $fixture);
    $tampered = $fixture;
    if (isset($tampered[$field]) && in_array($field, array('request_fingerprint', 'selection_hash', 'application_snapshot_hash'), true)) {
        $tampered[$field] = str_repeat('a', 64);
    } else {
        $body = json_decode($fixture['cp_payload'], true);
        $body[$field] = $field === 'price' ? 500 : 'invalid';
        $tampered['cp_payload'] = json_encode($body);
    }
    priceSeed($stack, $tampered, $preSend);
    priceAssert(empty(priceSubmit($stack, $fixture, $input)['success']), "$field tampering rejected");
    priceAssert(pricePosts($stack) === array(), "$field tampering sent no POST");
}
foreach (array('product', 'cart', 'checkout') as $entry) {
    $fixture = $fixtures[$entry];
    $stack = priceStack();
    $input = priceInput($stack, $fixture);
    if ($entry === 'checkout') {
        $input['order']['firstname'] = 'Changed';
    } else {
        $load = $input['load_order'];
        $input['load_order'] = function ($id) use ($load) {
            return array_replace($load($id), array('firstname' => 'Changed'));
        };
    }
    priceSeed($stack, $fixture, $preSend, false);
    priceAssert(empty(priceSubmit($stack, $fixture, $input)['success']), "$entry live drift");
    priceAssert(pricePosts($stack) === array(), "$entry drift sent no POST");
}
$fixture = $fixtures['cart'];
$stack = priceStack();
$input = priceInput($stack, $fixture);
$input['products'] = array_reverse($input['products']);
priceSeed($stack, $fixture, $preSend, false);
priceAssert(empty(priceSubmit($stack, $fixture, $input)['success']), 'Live product ordering drift rejected');
priceAssert(pricePosts($stack) === array(), 'Product ordering drift sent no POST');
$stack = priceStack();
$input = priceInput($stack, $fixture);
$body = json_decode($fixture['cp_payload'], true);
$body['products_id'] = '99_42';
$body['products_name'] = 'Втори_продукт_First_Product_name';
$tampered = array_replace($fixture, array('cp_payload' => json_encode($body)));
priceSeed($stack, $tampered, $preSend);
priceAssert(empty(priceSubmit($stack, $fixture, $input)['success']), 'Saved product ordering tamper rejected');
priceAssert(pricePosts($stack) === array(), 'Saved product ordering tamper sent no POST');
echo "PASS historical tamper and live drift checks\n";
foreach (array('product', 'cart', 'checkout') as $entry) {
    $fixture = $fixtures[$entry];
    $stack = priceStack();
    $input = priceInput($stack, $fixture, false);
    $inserted = false;
    if ($entry === 'checkout') {
        $input['order_products'][0]['order_product_id'] = 1;
        $input['get_order_options'] = function () use ($stack, $fixture, $preSend, &$inserted) {
            if (!$inserted) {
                priceSeed($stack, $fixture, $preSend, false);
                $inserted = true;
            }
            return array();
        };
    } else {
        $addOrder = $input['add_order'];
        $input['add_order'] = function ($draft) use ($stack, $fixture, $preSend, $addOrder, &$inserted) {
            $id = $addOrder($draft);
            priceSeed($stack, $fixture, $preSend, false);
            $inserted = true;
            return $id;
        };
    }
    Phase9TestHarness::enqueueCpCreateSuccess($stack['transport']);
    $recovered = priceSubmit($stack, $fixture, $input);
    priceAssert($inserted && !empty($recovered['success']), "$entry concurrent return");
    priceAssert(count(pricePosts($stack)) === 1, "$entry one historical create after race");
    priceOriginal($stack, $fixture);
}
echo "PASS concurrent repository returns in Product, Cart and Checkout\n";

$fixture = $fixtures['checkout'];
$unknownVersion = $fixture;
$body = json_decode($fixture['cp_payload'], true);
$body['version'] = '9.9.9';
$unknownVersion['cp_payload'] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
// Deliberately create coherent but unsupported v1 evidence to test reconstruction.
$unknownVersion['request_fingerprint'] = MtUniCreditControlPanelOrderPayloadBuilder::fingerprint($body);
$snapshot = json_decode($fixtures['checkout_v1']['application_snapshot_json'], true);
$snapshot['request_fingerprint'] = $unknownVersion['request_fingerprint'];
$snapshot['selection_hash'] = hash('sha256', 'KOPSTD|12|' . $snapshot['request_fingerprint']);
$unknownVersion['selection_hash'] = $snapshot['selection_hash'];
$unknownVersion['application_snapshot_json'] = MtUniCreditApplicationSnapshot::encode($snapshot);
$unknownVersion['application_snapshot_hash'] = MtUniCreditApplicationSnapshot::hash($snapshot);
$stack = priceStack();
$input = priceInput($stack, $fixture);
priceSeed($stack, $unknownVersion, $preSend, false);
priceAssert(empty(priceSubmit($stack, $fixture, $input)['success']), 'Unproven version rejected');
priceAssert(pricePosts($stack) === array(), 'Unproven version sent no POST');

$stack = priceStack();
$input = priceInput($stack, $fixture);
$inconsistent = $fixtures['checkout_v1'];
$snapshot = json_decode($inconsistent['application_snapshot_json'], true);
$snapshot['customer']['address2'] = 'Different frozen address';
$inconsistent['application_snapshot_json'] = MtUniCreditApplicationSnapshot::encode($snapshot);
$inconsistent['application_snapshot_hash'] = MtUniCreditApplicationSnapshot::hash($snapshot);
priceSeed($stack, $inconsistent, $preSend, false);
$attempt = priceRow($stack, $fixture);
$shop = mtuc4_valid_shop_snapshot(array('uni_first_vnoska' => 1));
MtUniCreditConsentGate::authorizeLegacy($attempt, $shop, $input);
$unrecoverable = $stack['lifecycle']->submitOrRecover(
    $attempt, $input['order'], $input['order_products'], MtUniCreditApplicationSnapshot::toCalculationResult($snapshot),
    $shop, MtUniCreditLockOwnerTokenGenerator::generate()
);
priceAssert(!$unrecoverable->success, 'Hash-valid frozen snapshot must still reproduce the complete original request');
priceAssert(pricePosts($stack) === array() && priceRow($stack, $fixture)['cp_payload'] === null,
    'Nonrecoverable frozen payload was neither persisted nor posted');

foreach (array(1, 2) as $process) {
    foreach (array('product', 'checkout') as $entry) {
        $fixture = $fixtures[$entry];
        $stack = priceStack($process);
        $input = priceInput($stack, $fixture);
        priceSeed($stack, $fixture, $preSend);
        $stack['transport']->enqueueJson(200, Phase7TestHarness::loginAndOrderSuccessPayloads()['login']);
        $stack['transport']->enqueueTimeout();
        $result = priceSubmit($stack, $fixture, $input);
        priceAssert(empty($result['success']) && priceRow($stack, $fixture)['state'] === $unknown, "$entry P$process timeout");
        priceAssert(empty(priceSubmit($stack, $fixture, $input)['success']), "$entry P$process ambiguous replay");
        priceAssert(count(pricePosts($stack)) === 1, "$entry P$process no alternate POST");
        priceOriginal($stack, $fixture);

        $stack = priceStack($process);
        $input = priceInput($stack, $fixture);
        priceSeed($stack, $fixture, $created);
        $stack['transport']->enqueueJson(200, Phase7TestHarness::loginAndOrderSuccessPayloads()['login']);
        $stack['transport']->failStatusPatch = true;
        priceAssert(!empty(priceSubmit($stack, $fixture, $input)['success']), "$entry P$process first continuation");
        $pending = new MtUniCreditControlPanelStatusSyncService(
            new MtUniCreditControlPanelStatusSyncRepository($stack['db'], $stack['clock']),
            $stack['client']
        );
        priceAssert($pending->readPersistedTarget(1)['state'] === MtUniCreditControlPanelStatusSyncStates::PENDING,
            "$entry P$process durable status target");
        $mailCount = count($stack['process2Mailer']->sent);
        priceAssert($process === 1 ? $mailCount === 0 : $mailCount > 0, "$entry P$process notification routing");
        $stack['transport']->failStatusPatch = false;
        priceAssert(!empty(priceSubmit($stack, $fixture, $input)['success']), "$entry P$process pending status replay");
        priceAssert($pending->readPersistedTarget(1)['state'] === MtUniCreditControlPanelStatusSyncStates::CONFIRMED,
            "$entry P$process status synced");
        priceAssert(pricePosts($stack) === array(), "$entry P$process no CP create");
        priceAssert(Phase9TestHarness::smartUcfCallCount($stack['smartUcfProbe']) === ($process === 1 ? 1 : 0),
            "$entry P$process no duplicate SmartUCF session");
        priceAssert(count($stack['process2Mailer']->sent) === $mailCount, "$entry P$process no duplicate Process 2 mail");
    }
}
echo "PASS unknown version, historical timeout, pending status, continuation and replay\n";
// Audited create semantics only. Validation, defaults and the full CP response
// contract are outside this fake. The archived CP contract defines the fields.
function priceCpContract($shopId, array &$records, array &$httpStatuses)
{
    $schema = json_decode(file_get_contents(__DIR__ . '/fixtures/cp_order_payload.json'), true)['idempotency'];
    return function (array $payload) use ($shopId, &$records, &$httpStatuses, $schema) {
        $id = (string) $payload['order_id'];
        $business = array();
        foreach ($schema['semantic_fields'] as $field) {
            $value = $payload[$field] ?? null;
            if ($value !== null && in_array($field, $schema['float_fields'], true)) {
                $value = (float) $value;
            } elseif ($value !== null && in_array($field, $schema['integer_fields'], true)) {
                $value = (int) $value;
            }
            $business[$field] = $value;
        }

        $existing = $records[$shopId][$id] ?? null;
        $status = $existing === null ? 201 : ($existing === $business ? 200 : 409);
        $httpStatuses[] = $status;
        if ($status === 409) {
            return array(409, array('success' => false, 'error' => 'semantic_conflict',
                'message' => 'Semantic conflict', 'data' => new stdClass()));
        }
        $records[$shopId][$id] = $business;
        return array($status, Phase7TestHarness::loginAndOrderSuccessPayloads()['order']);
    };
}

$records = array();
$httpStatuses = array();
$contract = priceCpContract(1, $records, $httpStatuses);

$fixture = $fixtures['product'];
$stack = priceStack();
$input = priceInput($stack, $fixture);
$claim = new MtUniCreditOperationOrderClaimRepository($stack['db'], $stack['clock']);
$owner = MtUniCreditLockOwnerTokenGenerator::generate();
$claim->ensureClaim($stack['storeId'], 'product', $fixture['operation_key_hash'], $owner);
$claim->bindOrderId($stack['storeId'], 'product', $fixture['operation_key_hash'], $fixture['order_id']);
unset($input['session'][MtUniCreditStorefrontFinancingSubmissionService::SESSION_ORDER_BIND_KEY]);
$stack['transport']->enqueueJson(200, Phase7TestHarness::loginAndOrderSuccessPayloads()['login']);
$stack['transport']->orderCreateResponse = $contract;
priceAssert(!empty(priceSubmit($stack, $fixture, $input)['success']), 'R02 first attempt on proven durable native claim');
$gross = priceRow($stack, $fixture);
priceAssert(count($records[1]) === 1 && (float) json_decode($gross['cp_payload'], true)['price'] === 500.0,
    'Fresh gross application');

// Use a clean synthetic pre-send attempt with exactly the captured authority.
$stack = priceStack();
$input = priceInput($stack, $fixture);
priceSeed($stack, $gross, $preSend, false);
$stack['transport']->enqueueJson(200, Phase7TestHarness::loginAndOrderSuccessPayloads()['login']);
$stack['transport']->orderCreateResponse = $contract;
priceAssert(!empty(priceSubmit($stack, $fixture, $input)['success']), 'R13 interrupted gross application reconstructed');
priceOriginal($stack, $gross);
priceAssert(count(pricePosts($stack)) === 1 && count($records[1]) === 1, 'Equivalent 200 preserves one CP application');

// A CP record can exist without a surviving attempt. Only price differs
// between these fixtures, including when the same test is run on old producers.
function priceOrphanComparison(array $fixture, $remotePrice)
{
    $records = array();
    $statuses = array();
    $contract = priceCpContract(1, $records, $statuses);
    $remote = json_decode($fixture['cp_payload'], true);
    $remote['price'] = $remotePrice;
    $contract($remote);
    $stack = priceStack();
    $input = priceInput($stack, $fixture);
    $stack['transport']->enqueueJson(200, Phase7TestHarness::loginAndOrderSuccessPayloads()['login']);
    $stack['transport']->orderCreateResponse = $contract;
    $result = priceSubmit($stack, $fixture, $input);
    $attempt = priceRow($stack, $fixture);
    priceSubmit($stack, $fixture, $input);
    $posts = pricePosts($stack);
    priceAssert(count($posts) === 1 && count($records[1]) === 1, 'No alternate-price POST or replacement CP application');
    $sent = $posts[0]['payload'];
    priceAssert($sent['order_id'] === $fixture['order_id'] && $attempt['order_id'] === $sent['order_id']
        && (int) $attempt['attempt_id'] === 1, 'Original order identity and one attempt');
    $sentPrice = (float) $sent['price'];
    $sent['price'] = $remotePrice;
    priceAssert($sent == $remote && $sent['version'] === $remote['version'], 'Only price differs');
    priceAssert($statuses[1] !== 409 || $attempt['state'] === MtUniCreditFinancingAttemptState::TERMINAL_FAILED,
        'Canonical conflict is terminal');
    return array($statuses[1], $sentPrice, !empty($result['success']), count($posts));
}

$fixture = $fixtures['checkout'];
priceAssert(priceOrphanComparison($fixture, 400.0) === array(409, 500.0, false, 1), 'R03 principal orphan conflicts');
priceAssert(priceOrphanComparison($fixture, 500.0) === array(200, 500.0, true, 1), 'R03 gross orphan is equivalent at CP');

$contractRecords = array();
$contractStatuses = array();
$shopOne = priceCpContract(1, $contractRecords, $contractStatuses);
$shopTwo = priceCpContract(2, $contractRecords, $contractStatuses);
$sample = json_decode($fixture['cp_payload'], true);
$shopOne($sample);
$shopOne(array_replace($sample, array('price' => '400.00')));
$shopOne(array_replace($sample, array('price' => 400.001)));
$shopOne(array_replace($sample, array('version' => '2.0.4')));
$shopTwo($sample);
priceAssert($contractStatuses === array(201, 200, 409, 409, 201), 'Fake compares floats, wire version and scoped shop identity');

$fixture = $fixtures['cart'];
$stack = priceStack();
$input = priceInput($stack, $fixture);
$input['load_order'] = function () { return null; };
priceAssert(empty(priceSubmit($stack, $fixture, $input)['success']), 'R03 missing bound native record blocked');
priceAssert(pricePosts($stack) === array() && priceRow($stack, $fixture) === null, 'R03 missing order does not create attempt');

$fixture = $fixtures['checkout'];
$stack = priceStack();
$input = priceInput($stack, $fixture);
$input['first_installment'] = 0.0;
Phase9TestHarness::enqueueCpCreateSuccess($stack['transport']);
priceAssert(!empty(priceSubmit($stack, $fixture, $input)['success']), 'Fresh zero-down application');
$zero = priceRow($stack, $fixture);
$stack = priceStack();
$input = priceInput($stack, $fixture);
priceSeed($stack, $zero, $preSend, false);
Phase9TestHarness::enqueueCpCreateSuccess($stack['transport']);
priceAssert(!empty(priceSubmit($stack, $fixture, $input)['success']), 'R14 zero-down candidates deduplicated');
priceAssert(count(pricePosts($stack)) === 1, 'R14 only one POST');
priceOriginal($stack, $zero);
function priceMoneyCase(array $fixture, $process, $state, $field, $value, $accepted, $sameFingerprint)
{
    $stack = priceStack($process);
    $input = priceInput($stack, $fixture);
    $body = json_decode($fixture['cp_payload'], true);
    $body[$field] = $value;
    $row = array_replace($fixture, array('cp_payload' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
    if ($sameFingerprint) {
        priceAssert(MtUniCreditControlPanelOrderPayloadBuilder::fingerprint($body) === $fixture['request_fingerprint'],
            "$field test must preserve local fingerprint");
    }
    priceSeed($stack, $row, $state);
    $confirmed = $state === MtUniCreditFinancingAttemptState::CP_CREATED;
    if ($confirmed) {
        $stack['transport']->enqueueJson(200, Phase7TestHarness::loginAndOrderSuccessPayloads()['login']);
    } else {
        Phase9TestHarness::enqueueCpCreateSuccess($stack['transport']);
    }

    $result = priceSubmit($stack, $fixture, $input);
    $posts = pricePosts($stack);
    $label = $fixture['entry_point'] . " P$process $state $field=" . json_encode($value);
    priceAssert(count($posts) === ($accepted && !$confirmed ? 1 : 0), "$label CP create count");
    priceAssert(!empty($result['success']) === $accepted, "$label outcome");
    if ($accepted && !$confirmed) {
        priceAssert($posts[0]['payload'] === json_decode($row['cp_payload'], true), "$label original saved payload on wire");
    }
    if (!$accepted) {
        priceAssert($stack['transport']->requests === array(), "$label no outbound CP calls");
        priceAssert(count($stack['process2Mailer']->sent) === 0, "$label no Process 2 mail");
    }
    priceAssert(Phase9TestHarness::smartUcfCallCount($stack['smartUcfProbe']) === ($accepted && $process === 1 ? 1 : 0),
        "$label SmartUCF calls");
    priceOriginal($stack, $row);
    priceAssert((int) priceRow($stack, $fixture)['control_panel_order_id'] === ($accepted || $confirmed ? 555001 : 0),
        "$label CP identity");
}

// Seed only the original intent, with clean downstream state for each case.
$grossIntent = array_intersect_key($gross, $fixtures['product']);
foreach (array('principal' => $fixtures['checkout'], 'gross' => $grossIntent) as $semantics => $fixture) {
    $body = json_decode($fixture['cp_payload'], true);
    foreach (array(1, 2) as $process) {
        foreach (array($preSend, MtUniCreditFinancingAttemptState::CP_FAILED_RETRYABLE, $created) as $state) {
            foreach (array('price', 'parva', 'vnoska', 'gpr') as $field) {
                $value = (float) $body[$field];
                foreach (array(
                    array($value, true, true),
                    array(number_format($value, 2, '.', ''), true, true),
                    array($value + 0.001, false, true),
                    array($value - 0.001, false, true),
                    array($value + 1.0, false, false),
                    array('1e999', false, false),
                    array('invalid', false, false),
                ) as list($testValue, $accepted, $sameFingerprint)) {
                    priceMoneyCase($fixture, $process, $state, $field, $testValue, $accepted, $sameFingerprint);
                }
            }
        }
    }
    echo "PASS $semantics exact money, P1/P2, initial/retry/confirmed proof\n";
}
foreach (array(1, 2) as $process) {
    priceMoneyCase($zero, $process, $preSend, 'parva', 0.001, false, true);
}
echo "PASS normal pre-attempt recovery, opaque historical binding, gross replay and zero-down proof\n";
echo "$priceAssertions assertions passed\n";
