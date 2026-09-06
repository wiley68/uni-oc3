<?php

/**
 * Strict trusted HTTPS destination policy for Control Panel API traffic (AUD-008-F02).
 *
 * Validates parsed URL components. Not a full SSRF framework — destination is
 * trusted deployment configuration, never request/admin controlled.
 */
final class MtUniCreditCpDestinationPolicy
{
    const HOST_PRODUCTION = 'uni.avalonbg.com';

    /** Offline/package-test fixture host only (tests/fixtures/cp_test_environment.php). */
    const HOST_OFFLINE_TEST = 'cp-test.example.com';

    const API_PATH = '/api/v1';

    /** @var array<int, string> */
    private static $trustedHosts = array(
        self::HOST_PRODUCTION,
        self::HOST_OFFLINE_TEST,
    );

    /**
     * Validate and canonicalize a CP origin from deployment config (no /api/v1).
     *
     * Accepted forms:
     *   https://uni.avalonbg.com
     *   https://uni.avalonbg.com/
     *
     * @param string $url
     * @return string https://host
     */
    public function assertTrustedOrigin($url)
    {
        $parts = $this->parseStrictHttpsUrl($url, 'Control Panel origin');
        $this->assertTrustedHostAndPort($parts, 'Control Panel origin');
        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        if ($path !== '' && $path !== '/') {
            throw new InvalidArgumentException('The Control Panel origin must not include a path.');
        }

        return 'https://' . strtolower((string) $parts['host']);
    }

    /**
     * Validate and canonicalize a CP API base URL (origin + /api/v1).
     *
     * @param string $url
     * @return string https://host/api/v1
     */
    public function assertTrustedApiBase($url)
    {
        $parts = $this->parseStrictHttpsUrl($url, 'Control Panel API base');
        $this->assertTrustedHostAndPort($parts, 'Control Panel API base');
        $path = $this->normalizedAbsolutePath((string) (isset($parts['path']) ? $parts['path'] : ''));
        if ($path !== self::API_PATH) {
            throw new InvalidArgumentException('The Control Panel API path must be exactly /api/v1.');
        }

        return 'https://' . strtolower((string) $parts['host']) . self::API_PATH;
    }

    /**
     * @param string $url
     * @return string
     */
    public function describeUrlForLog($url)
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '[unparseable]';
        }
        $authority = strtolower((string) (isset($parts['host']) ? $parts['host'] : ''));
        if (isset($parts['port'])) {
            $authority .= ':' . (int) $parts['port'];
        }

        return strtolower((string) (isset($parts['scheme']) ? $parts['scheme'] : ''))
            . '://' . $authority . (string) (isset($parts['path']) ? $parts['path'] : '');
    }

    /**
     * @param string $url
     * @param string $label
     * @return array<string, mixed>
     */
    private function parseStrictHttpsUrl($url, $label)
    {
        if (!is_string($url)) {
            throw new InvalidArgumentException('The ' . $label . ' URL is malformed.');
        }
        $url = trim($url);
        if ($url === '') {
            throw new InvalidArgumentException('The ' . $label . ' URL is empty.');
        }

        // Reject before parse_url so whitespace/control characters cannot be normalized away.
        if (preg_match('/\s/', $url)) {
            throw new InvalidArgumentException('The ' . $label . ' URL is malformed.');
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('The ' . $label . ' URL is malformed.');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('The ' . $label . ' URL is malformed.');
        }
        if (strtolower((string) $parts['scheme']) !== 'https') {
            throw new InvalidArgumentException('The ' . $label . ' URL must use HTTPS.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('The ' . $label . ' URL must not contain userinfo.');
        }
        if (array_key_exists('query', $parts)) {
            throw new InvalidArgumentException('The ' . $label . ' URL must not contain a query string.');
        }
        if (array_key_exists('fragment', $parts)) {
            throw new InvalidArgumentException('The ' . $label . ' URL must not contain a fragment.');
        }

        $host = (string) $parts['host'];
        if ($host === '' || $this->isIpLiteral($host)) {
            throw new InvalidArgumentException('The ' . $label . ' hostname is not trusted.');
        }

        return $parts;
    }

    /**
     * @param array<string, mixed> $parts
     * @param string $label
     * @return void
     */
    private function assertTrustedHostAndPort(array $parts, $label)
    {
        $host = strtolower((string) $parts['host']);
        if (!in_array($host, self::$trustedHosts, true)) {
            throw new InvalidArgumentException('The ' . $label . ' hostname is not trusted.');
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            throw new InvalidArgumentException('The ' . $label . ' URL must use the default HTTPS port.');
        }
    }

    /**
     * @param string $path
     * @return string
     */
    private function normalizedAbsolutePath($path)
    {
        if ($path === '' || $path === '/') {
            return '';
        }

        return rtrim('/' . ltrim($path, '/'), '/');
    }

    /**
     * @param string $host
     * @return bool
     */
    private function isIpLiteral($host)
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        // Bracketed IPv6 in authority (parse_url may strip brackets depending on PHP).
        if ($host !== '' && $host[0] === '[') {
            return true;
        }

        return false;
    }
}
