<?php

/**
 * AUD-010 F03 — immutable snapshot preserves firstname/lastname boundary.
 * Run: php tests/phase_aud010_f03_name_boundary_check.php
 *
 * PHP 7.3 compatible. Offline.
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
    mtuc_test_define_dir_storage('mtuc-aud010-f03');
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
function mtucAud010F03_assert($condition, $message)
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
function mtucAud010F03_calc($months, $price)
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
 * @param array<string, mixed> $order
 * @param string $entryPoint
 * @return array<string, mixed>
 */
function mtucAud010F03_snapshotFromOrder(array $order, $entryPoint)
{
    $orderId = (int) $order['order_id'];
    $products = Phase7TestHarness::orderProducts();
    $shop = mtuc4_valid_shop_snapshot();
    $calc = mtucAud010F03_calc(12, 500.0);
    $payload = (new MtUniCreditControlPanelOrderPayloadBuilder())->build(
        $orderId,
        $order,
        $products,
        $calc,
        $shop
    );
    $fingerprint = MtUniCreditControlPanelOrderPayloadBuilder::fingerprint($payload);
    $selectionHash = hash(
        'sha256',
        $calc->scheme->kopCode . '|' . $calc->scheme->months . '|' . $fingerprint
    );

    return MtUniCreditApplicationSnapshot::fromLive(
        $calc,
        $order,
        $products,
        $shop,
        $entryPoint,
        hash('sha256', 'aud010-f03|' . $orderId . '|' . $entryPoint),
        $selectionHash,
        $fingerprint
    );
}

/**
 * @param array<string, mixed> $snapshot
 * @param array<string, mixed>|null $liveOrder
 * @return array<string, mixed>
 */
function mtucAud010F03_smartFromSnapshot(array $snapshot, $liveOrder = null)
{
    $orderId = $liveOrder !== null
        ? (int) $liveOrder['order_id']
        : 910001;
    $order = $liveOrder !== null
        ? $liveOrder
        : Phase7TestHarness::orderRow($orderId, Phase5TestHarness::STORE_A);
    // Drift live names so handoff must come from snapshot.
    $order['firstname'] = 'LIVE';
    $order['lastname'] = 'DRIFT';

    $handoff = MtUniCreditApplicationSnapshot::resolveHandoffInputs($snapshot, $order);
    $shop = mtuc4_valid_shop_snapshot();

    return (new MtUniCreditSmartUcfPayloadBuilder())->build(
        $shop,
        $handoff['order'],
        $handoff['order_products'],
        $handoff['calculation'],
        $orderId
    );
}

/**
 * @param string $firstname
 * @param string $lastname
 * @param int $orderId
 * @return array<string, mixed>
 */
function mtucAud010F03_orderWithNames($firstname, $lastname, $orderId)
{
    $order = Phase7TestHarness::orderRow($orderId, Phase5TestHarness::STORE_A);
    $order['firstname'] = $firstname;
    $order['lastname'] = $lastname;

    return $order;
}

// -------------------------------------------------------------------------
// Compound firstname
// -------------------------------------------------------------------------
$fnCompound = 'Анна Мария';
$lnSimple = 'Иванова';
$order1 = mtucAud010F03_orderWithNames($fnCompound, $lnSimple, 910101);
$snap1 = mtucAud010F03_snapshotFromOrder($order1, MtUniCreditOperationEntryPoint::PRODUCT);
mtucAud010F03_assert(
    isset($snap1['customer']['firstname'], $snap1['customer']['lastname'])
        && $snap1['customer']['firstname'] === $fnCompound
        && $snap1['customer']['lastname'] === $lnSimple,
    'compound firstname: structured snapshot fields'
);
mtucAud010F03_assert(
    $snap1['customer']['name'] === trim($fnCompound . ' ' . $lnSimple),
    'compound firstname: combined name retained'
);
$smart1 = mtucAud010F03_smartFromSnapshot($snap1, $order1);
mtucAud010F03_assert(
    $smart1['clientFirstName'] === $fnCompound
        && $smart1['clientLastName'] === $lnSimple,
    'compound firstname: SmartUCF exact boundary'
);
mtucAud010F03_assert(
    $smart1['clientFirstName'] !== 'Анна'
        || $smart1['clientLastName'] !== 'Мария Иванова',
    'compound firstname: not legacy preg_split boundary'
);

// -------------------------------------------------------------------------
// Compound lastname
// -------------------------------------------------------------------------
$fnSimple = 'Анна';
$lnCompound = 'Иванова Петрова';
$order2 = mtucAud010F03_orderWithNames($fnSimple, $lnCompound, 910102);
$snap2 = mtucAud010F03_snapshotFromOrder($order2, MtUniCreditOperationEntryPoint::CART);
mtucAud010F03_assert(
    $snap2['customer']['firstname'] === $fnSimple
        && $snap2['customer']['lastname'] === $lnCompound,
    'compound lastname: Cart snapshot fields'
);
$smart2 = mtucAud010F03_smartFromSnapshot($snap2, $order2);
mtucAud010F03_assert(
    $smart2['clientFirstName'] === $fnSimple
        && $smart2['clientLastName'] === $lnCompound,
    'compound lastname: SmartUCF exact boundary'
);

// -------------------------------------------------------------------------
// Both compound + UTF-8
// -------------------------------------------------------------------------
$fnBoth = 'Анна Мария';
$lnBoth = 'Иванова Петрова';
$order3 = mtucAud010F03_orderWithNames($fnBoth, $lnBoth, 910103);
$snap3 = mtucAud010F03_snapshotFromOrder($order3, MtUniCreditOperationEntryPoint::CHECKOUT);
mtucAud010F03_assert(
    $snap3['customer']['firstname'] === $fnBoth
        && $snap3['customer']['lastname'] === $lnBoth,
    'both compound: Checkout snapshot fields'
);
$smart3 = mtucAud010F03_smartFromSnapshot($snap3, $order3);
mtucAud010F03_assert(
    $smart3['clientFirstName'] === $fnBoth
        && $smart3['clientLastName'] === $lnBoth,
    'both compound: UTF-8 SmartUCF exact boundary'
);

// -------------------------------------------------------------------------
// Encode/decode round-trip (persistence)
// -------------------------------------------------------------------------
$encoded = MtUniCreditApplicationSnapshot::encode($snap1);
$decoded = MtUniCreditApplicationSnapshot::decode($encoded);
mtucAud010F03_assert(
    is_array($decoded)
        && $decoded['customer']['firstname'] === $fnCompound
        && $decoded['customer']['lastname'] === $lnSimple,
    'serialization: structured names survive encode/decode'
);
$smartReload = mtucAud010F03_smartFromSnapshot($decoded, $order1);
mtucAud010F03_assert(
    $smartReload['clientFirstName'] === $fnCompound
        && $smartReload['clientLastName'] === $lnSimple,
    'reload/recovery: SmartUCF preserves structured boundary'
);

// -------------------------------------------------------------------------
// Immediate handoff (no mutation of live order names before overlay)
// -------------------------------------------------------------------------
$orderImm = mtucAud010F03_orderWithNames($fnCompound, $lnSimple, 910104);
$snapImm = mtucAud010F03_snapshotFromOrder($orderImm, MtUniCreditOperationEntryPoint::PRODUCT);
$handoffImm = MtUniCreditApplicationSnapshot::resolveHandoffInputs($snapImm, $orderImm);
mtucAud010F03_assert(
    $handoffImm['order']['firstname'] === $fnCompound
        && $handoffImm['order']['lastname'] === $lnSimple,
    'immediate handoff: overlay uses structured fields'
);

// -------------------------------------------------------------------------
// CP combined name remains correct
// -------------------------------------------------------------------------
$cpPayload = (new MtUniCreditControlPanelOrderPayloadBuilder())->build(
    910101,
    $order1,
    Phase7TestHarness::orderProducts(),
    mtucAud010F03_calc(12, 500.0),
    mtuc4_valid_shop_snapshot()
);
mtucAud010F03_assert(
    $cpPayload['name'] === trim($fnCompound . ' ' . $lnSimple),
    'CP combined name remains full text'
);

// -------------------------------------------------------------------------
// Legacy snapshot: only customer.name
// -------------------------------------------------------------------------
$legacy = $snap1;
unset($legacy['customer']['firstname'], $legacy['customer']['lastname']);
$legacy['customer']['name'] = 'Анна Мария Иванова';
mtucAud010F03_assert(
    !array_key_exists('firstname', $legacy['customer'])
        && !array_key_exists('lastname', $legacy['customer']),
    'legacy fixture: structured fields absent'
);
$legacyOrder = Phase7TestHarness::orderRow(910105, Phase5TestHarness::STORE_A);
$legacyOrder['firstname'] = 'LIVE';
$legacyOrder['lastname'] = 'DRIFT';
$legacyHandoff = MtUniCreditApplicationSnapshot::overlayOrderForHandoff($legacyOrder, $legacy);
mtucAud010F03_assert(
    $legacyHandoff['firstname'] === 'Анна'
        && $legacyHandoff['lastname'] === 'Мария Иванова',
    'legacy snapshot: recoverable via combined-name fallback'
);
$legacyDecoded = MtUniCreditApplicationSnapshot::decode(MtUniCreditApplicationSnapshot::encode($legacy));
mtucAud010F03_assert(is_array($legacyDecoded), 'legacy snapshot: remains loadable');

// -------------------------------------------------------------------------
// New-format must not use fallback (present empty keys still structured)
// -------------------------------------------------------------------------
$emptyStructured = $snap1;
$emptyStructured['customer']['firstname'] = '';
$emptyStructured['customer']['lastname'] = '';
$emptyStructured['customer']['name'] = 'Анна Мария Иванова';
$emptyOrder = Phase7TestHarness::orderRow(910106, Phase5TestHarness::STORE_A);
$emptyOrder['firstname'] = 'LIVE';
$emptyOrder['lastname'] = 'DRIFT';
$emptyHandoff = MtUniCreditApplicationSnapshot::overlayOrderForHandoff($emptyOrder, $emptyStructured);
mtucAud010F03_assert(
    $emptyHandoff['firstname'] === ''
        && $emptyHandoff['lastname'] === '',
    'structured present (even empty): no legacy preg_split fallback'
);

// -------------------------------------------------------------------------
// Product / Cart draft → snapshot boundary (storefront draft builder)
// -------------------------------------------------------------------------
$draft = (new MtUniCreditStorefrontOrderDraftBuilder())->buildOrderData(array(
    'customer' => array(
        'firstname' => $fnCompound,
        'lastname' => $lnSimple,
        'telephone' => '0888000111',
        'email' => 'anna@example.com',
        'address_1' => 'ул. Тест 1',
        'city' => 'София',
        'country_id' => 33,
        'zone_id' => 0,
    ),
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
$draft['order_id'] = 910107;
$draftSnap = mtucAud010F03_snapshotFromOrder($draft, MtUniCreditOperationEntryPoint::PRODUCT);
mtucAud010F03_assert(
    $draftSnap['customer']['firstname'] === $fnCompound
        && $draftSnap['customer']['lastname'] === $lnSimple,
    'Product draft: snapshot structured names'
);
$cartDraft = $draft;
$cartDraft['order_id'] = 910108;
$cartSnap = mtucAud010F03_snapshotFromOrder($cartDraft, MtUniCreditOperationEntryPoint::CART);
mtucAud010F03_assert(
    $cartSnap['customer']['firstname'] === $fnCompound
        && $cartSnap['customer']['lastname'] === $lnSimple,
    'Cart draft: snapshot structured names'
);

// -------------------------------------------------------------------------
// No Customer / Address mutation APIs introduced in snapshot module
// -------------------------------------------------------------------------
$snapSrc = (string) file_get_contents(
    $lib . DIRECTORY_SEPARATOR . 'application_snapshot.php'
);
mtucAud010F03_assert(
    strpos($snapSrc, 'editCustomer') === false
        && strpos($snapSrc, 'addCustomer') === false
        && strpos($snapSrc, 'editAddress') === false
        && strpos($snapSrc, 'addAddress') === false,
    'no Customer/Address mutation in application_snapshot'
);

// Process 2 / privacy unrelated fields untouched in customer block extras
mtucAud010F03_assert(
    !array_key_exists('egn', $snap1['customer'])
        && !array_key_exists('phone2', $snap1['customer']),
    'Process 2 privacy fields not introduced into snapshot customer'
);

// -------------------------------------------------------------------------
if (count($failures) > 0) {
    echo 'AUD-010 F03: FAIL (' . count($failures) . ' failures, ' . $passes . ' passes)' . PHP_EOL;
    exit(1);
}

echo 'AUD-010 F03: PASS (' . $passes . ' passes)' . PHP_EOL;
exit(0);
