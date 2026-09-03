<?php

/**
 * Popup/modal view fields from fresh shop cache (CP CDN banner + Step 2 process/customer).
 */
final class MtUniCreditStorefrontModalPresenter
{
    /**
     * @param array<string, mixed> $shop
     * @param string $currencyIso
     * @param array<string, mixed> $customer Prefill from MtUniCreditStorefrontCustomerPrefill
     * @return array<string, mixed>
     */
    public static function present(array $shop, $currencyIso, array $customer = array())
    {
        $bannerLink = self::httpUrl(isset($shop['reklama_url']) ? $shop['reklama_url'] : '');
        if ($bannerLink === '') {
            $bannerLink = self::httpUrl(isset($shop['uni_backurl']) ? $shop['uni_backurl'] : '');
        }

        $iso = strtoupper(trim((string) $currencyIso));
        $currencyWord = $iso === 'EUR' ? 'евро' : 'лв.';
        $process2 = ((int) (isset($shop['uni_proces']) ? $shop['uni_proces'] : 0)) === 1;
        $consents = (new MtUniCreditStorefrontConsentResolver())->normalize($shop);

        $customerDefaults = array(
            'firstname' => '',
            'lastname' => '',
            'address' => '',
            'telephone' => '',
            'email' => '',
            'address_id' => 0,
            'is_logged' => false,
            'phone2' => '',
            'egn' => '',
        );

        return array(
            'banner_url' => self::httpUrl(isset($shop['uni_picture']) ? $shop['uni_picture'] : ''),
            'banner_url_mobile' => self::httpUrl(isset($shop['uni_picturem']) ? $shop['uni_picturem'] : ''),
            'banner_link' => $bannerLink,
            'currency_word' => $currencyWord,
            'text_first_installment' => 'Първоначална вноска /' . $currencyWord . '/',
            'process2' => $process2,
            'customer' => array_merge($customerDefaults, $customer),
            'consents' => $consents,
            'fields' => $process2
                ? array('firstname', 'lastname', 'address', 'phone', 'email', 'phone2', 'egn')
                : array('firstname', 'lastname', 'address', 'phone', 'email'),
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
