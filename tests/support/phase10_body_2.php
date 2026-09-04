<?php
// ---------------------------------------------------------------------------
// I. Stale bound order → unbind + fresh addOrder
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
    'BGN'
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
mtuc10_assert(!empty($staleResult['success']), 'stale binding: fresh submit succeeds');
mtuc10_assert($addOrderCalls === 1, 'stale binding: new addOrder() call = 1');
mtuc10_assert((int) $staleResult['order_id'] === $freshOrderId, 'stale binding: new order id used');
mtuc10_assert(
    isset($staleResult['session'][MtUniCreditStorefrontFinancingSubmissionService::SESSION_ORDER_BIND_KEY][$bindKey])
        && (int) $staleResult['session'][MtUniCreditStorefrontFinancingSubmissionService::SESSION_ORDER_BIND_KEY][$bindKey] === $freshOrderId,
    'stale binding: session rebound to fresh order under application bind key'
);
mtuc10_assert(
    !isset($staleResult['session'][MtUniCreditStorefrontFinancingSubmissionService::SESSION_ORDER_BIND_KEY][$selectionHash]),
    'stale binding: legacy bare product bind pruned'
);
$staleAttempt = $stackStale['attempts']->findByStoreOrder($stackStale['storeId'], $freshOrderId);
mtuc10_assert($staleAttempt !== null, 'stale binding: attempt tied to fresh order');
$oldAttempt = $stackStale['attempts']->findByStoreOrder($stackStale['storeId'], $staleOrderId);
mtuc10_assert($oldAttempt === null, 'stale binding: old attempt not reused/migrated');

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
    array(),
    1,
    'BGN'
);
$opHashA = MtUniCreditStorefrontApplicationToken::bindKey($selectionA, (string) $inputA['application_token']);
// Incomplete A: order + attempt exist in ORDER_CREATED, no CP activity yet.
$stackA['memoryDb']->seedOrder($orderA, $stackA['storeId'], MtUniCreditConstants::EXTENSION_CODE);
$unicidA = MtUniCreditBootstrap::credentialsRepositoryFromDb($stackA['db'])->getUnicid($stackA['storeId']);
$attemptA = $stackA['attempts']->findOrCreateAttempt(
    $stackA['storeId'],
    $orderA,
    $unicidA,
    $opHashA,
    hash('sha256', 'sel-a'),
    hash('sha256', 'fp-a'),
    MtUniCreditOperationEntryPoint::PRODUCT
);
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

// Explicit replay of A resumes only A (token already authenticated via B on shared DB).
Phase9TestHarness::enqueueCpOrderCreateSuccess($transportA);
$inputAReplay = $inputA;
$inputAReplay['add_order'] = function ($orderData) use (&$addA) {
    $addA++;

    return 99999;
};
$resultAReplay = $stackA['storefront']->submit($inputAReplay);
mtuc10_assert(!empty($resultAReplay['success']), 'cross-op A replay: resumes itself');
mtuc10_assert($addA === 1, 'cross-op A replay: addOrder = 0 additional');
mtuc10_assert((int) $resultAReplay['order_id'] === $orderA, 'cross-op A replay: same order');

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
    'BGN'
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



