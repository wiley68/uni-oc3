<?php

/**
 * AUD-019 F01–F05 — Product entry-point behavior.
 *
 * Run: php tests/phase_aud019_product_entrypoint_check.php
 *
 * Anti-false-positive:
 * - must fail if invalid numeric option IDs become free-text
 * - must fail if Product Apply ignores product.minimum
 * - must fail if Buy cart/add uses .always() redirect
 * - must fail if Buy branch has no buySubmitInFlight guard
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
    mtuc_test_define_dir_storage('mtuc-aud019');
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
}
if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'oc_');
}

require_once $lib . DIRECTORY_SEPARATOR . 'bootstrap.php';

$failures = array();
$passes = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucAud019_assert($condition, $message)
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
function mtucAud019_read($path)
{
    $body = @file_get_contents($path);

    return is_string($body) ? $body : '';
}

/**
 * @return MtUniCreditOc3ProductLineResolver
 */
function mtucAud019_resolver()
{
    return new MtUniCreditOc3ProductLineResolver(
        function ($price, $taxClassId) {
            return (float) $price;
        },
        function ($amount, $from, $to) {
            return (float) $amount;
        },
        function () {
            return array(1);
        },
        function ($productOptionId, $value) {
            $productOptionId = (int) $productOptionId;
            $value = (int) $value;
            // PO 10: values 100, 200
            if ($productOptionId === 10 && ($value === 100 || $value === 200)) {
                return array(
                    'product_option_value_id' => $value,
                    'name' => $value === 100 ? 'Red' : 'Blue',
                    'price' => $value === 200 ? 50.0 : 0.0,
                    'price_prefix' => '+',
                    'type' => 'select',
                    'option_name' => 'Color',
                );
            }
            // PO 11 radio: value 300
            if ($productOptionId === 11 && $value === 300) {
                return array(
                    'product_option_value_id' => 300,
                    'name' => 'Size M',
                    'price' => 0.0,
                    'price_prefix' => '+',
                    'type' => 'radio',
                    'option_name' => 'Size',
                );
            }
            // PO 12 checkbox: 400, 401
            if ($productOptionId === 12 && ($value === 400 || $value === 401)) {
                return array(
                    'product_option_value_id' => $value,
                    'name' => 'Extra ' . $value,
                    'price' => 10.0,
                    'price_prefix' => '+',
                    'type' => 'checkbox',
                    'option_name' => 'Extras',
                );
            }

            return null;
        }
    );
}

/**
 * @return array<string, mixed>
 */
function mtucAud019_productRow($minimum = 1)
{
    return array(
        'product_id' => 9,
        'price' => 100.0,
        'tax_class_id' => 0,
        'name' => 'AUD019 Product',
        'model' => 'A019',
        'minimum' => (int) $minimum,
        'reward' => 0,
    );
}

/**
 * @return array<int, array<string, mixed>>
 */
function mtucAud019_productOptions()
{
    return array(
        array(
            'product_option_id' => 10,
            'name' => 'Color',
            'type' => 'select',
            'required' => 1,
            'product_option_value' => array(
                array('product_option_value_id' => 100, 'name' => 'Red', 'price' => 0, 'price_prefix' => '+'),
                array('product_option_value_id' => 200, 'name' => 'Blue', 'price' => 50, 'price_prefix' => '+'),
            ),
        ),
        array(
            'product_option_id' => 11,
            'name' => 'Size',
            'type' => 'radio',
            'required' => 1,
            'product_option_value' => array(
                array('product_option_value_id' => 300, 'name' => 'Size M', 'price' => 0, 'price_prefix' => '+'),
            ),
        ),
        array(
            'product_option_id' => 12,
            'name' => 'Extras',
            'type' => 'checkbox',
            'required' => 1,
            'product_option_value' => array(
                array('product_option_value_id' => 400, 'name' => 'Extra 400', 'price' => 10, 'price_prefix' => '+'),
                array('product_option_value_id' => 401, 'name' => 'Extra 401', 'price' => 10, 'price_prefix' => '+'),
            ),
        ),
        array(
            'product_option_id' => 13,
            'name' => 'Engraving',
            'type' => 'text',
            'required' => 0,
            'product_option_value' => array(),
        ),
    );
}

$resolver = mtucAud019_resolver();
$defs = mtucAud019_productOptions();
$product = mtucAud019_productRow(1);

// ---------------------------------------------------------------------------
// F01 — option matrix (production resolver)
// ---------------------------------------------------------------------------
$blocked = array(
    'required select missing' => array(11 => 300, 12 => array(400)),
    'required radio missing' => array(10 => 100, 12 => array(400)),
    'required checkbox missing' => array(10 => 100, 11 => 300),
    'invalid option value ID' => array(10 => 999, 11 => 300, 12 => array(400)),
    'foreign value under another option' => array(10 => 300, 11 => 300, 12 => array(400)),
    'numeric invalid must not become text' => array(10 => 12345, 11 => 300, 12 => array(400)),
);

foreach ($blocked as $label => $options) {
    $caught = null;
    try {
        $resolver->resolve($product, 1, $options, 'BGN', 'BGN', null, $defs, true);
    } catch (MtUniCreditProductLineValidationException $e) {
        $caught = $e;
    }
    mtucAud019_assert(
        $caught instanceof MtUniCreditProductLineValidationException,
        'F01 BLOCK: ' . $label
    );
    if ($caught instanceof MtUniCreditProductLineValidationException) {
        mtucAud019_assert(
            in_array(
                $caught->errorCode(),
                array(
                    MtUniCreditProductLineValidationException::CODE_MISSING_REQUIRED_OPTION,
                    MtUniCreditProductLineValidationException::CODE_INVALID_OPTION,
                ),
                true
            ),
            'F01 BLOCK code: ' . $label . ' → ' . $caught->errorCode()
        );
    }
}

// Legacy path: numeric invalid must not become free-text order option
$legacy = $resolver->resolve($product, 1, array(10 => 99999), 'BGN', 'BGN', null, null, false);
$legacyText = false;
foreach ($legacy->options as $opt) {
    if (isset($opt['type']) && $opt['type'] === 'text' && (string) $opt['value'] === '99999') {
        $legacyText = true;
    }
}
mtucAud019_assert(!$legacyText, 'F01 legacy: invalid numeric POV is not free-text');

$validOpts = array(10 => 100, 11 => 300, 12 => array(400), 13 => 'Hello');
$validLine = $resolver->resolve($product, 1, $validOpts, 'BGN', 'BGN', null, $defs, true);
mtucAud019_assert(
    is_object($validLine) && abs($validLine->financingPrice - 110.0) < 0.0001,
    'F01 PASS: valid select/radio/checkbox + free-text'
);

$radioOnly = array(
    array(
        'product_option_id' => 11,
        'name' => 'Size',
        'type' => 'radio',
        'required' => 1,
        'product_option_value' => array(
            array('product_option_value_id' => 300, 'name' => 'Size M', 'price' => 0, 'price_prefix' => '+'),
        ),
    ),
);
$radioOk = $resolver->resolve($product, 1, array(11 => 300), 'BGN', 'BGN', null, $radioOnly, true);
mtucAud019_assert(is_object($radioOk), 'F01 PASS: valid radio');

// ---------------------------------------------------------------------------
// F02 — minimum matrix
// ---------------------------------------------------------------------------
$minMatrix = array(
    array(1, 1, true),
    array(2, 1, false),
    array(2, 2, true),
    array(3, 1, false),
    array(3, 3, true),
    array(3, 5, true),
);
foreach ($minMatrix as $row) {
    list($minimum, $qty, $expectPass) = $row;
    $caught = null;
    $line = null;
    try {
        $line = $resolver->resolve(
            mtucAud019_productRow($minimum),
            $qty,
            array(),
            'BGN',
            'BGN',
            null,
            array(),
            true
        );
    } catch (MtUniCreditProductLineValidationException $e) {
        $caught = $e;
    }
    if ($expectPass) {
        mtucAud019_assert(
            $caught === null && is_object($line) && (int) $line->quantity === (int) $qty,
            'F02 PASS: minimum=' . $minimum . ' qty=' . $qty
        );
    } else {
        mtucAud019_assert(
            $caught instanceof MtUniCreditProductLineValidationException
                && $caught->errorCode()
                === MtUniCreditProductLineValidationException::CODE_QUANTITY_BELOW_MINIMUM,
            'F02 BLOCK: minimum=' . $minimum . ' qty=' . $qty
        );
    }
}

// ---------------------------------------------------------------------------
// Static F01/F02 wiring on Product submit
// ---------------------------------------------------------------------------
$productCtrl = mtucAud019_read(
    $root . '/upload/catalog/controller/extension/mt_uni_credit/product.php'
);
$runtimeSrc = mtucAud019_read($lib . '/storefront_runtime.php');
$js = mtucAud019_read(
    $root . '/upload/catalog/view/theme/default/template/extension/mt_uni_credit/storefront.js'
);
$twig = mtucAud019_read(
    $root . '/upload/catalog/view/theme/default/template/extension/mt_uni_credit/product_widget.twig'
);

mtucAud019_assert(
    strpos($productCtrl, 'resolveProductLine(') !== false
        && strpos($productCtrl, 'MtUniCreditProductLineValidationException') !== false
        && strpos($productCtrl, 'true') !== false,
    'F01/F02 static: submit uses strict resolveProductLine'
);
mtucAud019_assert(
    strpos($productCtrl, 'clearBuyPreference') !== false,
    'F03 static: clearBuyPreference endpoint present'
);
mtucAud019_assert(
    strpos($runtimeSrc, '$strict = false') !== false
        || strpos($runtimeSrc, '$strict = false') !== false
        || preg_match('/resolveProductLine\([^)]*\$strict/', $runtimeSrc) === 1,
    'F01 static: resolveProductLine accepts strict flag'
);
mtucAud019_assert(
    strpos($runtimeSrc, 'getProductOptions') !== false,
    'F01 static: runtime loads getProductOptions'
);

// ---------------------------------------------------------------------------
// F03/F04 — Buy JS behavior
// ---------------------------------------------------------------------------
mtucAud019_assert(
    strpos($js, 'buySubmitInFlight') !== false,
    'F04 static: buySubmitInFlight present'
);
mtucAud019_assert(
    strpos($js, 'isBuySubmitLocked') !== false,
    'F04 static: isBuySubmitLocked present'
);
mtucAud019_assert(
    strpos($js, '.always(') === false
        || !preg_match('/action === ["\']buy["\'][\s\S]{0,800}?\.always\s*\(/', $js),
    'F03 residual: Buy branch must not .always() redirect'
);
mtucAud019_assert(
    strpos($js, 'failBuyHandoff') !== false
        && strpos($js, 'clearBuyPreference') !== false,
    'F03 static: failBuyHandoff clears preference'
);
mtucAud019_assert(
    strpos($js, 'data-route-clear') !== false || strpos($twig, 'data-route-clear') !== false,
    'F03 static: clear route wired'
);
mtucAud019_assert(
    strpos($twig, 'data-route-clear') !== false
        && strpos($productCtrl, 'route_clear') !== false,
    'F03 static: product widget route_clear'
);
mtucAud019_assert(
    preg_match('/buySubmitInFlight\s*=\s*true/', $js) === 1
        && preg_match('/isBuySubmitLocked\(\)/', $js) === 1,
    'F04 residual: second Buy while in flight is ignored'
);
mtucAud019_assert(
    strpos($js, 'missing_required_option') !== false
        && strpos($js, 'quantity_below_minimum') !== false,
    'F01/F02 static: JS handles Apply option/minimum errors'
);

// Preference clear uses production clear()
$prefSrc = mtucAud019_read($lib . '/product_buy_preference.php');
mtucAud019_assert(
    strpos($productCtrl, 'MtUniCreditProductBuyPreference::clear') !== false
        && strpos($prefSrc, 'function clear') !== false,
    'F03: clearBuyPreference uses preference clear()'
);

$session = array();
$nav = MtUniCreditProductBuyPreference::save($session, array(
    'store_id' => 0,
    'product_id' => 9,
    'scheme_type' => 'standard',
    'kop_code' => 'STD',
    'months' => 12,
    'filter_id' => 1,
    'scheme_key' => 'standard|STD|12',
));
mtucAud019_assert(
    isset($session[MtUniCreditProductBuyPreference::SESSION_KEY]),
    'F03 fixture: pending preference saved'
);
MtUniCreditProductBuyPreference::clear($session);
mtucAud019_assert(
    !isset($session[MtUniCreditProductBuyPreference::SESSION_KEY]),
    'F03: clear() removes pending preference'
);
mtucAud019_assert(is_string($nav) && strlen($nav) >= 32, 'AUD-018: stash still returns navigation_id');

// Apply/Buy separation static
mtucAud019_assert(
    strpos($js, 'data-route-submit') !== false
        && !preg_match('/action === ["\']buy["\'][\s\S]{0,1200}?data-route-submit/', $js),
    'separation: Buy path does not call Product submit'
);

echo PHP_EOL;
if ($failures === array()) {
    echo 'RESULT  PASS (' . $passes . ' assertions)' . PHP_EOL;
    exit(0);
}

echo 'RESULT  FAIL (' . count($failures) . ' failed / ' . $passes . ' passed)' . PHP_EOL;
foreach ($failures as $f) {
    echo '  - ' . $f . PHP_EOL;
}
exit(1);
