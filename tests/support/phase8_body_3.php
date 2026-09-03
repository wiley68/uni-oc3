<?php

/**
 * Included from mtuc8_run() — inherits that function scope (continues body_2).
 *
 * @var string $root
 * @var string $lib
 * @var string $catalog
 * @var MtUniCreditStorefrontCalculatorPresenter $presenter
 * @var MtUniCreditProductContext $product
 * @var string $css
 * @var string $productSrc
 * @var string $cartSrc
 */

// Button visual parity — CP mapping via presenter + shared Product/Cart CSS contract
$buttonShopLight = mtuc3_golden_shop(array(
    'uni_eur' => 0,
    'uni_vnoska' => 1,
    'uni_type_button' => 0,
    'uni_button_row' => 1,
    'uni_button_width' => 315,
    'uni_button_height' => 62,
));
$buttonVmLight = $presenter->presentProduct($buttonShopLight, $product, 'BGN');
mtuc8_assert(is_array($buttonVmLight), 'button VM light present');
mtuc8_assert(
    isset($buttonVmLight['dark_button'], $buttonVmLight['buttons_in_row'], $buttonVmLight['button_width'], $buttonVmLight['button_height']),
    'button VM exposes uni_type_button/row/width/height mapping'
);
mtuc8_assert($buttonVmLight['dark_button'] === false, 'uni_type_button=0 => light (dark_button false)');
mtuc8_assert($buttonVmLight['buttons_in_row'] === true, 'uni_button_row=1 => buttons_in_row true');
mtuc8_assert((int) $buttonVmLight['button_width'] === 315, 'non-default uni_button_width reaches VM');
mtuc8_assert((int) $buttonVmLight['button_height'] === 62, 'non-default uni_button_height reaches VM');
mtuc8_assert(
    isset($buttonVmLight['offers']['standard'], $buttonVmLight['offers']['promo']),
    'standard + promo offers both present for visual parity'
);

$buttonShopDark = mtuc3_golden_shop(array(
    'uni_eur' => 0,
    'uni_vnoska' => 1,
    'uni_type_button' => 1,
    'uni_button_row' => 0,
    'uni_button_width' => 315,
    'uni_button_height' => 62,
));
$buttonVmDark = $presenter->presentProduct($buttonShopDark, $product, 'BGN');
mtuc8_assert(is_array($buttonVmDark) && $buttonVmDark['dark_button'] === true, 'uni_type_button=1 => dark');
mtuc8_assert($buttonVmDark['buttons_in_row'] === false, 'uni_button_row!=1 => stacked');

$buttonVmCart = $presenter->presentCart(
    $buttonShopLight,
    new MtUniCreditCartContext(array(mtuc3_cart_line(42, array(7), 500.0)), 500.0),
    null,
    'BGN'
);
mtuc8_assert(is_array($buttonVmCart), 'cart button VM present');
mtuc8_assert(
    (int) $buttonVmCart['button_width'] === 315
        && (int) $buttonVmCart['button_height'] === 62
        && $buttonVmCart['dark_button'] === false
        && $buttonVmCart['buttons_in_row'] === true,
    'Product/Cart share same CP button mapping fields'
);

$productTwig = (string) file_get_contents(
    $catalog . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'theme' . DIRECTORY_SEPARATOR
        . 'default' . DIRECTORY_SEPARATOR . 'template' . DIRECTORY_SEPARATOR . 'extension'
        . DIRECTORY_SEPARATOR . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'product_widget.twig'
);
$cartTwig = (string) file_get_contents(
    $catalog . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'theme' . DIRECTORY_SEPARATOR
        . 'default' . DIRECTORY_SEPARATOR . 'template' . DIRECTORY_SEPARATOR . 'extension'
        . DIRECTORY_SEPARATOR . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'cart_widget.twig'
);
foreach (array('product' => $productTwig, 'cart' => $cartTwig) as $surface => $twig) {
    mtuc8_assert(
        strpos($twig, 'mt-uni-credit-storefront--dark') !== false,
        $surface . ' twig dark class from dark_button'
    );
    mtuc8_assert(
        strpos($twig, 'mt-uni-credit-storefront--stacked') !== false,
        $surface . ' twig stacked class from buttons_in_row'
    );
    mtuc8_assert(
        strpos($twig, '--mtuc-button-width:') !== false && strpos($twig, '--mtuc-button-height:') !== false,
        $surface . ' twig inline CSS vars from button_width/height'
    );
    mtuc8_assert(
        strpos($twig, 'mt-uni-credit-storefront__button mt-uni-credit-storefront__button--') !== false,
        $surface . ' twig shared base button class + offer modifier'
    );
    mtuc8_assert(strpos($twig, 'mt-uni-credit-storefront__logo') !== false, $surface . ' twig standard logo slot');
    mtuc8_assert(strpos($twig, 'mt-uni-credit-storefront__badge') !== false, $surface . ' twig promo 0% badge');
    mtuc8_assert(strpos($twig, 'asset_fonts') !== false, $surface . ' twig loads local fonts CSS');
}

mtuc8_assert(
    strpos($css, '#mt-uni-credit-product-root') !== false
        && strpos($css, '#mt-uni-credit-cart-root') !== false,
    'CSS scopes Product and Cart roots together'
);
mtuc8_assert(strpos($css, 'border: 2px solid var(--mtuc-red)') !== false, 'CSS 2px UniCredit red border');
mtuc8_assert(
    preg_match(
        '/button\.mt-uni-credit-storefront__button:focus\s*,\s*\n#mt-uni-credit-cart-root button\.mt-uni-credit-storefront__button:focus\s*\{\s*outline:\s*none\s*;/m',
        $css
    ) === 1,
    'CSS :focus clears outline (no mouse double border)'
);
mtuc8_assert(
    preg_match(
        '/button\.mt-uni-credit-storefront__button:focus-visible[\s\S]*?outline:\s*2px solid var\(--mtuc-red-text\)/',
        $css
    ) === 1,
    'CSS :focus-visible keeps keyboard focus indicator'
);
mtuc8_assert(
    !preg_match(
        '/button\.mt-uni-credit-storefront__button:focus\s*,\s*\n#mt-uni-credit-cart-root button\.mt-uni-credit-storefront__button:focus\s*,\s*\n#mt-uni-credit-product-root button\.mt-uni-credit-storefront__button:focus-visible/',
        $css
    ),
    'CSS :focus no longer shares outline rule with :focus-visible'
);
mtuc8_assert(strpos($css, 'border-radius: 9999px') !== false, 'CSS pill radius 9999px');
mtuc8_assert(strpos($css, '--mtuc-button-width:') !== false, 'CSS configured width variable');
mtuc8_assert(strpos($css, '--mtuc-button-height:') !== false, 'CSS configured height variable');
mtuc8_assert(strpos($css, 'background: #fff') !== false, 'CSS white standard background');
mtuc8_assert(strpos($css, 'background: var(--mtuc-red)') !== false, 'CSS red dark background');
mtuc8_assert(strpos($css, 'border-color: #b82119') !== false, 'CSS dark red border');
mtuc8_assert(strpos($css, 'color: var(--mtuc-red-text)') !== false, 'CSS red standard title');
mtuc8_assert(
    preg_match(
        '/mt-uni-credit-storefront--dark[\s\S]*?\.mt-uni-credit-storefront__button-title[\s\S]*?color:\s*#fff/',
        $css
    ) === 1,
    'CSS white dark title'
);
mtuc8_assert(strpos($css, '--mtuc-red: #ee2e24') !== false, 'CSS OC4 --mtuc-red');
mtuc8_assert(strpos($css, 'mt-uni-credit-storefront--stacked') !== false, 'CSS stacked layout');
mtuc8_assert(strpos($css, '@container mtuc-product-buttons') !== false, 'CSS responsive container query');
mtuc8_assert(
    strpos($css, 'max-width: 900px') !== false
        && strpos($css, '14.5px 14.5px 80px 14.5px') !== false
        && strpos($css, 'processing[hidden]') !== false,
    'CSS popup Step 1 shell/calc/processing[hidden] contract'
);
mtuc8_assert(
    strpos($css, 'max-width: 560px') === false,
    'CSS popup no longer uses narrow 560px modal'
);

$fontsCss = $catalog . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'theme' . DIRECTORY_SEPARATOR
    . 'default' . DIRECTORY_SEPARATOR . 'template' . DIRECTORY_SEPARATOR . 'extension'
    . DIRECTORY_SEPARATOR . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'storefront_fonts.css';
$fontFile = $catalog . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'theme' . DIRECTORY_SEPARATOR
    . 'default' . DIRECTORY_SEPARATOR . 'template' . DIRECTORY_SEPARATOR . 'extension'
    . DIRECTORY_SEPARATOR . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'fonts' . DIRECTORY_SEPARATOR
    . 'roboto-condensed' . DIRECTORY_SEPARATOR . 'roboto-condensed-latin-700.woff2';
mtuc8_assert(is_file($fontsCss), 'local storefront_fonts.css present');
mtuc8_assert(is_file($fontFile), 'local Roboto Condensed woff2 present');

$logoStandardRel = 'view/theme/default/template/extension/mt_uni_credit/image/uni_logo.svg';
$logoAltRel = 'view/theme/default/template/extension/mt_uni_credit/image/uni_logo_red.svg';
$logoStandardFs = $catalog . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $logoStandardRel);
$logoAltFs = $catalog . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $logoAltRel);
$forbiddenLogoStandardFs = $catalog . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'image'
    . DIRECTORY_SEPARATOR . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'uni_logo.svg';
$forbiddenLogoAltFs = $catalog . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'image'
    . DIRECTORY_SEPARATOR . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'uni_logo_red.svg';
mtuc8_assert(is_file($logoStandardFs), 'module-local uni_logo.svg present');
mtuc8_assert(is_file($logoAltFs), 'module-local uni_logo_red.svg present');
mtuc8_assert(!is_file($forbiddenLogoStandardFs), 'forbidden catalog/view/image uni_logo.svg absent');
mtuc8_assert(!is_file($forbiddenLogoAltFs), 'forbidden catalog/view/image uni_logo_red.svg absent');
mtuc8_assert(
    MtUniCreditConstants::STOREFRONT_LOGO_STANDARD_RELATIVE
        === 'catalog/view/theme/default/template/extension/mt_uni_credit/image/uni_logo.svg',
    'STOREFRONT_LOGO_STANDARD_RELATIVE uses module-local path'
);
mtuc8_assert(
    MtUniCreditConstants::STOREFRONT_LOGO_ALTERNATIVE_RELATIVE
        === 'catalog/view/theme/default/template/extension/mt_uni_credit/image/uni_logo_red.svg',
    'STOREFRONT_LOGO_ALTERNATIVE_RELATIVE uses module-local path'
);
mtuc8_assert(
    strpos(MtUniCreditConstants::STOREFRONT_LOGO_STANDARD_RELATIVE, 'catalog/view/image/') === false
        && strpos(MtUniCreditConstants::STOREFRONT_LOGO_ALTERNATIVE_RELATIVE, 'catalog/view/image/') === false,
    'logo constants avoid catalog/view/image'
);
$runtimeSrc = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'storefront_runtime.php');
mtuc8_assert(
    strpos($runtimeSrc, "view/image") === false
        && strpos($runtimeSrc, "'image' . DIRECTORY_SEPARATOR . 'uni_logo.svg'") !== false
        && strpos($runtimeSrc, 'STOREFRONT_LOGO_STANDARD_RELATIVE') !== false,
    'storefront_runtime maps logos under module image/'
);

mtuc8_assert(
    MtUniCreditConstants::STOREFRONT_ASSET_FONTS_CSS_RELATIVE !== ''
        && MtUniCreditConstants::STOREFRONT_LOGO_STANDARD_RELATIVE !== '',
    'font/logo asset constants defined'
);
mtuc8_assert(
    strpos($productSrc, 'asset_fonts') !== false && strpos($cartSrc, 'asset_fonts') !== false,
    'Product/Cart controllers pass asset_fonts'
);
mtuc8_assert(
    strpos($productSrc, 'logo_standard_url') !== false && strpos($cartSrc, 'logo_alternative_url') !== false,
    'Product/Cart controllers pass logo URLs'
);

$packageScript = (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'package.ps1');
mtuc8_assert(
    strpos($packageScript, 'upload/catalog/view/theme/default/template/extension/mt_uni_credit/image/uni_logo.svg') !== false,
    'package expects module-local uni_logo.svg'
);
mtuc8_assert(
    strpos($packageScript, 'upload/catalog/view/image/mt_uni_credit/uni_logo.svg') !== false
        && strpos($packageScript, 'OC3 package must not write to catalog/view/image') !== false,
    'package forbids catalog/view/image storefront logos'
);
$distZip = $root . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'CC_OpenCartv.3.x_UNI_v.2.0.2.ocmod.zip';
if (is_file($distZip) && class_exists('ZipArchive')) {
    $zip = new ZipArchive();
    mtuc8_assert($zip->open($distZip) === true, 'dist package ZIP opens');
    $hasModuleLogo = false;
    $hasModuleLogoRed = false;
    $hasCatalogViewImage = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
        if ($name === 'upload/catalog/view/theme/default/template/extension/mt_uni_credit/image/uni_logo.svg') {
            $hasModuleLogo = true;
        }
        if ($name === 'upload/catalog/view/theme/default/template/extension/mt_uni_credit/image/uni_logo_red.svg') {
            $hasModuleLogoRed = true;
        }
        if (strpos($name, 'upload/catalog/view/image/') === 0) {
            $hasCatalogViewImage = true;
        }
    }
    $zip->close();
    mtuc8_assert($hasModuleLogo, 'ZIP contains module-local uni_logo.svg');
    mtuc8_assert($hasModuleLogoRed, 'ZIP contains module-local uni_logo_red.svg');
    mtuc8_assert(!$hasCatalogViewImage, 'OC3 package must not write to catalog/view/image');
} else {
    mtuc8_assert(true, 'dist package ZIP check skipped until rebuild');
}
