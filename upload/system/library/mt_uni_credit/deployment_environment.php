<?php

/**
 * Reads module-local deployment endpoints from the extension-owned environment file.
 *
 * Single authoritative Control Panel host source — do not duplicate elsewhere.
 * Path: system/library/mt_uni_credit/config/environment.php
 */
final class MtUniCreditDeploymentEnvironment
{
    const RELATIVE_PATH = 'config/environment.php';

    const CONTROL_PANEL_URL_KEY = 'control_panel_url';

    const API_PATH_PREFIX = '/api/v1';

    /** @var string */
    private $configFilePath;

    /** @var MtUniCreditCpDestinationPolicy */
    private $destinationPolicy;

    /**
     * @param string|null $configFilePath
     * @param MtUniCreditCpDestinationPolicy|null $destinationPolicy
     */
    public function __construct($configFilePath = null, $destinationPolicy = null)
    {
        $this->configFilePath = $configFilePath !== null && $configFilePath !== ''
            ? (string) $configFilePath
            : MtUniCreditExtensionRoot::path() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::RELATIVE_PATH);
        $this->destinationPolicy = $destinationPolicy instanceof MtUniCreditCpDestinationPolicy
            ? $destinationPolicy
            : new MtUniCreditCpDestinationPolicy();
    }

    /**
     * Authoritative Control Panel host origin (no API suffix), e.g. https://uni.avalonbg.com
     *
     * @return string
     */
    public function controlPanelUrl()
    {
        $loaded = $this->load();
        $url = isset($loaded[self::CONTROL_PANEL_URL_KEY]) ? $loaded[self::CONTROL_PANEL_URL_KEY] : null;
        if (!is_string($url)) {
            throw new RuntimeException('Control Panel URL is not configured in system/library/mt_uni_credit/config/environment.php.');
        }

        try {
            return $this->destinationPolicy->assertTrustedOrigin($url);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException(
                'Control Panel URL is invalid in system/library/mt_uni_credit/config/environment.php.',
                0,
                $exception
            );
        }
    }

    /**
     * Outbound CP HTTP API base (host + /api/v1).
     *
     * @return string
     */
    public function controlPanelApiBaseUrl()
    {
        try {
            return $this->destinationPolicy->assertTrustedApiBase(
                $this->controlPanelUrl() . self::API_PATH_PREFIX
            );
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException(
                'Control Panel API base URL is invalid.',
                0,
                $exception
            );
        }
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

        return strtolower($parts['host']);
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
            throw new RuntimeException(
                'Deployment environment file system/library/mt_uni_credit/config/environment.php is missing or unreadable.'
            );
        }

        $loaded = include $this->configFilePath;
        if (!is_array($loaded)) {
            throw new RuntimeException(
                'Deployment environment file system/library/mt_uni_credit/config/environment.php must return an array.'
            );
        }

        return $loaded;
    }
}
