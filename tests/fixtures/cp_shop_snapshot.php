<?php

/**
 * Valid CP shop snapshot fixture for Phase 4 offline tests.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function mtuc4_valid_shop_snapshot(array $overrides = array())
{
    $months = array();
    for ($m = 3; $m <= 36; ++$m) {
        $months['uni_meseci_' . $m] = $m === 12 ? 1 : 0;
    }

    $base = array_merge(array(
        'unicid' => '123e4567-e89b-12d3-a456-426614174000',
        'uni_status' => 1,
        'uni_typekop' => 0,
        'uni_minstojnost' => 100,
        'uni_maxstojnost' => 10000,
        'uni_first_vnoska' => 0,
        'uni_shema_current' => 12,
        'uni_eur' => 0,
        'uni_proces' => 0,
        'uni_env' => 0,
        'uni_sertificat' => 0,
        'uni_test_service' => 'https://onlinetest.ucfin.bg/suos/api/otp/',
        'uni_test_application' => 'https://onlinetest.ucfin.bg/sucf-online/Request/Start',
        'uni_production_service' => 'https://online.ucfin.bg/suos/api/otp/',
        'uni_production_application' => 'https://online.ucfin.bg/sucf-online/Request/Start',
        'uni_user' => 'demo-user',
        'uni_password' => 'demo-secret-password',
        'uni_email' => 'bank-admin@example.test',
        'kop' => array(
            'by_default' => array(
                'uni_kop_default' => 'KOPSTD',
                'uni_kop_default_desc' => 'Standard',
                'uni_kop_promo' => 'KOPPROMO',
                'uni_kop_promo_desc' => 'Promo',
                'uni_promo_price' => 0,
                'uni_promo_meseci_znak' => 'eq',
                'uni_promo_meseci' => '12',
            ),
            'by_schema' => array(
                'filters' => array(),
            ),
        ),
        'coeff_list' => array(
            array(
                'onlineProductCode' => 'KOPSTD',
                'installmentCount' => 12,
                'coeff' => 1.05,
                'interestPercent' => 5.5,
            ),
        ),
        'consents' => array(
            array(
                'id' => 1,
                'name' => 'Accept terms',
                'url' => 'https://example.com/terms',
                'mandatory' => 1,
            ),
        ),
    ), $months);

    foreach ($overrides as $key => $value) {
        if ($key === 'kop' && is_array($value) && isset($base['kop']) && is_array($base['kop'])) {
            $base['kop'] = array_replace_recursive($base['kop'], $value);
            continue;
        }
        $base[$key] = $value;
    }

    return $base;
}
