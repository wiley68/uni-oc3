<?php

/**
 * Deterministic canonical shop URL for CP login `name` field.
 */
final class MtUniCreditCanonicalShopUrlProvider
{
    /**
     * @param string $url
     * @return string
     */
    public static function normalize($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        if (stripos($url, 'http://') === 0) {
            $url = 'https://' . substr($url, 7);
        }

        return rtrim($url, '/');
    }

    /**
     * Prefer SSL catalog URL when available (Cloudflare / proxy aware via OpenCart config).
     *
     * @param string|null $sslUrl
     * @param string|null $plainUrl
     * @return string
     */
    public function resolve($sslUrl, $plainUrl)
    {
        $candidate = trim((string) ($sslUrl !== null ? $sslUrl : ''));
        if ($candidate === '') {
            $candidate = trim((string) ($plainUrl !== null ? $plainUrl : ''));
        }

        return self::normalize($candidate);
    }
}
