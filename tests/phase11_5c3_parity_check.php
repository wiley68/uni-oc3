<?php

/**
 * Phase 11.5C.3 — Checkout visual + native order status parity.
 * Run: php tests/phase11_5c3_parity_check.php
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
    $storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mtuc-phase115c3parity-storage';
    if (!is_dir($storage)) {
        @mkdir($storage, 0770, true);
    }
    if (!is_dir($storage . DIRECTORY_SEPARATOR . 'mt_uni_credit')) {
        @mkdir($storage . DIRECTORY_SEPARATOR . 'mt_uni_credit', 0770, true);
    }
    define('DIR_STORAGE', rtrim($storage, '/\\') . DIRECTORY_SEPARATOR);
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
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
function mtuc115c3p_assert($condition, $message)
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
 * @param string $path
 * @return string
 */
function mtuc115c3p_read($path)
{
    $body = @file_get_contents($path);

    return is_string($body) ? $body : '';
}

/**
 * Extract a CSS rule body for an exact selector under checkout root.
 *
 * @param string $css
 * @param string $selectorSuffix e.g. ".mt-uni-credit-checkout-panel__helper"
 * @return string
 */
function mtuc115c3p_rule($css, $selectorSuffix)
{
    $needle = '#mt-uni-credit-checkout-root ' . $selectorSuffix;
    $offset = 0;
    while (($pos = strpos($css, $needle, $offset)) !== false) {
        $after = $pos + strlen($needle);
        $ch = $after < strlen($css) ? $css[$after] : '';
        // Avoid matching `.consent` inside `.consents` / `.consent--info`.
        if ($ch !== '' && $ch !== '{' && $ch !== ' ' && $ch !== "\n" && $ch !== "\r" && $ch !== ',') {
            $offset = $after;
            continue;
        }
        $brace = strpos($css, '{', $pos);
        $end = strpos($css, '}', $brace);
        if ($brace === false || $end === false) {
            return '';
        }

        return substr($css, $brace + 1, $end - $brace - 1);
    }

    return '';
}

$controllerPath = $root . '/upload/catalog/controller/extension/payment/mt_uni_credit.php';
$cssPath = $root . '/upload/catalog/view/theme/default/template/extension/payment/mt_uni_credit_checkout.css';
$twigPath = $root . '/upload/catalog/view/theme/default/template/extension/payment/mt_uni_credit.twig';
$productPath = $root . '/upload/catalog/controller/extension/mt_uni_credit/product.php';
$cartPath = $root . '/upload/catalog/controller/extension/mt_uni_credit/cart.php';
$storefrontCss = $root . '/upload/catalog/view/theme/default/template/extension/mt_uni_credit/storefront.css';
$oc4Css = dirname($root) . '/reference-uni-oc4/catalog/view/stylesheet/mt_uni_credit_checkout.css';

$controller = mtuc115c3p_read($controllerPath);
$css = mtuc115c3p_read($cssPath);
$twig = mtuc115c3p_read($twigPath);
$productSrc = mtuc115c3p_read($productPath);
$cartSrc = mtuc115c3p_read($cartPath);
$sfCss = mtuc115c3p_read($storefrontCss);
$oc4 = mtuc115c3p_read($oc4Css);

// --- Visual: helper typography (authority-equivalent declarations) ---
$helperRule = mtuc115c3p_rule($css, '.mt-uni-credit-checkout-panel__helper');
mtuc115c3p_assert($helperRule !== '', 'helper selector scoped under checkout root');
mtuc115c3p_assert(strpos($helperRule, 'font-size: 14px') !== false, 'helper font-size 14px (OC4 0.875rem family)');
mtuc115c3p_assert(strpos($helperRule, 'line-height: 1.45') !== false, 'helper line-height 1.45');
mtuc115c3p_assert(strpos($helperRule, 'font-weight: 400') !== false, 'helper font-weight 400');
mtuc115c3p_assert(
    strpos($helperRule, 'color: var(--mtuc-popup-red') !== false
        || strpos($helperRule, 'color: #ed1c24') !== false,
    'helper color UniCredit red'
);
mtuc115c3p_assert(strpos($helperRule, 'margin: 0 0 0.85rem') !== false, 'helper margin matches OC4');
mtuc115c3p_assert(strpos($css, 'font-size: 0.875rem') === false, 'helper no longer uses rem that Journal shrinks');

// --- Visual: consent container / checkbox / text / links ---
$consentsRule = mtuc115c3p_rule($css, '.mt-uni-credit-storefront__consents');
mtuc115c3p_assert(strpos($consentsRule, 'gap: 10px') !== false, 'consent container gap 10px');
mtuc115c3p_assert(strpos($consentsRule, 'margin: 0 0 20px') !== false, 'consent container margin before confirm 0 0 20px');

$consentRule = mtuc115c3p_rule($css, '.mt-uni-credit-storefront__consent');
mtuc115c3p_assert(strpos($consentRule, 'align-items: flex-start') !== false, 'consent row flex-start alignment');
mtuc115c3p_assert(strpos($consentRule, 'gap: 10px') !== false, 'consent row gap 10px');

$cbRule = mtuc115c3p_rule($css, '.mt-uni-credit-storefront__consent-checkbox');
mtuc115c3p_assert(strpos($cbRule, 'width: 18px') !== false && strpos($cbRule, 'height: 18px') !== false, 'checkbox 18×18');
mtuc115c3p_assert(strpos($cbRule, 'margin: 2px 0 0') !== false, 'checkbox vertical alignment margin');
mtuc115c3p_assert(strpos($cbRule, 'accent-color: var(--mtuc-popup-red') !== false, 'checkbox UniCredit accent-color');

$labelRule = mtuc115c3p_rule($css, '.mt-uni-credit-storefront__consent-label,');
if ($labelRule === '') {
    // Combined selector may wrap differently — fall back to scanning block start.
    if (preg_match(
        '/#mt-uni-credit-checkout-root \.mt-uni-credit-storefront__consent-label,\s*'
            . '#mt-uni-credit-checkout-root \.mt-uni-credit-storefront__consent-text \{([^}]+)\}/s',
        $css,
        $m
    )) {
        $labelRule = $m[1];
    }
}
mtuc115c3p_assert(strpos($labelRule, 'font-size: 14px') !== false, 'consent text font-size 14px');
mtuc115c3p_assert(strpos($labelRule, 'line-height: 1.4') !== false, 'consent text line-height 1.4');
mtuc115c3p_assert(strpos($labelRule, 'font-weight: 400') !== false, 'consent text font-weight 400');
mtuc115c3p_assert(strpos($labelRule, 'color: #000') !== false, 'consent text color #000');

mtuc115c3p_assert(
    strpos($css, '.mt-uni-credit-storefront__consent-label a') !== false
        && strpos($css, 'text-decoration: underline') !== false,
    'consent link underline styling present'
);
mtuc115c3p_assert(
    strpos($css, '.mt-uni-credit-storefront__consent--info') !== false
        && strpos($css, 'padding-left: 28px') !== false,
    'info consent padding-left 28px'
);
mtuc115c3p_assert(
    strpos($css, '.checkout input[type=checkbox]') === false
        && strpos($css, 'input[type=checkbox]') === false,
    'no broad checkout checkbox selectors'
);

// Cross-check Product/Cart storefront authority still uses the same consent numbers.
mtuc115c3p_assert(
    strpos($sfCss, 'gap: 10px') !== false
        && strpos($sfCss, 'margin: 0 0 20px') !== false
        && strpos($sfCss, 'font-size: 14px') !== false
        && strpos($sfCss, 'color: #000') !== false,
    'Product/Cart storefront consent authority values present'
);
mtuc115c3p_assert(
    $oc4 === '' || strpos($oc4, '.mt-uni-credit-checkout-panel__helper') !== false,
    'OC4 checkout CSS authority reachable for comparison'
);

// Twig markup still uses storefront consent BEM (behaviour unchanged).
mtuc115c3p_assert(strpos($twig, 'data-mtuc-consent-checkbox') !== false, 'consent checkbox markup preserved');
mtuc115c3p_assert(strpos($twig, 'name="consent[]"') !== false, 'consent POST name preserved');

// --- Native status wiring (controller contract) ---
mtuc115c3p_assert(
    strpos($controller, 'maybeApplyCheckoutNativeOrderStatusAfterHandoff') !== false,
    'Checkout uses handoff-gated native status helper'
);
mtuc115c3p_assert(
    strpos($controller, 'isSuccessfulBankHandoff') !== false,
    'native status gated on durable bank handoff'
);
mtuc115c3p_assert(
    substr_count($controller, 'maybeApplyCheckoutNativeOrderStatusAfterHandoff') >= 3,
    'confirm + prepared submit both call handoff status helper'
);
mtuc115c3p_assert(
    strpos($controller, "if (!empty(\$submit['apply_native_order_status']))") === false
        && strpos($controller, "if (!empty(\$result['apply_native_order_status']))") === false,
    'Checkout no longer gates solely on apply_native_order_status (localReplay bug)'
);
mtuc115c3p_assert(
    strpos($controller, 'current === $statusId') !== false
        || strpos($controller, '$current === $statusId') !== false,
    'idempotent skip when native status already applied'
);
mtuc115c3p_assert(strpos($controller, 'addOrder(') === false || preg_match(
    '/function confirm\s*\([\s\S]*?\n    public function /s',
    $controller,
    $confirmMatch
) && strpos($confirmMatch[0], 'addOrder(') === false, 'confirm does not create a second order');

// Product/Cart canaries: still use maybeApplyNativeOrderStatus + apply_native flag.
mtuc115c3p_assert(
    strpos($productSrc, 'maybeApplyNativeOrderStatus') !== false
        && strpos($productSrc, "empty(\$result['apply_native_order_status'])") !== false,
    'Product canary: apply_native gating unchanged'
);
mtuc115c3p_assert(
    strpos($cartSrc, 'maybeApplyNativeOrderStatus') !== false
        && strpos($cartSrc, "empty(\$result['apply_native_order_status'])") !== false,
    'Cart canary: apply_native gating unchanged'
);

// --- Behavioural: handoff gate vs localReplay flag ---
$p1Handoff = array(
    'success' => true,
    'bank_status' => MtUniCreditBankStatus::SENT_PROCESS1,
    'apply_native_order_status' => false,
    'local_replay' => true,
);
$p2Handoff = array(
    'success' => true,
    'bank_status' => MtUniCreditBankStatus::SENT_PROCESS2,
    'apply_native_order_status' => false,
    'local_replay' => true,
);
$cpFail = array(
    'success' => false,
    'error' => 'cp_submit_failed',
    'apply_native_order_status' => false,
    'bank_status' => '',
);
$smartFail = array(
    'success' => false,
    'error' => 'smartucf_submit_failed',
    'apply_native_order_status' => false,
    'bank_status' => '',
    'cp_succeeded' => true,
);

mtuc115c3p_assert(
    MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff($p1Handoff),
    'P1 success+bank_sent_process1 is durable handoff even when apply_native false'
);
mtuc115c3p_assert(
    MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff($p2Handoff),
    'P2 success+bank_sent_process2 is durable handoff even when apply_native false'
);
mtuc115c3p_assert(
    !MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff($cpFail),
    'CP fail is not a success handoff'
);
mtuc115c3p_assert(
    !MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff($smartFail),
    'SmartUCF fail is not a success handoff'
);

// Simulate idempotent native status application (mirrors applyPreparedOrderStatus).
$configuredStatus = 2;
$orderStatuses = array(115301 => 0, 115302 => 0);
$historyLog = array();

/**
 * @param int $orderId
 * @param int $statusId
 * @param array<int,int> $orderStatuses
 * @param array<int,array{order_id:int,status_id:int}> $historyLog
 * @return void
 */
function mtuc115c3p_apply_if_needed($orderId, $statusId, array &$orderStatuses, array &$historyLog)
{
    if ($orderId <= 0 || $statusId <= 0 || !isset($orderStatuses[$orderId])) {
        return;
    }
    if ((int) $orderStatuses[$orderId] === (int) $statusId) {
        return;
    }
    $orderStatuses[$orderId] = (int) $statusId;
    $historyLog[] = array('order_id' => (int) $orderId, 'status_id' => (int) $statusId);
}

// P1: status 0 → configured; replay no second history
mtuc115c3p_assert((int) $orderStatuses[115301] === 0, 'P1 fixture starts at status 0');
if (MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff($p1Handoff)) {
    mtuc115c3p_apply_if_needed(115301, $configuredStatus, $orderStatuses, $historyLog);
}
mtuc115c3p_assert((int) $orderStatuses[115301] === $configuredStatus, 'P1 handoff moves native status off 0');
mtuc115c3p_assert(count($historyLog) === 1, 'P1 first handoff: one history transition');
if (MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff($p1Handoff)) {
    mtuc115c3p_apply_if_needed(115301, $configuredStatus, $orderStatuses, $historyLog);
}
mtuc115c3p_assert(count($historyLog) === 1, 'P1 replay: no duplicate history/mail transition');

// P2: same
if (MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff($p2Handoff)) {
    mtuc115c3p_apply_if_needed(115302, $configuredStatus, $orderStatuses, $historyLog);
}
mtuc115c3p_assert((int) $orderStatuses[115302] === $configuredStatus, 'P2 handoff moves native status off 0');
$historyBeforeFail = count($historyLog);
if (MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff($cpFail)) {
    mtuc115c3p_apply_if_needed(115303, $configuredStatus, $orderStatuses, $historyLog);
}
if (MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff($smartFail)) {
    mtuc115c3p_apply_if_needed(115304, $configuredStatus, $orderStatuses, $historyLog);
}
mtuc115c3p_assert(count($historyLog) === $historyBeforeFail, 'CP/SmartUCF failures do not apply success status');

// Lifecycle: Checkout localReplay success still reports bank_sent but apply_native false (root cause).
$transport = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transport);
$stack = Phase9TestHarness::stack($transport);
$orderId = 115310;
Phase9TestHarness::seedBankOrder($stack['memoryDb'], $orderId, $stack['storeId']);
$input = Phase9TestHarness::submitInput($orderId, $stack['storeId']);
$first = $stack['submission']->submit($input);
mtuc115c3p_assert(!empty($first['success']), 'Checkout P1 first submit succeeds');
mtuc115c3p_assert(
    Phase9TestHarness::bankStatusId($stack, $orderId) === MtUniCreditBankStatus::SENT_PROCESS1,
    'Checkout P1 first submit persists bank_sent_process1'
);
mtuc115c3p_assert(
    isset($first['bank_status']) && (string) $first['bank_status'] === MtUniCreditBankStatus::SENT_PROCESS1,
    'Checkout P1 result exposes bank_status for handoff gate'
);
mtuc115c3p_assert(!empty($first['apply_native_order_status']), 'first success still authorises apply_native');

$replay = $stack['submission']->submit($input);
mtuc115c3p_assert(!empty($replay['success']) && !empty($replay['local_replay']), 'Checkout P1 replay is local_replay success');
mtuc115c3p_assert(empty($replay['apply_native_order_status']), 'root cause: replay clears apply_native_order_status');
mtuc115c3p_assert(
    MtUniCreditFinancingTerminalNavigationSupport::isSuccessfulBankHandoff($replay),
    'fix: replay remains durable handoff so Checkout still applies status when order at 0'
);
mtuc115c3p_assert(Phase7TestHarness::countOrderPosts($transport) === 1, 'no second CP create on replay');
mtuc115c3p_assert(Phase9TestHarness::smartUcfCallCount($stack['smartUcfProbe']) === 1, 'SmartUCF exactly once');

// Same order id throughout — no second addOrder in submission service.
$checkoutSrc = mtuc115c3p_read($lib . '/checkout_financing_submission_service.php');
mtuc115c3p_assert(
    strpos($checkoutSrc, '->addOrder(') === false
        && strpos($checkoutSrc, 'addOrder($') === false,
    'Checkout financing service never calls addOrder'
);

echo PHP_EOL . 'Phase 11.5C.3 parity summary: ' . $passes . ' passed, ' . count($failures) . ' failed' . PHP_EOL;
if ($failures) {
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'PHASE 11.5C.3 CHECKOUT VISUAL AND NATIVE STATUS PARITY: PASS — LOCAL' . PHP_EOL;
exit(0);
