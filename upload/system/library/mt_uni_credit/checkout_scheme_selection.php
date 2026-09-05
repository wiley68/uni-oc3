<?php

/**
 * Checkout scheme dropdown defaults (OC4 ProductBuyCheckoutPreference parity).
 */
final class MtUniCreditCheckoutSchemeSelection
{
    /**
     * Precedence: Product Buy preference → Checkout PreferredOffer default.
     *
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
     * @param array<string, mixed> $presenter
     * @param list<array<string, mixed>> $schemes
     * @return string|null
     */
    private static function presenterDefaultSchemeKey(array $presenter, array $schemes)
    {
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
