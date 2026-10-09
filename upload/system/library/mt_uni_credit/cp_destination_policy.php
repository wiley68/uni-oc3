<?php

/**
 * Structural CP URL and public-network validation only.
 * The deployment environment file owns the destination; this class has no host allowlist.
 */
final class MtUniCreditCpDestinationPolicy
{
    const API_PATH = '/api/v1';

    /**
     * Validate and canonicalize a CP origin from deployment config (no /api/v1).
     *
     * Leading/trailing whitespace is intentionally trimmed before validation.
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
     * Leading/trailing whitespace is intentionally trimmed before validation.
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
        // Intentional: trim outer whitespace, then reject any remaining whitespace.
        $url = trim($url);
        if ($url === '') {
            throw new InvalidArgumentException('The ' . $label . ' URL is empty.');
        }

        // Reject before parse_url so interior whitespace/control characters cannot be normalized away.
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
            throw new MtUniCreditCpDestinationException('The ' . $label . ' hostname must be a public DNS name.', 'destination_unsafe');
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
        if (!$this->isPublicHostname($host)) {
            throw new MtUniCreditCpDestinationException('The ' . $label . ' hostname must be a public DNS name.', 'destination_unsafe');
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

    private function isPublicHostname($host)
    {
        if (strlen($host) > 253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9](?:[a-z0-9-]{0,56}[a-z0-9])?)$/D', $host)) {
            return false;
        }
        // Special-use/local namespaces, not a list of CP deployments.
        foreach (array('localhost', 'local', 'internal', 'lan', 'home', 'home.arpa', 'onion', 'invalid', 'test', 'example') as $suffix) {
            if ($host === $suffix || substr($host, -strlen($suffix) - 1) === '.' . $suffix) {
                return false;
            }
        }

        return true;
    }

    /** Reject private, loopback, link-local, multicast and reserved address ranges. */
    public function assertPublicAddress($address)
    {
        if (!is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new MtUniCreditCpDestinationException('The CP address is invalid.', 'destination_unsafe');
        }
        $binary = inet_pton($address);
        $ranges = strlen($binary) === 4
            ? array('0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/3')
            : array('2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20');
        if (strlen($binary) === 16 && !$this->inRange($binary, '2000::/3')) {
            throw new MtUniCreditCpDestinationException('The CP address is not public.', 'destination_unsafe');
        }
        foreach ($ranges as $range) {
            if ($this->inRange($binary, $range)) {
                throw new MtUniCreditCpDestinationException('The CP address is not public.', 'destination_unsafe');
            }
        }

        return $address;
    }

    /** Resolve a bounded CNAME chain; transport pins the public answer against rebinding. */
    public function resolvePublicAddresses($host, $resolver = null)
    {
        if (!is_callable($resolver) && !function_exists('dns_get_record')) {
            throw new MtUniCreditCpConnectionException('CP DNS resolution is unavailable.', 0, null, false, 'dns_failure');
        }
        $visited = array();
        for ($depth = 0; $depth < 8; $depth++) {
            if (!$this->isPublicHostname($host)) {
                throw new MtUniCreditCpDestinationException('The CP hostname is unsafe.', 'destination_unsafe');
            }
            if (isset($visited[$host])) {
                break;
            }
            $visited[$host] = true;
            $records = is_callable($resolver) ? call_user_func($resolver, $host) : @dns_get_record($host, DNS_A | DNS_AAAA | DNS_CNAME);
            $addresses = array();
            $alias = null;
            foreach (is_array($records) ? $records : array() as $record) {
                $address = isset($record['ip']) ? $record['ip'] : (isset($record['ipv6']) ? $record['ipv6'] : null);
                if ($address !== null) {
                    $addresses[] = $this->assertPublicAddress($address);
                } elseif (isset($record['type'], $record['target']) && $record['type'] === 'CNAME') {
                    $alias = strtolower(rtrim($record['target'], '.'));
                }
            }
            if ($addresses !== array()) {
                return array_values(array_unique($addresses));
            }
            if ($alias === null) {
                break;
            }
            $host = $alias;
        }
        throw new MtUniCreditCpConnectionException('CP DNS resolution failed.', 0, null, false, 'dns_failure');
    }

    private function inRange($binary, $range)
    {
        list($network, $bits) = explode('/', $range);
        $network = inet_pton($network);
        if (strlen($network) !== strlen($binary)) {
            return false;
        }
        $bytes = intdiv((int) $bits, 8);
        $remaining = (int) $bits % 8;
        if (substr($binary, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }

        return $remaining === 0 || (ord($binary[$bytes]) & (255 << (8 - $remaining))) === (ord($network[$bytes]) & (255 << (8 - $remaining)));
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
