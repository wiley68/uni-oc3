<?php

/**
 * Checkout scheme dropdown defaults.
 *
 * Precedence:
 * 1. Valid Product Buy preference (exact scheme identity)
 * 2. Normal Checkout default:
 *    Priority 1 — eligible 0% (zero_promo) with highest months
 *    Priority 2 — eligible nonzero_promo with highest months
 *    Priority 3 — CP/preferred default metadata (preferred_scheme_key)
 *
 * Tie-break within a priority bucket: months DESC, then filter_id ASC,
 * kop_code ASC, scheme_type ASC, key ASC (deterministic, not array order).
 */
final class MtUniCreditCheckoutSchemeSelection
{
    /**
     * @param array<string, mixed> $presenter
     * @param array<string, mixed> $sessionData
     * @param int $storeId
     * @return array{key:?string, source:string, buy_matched:bool}
     */
    public static function resolveInitialSchemeSelection(array $presenter, array &$sessionData, $storeId)
    {
        $schemes = self::collectPresenterSchemes($presenter);
        $defaultKey = self::presenterDefaultSchemeKey($presenter, $schemes);
        $preference = MtUniCreditProductBuyPreference::load($sessionData, (int) $storeId);

        if (is_array($preference)) {
            $buyKey = trim((string) (isset($preference['scheme_key']) ? $preference['scheme_key'] : ''));
            if ($buyKey !== '' && self::schemeKeyValid($schemes, $buyKey)) {
                return array(
                    'key' => $buyKey,
                    'source' => 'product_buy',
                    'buy_matched' => true,
                );
            }
        }

        return array(
            'key' => $defaultKey,
            'source' => 'checkout_default',
            'buy_matched' => false,
        );
    }

    /**
     * @param array<string, mixed> $presenter
     * @param string|null $selectedKey
     * @return list<array{key:string,label:string,selected:bool}>
     */
    public static function buildCheckoutSchemeOptions(array $presenter, $selectedKey)
    {
        $list = array();
        if (isset($presenter['offers']['standard']['schemes']) && is_array($presenter['offers']['standard']['schemes'])) {
            $list = $presenter['offers']['standard']['schemes'];
        }
        if ($list === array()) {
            $list = self::collectPresenterSchemes($presenter);
        }

        $options = array();
        foreach ($list as $scheme) {
            if (!is_array($scheme)) {
                continue;
            }
            $key = trim((string) (isset($scheme['key']) ? $scheme['key'] : ''));
            if ($key === '') {
                continue;
            }
            $label = trim((string) (isset($scheme['label']) ? $scheme['label'] : ''));
            if ($label === '') {
                $months = (int) (isset($scheme['months']) ? $scheme['months'] : 0);
                $description = trim((string) (isset($scheme['description']) ? $scheme['description'] : ''));
                $label = MtUniCreditStorefrontCalculatorPresenter::formatSchemeOptionLabel($months, $description);
            }
            $options[] = array(
                'key' => $key,
                'label' => $label,
                'selected' => $selectedKey !== null && $selectedKey !== '' && $key === $selectedKey,
            );
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $presenter
     * @return list<array<string, mixed>>
     */
    private static function collectPresenterSchemes(array $presenter)
    {
        $merged = array();
        $seen = array();
        foreach (array('standard', 'promo') as $type) {
            if (!isset($presenter['offers'][$type]['schemes']) || !is_array($presenter['offers'][$type]['schemes'])) {
                continue;
            }
            foreach ($presenter['offers'][$type]['schemes'] as $scheme) {
                if (!is_array($scheme)) {
                    continue;
                }
                $key = trim((string) (isset($scheme['key']) ? $scheme['key'] : ''));
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $merged[] = $scheme;
            }
        }

        return $merged;
    }

    /**
     * Normal Checkout initial offer (not used when Product Buy preference matches).
     *
     * @param array<string, mixed> $presenter
     * @param list<array<string, mixed>> $schemes
     * @return string|null
     */
    private static function presenterDefaultSchemeKey(array $presenter, array $schemes)
    {
        $zeroPromo = array();
        $nonzeroPromo = array();
        foreach ($schemes as $scheme) {
            $category = (string) (isset($scheme['presentation_category']) ? $scheme['presentation_category'] : '');
            if ($category === MtUniCreditSchemePresentationCategory::ZERO_PROMO) {
                $zeroPromo[] = $scheme;
            } elseif ($category === MtUniCreditSchemePresentationCategory::NONZERO_PROMO) {
                $nonzeroPromo[] = $scheme;
            }
        }

        if ($zeroPromo !== array()) {
            return self::highestMonthsSchemeKey($zeroPromo);
        }
        if ($nonzeroPromo !== array()) {
            return self::highestMonthsSchemeKey($nonzeroPromo);
        }

        foreach (array('standard', 'promo') as $type) {
            $key = trim((string) (isset($presenter['offers'][$type]['preferred_scheme_key'])
                ? $presenter['offers'][$type]['preferred_scheme_key']
                : ''));
            if ($key !== '') {
                return $key;
            }
        }
        if ($schemes === array()) {
            return null;
        }
        $key = trim((string) (isset($schemes[0]['key']) ? $schemes[0]['key'] : ''));

        return $key !== '' ? $key : null;
    }

    /**
     * Highest months wins; ties broken by filter_id, kop_code, scheme_type, key (ASC).
     *
     * @param list<array<string, mixed>> $schemes
     * @return string|null
     */
    private static function highestMonthsSchemeKey(array $schemes)
    {
        $best = null;
        foreach ($schemes as $scheme) {
            if (!is_array($scheme)) {
                continue;
            }
            $key = trim((string) (isset($scheme['key']) ? $scheme['key'] : ''));
            if ($key === '') {
                continue;
            }
            if ($best === null || self::compareHighestMonths($scheme, $best) < 0) {
                $best = $scheme;
            }
        }

        if ($best === null) {
            return null;
        }

        return trim((string) $best['key']);
    }

    /**
     * Negative when $left is preferred over $right for "highest months" selection.
     *
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @return int
     */
    private static function compareHighestMonths(array $left, array $right)
    {
        $leftMonths = (int) (isset($left['months']) ? $left['months'] : 0);
        $rightMonths = (int) (isset($right['months']) ? $right['months'] : 0);
        if ($leftMonths !== $rightMonths) {
            return $rightMonths <=> $leftMonths;
        }

        $leftFilter = (int) (isset($left['filter_id']) ? $left['filter_id'] : 0);
        $rightFilter = (int) (isset($right['filter_id']) ? $right['filter_id'] : 0);
        if ($leftFilter !== $rightFilter) {
            return $leftFilter <=> $rightFilter;
        }

        $kop = strcmp(
            (string) (isset($left['kop_code']) ? $left['kop_code'] : ''),
            (string) (isset($right['kop_code']) ? $right['kop_code'] : '')
        );
        if ($kop !== 0) {
            return $kop;
        }

        $type = strcmp(
            (string) (isset($left['scheme_type']) ? $left['scheme_type'] : ''),
            (string) (isset($right['scheme_type']) ? $right['scheme_type'] : '')
        );
        if ($type !== 0) {
            return $type;
        }

        return strcmp(
            (string) (isset($left['key']) ? $left['key'] : ''),
            (string) (isset($right['key']) ? $right['key'] : '')
        );
    }

    /**
     * @param list<array<string, mixed>> $schemes
     * @param string $key
     * @return bool
     */
    private static function schemeKeyValid(array $schemes, $key)
    {
        foreach ($schemes as $scheme) {
            if (isset($scheme['key']) && (string) $scheme['key'] === (string) $key) {
                return true;
            }
        }

        return false;
    }
}
