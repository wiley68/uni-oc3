<?php
/**
 * OC3-MANUAL-001: dependency-free authored CSS/asset regression. No shop or network.
 * This checks rule structure and authored precedence, not a real browser cascade.
 * Default/Journal computed display and scope switching still require manual acceptance.
 */
require_once __DIR__ . '/bootstrap.php';
define('DIR_APPLICATION', dirname(__DIR__) . '/upload/catalog/');
require_once dirname(__DIR__) . '/upload/system/library/mt_uni_credit/bootstrap.php';

$passes = 0;
function visibilityAssert($ok, $label)
{
    global $passes;
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
    $passes++;
    echo 'PASS ' . $label . PHP_EOL;
}

/** Read leaf style rules, including those inside media blocks; ignore comments. */
function visibilityRules($css)
{
    $css = preg_replace('~/\*.*?\*/~s', '', $css);
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER);
    $rules = array();
    foreach ($matches as $match) {
        $declarations = array();
        foreach (explode(';', $match[2]) as $declaration) {
            $pair = explode(':', $declaration, 2);
            if (count($pair) !== 2) { continue; }
            $value = trim($pair[1]);
            $declarations[strtolower(trim($pair[0]))] = array(
                'value' => strtolower(trim(preg_replace('/!\s*important\s*$/i', '', $value))),
                'important' => preg_match('/!\s*important\s*$/i', $value) === 1,
            );
        }
        foreach (explode(',', $match[1]) as $selector) {
            $rules[] = array('selector' => trim($selector), 'declarations' => $declarations);
        }
    }
    return $rules;
}
function visibilityDisplay(array $rules, $selector)
{
    $found = array_values(array_filter($rules, function ($rule) use ($selector) { return $rule['selector'] === $selector; }));
    if (count($found) !== 1 || !isset($found[0]['declarations']['display'])) { return null; }
    return $found[0]['declarations']['display'];
}
function visibilityHiddenRule($css)
{
    return visibilityDisplay(visibilityRules($css), '.mt-uni-credit-storefront__consent[hidden]')
        === array('value' => 'none', 'important' => true);
}

$root = dirname(__DIR__);
$extension = 'upload/catalog/view/theme/default/template/extension/';
$sharedPath = $extension . 'mt_uni_credit/storefront.css';
$checkoutPath = $extension . 'payment/mt_uni_credit_checkout.css';
$shared = file_get_contents($root . '/' . $sharedPath);
$checkout = file_get_contents($root . '/' . $checkoutPath);
visibilityAssert(visibilityHiddenRule($shared), 'exact consent[hidden] selector owns display:none !important');
$rules = array_merge(visibilityRules($shared), visibilityRules($checkout));
foreach (array('mt-uni-credit-product-modal', 'mt-uni-credit-cart-modal', 'mt-uni-credit-checkout-root') as $id) {
    $display = visibilityDisplay($rules, '#' . $id . ' .mt-uni-credit-storefront__consent');
    visibilityAssert($display === array('value' => 'flex', 'important' => false), $id . ' visible rows retain normal flex');
    visibilityAssert(visibilityHiddenRule($shared) && $display !== null && !$display['important'],
        $id . ' hidden important declaration outranks existing normal ID-scoped flex');
}
$globalHidden = array_filter($rules, function ($rule) { return in_array($rule['selector'], array('[hidden]', '*[hidden]'), true); });
visibilityAssert($globalHidden === array(), 'no global hidden selector');

// Byte oracles captured before this fix at checkpoint 3c99ab8. Strip only the new
// standalone rule to prove the rest of both production stylesheets is unchanged.
$withoutFix = preg_replace('/\n\.mt-uni-credit-storefront__consent\[hidden\]\s*\{[^{}]*\}\n/', '', $shared, -1, $removed);
visibilityAssert($removed === 1 && hash('sha256', $withoutFix) === 'e866f8439939ae35346312a2d53d404483065c62157ed059bc0d0fc6ac663a45',
    'shared CSS has no unrelated selector/declaration/byte changes');
visibilityAssert(hash('sha256', $checkout) === 'c7d0957607232b6259739f4141cc3fbb62566b221c6b7fbbd1d1f1c8a143b904',
    'checkout CSS remains byte-identical');
visibilityAssert(!visibilityHiddenRule($withoutFix), 'in-memory removal of hidden rule is detected');
$withoutImportant = preg_replace('/(\.mt-uni-credit-storefront__consent\[hidden\]\s*\{[^{}]*?)!important/', '$1', $shared);
visibilityAssert(!visibilityHiddenRule($withoutImportant), 'in-memory removal of precedence protection is detected');

// Exercise the production asset resolver without loading a shop or configuration.
$assets = MtUniCreditStorefrontRuntime::assetUrls(null);
visibilityAssert($assets['css'] === 'catalog/view/theme/default/template/extension/mt_uni_credit/storefront.css?ver=' . filemtime($root . '/' . $sharedPath),
    'production asset resolver selects the corrected versioned stylesheet');
foreach (array('product', 'cart') as $surface) {
    $controller = file_get_contents($root . '/upload/catalog/controller/extension/mt_uni_credit/' . $surface . '.php');
    $twig = file_get_contents($root . '/' . $extension . 'mt_uni_credit/' . $surface . '_widget.twig');
    visibilityAssert(strpos($controller, 'MtUniCreditStorefrontRuntime::assetUrls($this)') !== false
        && preg_match('/\$data\[\x27asset_css\x27\]\s*=\s*\$assets\[\x27css\x27\]/', $controller) === 1
        && preg_match('/<link\b[^>]*href="\{\{\s*asset_css\s*\}\}"[^>]*>/', $twig) === 1,
        $surface . ' controller/widget links the shared stylesheet');
}
$controller = file_get_contents($root . '/upload/catalog/controller/extension/payment/mt_uni_credit.php');
$twig = file_get_contents($root . '/' . $extension . 'payment/mt_uni_credit.twig');
visibilityAssert(strpos($controller, 'MtUniCreditStorefrontRuntime::assetUrls($this)') !== false
    && preg_match('/\x27css\x27\s*=>\s*\$assets\[\x27css\x27\]/', $controller) === 1
    && preg_match('/\$data\[\x27product_css_href\x27\]\s*=\s*\$assets\[\x27css\x27\]/', $controller) === 1
    && preg_match('/<link\b[^>]*href="\{\{\s*product_css_href\s*\}\}"[^>]*>/', $twig) === 1
    && strpos($twig, 'ensureLink(state.product_css_href)') !== false,
    'checkout direct and fragment asset loading includes the corrected shared stylesheet');
require_once $root . '/scripts/package_policy.php';
$sources = package_sources($root);
visibilityAssert(isset($sources[$sharedPath]) && in_array($sharedPath, package_required_entries(), true)
    && $sources[$sharedPath]['sha256'] === hash('sha256', $shared), 'corrected CSS is a required package entry with exact source hash');
echo 'CONSENT VISIBILITY CSS: ' . $passes . ' PASS (source rules/assets; browser acceptance pending)' . PHP_EOL;
