<?php

/**
 * Included from mtuc10_run() — inherits that function scope.
 *
 * @var string $root
 * @var string $lib
 */

// ---------------------------------------------------------------------------
// I. Stale bound order (missing A, no attempt) → fail closed (AUD-007-F03)
// ---------------------------------------------------------------------------
$transportStale = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportStale);
$stackStale = Phase9TestHarness::stack($transportStale, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$staleOrderId = 123;
$freshOrderId = 456;
$addOrderCalls = 0;
$staleInput = Phase9TestHarness::productStorefrontInput($stackStale, $freshOrderId);
$selectionHash = MtUniCreditStorefrontOperationIdentity::productHash(
    (int) $stackStale['storeId'],
    42,
    array(),
    1,
    $staleInput['currency_code']
);
$bindKey = MtUniCreditStorefrontApplicationToken::bindKey(
    $selectionHash,
    (string) $staleInput['application_token']
);
$staleInput['session'][MtUniCreditStorefrontFinancingSubmissionService::SESSION_ORDER_BIND_KEY] = array(
    $bindKey => $staleOrderId,
    $selectionHash => $staleOrderId, // legacy bare product bind must be ignored/pruned
);
$staleInput['load_order'] = function ($orderId) use ($stackStale, $staleOrderId, $freshOrderId) {
    if ((int) $orderId === (int) $staleOrderId) {
        return null;
    }
    if ((int) $orderId === (int) $freshOrderId) {
        return Phase7TestHarness::orderRow((int) $orderId, (int) $stackStale['storeId']);
    }

    return null;
};
$staleInput['add_order'] = function ($orderData) use (&$addOrderCalls, $stackStale, $freshOrderId) {
    $addOrderCalls++;
    $stackStale['memoryDb']->seedOrder($freshOrderId, $stackStale['storeId'], MtUniCreditConstants::EXTENSION_CODE);

    return $freshOrderId;
};
$staleResult = $stackStale['storefront']->submit($staleInput);
mtuc10_assert(empty($staleResult['success']), 'stale binding: fail closed');
mtuc10_assert($addOrderCalls === 0, 'stale binding: addOrder = 0');
mtuc10_assert(
    isset($staleResult['error']) && (
        (string) $staleResult['error'] === 'legacy_binding_unresolved'
        || (string) $staleResult['error'] === 'order_missing'
    ),
    'stale binding: legacy_binding_unresolved'
);
mtuc10_assert(
    !isset($staleResult['session'][MtUniCreditStorefrontFinancingSubmissionService::SESSION_ORDER_BIND_KEY][$bindKey]),
    'stale binding: fragile session bind cleared'
);
mtuc10_assert(
    !isset($staleResult['session'][MtUniCreditStorefrontFinancingSubmissionService::SESSION_ORDER_BIND_KEY][$selectionHash]),
    'stale binding: legacy bare product bind pruned'
);
$staleAttempt = $stackStale['attempts']->findByStoreOrder($stackStale['storeId'], $freshOrderId);
mtuc10_assert($staleAttempt === null, 'stale binding: no replacement attempt');
$oldAttempt = $stackStale['attempts']->findByStoreOrder($stackStale['storeId'], $staleOrderId);
mtuc10_assert($oldAttempt === null, 'stale binding: no phantom attempt for missing A');
$claimsStale = new MtUniCreditOperationOrderClaimRepository($stackStale['db'], $stackStale['clock']);
$claimStale = $claimsStale->find(
    $stackStale['storeId'],
    MtUniCreditOperationEntryPoint::PRODUCT,
    $bindKey
);
mtuc10_assert(
    is_array($claimStale)
        && (
            !isset($claimStale['order_id'])
            || $claimStale['order_id'] === null
            || $claimStale['order_id'] === ''
            || (int) $claimStale['order_id'] === 0
        ),
    'stale binding: durable claim not poisoned with missing/wrong order'
);

// ---------------------------------------------------------------------------
// J. Valid bound order replay — addOrder = 0
// ---------------------------------------------------------------------------
$transportValid = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportValid);
$stackValid = Phase9TestHarness::stack($transportValid, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$validOrder = 10120;
$validAdd = 0;
$validInput = Phase9TestHarness::productStorefrontInput($stackValid, $validOrder);
$validInput['add_order'] = function ($orderData) use (&$validAdd, $stackValid, $validOrder) {
    $validAdd++;
    $stackValid['memoryDb']->seedOrder($validOrder, $stackValid['storeId'], MtUniCreditConstants::EXTENSION_CODE);

    return $validOrder;
};
$firstValid = $stackValid['storefront']->submit($validInput);
mtuc10_assert(!empty($firstValid['success']), 'valid bind first: success');
mtuc10_assert($validAdd === 1, 'valid bind first: addOrder = 1');
$mailBeforeValidReplay = count($stackValid['process2Mailer']->sent);
$validInput2 = $validInput;
$validInput2['session'] = isset($firstValid['session']) ? $firstValid['session'] : array();
$secondValid = $stackValid['storefront']->submit($validInput2);
mtuc10_assert(!empty($secondValid['success']), 'valid bind replay: success');
mtuc10_assert($validAdd === 1, 'valid bind replay: addOrder = 0 additional');
mtuc10_assert(Phase7TestHarness::countOrderPosts($transportValid) === 1, 'valid bind replay: CP create = 1 total');
mtuc10_assert(
    count($stackValid['process2Mailer']->sent) === $mailBeforeValidReplay,
    'valid bind replay: Process 2 mail additional = 0'
);

// ---------------------------------------------------------------------------
// J2. Cross-operation: pending A must stay untouched when B submits
// ---------------------------------------------------------------------------
$orderA = 20110;
$orderB = 20120;
$addA = 0;
$addB = 0;
$transportA = new Phase4FakeCpHttpTransport();
$stackA = Phase9TestHarness::stack($transportA, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$inputA = Phase9TestHarness::productStorefrontInput($stackA, $orderA);
$selectionA = MtUniCreditStorefrontOperationIdentity::productHash(
    (int) $stackA['storeId'],
    42,
    $inputA['product_line']->options,
    1,
    $inputA['currency_code']
);
$opHashA = MtUniCreditStorefrontApplicationToken::bindKey($selectionA, (string) $inputA['application_token']);
// Incomplete A: order + attempt exist in ORDER_CREATED, no CP / no snapshot yet.
// Financial identity must match what a live replay will compute (AUD-007-F02).
$stackA['memoryDb']->seedOrder($orderA, $stackA['storeId'], MtUniCreditConstants::EXTENSION_CODE);
$unicidA = MtUniCreditBootstrap::credentialsRepositoryFromDb($stackA['db'])->getUnicid($stackA['storeId']);
$shopA = mtuc4_valid_shop_snapshot(array('uni_proces' => 1));
$calcA = Phase9TestHarness::calculation($shopA);
$orderARow = Phase7TestHarness::orderRow($orderA, $stackA['storeId']);
$orderAProducts = array(
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
$payloadA = (new MtUniCreditControlPanelOrderPayloadBuilder())->build(
    $orderA,
    $orderARow,
    $orderAProducts,
    $calcA,
    $shopA
);
$fpA = MtUniCreditControlPanelOrderPayloadBuilder::fingerprint($payloadA);
$selHashA = hash('sha256', $calcA->scheme->kopCode . '|' . $calcA->scheme->months . '|' . $fpA);
$attemptATime = $stackA['clock']->formatUtc($stackA['clock']->now());
$stackA['memoryDb']->seedFinancingAttempt(array(
    'store_id' => $stackA['storeId'],
    'order_id' => $orderA,
    'unicid' => $unicidA,
    'operation_key_hash' => $opHashA,
    'selection_hash' => $selHashA,
    'request_fingerprint' => $fpA,
    'entry_point' => MtUniCreditOperationEntryPoint::PRODUCT,
    'state' => MtUniCreditFinancingAttemptState::ORDER_CREATED,
    'created_at' => $attemptATime,
    'updated_at' => $attemptATime,
));
$attemptA = $stackA['attempts']->findByStoreOrder($stackA['storeId'], $orderA);
$inputA['session'][MtUniCreditStorefrontFinancingSubmissionService::SESSION_ORDER_BIND_KEY] = array(
    $opHashA => $orderA,
);
$addA = 1; // order already materialized for incomplete A
mtuc10_assert($attemptA !== null, 'cross-op A: attempt exists');
$stateABefore = (string) $attemptA['state'];
$updatedABefore = (string) $attemptA['updated_at'];
$cpABefore = (int) $attemptA['control_panel_order_id'];
mtuc10_assert($stateABefore === MtUniCreditFinancingAttemptState::ORDER_CREATED, 'cross-op A: incomplete order_created');
mtuc10_assert($cpABefore === 0, 'cross-op A: no CP yet');

$transportB = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportB);
$stackB = Phase9TestHarness::stack(
    $transportB,
    null,
    $stackA['memoryDb'],
    Phase5TestHarness::STORE_A,
    array('uni_proces' => 1)
);
$inputB = Phase9TestHarness::productStorefrontInput($stackB, $orderB);
$inputB['session'][MtUniCreditStorefrontFinancingSubmissionService::SESSION_ORDER_BIND_KEY] = $inputA['session'][MtUniCreditStorefrontFinancingSubmissionService::SESSION_ORDER_BIND_KEY];
$inputB['add_order'] = function ($orderData) use (&$addB, $stackB, $orderB) {
    $addB++;
    $stackB['memoryDb']->seedOrder($orderB, $stackB['storeId'], MtUniCreditConstants::EXTENSION_CODE);

    return $orderB;
};
$resultB = $stackB['storefront']->submit($inputB);
mtuc10_assert(!empty($resultB['success']), 'cross-op B: new submit succeeds');
mtuc10_assert($addB === 1, 'cross-op B: addOrder = 1');
mtuc10_assert((int) $resultB['order_id'] === $orderB, 'cross-op B: uses own order');
mtuc10_assert(Phase7TestHarness::countOrderPosts($transportB) === 1, 'cross-op B: CP create = 1');
mtuc10_assert(Phase7TestHarness::countOrderPosts($transportA) === 0, 'cross-op A: no CP from B path');
$attemptAAfter = $stackA['attempts']->findByStoreOrder($stackA['storeId'], $orderA);
mtuc10_assert($attemptAAfter !== null, 'cross-op A: attempt still present');
mtuc10_assert((string) $attemptAAfter['state'] === $stateABefore, 'cross-op A: state untouched');
mtuc10_assert((string) $attemptAAfter['updated_at'] === $updatedABefore, 'cross-op A: updated_at untouched');
mtuc10_assert((int) $attemptAAfter['control_panel_order_id'] === $cpABefore, 'cross-op A: CP id untouched');
mtuc10_assert($addA === 1, 'cross-op A: no new OC order');

// A predates snapshots: replay must refuse without inventing historical consent.
$inputAReplay = $inputA;
$inputAReplay['add_order'] = function ($orderData) use (&$addA) {
    $addA++;

    return 99999;
};
$resultAReplay = $stackA['storefront']->submit($inputAReplay);
mtuc10_assert(empty($resultAReplay['success']) && $resultAReplay['error'] === 'consent_evidence',
    'cross-op A replay: snapshot-less history fails closed');
mtuc10_assert($addA === 1, 'cross-op A replay: addOrder = 0 additional');
mtuc10_assert((int) $resultAReplay['order_id'] === $orderA, 'cross-op A replay: same order');
$attemptAReplay = $stackA['attempts']->findByStoreOrder($stackA['storeId'], $orderA);
mtuc10_assert($attemptAReplay === $attemptAAfter
    && $attemptAReplay['application_snapshot_json'] === null
    && $attemptAReplay['application_snapshot_hash'] === '',
    'cross-op A replay: attempt identity/state/timestamps unchanged; no snapshot or consent backfill');
mtuc10_assert(count($transportA->requests) === 0
    && Phase9TestHarness::smartUcfCallCount($stackA['smartUcfProbe']) === 0
    && count($stackA['process2Mailer']->sent) === 0,
    'cross-op A replay: no CP, SmartUCF or mail effects');
mtuc10_assert($stackA['bankStatuses']->findByOrderId($stackA['storeId'], $orderA) === null,
    'cross-op A replay: no banking failure state');
mtuc10_assert($stackA['db']->query("SELECT * FROM `oc_mt_uni_credit_financing_attempt` WHERE `store_id` = "
    . (int) $stackA['storeId'] . " AND `order_id` = '" . (int) $orderA . "'")->num_rows === 1,
    'cross-op A replay: one existing attempt, no duplicate');

// A genuine persisted v1 application may continue with current-request acceptance.
$legacyTransport = new Phase4FakeCpHttpTransport();
$legacyStack = Phase9TestHarness::stack($legacyTransport, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$legacyOrderId = 20111;
$legacyInput = Phase9TestHarness::productStorefrontInput($legacyStack, $legacyOrderId);
$legacyProductHash = MtUniCreditStorefrontOperationIdentity::productHash(
    $legacyStack['storeId'], 42, $legacyInput['product_line']->options, 1, $legacyInput['currency_code']);
$legacyOperation = MtUniCreditStorefrontApplicationToken::bindKey($legacyProductHash, $legacyInput['application_token']);
$legacyOrder = Phase7TestHarness::orderRow($legacyOrderId, $legacyStack['storeId']);
Phase9TestHarness::seedBankOrder($legacyStack['memoryDb'], $legacyOrderId, $legacyStack['storeId']);
$legacyPayload = (new MtUniCreditControlPanelOrderPayloadBuilder())->build(
    $legacyOrderId, $legacyOrder, $orderAProducts, $calcA, $shopA);
$legacyFingerprint = MtUniCreditControlPanelOrderPayloadBuilder::fingerprint($legacyPayload);
$legacySelection = hash('sha256', $calcA->scheme->kopCode . '|' . $calcA->scheme->months . '|' . $legacyFingerprint);
$legacySnapshot = MtUniCreditApplicationSnapshot::fromLive($calcA, $legacyOrder, $orderAProducts, $shopA,
    MtUniCreditOperationEntryPoint::PRODUCT, $legacyOperation, $legacySelection, $legacyFingerprint);
$legacyJson = MtUniCreditApplicationSnapshot::encode($legacySnapshot);
$legacyHash = MtUniCreditApplicationSnapshot::hash($legacySnapshot);
$legacyTime = $legacyStack['clock']->formatUtc($legacyStack['clock']->now());
$legacySeed = $legacyStack['memoryDb']->seedFinancingAttempt(array(
    'store_id' => $legacyStack['storeId'],
    'order_id' => $legacyOrderId,
    'unicid' => $unicidA,
    'operation_key_hash' => $legacyOperation,
    'selection_hash' => $legacySelection,
    'request_fingerprint' => $legacyFingerprint,
    'entry_point' => MtUniCreditOperationEntryPoint::PRODUCT,
    'state' => MtUniCreditFinancingAttemptState::ORDER_CREATED,
    'created_at' => $legacyTime,
    'updated_at' => $legacyTime,
    'application_snapshot_json' => $legacyJson,
    'application_snapshot_hash' => $legacyHash,
));
$legacyInput['session'][MtUniCreditStorefrontFinancingSubmissionService::SESSION_ORDER_BIND_KEY] = array(
    $legacyOperation => $legacyOrderId,
);
$legacyAdds = 0;
$legacyInput['add_order'] = function ($orderData) use (&$legacyAdds) {
    $legacyAdds++;

    return 99998;
};
// Posted selection B cannot replace the persisted selection A.
$legacyInput['scheme_key'] = 'standard|POST-B|24';
$legacyNoConsent = $legacyInput;
$legacyNoConsent['consent'] = array();
$legacyBefore = $legacyStack['attempts']->findByStoreOrder($legacyStack['storeId'], $legacyOrderId);
$legacyRefused = $legacyStack['storefront']->submit($legacyNoConsent);
mtuc10_assert(empty($legacyRefused['success']) && $legacyRefused['error'] === 'consent_required',
    'v1 replay: current-request mandatory acceptance required');
mtuc10_assert($legacyAdds === 0 && count($legacyTransport->requests) === 0
    && Phase9TestHarness::smartUcfCallCount($legacyStack['smartUcfProbe']) === 0
    && count($legacyStack['process2Mailer']->sent) === 0,
    'v1 replay: missing acceptance has no order/CP/SmartUCF/mail effects');
mtuc10_assert($legacyStack['attempts']->findByStoreOrder($legacyStack['storeId'], $legacyOrderId) === $legacyBefore
    && $legacyStack['bankStatuses']->findByOrderId($legacyStack['storeId'], $legacyOrderId) === null,
    'v1 replay: refusal preserves historical attempt and bank state');
Phase9TestHarness::enqueueCpCreateSuccess($legacyTransport);
$legacyResult = $legacyStack['storefront']->submit($legacyInput);
mtuc10_assert(!empty($legacyResult['success']) && (int) $legacyResult['order_id'] === $legacyOrderId
    && $legacyAdds === 0, 'v1 replay: authorized continuation reuses its own existing order');
$legacyAfter = $legacyStack['attempts']->findByStoreOrder($legacyStack['storeId'], $legacyOrderId);
mtuc10_assert($legacySnapshot['version'] === 1 && !isset($legacySnapshot['consent_evidence'])
    && $legacyAfter['application_snapshot_json'] === $legacyJson
    && $legacyAfter['application_snapshot_hash'] === $legacyHash
    && hash('sha256', $legacyJson) === $legacyHash,
    'v1 replay: original serialization/hash unchanged, no consent evidence backfill');
mtuc10_assert($legacyAfter['selection_hash'] === $legacySelection
    && $legacySnapshot['scheme']['kop_code'] === 'KOPSTD' && $legacySnapshot['scheme']['months'] === 12
    && (int) $legacyAfter['attempt_id'] === (int) $legacySeed['attempt_id'],
    'v1 replay: persisted selection A and existing attempt retained despite posted B');
$legacyMailCount = count($legacyStack['process2Mailer']->sent);
mtuc10_assert(Phase7TestHarness::countOrderPosts($legacyTransport) === 1 && $legacyMailCount > 0
    && Phase9TestHarness::smartUcfCallCount($legacyStack['smartUcfProbe']) === 0,
    'v1 replay: Process 2 reaches fake CP/mail ports, no SmartUCF session');
$legacyInput['session'] = $legacyResult['session'];
$legacyReplay = $legacyStack['storefront']->submit($legacyInput);
mtuc10_assert(!empty($legacyReplay['success']) && (int) $legacyReplay['order_id'] === $legacyOrderId
    && $legacyAdds === 0 && Phase7TestHarness::countOrderPosts($legacyTransport) === 1
    && count($legacyStack['process2Mailer']->sent) === $legacyMailCount,
    'v1 replay: repeated authorized continuation is idempotent');
$legacyFinal = $legacyStack['attempts']->findByStoreOrder($legacyStack['storeId'], $legacyOrderId);
mtuc10_assert($legacyFinal['application_snapshot_json'] === $legacyJson
    && $legacyFinal['application_snapshot_hash'] === $legacyHash
    && $legacyStack['db']->query("SELECT * FROM `oc_mt_uni_credit_financing_attempt` WHERE `store_id` = "
        . (int) $legacyStack['storeId'] . " AND `order_id` = '" . $legacyOrderId . "'")->num_rows === 1,
    'v1 replay: repeated continuation preserves legacy bytes/hash without duplicate attempt');

// Completed A + new B (same product/scheme, new application token).
$transportC = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportC);
$stackC = Phase9TestHarness::stack($transportC, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$orderCompleted = 20210;
$orderFresh = 20220;
$addCompleted = 0;
$addFresh = 0;
$inputCompleted = Phase9TestHarness::productStorefrontInput($stackC, $orderCompleted);
$inputCompleted['add_order'] = function ($orderData) use (&$addCompleted, $stackC, $orderCompleted) {
    $addCompleted++;
    $stackC['memoryDb']->seedOrder($orderCompleted, $stackC['storeId'], MtUniCreditConstants::EXTENSION_CODE);

    return $orderCompleted;
};
$resultCompleted = $stackC['storefront']->submit($inputCompleted);
mtuc10_assert(!empty($resultCompleted['success']), 'completed A: success');
mtuc10_assert($addCompleted === 1, 'completed A: addOrder = 1');
$inputFresh = Phase9TestHarness::productStorefrontInput($stackC, $orderFresh);
$inputFresh['add_order'] = function ($orderData) use (&$addFresh, $stackC, $orderFresh) {
    $addFresh++;
    $stackC['memoryDb']->seedOrder($orderFresh, $stackC['storeId'], MtUniCreditConstants::EXTENSION_CODE);

    return $orderFresh;
};
Phase9TestHarness::enqueueCpOrderCreateSuccess($transportC);
$resultFresh = $stackC['storefront']->submit($inputFresh);
mtuc10_assert(!empty($resultFresh['success']), 'new app after completed: success');
mtuc10_assert($addFresh === 1, 'new app after completed: addOrder = 1');
mtuc10_assert((int) $resultFresh['order_id'] === $orderFresh, 'new app after completed: distinct order');
mtuc10_assert(
    (int) $resultFresh['order_id'] !== (int) $resultCompleted['order_id'],
    'new app after completed: not rebound to completed order'
);

// Cart path: distinct application tokens → distinct orders.
$transportCart = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportCart);
$stackCart = Phase9TestHarness::stack($transportCart, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$cartOrder1 = 20310;
$cartOrder2 = 20320;
$cartAdd = 0;
$cartInput1 = Phase9TestHarness::cartStorefrontInput($stackCart, $cartOrder1);
$cartInput1['add_order'] = function ($orderData) use (&$cartAdd, $stackCart, $cartOrder1) {
    $cartAdd++;
    $stackCart['memoryDb']->seedOrder($cartOrder1, $stackCart['storeId'], MtUniCreditConstants::EXTENSION_CODE);

    return $cartOrder1;
};
$cartResult1 = $stackCart['storefront']->submit($cartInput1);
$cartInput2 = Phase9TestHarness::cartStorefrontInput($stackCart, $cartOrder2);
$cartInput2['add_order'] = function ($orderData) use (&$cartAdd, $stackCart, $cartOrder2) {
    $cartAdd++;
    $stackCart['memoryDb']->seedOrder($cartOrder2, $stackCart['storeId'], MtUniCreditConstants::EXTENSION_CODE);

    return $cartOrder2;
};
Phase9TestHarness::enqueueCpOrderCreateSuccess($transportCart);
$cartResult2 = $stackCart['storefront']->submit($cartInput2);
mtuc10_assert(!empty($cartResult1['success']) && !empty($cartResult2['success']), 'cart cross-op: both succeed');
mtuc10_assert($cartAdd === 2, 'cart cross-op: addOrder = 2 for distinct applications');
mtuc10_assert((int) $cartResult1['order_id'] !== (int) $cartResult2['order_id'], 'cart cross-op: distinct orders');

// Concurrency same operation: second simultaneous owner is rejected without second order.
$transportConc = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportConc);
$stackConc = Phase9TestHarness::stack($transportConc, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$concOrder = 20410;
$concAdd = 0;
$concInput = Phase9TestHarness::productStorefrontInput($stackConc, $concOrder);
$selectionConc = MtUniCreditStorefrontOperationIdentity::productHash(
    (int) $stackConc['storeId'],
    42,
    array(),
    1,
    $concInput['currency_code']
);
$opConc = MtUniCreditStorefrontApplicationToken::bindKey($selectionConc, (string) $concInput['application_token']);
$ownerA = MtUniCreditLockOwnerTokenGenerator::generate();
$ownerB = MtUniCreditLockOwnerTokenGenerator::generate();
mtuc10_assert(
    $stackConc['locks']->acquire(
        $stackConc['storeId'],
        MtUniCreditOperationEntryPoint::PRODUCT,
        $opConc,
        $ownerA
    ),
    'concurrency: first owner acquires'
);
$concInput['lock_owner_token'] = $ownerB;
$concInput['add_order'] = function ($orderData) use (&$concAdd) {
    $concAdd++;

    return 20499;
};
$concBlocked = $stackConc['storefront']->submit($concInput);
mtuc10_assert(empty($concBlocked['success']), 'concurrency same op: second owner rejected');
mtuc10_assert($concAdd === 0, 'concurrency same op: no addOrder while locked');
$stackConc['locks']->release(
    $stackConc['storeId'],
    MtUniCreditOperationEntryPoint::PRODUCT,
    $opConc,
    $ownerA
);

// Wiring: application token issued + posted.
mtuc10_assert(
    is_file($lib . DIRECTORY_SEPARATOR . 'storefront_application_token.php'),
    'wiring: storefront_application_token present'
);
$productWidget = mtuc10_read(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'view' . DIRECTORY_SEPARATOR . 'theme' . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR
        . 'template' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'product_widget.twig'
);
$jsSrc = mtuc10_read(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'view' . DIRECTORY_SEPARATOR . 'theme' . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR
        . 'template' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'storefront.js'
);
$productCtrlWiring = mtuc10_read(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'product.php'
);
mtuc10_assert(strpos($productWidget, 'data-application-token') !== false, 'wiring: product widget application token');
mtuc10_assert(strpos($jsSrc, 'application_token') !== false, 'wiring: JS posts application_token');
mtuc10_assert(strpos($productCtrlWiring, 'application_token') !== false, 'wiring: product controller application_token');
