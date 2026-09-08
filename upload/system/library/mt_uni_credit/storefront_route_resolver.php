<?php

/**
 * OpenCart storefront route helpers (homepage + Product Buy lifecycle classification).
 *
 * AUD-018 F02: classify routes so active Buy preference clears on unrelated browsing
 * without breaking Buy stash → cart/add → Checkout, or Checkout AJAX.
 */
final class MtUniCreditStorefrontRouteResolver
{
    /**
     * @param mixed $route
     * @return string
     */
    public static function currentRoute($route)
    {
        return trim((string) $route);
    }

    /**
     * @param mixed $route
     * @return bool
     */
    public static function isHomepageRoute($route)
    {
        $route = self::currentRoute($route);

        return $route === '' || $route === 'common/home';
    }

    /**
     * Product Buy stash / product widget endpoints.
     *
     * @param mixed $route
     * @return bool
     */
    public static function isProductBuyRoute($route)
    {
        $route = self::currentRoute($route);
        if ($route === '') {
            return false;
        }
        if (strpos($route, 'extension/mt_uni_credit/product') === 0) {
            return true;
        }

        return strpos($route, 'extension/mt_uni_credit/product_buy') === 0;
    }

    /**
     * Native cart add used during Product Buy handoff.
     *
     * @param mixed $route
     * @return bool
     */
    public static function isCartAddRoute($route)
    {
        return self::currentRoute($route) === 'checkout/cart/add';
    }

    /**
     * Cart page (full clear of Buy preference).
     *
     * @param mixed $route
     * @return bool
     */
    public static function isCartPageRoute($route)
    {
        $route = self::currentRoute($route);
        if (self::isCartAddRoute($route)) {
            return false;
        }

        return $route === 'checkout/cart' || strpos($route, 'checkout/cart/') === 0;
    }

    /**
     * Checkout entry page.
     *
     * @param mixed $route
     * @return bool
     */
    public static function isCheckoutEntryRoute($route)
    {
        return self::currentRoute($route) === 'checkout/checkout';
    }

    /**
     * Same Checkout navigation lifecycle (entry, steps, payment AJAX, UniCredit checkout).
     * Excludes cart page, cart/add, and success.
     *
     * @param mixed $route
     * @return bool
     */
    public static function isCheckoutLifecycleRoute($route)
    {
        $route = self::currentRoute($route);
        if ($route === '') {
            return false;
        }
        if ($route === 'checkout/success' || strpos($route, 'checkout/success/') === 0) {
            return false;
        }
        if (self::isCartAddRoute($route)) {
            return true;
        }
        if (self::isCartPageRoute($route)) {
            return false;
        }
        if (strpos($route, 'checkout/') === 0) {
            return true;
        }
        if (strpos($route, 'extension/payment/mt_uni_credit') === 0) {
            return true;
        }
        if (strpos($route, 'extension/mt_uni_credit/') === 0) {
            // Product widget/buy endpoints are handled separately; other mt_uni_credit
            // checkout helpers stay in lifecycle.
            if (self::isProductBuyRoute($route)) {
                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * Product information page (pending preserved; active cleared).
     *
     * @param mixed $route
     * @return bool
     */
    public static function isProductPageRoute($route)
    {
        return self::currentRoute($route) === 'product/product';
    }

    /**
     * Unrelated storefront browsing that must terminate an active Buy lifecycle.
     *
     * @param mixed $route
     * @return bool
     */
    public static function isUnrelatedStorefrontRoute($route)
    {
        $route = self::currentRoute($route);
        if ($route === '') {
            return true;
        }
        if (self::isProductBuyRoute($route) || self::isCartAddRoute($route)) {
            return false;
        }
        if (self::isCheckoutLifecycleRoute($route)) {
            return false;
        }
        if (self::isProductPageRoute($route)) {
            return false;
        }
        // Cart page is "related" only as an explicit clear target (handled by caller).
        if (self::isCartPageRoute($route) || self::isHomepageRoute($route)) {
            return true;
        }
        if (strpos($route, 'product/') === 0) {
            return true;
        }
        if (strpos($route, 'information/') === 0) {
            return true;
        }
        if (strpos($route, 'account/') === 0) {
            return true;
        }
        if (strpos($route, 'common/') === 0) {
            return true;
        }
        if (strpos($route, 'extension/') === 0) {
            return false;
        }
        // Any other catalog controller route is unrelated browsing.
        return true;
    }
}
