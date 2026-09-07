<?php

/**
 * AUD-010 F04 — Product/Cart applicant address authority (no store locality fabrication).
 * Run: php tests/phase_aud010_f04_address_authority_check.php
 *
 * PHP 7.3 compatible. Offline.
 *
 * Native OC3 evidence (reference-oc3-core install/opencart.sql oc_order):
 * payment_city/postcode/country/zone are varchar NOT NULL — empty string is valid.
 * payment_country_id/zone_id are int NOT NULL — 0 is valid (addOrder casts to int, no FK).
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
    mtuc_test_define_dir_storage('mtuc-aud010-f04');
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
function mtucAud010F04_assert($condition, $message)
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
 * @param string $address
 * @param int $customerId
 * @return array<string, mixed>
 */
function mtucAud010F04_customer($address, $customerId = 0)
{
    return array(
        'customer_id' => (int) $customerId,
        'customer_group_id' => 1,
        'firstname' => 'Анна',
        'lastname' => 'Иванова',
        'email' => 'anna@example.test',
        'telephone' => '0888000111',
        'address_1' => $address,
        'city' => '',
        'postcode' => '',
        'country' => '',
        'country_id' => 0,
        'zone' => '',
        'zone_id' => 0,
    );
}

/**
 * @param array<string, mixed> $customer
 * @return array<string, mixed>
 */
function mtucAud010F04_draftOrder(array $customer)
{
    return (new MtUniCreditStorefrontOrderDraftBuilder())->buildOrderData(array(
        'customer' => $customer,
        'products' => array(
            array(
                'product_id' => 7,
                'name' => 'Example',
                'quantity' => 1,
                'price' => 500.0,
                'total' => 500.0,
            ),
        ),
        'order_total' => 500.0,
        'store_id' => Phase5TestHarness::STORE_A,
        'currency_code' => 'BGN',
        'currency_id' => 1,
        'language_id' => 1,
    ));
}

/**
 * @param array<string, mixed> $order
 * @param string $label
 * @param string $expectedAddress
 * @return void
 */
function mtucAud010F04_assertCleanOrder(array $order, $label, $expectedAddress)
{
    mtucAud010F04_assert(
        $order['payment_address_1'] === $expectedAddress
            && $order['shipping_address_1'] === $expectedAddress,
        $label . ': payment/shipping address_1 exact'
    );
    mtucAud010F04_assert(
        $order['payment_city'] === ''
            && $order['shipping_city'] === '',
        $label . ': merchant city absent'
    );
    mtucAud010F04_assert(
        $order['payment_postcode'] === ''
            && $order['shipping_postcode'] === '',
        $label . ': merchant postcode absent'
    );
    mtucAud010F04_assert(
        $order['payment_zone'] === ''
            && $order['shipping_zone'] === ''
            && (int) $order['payment_zone_id'] === 0
            && (int) $order['shipping_zone_id'] === 0,
        $label . ': merchant zone absent'
    );
    mtucAud010F04_assert(
        $order['payment_country'] === ''
            && $order['shipping_country'] === ''
            && (int) $order['payment_country_id'] === 0
            && (int) $order['shipping_country_id'] === 0,
        $label . ': merchant country absent'
    );
}

$guestAddress = 'ул. България 10, Пловдив 4000';
$loggedAddress = 'ул. Морска 5, Варна 9000';
$editedAddress = 'Street 9, Varna';

// -------------------------------------------------------------------------
// Normalizer: no store-default locality fill
// -------------------------------------------------------------------------
$norm = (new MtUniCreditStorefrontPopupFormNormalizer())->normalize(
    array(
        'firstname' => 'A',
        'lastname' => 'B',
        'phone' => '0888',
        'address' => $guestAddress,
        'email' => 'a@b.test',
    ),
    array(
        'city' => 'Sofia',
        'postcode' => '1000',
        'country_id' => 33,
        'zone_id' => 1,
        'country' => 'Bulgaria',
        'zone' => 'Sofia',
    )
);
mtucAud010F04_assert($norm['address_1'] === $guestAddress, 'normalizer: submitted address_1');
mtucAud010F04_assert(
    !isset($norm['city']) && !isset($norm['postcode']) && !isset($norm['country']),
    'normalizer: no store locality keys invented'
);

// -------------------------------------------------------------------------
// Product / Cart / guest / logged-in draft orders
// -------------------------------------------------------------------------
$productOrder = mtucAud010F04_draftOrder(mtucAud010F04_customer($guestAddress, 0));
mtucAud010F04_assertCleanOrder($productOrder, 'Product guest', $guestAddress);

$cartOrder = mtucAud010F04_draftOrder(mtucAud010F04_customer($guestAddress, 0));
mtucAud010F04_assertCleanOrder($cartOrder, 'Cart guest', $guestAddress);

$loggedOrder = mtucAud010F04_draftOrder(mtucAud010F04_customer($loggedAddress, 42));
mtucAud010F04_assertCleanOrder($loggedOrder, 'logged-in', $loggedAddress);

$editedOrder = mtucAud010F04_draftOrder(mtucAud010F04_customer($editedAddress, 42));
mtucAud010F04_assertCleanOrder($editedOrder, 'edited-prefill', $editedAddress);

// -------------------------------------------------------------------------
// Snapshot / CP / SmartUCF
// -------------------------------------------------------------------------
$calc = (new MtUniCreditCalculator())->calculateScheme(
    mtuc4_valid_shop_snapshot(),
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
$orderRow = $productOrder;
$orderRow['order_id'] = 930001;
$orderRow['currency_code'] = 'BGN';
$products = Phase7TestHarness::orderProducts();
$shop = mtuc4_valid_shop_snapshot();
$snap = MtUniCreditApplicationSnapshot::fromLive(
    $calc,
    $orderRow,
    $products,
    $shop,
    MtUniCreditOperationEntryPoint::PRODUCT,
    hash('sha256', 'aud010-f04'),
    hash('sha256', 'aud010-f04-sel'),
    hash('sha256', 'aud010-f04-fp')
);
mtucAud010F04_assert(
    $snap['customer']['address'] === $guestAddress
        && strpos($snap['customer']['address'], 'Sofia') === false
        && strpos($snap['customer']['address'], '1000') === false,
    'snapshot: applicant address only'
);
mtucAud010F04_assert(
    strpos((string) $snap['customer']['address2'], 'Sofia') === false
        && strpos((string) $snap['customer']['address2'], '1000') === false,
    'snapshot address2: no merchant locality'
);

$cp = (new MtUniCreditControlPanelOrderPayloadBuilder())->build(
    930001,
    $orderRow,
    $products,
    $calc,
    $shop
);
mtucAud010F04_assert(
    $cp['address'] === $guestAddress
        && strpos($cp['address'], 'Sofia') === false
        && strpos($cp['address'], '1000') === false,
    'CP address: applicant only'
);
mtucAud010F04_assert(
    strpos((string) $cp['address2'], 'Sofia') === false
        && strpos((string) $cp['address2'], '1000') === false,
    'CP address2: no merchant locality'
);

$handoff = MtUniCreditApplicationSnapshot::resolveHandoffInputs($snap, $orderRow);
$smart = (new MtUniCreditSmartUcfPayloadBuilder())->build(
    $shop,
    $handoff['order'],
    $handoff['order_products'],
    $handoff['calculation'],
    930001
);
mtucAud010F04_assert(
    $smart['clientDeliveryAddress'] === $guestAddress
        && strpos($smart['clientDeliveryAddress'], 'Sofia') === false
        && strpos($smart['clientDeliveryAddress'], '1000') === false,
    'SmartUCF clientDeliveryAddress: applicant only'
);

// -------------------------------------------------------------------------
// Checkout regression: structured native order still formats fully
// -------------------------------------------------------------------------
$checkoutOrder = Phase7TestHarness::orderRow(930002, Phase5TestHarness::STORE_A);
$checkoutOrder['payment_address_1'] = 'ул. Checkout 1';
$checkoutOrder['payment_city'] = 'София';
$checkoutOrder['payment_postcode'] = '1000';
$checkoutOrder['payment_country'] = 'Bulgaria';
$checkoutCp = (new MtUniCreditControlPanelOrderPayloadBuilder())->build(
    930002,
    $checkoutOrder,
    $products,
    $calc,
    $shop
);
mtucAud010F04_assert(
    strpos($checkoutCp['address'], 'ул. Checkout 1') !== false
        && strpos($checkoutCp['address'], 'София') !== false
        && strpos($checkoutCp['address'], '1000') !== false,
    'Checkout: structured native address unchanged'
);

// -------------------------------------------------------------------------
// No Customer/Address mutation in changed production files
// -------------------------------------------------------------------------
$files = array(
    'storefront_popup_form_normalizer.php',
    'storefront_order_draft_builder.php',
);
foreach ($files as $file) {
    $src = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . $file);
    mtucAud010F04_assert(
        strpos($src, 'editCustomer') === false
            && strpos($src, 'addCustomer') === false
            && strpos($src, 'editAddress') === false
            && strpos($src, 'addAddress') === false,
        $file . ': no Customer/Address mutation'
    );
}
$productCtrl = (string) file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'product.php'
);
$cartCtrl = (string) file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'cart.php'
);
mtucAud010F04_assert(
    strpos($productCtrl, 'storeAddressDefaults') === false
        && strpos($cartCtrl, 'storeAddressDefaults') === false
        && strpos($productCtrl, "'Sofia'") === false
        && strpos($cartCtrl, "'Sofia'") === false
        && strpos($productCtrl, "'1000'") === false
        && strpos($cartCtrl, "'1000'") === false,
    'controllers: store defaults / Sofia / 1000 removed'
);

// -------------------------------------------------------------------------
if (count($failures) > 0) {
    echo 'AUD-010 F04: FAIL (' . count($failures) . ' failures, ' . $passes . ' passes)' . PHP_EOL;
    exit(1);
}

echo 'AUD-010 F04: PASS (' . $passes . ' passes)' . PHP_EOL;
exit(0);
