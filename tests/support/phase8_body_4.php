<?php

/**
 * Included from mtuc8_run() — inherits that function scope (continues body_3).
 *
 * @var string $root
 * @var string $lib
 * @var string $catalog
 * @var array<string, mixed> $shop
 * @var MtUniCreditStorefrontCalculatorPresenter $presenter
 * @var MtUniCreditProductContext $product
 * @var array<string, mixed> $eligible
 */

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

// --- Popup Step 1 visual + runtime parity ---
$modalTwig = (string) file_get_contents(
    $catalog . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'theme' . DIRECTORY_SEPARATOR
        . 'default' . DIRECTORY_SEPARATOR . 'template' . DIRECTORY_SEPARATOR . 'extension'
        . DIRECTORY_SEPARATOR . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'modal.twig'
);
$cssPopup = (string) file_get_contents(
    $catalog . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'theme' . DIRECTORY_SEPARATOR
        . 'default' . DIRECTORY_SEPARATOR . 'template' . DIRECTORY_SEPARATOR . 'extension'
        . DIRECTORY_SEPARATOR . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'storefront.css'
);
$jsPopup = (string) file_get_contents(
    $catalog . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'theme' . DIRECTORY_SEPARATOR
        . 'default' . DIRECTORY_SEPARATOR . 'template' . DIRECTORY_SEPARATOR . 'extension'
        . DIRECTORY_SEPARATOR . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'storefront.js'
);
$productLangBg = (string) file_get_contents(
    $catalog . DIRECTORY_SEPARATOR . 'language' . DIRECTORY_SEPARATOR . 'bg-bg' . DIRECTORY_SEPARATOR
        . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'product.php'
);
$cartLangBg = (string) file_get_contents(
    $catalog . DIRECTORY_SEPARATOR . 'language' . DIRECTORY_SEPARATOR . 'bg-bg' . DIRECTORY_SEPARATOR
        . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'cart.php'
);

mtuc8_assert(
    strpos($cssPopup, '#mt-uni-credit-product-modal') !== false
        && strpos($cssPopup, '#mt-uni-credit-cart-modal') !== false,
    'popup CSS shared Product/Cart modal selectors'
);
mtuc8_assert(strpos($cssPopup, 'max-width: 900px') !== false, 'popup shell max-width 900px');
mtuc8_assert(strpos($cssPopup, 'background: #fefefe') !== false, 'popup shell white/#fefefe background');
mtuc8_assert(
    strpos($cssPopup, '0 4px 8px rgba(0, 0, 0, .2)') !== false
        && strpos($cssPopup, '0 6px 20px rgba(0, 0, 0, .19)') !== false,
    'popup shell OC4 box-shadow'
);
mtuc8_assert(strpos($cssPopup, 'rgba(0, 0, 0, .4)') !== false, 'popup overlay rgba(.4)');
mtuc8_assert(
    strpos($cssPopup, 'border: 2px solid') !== false
        && strpos($cssPopup, 'border-radius: 14.5px 14.5px 80px 14.5px') !== false,
    'calculator frame asymmetric radius'
);
mtuc8_assert(
    strpos($cssPopup, '.mt-uni-credit-storefront__processing[hidden]') !== false
        && strpos($cssPopup, 'display: none !important') !== false,
    'processing[hidden] forces display:none'
);
mtuc8_assert(strpos($cssPopup, 'color: #000') !== false, 'popup labels black');
mtuc8_assert(strpos($cssPopup, '--mtuc-popup-red: #ed1c24') !== false, 'popup values red token');

mtuc8_assert(strpos($modalTwig, 'Избор на схема за лизинг') !== false || strpos($productLangBg, 'Избор на схема за лизинг') !== false, 'Step 1 title language present');
mtuc8_assert(strpos($modalTwig, 'text_modal_title_scheme') !== false, 'modal uses text_modal_title_scheme');
mtuc8_assert(strpos($modalTwig, 'mt-uni-credit-storefront__banner') !== false, 'modal banner block');
mtuc8_assert(strpos($modalTwig, 'modal_meta.banner_url') !== false, 'modal renders CP banner_url');
mtuc8_assert(strpos($modalTwig, 'modal_meta.banner_link') !== false, 'modal renders CP banner_link');
mtuc8_assert(strpos($modalTwig, 'target="_blank"') !== false && strpos($modalTwig, 'noopener noreferrer') !== false, 'banner security target/rel');
mtuc8_assert(strpos($modalTwig, 'data-mtuc-display="price"') !== false, 'row Цена / price display');
mtuc8_assert(strpos($modalTwig, 'data-mtuc-schemes') !== false, 'row months/schemes');
mtuc8_assert(strpos($modalTwig, 'data-mtuc-first') !== false, 'row first installment input');
mtuc8_assert(strpos($modalTwig, 'data-mtuc-display="financed_amount"') !== false, 'row financed');
mtuc8_assert(strpos($modalTwig, 'data-mtuc-display="monthly_installment"') !== false, 'row monthly');
mtuc8_assert(strpos($modalTwig, 'data-mtuc-display="total_payable"') !== false, 'row total');
mtuc8_assert(strpos($modalTwig, 'data-mtuc-display="glp"') !== false, 'row GLP');
mtuc8_assert(strpos($modalTwig, 'data-mtuc-display="gpr"') !== false, 'row GPR');
mtuc8_assert(strpos($modalTwig, 'pattern="[0-9]*"') !== false, 'first installment numeric-only input');
mtuc8_assert(strpos($modalTwig, 'popup-actions-left') !== false && strpos($modalTwig, 'popup-actions-right') !== false, 'footer left/right structure');
mtuc8_assert(strpos($modalTwig, 'data-mtuc-dismiss') !== false, 'footer Cancel');
mtuc8_assert(strpos($modalTwig, 'data-mtuc-secondary') !== false, 'footer Add/Buy');
mtuc8_assert(strpos($modalTwig, 'data-mtuc-apply') !== false, 'footer Apply');
mtuc8_assert(strpos($modalTwig, 'badge_url') !== false && strpos($modalTwig, '<i style="background-image:url') !== false, 'Apply badge image via i');
mtuc8_assert(strpos($modalTwig, 'data-mtuc-processing') !== false && strpos($modalTwig, 'hidden>') !== false, 'processing starts hidden');
mtuc8_assert(strpos($modalTwig, 'role="dialog"') !== false && strpos($modalTwig, 'aria-modal="true"') !== false, 'dialog a11y');

mtuc8_assert(strpos($productLangBg, 'Цена на артикула') !== false, 'product label Цена на артикула');
mtuc8_assert(strpos($productLangBg, 'Брой месеци за погасяване') !== false, 'product label months');
mtuc8_assert(strpos($productLangBg, 'Обща сума на заема') !== false, 'product label financed');
mtuc8_assert(strpos($productLangBg, 'Размер на погасителна вноска') !== false, 'product label monthly');
mtuc8_assert(strpos($productLangBg, 'Обща дължима сума') !== false, 'product label total');
mtuc8_assert(strpos($productLangBg, "'ГЛП'") !== false || strpos($productLangBg, '= \'ГЛП\'') !== false, 'product label GLP');
mtuc8_assert(strpos($productLangBg, "'ГПР'") !== false || strpos($productLangBg, '= \'ГПР\'') !== false, 'product label GPR');
mtuc8_assert(strpos($productLangBg, 'Добави в количката') !== false, 'secondary Add to cart wording');
mtuc8_assert(strpos($cartLangBg, 'Стойност на поръчката') !== false, 'cart price label');

$modalPresenter = MtUniCreditStorefrontModalPresenter::present(
    array(
        'uni_picture' => 'https://cdn.example/banner.jpg',
        'uni_picturem' => 'https://cdn.example/banner-m.jpg',
        'reklama_url' => 'https://cdn.example/click',
        'uni_backurl' => 'https://fallback.example/',
    ),
    'EUR'
);
mtuc8_assert($modalPresenter['banner_url'] === 'https://cdn.example/banner.jpg', 'modal presenter banner_url');
mtuc8_assert($modalPresenter['banner_url_mobile'] === 'https://cdn.example/banner-m.jpg', 'modal presenter banner mobile');
mtuc8_assert($modalPresenter['banner_link'] === 'https://cdn.example/click', 'modal presenter banner_link from reklama_url');
mtuc8_assert($modalPresenter['text_first_installment'] === 'Първоначална вноска /евро/', 'EUR first installment label');
$modalPresenterBgn = MtUniCreditStorefrontModalPresenter::present(array(), 'BGN');
mtuc8_assert($modalPresenterBgn['text_first_installment'] === 'Първоначална вноска /лв./', 'BGN first installment label');
$modalPresenterFallback = MtUniCreditStorefrontModalPresenter::present(
    array('uni_backurl' => 'https://fallback.example/ads'),
    'BGN'
);
mtuc8_assert($modalPresenterFallback['banner_link'] === 'https://fallback.example/ads', 'banner_link falls back to uni_backurl');

$schemeWithGlp = null;
foreach ($eligible['offers']['standard']['schemes'] as $schemeRow) {
    if (isset($schemeRow['glp']) && isset($schemeRow['gpr'])) {
        $schemeWithGlp = $schemeRow;
        break;
    }
}
mtuc8_assert(is_array($schemeWithGlp), 'presenter schemes expose glp/gpr');

$parsedKey = MtUniCreditStorefrontCalculatorPresenter::parseSchemeKey($schemeWithGlp['key']);
mtuc8_assert(is_array($parsedKey), 'scheme key parseable for recalculate');
$foundScheme = $presenter->findProductScheme($shop, $product, $parsedKey);
mtuc8_assert($foundScheme instanceof MtUniCreditAvailableScheme, 'findProductScheme resolves scheme');
$recalc = $presenter->presentSchemeCalculation($shop, (float) $eligible['price'], $foundScheme, 50.0);
mtuc8_assert(isset($recalc['financed_amount'], $recalc['monthly_installment'], $recalc['total_payable'], $recalc['glp'], $recalc['gpr']), 'recalculate payload fields');
mtuc8_assert((float) $recalc['first_installment'] >= 0, 'recalculate first installment numeric');

mtuc8_assert(strpos($jsPopup, 'function setProcessing') !== false || strpos($jsPopup, 'setProcessing(active)') !== false, 'JS setProcessing present');
mtuc8_assert(strpos($jsPopup, 'setProcessing(false)') !== false, 'JS clears processing on terminal paths');
mtuc8_assert(strpos($jsPopup, 'scheduleRecalculate') !== false || strpos($jsPopup, 'runRecalculate') !== false, 'JS recalculate path');
mtuc8_assert(strpos($jsPopup, 'data-mtuc-display="financed_amount"') !== false, 'JS fills financed_amount');
mtuc8_assert(strpos($jsPopup, 'data-mtuc-display="glp"') !== false, 'JS fills glp');
mtuc8_assert(strpos($jsPopup, 'setStep(2)') !== false, 'JS Apply → setStep(2)');
mtuc8_assert(
    strpos($jsPopup, 'mt-uni-credit-storefront__step--active') !== false,
    'JS Step transition toggles --active class'
);
mtuc8_assert(
    strpos($jsPopup, 'is-transitioning-out') !== false
        && strpos($jsPopup, 'is-transitioning-in') !== false,
    'JS Step transition opacity classes present'
);
mtuc8_assert(
    strpos($jsPopup, 'scheme.label') !== false || strpos($jsPopup, 'scheme.description') !== false,
    'JS scheme option uses label/description not months-only'
);
mtuc8_assert(
    strpos($jsPopup, 'data-preferred-key') !== false,
    'JS openModal uses clicked button preferred-key'
);
mtuc8_assert(
    strpos($jsPopup, '.trigger("focus")') !== false || strpos($jsPopup, ".trigger('focus')") !== false,
    'JS focuses Step 2 field after transition'
);

mtuc8_assert(strpos($productCtrl, 'function recalculate') !== false, 'product recalculate endpoint');
mtuc8_assert(strpos($cartCtrl, 'function recalculate') !== false, 'cart recalculate endpoint');
mtuc8_assert(strpos($productCtrl, 'modal_meta') !== false && strpos($cartCtrl, 'modal_meta') !== false, 'controllers pass modal_meta');
mtuc8_assert(strpos($productCtrl, 'badge_url') !== false && strpos($cartCtrl, 'badge_url') !== false, 'controllers pass badge_url');
mtuc8_assert(
    strpos($productCtrl, 'route_recalculate') !== false && strpos($cartCtrl, 'route_recalculate') !== false,
    'controllers pass route_recalculate'
);

$imageDir = $catalog . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'theme' . DIRECTORY_SEPARATOR
    . 'default' . DIRECTORY_SEPARATOR . 'template' . DIRECTORY_SEPARATOR . 'extension'
    . DIRECTORY_SEPARATOR . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'image';
$badgeFs = $imageDir . DIRECTORY_SEPARATOR . 'uni_mini_logo.png';
$watermarkFs = $imageDir . DIRECTORY_SEPARATOR . 'popup-calc-bg.png';
mtuc8_assert(is_file($badgeFs), 'module-local uni_mini_logo.png present');
mtuc8_assert(is_file($watermarkFs), 'module-local popup-calc-bg.png present');
mtuc8_assert(
    MtUniCreditConstants::STOREFRONT_APPLY_BADGE_RELATIVE
        === 'catalog/view/theme/default/template/extension/mt_uni_credit/image/uni_mini_logo.png',
    'APPLY badge constant uses uni_mini_logo.png'
);
mtuc8_assert(
    MtUniCreditConstants::STOREFRONT_POPUP_CALC_BG_RELATIVE
        === 'catalog/view/theme/default/template/extension/mt_uni_credit/image/popup-calc-bg.png',
    'popup calc bg constant module-local'
);
mtuc8_assert(
    strpos(MtUniCreditConstants::STOREFRONT_APPLY_BADGE_RELATIVE, 'catalog/view/image/') === false
        && strpos(MtUniCreditConstants::STOREFRONT_POPUP_CALC_BG_RELATIVE, 'catalog/view/image/') === false,
    'badge/watermark paths avoid forbidden catalog/view/image'
);
mtuc8_assert(
    strpos($cssPopup, 'image/popup-calc-bg.png') !== false
        && strpos($cssPopup, 'background-size: 150px 150px') !== false,
    'CSS calculator watermark references module-local popup-calc-bg'
);
mtuc8_assert(strpos($cssPopup, 'margin-bottom: 12px') !== false, 'CSS Step 1 row spacing 12px');
mtuc8_assert(
    strpos($cssPopup, 'is-transitioning-out') !== false
        && strpos($cssPopup, 'transition: opacity 0.35s ease') !== false,
    'CSS Step transition opacity present'
);

$packageScriptPopup = (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'package.ps1');
mtuc8_assert(
    strpos($packageScriptPopup, 'uni_mini_logo.png') !== false
        && strpos($packageScriptPopup, 'popup-calc-bg.png') !== false,
    'package expects mini logo and calc watermark'
);

// --- Scheme identity fixture (first button full list + 0% button subset) ---
$popupFilter = function ($id, $months, $promo, $kop, $desc) {
    return array(
        'id' => $id,
        'category_id' => 7,
        'product_id' => null,
        'uni_meseci' => (string) $months,
        'uni_price_from' => 100,
        'uni_price_to' => 10000,
        'uni_promo' => $promo,
        'uni_parva' => 0,
        'uni_date_from' => null,
        'uni_date_to' => null,
        'uni_kop' => $kop,
        'uni_kop_desc' => $desc,
    );
};
$popupShop = mtuc3_typekop1_shop(
    array(
        $popupFilter(1, 6, 0, 'STD', ''),
        $popupFilter(2, 6, 1, 'PROMO', 'промо 0%'),
        $popupFilter(3, 9, 0, 'STD', ''),
        $popupFilter(4, 12, 0, 'STD', ''),
        $popupFilter(5, 12, 0, 'CAT', 'промо компютри'),
        $popupFilter(6, 18, 0, 'STD', ''),
        $popupFilter(7, 12, 1, 'PROMO', 'промо 0%'),
    ),
    array(
        'uni_shema_current' => 12,
        'uni_meseci_6' => 1,
        'uni_meseci_9' => 1,
        'uni_meseci_12' => 1,
        'uni_meseci_18' => 1,
        'uni_vnoska' => 1,
        'kop' => array(
            'by_default' => array(
                'uni_kop_default' => 'STD',
                'uni_kop_default_desc' => '',
                'uni_kop_promo' => 'PROMO',
                'uni_kop_promo_desc' => 'промо 0%',
            ),
        ),
        'coeff_list' => array(
            array('onlineProductCode' => 'STD', 'installmentCount' => 6, 'coeff' => 0.18, 'interestPercent' => 20),
            array('onlineProductCode' => 'STD', 'installmentCount' => 9, 'coeff' => 0.12, 'interestPercent' => 19),
            array('onlineProductCode' => 'STD', 'installmentCount' => 12, 'coeff' => 0.095, 'interestPercent' => 18),
            array('onlineProductCode' => 'STD', 'installmentCount' => 18, 'coeff' => 0.07, 'interestPercent' => 17),
            array('onlineProductCode' => 'CAT', 'installmentCount' => 12, 'coeff' => 0.09, 'interestPercent' => 10),
            array('onlineProductCode' => 'PROMO', 'installmentCount' => 6, 'coeff' => 0.166667, 'interestPercent' => 0),
            array('onlineProductCode' => 'PROMO', 'installmentCount' => 12, 'coeff' => 0.083333, 'interestPercent' => 0),
        ),
    )
);
$popupProduct = new MtUniCreditProductContext(42, array(7), 800.0);
$popupPresented = $presenter->presentProduct($popupShop, $popupProduct, 'BGN');
mtuc8_assert(is_array($popupPresented) && isset($popupPresented['offers']['standard']['schemes']), 'popup fixture standard offer present');
$firstSchemes = $popupPresented['offers']['standard']['schemes'];
$firstCats = array();
foreach ($firstSchemes as $row) {
    $firstCats[] = $row['months'] . ':' . $row['presentation_category'];
}
mtuc8_assert(count($firstSchemes) >= 6, 'first-button popup has >=6 schemes (no month collapse)');
mtuc8_assert(
    $firstCats === array(
        '6:standard',
        '6:zero_promo',
        '9:standard',
        '12:standard',
        '12:nonzero_promo',
        '12:zero_promo',
        '18:standard',
    ) || (
        in_array('6:standard', $firstCats, true)
        && in_array('6:zero_promo', $firstCats, true)
        && in_array('9:standard', $firstCats, true)
        && in_array('12:standard', $firstCats, true)
        && in_array('12:nonzero_promo', $firstCats, true)
        && in_array('18:standard', $firstCats, true)
    ),
    'first-button collection includes standard/promo-nonzero/promo-0% without month dedupe'
);
mtuc8_assert(
    $firstCats === array(
        '6:standard',
        '6:zero_promo',
        '9:standard',
        '12:standard',
        '12:nonzero_promo',
        '12:zero_promo',
        '18:standard',
    ),
    'first-button ordering months ASC then standard→nonzero→zero'
);

$twelveStandard = null;
$twelveComputers = null;
$twelveZero = null;
foreach ($firstSchemes as $row) {
    if ((int) $row['months'] !== 12) {
        continue;
    }
    if ($row['presentation_category'] === 'standard') {
        $twelveStandard = $row;
    }
    if ($row['presentation_category'] === 'nonzero_promo') {
        $twelveComputers = $row;
    }
    if ($row['presentation_category'] === 'zero_promo') {
        $twelveZero = $row;
    }
}
mtuc8_assert(is_array($twelveStandard) && is_array($twelveComputers), '12m standard and promo computers coexist');
mtuc8_assert($twelveStandard['key'] !== $twelveComputers['key'], 'same-month schemes keep distinct keys');
mtuc8_assert(
    strpos($twelveComputers['label'], 'промо компютри') !== false
        && strpos($twelveComputers['label'], '12 месеца') !== false,
    'promo computers option label preserves description'
);
mtuc8_assert(
    $twelveStandard['label'] === '12 месеца'
        || strpos($twelveStandard['label'], '12 месеца') === 0,
    'standard 12m label is months-only when no description'
);

// Prefer CAT 12 as first-button preferred when it is the preferred scheme for that offer.
$popupPresented['offers']['standard']['preferred_scheme_key'] = $twelveComputers['key'];
mtuc8_assert(
    $popupPresented['offers']['standard']['preferred_scheme_key'] === $twelveComputers['key'],
    'first-button initial selection can target promo computers identity'
);
mtuc8_assert(
    $popupPresented['offers']['standard']['preferred_scheme_key'] !== $twelveStandard['key'],
    'initial selection is not collapsed to 12m standard'
);

mtuc8_assert(isset($popupPresented['offers']['promo']['schemes']), '0% button offer present');
$promoSchemes = $popupPresented['offers']['promo']['schemes'];
$promoCats = array();
foreach ($promoSchemes as $row) {
    $promoCats[] = $row['presentation_category'];
    mtuc8_assert($row['presentation_category'] === 'zero_promo' || !empty($row['zero_interest_promo']), '0% popup scheme is zero-interest');
    mtuc8_assert($row['presentation_category'] !== 'standard', '0% popup excludes standard');
    mtuc8_assert($row['presentation_category'] !== 'nonzero_promo', '0% popup excludes nonzero promo');
}
mtuc8_assert(count($promoSchemes) >= 2, '0% button contains multiple 0% schemes');
mtuc8_assert(
    $popupPresented['offers']['promo']['preferred_scheme_key'] !== '',
    '0% button preferred_scheme_key set'
);

// Same-month recalculation identity change
$calcStd = $presenter->presentSchemeCalculation(
    $popupShop,
    800.0,
    $presenter->findProductScheme($popupShop, $popupProduct, MtUniCreditStorefrontCalculatorPresenter::parseSchemeKey($twelveStandard['key'])),
    0.0
);
$calcPromo = $presenter->presentSchemeCalculation(
    $popupShop,
    800.0,
    $presenter->findProductScheme($popupShop, $popupProduct, MtUniCreditStorefrontCalculatorPresenter::parseSchemeKey($twelveComputers['key'])),
    0.0
);
mtuc8_assert(
    (float) $calcStd['monthly_installment'] !== (float) $calcPromo['monthly_installment']
        || (float) $calcStd['glp'] !== (float) $calcPromo['glp'],
    '12 standard vs 12 promo computers recalculation differs'
);
mtuc8_assert(is_array($twelveZero), '12m three-way includes zero promo');
