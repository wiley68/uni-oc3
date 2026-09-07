<?php

/**
 * AUD-010 F01-R1 — malformed UTF-8 fail-closed + Product/Cart controller side-effect guards.
 * Run: php tests/phase_aud010_f01_r1_utf8_controller_guard_check.php
 *
 * PHP 7.3 compatible. Offline.
 *
 * Behavioral proof uses the real Product/Cart controller validateStep2Customer() path
 * (normalize → shared validator) and only proceeds to storefront submit/addOrder when ok.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/support/phase2_memory_db.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';
$ctrlDir = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
    . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DIR_STORAGE')) {
    mtuc_test_define_dir_storage('mtuc-aud010-f01-r1');
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

if (!class_exists('Registry', false)) {
    class Registry
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
            return isset($this->data[$key]) ? $this->data[$key] : null;
        }

        /**
         * @param string $key
         * @return bool
         */
        public function has($key)
        {
            return array_key_exists($key, $this->data);
        }
    }
}

if (!class_exists('Controller', false)) {
    abstract class Controller
    {
        /** @var Registry */
        protected $registry;

        public function __construct(Registry $registry)
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

if (!class_exists('Aud010F01R1ConfigFake', false)) {
    final class Aud010F01R1ConfigFake
    {
        /** @var array<string, mixed> */
        private $data;

        /**
         * @param array<string, mixed> $data
         */
        public function __construct(array $data)
        {
            $this->data = $data;
        }

        /**
         * @param string $key
         * @return mixed
         */
        public function get($key)
        {
            return isset($this->data[$key]) ? $this->data[$key] : null;
        }
    }
}

require_once $ctrlDir . DIRECTORY_SEPARATOR . 'product.php';
require_once $ctrlDir . DIRECTORY_SEPARATOR . 'cart.php';

$failures = array();
$passes = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucAud010F01R1_assert($condition, $message)
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
 * @return string
 */
function mtucAud010F01R1_malformedUtf8()
{
    return chr(0xC3) . chr(0x28);
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function mtucAud010F01R1_validPost(array $overrides = array())
{
    return array_merge(array(
        'firstname' => 'Йоан',
        'lastname' => 'Иванов',
        'email' => 'ioan.r1@example.test',
        'phone' => '0888123456',
        'address' => 'ул. България 10',
        'consent' => '1',
    ), $overrides);
}

/**
 * @param string $controllerClass
 * @param array<string, mixed> $post
 * @return object
 */
function mtucAud010F01R1_makeController($controllerClass, array $post)
{
    $registry = new Registry();
    $request = new stdClass();
    $request->post = $post;
    $request->get = array();
    $request->server = array('REQUEST_METHOD' => 'POST');
    $session = new stdClass();
    $session->data = array();
    // OC Controller uses $this->config->get via __get; provide object with get().
    $configObj = new Aud010F01R1ConfigFake(array('config_store_id' => 900001));
    $registry->set('request', $request);
    $registry->set('session', $session);
    $registry->set('config', $configObj);

    return new $controllerClass($registry);
}

/**
 * Execute real Product/Cart controller validateStep2Customer(); only then run $onValid.
 *
 * @param string $controllerClass
 * @param array<string, mixed> $post
 * @param array<string, mixed>|null $shop
 * @param callable|null $onValid
 * @return array{ok:bool,errors:array<string,string>,proceeded:bool,on_valid_result:mixed}
 */
function mtucAud010F01R1_controllerApplicantGate($controllerClass, array $post, $shop = null, $onValid = null)
{
    if ($shop === null) {
        $shop = array('uni_proces' => 0, 'consents' => array());
    }
    $controller = mtucAud010F01R1_makeController($controllerClass, $post);
    $method = new ReflectionMethod($controllerClass, 'validateStep2Customer');
    $method->setAccessible(true);
    /** @var array{ok:bool,message:string,errors:array<string,string>} $validation */
    $validation = $method->invoke($controller, $shop);

    $proceeded = false;
    $onValidResult = null;
    if (!empty($validation['ok'])) {
        $proceeded = true;
        if (is_callable($onValid)) {
            $onValidResult = call_user_func($onValid);
        }
    }

    return array(
        'ok' => !empty($validation['ok']),
        'errors' => isset($validation['errors']) && is_array($validation['errors'])
            ? $validation['errors']
            : array(),
        'proceeded' => $proceeded,
        'on_valid_result' => $onValidResult,
    );
}

/**
 * @param int $n
 * @param string $ch
 * @return string
 */
function mtucAud010F01R1_chars($n, $ch = 'A')
{
    return str_repeat($ch, (int) $n);
}

$validator = new MtUniCreditStorefrontApplicantFieldValidator();
$malformed = mtucAud010F01R1_malformedUtf8();

// -------------------------------------------------------------------------
// Shared validator: malformed UTF-8 fail-closed
// -------------------------------------------------------------------------
mtucAud010F01R1_assert($validator->isValidUtf8('Иван') === true, 'valid Bulgarian UTF-8 accepted by helper');
mtucAud010F01R1_assert($validator->isValidUtf8('ASCII') === true, 'ASCII is valid UTF-8');
mtucAud010F01R1_assert($validator->isValidUtf8('') === true, 'empty string is valid UTF-8');
mtucAud010F01R1_assert($validator->isValidUtf8($malformed) === false, '0xC3 0x28 rejected by UTF-8 helper');
mtucAud010F01R1_assert($validator->characterLength($malformed) === null, 'length helper refuses malformed UTF-8');

$base = array(
    'firstname' => 'Йоан',
    'lastname' => 'Иванов',
    'email' => 'ioan@example.test',
    'telephone' => '0888123456',
    'address_1' => 'ул. България 10',
);
$ef = $validator->validate(array_merge($base, array('firstname' => $malformed)));
mtucAud010F01R1_assert(isset($ef['firstname']), 'malformed UTF-8 firstname rejected');
$el = $validator->validate(array_merge($base, array('lastname' => $malformed)));
mtucAud010F01R1_assert(isset($el['lastname']), 'malformed UTF-8 lastname rejected');
$ea = $validator->validate(array_merge($base, array('address_1' => $malformed)));
mtucAud010F01R1_assert(isset($ea['address']) && !isset($ea['address_1']), 'malformed UTF-8 address rejected (public key)');
$ee = $validator->validate(array_merge($base, array('email' => $malformed . '@x.test')));
mtucAud010F01R1_assert(isset($ee['email']), 'malformed UTF-8 email rejected');
$ep = $validator->validate(array_merge($base, array('telephone' => $malformed)));
mtucAud010F01R1_assert(isset($ep['phone']), 'malformed UTF-8 telephone rejected');

mtucAud010F01R1_assert(
    $validator->validate($base) === array(),
    'valid Bulgarian UTF-8 applicant accepted'
);
mtucAud010F01R1_assert(
    $validator->validate(array_merge($base, array(
        'firstname' => 'Анна Мария',
        'lastname' => 'Иванова Петрова',
        'address_1' => 'ул. България 10',
    ))) === array(),
    'compound Bulgarian names + address accepted'
);

// -------------------------------------------------------------------------
// Product controller: malformed UTF-8 → no side effects
// -------------------------------------------------------------------------
$transportP = new Phase4FakeCpHttpTransport();
$stackP = Phase9TestHarness::stack($transportP);
$orderIdP = 941001;
$addP = 0;
$smartBeforeP = count($stackP['smartUcfProbe']->calls);
$gateP = mtucAud010F01R1_controllerApplicantGate(
    'ControllerExtensionMtUniCreditProduct',
    mtucAud010F01R1_validPost(array('firstname' => $malformed)),
    array('uni_proces' => 0, 'consents' => array()),
    function () use ($stackP, $orderIdP, &$addP) {
        $input = Phase9TestHarness::productStorefrontInput($stackP, $orderIdP);
        $inner = $input['add_order'];
        $input['add_order'] = function ($orderData) use ($inner, &$addP) {
            $addP++;

            return call_user_func($inner, $orderData);
        };

        return $stackP['storefront']->submit($input);
    }
);
mtucAud010F01R1_assert($gateP['ok'] === false && isset($gateP['errors']['firstname']), 'Product malformed: validation failure');
mtucAud010F01R1_assert($gateP['proceeded'] === false, 'Product malformed: did not proceed past controller validation');
mtucAud010F01R1_assert($addP === 0, 'Product malformed: addOrder = 0');
mtucAud010F01R1_assert(
    $stackP['attempts']->findByStoreOrder($stackP['storeId'], $orderIdP) === null,
    'Product malformed: no attempt'
);
mtucAud010F01R1_assert(Phase7TestHarness::countOrderPosts($transportP) === 0, 'Product malformed: CP POST = 0');
mtucAud010F01R1_assert(
    count($stackP['smartUcfProbe']->calls) === $smartBeforeP,
    'Product malformed: SmartUCF call count unchanged (0)'
);

// -------------------------------------------------------------------------
// Cart controller: malformed UTF-8 → no side effects
// -------------------------------------------------------------------------
$transportC = new Phase4FakeCpHttpTransport();
$stackC = Phase9TestHarness::stack($transportC);
$orderIdC = 941002;
$addC = 0;
$smartBeforeC = count($stackC['smartUcfProbe']->calls);
$gateC = mtucAud010F01R1_controllerApplicantGate(
    'ControllerExtensionMtUniCreditCart',
    mtucAud010F01R1_validPost(array('firstname' => $malformed)),
    array('uni_proces' => 0, 'consents' => array()),
    function () use ($stackC, $orderIdC, &$addC) {
        $input = Phase9TestHarness::cartStorefrontInput($stackC, $orderIdC);
        $inner = $input['add_order'];
        $input['add_order'] = function ($orderData) use ($inner, &$addC) {
            $addC++;

            return call_user_func($inner, $orderData);
        };

        return $stackC['storefront']->submit($input);
    }
);
mtucAud010F01R1_assert($gateC['ok'] === false && isset($gateC['errors']['firstname']), 'Cart malformed: validation failure');
mtucAud010F01R1_assert($gateC['proceeded'] === false, 'Cart malformed: did not proceed past controller validation');
mtucAud010F01R1_assert($addC === 0, 'Cart malformed: addOrder = 0');
mtucAud010F01R1_assert(
    $stackC['attempts']->findByStoreOrder($stackC['storeId'], $orderIdC) === null,
    'Cart malformed: no attempt'
);
mtucAud010F01R1_assert(Phase7TestHarness::countOrderPosts($transportC) === 0, 'Cart malformed: CP POST = 0');
mtucAud010F01R1_assert(
    count($stackC['smartUcfProbe']->calls) === $smartBeforeC,
    'Cart malformed: SmartUCF call count unchanged (0)'
);

// -------------------------------------------------------------------------
// Well-formed overlength via controllers
// -------------------------------------------------------------------------
$overFirst = mtucAud010F01R1_chars(33, 'Ж');
$gatePO = mtucAud010F01R1_controllerApplicantGate(
    'ControllerExtensionMtUniCreditProduct',
    mtucAud010F01R1_validPost(array('firstname' => $overFirst)),
    null,
    function () {
        throw new RuntimeException('Product overlength must not proceed');
    }
);
mtucAud010F01R1_assert(
    $gatePO['ok'] === false && isset($gatePO['errors']['firstname']) && $gatePO['proceeded'] === false,
    'Product overlength firstname rejected by controller path'
);

$gateCO = mtucAud010F01R1_controllerApplicantGate(
    'ControllerExtensionMtUniCreditCart',
    mtucAud010F01R1_validPost(array('address' => mtucAud010F01R1_chars(129, 'ж'))),
    null,
    function () {
        throw new RuntimeException('Cart overlength must not proceed');
    }
);
mtucAud010F01R1_assert(
    $gateCO['ok'] === false && isset($gateCO['errors']['address']) && $gateCO['proceeded'] === false,
    'Cart overlength address rejected by controller path'
);

// -------------------------------------------------------------------------
// Retry after correction (no lock/attempt poisoning)
// -------------------------------------------------------------------------
$transportR = new Phase4FakeCpHttpTransport();
$stackR = Phase9TestHarness::stack($transportR);
$orderIdR = 941003;
$addR = 0;

$gateBad = mtucAud010F01R1_controllerApplicantGate(
    'ControllerExtensionMtUniCreditProduct',
    mtucAud010F01R1_validPost(array('firstname' => $malformed)),
    null,
    function () {
        throw new RuntimeException('invalid must not submit');
    }
);
mtucAud010F01R1_assert($gateBad['ok'] === false && $gateBad['proceeded'] === false, 'retry step1: invalid rejected');
mtucAud010F01R1_assert(
    $stackR['attempts']->findByStoreOrder($stackR['storeId'], $orderIdR) === null,
    'retry step1: no attempt after invalid'
);

$gateGood = mtucAud010F01R1_controllerApplicantGate(
    'ControllerExtensionMtUniCreditProduct',
    mtucAud010F01R1_validPost(),
    null,
    function () use ($stackR, $orderIdR, &$addR) {
        $input = Phase9TestHarness::productStorefrontInput($stackR, $orderIdR);
        $inner = $input['add_order'];
        $input['add_order'] = function ($orderData) use ($inner, &$addR) {
            $addR++;

            return call_user_func($inner, $orderData);
        };

        return $stackR['storefront']->submit($input);
    }
);
mtucAud010F01R1_assert($gateGood['ok'] === true && $gateGood['proceeded'] === true, 'retry step2: corrected accepted by controller');
mtucAud010F01R1_assert($addR === 1, 'retry step2: addOrder executed after correction');
mtucAud010F01R1_assert(
    $stackR['attempts']->findByStoreOrder($stackR['storeId'], $orderIdR) !== null,
    'retry step2: attempt created only after corrected submit'
);

// -------------------------------------------------------------------------
// Shared architecture + no silent sanitization
// -------------------------------------------------------------------------
$valSrc = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'storefront_applicant_field_validator.php');
mtucAud010F01R1_assert(
    strpos($valSrc, 'mb_check_encoding') !== false || strpos($valSrc, "preg_match('//u'") !== false,
    'validator encodes UTF-8 validity check'
);
mtucAud010F01R1_assert(
    strpos($valSrc, '//IGNORE') === false
        && strpos($valSrc, 'iconv(') === false
        && strpos($valSrc, 'utf8_encode') === false,
    'no silent sanitization / recoding of malformed UTF-8'
);
$productSrc = (string) file_get_contents($ctrlDir . DIRECTORY_SEPARATOR . 'product.php');
$cartSrc = (string) file_get_contents($ctrlDir . DIRECTORY_SEPARATOR . 'cart.php');
mtucAud010F01R1_assert(
    strpos($productSrc, 'MtUniCreditStorefrontApplicantFieldValidator') !== false
        && strpos($cartSrc, 'MtUniCreditStorefrontApplicantFieldValidator') !== false,
    'Product and Cart still share ApplicantFieldValidator'
);

// -------------------------------------------------------------------------
if (count($failures) > 0) {
    echo 'AUD-010 F01-R1: FAIL (' . count($failures) . ' failures, ' . $passes . ' passes)' . PHP_EOL;
    exit(1);
}

echo 'AUD-010 F01-R1: PASS (' . $passes . ' passes)' . PHP_EOL;
exit(0);
