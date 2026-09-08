<?php

/**
 * Validated CP-compatible shop base identity for login `name` (AUD-008-F04 / RECOVERY-01).
 *
 * CP stores and compares Shop.name by exact string match (no CP-side URL rewrite).
 * Registration requires a literal `https://` prefix.
 *
 * Historical / reference-uni-oc4 login identity collapses a single trailing `/`
 * after validation (`rtrim`). OpenCart `config_ssl` / `HTTPS_CATALOG` almost always
 * include that slash; shops registered under the working contract do not. Sending the
 * raw trailing-slash form therefore fails `/auth/login` with 401.
 *
 * This provider therefore:
 *   - validates the configured storefront base URL against CP-compatible rules
 *   - preserves host/path/port spelling (no lowercasing, no port stripping)
 *   - applies the historical trailing-slash identity (rtrim of trailing slash chars) only
 */
final class MtUniCreditCanonicalShopUrlProvider
{
    /**
     * Validate a shop identity URL or fail closed, then apply historical trailing-slash identity.
     *
     * Empty / whitespace-only input returns empty string (not configured).
     * Non-empty invalid input throws InvalidArgumentException.
     *
     * Outer whitespace is trimmed before validation.
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

        // CP StoreShopRequest / UpdateShopRequest: starts_with:https:// (literal, case-sensitive).
        if (strpos($url, 'https://') !== 0) {
            throw new InvalidArgumentException('The shop URL must start with https://.');
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('The shop URL is malformed.');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('The shop URL is malformed.');
        }

        if (strtolower((string) $parts['scheme']) !== 'https') {
            throw new InvalidArgumentException('The shop URL must use HTTPS.');
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

        self::assertTrustedHost((string) $parts['host']);

        if (isset($parts['port'])) {
            $port = (int) $parts['port'];
            if ($port < 1 || $port > 65535) {
                throw new InvalidArgumentException('The shop URL port is invalid.');
            }
        }

        self::assertSafePath(isset($parts['path']) ? (string) $parts['path'] : '');

        // Historical CP login identity (reference-uni-oc4 / pre-AUD-008 OC3): drop trailing '/'.
        return rtrim($url, '/');
    }

    /**
     * Prefer SSL catalog URL when available (Cloudflare / proxy aware via OpenCart config).
     *
     * Non-empty config_ssl is validated as-is (no fallback to config_url on malformation).
     * Empty config_ssl may use config_url, which must still satisfy HTTPS identity rules.
     *
     * @param string|null $sslUrl
     * @param string|null $plainUrl
     * @return string
     */
    public function resolve($sslUrl, $plainUrl)
    {
        $ssl = trim((string) ($sslUrl !== null ? $sslUrl : ''));
        if ($ssl !== '') {
            return self::normalize($ssl);
        }

        $plain = trim((string) ($plainUrl !== null ? $plainUrl : ''));

        return self::normalize($plain);
    }

    /**
     * @param string $host
     * @return void
     */
    private static function assertTrustedHost($host)
    {
        if ($host === '') {
            throw new InvalidArgumentException('The shop URL hostname is invalid.');
        }

        // Bracketed or bare IPv6 — rejected (CP ShopModuleEndpointUrl hostname check fails).
        if ($host[0] === '[' || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            throw new InvalidArgumentException('The shop URL hostname is invalid.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return;
        }

        // ASCII DNS only — no IDN/punycode conversion.
        if (!preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
            throw new InvalidArgumentException('The shop URL hostname is invalid.');
        }
    }

    /**
     * Validate path without rewriting spelling.
     *
     * @param string $path
     * @return void
     */
    private static function assertSafePath($path)
    {
        if ($path === '' || $path === '/') {
            return;
        }

        if ($path[0] !== '/') {
            throw new InvalidArgumentException('The shop URL path is invalid.');
        }

        if (strpos($path, '\\') !== false || strpos($path, '%') !== false) {
            throw new InvalidArgumentException('The shop URL path is invalid.');
        }

        $segments = explode('/', $path);
        // Leading empty from initial '/'.
        if ($segments === array() || $segments[0] !== '') {
            throw new InvalidArgumentException('The shop URL path is invalid.');
        }
        array_shift($segments);

        $count = count($segments);
        foreach ($segments as $index => $segment) {
            $isTrailingEmpty = ($segment === '' && $index === ($count - 1));
            if ($isTrailingEmpty) {
                // Trailing slash is allowed on input; normalize() rtrims it for login identity.
                continue;
            }
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('The shop URL path is invalid.');
            }
            if (!preg_match('/^[A-Za-z0-9._~-]+$/', $segment)) {
                throw new InvalidArgumentException('The shop URL path is invalid.');
            }
        }
    }
}
