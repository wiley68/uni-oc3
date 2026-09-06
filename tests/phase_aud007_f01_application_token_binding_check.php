<?php

/**
 * AUD-007 F01 — authoritative Product/Cart application token binding.
 * Run: php tests/phase_aud007_f01_application_token_binding_check.php
 *
 * PHP 7.3 compatible. Offline.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/support/phase2_memory_db.php';

$failures = array();
$passes = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucAud007F01_assert($condition, $message)
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

mtucAud007F01_assert(mtuc_phase0_network_isolation_active(), 'isolation: offline guard active');

/**
 * @param array<string, mixed> $result
 * @return bool
 */
function mtucAud007F01_rejected($result)
{
    return empty($result['success']);
}

/**
 * @param int $addCount
 * @param Phase4FakeCpHttpTransport $transport
 * @param object $probe
 * @param string $label
 * @return void
 */
function mtucAud007F01_assertZeroSideEffects($addCount, $transport, $probe, $label)
{
    mtucAud007F01_assert($addCount === 0, $label . ': addOrder = 0');
    mtucAud007F01_assert(Phase7TestHarness::countOrderPosts($transport) === 0, $label . ': CP create = 0');
    mtucAud007F01_assert(Phase9TestHarness::smartUcfCallCount($probe) === 0, $label . ': SmartUCF = 0');
}

// ---------------------------------------------------------------------------
// Format strictness
// ---------------------------------------------------------------------------
mtucAud007F01_assert(
    MtUniCreditStorefrontApplicationToken::isValidFormat('0123456789abcdef0123456789abcdef'),
    'format: exact 32 lowercase hex accepted'
);
mtucAud007F01_assert(
    !MtUniCreditStorefrontApplicationToken::isValidFormat("0123456789abcdef0123456789abcdef\n"),
    'format: trailing LF rejected'
);
mtucAud007F01_assert(
    !MtUniCreditStorefrontApplicationToken::isValidFormat('0123456789ABCDEF0123456789ABCDEF'),
    'format: uppercase rejected'
);
mtucAud007F01_assert(
    !MtUniCreditStorefrontApplicationToken::isValidFormat(' 0123456789abcdef0123456789abcdef'),
    'format: leading whitespace rejected'
);

$sessionFmt = array();
$selEmpty = MtUniCreditStorefrontOperationIdentity::productHash(1, 42, array(), 1, 'BGN');
$issued = MtUniCreditStorefrontApplicationToken::issue(
    $sessionFmt,
    1,
    MtUniCreditOperationEntryPoint::PRODUCT,
    $selEmpty
);
mtucAud007F01_assert(
    MtUniCreditStorefrontApplicationToken::accepts(
        $sessionFmt,
        $issued,
        1,
        MtUniCreditOperationEntryPoint::PRODUCT,
        $selEmpty
    ),
    'accepts: issued same-selection Product token'
);
mtucAud007F01_assert(
    !MtUniCreditStorefrontApplicationToken::accepts(
        $sessionFmt,
        $issued . "\n",
        1,
        MtUniCreditOperationEntryPoint::PRODUCT,
        $selEmpty
    ),
    'accepts: trailing-LF issued token rejected (no trim)'
);

// ---------------------------------------------------------------------------
// Unknown / evicted tokens
// ---------------------------------------------------------------------------
$unknown = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
mtucAud007F01_assert(
    !MtUniCreditStorefrontApplicationToken::accepts(
        $sessionFmt,
        $unknown,
        1,
        MtUniCreditOperationEntryPoint::PRODUCT,
        $selEmpty
    ),
    'accepts: unknown format-valid token rejected'
);

$sessionEvict = array();
$kept = array();
for ($i = 0; $i < MtUniCreditStorefrontApplicationToken::MAX_ISSUED + 2; $i++) {
    $kept[] = MtUniCreditStorefrontApplicationToken::issue(
        $sessionEvict,
        1,
        MtUniCreditOperationEntryPoint::PRODUCT,
        $selEmpty
    );
}
$evicted = $kept[0];
mtucAud007F01_assert(
    !isset($sessionEvict[MtUniCreditStorefrontApplicationToken::SESSION_ISSUED_KEY][$evicted]),
    'eviction: oldest token removed from registry'
);
mtucAud007F01_assert(
    !MtUniCreditStorefrontApplicationToken::accepts(
        $sessionEvict,
        $evicted,
        1,
        MtUniCreditOperationEntryPoint::PRODUCT,
        $selEmpty
    ),
    'accepts: evicted token rejected (no format fallback)'
);

// ---------------------------------------------------------------------------
// Unknown token submit — Product + Cart
// ---------------------------------------------------------------------------
$transportUnknownP = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportUnknownP);
$stackUnknownP = Phase9TestHarness::stack($transportUnknownP, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$addUnknownP = 0;
$inputUnknownP = Phase9TestHarness::productStorefrontInput($stackUnknownP, 70101);
$inputUnknownP['application_token'] = $unknown;
$inputUnknownP['add_order'] = function () use (&$addUnknownP) {
    $addUnknownP++;

    return 70101;
};
$resUnknownP = $stackUnknownP['storefront']->submit($inputUnknownP);
mtucAud007F01_assert(mtucAud007F01_rejected($resUnknownP), 'unknown Product token: rejected');
mtucAud007F01_assertZeroSideEffects(
    $addUnknownP,
    $transportUnknownP,
    $stackUnknownP['smartUcfProbe'],
    'unknown Product token'
);

$transportUnknownC = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportUnknownC);
$stackUnknownC = Phase9TestHarness::stack($transportUnknownC, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$addUnknownC = 0;
$inputUnknownC = Phase9TestHarness::cartStorefrontInput($stackUnknownC, 70102);
$inputUnknownC['application_token'] = $unknown;
$inputUnknownC['add_order'] = function () use (&$addUnknownC) {
    $addUnknownC++;

    return 70102;
};
$resUnknownC = $stackUnknownC['storefront']->submit($inputUnknownC);
mtucAud007F01_assert(mtucAud007F01_rejected($resUnknownC), 'unknown Cart token: rejected');
mtucAud007F01_assertZeroSideEffects(
    $addUnknownC,
    $transportUnknownC,
    $stackUnknownC['smartUcfProbe'],
    'unknown Cart token'
);

// ---------------------------------------------------------------------------
// Cross-entry-point
// ---------------------------------------------------------------------------
$transportXEntry = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportXEntry);
$stackXEntry = Phase9TestHarness::stack($transportXEntry, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$inputProductToken = Phase9TestHarness::productStorefrontInput($stackXEntry, 70103);
$inputCartWithProductToken = Phase9TestHarness::cartStorefrontInput($stackXEntry, 70103);
$addXEntry = 0;
$inputCartWithProductToken['application_token'] = $inputProductToken['application_token'];
$inputCartWithProductToken['session'] = $inputProductToken['session'];
$inputCartWithProductToken['add_order'] = function () use (&$addXEntry) {
    $addXEntry++;

    return 70103;
};
$resXEntry = $stackXEntry['storefront']->submit($inputCartWithProductToken);
mtucAud007F01_assert(mtucAud007F01_rejected($resXEntry), 'Product token → Cart: rejected');
mtucAud007F01_assertZeroSideEffects(
    $addXEntry,
    $transportXEntry,
    $stackXEntry['smartUcfProbe'],
    'Product→Cart'
);

$transportXEntry2 = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportXEntry2);
$stackXEntry2 = Phase9TestHarness::stack($transportXEntry2, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$inputCartToken = Phase9TestHarness::cartStorefrontInput($stackXEntry2, 70104);
$inputProductWithCartToken = Phase9TestHarness::productStorefrontInput($stackXEntry2, 70104);
$addXEntry2 = 0;
$inputProductWithCartToken['application_token'] = $inputCartToken['application_token'];
$inputProductWithCartToken['session'] = $inputCartToken['session'];
$inputProductWithCartToken['add_order'] = function () use (&$addXEntry2) {
    $addXEntry2++;

    return 70104;
};
$resXEntry2 = $stackXEntry2['storefront']->submit($inputProductWithCartToken);
mtucAud007F01_assert(mtucAud007F01_rejected($resXEntry2), 'Cart token → Product: rejected');
mtucAud007F01_assertZeroSideEffects(
    $addXEntry2,
    $transportXEntry2,
    $stackXEntry2['smartUcfProbe'],
    'Cart→Product'
);

// ---------------------------------------------------------------------------
// Cross-store
// ---------------------------------------------------------------------------
$transportStore = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportStore);
$stackStore1 = Phase9TestHarness::stack($transportStore, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$stackStore2 = Phase9TestHarness::stack($transportStore, null, null, Phase5TestHarness::STORE_B, array('uni_proces' => 1));
$inputStore1 = Phase9TestHarness::productStorefrontInput($stackStore1, 70105);
$inputStore2 = Phase9TestHarness::productStorefrontInput($stackStore2, 70105);
$addStore = 0;
$inputStore2['application_token'] = $inputStore1['application_token'];
$inputStore2['session'] = $inputStore1['session'];
$inputStore2['add_order'] = function () use (&$addStore) {
    $addStore++;

    return 70105;
};
$resStore = $stackStore2['storefront']->submit($inputStore2);
mtucAud007F01_assert(mtucAud007F01_rejected($resStore), 'store-1 token → store-2: rejected');
mtucAud007F01_assertZeroSideEffects(
    $addStore,
    $transportStore,
    $stackStore2['smartUcfProbe'],
    'cross-store'
);

// ---------------------------------------------------------------------------
// Product changed quantity / options
// ---------------------------------------------------------------------------
$transportQty = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportQty);
$stackQty = Phase9TestHarness::stack($transportQty, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$inputQty = Phase9TestHarness::productStorefrontInput($stackQty, 70106);
$inputQty['product_line'] = new MtUniCreditProductLine(
    42,
    'Example',
    'EX',
    array(7),
    2,
    500.0,
    500.0,
    1000.0,
    0,
    array(),
    0
);
// Keep original token bound to qty=1; submit qty=2 without rebind.
$addQty = 0;
$inputQty['add_order'] = function () use (&$addQty) {
    $addQty++;

    return 70106;
};
$resQty = $stackQty['storefront']->submit($inputQty);
mtucAud007F01_assert(mtucAud007F01_rejected($resQty), 'Product qty change: rejected');
mtucAud007F01_assertZeroSideEffects($addQty, $transportQty, $stackQty['smartUcfProbe'], 'Product qty change');

$transportOpt = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportOpt);
$stackOpt = Phase9TestHarness::stack($transportOpt, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$inputOpt = Phase9TestHarness::productStorefrontInput($stackOpt, 70107);
$inputOpt['product_line'] = new MtUniCreditProductLine(
    42,
    'Example',
    'EX',
    array(7),
    1,
    500.0,
    500.0,
    500.0,
    0,
    array(
        array(
            'product_option_id' => 5,
            'product_option_value_id' => 11,
            'value' => 'Red',
            'type' => 'checkbox',
        ),
    ),
    0
);
$addOpt = 0;
$inputOpt['add_order'] = function () use (&$addOpt) {
    $addOpt++;

    return 70107;
};
$resOpt = $stackOpt['storefront']->submit($inputOpt);
mtucAud007F01_assert(mtucAud007F01_rejected($resOpt), 'Product option change: rejected');
mtucAud007F01_assertZeroSideEffects($addOpt, $transportOpt, $stackOpt['smartUcfProbe'], 'Product option change');

// ---------------------------------------------------------------------------
// Cart changed state
// ---------------------------------------------------------------------------
$transportCartChange = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportCartChange);
$stackCartChange = Phase9TestHarness::stack(
    $transportCartChange,
    null,
    null,
    Phase5TestHarness::STORE_A,
    array('uni_proces' => 1)
);
$cartC1 = new MtUniCreditCartContext(
    array(
        new MtUniCreditCartLine(new MtUniCreditProductContext(42, array(7), 500.0), 0, 1, 500.0),
    ),
    500.0
);
$cartC2 = new MtUniCreditCartContext(
    array(
        new MtUniCreditCartLine(new MtUniCreditProductContext(42, array(7), 700.0), 0, 1, 700.0),
    ),
    700.0
);
$inputCartC1 = Phase9TestHarness::cartStorefrontInput($stackCartChange, 70108, $cartC1);
$inputCartC2 = Phase9TestHarness::cartStorefrontInput($stackCartChange, 70108, $cartC2);
$addCartChange = 0;
$inputCartC2['application_token'] = $inputCartC1['application_token'];
$inputCartC2['session'] = $inputCartC1['session'];
$inputCartC2['add_order'] = function () use (&$addCartChange) {
    $addCartChange++;

    return 70108;
};
$resCartChange = $stackCartChange['storefront']->submit($inputCartC2);
mtucAud007F01_assert(mtucAud007F01_rejected($resCartChange), 'Cart change: rejected');
mtucAud007F01_assertZeroSideEffects(
    $addCartChange,
    $transportCartChange,
    $stackCartChange['smartUcfProbe'],
    'Cart change'
);

// Even when client fingerprint is omitted and substituted with the *current* cart,
// the old token remains bound to C1 and must still reject.
$inputCartEmptyFp = $inputCartC2;
$inputCartEmptyFp['cart_fingerprint'] = MtUniCreditStorefrontOperationIdentity::cartFingerprintFromContext(
    $cartC2,
    'BGN'
);
$addCartEmptyFp = 0;
$inputCartEmptyFp['add_order'] = function () use (&$addCartEmptyFp) {
    $addCartEmptyFp++;

    return 70108;
};
$resCartEmptyFp = $stackCartChange['storefront']->submit($inputCartEmptyFp);
mtucAud007F01_assert(mtucAud007F01_rejected($resCartEmptyFp), 'Cart change + substituted fingerprint: rejected');
mtucAud007F01_assert($addCartEmptyFp === 0, 'Cart change + substituted fingerprint: addOrder = 0');

// ---------------------------------------------------------------------------
// Happy path + same-operation replay + fresh token new application
// ---------------------------------------------------------------------------
$transportOk = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportOk);
$stackOk = Phase9TestHarness::stack($transportOk, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$orderOk = 70201;
$addOk = 0;
$inputOk = Phase9TestHarness::productStorefrontInput($stackOk, $orderOk);
$inputOk['add_order'] = function ($orderData) use (&$addOk, $stackOk, $orderOk) {
    $addOk++;
    $stackOk['memoryDb']->seedOrder($orderOk, $stackOk['storeId'], MtUniCreditConstants::EXTENSION_CODE);

    return $orderOk;
};
$selOk = MtUniCreditStorefrontOperationIdentity::productHash(
    (int) $stackOk['storeId'],
    42,
    array(),
    1,
    'BGN'
);
$opOk = MtUniCreditStorefrontApplicationToken::bindKey($selOk, (string) $inputOk['application_token']);
$firstOk = $stackOk['storefront']->submit($inputOk);
mtucAud007F01_assert(!empty($firstOk['success']), 'issued same-selection Product: accepted');
mtucAud007F01_assert($addOk === 1, 'issued Product: first addOrder = 1');
$cpOk = Phase7TestHarness::countOrderPosts($transportOk);
$smartOk = Phase9TestHarness::smartUcfCallCount($stackOk['smartUcfProbe']);

$inputOkReplay = $inputOk;
$inputOkReplay['session'] = isset($firstOk['session']) ? $firstOk['session'] : array();
Phase9TestHarness::enqueueCpCreateSuccess($transportOk);
$secondOk = $stackOk['storefront']->submit($inputOkReplay);
mtucAud007F01_assert(!empty($secondOk['success']), 'same-token same-selection replay: accepted');
mtucAud007F01_assert($addOk === 1, 'replay: additional addOrder = 0');
mtucAud007F01_assert(Phase7TestHarness::countOrderPosts($transportOk) === $cpOk, 'replay: additional CP = 0');
mtucAud007F01_assert(
    Phase9TestHarness::smartUcfCallCount($stackOk['smartUcfProbe']) === $smartOk,
    'replay: additional SmartUCF = 0'
);

$inputFresh = Phase9TestHarness::rebindProductApplicationToken($inputOkReplay);
$opFresh = MtUniCreditStorefrontApplicationToken::bindKey($selOk, (string) $inputFresh['application_token']);
mtucAud007F01_assert(
    (string) $inputFresh['application_token'] !== (string) $inputOk['application_token'],
    'fresh token: distinct from T1'
);
mtucAud007F01_assert($opFresh !== $opOk, 'fresh token: distinct operation identity');

$transportCartOk = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportCartOk);
$stackCartOk = Phase9TestHarness::stack($transportCartOk, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$orderCartOk = 70202;
$addCartOk = 0;
$inputCartOk = Phase9TestHarness::cartStorefrontInput($stackCartOk, $orderCartOk);
$inputCartOk['add_order'] = function ($orderData) use (&$addCartOk, $stackCartOk, $orderCartOk) {
    $addCartOk++;
    $stackCartOk['memoryDb']->seedOrder($orderCartOk, $stackCartOk['storeId'], MtUniCreditConstants::EXTENSION_CODE);

    return $orderCartOk;
};
$firstCartOk = $stackCartOk['storefront']->submit($inputCartOk);
mtucAud007F01_assert(!empty($firstCartOk['success']), 'issued same-selection Cart: accepted');
$inputCartReplay = $inputCartOk;
$inputCartReplay['session'] = isset($firstCartOk['session']) ? $firstCartOk['session'] : array();
Phase9TestHarness::enqueueCpCreateSuccess($transportCartOk);
$secondCartOk = $stackCartOk['storefront']->submit($inputCartReplay);
mtucAud007F01_assert(!empty($secondCartOk['success']), 'Cart same-token replay: accepted');
mtucAud007F01_assert($addCartOk === 1, 'Cart replay: additional addOrder = 0');

// ---------------------------------------------------------------------------
// Alternate-route after ambiguity: Product T1 cannot authorize Cart
// ---------------------------------------------------------------------------
$transportAmb = new Phase4FakeCpHttpTransport();
$payloadsAmb = Phase7TestHarness::loginAndOrderSuccessPayloads();
$transportAmb->enqueueJson(200, $payloadsAmb['login']);
$transportAmb->enqueueTimeout();
$stackAmb = Phase9TestHarness::stack($transportAmb, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$orderAmb = 70301;
$addAmb = 0;
$inputAmb = Phase9TestHarness::productStorefrontInput($stackAmb, $orderAmb);
$inputAmb['add_order'] = function ($orderData) use (&$addAmb, $stackAmb, $orderAmb) {
    $addAmb++;
    $stackAmb['memoryDb']->seedOrder($orderAmb, $stackAmb['storeId'], MtUniCreditConstants::EXTENSION_CODE);

    return $orderAmb;
};
$resAmb = $stackAmb['storefront']->submit($inputAmb);
mtucAud007F01_assert(mtucAud007F01_rejected($resAmb), 'Product ambiguity: submit fails closed');
mtucAud007F01_assert($addAmb === 1, 'Product ambiguity: local order created once');

$inputAmbCart = Phase9TestHarness::cartStorefrontInput($stackAmb, 70302);
$addAmbCart = 0;
$inputAmbCart['application_token'] = $inputAmb['application_token'];
$inputAmbCart['session'] = isset($resAmb['session']) ? $resAmb['session'] : $inputAmb['session'];
$inputAmbCart['add_order'] = function () use (&$addAmbCart) {
    $addAmbCart++;

    return 70302;
};
$cpBeforeAlt = Phase7TestHarness::countOrderPosts($transportAmb);
$smartBeforeAlt = Phase9TestHarness::smartUcfCallCount($stackAmb['smartUcfProbe']);
$resAmbCart = $stackAmb['storefront']->submit($inputAmbCart);
mtucAud007F01_assert(mtucAud007F01_rejected($resAmbCart), 'ambiguous Product T1 → Cart: rejected');
mtucAud007F01_assert($addAmbCart === 0, 'ambiguous alternate-route: addOrder = 0');
mtucAud007F01_assert(
    Phase7TestHarness::countOrderPosts($transportAmb) === $cpBeforeAlt,
    'ambiguous alternate-route: CP create = 0 additional'
);
mtucAud007F01_assert(
    Phase9TestHarness::smartUcfCallCount($stackAmb['smartUcfProbe']) === $smartBeforeAlt,
    'ambiguous alternate-route: SmartUCF = 0 additional'
);

// Same-route ambiguity replay remains same operation / still blocked.
$inputAmbReplay = $inputAmb;
$inputAmbReplay['session'] = isset($resAmb['session']) ? $resAmb['session'] : $inputAmb['session'];
$resAmbReplay = $stackAmb['storefront']->submit($inputAmbReplay);
mtucAud007F01_assert(mtucAud007F01_rejected($resAmbReplay), 'same-route ambiguity replay: still blocked');
mtucAud007F01_assert($addAmb === 1, 'same-route ambiguity replay: no additional addOrder');
mtucAud007F01_assert(
    Phase7TestHarness::countOrderPosts($transportAmb) === $cpBeforeAlt,
    'same-route ambiguity replay: no additional CP'
);

// Reverse: Cart ambiguous token cannot authorize Product.
$transportAmbC = new Phase4FakeCpHttpTransport();
$transportAmbC->enqueueJson(200, $payloadsAmb['login']);
$transportAmbC->enqueueTimeout();
$stackAmbC = Phase9TestHarness::stack($transportAmbC, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$orderAmbC = 70303;
$addAmbC = 0;
$inputAmbC = Phase9TestHarness::cartStorefrontInput($stackAmbC, $orderAmbC);
$inputAmbC['add_order'] = function ($orderData) use (&$addAmbC, $stackAmbC, $orderAmbC) {
    $addAmbC++;
    $stackAmbC['memoryDb']->seedOrder($orderAmbC, $stackAmbC['storeId'], MtUniCreditConstants::EXTENSION_CODE);

    return $orderAmbC;
};
$resAmbC = $stackAmbC['storefront']->submit($inputAmbC);
mtucAud007F01_assert(mtucAud007F01_rejected($resAmbC), 'Cart ambiguity: submit fails closed');

$inputAmbCProduct = Phase9TestHarness::productStorefrontInput($stackAmbC, 70304);
$addAmbCProduct = 0;
$inputAmbCProduct['application_token'] = $inputAmbC['application_token'];
$inputAmbCProduct['session'] = isset($resAmbC['session']) ? $resAmbC['session'] : $inputAmbC['session'];
$inputAmbCProduct['add_order'] = function () use (&$addAmbCProduct) {
    $addAmbCProduct++;

    return 70304;
};
$resAmbCProduct = $stackAmbC['storefront']->submit($inputAmbCProduct);
mtucAud007F01_assert(mtucAud007F01_rejected($resAmbCProduct), 'ambiguous Cart T1 → Product: rejected');
mtucAud007F01_assert($addAmbCProduct === 0, 'ambiguous Cart→Product: addOrder = 0');

// issueForSelection reuses when binding unchanged.
$sessionReuse = array();
$t1 = MtUniCreditStorefrontApplicationToken::issue(
    $sessionReuse,
    1,
    MtUniCreditOperationEntryPoint::PRODUCT,
    $selEmpty
);
$tReuse = MtUniCreditStorefrontApplicationToken::issueForSelection(
    $sessionReuse,
    1,
    MtUniCreditOperationEntryPoint::PRODUCT,
    $selEmpty,
    $t1
);
mtucAud007F01_assert($tReuse === $t1, 'issueForSelection: unchanged binding reuses token');
$selOther = MtUniCreditStorefrontOperationIdentity::productHash(1, 42, array(), 2, 'BGN');
$t2 = MtUniCreditStorefrontApplicationToken::issueForSelection(
    $sessionReuse,
    1,
    MtUniCreditOperationEntryPoint::PRODUCT,
    $selOther,
    $t1
);
mtucAud007F01_assert($t2 !== $t1, 'issueForSelection: changed selection issues fresh token');

if ($failures !== array()) {
    fwrite(STDERR, 'AUD-007 F01: FAIL (' . count($failures) . ')' . PHP_EOL);
    foreach ($failures as $failure) {
        fwrite(STDERR, ' - ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo PHP_EOL . 'AUD-007 F01: PASS (' . $passes . ' passes)' . PHP_EOL;
