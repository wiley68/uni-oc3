<?php
// OCMOD anchors — frozen Product template strategy
$installXml = (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'install.xml');
mtuc8_assert(strpos($installXml, 'mt_uni_credit:product') !== false, 'OCMOD product marker');
mtuc8_assert(strpos($installXml, 'mt_uni_credit:cart') !== false, 'OCMOD cart marker');
mtuc8_assert(strpos($installXml, '$data[\'products\'] = array();') !== false, 'OCMOD product controller anchor');
mtuc8_assert(strpos($installXml, '{{ content_bottom }}</div>') !== false, 'OCMOD cart template anchor');
mtuc8_assert(
    strpos($installXml, 'catalog/view/theme/*/template/product/product.twig" error="abort"') !== false,
    'OCMOD product twig file uses error=abort'
);
mtuc8_assert(
    preg_match(
        '/product\/product\.twig"\s+error="abort">[\s\S]*?<search><!\[CDATA\[\s*\{% if minimum > 1 %\}\s*\]\]><\/search>/',
        $installXml
    ) === 1,
    'OCMOD product search is exactly {% if minimum > 1 %}'
);
mtuc8_assert(
    preg_match(
        '/product\/product\.twig"\s+error="abort">[\s\S]*?<add position="before">/',
        $installXml
    ) === 1,
    'OCMOD product add position is before'
);
mtuc8_assert(
    strpos($installXml, 'text_minimum') === false
        && strpos($installXml, '{% endif %}</div>') === false,
    'OCMOD previous brittle multi-line product anchor is gone'
);
mtuc8_assert(
    strpos($installXml, 'checkout/cart.twig" error="skip"') !== false,
    'OCMOD cart theme still uses error=skip'
);
mtuc8_assert(!preg_match('/<search[^>]*>[^<]*\\.\\*/', $installXml), 'OCMOD no broad .* regex search');

$referenceProductTwig = 'c:\\Projects\\reference-oc3-core\\catalog\\view\\theme\\default\\template\\product\\product.twig';
mtuc8_assert(is_file($referenceProductTwig), 'reference OC3 default product.twig present');
$refTwig = (string) file_get_contents($referenceProductTwig);
$anchor = '{% if minimum > 1 %}';
mtuc8_assert(strpos($refTwig, $anchor) !== false, 'reference product.twig contains frozen minimum anchor');
mtuc8_assert(substr_count($refTwig, $anchor) === 1, 'reference product.twig minimum anchor matches exactly once');


// Phase 7 regression: prepared without submitCheckoutFinancing
$paymentController = (string) file_get_contents(
    $catalog . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'extension'
        . DIRECTORY_SEPARATOR . 'payment' . DIRECTORY_SEPARATOR . 'mt_uni_credit.php'
);
if (preg_match('/function\\s+prepared\\s*\\([^)]*\\)\\s*\\{/', $paymentController, $m, PREG_OFFSET_CAPTURE)) {
    $start = (int) $m[0][1] + strlen($m[0][0]);
    $depth = 1;
    $len = strlen($paymentController);
    $body = '';
    for ($i = $start; $i < $len; $i++) {
        $ch = $paymentController[$i];
        if ($ch === '{') {
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                $body = substr($paymentController, $start, $i - $start);
                break;
            }
        }
    }
    mtuc8_assert(strpos($body, 'submitCheckoutFinancing') === false, 'prepared() still without submitCheckoutFinancing');
} else {
    mtuc8_assert(false, 'prepared() method extractable');
}

// Storefront controllers must not mention SmartUCF
$productCtrl = (string) file_get_contents(
    $catalog . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'extension'
        . DIRECTORY_SEPARATOR . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'product.php'
);
$cartCtrl = (string) file_get_contents(
    $catalog . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'extension'
        . DIRECTORY_SEPARATOR . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'cart.php'
);
mtuc8_assert(stripos($productCtrl, 'SmartUCF') === false, 'product controller no SmartUCF');
mtuc8_assert(stripos($cartCtrl, 'SmartUCF') === false, 'cart controller no SmartUCF');

// Idempotent double materialize
$transport = new Phase4FakeCpHttpTransport();
$payloads = Phase7TestHarness::loginAndOrderSuccessPayloads();
$transport->enqueueJson(200, $payloads['login']);
$transport->enqueueJson(201, $payloads['order']);
$stack = Phase7TestHarness::stack($transport);
$addCount = 0;
$credentials = MtUniCreditBootstrap::credentialsRepositoryFromDb($stack['db']);
$service = new MtUniCreditStorefrontFinancingSubmissionService(
    $stack['attempts'],
    $stack['locks'],
    $stack['lifecycle'],
    $credentials,
    new MtUniCreditShopConfigurationCache(
        new MtUniCreditShopCacheRepository($stack['db'], new MtUniCreditPersistenceClock(function () {
            return Phase7TestHarness::NOW;
        })),
        null,
        MtUniCreditBootstrap::shopCachePersistenceFromDb($stack['db'])
    )
);
$line = new MtUniCreditProductLine(42, 'Example', 'EX', array(7), 1, 500.0, 500.0, 500.0, 0, array(), 0);
$schemeKey = MtUniCreditStorefrontCalculatorPresenter::schemeKey('standard', 'KOPSTD', 12, 0);
$sessionBind = array();
$inputBase = array(
    'entry_point' => MtUniCreditOperationEntryPoint::PRODUCT,
    'store_id' => (int) $stack['storeId'],
    'currency_code' => 'BGN',
    'scheme_key' => $schemeKey,
    'product_line' => $line,
    'customer' => array(
        'firstname' => 'A',
        'lastname' => 'B',
        'email' => 'a@b.test',
        'telephone' => '0888',
        'address_1' => 'x',
        'city' => 'Sofia',
        'postcode' => '1000',
        'country_id' => 33,
        'zone_id' => 1,
    ),
    'invoice_prefix' => 'INV',
    'store_name' => 'Store',
    'store_url' => 'https://example.test/',
    'language_id' => 1,
    'currency_id' => 1,
    'currency_value' => 1.0,
    'add_order' => function ($orderData) use (&$addCount) {
        $addCount++;
        return 91001;
    },
    'load_order' => function ($orderId) use ($stack) {
        return Phase7TestHarness::orderRow((int) $orderId, (int) $stack['storeId'], 500.0);
    },
);

$input1 = $inputBase;
$input1['session'] = $sessionBind;
$result1 = $service->submit($input1);
mtuc8_assert(!empty($result1['order_id']), 'storefront submit first pass creates/binds order');
if (isset($result1['session']) && is_array($result1['session'])) {
    $sessionBind = $result1['session'];
}
$input2 = $inputBase;
$input2['session'] = $sessionBind;
$transport->enqueueJson(200, $payloads['login']);
$transport->enqueueJson(201, $payloads['order']);
$result2 = $service->submit($input2);
mtuc8_assert($addCount === 1, 'storefront submission idempotent addOrder counted once');

