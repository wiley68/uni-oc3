<?php

/**
 * Canonical FinancingOrderResolver ownership (UNICID / store / cardinality).
 * Run: php tests/phase_canonical_financing_resolver_check.php
 */
require_once __DIR__ . '/bootstrap.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DIR_STORAGE')) {
    mtuc_test_define_dir_storage('mtuc-canonical-resolver');
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


$failures = array();
$passes = 0;

function mtucCanonRes_assert(bool $condition, string $message): void
{
    global $failures, $passes;
    CanonicalTestHarness::assert($condition, $message, $failures, $passes);
}

$memoryDb = new Phase2MemoryDb();
$db = new MtUniCreditDbAdapter($memoryDb, 'oc_');
$resolver = new MtUniCreditFinancingOrderResolver($db);
$storeId = Phase6TestHarness::STORE_A;
$unicid = Phase4TestHarness::TEST_UNICID;
$orderId = 91001;

$memoryDb->seedOrder($orderId, $storeId, MtUniCreditConstants::EXTENSION_CODE);
$memoryDb->seedFinancingAttempt(array(
    'store_id' => $storeId,
    'order_id' => $orderId,
    'unicid' => $unicid,
));

$ok = $resolver->resolve($storeId, $unicid, (string) $orderId);
mtucCanonRes_assert($ok !== null && $ok['order_id'] === (string) $orderId && is_string($ok['order_id']), 'correct UNICID resolves attempt');

$wrongUnicid = $resolver->resolve($storeId, '00000000-0000-0000-0000-000000000000', (string) $orderId);
mtucCanonRes_assert($wrongUnicid === null, 'wrong UNICID → null (no disclosure)');

$wrongStore = $resolver->resolve(Phase6TestHarness::STORE_B, $unicid, (string) $orderId);
mtucCanonRes_assert($wrongStore === null, 'wrong store → null');

$missingOrder = 91099;
$memoryDb->seedOrder($missingOrder, $storeId, MtUniCreditConstants::EXTENSION_CODE);
$noAttempt = $resolver->resolve($storeId, $unicid, (string) $missingOrder);
mtucCanonRes_assert($noAttempt === null, 'payment-capable order without attempt → null');

$paymentOnly = 91100;
$memoryDb->seedOrder($paymentOnly, $storeId, MtUniCreditConstants::EXTENSION_CODE, 'UniCredit');
$paymentDenied = $resolver->resolve($storeId, $unicid, (string) $paymentOnly);
mtucCanonRes_assert($paymentDenied === null, 'payment-method alone does not authorize');

$dupOrder = 91200;
$memoryDb->seedOrder($dupOrder, $storeId, MtUniCreditConstants::EXTENSION_CODE);
$memoryDb->seedFinancingAttempt(array(
    'store_id' => $storeId,
    'order_id' => $dupOrder,
    'unicid' => $unicid,
));
$memoryDb->seedFinancingAttempt(array(
    'store_id' => $storeId,
    'order_id' => $dupOrder,
    'unicid' => $unicid,
    'operation_key_hash' => hash('sha256', 'dup-b'),
), true);

$ambiguousThrown = false;
try {
    $resolver->resolve($storeId, $unicid, (string) $dupOrder);
} catch (MtUniCreditFinancingOrderAmbiguousException $exception) {
    $ambiguousThrown = true;
}
mtucCanonRes_assert($ambiguousThrown, 'duplicate attempts → FinancingOrderAmbiguousException');

mtucCanonRes_assert($resolver->resolve($storeId, $unicid, 91001) === null, 'non-string int order_id rejected');
mtucCanonRes_assert($resolver->resolve($storeId, $unicid, 91001.0) === null, 'float order_id rejected');
mtucCanonRes_assert($resolver->resolve($storeId, $unicid, array('91001')) === null, 'array order_id rejected');
mtucCanonRes_assert($resolver->resolve($storeId, $unicid, '') === null, 'empty order_id rejected');
mtucCanonRes_assert(
    $resolver->resolve($storeId, $unicid, str_repeat('9', 14)) === null,
    'order_id >13 rejected (no truncation)'
);
mtucCanonRes_assert(
    $resolver->resolve($storeId, $unicid, '091001') === null,
    'leading-zero noncanonical order_id rejected'
);
mtucCanonRes_assert(
    MtUniCreditShopOrderId::tryNormalize('1234567890123') === '1234567890123',
    'ShopOrderId accepts 13-char'
);
mtucCanonRes_assert(
    MtUniCreditShopOrderId::tryNormalize('12345678901234') === null,
    'ShopOrderId rejects 14-char without truncation'
);
mtucCanonRes_assert(
    MtUniCreditShopOrderId::tryNormalizeStrictString(91001) === null,
    'strict string helper rejects int'
);

echo PHP_EOL . 'canonical financing resolver: ' . $passes . ' passed, ' . count($failures) . ' failed' . PHP_EOL;
exit(count($failures) === 0 ? 0 : 1);
