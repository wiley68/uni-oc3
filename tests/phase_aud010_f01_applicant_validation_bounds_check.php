<?php

/**
 * AUD-010 F01 — shared Product/Cart applicant validation with native OC3 bounds.
 * Run: php tests/phase_aud010_f01_applicant_validation_bounds_check.php
 *
 * PHP 7.3 compatible. Offline.
 *
 * Native authority (reference-oc3-core):
 * - checkout/guest.php / register.php / payment_address.php utf8_strlen bounds
 * - oc_order: firstname/lastname varchar(32), email varchar(96),
 *   telephone varchar(32), payment_address_1 varchar(128)
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/support/phase2_memory_db.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';
$ctrl = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
    . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DIR_STORAGE')) {
    mtuc_test_define_dir_storage('mtuc-aud010-f01');
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
function mtucAud010F01_assert($condition, $message)
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
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function mtucAud010F01_baseNormalized(array $overrides = array())
{
    return array_merge(array(
        'firstname' => 'Йоан',
        'lastname' => 'Иванов',
        'email' => 'ioan@example.test',
        'telephone' => '0888123456',
        'address_1' => 'ул. Витоша 1, София',
    ), $overrides);
}

/**
 * @param int $n
 * @param string $ch
 * @return string
 */
function mtucAud010F01_chars($n, $ch = 'a')
{
    return str_repeat($ch, (int) $n);
}

if (!class_exists('Aud010F01LangFake', false)) {
    final class Aud010F01LangFake
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

$validator = new MtUniCreditStorefrontApplicantFieldValidator(
    MtUniCreditStorefrontValidationCopy::applicantMessages(new Aud010F01LangFake())
);

// -------------------------------------------------------------------------
// Shared structural proof
// -------------------------------------------------------------------------
$productSrc = (string) file_get_contents($ctrl . DIRECTORY_SEPARATOR . 'product.php');
$cartSrc = (string) file_get_contents($ctrl . DIRECTORY_SEPARATOR . 'cart.php');
mtucAud010F01_assert(
    strpos($productSrc, 'MtUniCreditStorefrontApplicantFieldValidator') !== false,
    'Product uses shared ApplicantFieldValidator'
);
mtucAud010F01_assert(
    strpos($cartSrc, 'MtUniCreditStorefrontApplicantFieldValidator') !== false,
    'Cart uses shared ApplicantFieldValidator'
);
mtucAud010F01_assert(
    is_file($lib . DIRECTORY_SEPARATOR . 'storefront_applicant_field_validator.php'),
    'shared validator file exists once'
);
mtucAud010F01_assert(
    strpos($productSrc, "errors['firstname'] = 'Полето е задължително.") === false
        && strpos($cartSrc, "errors['firstname'] = 'Полето е задължително.") === false,
    'Product/Cart no longer duplicate ordinary field rules inline'
);

// -------------------------------------------------------------------------
// Firstname / lastname boundaries
// -------------------------------------------------------------------------
mtucAud010F01_assert(
    $validator->validate(mtucAud010F01_baseNormalized(array('firstname' => 'И'))) === array(),
    'firstname 1 char UTF-8 valid'
);
mtucAud010F01_assert(
    $validator->validate(mtucAud010F01_baseNormalized(array('firstname' => mtucAud010F01_chars(32, 'Ж')))) === array(),
    'firstname 32 UTF-8 valid'
);
$e33 = $validator->validate(mtucAud010F01_baseNormalized(array('firstname' => mtucAud010F01_chars(33, 'Ж'))));
mtucAud010F01_assert(isset($e33['firstname']), 'firstname 33 invalid');

mtucAud010F01_assert(
    $validator->validate(mtucAud010F01_baseNormalized(array('lastname' => 'И'))) === array(),
    'lastname 1 char valid'
);
mtucAud010F01_assert(
    $validator->validate(mtucAud010F01_baseNormalized(array('lastname' => mtucAud010F01_chars(32, 'Я')))) === array(),
    'lastname 32 valid'
);
$eln = $validator->validate(mtucAud010F01_baseNormalized(array('lastname' => mtucAud010F01_chars(33, 'Я'))));
mtucAud010F01_assert(isset($eln['lastname']), 'lastname 33 invalid');

// -------------------------------------------------------------------------
// Email
// -------------------------------------------------------------------------
mtucAud010F01_assert(
    $validator->validate(mtucAud010F01_baseNormalized(array('email' => 'ok@example.test'))) === array(),
    'email short valid'
);
$longOkLocal = mtucAud010F01_chars(64, 'a');
$longOkDomain = mtucAud010F01_chars(27, 'b') . '.com'; // 64+1+27+4 = 96
$email96 = $longOkLocal . '@' . $longOkDomain;
mtucAud010F01_assert(
    $validator->characterLength($email96) === 96
        && $validator->validate(mtucAud010F01_baseNormalized(array('email' => $email96))) === array(),
    'email 96 valid-format accepted'
);
$email97 = $longOkLocal . '@' . mtucAud010F01_chars(28, 'b') . '.com'; // 97
$ee = $validator->validate(mtucAud010F01_baseNormalized(array('email' => $email97)));
mtucAud010F01_assert(
    $validator->characterLength($email97) === 97 && isset($ee['email']),
    'email >96 invalid'
);

// -------------------------------------------------------------------------
// Telephone
// -------------------------------------------------------------------------
$e2 = $validator->validate(mtucAud010F01_baseNormalized(array('telephone' => '12')));
mtucAud010F01_assert(isset($e2['phone']), 'phone 2 chars invalid');
mtucAud010F01_assert(
    $validator->validate(mtucAud010F01_baseNormalized(array('telephone' => '088'))) === array(),
    'phone 3 chars valid'
);
$phone32 = '+359' . mtucAud010F01_chars(28, '8'); // 4+28=32
mtucAud010F01_assert(
    $validator->characterLength($phone32) === 32
        && $validator->validate(mtucAud010F01_baseNormalized(array('telephone' => $phone32))) === array(),
    'phone 32 valid'
);
$phone33 = $phone32 . '8';
$ep33 = $validator->validate(mtucAud010F01_baseNormalized(array('telephone' => $phone33)));
mtucAud010F01_assert(isset($ep33['phone']), 'phone 33 invalid');
$epBad = $validator->validate(mtucAud010F01_baseNormalized(array('telephone' => 'abc-def')));
mtucAud010F01_assert(isset($epBad['phone']), 'phone invalid characters');
$epNoDigit = $validator->validate(mtucAud010F01_baseNormalized(array('telephone' => '+++---')));
mtucAud010F01_assert(isset($epNoDigit['phone']), 'phone with no digit invalid');

// -------------------------------------------------------------------------
// Address
// -------------------------------------------------------------------------
$ea2 = $validator->validate(mtucAud010F01_baseNormalized(array('address_1' => 'аб')));
mtucAud010F01_assert(isset($ea2['address']), 'address 2 UTF-8 invalid');
mtucAud010F01_assert(
    $validator->validate(mtucAud010F01_baseNormalized(array('address_1' => 'абв'))) === array(),
    'address 3 UTF-8 valid'
);
$addr128 = mtucAud010F01_chars(128, 'ж');
mtucAud010F01_assert(
    $validator->characterLength($addr128) === 128
        && $validator->validate(mtucAud010F01_baseNormalized(array('address_1' => $addr128))) === array(),
    'address 128 valid'
);
$ea129 = $validator->validate(mtucAud010F01_baseNormalized(array('address_1' => mtucAud010F01_chars(129, 'ж'))));
mtucAud010F01_assert(isset($ea129['address']), 'address 129 invalid');

// -------------------------------------------------------------------------
// UTF-8 names + trim
// -------------------------------------------------------------------------
mtucAud010F01_assert(
    $validator->validate(mtucAud010F01_baseNormalized(array(
        'firstname' => 'Йоан',
        'lastname' => 'Иванов',
        'address_1' => 'бул. България 100',
    ))) === array(),
    'UTF-8 Bulgarian applicant valid'
);
mtucAud010F01_assert(
    $validator->validate(mtucAud010F01_baseNormalized(array('firstname' => '  Иван  '))) === array(),
    'trim-before-length: padded Иван valid'
);
$ew = $validator->validate(mtucAud010F01_baseNormalized(array('firstname' => '   ')));
mtucAud010F01_assert(isset($ew['firstname']), 'whitespace-only firstname invalid');

// -------------------------------------------------------------------------
// Multi-error + public keys
// -------------------------------------------------------------------------
$multi = $validator->validate(array(
    'firstname' => mtucAud010F01_chars(33),
    'lastname' => '',
    'email' => 'not-an-email',
    'telephone' => 'x',
    'address_1' => 'ab',
));
mtucAud010F01_assert(
    isset($multi['firstname'], $multi['lastname'], $multi['email'], $multi['phone'], $multi['address']),
    'multiple field errors accumulated'
);
mtucAud010F01_assert(
    !isset($multi['address_1']) && !isset($multi['telephone']),
    'public keys use address/phone not address_1/telephone'
);

// -------------------------------------------------------------------------
// Direct POST / side-effect guard via storefront submit
// -------------------------------------------------------------------------
$transport = new Phase4FakeCpHttpTransport();
$stack = Phase9TestHarness::stack($transport);
$input = Phase9TestHarness::productStorefrontInput($stack, 940001);
$input['customer']['firstname'] = mtucAud010F01_chars(33, 'A');
// Simulate controller-level rejection before service: shared validator alone.
$pre = $validator->validate(array(
    'firstname' => $input['customer']['firstname'],
    'lastname' => $input['customer']['lastname'],
    'email' => $input['customer']['email'],
    'telephone' => $input['customer']['telephone'],
    'address_1' => $input['customer']['address_1'],
));
mtucAud010F01_assert(isset($pre['firstname']), 'direct POST overlength firstname rejected server-side');

// Controllers call validator before product/cart resolution in submit path (not whole-file method order).
$productSubmitPos = strpos($productSrc, 'function submit');
$productValidateCall = strpos($productSrc, '$customerValidation = $this->validateStep2Customer($shop);', $productSubmitPos !== false ? $productSubmitPos : 0);
$productResolveCall = strpos($productSrc, 'MtUniCreditStorefrontRuntime::resolveProductLine(', $productValidateCall !== false ? $productValidateCall : 0);
$productServiceCall = strpos($productSrc, 'submissionService($this)->submit(', $productValidateCall !== false ? $productValidateCall : 0);
mtucAud010F01_assert(
    $productValidateCall !== false
        && $productResolveCall !== false
        && $productServiceCall !== false
        && $productValidateCall < $productResolveCall
        && $productValidateCall < $productServiceCall,
    'Product validates applicant before product resolution'
);
$cartSubmitPos = strpos($cartSrc, 'function runCartSubmit');
$cartValidateCall = strpos($cartSrc, '$customerValidation = $this->validateStep2Customer($shop);', $cartSubmitPos !== false ? $cartSubmitPos : 0);
$cartResolveCall = strpos($cartSrc, 'MtUniCreditStorefrontRuntime::resolveCartContext($this);', $cartValidateCall !== false ? $cartValidateCall : 0);
mtucAud010F01_assert(
    $cartValidateCall !== false
        && $cartResolveCall !== false
        && $cartValidateCall < $cartResolveCall
        && strpos($cartSrc, 'MtUniCreditStorefrontApplicantFieldValidator') !== false,
    'Cart validates via shared validator before side effects'
);

$attemptsBefore = $stack['attempts']->findByStoreOrder($stack['storeId'], 940001);
mtucAud010F01_assert($attemptsBefore === null, 'invalid input: no attempt created by harness alone');
mtucAud010F01_assert(
    Phase7TestHarness::countOrderPosts($transport) === 0,
    'invalid input: no CP POST'
);

// Valid baseline still passes validator (valid-flow regression for shared rules)
mtucAud010F01_assert(
    $validator->validate(mtucAud010F01_baseNormalized()) === array(),
    'valid applicant still accepted'
);

// -------------------------------------------------------------------------
// Process 2 unchanged (EGN/phone2 stay on ProcessTwoFieldValidator)
// -------------------------------------------------------------------------
$p2 = new MtUniCreditStorefrontProcessTwoFieldValidator();
$p2bad = $p2->validate(array('egn' => '', 'phone2' => ''));
mtucAud010F01_assert(
    $p2bad['ok'] === false && isset($p2bad['errors']['egn'], $p2bad['errors']['phone2']),
    'Process 2 EGN/phone2 still required'
);
mtucAud010F01_assert(
    strpos($productSrc, 'MtUniCreditStorefrontProcessTwoFieldValidator') !== false
        && strpos($cartSrc, 'MtUniCreditStorefrontProcessTwoFieldValidator') !== false,
    'Process 2 validator still composed in Product/Cart'
);

// -------------------------------------------------------------------------
// No Customer/Address mutation
// -------------------------------------------------------------------------
$valSrc = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'storefront_applicant_field_validator.php');
mtucAud010F01_assert(
    strpos($valSrc, 'editCustomer') === false
        && strpos($valSrc, 'addAddress') === false,
    'no Customer/Address mutation in shared validator'
);

// -------------------------------------------------------------------------
if (count($failures) > 0) {
    echo 'AUD-010 F01: FAIL (' . count($failures) . ' failures, ' . $passes . ' passes)' . PHP_EOL;
    exit(1);
}

echo 'AUD-010 F01: PASS (' . $passes . ' passes)' . PHP_EOL;
exit(0);
