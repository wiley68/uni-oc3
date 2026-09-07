<?php

/**
 * AUD-010 F02 (+ R1/R2) — field-level validation UX + BG/EN localization.
 * Run: php tests/phase_aud010_f02_validation_ux_localization_check.php
 *
 * R1: behavioral DOM proofs execute production storefront.js via Node/jsdom
 *     (tests/support/aud010_f02_jsdom/run_production_js_dom_proof.js).
 * R2: validators must not embed a parallel Bulgarian customer-facing catalogue.
 *
 * PHP 7.3 compatible. Offline after npm install of the jsdom harness deps.
 */
require_once __DIR__ . '/bootstrap.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';
$catalog = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog';
$jsPath = $catalog . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'theme' . DIRECTORY_SEPARATOR
    . 'default' . DIRECTORY_SEPARATOR . 'template' . DIRECTORY_SEPARATOR . 'extension'
    . DIRECTORY_SEPARATOR . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'storefront.js';
$modalPath = $catalog . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'theme' . DIRECTORY_SEPARATOR
    . 'default' . DIRECTORY_SEPARATOR . 'template' . DIRECTORY_SEPARATOR . 'extension'
    . DIRECTORY_SEPARATOR . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'modal.twig';
$productCtrl = $catalog . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'extension'
    . DIRECTORY_SEPARATOR . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'product.php';
$cartCtrl = $catalog . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'extension'
    . DIRECTORY_SEPARATOR . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'cart.php';
$applicantPath = $lib . DIRECTORY_SEPARATOR . 'storefront_applicant_field_validator.php';
$processTwoPath = $lib . DIRECTORY_SEPARATOR . 'storefront_process_two_field_validator.php';
$jsdomDir = __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'aud010_f02_jsdom';
$jsdomProof = $jsdomDir . DIRECTORY_SEPARATOR . 'run_production_js_dom_proof.js';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
require_once $lib . DIRECTORY_SEPARATOR . 'bootstrap.php';

$failures = array();
$passes = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucAud010F02_assert($condition, $message)
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
 * @return array<string, string>
 */
function mtucAud010F02_loadLang($path)
{
    $_ = array();
    require $path;

    return $_;
}

$requiredKeys = array(
    'error_validation',
    'error_validation_incomplete',
    'error_request_failed',
    'error_field_required',
    'error_name_length',
    'error_address_length',
    'error_phone_length',
    'error_phone_invalid',
    'error_email_invalid',
    'error_email_length',
    'error_invalid_characters',
    'error_egn_invalid',
    'error_phone2_invalid',
);

$bgProduct = mtucAud010F02_loadLang($catalog . '/language/bg-bg/extension/mt_uni_credit/product.php');
$enProduct = mtucAud010F02_loadLang($catalog . '/language/en-gb/extension/mt_uni_credit/product.php');
$bgCart = mtucAud010F02_loadLang($catalog . '/language/bg-bg/extension/mt_uni_credit/cart.php');
$enCart = mtucAud010F02_loadLang($catalog . '/language/en-gb/extension/mt_uni_credit/cart.php');

foreach ($requiredKeys as $key) {
    mtucAud010F02_assert(isset($bgProduct[$key]) && $bgProduct[$key] !== '', 'BG product has ' . $key);
    mtucAud010F02_assert(isset($enProduct[$key]) && $enProduct[$key] !== '', 'EN product has ' . $key);
    mtucAud010F02_assert(isset($bgCart[$key]) && $bgCart[$key] !== '', 'BG cart has ' . $key);
    mtucAud010F02_assert(isset($enCart[$key]) && $enCart[$key] !== '', 'EN cart has ' . $key);
    mtucAud010F02_assert(
        !preg_match('/[\x{0400}-\x{04FF}]/u', $enProduct[$key]),
        'EN product ' . $key . ' has no Cyrillic'
    );
    mtucAud010F02_assert(
        $enProduct[$key] !== $key,
        'EN product does not expose literal key ' . $key
    );
}

mtucAud010F02_assert(
    $bgProduct['error_validation'] !== $enProduct['error_validation'],
    'BG and EN generic validation messages differ'
);

// -------------------------------------------------------------------------
// Shared JS path + no hardcoded BG validation copy in storefront.js
// -------------------------------------------------------------------------
$js = (string) file_get_contents($jsPath);
mtucAud010F02_assert(strpos($js, 'function showFieldErrors') !== false, 'shared JS defines showFieldErrors');
mtucAud010F02_assert(strpos($js, 'function clearAllFieldErrors') !== false, 'shared JS defines clearAllFieldErrors');
mtucAud010F02_assert(strpos($js, 'function clearOneFieldError') !== false, 'shared JS defines clearOneFieldError');
mtucAud010F02_assert(strpos($js, 'function focusFirstInvalidField') !== false, 'shared JS defines focusFirstInvalidField');
mtucAud010F02_assert(strpos($js, 'response.error === "validation"') !== false, 'JS consumes response.error validation');
mtucAud010F02_assert(strpos($js, 'showFieldErrors(response.errors') !== false, 'JS renders response.errors');
mtucAud010F02_assert(strpos($js, 'clearAllFieldErrors()') !== false, 'JS clears before submit');
mtucAud010F02_assert(strpos($js, '.html(message') === false, 'errors not inserted via .html(message)');
mtucAud010F02_assert(
    strpos($js, 'bootstrap.i18n') !== false || strpos($js, 'i18n = bootstrap.i18n') !== false,
    'JS reads bootstrap i18n'
);
mtucAud010F02_assert(
    !preg_match('/Полето е задължително|Моля, попълнете всички|Моля, коригирайте|Въведете валиден/u', $js),
    'no Bulgarian hardcoded validation copy in shared JS'
);

$productSrc = (string) file_get_contents($productCtrl);
$cartSrc = (string) file_get_contents($cartCtrl);
mtucAud010F02_assert(
    strpos($productSrc, 'MtUniCreditStorefrontValidationCopy') !== false
        && strpos($cartSrc, 'MtUniCreditStorefrontValidationCopy') !== false,
    'Product/Cart use shared ValidationCopy'
);
mtucAud010F02_assert(
    strpos($productSrc, "Моля, коригирайте данните.") === false
        && strpos($cartSrc, "Моля, коригирайте данните.") === false,
    'controllers no longer hardcode generic BG validation message'
);

$modalTwig = (string) file_get_contents($modalPath);
foreach (array('firstname', 'lastname', 'address', 'phone', 'email', 'phone2', 'egn') as $field) {
    mtucAud010F02_assert(
        strpos($modalTwig, 'data-mtuc-field-error="' . $field . '"') !== false,
        'modal exposes span for ' . $field
    );
}

$productWidget = (string) file_get_contents(
    $catalog . '/view/theme/default/template/extension/mt_uni_credit/product_widget.twig'
);
$cartWidget = (string) file_get_contents(
    $catalog . '/view/theme/default/template/extension/mt_uni_credit/cart_widget.twig'
);
mtucAud010F02_assert(strpos($productWidget, 'i18n: mtuc_i18n') !== false, 'Product bootstrap includes i18n');
mtucAud010F02_assert(strpos($cartWidget, 'i18n: mtuc_i18n') !== false, 'Cart bootstrap includes i18n');

// -------------------------------------------------------------------------
// F-010-02-R2 — no duplicated Bulgarian customer-facing catalogue in validators
// -------------------------------------------------------------------------
$applicantSrc = (string) file_get_contents($applicantPath);
$processTwoSrc = (string) file_get_contents($processTwoPath);
mtucAud010F02_assert(
    !preg_match('/Полето е задължително|Въведете валиден|ЕГН трябва да съдържа|Вторият телефон може/u', $applicantSrc),
    'R2: applicant validator has no Bulgarian customer-facing fallback catalogue'
);
mtucAud010F02_assert(
    !preg_match('/Полето е задължително|ЕГН трябва да съдържа|Вторият телефон може/u', $processTwoSrc),
    'R2: process-two validator has no Bulgarian customer-facing fallback catalogue'
);
mtucAud010F02_assert(
    strpos($applicantSrc, 'array_merge(array(') === false
        || strpos($applicantSrc, "'required' => 'Полето") === false,
    'R2: applicant constructor does not merge translated defaults'
);

class Aud010F02LangFake
{
    /** @var array<string, string> */
    private $bag;

    /**
     * @param array<string, string> $bag
     */
    public function __construct(array $bag)
    {
        $this->bag = $bag;
    }

    /**
     * @param string $key
     * @return string
     */
    public function get($key)
    {
        return isset($this->bag[$key]) ? $this->bag[$key] : $key;
    }
}

$enLang = new Aud010F02LangFake($enProduct);
$enValidator = new MtUniCreditStorefrontApplicantFieldValidator(
    MtUniCreditStorefrontValidationCopy::applicantMessages($enLang)
);
$enErr = $enValidator->validate(array(
    'firstname' => str_repeat('A', 33),
    'lastname' => 'Smith',
    'email' => 'ok@example.test',
    'telephone' => '0888123456',
    'address_1' => 'Main street 1',
));
mtucAud010F02_assert(
    isset($enErr['firstname']) && $enErr['firstname'] === $enProduct['error_name_length'],
    'native-boundary message localized EN via ValidationCopy'
);
mtucAud010F02_assert(
    !preg_match('/[\x{0400}-\x{04FF}]/u', $enErr['firstname']),
    'EN validator message has no Bulgarian'
);

$bgLang = new Aud010F02LangFake($bgProduct);
$bgValidator = new MtUniCreditStorefrontApplicantFieldValidator(
    MtUniCreditStorefrontValidationCopy::applicantMessages($bgLang)
);
$bgErr = $bgValidator->validate(array(
    'firstname' => '',
    'lastname' => 'Иванов',
    'email' => 'ok@example.test',
    'telephone' => '0888123456',
    'address_1' => 'ул. Витоша 1',
));
mtucAud010F02_assert(
    isset($bgErr['firstname']) && $bgErr['firstname'] === $bgProduct['error_field_required'],
    'BG validator message localized via ValidationCopy'
);

$p2 = new MtUniCreditStorefrontProcessTwoFieldValidator(
    MtUniCreditStorefrontValidationCopy::processTwoMessages($enLang)
);
$p2err = $p2->validate(array('egn' => '123', 'phone2' => ''));
mtucAud010F02_assert(
    isset($p2err['errors']['egn'], $p2err['errors']['phone2'])
        && $p2err['errors']['egn'] === $enProduct['error_egn_invalid']
        && strpos($p2err['errors']['egn'], '123') === false,
    'Process 2 messages localized EN without EGN echo'
);

$bare = new MtUniCreditStorefrontApplicantFieldValidator();
$bareErr = $bare->validate(array(
    'firstname' => '',
    'lastname' => '',
    'email' => '',
    'telephone' => '',
    'address_1' => '',
));
mtucAud010F02_assert(
    isset($bareErr['firstname']) && $bareErr['firstname'] === '',
    'R2: validator without ValidationCopy yields empty (non-translated) messages'
);

// -------------------------------------------------------------------------
// F-010-02-R1 — execute production storefront.js (no PHP DOM mirror)
// -------------------------------------------------------------------------
mtucAud010F02_assert(is_file($jsdomProof), 'R1 harness script exists');
mtucAud010F02_assert(
    strpos((string) file_get_contents($jsdomProof), 'storefront.js') !== false
        && strpos((string) file_get_contents($jsdomProof), 'mtucAud010F02_applyErrors') === false,
    'R1 harness loads production storefront.js and does not reimplement PHP mirrors'
);

$nodeModules = $jsdomDir . DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR . 'jsdom';
if (!is_dir($nodeModules)) {
    $installCmd = 'npm install --prefix '
        . escapeshellarg($jsdomDir);
    exec($installCmd . ' 2>&1', $installOut, $installCode);
    mtucAud010F02_assert($installCode === 0, 'R1 npm install jsdom harness deps');
} else {
    mtucAud010F02_assert(true, 'R1 jsdom harness deps present');
}

$cmd = 'node ' . escapeshellarg($jsdomProof);
exec($cmd . ' 2>&1', $jsOut, $jsCode);
foreach ($jsOut as $line) {
    echo $line . PHP_EOL;
    if (strpos($line, 'PASS  ') === 0) {
        $passes++;
    } elseif (strpos($line, 'FAIL  ') === 0) {
        $failures[] = substr($line, 6);
    }
}
mtucAud010F02_assert($jsCode === 0, 'R1 production storefront.js DOM proof exit 0');
mtucAud010F02_assert(
    strpos(implode("\n", $jsOut), 'AUD-010 F02-R1 JSDOM: PASS') !== false,
    'R1 JSDOM summary PASS'
);

// -------------------------------------------------------------------------
if (count($failures) > 0) {
    echo 'AUD-010 F02: FAIL (' . count($failures) . ' failures, ' . $passes . ' passes)' . PHP_EOL;
    exit(1);
}

echo 'AUD-010 F02: PASS (' . $passes . ' passes)' . PHP_EOL;
exit(0);
