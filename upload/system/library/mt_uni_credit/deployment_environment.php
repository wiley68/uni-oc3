<?php

/**
 * Reads module-local deployment endpoints from config/environment.php.
 *
 * Single authoritative Control Panel host source — do not duplicate elsewhere.
 */
final class MtUniCreditDeploymentEnvironment
{
    const RELATIVE_PATH = 'config/environment.php';

    const CONTROL_PANEL_URL_KEY = 'control_panel_url';

    const API_PATH_PREFIX = '/api/v1';

    /** @var string */
    private $configFilePath;

    /**
     * @param string|null $configFilePath
     */
    public function __construct($configFilePath = null)
    {
        $this->configFilePath = $configFilePath !== null && $configFilePath !== ''
            ? (string) $configFilePath
            : MtUniCreditExtensionRoot::path() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::RELATIVE_PATH);
    }

    /**
     * Authoritative Control Panel host base (no API suffix), e.g. https://cp.example.com
     *
     * @return string
     */
    public function controlPanelUrl()
    {
        $loaded = $this->load();
        $url = isset($loaded[self::CONTROL_PANEL_URL_KEY]) ? $loaded[self::CONTROL_PANEL_URL_KEY] : null;
        if (!is_string($url)) {
            throw new RuntimeException('Control Panel URL is not configured in config/environment.php.');
        }
        $url = rtrim(trim($url), '/');
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            throw new RuntimeException('Control Panel URL is invalid in config/environment.php.');
        }

        return $url;
    }

    /**
     * Outbound CP HTTP API base (host + /api/v1).
     *
     * @return string
     */
    public function controlPanelApiBaseUrl()
    {
        return $this->controlPanelUrl() . self::API_PATH_PREFIX;
    }

    /**
     * Safe host for admin display (no credentials, no path).
     *
     * @return string|null
     */
    public function controlPanelHost()
    {
        try {
            $parts = parse_url($this->controlPanelUrl());
        } catch (Exception $exception) {
            return null;
        }

        if (!is_array($parts) || !isset($parts['host']) || !is_string($parts['host']) || $parts['host'] === '') {
            return null;
        }

        return $parts['host'];
    }

    /**
     * @return string
     */
    public function configFilePath()
    {
        return $this->configFilePath;
    }

    /**
     * @return bool
     */
    public function isReadable()
    {
        return is_file($this->configFilePath) && is_readable($this->configFilePath);
    }

    /**
     * @return array<string, mixed>
     */
    private function load()
    {
        if (!is_file($this->configFilePath) || !is_readable($this->configFilePath)) {
            throw new RuntimeException('Deployment environment file config/environment.php is missing or unreadable.');
        }

        $loaded = include $this->configFilePath;
        if (!is_array($loaded)) {
            throw new RuntimeException('Deployment environment file config/environment.php must return an array.');
        }

        return $loaded;
    }
}
