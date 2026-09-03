<?php

/**
 * Trusted SmartUCF HTTPS endpoint allowlist and redirect builders.
 */
final class MtUniCreditSmartUcfEndpointPolicy
{
    const HOST_PRODUCTION = 'online.ucfin.bg';
    const HOST_TEST = 'onlinetest.ucfin.bg';
    const SERVICE_PATH = '/suos/api/otp';
    const APPLICATION_PATH = '/sucf-online/Request/Start';
    const SESSION_START_SUFFIX = 'sucfOnlineSessionStart';

    /** @var array<int, string> */
    private static $trustedHosts = array(self::HOST_PRODUCTION, self::HOST_TEST);

    /**
     * @param string $url
     * @return string
     */
    public function assertTrustedServiceBase($url)
    {
        $parts = $this->parseStrictHttpsUrl($url, 'SmartUCF service');
        $this->assertTrustedHostAndPort($parts, 'SmartUCF service');
        if ($this->normalizedAbsolutePath((string) (isset($parts['path']) ? $parts['path'] : '')) !== self::SERVICE_PATH) {
            throw new InvalidArgumentException('The SmartUCF service path is not trusted.');
        }

        return 'https://' . strtolower((string) $parts['host']) . self::SERVICE_PATH . '/';
    }

    /**
     * @param string $serviceBaseUrl
     * @return string
     */
    public function buildSessionStartUrl($serviceBaseUrl)
    {
        return $this->assertTrustedServiceBase($serviceBaseUrl) . self::SESSION_START_SUFFIX;
    }

    /**
     * @param string $url
     * @return string
     */
    public function assertTrustedApplicationBase($url)
    {
        $parts = $this->parseStrictHttpsUrl($url, 'SmartUCF application');
        $this->assertTrustedHostAndPort($parts, 'SmartUCF application');
        if ($this->normalizedAbsolutePath((string) (isset($parts['path']) ? $parts['path'] : '')) !== self::APPLICATION_PATH) {
            throw new InvalidArgumentException('The SmartUCF application path is not trusted.');
        }

        return 'https://' . strtolower((string) $parts['host']) . self::APPLICATION_PATH;
    }

    /**
     * @param string $applicationBaseUrl
     * @param string $sessionId
     * @return string
     */
    public function buildApplicationRedirect($applicationBaseUrl, $sessionId)
    {
        return $this->assertTrustedApplicationBase($applicationBaseUrl) . '/' . $this->assertSafeSessionId($sessionId);
    }

    /**
     * @param string $redirectUrl
     * @return bool
     */
    public function isTrustedApplicationRedirect($redirectUrl)
    {
        try {
            $this->assertTrustedApplicationRedirect($redirectUrl);

            return true;
        } catch (InvalidArgumentException $exception) {
            return false;
        }
    }

    /**
     * @param string $redirectUrl
     * @return string
     */
    public function assertTrustedApplicationRedirect($redirectUrl)
    {
        $parts = $this->parseStrictHttpsUrl($redirectUrl, 'SmartUCF redirect');
        $this->assertTrustedHostAndPort($parts, 'SmartUCF redirect');
        $path = (string) (isset($parts['path']) ? $parts['path'] : '');
        $prefix = self::APPLICATION_PATH . '/';
        if (strpos($path, $prefix) !== 0) {
            throw new InvalidArgumentException('The SmartUCF redirect path is not trusted.');
        }
        $sessionId = $this->assertSafeSessionId(substr($path, strlen($prefix)));

        return 'https://' . strtolower((string) $parts['host']) . $prefix . $sessionId;
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
        $url = trim($url);
        $parts = parse_url($url);
        if ($url === '' || !is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('The ' . $label . ' URL is malformed.');
        }
        if (strtolower((string) $parts['scheme']) !== 'https') {
            throw new InvalidArgumentException('The ' . $label . ' URL must use HTTPS.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || !empty($parts['query']) || !empty($parts['fragment'])) {
            throw new InvalidArgumentException('The ' . $label . ' URL contains forbidden components.');
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
        if (!in_array(strtolower((string) $parts['host']), self::$trustedHosts, true)) {
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
        return rtrim('/' . ltrim($path, '/'), '/');
    }

    /**
     * @param string $sessionId
     * @return string
     */
    private function assertSafeSessionId($sessionId)
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '' || strlen($sessionId) > 128 || !preg_match('/^[A-Za-z0-9._~-]+$/', $sessionId)) {
            throw new InvalidArgumentException('The SmartUCF session identifier is invalid.');
        }

        return $sessionId;
    }
}
