<?php

/**
 * Included from mtuc8_run() — Popup Step 2 Process 1/2 parity.
 *
 * @var string $catalog
 * @var string $modalTwig
 * @var string $cssPopup
 * @var string $jsPopup
 * @var string $productLangBg
 * @var string $cartLangBg
 */

// --- Process field matrix via modal presenter ---
$step2P1 = MtUniCreditStorefrontModalPresenter::present(
    array('uni_proces' => 0, 'consents' => array()),
    'BGN',
    array()
);
mtuc8_assert($step2P1['process2'] === false, 'Process 1: uni_proces=0 => process2 false');
mtuc8_assert(
    $step2P1['fields'] === array('firstname', 'lastname', 'address', 'phone', 'email'),
    'Process 1 fields exactly five base keys'
);
mtuc8_assert(!in_array('phone2', $step2P1['fields'], true) && !in_array('egn', $step2P1['fields'], true), 'Process 1 omits phone2/egn');

$step2P2 = MtUniCreditStorefrontModalPresenter::present(
    array('uni_proces' => 1, 'consents' => array()),
    'BGN',
    array()
);
mtuc8_assert($step2P2['process2'] === true, 'Process 2: uni_proces=1 => process2 true');
mtuc8_assert(
    $step2P2['fields'] === array('firstname', 'lastname', 'address', 'phone', 'email', 'phone2', 'egn'),
    'Process 2 fields exactly seven keys'
);

// Twig contract
mtuc8_assert(strpos($modalTwig, 'name="firstname"') !== false, 'Step 2 has firstname');
mtuc8_assert(strpos($modalTwig, 'name="lastname"') !== false, 'Step 2 has lastname');
mtuc8_assert(strpos($modalTwig, 'name="address"') !== false, 'Step 2 has address');
mtuc8_assert(strpos($modalTwig, 'name="phone"') !== false, 'Step 2 has phone');
mtuc8_assert(strpos($modalTwig, 'name="email"') !== false, 'Step 2 has email');
mtuc8_assert(strpos($modalTwig, '{% if process2 %}') !== false, 'Step 2 Process 2 gate');
mtuc8_assert(
    preg_match('/\{\%\s*if\s+process2\s*\%\}[\s\S]*name="phone2"[\s\S]*name="egn"/', $modalTwig) === 1,
    'Process 2 renders phone2 then egn'
);
mtuc8_assert(strpos($modalTwig, 'name="city"') === false, 'legacy city field removed');
mtuc8_assert(strpos($modalTwig, 'name="postcode"') === false, 'legacy postcode field removed');
mtuc8_assert(strpos($modalTwig, 'name="country_id"') === false, 'legacy country_id field removed');
mtuc8_assert(strpos($modalTwig, 'name="zone_id"') === false, 'legacy zone_id field removed');
mtuc8_assert(strpos($modalTwig, 'name="address_1"') === false, 'legacy address_1 input removed');
mtuc8_assert(strpos($modalTwig, 'name="telephone"') === false, 'legacy telephone name removed (uses phone)');
mtuc8_assert(strpos($modalTwig, 'mt-uni-credit-storefront__required') !== false, 'required marker class');
mtuc8_assert(strpos($modalTwig, 'data-mtuc-consent-checkbox') !== false, 'consent checkbox marker');
mtuc8_assert(strpos($modalTwig, 'data-mtuc-submit') !== false && strpos($modalTwig, 'disabled') !== false, 'Submit starts disabled');
mtuc8_assert(strpos($modalTwig, 'is-disabled') !== false, 'Submit has is-disabled class');

// Prefill
$prefill = new MtUniCreditStorefrontCustomerPrefill();
$logged = $prefill->present(
    true,
    array(
        'firstname' => 'Иван',
        'lastname' => 'Иванов',
        'email' => 'ivan@example.test',
        'telephone' => '0888123456',
    ),
    array(
        array(
            'address_id' => 9,
            'firstname' => 'Иван',
            'lastname' => 'Иванов',
            'address_1' => 'ул. Тест 1',
            'address_2' => '',
            'city' => 'София',
            'postcode' => '1000',
        ),
    ),
    9
);
mtuc8_assert($logged['firstname'] === 'Иван', 'prefill firstname');
mtuc8_assert($logged['lastname'] === 'Иванов', 'prefill lastname');
mtuc8_assert($logged['email'] === 'ivan@example.test', 'prefill email');
mtuc8_assert($logged['telephone'] === '0888123456', 'prefill phone from customer telephone');
mtuc8_assert(strpos($logged['address'], 'ул. Тест 1') !== false, 'prefill address joins default address');
mtuc8_assert($logged['phone2'] === '' && $logged['egn'] === '', 'Process 2 extras empty without source');

// A. Valid default address_id
$caseA = $prefill->present(
    true,
    array('firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test', 'telephone' => '0888'),
    array(
        array('address_id' => 10, 'address_1' => 'Other', 'city' => 'X', 'postcode' => '1'),
        array('address_id' => 42, 'address_1' => 'Default St', 'city' => 'Sofia', 'postcode' => '1000'),
    ),
    42
);
mtuc8_assert(
    $caseA['address_id'] === 42 && strpos($caseA['address'], 'Default St') !== false,
    'A: valid default address_id=42 used'
);

// B. One address, address_id=0 (key remote regression)
$caseB = $prefill->present(
    true,
    array('firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test', 'telephone' => ''),
    array(
        array('address_id' => 7, 'address_1' => 'Only Road 5', 'city' => 'Plovdiv', 'postcode' => '4000'),
    ),
    0
);
mtuc8_assert(
    $caseB['address_id'] === 7 && strpos($caseB['address'], 'Only Road 5') !== false,
    'B: single-address fallback when address_id=0'
);
mtuc8_assert($caseB['telephone'] === '', 'B: missing phone stays empty');

// C. Stale default + one valid address
$caseC = $prefill->present(
    true,
    array('firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test', 'telephone' => '1'),
    array(
        array('address_id' => 11, 'address_1' => 'Surviving', 'city' => 'Varna', 'postcode' => '9000'),
    ),
    999
);
mtuc8_assert(
    $caseC['address_id'] === 11 && strpos($caseC['address'], 'Surviving') !== false,
    'C: stale default falls back to single book address'
);

// D. Multiple addresses, no default — empty (no arbitrary first-row pick)
$caseD = $prefill->present(
    true,
    array('firstname' => 'Cust', 'lastname' => 'Name', 'email' => 'c@d.test', 'telephone' => '2'),
    array(
        array('address_id' => 1, 'firstname' => 'X', 'lastname' => 'Y', 'address_1' => 'First', 'city' => 'A', 'postcode' => '1'),
        array('address_id' => 2, 'firstname' => 'P', 'lastname' => 'Q', 'address_1' => 'Second', 'city' => 'B', 'postcode' => '2'),
    ),
    0
);
mtuc8_assert($caseD['address'] === '' && $caseD['address_id'] === 0, 'D: multi-address no default → empty address');
mtuc8_assert($caseD['firstname'] === 'Cust' && $caseD['lastname'] === 'Name', 'D: names fall back to customer when address unresolved');

// E. Ownership — foreign address_id never selected from book
$caseE = $prefill->present(
    true,
    array('firstname' => 'Me', 'lastname' => 'User', 'email' => 'me@test', 'telephone' => '3'),
    array(
        array('address_id' => 5, 'address_1' => 'Mine', 'city' => 'Sofia', 'postcode' => '1000'),
    ),
    8
);
mtuc8_assert(
    $caseE['address_id'] === 5 && strpos($caseE['address'], 'Mine') !== false,
    'E: foreign preferred id ignored; single own address used'
);
$caseE2 = $prefill->present(
    true,
    array('firstname' => 'Me', 'lastname' => 'User', 'email' => 'me@test', 'telephone' => '3'),
    array(
        array('address_id' => 5, 'address_1' => 'Mine', 'city' => 'Sofia', 'postcode' => '1000'),
        array('address_id' => 6, 'address_1' => 'AlsoMine', 'city' => 'Sofia', 'postcode' => '1001'),
    ),
    8
);
mtuc8_assert(
    $caseE2['address'] === '' && $caseE2['address_id'] === 0,
    'E: foreign preferred id + multiple own addresses → empty (no guess)'
);

$noPhone = $prefill->present(
    true,
    array(
        'firstname' => 'A',
        'lastname' => 'B',
        'email' => 'a@b.test',
        'telephone' => '',
    ),
    array(
        array(
            'address_id' => 1,
            'address_1' => 'Street',
            'city' => 'Sofia',
            'postcode' => '1000',
        ),
    ),
    1
);
mtuc8_assert($noPhone['telephone'] === '', 'missing phone stays empty (no substitution)');

$guest = $prefill->present(false, array(), array(), 0);
mtuc8_assert($guest['is_logged'] === false && $guest['firstname'] === '' && $guest['telephone'] === '', 'guest prefill empty');

// Controllers must not gate address model with isset() (OC3 Controller has no __isset).
$productCtrl = (string) file_get_contents(
    $catalog . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'extension'
        . DIRECTORY_SEPARATOR . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'product.php'
);
$cartCtrl = (string) file_get_contents(
    $catalog . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'extension'
        . DIRECTORY_SEPARATOR . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'cart.php'
);
mtuc8_assert(
    strpos($productCtrl, 'isset($this->model_account_address)') === false,
    'product controller does not isset()-gate address model'
);
mtuc8_assert(
    strpos($cartCtrl, 'isset($this->model_account_address)') === false,
    'cart controller does not isset()-gate address model'
);
mtuc8_assert(
    strpos($productCtrl, "load->model('account/address')") !== false
        && strpos($productCtrl, 'getAddresses()') !== false,
    'product controller loads account/address and getAddresses'
);
mtuc8_assert(
    strpos($cartCtrl, "load->model('account/address')") !== false
        && strpos($cartCtrl, 'getAddresses()') !== false,
    'cart controller loads account/address and getAddresses'
);

// Process 1 privacy: presenter customer never invents egn for process1 DOM
$p1Presented = MtUniCreditStorefrontModalPresenter::present(
    array('uni_proces' => 0),
    'BGN',
    $logged
);
mtuc8_assert($p1Presented['process2'] === false, 'privacy process1 flag');
mtuc8_assert(!in_array('egn', $p1Presented['fields'], true), 'Process 1 view model fields omit egn');

// Normalizer phone → telephone, address → address_1 + store defaults
$norm = (new MtUniCreditStorefrontPopupFormNormalizer())->normalize(
    array(
        'firstname' => 'A',
        'lastname' => 'B',
        'phone' => '0888',
        'address' => 'Line 1',
        'email' => 'a@b.test',
    ),
    array(
        'city' => 'Sofia',
        'postcode' => '1000',
        'country_id' => 33,
        'zone_id' => 1,
        'country' => 'Bulgaria',
        'zone' => 'Sofia',
    )
);
mtuc8_assert($norm['telephone'] === '0888', 'normalizer maps phone→telephone');
mtuc8_assert($norm['address_1'] === 'Line 1', 'normalizer maps address→address_1');
mtuc8_assert($norm['city'] === 'Sofia', 'normalizer fills city from store defaults');

// Process 2 validator
$p2v = new MtUniCreditStorefrontProcessTwoFieldValidator();
$bad = $p2v->validate(array('egn' => '', 'phone2' => ''));
mtuc8_assert($bad['ok'] === false && isset($bad['errors']['egn'], $bad['errors']['phone2']), 'Process 2 requires egn+phone2');
$good = $p2v->validate(array('egn' => '1990010112', 'phone2' => '0888111222'));
mtuc8_assert($good['ok'] === true, 'Process 2 valid egn+phone2 accepted');
mtuc8_assert($p2v->isValidEgn('1990023012') === false, 'invalid EGN calendar date rejected');

// Consent resolver
$consents = (new MtUniCreditStorefrontConsentResolver())->normalize(array(
    'consents' => array(
        array('id' => 1, 'name' => 'Terms', 'url' => 'https://example.com/t', 'mandatory' => 1),
    ),
));
mtuc8_assert(count($consents) === 1 && $consents[0]['has_checkbox'] === true, 'consent normalize mandatory checkbox');
$cr = new MtUniCreditStorefrontConsentResolver();
mtuc8_assert($cr->isSatisfied(array('consents' => $consents), array(1)) === true, 'consent satisfied when id checked');
mtuc8_assert($cr->isSatisfied(array('consents' => $consents), array()) === false, 'consent unsatisfied when empty');
mtuc8_assert($cr->isSatisfied(array('consents' => array()), '1') === true, 'legacy consent accepts 1');

// Language labels
mtuc8_assert(strpos($productLangBg, 'Мобилен телефон') !== false, 'BG product telephone label');
mtuc8_assert(strpos($productLangBg, 'Втори телефон') !== false, 'BG product phone2 label');
mtuc8_assert(strpos($productLangBg, "'ЕГН'") !== false || strpos($productLangBg, '= \'ЕГН\'') !== false, 'BG product EGN label');
mtuc8_assert(strpos($cartLangBg, 'Мобилен телефон') !== false, 'BG cart telephone label');

// CSS visual parity asserts
mtuc8_assert(strpos($cssPopup, 'mt-uni-credit-storefront__customer-label') !== false, 'CSS customer label');
mtuc8_assert(
    preg_match('/customer-label[\s\S]*?color:\s*#000/', $cssPopup) === 1,
    'CSS black labels'
);
mtuc8_assert(
    preg_match('/customer-input[\s\S]*?font-size:\s*20px/', $cssPopup) === 1,
    'CSS larger customer input text'
);
mtuc8_assert(strpos($cssPopup, 'mt-uni-credit-storefront__required') !== false, 'CSS required marker');
mtuc8_assert(strpos($cssPopup, 'consent-checkbox') !== false && strpos($cssPopup, 'accent-color') !== false, 'CSS custom consent checkbox');
mtuc8_assert(
    preg_match('/consent-label a[\s\S]*?text-decoration:\s*underline/', $cssPopup) === 1,
    'CSS underlined consent link'
);
mtuc8_assert(
    preg_match('/customer-input:focus[\s\S]*?outline:\s*none[\s\S]*?box-shadow:\s*none/', $cssPopup) === 1,
    'CSS focus without Bootstrap frame'
);
mtuc8_assert(strpos($cssPopup, 'popup-button--primary.is-disabled') !== false, 'CSS disabled Submit style');
mtuc8_assert(strpos($cssPopup, '--mtuc-btn-disabled-bg') !== false, 'CSS disabled Submit token');

// JS submit gating mechanics
mtuc8_assert(strpos($jsPopup, 'function updateSubmitState') !== false, 'JS updateSubmitState');
mtuc8_assert(strpos($jsPopup, 'function getStep2FieldErrors') !== false, 'JS getStep2FieldErrors');
mtuc8_assert(strpos($jsPopup, 'function isValidEmail') !== false, 'JS email validation');
mtuc8_assert(strpos($jsPopup, 'function isValidEgn') !== false, 'JS EGN validation');
mtuc8_assert(strpos($jsPopup, 'areMandatoryConsentsChecked') !== false, 'JS consent gating');
mtuc8_assert(strpos($jsPopup, 'bindStep2ReadinessListeners') !== false, 'JS Step 2 input/change listeners');
mtuc8_assert(strpos($jsPopup, 'input.mtucStep2') !== false || strpos($jsPopup, '"input.mtucStep2') !== false, 'JS listens to input events');
mtuc8_assert(
    strpos($jsPopup, 'process === "1"') !== false && strpos($jsPopup, 'egn') !== false,
    'JS Process 1 strips egn/phone2 from payload'
);

// Logical gating matrix (PHP-side mirror of client rules)
function mtuc8_step2_ready(array $fields, $consent, $process2)
{
    $required = array('firstname', 'lastname', 'address', 'phone', 'email');
    if ($process2) {
        $required[] = 'phone2';
        $required[] = 'egn';
    }
    foreach ($required as $key) {
        $value = isset($fields[$key]) ? trim((string) $fields[$key]) : '';
        if ($value === '') {
            return false;
        }
        if ($key === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        if (($key === 'phone' || $key === 'phone2') && !(new MtUniCreditStorefrontProcessTwoFieldValidator())->isValidPhone($value)) {
            return false;
        }
        if ($key === 'egn') {
            $digits = preg_replace('/\D+/', '', $value);
            if (!(new MtUniCreditStorefrontProcessTwoFieldValidator())->isValidEgn((string) $digits)) {
                return false;
            }
        }
    }

    return $consent === true;
}

mtuc8_assert(mtuc8_step2_ready(array(), false, false) === false, 'P1 empty → disabled');
mtuc8_assert(
    mtuc8_step2_ready(
        array(
            'firstname' => 'A',
            'lastname' => 'B',
            'address' => 'Addr',
            'phone' => '0888111222',
            'email' => 'a@b.test',
        ),
        false,
        false
    ) === false,
    'P1 filled without consent → disabled'
);
mtuc8_assert(
    mtuc8_step2_ready(
        array(
            'firstname' => 'A',
            'lastname' => 'B',
            'address' => 'Addr',
            'phone' => '0888111222',
            'email' => 'a@b.test',
        ),
        true,
        false
    ) === true,
    'P1 all required + consent → enabled'
);
mtuc8_assert(
    mtuc8_step2_ready(
        array(
            'firstname' => 'A',
            'lastname' => 'B',
            'address' => 'Addr',
            'phone' => '0888111222',
            'email' => 'a@b.test',
        ),
        true,
        true
    ) === false,
    'P2 missing phone2/egn → disabled'
);
mtuc8_assert(
    mtuc8_step2_ready(
        array(
            'firstname' => 'A',
            'lastname' => 'B',
            'address' => 'Addr',
            'phone' => '0888111222',
            'email' => 'a@b.test',
            'phone2' => '0888333444',
            'egn' => '1990010112',
        ),
        true,
        true
    ) === true,
    'P2 all seven + consent → enabled'
);
mtuc8_assert(
    mtuc8_step2_ready(
        array(
            'firstname' => '   ',
            'lastname' => 'B',
            'address' => 'Addr',
            'phone' => '0888111222',
            'email' => 'a@b.test',
        ),
        true,
        false
    ) === false,
    'whitespace-only required field → disabled'
);
mtuc8_assert(
    mtuc8_step2_ready(
        array(
            'firstname' => 'A',
            'lastname' => 'B',
            'address' => 'Addr',
            'phone' => '0888111222',
            'email' => 'not-an-email',
        ),
        true,
        false
    ) === false,
    'invalid email → disabled'
);
