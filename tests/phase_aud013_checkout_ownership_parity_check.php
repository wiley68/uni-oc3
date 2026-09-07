<?php

/**
 * AUD-013 F01–F04 — Checkout native order actor ownership + full cart/currency parity.
 * Run: php tests/phase_aud013_checkout_ownership_parity_check.php
 *
 * PHP 7.3 compatible. Offline. Production ownership/parity paths only.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/support/phase2_memory_db.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DIR_STORAGE')) {
    mtuc_test_define_dir_storage('mtuc-aud013');
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
}
if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'oc_');
}

require_once $lib . DIRECTORY_SEPARATOR . 'bootstrap.php';
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
function mtucAud013_assert($condition, $message)
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
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function mtucAud013_prepareInput(array $overrides = array())
{
    $order = Phase5TestHarness::orderRow(42, Phase5TestHarness::STORE_A);
    $base = array(
        'payment_code' => MtUniCreditConstants::EXTENSION_CODE,
        'order_id' => 42,
        'prepared_order_id' => 0,
        'order' => $order,
        'order_products' => array(array('order_product_id' => 1, 'product_id' => 1, 'quantity' => 1)),
        'cart_products' => Phase5TestHarness::cartProducts(),
        'get_order_options' => function () {
            return array();
        },
        'checkout_grand_total' => 500.0,
        'currency_code' => 'BGN',
        'currency_value' => 1.0,
        'actor' => Phase5TestHarness::guestActor(),
        'store_id' => Phase5TestHarness::STORE_A,
        'module_enabled' => true,
        'payment_enabled' => true,
    );

    return array_merge($base, $overrides);
}

$paymentModelSrc = file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'model' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'payment'
        . DIRECTORY_SEPARATOR . 'mt_uni_credit.php'
);
$paymentCtrlSrc = file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'payment'
        . DIRECTORY_SEPARATOR . 'mt_uni_credit.php'
);
mtucAud013_assert(
    is_string($paymentModelSrc) && strpos($paymentModelSrc, 'addOrder(') === false,
    'canary: checkout payment model has no addOrder()'
);
mtucAud013_assert(
    is_string($paymentCtrlSrc) && preg_match('/->addOrder\s*\(/', (string) $paymentCtrlSrc) !== 1,
    'canary: checkout payment controller has no addOrder() call'
);

$memoryDb = new Phase2MemoryDb();
$preparation = Phase5TestHarness::confirmPreparation($memoryDb, Phase5TestHarness::STORE_A);

// ---------------------------------------------------------------------------
// F01 — logged-in ownership
// ---------------------------------------------------------------------------
$orderA = Phase5TestHarness::orderRow(42, Phase5TestHarness::STORE_A);
$orderA['customer_id'] = 10;
$orderA['email'] = 'a@example.test';
$resultOwnerMismatch = $preparation->prepare(mtucAud013_prepareInput(array(
    'order' => $orderA,
    'actor' => Phase5TestHarness::loggedInActor(99),
)));
mtucAud013_assert(
    isset($resultOwnerMismatch['error']) && $resultOwnerMismatch['error'] === 'order_ownership_mismatch',
    'F01 logged-in: customer B blocked for order owned by A'
);

$resultOwnerOk = $preparation->prepare(mtucAud013_prepareInput(array(
    'order' => $orderA,
    'actor' => Phase5TestHarness::loggedInActor(10),
)));
mtucAud013_assert(!empty($resultOwnerOk['success']), 'F01 logged-in: matching customer allowed');

// Guest → logged-in transition
$resultGuestToLogin = $preparation->prepare(mtucAud013_prepareInput(array(
    'order' => Phase5TestHarness::orderRow(42, Phase5TestHarness::STORE_A), // customer_id 0
    'actor' => Phase5TestHarness::loggedInActor(10),
)));
mtucAud013_assert(
    isset($resultGuestToLogin['error']) && $resultGuestToLogin['error'] === 'order_ownership_mismatch',
    'F01 transition: guest order blocked for logged-in customer'
);

// Logged-in → guest
$orderLogged = Phase5TestHarness::orderRow(42, Phase5TestHarness::STORE_A);
$orderLogged['customer_id'] = 10;
$resultLoginToGuest = $preparation->prepare(mtucAud013_prepareInput(array(
    'order' => $orderLogged,
    'actor' => Phase5TestHarness::guestActor('guest@example.test'),
)));
mtucAud013_assert(
    isset($resultLoginToGuest['error']) && $resultLoginToGuest['error'] === 'order_ownership_mismatch',
    'F01 transition: logged-in order blocked for guest actor'
);

// Customer A → customer B
$orderA2 = $orderLogged;
$resultAtoB = $preparation->prepare(mtucAud013_prepareInput(array(
    'order' => $orderA2,
    'actor' => Phase5TestHarness::loggedInActor(11),
)));
mtucAud013_assert(
    isset($resultAtoB['error']) && $resultAtoB['error'] === 'order_ownership_mismatch',
    'F01 transition: customer A order blocked for customer B'
);

// Guest identity mismatch
$resultGuestMismatch = $preparation->prepare(mtucAud013_prepareInput(array(
    'actor' => Phase5TestHarness::guestActor('other-guest@example.test'),
)));
mtucAud013_assert(
    isset($resultGuestMismatch['error']) && $resultGuestMismatch['error'] === 'order_ownership_mismatch',
    'F01 guest: email mismatch blocked'
);

$resultGuestOk = $preparation->prepare(mtucAud013_prepareInput());
mtucAud013_assert(!empty($resultGuestOk['success']), 'F01 guest: matching email allowed');

$resultGuestEmpty = $preparation->prepare(mtucAud013_prepareInput(array(
    'actor' => Phase5TestHarness::guestActor(''),
)));
mtucAud013_assert(
    isset($resultGuestEmpty['error']) && $resultGuestEmpty['error'] === 'order_ownership_mismatch',
    'F01 guest: missing session guest email fails closed'
);

// ---------------------------------------------------------------------------
// F03 — free-text / enumerated option parity (unit via production parity class)
// ---------------------------------------------------------------------------
$getTextOptions = function ($orderId, $orderProductId) {
    return array(
        array(
            'product_option_id' => 7,
            'product_option_value_id' => 0,
            'type' => 'text',
            'value' => 'Engrave A',
            'name' => 'Text',
        ),
    );
};
$cartTextA = array(
    array(
        'product_id' => 1,
        'quantity' => 1,
        'option' => array(
            array(
                'product_option_id' => 7,
                'product_option_value_id' => 0,
                'type' => 'text',
                'value' => 'Engrave A',
            ),
        ),
    ),
);
$cartTextB = array(
    array(
        'product_id' => 1,
        'quantity' => 1,
        'option' => array(
            array(
                'product_option_id' => 7,
                'product_option_value_id' => 0,
                'type' => 'text',
                'value' => 'Engrave B',
            ),
        ),
    ),
);
$orderParity = Phase5TestHarness::orderRow(42, Phase5TestHarness::STORE_A);
$orderProductsParity = array(array('order_product_id' => 1, 'product_id' => 1, 'quantity' => 1));
mtucAud013_assert(
    MtUniCreditCheckoutOrderCartParity::matchesCurrentCart(
        $orderParity,
        $orderProductsParity,
        $getTextOptions,
        $cartTextA,
        500.0,
        'BGN',
        1.0
    ),
    'F03: matching free-text option passes parity'
);
mtucAud013_assert(
    !MtUniCreditCheckoutOrderCartParity::matchesCurrentCart(
        $orderParity,
        $orderProductsParity,
        $getTextOptions,
        $cartTextB,
        500.0,
        'BGN',
        1.0
    ),
    'F03: different free-text option fails parity'
);

$getEnumOptions = function () {
    return array(
        array(
            'product_option_id' => 3,
            'product_option_value_id' => 55,
            'type' => 'select',
            'value' => 'Red',
            'name' => 'Color',
        ),
    );
};
$cartEnum = array(
    array(
        'product_id' => 1,
        'quantity' => 1,
        'option' => array(
            array(
                'product_option_id' => 3,
                'product_option_value_id' => 55,
                'type' => 'select',
                'value' => 'Red',
            ),
        ),
    ),
);
$cartEnumOther = array(
    array(
        'product_id' => 1,
        'quantity' => 1,
        'option' => array(
            array(
                'product_option_id' => 3,
                'product_option_value_id' => 56,
                'type' => 'select',
                'value' => 'Blue',
            ),
        ),
    ),
);
mtucAud013_assert(
    MtUniCreditCheckoutOrderCartParity::matchesCurrentCart(
        $orderParity,
        $orderProductsParity,
        $getEnumOptions,
        $cartEnum,
        500.0,
        'BGN',
        1.0
    ),
    'F03: matching enumerated option passes'
);
mtucAud013_assert(
    !MtUniCreditCheckoutOrderCartParity::matchesCurrentCart(
        $orderParity,
        $orderProductsParity,
        $getEnumOptions,
        $cartEnumOther,
        500.0,
        'BGN',
        1.0
    ),
    'F03: different enumerated option fails'
);

$resultTextPrepare = $preparation->prepare(mtucAud013_prepareInput(array(
    'get_order_options' => $getTextOptions,
    'cart_products' => $cartTextB,
)));
mtucAud013_assert(
    isset($resultTextPrepare['error']) && $resultTextPrepare['error'] === 'order_changed',
    'F03 prepare: free-text mismatch rejected'
);

// ---------------------------------------------------------------------------
// F04 — currency parity
// ---------------------------------------------------------------------------
$resultCurrencyCode = $preparation->prepare(mtucAud013_prepareInput(array(
    'currency_code' => 'EUR',
    'currency_value' => 1.0,
)));
mtucAud013_assert(
    isset($resultCurrencyCode['error']) && $resultCurrencyCode['error'] === 'order_changed',
    'F04 prepare: currency_code mismatch rejected'
);

$resultCurrencyValue = $preparation->prepare(mtucAud013_prepareInput(array(
    'currency_code' => 'BGN',
    'currency_value' => 1.9558,
)));
mtucAud013_assert(
    isset($resultCurrencyValue['error']) && $resultCurrencyValue['error'] === 'order_changed',
    'F04 prepare: currency_value mismatch rejected'
);

// ---------------------------------------------------------------------------
// F02 — final submission full structural parity (equal total, changed cart)
// ---------------------------------------------------------------------------
$transport = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transport);
$stack = Phase9TestHarness::stack($transport, null, null, Phase5TestHarness::STORE_A, array('uni_proces' => 1));
$orderId = 30101;
Phase9TestHarness::seedBankOrder($stack['memoryDb'], $orderId, $stack['storeId']);

$inputOk = Phase9TestHarness::submitInputProcess2($orderId, $stack['storeId']);
$resultSubmitOk = $stack['submission']->submit($inputOk);
mtucAud013_assert(!empty($resultSubmitOk['success']), 'F02 baseline: matching cart submit succeeds');
mtucAud013_assert(Phase7TestHarness::countOrderPosts($transport) === 1, 'F02 baseline: CP create = 1');
mtucAud013_assert(Phase9TestHarness::smartUcfCallCount($stack['smartUcfProbe']) === 0, 'F02 baseline: SmartUCF = 0');

// Product changed, equal total
$transportProd = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportProd);
$stackProd = Phase9TestHarness::stack($transportProd);
$orderProd = 30102;
Phase9TestHarness::seedBankOrder($stackProd['memoryDb'], $orderProd, $stackProd['storeId']);
$inputProd = Phase9TestHarness::submitInput($orderProd, $stackProd['storeId']);
$inputProd['cart_products'] = array(
    array('product_id' => 99, 'quantity' => 1, 'price' => 500.0, 'tax_class_id' => 0, 'option' => array()),
);
$inputProd['cart_context'] = (new MtUniCreditOc3CartContextFactory(function () {
    return array(7);
}))->create($inputProd['cart_products'], 500.0);
$resultProd = $stackProd['submission']->submit($inputProd);
mtucAud013_assert(empty($resultProd['success']), 'F02 submit: product change blocked');
mtucAud013_assert(
    isset($resultProd['error']) && $resultProd['error'] === 'order_changed',
    'F02 submit: product change error=order_changed'
);
mtucAud013_assert(Phase7TestHarness::countOrderPosts($transportProd) === 0, 'F02 product: CP create = 0');
mtucAud013_assert(Phase9TestHarness::smartUcfCallCount($stackProd['smartUcfProbe']) === 0, 'F02 product: SmartUCF = 0');

// Quantity changed, equal total (two lines totaling same — use qty 2 of cheaper conceptually: same product qty 2 with adjusted cart total still 500 via grand total)
$transportQty = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportQty);
$stackQty = Phase9TestHarness::stack($transportQty);
$orderQty = 30103;
Phase9TestHarness::seedBankOrder($stackQty['memoryDb'], $orderQty, $stackQty['storeId']);
$inputQty = Phase9TestHarness::submitInput($orderQty, $stackQty['storeId']);
$inputQty['cart_products'] = array(
    array('product_id' => 42, 'quantity' => 2, 'price' => 250.0, 'tax_class_id' => 0, 'option' => array()),
);
$inputQty['cart_context'] = (new MtUniCreditOc3CartContextFactory(function () {
    return array(7);
}))->create($inputQty['cart_products'], 500.0);
$resultQty = $stackQty['submission']->submit($inputQty);
mtucAud013_assert(empty($resultQty['success']), 'F02 submit: quantity change blocked');
mtucAud013_assert(Phase7TestHarness::countOrderPosts($transportQty) === 0, 'F02 quantity: CP create = 0');
mtucAud013_assert(Phase9TestHarness::smartUcfCallCount($stackQty['smartUcfProbe']) === 0, 'F02 quantity: SmartUCF = 0');

// Option changed, equal total
$transportOpt = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportOpt);
$stackOpt = Phase9TestHarness::stack($transportOpt);
$orderOpt = 30104;
Phase9TestHarness::seedBankOrder($stackOpt['memoryDb'], $orderOpt, $stackOpt['storeId']);
$inputOpt = Phase9TestHarness::submitInput($orderOpt, $stackOpt['storeId']);
$inputOpt['order_products'] = array(
    array('order_product_id' => 1, 'product_id' => 42, 'quantity' => 1, 'price' => 500.0, 'total' => 500.0),
);
$inputOpt['get_order_options'] = function () {
    return array(
        array(
            'product_option_id' => 7,
            'product_option_value_id' => 0,
            'type' => 'text',
            'value' => 'Original',
        ),
    );
};
$inputOpt['cart_products'] = array(
    array(
        'product_id' => 42,
        'quantity' => 1,
        'price' => 500.0,
        'tax_class_id' => 0,
        'option' => array(
            array(
                'product_option_id' => 7,
                'product_option_value_id' => 0,
                'type' => 'text',
                'value' => 'Changed',
            ),
        ),
    ),
);
$inputOpt['cart_context'] = (new MtUniCreditOc3CartContextFactory(function () {
    return array(7);
}))->create($inputOpt['cart_products'], 500.0);
$resultOpt = $stackOpt['submission']->submit($inputOpt);
mtucAud013_assert(empty($resultOpt['success']), 'F02 submit: option change blocked');
mtucAud013_assert(Phase7TestHarness::countOrderPosts($transportOpt) === 0, 'F02 option: CP create = 0');
mtucAud013_assert(Phase9TestHarness::smartUcfCallCount($stackOpt['smartUcfProbe']) === 0, 'F02 option: SmartUCF = 0');

// F04 final submit currency switch
$transportCur = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportCur);
$stackCur = Phase9TestHarness::stack($transportCur);
$orderCur = 30105;
Phase9TestHarness::seedBankOrder($stackCur['memoryDb'], $orderCur, $stackCur['storeId']);
$inputCur = Phase9TestHarness::submitInput($orderCur, $stackCur['storeId']);
$inputCur['currency_code'] = 'EUR';
$inputCur['currency_value'] = 1.0;
$resultCur = $stackCur['submission']->submit($inputCur);
mtucAud013_assert(empty($resultCur['success']), 'F04 submit: currency_code change blocked');
mtucAud013_assert(Phase7TestHarness::countOrderPosts($transportCur) === 0, 'F04 submit: CP create = 0');
mtucAud013_assert(Phase9TestHarness::smartUcfCallCount($stackCur['smartUcfProbe']) === 0, 'F04 submit: SmartUCF = 0');

$transportCurVal = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportCurVal);
$stackCurVal = Phase9TestHarness::stack($transportCurVal);
$orderCurVal = 30106;
Phase9TestHarness::seedBankOrder($stackCurVal['memoryDb'], $orderCurVal, $stackCurVal['storeId']);
$inputCurVal = Phase9TestHarness::submitInput($orderCurVal, $stackCurVal['storeId']);
$inputCurVal['currency_value'] = 1.9558;
$resultCurVal = $stackCurVal['submission']->submit($inputCurVal);
mtucAud013_assert(empty($resultCurVal['success']), 'F04 submit: currency_value change blocked');
mtucAud013_assert(Phase7TestHarness::countOrderPosts($transportCurVal) === 0, 'F04 currency_value: CP create = 0');

// F01 ownership on final submit + zero side effects
$transportOwn = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transportOwn);
$stackOwn = Phase9TestHarness::stack($transportOwn);
$orderOwn = 30107;
Phase9TestHarness::seedBankOrder($stackOwn['memoryDb'], $orderOwn, $stackOwn['storeId']);
$inputOwn = Phase9TestHarness::submitInput($orderOwn, $stackOwn['storeId']);
$inputOwn['order']['customer_id'] = 10;
$inputOwn['actor'] = Phase5TestHarness::loggedInActor(99);
$resultOwn = $stackOwn['submission']->submit($inputOwn);
mtucAud013_assert(empty($resultOwn['success']), 'F01 submit: foreign customer blocked');
mtucAud013_assert(
    isset($resultOwn['error']) && $resultOwn['error'] === 'order_ownership_mismatch',
    'F01 submit: ownership error code'
);
mtucAud013_assert(Phase7TestHarness::countOrderPosts($transportOwn) === 0, 'F01 submit: CP create = 0');
mtucAud013_assert(Phase9TestHarness::smartUcfCallCount($stackOwn['smartUcfProbe']) === 0, 'F01 submit: SmartUCF = 0');

// Posted order_id must not become authority — session/model always pass session order_id;
// assert production model does not read request order_id.
mtucAud013_assert(
    is_string($paymentModelSrc)
        && strpos($paymentModelSrc, "session->data['order_id']") !== false
        && !preg_match('/request->(get|post).*order_id/i', (string) $paymentModelSrc),
    'posted-ID: model uses session.order_id, not request order_id'
);

// PHP 7.3 surface
$changed = array(
    'checkout_order_actor_ownership.php',
    'checkout_order_cart_parity.php',
    'checkout_confirm_preparation.php',
    'checkout_financing_submission_service.php',
);
$forbiddenTokens = array('str_contains', 'str_starts_with', '?' . '->', '#' . '[', 'fn' . '(');
foreach ($changed as $file) {
    $src = file_get_contents($lib . DIRECTORY_SEPARATOR . $file);
    mtucAud013_assert(is_string($src) && $src !== '', 'readable: ' . $file);
    foreach ($forbiddenTokens as $forbidden) {
        mtucAud013_assert(strpos((string) $src, $forbidden) === false, 'PHP 7.3 free of ' . $forbidden . ': ' . $file);
    }
}

echo PHP_EOL . 'AUD-013 checks: ' . $passes . ' passed';
if ($failures) {
    echo ', ' . count($failures) . ' failed' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    exit(1);
}
echo ', 0 failed' . PHP_EOL;
exit(0);
