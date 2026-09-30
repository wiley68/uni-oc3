<?php

/**
 * Checkout payment availability — PRESENTATION surface.
 *
 * Uses the shared shop configuration presentation resolver (local fresh hit, otherwise one
 * coordinated lazy refresh per exact scope, LKG only after a transiently failed attempt).
 * A legacy offline MtUniCreditShopConfigurationCache is still accepted for local-only wiring
 * (fresh-only, fail closed) so offline harnesses keep their frozen semantics.
 */
final class MtUniCreditCheckoutPaymentAvailability
{
    /** @var MtUniCreditCheckoutFinancingEligibility */
    private $eligibility;

    /** @var MtUniCreditOc3CartContextFactory */
    private $cartContextFactory;

    /** @var MtUniCreditShopConfigurationService|MtUniCreditShopConfigurationCache */
    private $shopConfiguration;

    /** @var MtUniCreditCredentialsRepository */
    private $credentials;

    /**
     * @param MtUniCreditShopConfigurationService|MtUniCreditShopConfigurationCache $shopConfiguration
     * @param MtUniCreditCredentialsRepository $credentials
     * @param MtUniCreditOc3CartContextFactory $cartContextFactory
     * @param MtUniCreditCheckoutFinancingEligibility|null $eligibility
     */
    public function __construct(
        $shopConfiguration,
        MtUniCreditCredentialsRepository $credentials,
        MtUniCreditOc3CartContextFactory $cartContextFactory,
        $eligibility = null
    ) {
        $this->shopConfiguration = $shopConfiguration;
        $this->credentials = $credentials;
        $this->cartContextFactory = $cartContextFactory;
        $this->eligibility = $eligibility instanceof MtUniCreditCheckoutFinancingEligibility
            ? $eligibility
            : new MtUniCreditCheckoutFinancingEligibility();
    }

    /**
     * @param array<string, mixed> $address
     * @param float $checkoutGrandTotal
     * @param string $currencyCode
     * @param array<int, array<string, mixed>> $cartProducts
     * @param int $storeId
     * @param bool $moduleEnabled
     * @param bool $paymentEnabled
     * @param int $geoZoneId
     * @param MtUniCreditDbAdapter $db
     * @return bool
     */
    public function isAvailable(
        array $address,
        $checkoutGrandTotal,
        $currencyCode,
        array $cartProducts,
        $storeId,
        $moduleEnabled,
        $paymentEnabled,
        $geoZoneId,
        MtUniCreditDbAdapter $db,
        $currencyValue = null
    ) {
        if (!$moduleEnabled || !$paymentEnabled) {
            return false;
        }
        if ($cartProducts === array() || (float) $checkoutGrandTotal <= 0.0) {
            return false;
        }
        if (!$this->isGeoZoneAllowed($address, (int) $geoZoneId, $db)) {
            return false;
        }

        $shop = $this->loadPresentationShopSnapshot((int) $storeId);
        if ($shop === null) {
            return false;
        }

        if (!MtUniCreditEurAmount::isEur($currencyCode) || !MtUniCreditEurAmount::validFactor($currencyValue)) {
            return false;
        }
        try {
            $cart = MtUniCreditEurAmount::cartContext(
                $this->cartContextFactory->create($cartProducts, (float) $checkoutGrandTotal),
                $currencyValue
            );
        } catch (InvalidArgumentException $exception) {
            return false;
        }

        return $this->eligibility->isEligible(
            $shop,
            $cart,
            (string) $currencyCode,
            $moduleEnabled,
            $paymentEnabled
        );
    }

    /**
     * @param int $storeId
     * @param float $checkoutGrandTotal
     * @param string $currencyCode
     * @param array<int, array<string, mixed>> $cartProducts
     * @param bool $moduleEnabled
     * @param bool $paymentEnabled
     * @return bool
     */
    public function isEligibleForPreparedOrder(
        $storeId,
        $checkoutGrandTotal,
        $currencyCode,
        array $cartProducts,
        $moduleEnabled,
        $paymentEnabled,
        $currencyValue = null
    ) {
        if (!$moduleEnabled || !$paymentEnabled) {
            return false;
        }

        $shop = $this->loadPresentationShopSnapshot((int) $storeId);
        if ($shop === null) {
            return false;
        }

        if (!MtUniCreditEurAmount::isEur($currencyCode) || !MtUniCreditEurAmount::validFactor($currencyValue)) {
            return false;
        }
        try {
            $cart = MtUniCreditEurAmount::cartContext(
                $this->cartContextFactory->create($cartProducts, (float) $checkoutGrandTotal),
                $currencyValue
            );
        } catch (InvalidArgumentException $exception) {
            return false;
        }

        return $this->eligibility->isEligible(
            $shop,
            $cart,
            (string) $currencyCode,
            $moduleEnabled,
            $paymentEnabled
        );
    }

    /**
     * @param array<string, mixed> $address
     * @param int $geoZoneId
     * @param MtUniCreditDbAdapter $db
     * @return bool
     */
    public function isGeoZoneAllowed(array $address, $geoZoneId, MtUniCreditDbAdapter $db)
    {
        if ((int) $geoZoneId <= 0) {
            return true;
        }

        $countryId = (int) (isset($address['country_id']) ? $address['country_id'] : 0);
        $zoneId = (int) (isset($address['zone_id']) ? $address['zone_id'] : 0);
        $table = $db->getPrefix() . 'zone_to_geo_zone';
        $result = $db->query(
            "SELECT * FROM `{$table}`"
                . " WHERE `geo_zone_id` = " . (int) $geoZoneId
                . " AND `country_id` = " . $countryId
                . " AND (`zone_id` = " . $zoneId . " OR `zone_id` = 0)"
                . " LIMIT 1"
        );

        return is_object($result) && isset($result->num_rows) && (int) $result->num_rows > 0;
    }

    /**
     * @param int $storeId
     * @return array<string, mixed>|null
     */
    private function loadPresentationShopSnapshot($storeId)
    {
        $unicid = $this->credentials->getUnicid($storeId);
        if ($unicid === '') {
            return null;
        }
        if (!$this->credentials->hasSecret($storeId) || !$this->credentials->isSecretReadable($storeId)) {
            return null;
        }

        if ($this->shopConfiguration instanceof MtUniCreditShopConfigurationService) {
            return $this->shopConfiguration->getForPresentation();
        }

        if ($this->shopConfiguration instanceof MtUniCreditShopConfigurationCache) {
            // Legacy offline wiring: fresh-only, never LKG, never CP.
            return $this->shopConfiguration->getFreshShopData($storeId, $unicid);
        }

        return null;
    }
}
