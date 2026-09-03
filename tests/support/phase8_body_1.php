<?php
$required = array(
    'storefront_asset_urls.php',
    'storefront_csrf.php',
    'product_buy_preference.php',
    'product_line.php',
    'oc3_product_line_resolver.php',
    'storefront_calculator_presenter.php',
    'storefront_order_draft_builder.php',
    'storefront_operation_identity.php',
    'storefront_financing_submission_service.php',
    'storefront_runtime.php',
);
foreach ($required as $file) {
    mtuc8_assert(is_file($lib . DIRECTORY_SEPARATOR . $file), 'required library: ' . $file);
}

$requiredCatalog = array(
    'controller/extension/mt_uni_credit/product.php',
    'controller/extension/mt_uni_credit/cart.php',
    'model/extension/mt_uni_credit/product.php',
    'model/extension/mt_uni_credit/cart.php',
    'view/theme/default/template/extension/mt_uni_credit/product_widget.twig',
    'view/theme/default/template/extension/mt_uni_credit/cart_widget.twig',
    'view/theme/default/template/extension/mt_uni_credit/modal.twig',
    'view/theme/default/template/extension/mt_uni_credit/storefront.css',
    'view/theme/default/template/extension/mt_uni_credit/storefront.js',
    'language/bg-bg/extension/mt_uni_credit/product.php',
    'language/bg-bg/extension/mt_uni_credit/cart.php',
    'language/en-gb/extension/mt_uni_credit/product.php',
    'language/en-gb/extension/mt_uni_credit/cart.php',
);
foreach ($requiredCatalog as $relative) {
    mtuc8_assert(
        is_file($catalog . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative)),
        'required catalog: ' . $relative
    );
}

mtuc8_assert(
    MtUniCreditConstants::PRODUCT_ROUTE === 'extension/mt_uni_credit/product',
    'PRODUCT_ROUTE constant'
);
mtuc8_assert(
    MtUniCreditConstants::CART_ROUTE === 'extension/mt_uni_credit/cart',
    'CART_ROUTE constant'
);

// Presenter eligible / ineligible
$shop = mtuc3_golden_shop(array('uni_eur' => 0, 'uni_vnoska' => 1));
$presenter = new MtUniCreditStorefrontCalculatorPresenter();
$product = new MtUniCreditProductContext(42, array(7), 500.0);
$eligible = $presenter->presentProduct($shop, $product, 'BGN');
mtuc8_assert(is_array($eligible) && isset($eligible['offers']['standard']), 'product presenter eligible');
$ineligible = $presenter->presentProduct($shop, new MtUniCreditProductContext(42, array(7), 1.0), 'BGN');
mtuc8_assert($ineligible === null, 'product presenter ineligible low amount');
$wrongCurrency = $presenter->presentProduct($shop, $product, 'USD');
mtuc8_assert($wrongCurrency === null, 'product presenter rejects unsupported currency');

// Quantity scales financing price in resolver
$resolver = new MtUniCreditOc3ProductLineResolver(
    function ($price, $taxClassId) {
        return (float) $price * 1.2;
    },
    function ($amount, $from, $to) {
        return (float) $amount;
    },
    function () {
        return array(3, 5);
    }
);
$lineQty1 = $resolver->resolve(
    array('product_id' => 9, 'price' => 100.0, 'tax_class_id' => 1, 'name' => 'P', 'model' => 'M'),
    1,
    array(),
    'BGN',
    'BGN'
);
$lineQty3 = $resolver->resolve(
    array('product_id' => 9, 'price' => 100.0, 'tax_class_id' => 1, 'name' => 'P', 'model' => 'M'),
    3,
    array(),
    'BGN',
    'BGN'
);
mtuc8_assert(
    abs($lineQty1->financingPrice - 120.0) < 0.0001,
    'resolver qty1 financingPrice = unitWithTax'
);
mtuc8_assert(
    abs($lineQty3->financingPrice - 360.0) < 0.0001,
    'resolver qty3 scales financingPrice'
);
mtuc8_assert($lineQty3->categories === array(3, 5), 'resolver categories from loader');

// Cart intersection empty vs eligible
$calculator = new MtUniCreditCalculator();
$cartResolver = new MtUniCreditCartSchemeResolver($calculator);
$emptyCart = new MtUniCreditCartContext(array(), 0.0);
$emptyResolution = $cartResolver->resolve($shop, $emptyCart);
mtuc8_assert(
    $emptyResolution->standardOffer === null && $emptyResolution->promoOffer === null,
    'cart intersection empty cart ineligible'
);
$eligibleCart = new MtUniCreditCartContext(
    array(mtuc3_cart_line(42, array(7), 500.0)),
    500.0
);
$eligibleResolution = $cartResolver->resolve($shop, $eligibleCart);
$cartPresented = $presenter->presentCart($shop, $eligibleCart, $eligibleResolution, 'BGN');
mtuc8_assert(is_array($cartPresented) && !empty($cartPresented['hide_secondary']), 'cart presenter eligible + hide_secondary');
mtuc8_assert(isset($cartPresented['cart_fingerprint']) && $cartPresented['cart_fingerprint'] !== '', 'cart fingerprint present');

