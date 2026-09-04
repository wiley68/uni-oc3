<?php

/**
 * Resolves homepage advertising view-model once per request (cache-only CP shop).
 */
final class MtUniCreditHomepageAdvertisingContextResolver
{
    /** @var array<string, array<string, mixed>|null|false> */
    private static $requestCache = array();

    /** @var MtUniCreditHomepageAdvertisingGate */
    private $gate;

    /** @var MtUniCreditHomepageAdvertisingPresenter */
    private $presenter;

    /** @var MtUniCreditShopConfigurationService */
    private $shopConfiguration;

    /** @var MtUniCreditCredentialsRepository */
    private $credentials;

    /** @var int */
    private $storeId;

    /**
     * @param MtUniCreditHomepageAdvertisingGate $gate
     * @param MtUniCreditHomepageAdvertisingPresenter $presenter
     * @param MtUniCreditShopConfigurationService $shopConfiguration
     * @param MtUniCreditCredentialsRepository $credentials
     * @param int $storeId
     */
    public function __construct(
        MtUniCreditHomepageAdvertisingGate $gate,
        MtUniCreditHomepageAdvertisingPresenter $presenter,
        MtUniCreditShopConfigurationService $shopConfiguration,
        MtUniCreditCredentialsRepository $credentials,
        $storeId
    ) {
        $this->gate = $gate;
        $this->presenter = $presenter;
        $this->shopConfiguration = $shopConfiguration;
        $this->credentials = $credentials;
        $this->storeId = (int) $storeId;
    }

    /**
     * @param bool $isHomepage
     * @param bool $moduleEnabled
     * @param bool $advertisingEnabled
     * @param bool $isMobile
     * @param string $defaultLogoUrl
     * @return array<string, mixed>|null
     */
    public function resolve($isHomepage, $moduleEnabled, $advertisingEnabled, $isMobile, $defaultLogoUrl)
    {
        $cacheKey = implode('|', array(
            (string) $this->storeId,
            $isHomepage ? '1' : '0',
            $moduleEnabled ? '1' : '0',
            $advertisingEnabled ? '1' : '0',
            $isMobile ? '1' : '0',
        ));
        if (array_key_exists($cacheKey, self::$requestCache)) {
            $cached = self::$requestCache[$cacheKey];

            return is_array($cached) ? $cached : null;
        }

        if (
            !$isHomepage
            || !$this->gate->allowsLocalSettings(
                (bool) $moduleEnabled,
                (bool) $moduleEnabled,
                (bool) $advertisingEnabled,
                $this->credentials->getUnicid($this->storeId)
            )
        ) {
            self::$requestCache[$cacheKey] = false;

            return null;
        }

        $shop = $this->shopConfiguration->getCachedOnly();
        if ($shop === null || $shop === array()) {
            self::$requestCache[$cacheKey] = false;

            return null;
        }

        $context = $this->presenter->present($shop, (bool) $isMobile, (string) $defaultLogoUrl);
        self::$requestCache[$cacheKey] = $context !== null ? $context : false;

        return $context;
    }

    /**
     * @return void
     */
    public static function resetRequestCache()
    {
        self::$requestCache = array();
    }
}
