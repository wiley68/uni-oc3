<?php

/**
 * Homepage advertising payload. Graphic URLs come from CP cache, never invented locally.
 *
 * AUD-029-F01: malformed/partial cached advertising fields fail closed (null) —
 * no Array-to-string warnings, no incomplete blocks.
 * AUD-029-F03: raw attribute-breaking characters in HTTP(S) URLs fail closed.
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
     * Required advertising fields (meaningful block):
     * - uni_backurl: validated absolute http/https CTA
     * - uni_container_txt1: non-empty primary text after strip_tags/trim
     *
     * Optional:
     * - uni_container_txt2: supporting copy (may be empty)
     * - uni_picturem: panel/mobile graphic (may be empty; desktop float uses default logo)
     *
     * Wrong-type values on any of the above keys fail the entire present() closed.
     * Extra unknown shop keys are ignored.
     *
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

        $backRaw = $this->readStringField($shop, 'uni_backurl');
        if ($backRaw === false) {
            return null;
        }
        $txt1Raw = $this->readStringField($shop, 'uni_container_txt1');
        if ($txt1Raw === false) {
            return null;
        }
        $txt2Raw = $this->readStringField($shop, 'uni_container_txt2');
        if ($txt2Raw === false) {
            return null;
        }
        $pictureRaw = $this->readStringField($shop, 'uni_picturem');
        if ($pictureRaw === false) {
            return null;
        }

        $backurl = $this->httpUrl($backRaw);
        if ($backurl === '') {
            return null;
        }

        $txt1 = $this->text($txt1Raw);
        if ($txt1 === '') {
            return null;
        }

        $txt2 = $this->text($txt2Raw);
        $pictureUrl = $this->httpUrl($pictureRaw);

        $floatImageUrl = $isMobile ? $pictureUrl : $defaultLogoUrl;
        if ($floatImageUrl === '') {
            $floatImageUrl = $defaultLogoUrl;
        }

        return array(
            'is_mobile' => (bool) $isMobile,
            'backurl' => $backurl,
            'txt1' => $txt1,
            'txt2' => $txt2,
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
        if (!$this->isAllowedString($value)) {
            return '';
        }
        $url = trim($value);
        if ($url === '') {
            return '';
        }
        // AUD-029-F03: FILTER_VALIDATE_URL alone is not HTML-attribute-safe.
        // CTA sinks use double-quoted attributes with Twig autoescape=false.
        if ($this->containsUnsafeRawAttributeChars($url)) {
            return '';
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return ($scheme === 'http' || $scheme === 'https') ? $url : '';
    }

    /**
     * Reject raw characters that can break out of (or corrupt) double-quoted
     * HTML attribute sinks used for CTA / image URLs.
     *
     * Rejected set (justified by homepage_advertising.twig + JS open path):
     * - " (U+0022): breaks href="…" and data-mt-uni-credit-advertising-open="…"
     * - ASCII C0 controls (0x00–0x1F) and DEL (0x7F): malformed attribute values
     *
     * Not rejected here:
     * - ' (no single-quoted backurl sink in current templates)
     * - percent-encoded sequences such as %22 (remain encoded; not decoded)
     *
     * @param string $url
     * @return bool
     */
    private function containsUnsafeRawAttributeChars($url)
    {
        if (strpos($url, '"') !== false) {
            return true;
        }
        // ASCII controls / DEL — keep PHP 7.3-safe (no \p{C} / unicode props required).
        return (bool) preg_match('/[\x00-\x1F\x7F]/', $url);
    }

    /**
     * @param mixed $value
     * @return string
     */
    public function text($value)
    {
        if (!$this->isAllowedString($value)) {
            return '';
        }

        return trim(strip_tags($value));
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private function isAllowedString($value)
    {
        return is_string($value);
    }

    /**
     * Missing/null → empty string; wrong type → boolean false (malformed).
     *
     * @param array<string, mixed> $shop
     * @param string $key
     * @return mixed string when valid/missing-as-empty; false when malformed type
     */
    private function readStringField(array $shop, $key)
    {
        if (!array_key_exists($key, $shop) || $shop[$key] === null) {
            return '';
        }
        if (!$this->isAllowedString($shop[$key])) {
            return false;
        }

        return $shop[$key];
    }
}
