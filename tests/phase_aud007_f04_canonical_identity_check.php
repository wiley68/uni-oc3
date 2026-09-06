<?php

/**
 * AUD-007 F04 — canonical Product/Cart selection identity.
 * Run: php tests/phase_aud007_f04_canonical_identity_check.php
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
function mtucAud007F04_assert($condition, $message)
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

mtucAud007F04_assert(mtuc_phase0_network_isolation_active(), 'isolation: offline guard active');

// ---------------------------------------------------------------------------
// Product: multi-value checkbox reorder stability
// ---------------------------------------------------------------------------
$optsA = array(
    array(
        'product_option_id' => 5,
        'product_option_value_id' => 11,
        'name' => 'Color',
        'value' => 'Red',
        'type' => 'checkbox',
    ),
    array(
        'product_option_id' => 5,
        'product_option_value_id' => 21,
        'name' => 'Color',
        'value' => 'Blue',
        'type' => 'checkbox',
    ),
);
$optsB = array($optsA[1], $optsA[0]);
$productPayloadA = MtUniCreditStorefrontOperationIdentity::productPayload(1, 42, $optsA, 1, 'BGN');
$productPayloadB = MtUniCreditStorefrontOperationIdentity::productPayload(1, 42, $optsB, 1, 'BGN');
$productJsonA = MtUniCreditStorefrontOperationIdentity::encodeJson($productPayloadA);
$productJsonB = MtUniCreditStorefrontOperationIdentity::encodeJson($productPayloadB);
$hashA = MtUniCreditStorefrontOperationIdentity::productHash(1, 42, $optsA, 1, 'BGN');
$hashB = MtUniCreditStorefrontOperationIdentity::productHash(1, 42, $optsB, 1, 'BGN');
mtucAud007F04_assert($productJsonA === $productJsonB, 'product: checkbox reorder → identical JSON');
mtucAud007F04_assert($hashA === $hashB, 'product: checkbox reorder → identical selection hash');
mtucAud007F04_assert(strlen($hashA) === 64 && ctype_xdigit($hashA) && $hashA === strtolower($hashA), 'product: hash is lowercase sha256 hex');

$expectedCanonicalOptions = array(
    array('option_id' => 5, 'value_id' => 11, 'value' => null),
    array('option_id' => 5, 'value_id' => 21, 'value' => null),
);
mtucAud007F04_assert(
    $productPayloadA['options'] === $expectedCanonicalOptions,
    'product: multi-value options fully preserved + sorted'
);
echo 'VECTOR product checkbox preimage: ' . $productJsonA . PHP_EOL;
echo 'VECTOR product checkbox sha256: ' . $hashA . PHP_EOL;

// ---------------------------------------------------------------------------
// Product: distinct option differentiation
// ---------------------------------------------------------------------------
$optsSingle = array(
    array(
        'product_option_id' => 5,
        'product_option_value_id' => 11,
        'value' => 'Red',
        'type' => 'checkbox',
    ),
);
$optsOther = array(
    array(
        'product_option_id' => 5,
        'product_option_value_id' => 21,
        'value' => 'Blue',
        'type' => 'checkbox',
    ),
);
mtucAud007F04_assert(
    MtUniCreditStorefrontOperationIdentity::productHash(1, 42, $optsSingle, 1, 'BGN')
        !== MtUniCreditStorefrontOperationIdentity::productHash(1, 42, $optsOther, 1, 'BGN'),
    'product: different checkbox set → different hash'
);
mtucAud007F04_assert(
    MtUniCreditStorefrontOperationIdentity::productHash(1, 42, $optsSingle, 1, 'BGN')
        !== MtUniCreditStorefrontOperationIdentity::productHash(1, 42, $optsA, 1, 'BGN'),
    'product: incomplete multi-value ≠ full multi-value'
);

$textA = array(
    array(
        'product_option_id' => 9,
        'product_option_value_id' => '',
        'value' => 'engraving-A',
        'type' => 'text',
    ),
);
$textB = array(
    array(
        'product_option_id' => 9,
        'product_option_value_id' => '',
        'value' => 'engraving-B',
        'type' => 'text',
    ),
);
mtucAud007F04_assert(
    MtUniCreditStorefrontOperationIdentity::productHash(1, 42, $textA, 1, 'BGN')
        !== MtUniCreditStorefrontOperationIdentity::productHash(1, 42, $textB, 1, 'BGN'),
    'product: different free-text → different hash'
);
mtucAud007F04_assert(
    MtUniCreditStorefrontOperationIdentity::productHash(1, 42, $textA, 1, 'BGN')
        === MtUniCreditStorefrontOperationIdentity::productHash(
            1,
            42,
            array(9 => 'engraving-A'),
            1,
            'BGN'
        ),
    'product: free-text row matches legacy map form'
);

// Empty options remain structurally stable (no options key shape change for empty list).
$emptyHash = MtUniCreditStorefrontOperationIdentity::productHash(1, 42, array(), 1, 'BGN');
$emptyExpected = hash(
    'sha256',
    json_encode(
        array(
            'store_id' => 1,
            'product_id' => 42,
            'options' => array(),
            'quantity' => 1,
            'currency' => 'BGN',
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    )
);
mtucAud007F04_assert($emptyHash === $emptyExpected, 'product: empty options hash matches prior empty payload');

// ---------------------------------------------------------------------------
// Cart: line reorder + option differentiation + decimal stability
// ---------------------------------------------------------------------------
$cartLinesA = array(
    array(
        'product_id' => 10,
        'quantity' => 1,
        'total' => 100.1,
        'options' => array(
            array('product_option_id' => 3, 'product_option_value_id' => 30, 'value' => 'S'),
        ),
    ),
    array(
        'product_id' => 10,
        'quantity' => 1,
        'total' => 500.1,
        'options' => array(
            array('product_option_id' => 3, 'product_option_value_id' => 31, 'value' => 'L'),
        ),
    ),
);
$cartLinesB = array($cartLinesA[1], $cartLinesA[0]);
$cartPayloadA = MtUniCreditStorefrontOperationIdentity::cartFingerprintPayload($cartLinesA, 600.2, 'BGN');
$cartPayloadB = MtUniCreditStorefrontOperationIdentity::cartFingerprintPayload($cartLinesB, 600.2, 'BGN');
$cartJsonA = MtUniCreditStorefrontOperationIdentity::encodeJson($cartPayloadA);
$cartJsonB = MtUniCreditStorefrontOperationIdentity::encodeJson($cartPayloadB);
$fpA = MtUniCreditStorefrontOperationIdentity::cartFingerprint($cartLinesA, 600.2, 'BGN');
$fpB = MtUniCreditStorefrontOperationIdentity::cartFingerprint($cartLinesB, 600.2, 'BGN');
mtucAud007F04_assert($cartJsonA === $cartJsonB, 'cart: line reorder → identical JSON');
mtucAud007F04_assert($fpA === $fpB, 'cart: line reorder → identical fingerprint');
mtucAud007F04_assert(
    $cartPayloadA['lines'][0]['total'] === '100.1000'
        && $cartPayloadA['lines'][1]['total'] === '500.1000'
        && $cartPayloadA['total'] === '600.20',
    'cart: fixed decimal strings (4/2)'
);
echo 'VECTOR cart reorder preimage: ' . $cartJsonA . PHP_EOL;
echo 'VECTOR cart reorder sha256: ' . $fpA . PHP_EOL;

$samePriceDifferentOptions = array(
    array(
        'product_id' => 10,
        'quantity' => 1,
        'total' => 100.0,
        'options' => array(
            array('product_option_id' => 3, 'product_option_value_id' => 30, 'value' => 'S'),
        ),
    ),
);
$samePriceOtherOptions = array(
    array(
        'product_id' => 10,
        'quantity' => 1,
        'total' => 100.0,
        'options' => array(
            array('product_option_id' => 3, 'product_option_value_id' => 31, 'value' => 'L'),
        ),
    ),
);
mtucAud007F04_assert(
    MtUniCreditStorefrontOperationIdentity::cartFingerprint($samePriceDifferentOptions, 100.0, 'BGN')
        !== MtUniCreditStorefrontOperationIdentity::cartFingerprint($samePriceOtherOptions, 100.0, 'BGN'),
    'cart: same product/qty/total, different options → different fingerprint'
);

$sameOptionsDifferentTotal = array(
    array(
        'product_id' => 10,
        'quantity' => 1,
        'total' => 100.0,
        'options' => array(
            array('product_option_id' => 3, 'product_option_value_id' => 30, 'value' => 'S'),
        ),
    ),
);
$sameOptionsHigherTotal = array(
    array(
        'product_id' => 10,
        'quantity' => 1,
        'total' => 120.0,
        'options' => array(
            array('product_option_id' => 3, 'product_option_value_id' => 30, 'value' => 'S'),
        ),
    ),
);
mtucAud007F04_assert(
    MtUniCreditStorefrontOperationIdentity::cartFingerprint($sameOptionsDifferentTotal, 100.0, 'BGN')
        !== MtUniCreditStorefrontOperationIdentity::cartFingerprint($sameOptionsHigherTotal, 120.0, 'BGN'),
    'cart: same options, different material total → different fingerprint'
);

$prevPrecision = ini_get('serialize_precision');
ini_set('serialize_precision', '-1');
$fpPrecisionNeg1 = MtUniCreditStorefrontOperationIdentity::cartFingerprint($cartLinesA, 600.2, 'BGN');
ini_set('serialize_precision', '17');
$fpPrecision17 = MtUniCreditStorefrontOperationIdentity::cartFingerprint($cartLinesA, 600.2, 'BGN');
if ($prevPrecision === false) {
    ini_restore('serialize_precision');
} else {
    ini_set('serialize_precision', (string) $prevPrecision);
}
mtucAud007F04_assert(
    $fpPrecisionNeg1 === $fpPrecision17 && $fpPrecisionNeg1 === $fpA,
    'cart: serialize_precision -1/17 → identical fingerprint'
);

// Context path includes CartLine options.
$contextCart = new MtUniCreditCartContext(
    array(
        new MtUniCreditCartLine(
            new MtUniCreditProductContext(10, array(1), 100.1),
            30,
            1,
            100.1,
            array(30),
            array(
                array('product_option_id' => 3, 'product_option_value_id' => 30, 'value' => 'S'),
            )
        ),
        new MtUniCreditCartLine(
            new MtUniCreditProductContext(10, array(1), 500.1),
            31,
            1,
            500.1,
            array(31),
            array(
                array('product_option_id' => 3, 'product_option_value_id' => 31, 'value' => 'L'),
            )
        ),
    ),
    600.2
);
$contextCartReversed = new MtUniCreditCartContext(
    array(
        $contextCart->lines[1],
        $contextCart->lines[0],
    ),
    600.2
);
mtucAud007F04_assert(
    MtUniCreditStorefrontOperationIdentity::cartFingerprintFromContext($contextCart, 'BGN')
        === MtUniCreditStorefrontOperationIdentity::cartFingerprintFromContext($contextCartReversed, 'BGN'),
    'cart context: line reorder → identical fingerprint'
);
mtucAud007F04_assert(
    MtUniCreditStorefrontOperationIdentity::cartFingerprintFromContext($contextCart, 'BGN') === $fpA,
    'cart context matches raw line fingerprint vector'
);

// ---------------------------------------------------------------------------
// Equivalent Product replay (checkbox reorder) — no second workflow
// ---------------------------------------------------------------------------
$transportProduct = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportProduct);
$stackProduct = Phase9TestHarness::stack($transportProduct, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$orderProduct = 70401;
$addProduct = 0;
$inputProduct = Phase9TestHarness::productStorefrontInput($stackProduct, $orderProduct);
$inputProduct['product_line'] = new MtUniCreditProductLine(
    42,
    'Example',
    'EX',
    array(7),
    1,
    500.0,
    500.0,
    500.0,
    0,
    $optsA,
    0
);
$inputProduct = Phase9TestHarness::rebindProductApplicationToken($inputProduct);
$inputProduct['add_order'] = function ($orderData) use (&$addProduct, $stackProduct, $orderProduct) {
    $addProduct++;
    $stackProduct['memoryDb']->seedOrder($orderProduct, $stackProduct['storeId'], MtUniCreditConstants::EXTENSION_CODE);

    return $orderProduct;
};
$firstProduct = $stackProduct['storefront']->submit($inputProduct);
mtucAud007F04_assert(!empty($firstProduct['success']), 'product replay: first submit success');
mtucAud007F04_assert($addProduct === 1, 'product replay: first addOrder = 1');
$cpAfterFirst = Phase7TestHarness::countOrderPosts($transportProduct);
$smartAfterFirst = Phase9TestHarness::smartUcfCallCount($stackProduct['smartUcfProbe']);

$inputProductReplay = $inputProduct;
$inputProductReplay['product_line'] = new MtUniCreditProductLine(
    42,
    'Example',
    'EX',
    array(7),
    1,
    500.0,
    500.0,
    500.0,
    0,
    $optsB,
    0
);
$inputProductReplay['session'] = isset($firstProduct['session']) ? $firstProduct['session'] : array();
Phase9TestHarness::enqueueCpCreateSuccess($transportProduct);
$secondProduct = $stackProduct['storefront']->submit($inputProductReplay);
mtucAud007F04_assert(!empty($secondProduct['success']), 'product replay: reorder submit success');
mtucAud007F04_assert($addProduct === 1, 'product replay: additional addOrder = 0');
mtucAud007F04_assert(
    Phase7TestHarness::countOrderPosts($transportProduct) === $cpAfterFirst,
    'product replay: additional CP create = 0'
);
mtucAud007F04_assert(
    Phase9TestHarness::smartUcfCallCount($stackProduct['smartUcfProbe']) === $smartAfterFirst,
    'product replay: additional SmartUCF = 0'
);

$selA = MtUniCreditStorefrontOperationIdentity::productHash(
    (int) $stackProduct['storeId'],
    42,
    $optsA,
    1,
    'BGN'
);
$selB = MtUniCreditStorefrontOperationIdentity::productHash(
    (int) $stackProduct['storeId'],
    42,
    $optsB,
    1,
    'BGN'
);
$opA = MtUniCreditStorefrontApplicationToken::bindKey($selA, (string) $inputProduct['application_token']);
$opB = MtUniCreditStorefrontApplicationToken::bindKey($selB, (string) $inputProduct['application_token']);
mtucAud007F04_assert($selA === $selB && $opA === $opB, 'product replay: same selection + operation hash');

// ---------------------------------------------------------------------------
// Equivalent Cart replay (line reorder) — no second workflow
// ---------------------------------------------------------------------------
$transportCart = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportCart);
$stackCart = Phase9TestHarness::stack($transportCart, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$orderCart = 70402;
$addCart = 0;
$cartA = $contextCart;
$cartB = $contextCartReversed;
$inputCart = Phase9TestHarness::cartStorefrontInput($stackCart, $orderCart, $cartA);
$inputCart['add_order'] = function ($orderData) use (&$addCart, $stackCart, $orderCart) {
    $addCart++;
    $stackCart['memoryDb']->seedOrder($orderCart, $stackCart['storeId'], MtUniCreditConstants::EXTENSION_CODE);

    return $orderCart;
};
$firstCart = $stackCart['storefront']->submit($inputCart);
mtucAud007F04_assert(!empty($firstCart['success']), 'cart replay: first submit success');
mtucAud007F04_assert($addCart === 1, 'cart replay: first addOrder = 1');
$cpCartFirst = Phase7TestHarness::countOrderPosts($transportCart);
$smartCartFirst = Phase9TestHarness::smartUcfCallCount($stackCart['smartUcfProbe']);

$inputCartReplay = Phase9TestHarness::cartStorefrontInput($stackCart, $orderCart, $cartB);
$inputCartReplay['application_token'] = $inputCart['application_token'];
$inputCartReplay['session'] = isset($firstCart['session']) ? $firstCart['session'] : array();
$inputCartReplay['add_order'] = $inputCart['add_order'];
$inputCartReplay['load_order'] = $inputCart['load_order'];
Phase9TestHarness::enqueueCpCreateSuccess($transportCart);
$secondCart = $stackCart['storefront']->submit($inputCartReplay);
mtucAud007F04_assert(!empty($secondCart['success']), 'cart replay: reorder submit success');
mtucAud007F04_assert($addCart === 1, 'cart replay: additional addOrder = 0');
mtucAud007F04_assert(
    Phase7TestHarness::countOrderPosts($transportCart) === $cpCartFirst,
    'cart replay: additional CP create = 0'
);
mtucAud007F04_assert(
    Phase9TestHarness::smartUcfCallCount($stackCart['smartUcfProbe']) === $smartCartFirst,
    'cart replay: additional SmartUCF = 0'
);

// Distinct genuine Product selection still differs and can create a separate operation.
$transportDistinct = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportDistinct);
$stackDistinct = Phase9TestHarness::stack($transportDistinct, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$orderDistinct = 70403;
$addDistinct = 0;
$inputDistinct = Phase9TestHarness::productStorefrontInput($stackDistinct, $orderDistinct);
$inputDistinct['product_line'] = new MtUniCreditProductLine(
    42,
    'Example',
    'EX',
    array(7),
    1,
    500.0,
    500.0,
    500.0,
    0,
    $optsSingle,
    0
);
$inputDistinct = Phase9TestHarness::rebindProductApplicationToken($inputDistinct);
$inputDistinct['add_order'] = function ($orderData) use (&$addDistinct, $stackDistinct, $orderDistinct) {
    $addDistinct++;
    $stackDistinct['memoryDb']->seedOrder($orderDistinct, $stackDistinct['storeId'], MtUniCreditConstants::EXTENSION_CODE);

    return $orderDistinct;
};
$distinctFirst = $stackDistinct['storefront']->submit($inputDistinct);
mtucAud007F04_assert(!empty($distinctFirst['success']), 'distinct product: first success');
$inputDistinct2 = $inputDistinct;
$inputDistinct2['product_line'] = new MtUniCreditProductLine(
    42,
    'Example',
    'EX',
    array(7),
    1,
    500.0,
    500.0,
    500.0,
    0,
    $optsOther,
    0
);
$inputDistinct2['session'] = isset($distinctFirst['session']) ? $distinctFirst['session'] : array();
$inputDistinct2 = Phase9TestHarness::rebindProductApplicationToken($inputDistinct2);
// Token already authenticated after first CP create — enqueue order-create only.
Phase9TestHarness::enqueueCpOrderCreateSuccess($transportDistinct);
$orderDistinct2 = 70404;
$inputDistinct2['add_order'] = function ($orderData) use (&$addDistinct, $stackDistinct, $orderDistinct2) {
    $addDistinct++;
    $stackDistinct['memoryDb']->seedOrder($orderDistinct2, $stackDistinct['storeId'], MtUniCreditConstants::EXTENSION_CODE);

    return $orderDistinct2;
};
$inputDistinct2['load_order'] = function ($loadedId) use ($stackDistinct, $orderDistinct, $orderDistinct2) {
    if ((int) $loadedId === (int) $orderDistinct || (int) $loadedId === (int) $orderDistinct2) {
        return Phase7TestHarness::orderRow((int) $loadedId, (int) $stackDistinct['storeId']);
    }

    return null;
};
$distinctSecond = $stackDistinct['storefront']->submit($inputDistinct2);
mtucAud007F04_assert(!empty($distinctSecond['success']), 'distinct product: different options still succeed');
mtucAud007F04_assert($addDistinct === 2, 'distinct product: different selection → second addOrder');

if ($failures !== array()) {
    fwrite(STDERR, 'AUD-007 F04: FAIL (' . count($failures) . ')' . PHP_EOL);
    foreach ($failures as $failure) {
        fwrite(STDERR, ' - ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo PHP_EOL . 'AUD-007 F04: PASS (' . $passes . ' passes)' . PHP_EOL;
