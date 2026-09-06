<?php

/**
 * Deterministic canonical shop URL for CP login `name` field (AUD-008-F04).
 *
 * CP authenticates shops by exact `name` string match and later uses that value
 * as the storefront base URL for module callbacks (ShopModuleEndpointUrl).
 * Therefore identity is a parsed origin, optionally with a subdirectory path.
 */
final class MtUniCreditCanonicalShopUrlProvider
{
    /**
     * Canonicalize a shop identity URL or fail closed.
     *
     * Empty input returns empty string (caller treats as "not configured").
     * Non-empty invalid input throws InvalidArgumentException.
     *
     * Outer whitespace is trimmed (same policy as CP destination validation).
     * Interior whitespace is rejected.
     *
     * @param string $url
     * @return string
     */
    public static function normalize($url)
    {
        if (!is_string($url)) {
            throw new InvalidArgumentException('The shop URL is malformed.');
        }

        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (preg_match('/\s/', $url)) {
            throw new InvalidArgumentException('The shop URL is malformed.');
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('The shop URL is malformed.');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('The shop URL is malformed.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'https' && $scheme !== 'http') {
            throw new InvalidArgumentException('The shop URL must use HTTP or HTTPS.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('The shop URL must not contain userinfo.');
        }
        if (array_key_exists('query', $parts)) {
            throw new InvalidArgumentException('The shop URL must not contain a query string.');
        }
        if (array_key_exists('fragment', $parts)) {
            throw new InvalidArgumentException('The shop URL must not contain a fragment.');
        }

        $host = strtolower((string) $parts['host']);
        if ($host === '') {
            throw new InvalidArgumentException('The shop URL hostname is invalid.');
        }

        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if ($host !== '' && $host[0] === '[') {
            $isIp = true;
        }
        if (!$isIp && !preg_match('/^[a-z0-9.-]+$/', $host)) {
            // Reject IDN/unicode hosts — no punycode conversion in this module.
            throw new InvalidArgumentException('The shop URL hostname is invalid.');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port !== null) {
            if ($port < 1 || $port > 65535) {
                throw new InvalidArgumentException('The shop URL port is invalid.');
            }
            if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
                $port = null;
            }
        }

        $path = self::canonicalPath(isset($parts['path']) ? (string) $parts['path'] : '');

        $canonical = $scheme . '://' . $host;
        if ($port !== null) {
            $canonical .= ':' . $port;
        }
        if ($path !== '') {
            $canonical .= $path;
        }

        return $canonical;
    }

    /**
     * Prefer SSL catalog URL when available (Cloudflare / proxy aware via OpenCart config).
     *
     * Does not invent a scheme: http candidates stay http; https stay https.
     * Preferring config_ssl over config_url selects the configured identity source.
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

    /**
     * Path policy: root `/` or empty → omitted.
     * Non-root paths allowed for subdirectory OpenCart installs (CP callback base).
     * Rejects dot-segments, empty segments, and trailing slash.
     *
     * @param string $path
     * @return string
     */
    private static function canonicalPath($path)
    {
        if ($path === '' || $path === '/') {
            return '';
        }

        if ($path[0] !== '/') {
            throw new InvalidArgumentException('The shop URL path is invalid.');
        }

        $trimmed = $path;
        if (substr($trimmed, -1) === '/') {
            $trimmed = substr($trimmed, 0, -1);
        }
        if ($trimmed === '' || $trimmed === '/') {
            return '';
        }

        $segments = explode('/', $trimmed);
        // First element is empty because path starts with '/'.
        if ($segments === array() || $segments[0] !== '') {
            throw new InvalidArgumentException('The shop URL path is invalid.');
        }
        array_shift($segments);
        if ($segments === array()) {
            return '';
        }

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('The shop URL path is invalid.');
            }
            if (!preg_match('/^[A-Za-z0-9._~-]+$/', $segment)) {
                throw new InvalidArgumentException('The shop URL path is invalid.');
            }
        }

        return '/' . implode('/', $segments);
    }
}
