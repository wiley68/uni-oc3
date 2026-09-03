<?php

/**
 * Included from mtuc8_run() — Product dynamic recalculation + optional CP heading.
 *
 * @var string $catalog
 * @var MtUniCreditStorefrontCalculatorPresenter $presenter
 * @var array $shop
 * @var string $jsPopup
 * @var string $productTwig
 * @var string $cartTwig
 */

// --- Optional CP heading contract ---
$headingConfigured = $presenter->presentProduct(
    array_merge($shop, array('uni_zaglavie' => 'Купи с УниКредит')),
    new MtUniCreditProductContext(42, array(7), 500.0),
    'BGN'
);
mtuc8_assert(is_array($headingConfigured), 'heading configured shop presents');
mtuc8_assert(
    $headingConfigured['heading'] === 'Купи с УниКредит',
    'CP heading non-empty is authoritative text'
);

$headingEmpty = $presenter->presentProduct(
    array_merge($shop, array('uni_zaglavie' => '')),
    new MtUniCreditProductContext(42, array(7), 500.0),
    'BGN'
);
mtuc8_assert(is_array($headingEmpty), 'heading empty shop still presents when eligible');
mtuc8_assert($headingEmpty['heading'] === '', 'empty CP heading is empty string (no language fallback)');

$headingWhitespace = $presenter->presentProduct(
    array_merge($shop, array('uni_zaglavie' => "  \t  ")),
    new MtUniCreditProductContext(42, array(7), 500.0),
    'BGN'
);
mtuc8_assert(is_array($headingWhitespace), 'whitespace CP heading shop presents');
mtuc8_assert($headingWhitespace['heading'] === '', 'whitespace-only CP heading trimmed to empty');

foreach (array('product' => $productTwig, 'cart' => $cartTwig) as $surface => $twig) {
    mtuc8_assert(
        preg_match(
            '/\{%\s*if\s+calc\.heading\s*%\}\s*<p class="mt-uni-credit-storefront__heading"[^>]*>\{\{\s*calc\.heading\s*\}\}<\/p>\s*\{%\s*endif\s*%\}/',
            $twig
        ) === 1,
        $surface . ' twig heading is if/endif only (no else fallback)'
    );
    mtuc8_assert(
        strpos($twig, 'data-mtuc-heading') !== false,
        $surface . ' twig heading marker for dynamic sync'
    );
}

// --- Product option × quantity financing amounts (server resolver) ---
$optionResolver = new MtUniCreditOc3ProductLineResolver(
    function ($price, $taxClassId) {
        return (float) $price;
    },
    function ($amount, $from, $to) {
        return (float) $amount;
    },
    function () {
        return array(7);
    },
    function ($productOptionId, $value) {
        if ((int) $productOptionId !== 10) {
            return null;
        }
        if ((string) $value === '100') {
            return array(
                'name' => 'Option A',
                'price' => 0.0,
                'price_prefix' => '+',
                'product_option_value_id' => 100,
            );
        }
        if ((string) $value === '200') {
            return array(
                'name' => 'Option B',
                'price' => 200.0,
                'price_prefix' => '+',
                'product_option_value_id' => 200,
            );
        }

        return null;
    }
);
$optionProduct = array(
    'product_id' => 9,
    'price' => 500.0,
    'tax_class_id' => 0,
    'name' => 'Dynamic Product',
    'model' => 'DYN',
);
$lineA1 = $optionResolver->resolve($optionProduct, 1, array(10 => '100'), 'BGN', 'BGN');
$lineB1 = $optionResolver->resolve($optionProduct, 1, array(10 => '200'), 'BGN', 'BGN');
$lineB2 = $optionResolver->resolve($optionProduct, 2, array(10 => '200'), 'BGN', 'BGN');
mtuc8_assert(is_object($lineA1) && is_object($lineB1) && is_object($lineB2), 'option resolver lines present');
mtuc8_assert(abs($lineA1->financingPrice - 500.0) < 0.0001, 'option A × qty1 = 500');
mtuc8_assert(abs($lineB1->financingPrice - 700.0) < 0.0001, 'option B × qty1 = 700');
mtuc8_assert(abs($lineB2->financingPrice - 1400.0) < 0.0001, 'option B × qty2 = 1400');

$vmA1 = $presenter->presentProduct($shop, new MtUniCreditProductContext(9, array(7), $lineA1->financingPrice), 'BGN');
$vmB1 = $presenter->presentProduct($shop, new MtUniCreditProductContext(9, array(7), $lineB1->financingPrice), 'BGN');
$vmB2 = $presenter->presentProduct($shop, new MtUniCreditProductContext(9, array(7), $lineB2->financingPrice), 'BGN');
mtuc8_assert(is_array($vmA1) && is_array($vmB1) && is_array($vmB2), 'distinct option/qty states remain eligible');
mtuc8_assert(
    (float) $vmA1['price'] !== (float) $vmB1['price']
        && (float) $vmB1['price'] !== (float) $vmB2['price'],
    'presenter price tracks option/qty financing amounts'
);
mtuc8_assert(
    $vmA1['offers']['standard']['installment_label'] !== $vmB1['offers']['standard']['installment_label']
        || (float) $vmA1['offers']['standard']['monthly_installment']
        !== (float) $vmB1['offers']['standard']['monthly_installment'],
    'option change refreshes preferred monthly presentation'
);
mtuc8_assert(
    $vmB1['offers']['standard']['installment_label'] !== $vmB2['offers']['standard']['installment_label']
        || (float) $vmB1['offers']['standard']['monthly_installment']
        !== (float) $vmB2['offers']['standard']['monthly_installment'],
    'quantity change refreshes preferred monthly presentation'
);

// Eligibility threshold flip (hidden → visible / reverse)
$belowMin = $presenter->presentProduct($shop, new MtUniCreditProductContext(9, array(7), 1.0), 'BGN');
$aboveMin = $presenter->presentProduct($shop, new MtUniCreditProductContext(9, array(7), 500.0), 'BGN');
mtuc8_assert($belowMin === null, 'below minimum → no calculator (hidden/no buttons)');
mtuc8_assert(is_array($aboveMin) && !empty($aboveMin['offers']), 'above minimum → offers visible');
$aboveAgain = $presenter->presentProduct($shop, new MtUniCreditProductContext(9, array(7), 700.0), 'BGN');
$belowAgain = $presenter->presentProduct($shop, new MtUniCreditProductContext(9, array(7), 1.0), 'BGN');
mtuc8_assert(is_array($aboveAgain) && $belowAgain === null, 'eligibility can flip both directions');

// Preferred scheme identity can change with amount (when rules allow)
$keyA = $vmA1['offers']['standard']['preferred_scheme_key'];
$keyB = $vmB2['offers']['standard']['preferred_scheme_key'];
mtuc8_assert($keyA !== '' && $keyB !== '', 'preferred_scheme_key always set when eligible');
mtuc8_assert(
    $keyA !== $keyB
        || $vmA1['offers']['standard']['months'] !== $vmB2['offers']['standard']['months']
        || (float) $vmA1['offers']['standard']['monthly_installment']
        !== (float) $vmB2['offers']['standard']['monthly_installment'],
    'refreshed preferred presentation differs across product states'
);

// Same-month promo contract must survive dynamic amount refresh
$poolA = array();
foreach ($vmA1['offers']['standard']['schemes'] as $row) {
    $poolA[] = $row['key'];
}
$poolB = array();
foreach ($vmB1['offers']['standard']['schemes'] as $row) {
    $poolB[] = $row['key'];
}
mtuc8_assert(count($poolA) >= 1 && count($poolB) >= 1, 'first-button scheme pools remain after amount change');
if (isset($vmA1['offers']['promo']['schemes'], $vmB1['offers']['promo']['schemes'])) {
    foreach ($vmB1['offers']['promo']['schemes'] as $row) {
        mtuc8_assert(
            !empty($row['zero_interest_promo']) || $row['presentation_category'] === 'zero_promo',
            '0% pool after refresh stays zero-interest'
        );
    }
}

// Offer count structural change support (one vs two buttons when promo appears/disappears)
$countA = count($vmA1['offers']);
$countLow = is_array($belowMin) ? count($belowMin['offers']) : 0;
mtuc8_assert($countA >= 1 && $countLow === 0, 'button structure can go from zero to N offers');

// --- Client JS mechanics (source contract) ---
mtuc8_assert(strpos($jsPopup, 'function productContainer') !== false, 'JS productContainer for #product/#form-product');
mtuc8_assert(strpos($jsPopup, '#product') !== false, 'JS observes OC3 #product root');
mtuc8_assert(strpos($jsPopup, '[id^="input-option"]') !== false, 'JS Jet-like input-option listeners');
mtuc8_assert(strpos($jsPopup, '#input-quantity') !== false, 'JS observes #input-quantity');
mtuc8_assert(strpos($jsPopup, 'setTimeout(runCalculate, 250)') !== false, 'JS 250ms debounce for Product refresh');
mtuc8_assert(strpos($jsPopup, 'function renderOfferButtons') !== false, 'JS rebuilds offer buttons structurally');
mtuc8_assert(strpos($jsPopup, 'function applyCalculator') !== false, 'JS applyCalculator full refresh');
mtuc8_assert(strpos($jsPopup, 'function syncHeading') !== false, 'JS syncHeading for CP heading visibility');
mtuc8_assert(
    strpos($jsPopup, 'response.sequence') !== false && strpos($jsPopup, 'localSeq') !== false,
    'JS stale-response sequence guard'
);
mtuc8_assert(strpos($jsPopup, 'abortController.abort') !== false, 'JS aborts prior calculate request');
mtuc8_assert(strpos($jsPopup, 'data-mtuc-bound') !== false, 'JS idempotent root binding');
mtuc8_assert(strpos($jsPopup, 'mtucDocBound') !== false, 'JS document handlers bound once');
mtuc8_assert(strpos($jsPopup, 'mtucStale') !== false, 'JS degraded stale state blocks old clicks');
mtuc8_assert(strpos($jsPopup, 'response.unavailable') !== false, 'JS hides root when financing unavailable');
mtuc8_assert(
    strpos($jsPopup, 'option: form.option') !== false && strpos($jsPopup, 'quantity: form.quantity') !== false,
    'JS calculate posts current Product form state'
);
mtuc8_assert(
    strpos($jsPopup, '#form-product [name=\'quantity\']') === false,
    'JS no longer binds only legacy #form-product selectors'
);
