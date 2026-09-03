<?php

/**
 * Popup/modal view fields from fresh shop cache (CP CDN banner URLs).
 */
final class MtUniCreditStorefrontModalPresenter
{
    /**
     * @param array<string, mixed> $shop
     * @param string $currencyIso
     * @return array<string, mixed>
     */
    public static function present(array $shop, $currencyIso)
    {
        $bannerLink = self::httpUrl(isset($shop['reklama_url']) ? $shop['reklama_url'] : '');
        if ($bannerLink === '') {
            $bannerLink = self::httpUrl(isset($shop['uni_backurl']) ? $shop['uni_backurl'] : '');
        }

        $iso = strtoupper(trim((string) $currencyIso));
        $currencyWord = $iso === 'EUR' ? 'евро' : 'лв.';

        return array(
            'banner_url' => self::httpUrl(isset($shop['uni_picture']) ? $shop['uni_picture'] : ''),
            'banner_url_mobile' => self::httpUrl(isset($shop['uni_picturem']) ? $shop['uni_picturem'] : ''),
            'banner_link' => $bannerLink,
            'currency_word' => $currencyWord,
            'text_first_installment' => 'Първоначална вноска /' . $currencyWord . '/',
        );
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function httpUrl($value)
    {
        $url = trim((string) $value);
        if ($url === '') {
            return '';
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return ($scheme === 'http' || $scheme === 'https') ? $url : '';
    }
}
