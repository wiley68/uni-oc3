<?php

/**
 * Build calculator/cache-ready shop snapshots for Phase 3 offline tests.
 */

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function mtuc3_golden_shop(array $overrides = array())
{
    $fixture = mtuc_phase0_load_fixture('calculator_golden.json');
    $shop = $fixture['shop'];

    for ($month = 3; $month <= 36; $month++) {
        $key = 'uni_meseci_' . $month;
        if (!array_key_exists($key, $shop)) {
            $shop[$key] = 0;
        }
    }

    $shop['uni_proces'] = 1;
    $shop['uni_env'] = 0;
    $shop['uni_first_vnoska'] = isset($shop['uni_first_vnoska']) ? $shop['uni_first_vnoska'] : 1;
    $shop['unicid'] = 'TEST-UNICID';

    return array_replace_recursive($shop, $overrides);
}

/**
 * @return array<string, mixed>
 */
function mtuc3_schema_filter_shop()
{
    $fixture = mtuc_phase0_load_fixture('calculator_golden.json');
    $shop = mtuc3_golden_shop(array(
        'uni_typekop' => 1,
        'kop' => array(
            'by_default' => array(
                'uni_kop_default' => 'STD',
                'uni_kop_default_desc' => '',
                'uni_kop_promo' => 'PROMO',
                'uni_kop_promo_desc' => '0% лихва за компютри',
                'uni_promo_price' => 500,
                'uni_promo_meseci_znak' => 'eq',
                'uni_promo_meseci' => '12_24',
            ),
            'by_schema' => array(
                'filters' => $fixture['schema_filters'],
            ),
        ),
    ));

    return $shop;
}

/**
 * @param array<string, mixed>|null $product
 * @return MtUniCreditProductContext
 */
function mtuc3_golden_product($product = null)
{
    $fixture = mtuc_phase0_load_fixture('calculator_golden.json');
    $product = $product !== null ? $product : $fixture['product'];

    return new MtUniCreditProductContext(
        (int) $product['productId'],
        $product['categoryIds'],
        (float) $product['price']
    );
}
