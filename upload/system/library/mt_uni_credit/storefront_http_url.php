<?php

/**
 * Narrow storefront HTTP(S) URL gate for HTML-attribute sinks (Twig autoescape=false).
 *
 * Shared by homepage advertising CTA and storefront modal banner_link / banner images.
 * AUD-029-F03: FILTER_VALIDATE_URL alone is not attribute-safe.
 */
final class MtUniCreditStorefrontHttpUrl
{
    /**
     * @param mixed $value
     * @return string Accepted absolute http/https URL, or '' when unsafe/invalid
     */
    public static function sanitize($value)
    {
        if (!is_string($value)) {
            return '';
        }
        $url = trim($value);
        if ($url === '') {
            return '';
        }
        if (self::containsUnsafeRawAttributeChars($url)) {
            return '';
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return ($scheme === 'http' || $scheme === 'https') ? $url : '';
    }

    /**
     * Reject raw characters that break or corrupt double-quoted HTML attributes.
     *
     * Rejected:
     * - " (U+0022)
     * - ASCII C0 controls 0x00–0x1F and DEL 0x7F
     *
     * Not rejected:
     * - ' (no single-quoted CTA sink in current templates)
     * - percent-encoded %22 (not decoded)
     *
     * @param string $url
     * @return bool
     */
    private static function containsUnsafeRawAttributeChars($url)
    {
        if (strpos($url, '"') !== false) {
            return true;
        }

        return (bool) preg_match('/[\x00-\x1F\x7F]/', $url);
    }
}
