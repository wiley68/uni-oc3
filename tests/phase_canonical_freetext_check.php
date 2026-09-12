<?php

/**
 * Canonical free-text payload preservation (apostrophe, underscore, UTF-8, order_id length).
 * Run: php tests/phase_canonical_freetext_check.php
 */
require_once __DIR__ . '/bootstrap.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DIR_STORAGE')) {
    mtuc_test_define_dir_storage('mtuc-canonical-freetext');
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
require_once __DIR__ . '/support/phase6_harness.php';
require_once __DIR__ . '/support/phase7_harness.php';
require_once __DIR__ . '/support/canonical_harness.php';
require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

$failures = array();
$passes = 0;

function mtucCanonFt_assert(bool $condition, string $message): void
{
    global $failures, $passes;
    CanonicalTestHarness::assert($condition, $message, $failures, $passes);
}

$builder = new MtUniCreditControlPanelOrderPayloadBuilder();
$calc = (new MtUniCreditCalculator())->calculateScheme(
    mtuc4_valid_shop_snapshot(),
    100.0,
    new MtUniCreditAvailableScheme(
        'standard',
        'KOPSTD',
        12,
        0,
        array(),
        array('coeff' => 1.05, 'interestPercent' => 5.5, 'installmentCount' => 12, 'onlineProductCode' => 'KOPSTD')
    ),
    0.0
);

$order = Phase7TestHarness::orderRow(98001);
$order['firstname'] = "O'Brien";
$order['payment_address_1'] = "ул. _Тест_ 1";
$products = array(
    array(
        'product_id' => 7,
        'name' => "Name_with_underscore + 'quote' + Кирилица",
        'quantity' => 1,
        'price' => 100.0,
        'total' => 100.0,
    ),
);

$payload = $builder->build(98001, $order, $products, $calc, mtuc4_valid_shop_snapshot());

mtucCanonFt_assert(
    isset($payload['products_name'])
        && strpos((string) $payload['products_name'], '_') !== false
        && strpos((string) $payload['products_name'], "'") !== false
        && strpos((string) $payload['products_name'], 'Кирилица') !== false,
    'product name preserves underscore, apostrophe, UTF-8'
);
mtucCanonFt_assert(
    isset($payload['name']) && strpos((string) $payload['name'], "'") !== false,
    'customer name preserves apostrophe'
);
mtucCanonFt_assert(
    isset($payload['address']) && strpos((string) $payload['address'], '_') !== false,
    'address preserves underscore'
);
mtucCanonFt_assert(
    isset($payload['order_id']) && (string) $payload['order_id'] === '98001',
    'order_id preserved as string'
);

$ok13 = true;
try {
    $builder->build(
        str_repeat('1', 13),
        Phase7TestHarness::orderRow(1),
        $products,
        $calc,
        mtuc4_valid_shop_snapshot()
    );
} catch (InvalidArgumentException $exception) {
    $ok13 = false;
}
mtucCanonFt_assert($ok13, '13-char order_id accepted');

$rejected14 = false;
try {
    $builder->build(
        str_repeat('2', 14),
        Phase7TestHarness::orderRow(1),
        $products,
        $calc,
        mtuc4_valid_shop_snapshot()
    );
} catch (InvalidArgumentException $exception) {
    $rejected14 = strpos($exception->getMessage(), '13') !== false;
}
mtucCanonFt_assert($rejected14, '14-char order_id rejected (not truncated)');

$src = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'control_panel_order_payload_builder.php');
mtucCanonFt_assert(
    strpos($src, "str_replace('_', '-')") === false
        && strpos($src, 'htmlspecialchars') === false
        && strpos($src, "substr(implode('_', \$names), 0, 255)") === false,
    'builder does not rewrite underscores, HTML-encode, or silently truncate products_name'
);

$longAscii = str_repeat('A', 300);
$longUtf8 = str_repeat('Ж', 100); // >255 bytes in UTF-8
$multiProducts = array(
    array('product_id' => 1, 'name' => $longAscii, 'quantity' => 1, 'price' => 10.0, 'total' => 10.0),
    array('product_id' => 2, 'name' => "Test_Product's Name", 'quantity' => 2, 'price' => 20.0, 'total' => 40.0),
    array('product_id' => 3, 'name' => $longUtf8, 'quantity' => 1, 'price' => 5.0, 'total' => 5.0),
);
$longPayload = $builder->build(98002, Phase7TestHarness::orderRow(98002), $multiProducts, $calc, mtuc4_valid_shop_snapshot());
mtucCanonFt_assert(
    isset($longPayload['products_name'])
        && strlen((string) $longPayload['products_name']) > 255
        && strpos((string) $longPayload['products_name'], $longAscii) !== false,
    'long ASCII product name preserved without silent truncation'
);
mtucCanonFt_assert(
    strpos((string) $longPayload['products_name'], "Test_Product's Name") !== false,
    "Test_Product's Name preserved in multi-product join"
);
mtucCanonFt_assert(
    strpos((string) $longPayload['products_name'], $longUtf8) !== false
        && preg_match('/Ж/', (string) $longPayload['products_name']) === 1,
    'long UTF-8 product text preserved without mid-byte cut'
);

echo PHP_EOL . 'canonical freetext: ' . $passes . ' passed, ' . count($failures) . ' failed' . PHP_EOL;
exit(count($failures) === 0 ? 0 : 1);
