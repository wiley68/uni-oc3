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
 * @param array<string, string> $uploads code => name
 * @return MtUniCreditOc3ProductLineResolver
 */
function mtucAud019_resolver(array $uploads = array())
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
        },
        function ($code) use ($uploads) {
            $code = (string) $code;
            if (!isset($uploads[$code])) {
                return null;
            }

            return array(
                'code' => $code,
                'name' => $uploads[$code],
                'filename' => $uploads[$code] . '.bin',
            );
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
        array(
            'product_option_id' => 14,
            'name' => 'Note',
            'type' => 'text',
            'required' => 1,
            'product_option_value' => array(),
        ),
        array(
            'product_option_id' => 15,
            'name' => 'Attachment',
            'type' => 'file',
            'required' => 1,
            'product_option_value' => array(),
        ),
        array(
            'product_option_id' => 16,
            'name' => 'Weird',
            'type' => 'custom_unknown',
            'required' => 0,
            'product_option_value' => array(),
        ),
    );
}

/**
 * Production Apply quantity gate used by Product submit (no clamp).
 *
 * @param mixed $postedQty
 * @param int $minimum
 * @param int $submissionCalls
 * @return string PASS|BLOCK
 */
function mtucAud019_strictApplyQuantityGate($postedQty, $minimum, &$submissionCalls)
{
    $resolver = mtucAud019_resolver();
    try {
        $quantity = MtUniCreditOc3ProductLineResolver::parseStrictPostedQuantity($postedQty);
        $resolver->resolve(
            mtucAud019_productRow($minimum),
            $quantity,
            array(),
            'BGN',
            'BGN',
            null,
            array(),
            true
        );
        $submissionCalls++;

        return 'PASS';
    } catch (MtUniCreditProductLineValidationException $exception) {
        return 'BLOCK';
    }
}

$resolver = mtucAud019_resolver(array('valid-upload-token' => 'photo.jpg'));
$defs = mtucAud019_productOptions();
$product = mtucAud019_productRow(1);

// ---------------------------------------------------------------------------
// F01 — option matrix (production resolver)
// ---------------------------------------------------------------------------
$blocked = array(
    'required select missing' => array(11 => 300, 12 => array(400), 14 => 'note', 15 => 'valid-upload-token'),
    'required radio missing' => array(10 => 100, 12 => array(400), 14 => 'note', 15 => 'valid-upload-token'),
    'required checkbox missing' => array(10 => 100, 11 => 300, 14 => 'note', 15 => 'valid-upload-token'),
    'required text missing' => array(10 => 100, 11 => 300, 12 => array(400), 15 => 'valid-upload-token'),
    'required file missing' => array(10 => 100, 11 => 300, 12 => array(400), 14 => 'note'),
    'invalid option value ID' => array(10 => 999, 11 => 300, 12 => array(400), 14 => 'note', 15 => 'valid-upload-token'),
    'foreign value under another option' => array(10 => 300, 11 => 300, 12 => array(400), 14 => 'note', 15 => 'valid-upload-token'),
    'numeric invalid must not become text' => array(10 => 12345, 11 => 300, 12 => array(400), 14 => 'note', 15 => 'valid-upload-token'),
    'file fabricated token' => array(10 => 100, 11 => 300, 12 => array(400), 14 => 'note', 15 => 'fabricated-token'),
    'unknown option type' => array(
        10 => 100,
        11 => 300,
        12 => array(400),
        14 => 'note',
        15 => 'valid-upload-token',
        16 => 'anything',
    ),
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

$validOpts = array(
    10 => 100,
    11 => 300,
    12 => array(400),
    13 => 'Hello',
    14 => 'Required note',
    15 => 'valid-upload-token',
);
$validLine = $resolver->resolve($product, 1, $validOpts, 'BGN', 'BGN', null, $defs, true);
mtucAud019_assert(
    is_object($validLine) && abs($validLine->financingPrice - 110.0) < 0.0001,
    'F01 PASS: valid select/radio/checkbox + free-text + native file token'
);

$fileTypes = array();
foreach ($validLine->options as $opt) {
    if (isset($opt['type']) && $opt['type'] === 'file') {
        $fileTypes[] = (string) $opt['value'];
    }
}
mtucAud019_assert(
    in_array('valid-upload-token', $fileTypes, true),
    'R2 PASS: valid native upload token retained as file type'
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
// F02 / R1 — minimum + raw qty=0 matrix (production Apply gate, no clamp)
// ---------------------------------------------------------------------------
$minMatrix = array(
    array(1, 0, false),
    array(1, 1, true),
    array(2, 1, false),
    array(2, 2, true),
    array(3, 1, false),
    array(3, 3, true),
    array(3, 5, true),
);
foreach ($minMatrix as $row) {
    list($minimum, $qty, $expectPass) = $row;
    $submissionCalls = 0;
    $outcome = mtucAud019_strictApplyQuantityGate($qty, $minimum, $submissionCalls);
    if ($expectPass) {
        mtucAud019_assert(
            $outcome === 'PASS' && $submissionCalls === 1,
            'R1/F02 PASS: minimum=' . $minimum . ' qty=' . $qty
        );
    } else {
        mtucAud019_assert(
            $outcome === 'BLOCK' && $submissionCalls === 0,
            'R1/F02 BLOCK: minimum=' . $minimum . ' qty=' . $qty . ' (submissionCalls=0)'
        );
    }
}

// Controller bypass sensitivity: qty=0/minimum=1 must BLOCK via production parse path.
$submitCallsZero = 0;
mtucAud019_assert(
    mtucAud019_strictApplyQuantityGate(0, 1, $submitCallsZero) === 'BLOCK'
        && $submitCallsZero === 0,
    'R1 controller-path: posted qty=0 minimum=1 BLOCK before submission'
);
mtucAud019_assert(
    MtUniCreditOc3ProductLineResolver::parseStrictPostedQuantity(1) === 1,
    'R1 parseStrictPostedQuantity(1)=1'
);
$caughtZero = null;
try {
    MtUniCreditOc3ProductLineResolver::parseStrictPostedQuantity(0);
} catch (MtUniCreditProductLineValidationException $e) {
    $caughtZero = $e;
}
mtucAud019_assert(
    $caughtZero instanceof MtUniCreditProductLineValidationException,
    'R1 parseStrictPostedQuantity(0) throws'
);

// ---------------------------------------------------------------------------
// R1A — canonical quantity grammar (no trim / no normalization)
// ---------------------------------------------------------------------------
$qtySyntax = array(
    '0' => false, // syntactic ok then Apply BLOCK (<1) — parse throws
    '1' => true,
    '01' => false,
    '+1' => false,
    '-1' => false,
    '1.0' => false,
    '1abc' => false,
    ' 1' => false,
    '1 ' => false,
    '' => false,
    "\t1" => false,
    "1\n" => false,
    ' 01 ' => false,
);
foreach ($qtySyntax as $raw => $expectPass) {
    $caught = null;
    $parsed = null;
    try {
        $parsed = MtUniCreditOc3ProductLineResolver::parseStrictPostedQuantity($raw);
    } catch (MtUniCreditProductLineValidationException $e) {
        $caught = $e;
    }
    if ($expectPass) {
        mtucAud019_assert(
            $caught === null && $parsed === 1,
            'R1A PASS raw=' . json_encode($raw)
        );
    } else {
        mtucAud019_assert(
            $caught instanceof MtUniCreditProductLineValidationException,
            'R1A BLOCK raw=' . json_encode($raw)
        );
    }
}
$resolverSrc = mtucAud019_read($lib . '/oc3_product_line_resolver.php');
mtucAud019_assert(
    preg_match(
        '/function parseStrictPostedQuantity\([\s\S]*?trim\s*\(\s*\(string\)\s*\$raw\s*\)/',
        $resolverSrc
    ) !== 1,
    'R1A residual: parseStrictPostedQuantity must not trim()'
);
mtucAud019_assert(
    strpos($resolverSrc, "preg_match('/\\A(0|[1-9][0-9]*)\\z/', \$s)") !== false
        || preg_match('/preg_match\\(\'\\/\\\\A\\(0\\|\\[1-9\\]\\[0-9\\]\\*\\)\\\\z\\/\'/', $resolverSrc) === 1,
    'R1A residual: canonical quantity regex uses \\A...\\z'
);

// Whitespace Apply side-effect barrier (submissionCalls=0)
foreach (array(' 1', '1 ', "\t1", '0') as $blockedQty) {
    $calls = 0;
    mtucAud019_assert(
        mtucAud019_strictApplyQuantityGate($blockedQty, 1, $calls) === 'BLOCK' && $calls === 0,
        'R1A side-effect: raw=' . json_encode($blockedQty) . ' → submission=0'
    );
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
        && strpos($productCtrl, 'parseStrictPostedQuantity') !== false,
    'F01/F02/R1 static: submit uses parseStrictPostedQuantity + strict resolveProductLine'
);

// R1 residual: submit must not clamp quantity with max(1, ...) before validation.
if (!preg_match(
    '/public function submit\(\)\s*\{(?P<body>.*?)\n    private function buildProductSubmitInput/s',
    $productCtrl,
    $submitMatch
)) {
    $submitMatch = array('body' => '');
}
$submitBody = isset($submitMatch['body']) ? $submitMatch['body'] : '';
mtucAud019_assert(
    $submitBody !== ''
        && strpos($submitBody, 'parseStrictPostedQuantity') !== false
        && strpos($submitBody, 'max(1,') === false,
    'R1 residual: submit has no max(1, posted quantity) clamp bypass'
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
mtucAud019_assert(
    strpos($runtimeSrc, 'lookupNativeUploadByCode') !== false
        && strpos($runtimeSrc, 'isset($self->model_tool_upload)') === false
        && strpos($runtimeSrc, 'isset($controller->model_tool_upload)') === false,
    'R2A residual: upload loader uses lookupNativeUploadByCode without isset(model_tool_upload)'
);
mtucAud019_assert(
    preg_match(
        '/function lookupNativeUploadByCode\([\s\S]*?\$model\s*=\s*\$controller->model_tool_upload/s',
        $runtimeSrc
    ) === 1,
    'R2A residual: OC3-compatible model_tool_upload assignment via __get'
);
mtucAud019_assert(
    strpos($runtimeSrc, 'CODE_PRODUCT_OPTIONS_UNAVAILABLE') !== false
        || strpos($runtimeSrc, 'product_options_unavailable') !== false,
    'R3 static: strict definitions-load failure maps to product_options_unavailable'
);
mtucAud019_assert(
    preg_match(
        '/catch \(Exception \$exception\) \{\s*if \(\$strict\) \{\s*throw new MtUniCreditProductLineValidationException/s',
        $runtimeSrc
    ) === 1,
    'R3 residual: getProductOptions exception fail-closed in strict (no empty legacy fallback)'
);

// R3 functional: strict + unavailable definitions → BLOCK (no legacy).
$defsFail = null;
try {
    $resolver->resolve($product, 1, array(), 'BGN', 'BGN', null, null, true);
} catch (MtUniCreditProductLineValidationException $e) {
    $defsFail = $e;
}
mtucAud019_assert(
    $defsFail instanceof MtUniCreditProductLineValidationException
        && $defsFail->errorCode()
        === MtUniCreditProductLineValidationException::CODE_PRODUCT_OPTIONS_UNAVAILABLE,
    'R3 BLOCK: strict definitions unavailable'
);

// Non-strict may still use legacy when definitions are null.
$legacyOk = $resolver->resolve($product, 1, array(), 'BGN', 'BGN', null, null, false);
mtucAud019_assert(is_object($legacyOk), 'R3 legacy: non-strict null definitions still resolve');

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
    preg_match(
        '/action === ["\']buy["\'][\s\S]{0,200}?buySubmitInFlight\s*=\s*true[\s\S]{0,200}?postJson\s*\(\s*\$root\.attr\(\s*["\']data-route-stash["\']\s*\)/s',
        $js
    ) === 1,
    'R4 residual: buySubmitInFlight=true before stash postJson'
);
mtucAud019_assert(
    preg_match(
        '/action === ["\']buy["\'][\s\S]{0,200}?postJson\s*\(\s*\$root\.attr\(\s*["\']data-route-stash["\']\s*\)[\s\S]{0,200}?buySubmitInFlight\s*=\s*true/s',
        $js
    ) !== 1,
    'R4 residual: stash postJson must not precede buySubmitInFlight lock'
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

// ---------------------------------------------------------------------------
// R4B — production runtime upload adapter (OC3 magic __get, no isset)
// ---------------------------------------------------------------------------
if (!class_exists('MtucAud019Registry', false)) {
    final class MtucAud019Registry
    {
        /** @var array<string, mixed> */
        private $data = array();

        /**
         * @param string $key
         * @param mixed $value
         * @return void
         */
        public function set($key, $value)
        {
            $this->data[$key] = $value;
        }

        /**
         * @param string $key
         * @return mixed
         */
        public function get($key)
        {
            return array_key_exists($key, $this->data) ? $this->data[$key] : null;
        }
    }
}
if (!class_exists('MtucAud019UploadModel', false)) {
    final class MtucAud019UploadModel
    {
        /** @var array<string, array<string, mixed>> */
        private $rows;

        /**
         * @param array<string, array<string, mixed>> $rows
         */
        public function __construct(array $rows)
        {
            $this->rows = $rows;
        }

        /**
         * @param string $code
         * @return array<string, mixed>
         */
        public function getUploadByCode($code)
        {
            $code = (string) $code;

            return isset($this->rows[$code]) ? $this->rows[$code] : array();
        }
    }
}
if (!class_exists('MtucAud019Loader', false)) {
    final class MtucAud019Loader
    {
        /** @var MtucAud019Registry */
        private $registry;
        /** @var MtucAud019UploadModel */
        private $uploadModel;

        /**
         * @param MtucAud019Registry $registry
         * @param MtucAud019UploadModel $uploadModel
         */
        public function __construct(MtucAud019Registry $registry, MtucAud019UploadModel $uploadModel)
        {
            $this->registry = $registry;
            $this->uploadModel = $uploadModel;
        }

        /**
         * @param string $route
         * @return void
         */
        public function model($route)
        {
            if ($route === 'tool/upload') {
                // Native OC3 Loader registers model_* on the Registry.
                $this->registry->set('model_tool_upload', $this->uploadModel);
            }
        }
    }
}
if (!class_exists('MtucAud019ControllerHost', false)) {
    /**
     * Models OC3 Controller: __get without __isset (isset(model_*) is unreliable).
     */
    final class MtucAud019ControllerHost
    {
        /** @var MtucAud019Registry */
        private $registry;

        public function __construct(MtucAud019Registry $registry)
        {
            $this->registry = $registry;
        }

        /**
         * @param string $key
         * @return mixed
         */
        public function __get($key)
        {
            return $this->registry->get($key);
        }

        /**
         * @param string $key
         * @param mixed $value
         * @return void
         */
        public function __set($key, $value)
        {
            $this->registry->set($key, $value);
        }
    }
}

$uploadReg = new MtucAud019Registry();
$uploadModel = new MtucAud019UploadModel(array(
    'native-valid-code' => array(
        'code' => 'native-valid-code',
        'name' => 'photo.jpg',
        'filename' => 'photo.jpg',
    ),
    'malformed-empty-code' => array(
        'name' => 'broken.bin',
        'filename' => 'broken.bin',
    ),
));
$uploadReg->set('load', new MtucAud019Loader($uploadReg, $uploadModel));
$uploadHost = new MtucAud019ControllerHost($uploadReg);

// Prove isset trap: magic property is not a real property.
mtucAud019_assert(
    !isset($uploadHost->model_tool_upload),
    'R4B fixture: isset(model_tool_upload) is false before/after load (OC3 trap)'
);
$validUpload = MtUniCreditStorefrontRuntime::lookupNativeUploadByCode($uploadHost, 'native-valid-code');
mtucAud019_assert(
    is_array($validUpload) && (string) $validUpload['code'] === 'native-valid-code',
    'R4B PASS: production lookupNativeUploadByCode(valid) via load->model + __get'
);
mtucAud019_assert(
    !isset($uploadHost->model_tool_upload) && is_object($uploadHost->model_tool_upload),
    'R4B: model available via __get while isset remains false'
);
$fabricatedUpload = MtUniCreditStorefrontRuntime::lookupNativeUploadByCode($uploadHost, 'fabricated-token');
mtucAud019_assert(
    $fabricatedUpload === null,
    'R4B BLOCK: fabricated upload code lookup miss'
);
$malformedUpload = MtUniCreditStorefrontRuntime::lookupNativeUploadByCode($uploadHost, 'malformed-empty-code');
mtucAud019_assert(
    $malformedUpload === null,
    'R4B BLOCK: malformed lookup record without code'
);

// Resolver + production adapter loader (not a test-local closure bypass).
$runtimeBackedResolver = new MtUniCreditOc3ProductLineResolver(
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
        return null;
    },
    function ($code) use ($uploadHost) {
        return MtUniCreditStorefrontRuntime::lookupNativeUploadByCode($uploadHost, $code);
    }
);
$fileOnlyDefs = array(
    array(
        'product_option_id' => 15,
        'name' => 'Attachment',
        'type' => 'file',
        'required' => 1,
        'product_option_value' => array(),
    ),
);
$filePass = $runtimeBackedResolver->resolve(
    mtucAud019_productRow(1),
    1,
    array(15 => 'native-valid-code'),
    'BGN',
    'BGN',
    null,
    $fileOnlyDefs,
    true
);
mtucAud019_assert(
    is_object($filePass)
        && isset($filePass->options[0]['type'])
        && $filePass->options[0]['type'] === 'file'
        && $filePass->options[0]['value'] === 'native-valid-code',
    'R4B PASS: strict Apply accepts file via production runtime adapter'
);
$fileBlock = null;
try {
    $runtimeBackedResolver->resolve(
        mtucAud019_productRow(1),
        1,
        array(15 => 'fabricated-token'),
        'BGN',
        'BGN',
        null,
        $fileOnlyDefs,
        true
    );
} catch (MtUniCreditProductLineValidationException $e) {
    $fileBlock = $e;
}
mtucAud019_assert(
    $fileBlock instanceof MtUniCreditProductLineValidationException
        && $fileBlock->errorCode() === MtUniCreditProductLineValidationException::CODE_INVALID_OPTION,
    'R4B BLOCK: fabricated file via production runtime adapter'
);

// ---------------------------------------------------------------------------
// R4C — real Product controller submit path (qty=0 / minimum=1)
// ---------------------------------------------------------------------------
if (!class_exists('Controller', false)) {
    abstract class Controller
    {
        /** @var MtucAud019Registry */
        protected $registry;

        public function __construct($registry)
        {
            $this->registry = $registry;
        }

        /**
         * @param string $key
         * @return mixed
         */
        public function __get($key)
        {
            return $this->registry->get($key);
        }

        /**
         * @param string $key
         * @param mixed $value
         * @return void
         */
        public function __set($key, $value)
        {
            $this->registry->set($key, $value);
        }
    }
}
if (!class_exists('MtucAud019Language', false)) {
    final class MtucAud019Language
    {
        /**
         * @param string $key
         * @return string
         */
        public function get($key)
        {
            return (string) $key;
        }
    }
}
if (!class_exists('MtucAud019Config', false)) {
    final class MtucAud019Config
    {
        /**
         * @param string $key
         * @return mixed
         */
        public function get($key)
        {
            $map = array(
                'config_store_id' => 0,
                'config_currency' => 'BGN',
                'config_language_id' => 1,
                'config_customer_group_id' => 1,
            );

            return isset($map[$key]) ? $map[$key] : null;
        }
    }
}
if (!class_exists('MtucAud019Response', false)) {
    final class MtucAud019Response
    {
        /** @var string */
        public $output = '';

        /**
         * @param string $header
         * @return void
         */
        public function addHeader($header) {}

        /**
         * @param string $output
         * @return void
         */
        public function setOutput($output)
        {
            $this->output = (string) $output;
        }
    }
}
if (!class_exists('MtucAud019Session', false)) {
    final class MtucAud019Session
    {
        /** @var array<string, mixed> */
        public $data = array();
    }
}
if (!class_exists('MtucAud019Request', false)) {
    final class MtucAud019Request
    {
        /** @var array<string, mixed> */
        public $post = array();
        /** @var array<string, mixed> */
        public $get = array();
        /** @var array<string, mixed> */
        public $server = array();
    }
}
if (!class_exists('MtucAud019Db', false)) {
    final class MtucAud019Db
    {
        /**
         * @param string $value
         * @return string
         */
        public function escape($value)
        {
            return addslashes((string) $value);
        }

        /**
         * @param string $sql
         * @return object
         */
        public function query($sql)
        {
            return (object) array('num_rows' => 0, 'row' => array(), 'rows' => array());
        }
    }
}
if (!class_exists('MtucAud019ProductLoad', false)) {
    final class MtucAud019ProductLoad
    {
        /**
         * @param string $route
         * @return void
         */
        public function language($route) {}

        /**
         * @param string $route
         * @return void
         */
        public function model($route) {}
    }
}

require_once $root . '/upload/catalog/controller/extension/mt_uni_credit/product.php';

$ctrlReg = new MtucAud019Registry();
$sessionObj = new MtucAud019Session();
$csrf = MtUniCreditStorefrontCsrf::issue($sessionObj->data);
$req = new MtucAud019Request();
$req->server['REQUEST_METHOD'] = 'POST';
$req->post = array(
    'csrf' => $csrf,
    'consent' => '1',
    'product_id' => '9',
    'quantity' => '0',
    'scheme_key' => 'standard|STD|12',
    'firstname' => 'Ivan',
    'lastname' => 'Petrov',
    'email' => 'ivan@example.com',
    'telephone' => '0888123456',
    'address_1' => 'Test Street 1',
);
$resp = new MtucAud019Response();
$ctrlReg->set('session', $sessionObj);
$ctrlReg->set('request', $req);
$ctrlReg->set('response', $resp);
$ctrlReg->set('language', new MtucAud019Language());
$ctrlReg->set('config', new MtucAud019Config());
$ctrlReg->set('db', new MtucAud019Db());
$ctrlReg->set('load', new MtucAud019ProductLoad());
$ctrlReg->set('customer', new class {
    public function isLogged()
    {
        return false;
    }

    public function getId()
    {
        return 0;
    }
});

$productCtrlObj = new ControllerExtensionMtUniCreditProduct($ctrlReg);
$productCtrlObj->submit();
$jsonOut = json_decode($resp->output, true);
mtucAud019_assert(
    is_array($jsonOut)
        && isset($jsonOut['error'])
        && (string) $jsonOut['error'] === 'quantity_below_minimum'
        && empty($jsonOut['success']),
    'R4C controller submit: qty=0 → quantity_below_minimum (no clamp bypass)'
);
mtucAud019_assert(
    !empty($jsonOut['cart_unchanged']),
    'R4C controller submit: cart_unchanged on quantity BLOCK'
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
