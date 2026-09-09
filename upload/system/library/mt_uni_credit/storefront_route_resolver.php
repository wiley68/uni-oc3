<?php

/**
 * OpenCart storefront route helpers (homepage + Product Buy lifecycle classification).
 *
 * AUD-018 F02: classify routes so active Buy preference clears on unrelated browsing
 * without breaking Buy stash → cart/add → Checkout, or Checkout AJAX.
 *
 * Nested common/* layout fragments (header/footer/columns/cart chrome) are NOT
 * unrelated browsing — OC3 fires them via Loader during Checkout page render.
 *
 * Checkout / extension preservation is an explicit allowlist (not checkout/* or extension/*).
 */
final class MtUniCreditStorefrontRouteResolver
{
    /**
     * Native OC3 Checkout lifecycle controllers used by default checkout.twig AJAX
     * and checkout controllers (reference-oc3-store). Not a blanket checkout/* prefix.
     *
     * @return string[]
     */
    private static function checkoutLifecycleBases()
    {
        return array(
            'checkout/checkout',
            'checkout/login',
            'checkout/register',
            'checkout/guest',
            'checkout/guest_shipping',
            'checkout/payment_address',
            'checkout/shipping_address',
            'checkout/shipping_method',
            'checkout/payment_method',
            'checkout/confirm',
            'checkout/cart/add',
        );
    }

    /**
     * UniCredit payment endpoints invoked from Checkout confirm / payment step.
     *
     * @return string[]
     */
    private static function unicreditPaymentLifecycleBases()
    {
        return array(
            'extension/payment/mt_uni_credit',
        );
    }

    /**
     * @param string $route
     * @param string $base
     * @return bool
     */
    private static function matchesRouteBase($route, $base)
    {
        return $route === $base || strpos($route, $base . '/') === 0;
    }

    /**
     * @param string $route
     * @param string[] $bases
     * @return bool
     */
    private static function matchesAnyRouteBase($route, array $bases)
    {
        foreach ($bases as $base) {
            if (self::matchesRouteBase($route, $base)) {
                return true;
            }
        }

        return false;
    }

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
     * Exact base or base + "/" sub-action only (no sibling prefixes).
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

        return self::matchesRouteBase($route, 'extension/mt_uni_credit/product')
            || self::matchesRouteBase($route, 'extension/mt_uni_credit/product_buy');
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
     * Intended Checkout navigation lifecycle (entry, steps, payment AJAX, UniCredit).
     * Explicit allowlist only — excludes cart page, success, failure, and unrelated checkout/*.
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
        if (self::isCartPageRoute($route)) {
            return false;
        }
        if (self::matchesAnyRouteBase($route, self::checkoutLifecycleBases())) {
            return true;
        }
        if (self::matchesAnyRouteBase($route, self::unicreditPaymentLifecycleBases())) {
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
     * Nested layout / chrome controllers fired by Loader during a page render.
     *
     * OC3 Checkout (and most catalog pages) load common/header|footer|column_*|cart
     * via load->controller(). Those nested routes fire the catalog controller
     * wildcard before-event but are NOT storefront browsing — clearing Buy
     * preference here wipes activation before payment_method AJAX runs.
     *
     * common/home remains a homepage clear target (handled separately).
     *
     * @param mixed $route
     * @return bool
     */
    public static function isLayoutFragmentRoute($route)
    {
        $route = self::currentRoute($route);
        if ($route === '' || self::isHomepageRoute($route)) {
            return false;
        }

        return strpos($route, 'common/') === 0;
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
        // Nested layout loads during page render — not browsing.
        if (self::isLayoutFragmentRoute($route)) {
            return false;
        }
        // Cart page / home are explicit clear targets (handled by caller).
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
        // Unrelated extension/* and checkout/* (not on allowlist) clear active preference.
        if (strpos($route, 'extension/') === 0 || strpos($route, 'checkout/') === 0) {
            return true;
        }
        // Any other catalog controller route is unrelated browsing.
        return true;
    }
}
