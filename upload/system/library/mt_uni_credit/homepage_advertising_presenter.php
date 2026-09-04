<?php

/**
 * Homepage advertising payload. Graphic URLs come from CP cache, never invented locally.
 */
final class MtUniCreditHomepageAdvertisingPresenter
{
    /** @var MtUniCreditHomepageAdvertisingGate */
    private $gate;

    /**
     * @param MtUniCreditHomepageAdvertisingGate|null $gate
     */
    public function __construct($gate = null)
    {
        $this->gate = $gate instanceof MtUniCreditHomepageAdvertisingGate
            ? $gate
            : new MtUniCreditHomepageAdvertisingGate();
    }

    /**
     * @param array<string, mixed> $shop
     * @param bool $isMobile
     * @param string $defaultLogoUrl
     * @return array<string, mixed>|null
     */
    public function present(array $shop, $isMobile, $defaultLogoUrl)
    {
        if (!$this->gate->allowsShop($shop)) {
            return null;
        }

        $defaultLogoUrl = trim((string) $defaultLogoUrl);
        if ($defaultLogoUrl === '') {
            return null;
        }

        $pictureUrl = $this->httpUrl(isset($shop['uni_picturem']) ? $shop['uni_picturem'] : '');
        $floatImageUrl = $isMobile ? $pictureUrl : $defaultLogoUrl;
        if ($floatImageUrl === '') {
            $floatImageUrl = $defaultLogoUrl;
        }

        return array(
            'is_mobile' => (bool) $isMobile,
            'backurl' => $this->httpUrl(isset($shop['uni_backurl']) ? $shop['uni_backurl'] : ''),
            'txt1' => $this->text(isset($shop['uni_container_txt1']) ? $shop['uni_container_txt1'] : ''),
            'txt2' => $this->text(isset($shop['uni_container_txt2']) ? $shop['uni_container_txt2'] : ''),
            'float_image_url' => $floatImageUrl,
            'picture_url' => $pictureUrl,
        );
    }

    /**
     * @param mixed $value
     * @return string
     */
    public function httpUrl($value)
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

    /**
     * @param mixed $value
     * @return string
     */
    public function text($value)
    {
        return trim(strip_tags((string) $value));
    }
}
