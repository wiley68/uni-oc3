<?php

/**
 * Homepage advertising permission gates (module eligibility and CP shop flags).
 */
final class MtUniCreditHomepageAdvertisingGate
{
    /**
     * @param string $route
     * @return bool
     */
    public function allowsPage($route)
    {
        return MtUniCreditStorefrontRouteResolver::isHomepageRoute($route);
    }

    /**
     * @param bool $moduleActive
     * @param bool $moduleEnabled
     * @param string $unicid
     * @return bool
     */
    public function allowsLocalSettings($moduleActive, $moduleEnabled, $unicid)
    {
        return $moduleActive
            && $moduleEnabled
            && trim((string) $unicid) !== '';
    }

    /**
     * @param string $route
     * @param bool $moduleActive
     * @param bool $moduleEnabled
     * @param string $unicid
     * @return bool
     */
    public function allowsAssets($route, $moduleActive, $moduleEnabled, $unicid)
    {
        return $this->allowsPage($route)
            && $this->allowsLocalSettings($moduleActive, $moduleEnabled, $unicid);
    }

    /**
     * @param array<string, mixed> $shop
     * @return bool
     */
    public function allowsShop(array $shop)
    {
        return MtUniCreditShopConfigurationFlags::isYesFlag(isset($shop['uni_status']) ? $shop['uni_status'] : 0)
            && MtUniCreditShopConfigurationFlags::isYesFlag(
                isset($shop['uni_container_status']) ? $shop['uni_container_status'] : 0
            );
    }
}
