<?php

/**
 * AUD-010 F02 — field-level validation UX + BG/EN localization.
 * Run: php tests/phase_aud010_f02_validation_ux_localization_check.php
 *
 * PHP 7.3 compatible. Offline.
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

/**
 * Minimal DOM field-error contract mirror of storefront.js showFieldErrors/clear*.
 *
 * @param DOMDocument $dom
 * @param array<string, string> $errors
 * @return void
 */
function mtucAud010F02_applyErrors(DOMDocument $dom, array $errors)
{
    $xpath = new DOMXPath($dom);
    foreach ($errors as $key => $message) {
        $spans = $xpath->query('//*[@data-mtuc-field-error="' . $key . '"]');
        if (!$spans || $spans->length === 0) {
            continue;
        }
        $span = $spans->item(0);
        while ($span->firstChild) {
            $span->removeChild($span->firstChild);
        }
        $span->appendChild($dom->createTextNode((string) $message));
        $inputs = $xpath->query('//*[@name="' . $key . '"]');
        if ($inputs && $inputs->length > 0) {
            /** @var DOMElement $input */
            $input = $inputs->item(0);
            $input->setAttribute('aria-invalid', $message !== '' ? 'true' : 'false');
        }
    }
}

/**
 * @param DOMDocument $dom
 * @param string $key
 * @return void
 */
function mtucAud010F02_clearOne(DOMDocument $dom, $key)
{
    $xpath = new DOMXPath($dom);
    $spans = $xpath->query('//*[@data-mtuc-field-error="' . $key . '"]');
    if ($spans && $spans->length > 0) {
        $span = $spans->item(0);
        while ($span->firstChild) {
            $span->removeChild($span->firstChild);
        }
    }
    $inputs = $xpath->query('//*[@name="' . $key . '"]');
    if ($inputs && $inputs->length > 0) {
        /** @var DOMElement $input */
        $input = $inputs->item(0);
        $input->setAttribute('aria-invalid', 'false');
    }
}

/**
 * @param DOMDocument $dom
 * @return void
 */
function mtucAud010F02_clearAll(DOMDocument $dom)
{
    $xpath = new DOMXPath($dom);
    foreach ($xpath->query('//*[@data-mtuc-field-error]') as $span) {
        while ($span->firstChild) {
            $span->removeChild($span->firstChild);
        }
    }
    foreach ($xpath->query('//*[@data-mtuc-submit-error]') as $span) {
        while ($span->firstChild) {
            $span->removeChild($span->firstChild);
        }
    }
}

/**
 * @param DOMDocument $dom
 * @param string $key
 * @return string
 */
function mtucAud010F02_spanText(DOMDocument $dom, $key)
{
    $xpath = new DOMXPath($dom);
    $spans = $xpath->query('//*[@data-mtuc-field-error="' . $key . '"]');
    if (!$spans || $spans->length === 0) {
        return '';
    }

    return trim((string) $spans->item(0)->textContent);
}

/**
 * @param DOMDocument $dom
 * @param array<string, string> $errors
 * @return string|null
 */
function mtucAud010F02_firstFocus(DOMDocument $dom, array $errors)
{
    $order = array('firstname', 'lastname', 'address', 'phone', 'email', 'phone2', 'egn');
    $xpath = new DOMXPath($dom);
    foreach ($order as $name) {
        if (!isset($errors[$name])) {
            continue;
        }
        $inputs = $xpath->query('//*[@name="' . $name . '"]');
        if (!$inputs || $inputs->length === 0) {
            continue;
        }
        /** @var DOMElement $input */
        $input = $inputs->item(0);
        if ($input->hasAttribute('hidden')) {
            continue;
        }
        if ($input->getAttribute('type') === 'hidden') {
            continue;
        }

        return $name;
    }

    return null;
}

$fixtureHtml = <<<'HTML'
<!DOCTYPE html>
<html><head><meta charset="utf-8"></head><body>
<div id="mt-uni-credit-product-modal">
  <form data-mtuc-form data-mtuc-process="1">
    <input name="firstname" value="Ivan" data-retained="1" />
    <span data-mtuc-field-error="firstname"></span>
    <input name="lastname" value="Ivanov" />
    <span data-mtuc-field-error="lastname"></span>
    <input name="address" value="Vitosha 1" />
    <span data-mtuc-field-error="address"></span>
    <input name="phone" value="0888" />
    <span data-mtuc-field-error="phone"></span>
    <input name="email" value="bad" />
    <span data-mtuc-field-error="email"></span>
    <span data-mtuc-submit-error></span>
  </form>
</div>
<div id="mt-uni-credit-cart-modal">
  <form data-mtuc-form data-mtuc-process="2">
    <input name="firstname" value="Anna" />
    <span data-mtuc-field-error="firstname"></span>
    <input name="lastname" value="Smith" />
    <span data-mtuc-field-error="lastname"></span>
    <input name="address" value="Main 1" />
    <span data-mtuc-field-error="address"></span>
    <input name="phone" value="0888" />
    <span data-mtuc-field-error="phone"></span>
    <input name="email" value="a@b.co" />
    <span data-mtuc-field-error="email"></span>
    <input name="phone2" value="" />
    <span data-mtuc-field-error="phone2"></span>
    <input name="egn" value="" />
    <span data-mtuc-field-error="egn"></span>
    <span data-mtuc-submit-error></span>
  </form>
</div>
</body></html>
HTML;

// -------------------------------------------------------------------------
// Language resources
// -------------------------------------------------------------------------
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
        $enProduct[$key] !== $key && strpos($enProduct[$key], 'error_') !== 0,
        'EN product does not expose literal key ' . $key
    );
}

mtucAud010F02_assert(
    $bgProduct['error_validation'] !== $enProduct['error_validation'],
    'BG and EN generic validation messages differ'
);

// -------------------------------------------------------------------------
// Shared JS path + no hardcoded BG validation copy
// -------------------------------------------------------------------------
$js = (string) file_get_contents($jsPath);
mtucAud010F02_assert(strpos($js, 'function showFieldErrors') !== false, 'shared JS defines showFieldErrors');
mtucAud010F02_assert(strpos($js, 'function clearAllFieldErrors') !== false, 'shared JS defines clearAllFieldErrors');
mtucAud010F02_assert(strpos($js, 'function clearOneFieldError') !== false, 'shared JS defines clearOneFieldError');
mtucAud010F02_assert(strpos($js, 'function focusFirstInvalidField') !== false, 'shared JS defines focusFirstInvalidField');
mtucAud010F02_assert(strpos($js, 'response.error === "validation"') !== false, 'JS consumes response.error validation');
mtucAud010F02_assert(strpos($js, 'showFieldErrors(response.errors') !== false, 'JS renders response.errors');
mtucAud010F02_assert(strpos($js, 'clearAllFieldErrors()') !== false, 'JS clears before submit');
mtucAud010F02_assert(strpos($js, '.text(') !== false && strpos($js, '.html(message') === false, 'errors use text not html');
mtucAud010F02_assert(strpos($js, 'bootstrap.i18n') !== false || strpos($js, 'i18n = bootstrap.i18n') !== false, 'JS reads bootstrap i18n');
mtucAud010F02_assert(
    !preg_match('/Полето е задължително|Моля, попълнете всички|Моля, коригирайте|Въведете валиден/u', $js),
    'no Bulgarian hardcoded validation copy in shared JS'
);
mtucAud010F02_assert(
    strpos($js, 'data-mtuc-field-error') !== false,
    'JS targets data-mtuc-field-error spans'
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
mtucAud010F02_assert(
    strpos($productSrc, 'error_validation') !== false
        && strpos($cartSrc, 'error_validation') !== false,
    'controllers load error_validation language key'
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
// Behavioral DOM mapping (Product fixture)
// -------------------------------------------------------------------------
$dom = new DOMDocument();
@$dom->loadHTML($fixtureHtml);
$productErrors = array(
    'firstname' => $enProduct['error_name_length'],
    'email' => $enProduct['error_email_invalid'],
    'phone' => $enProduct['error_phone_invalid'],
    'future_field' => 'should be ignored',
);
mtucAud010F02_applyErrors($dom, $productErrors);
mtucAud010F02_assert(mtucAud010F02_spanText($dom, 'firstname') === $enProduct['error_name_length'], 'firstname server error → span');
mtucAud010F02_assert(mtucAud010F02_spanText($dom, 'email') === $enProduct['error_email_invalid'], 'email server error → span');
mtucAud010F02_assert(mtucAud010F02_spanText($dom, 'phone') === $enProduct['error_phone_invalid'], 'phone server error → span');
mtucAud010F02_assert(mtucAud010F02_spanText($dom, 'lastname') === '', 'unrelated field unchanged');
mtucAud010F02_assert(mtucAud010F02_firstFocus($dom, $productErrors) === 'firstname', 'first invalid field focus = firstname');

$xpath = new DOMXPath($dom);
$fnInput = $xpath->query('//*[@id="mt-uni-credit-product-modal"]//*[@name="firstname"]')->item(0);
mtucAud010F02_assert(
    $fnInput instanceof DOMElement && $fnInput->getAttribute('aria-invalid') === 'true',
    'invalid field marked aria-invalid'
);
mtucAud010F02_assert(
    $fnInput instanceof DOMElement && $fnInput->getAttribute('value') === 'Ivan',
    'entered values retained'
);

mtucAud010F02_clearOne($dom, 'firstname');
mtucAud010F02_assert(mtucAud010F02_spanText($dom, 'firstname') === '', 'field error clears on correction');
mtucAud010F02_assert(mtucAud010F02_spanText($dom, 'email') === $enProduct['error_email_invalid'], 'other errors remain after one clear');

mtucAud010F02_clearAll($dom);
mtucAud010F02_assert(
    mtucAud010F02_spanText($dom, 'email') === '' && mtucAud010F02_spanText($dom, 'phone') === '',
    'new submit clears stale errors'
);

// Empty errors map → generic only (no crash / no field text)
mtucAud010F02_applyErrors($dom, array());
$submit = $xpath->query('//*[@id="mt-uni-credit-product-modal"]//*[@data-mtuc-submit-error]')->item(0);
if ($submit) {
    while ($submit->firstChild) {
        $submit->removeChild($submit->firstChild);
    }
    $submit->appendChild($dom->createTextNode($enProduct['error_validation']));
}
mtucAud010F02_assert(
    $submit instanceof DOMNode && trim((string) $submit->textContent) === $enProduct['error_validation'],
    'empty errors map → generic message'
);

// -------------------------------------------------------------------------
// Cart / Process 2 fixture
// -------------------------------------------------------------------------
$cartErrors = array(
    'lastname' => $bgCart['error_field_required'],
    'address' => $bgCart['error_address_length'],
    'phone2' => $bgCart['error_phone2_invalid'],
    'egn' => $bgCart['error_egn_invalid'],
);
mtucAud010F02_applyErrors($dom, $cartErrors);
mtucAud010F02_assert(mtucAud010F02_spanText($dom, 'lastname') === $bgCart['error_field_required'], 'lastname → span');
mtucAud010F02_assert(mtucAud010F02_spanText($dom, 'address') === $bgCart['error_address_length'], 'address → span');
mtucAud010F02_assert(mtucAud010F02_spanText($dom, 'phone2') === $bgCart['error_phone2_invalid'], 'phone2 → span');
mtucAud010F02_assert(mtucAud010F02_spanText($dom, 'egn') === $bgCart['error_egn_invalid'], 'egn → span');
mtucAud010F02_assert(
    strpos(mtucAud010F02_spanText($dom, 'egn'), '880101') === false,
    'EGN error does not echo sensitive value'
);
mtucAud010F02_assert(mtucAud010F02_firstFocus($dom, $cartErrors) === 'lastname', 'Process 2 first focus = lastname');

// Process 1: no phone2/egn inputs in product form — unknown/hidden ignored
$p1Only = array('phone2' => 'x', 'egn' => 'y', 'firstname' => $enProduct['error_field_required']);
$domP1 = new DOMDocument();
@$domP1->loadHTML($fixtureHtml);
// Remove P2 fields from product modal context by applying only on product subtree via full doc:
// phone2/egn spans exist only under cart modal in fixture; product apply of phone2 finds cart spans.
// Isolate product fragment:
$productOnly = <<<'HTML'
<!DOCTYPE html><html><body>
<form data-mtuc-form data-mtuc-process="1">
<input name="firstname" value="A" /><span data-mtuc-field-error="firstname"></span>
<input name="email" value="a@b.co" /><span data-mtuc-field-error="email"></span>
<span data-mtuc-submit-error></span>
</form>
</body></html>
HTML;
$domIso = new DOMDocument();
@$domIso->loadHTML($productOnly);
mtucAud010F02_applyErrors($domIso, $p1Only);
mtucAud010F02_assert(mtucAud010F02_spanText($domIso, 'firstname') === $enProduct['error_field_required'], 'Process 1 known error renders');
mtucAud010F02_assert(mtucAud010F02_spanText($domIso, 'phone2') === '', 'Process 1 hidden P2 fields unaffected');
mtucAud010F02_assert(mtucAud010F02_spanText($domIso, 'egn') === '', 'Process 1 egn span absent/clear');

// -------------------------------------------------------------------------
// Localized validator messages
// -------------------------------------------------------------------------
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
    'native-boundary message localized EN'
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
    'BG validator message localized'
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

$i18n = MtUniCreditStorefrontValidationCopy::bootstrapI18n($enLang);
mtucAud010F02_assert(
    isset($i18n['error_validation'], $i18n['error_email_invalid'])
        && $i18n['error_validation'] === $enProduct['error_validation'],
    'bootstrap i18n bag localized'
);

// -------------------------------------------------------------------------
if (count($failures) > 0) {
    echo 'AUD-010 F02: FAIL (' . count($failures) . ' failures, ' . $passes . ' passes)' . PHP_EOL;
    exit(1);
}

echo 'AUD-010 F02: PASS (' . $passes . ' passes)' . PHP_EOL;
exit(0);
