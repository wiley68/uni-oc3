<?php

/**
 * Transient Product Buy handoff preference (checkout payment + scheme UX).
 *
 * AUD-018: scoped to one Buy→Checkout navigation identity.
 * - pending until Checkout request proves matching mt_uni_nav
 * - active while request (or same-navigation AJAX) carries that navigation_id
 * - session guard alone does not grant preference to an unrelated Checkout
 *
 * Transport (manual-release):
 * 1. Query/body `mt_uni_nav` (redirect + optional OCMOD/JS)
 * 2. HttpOnly cookie `mt_uni_nav` issued on Buy stash — browser sends it on all
 *    same-origin Checkout AJAX without theme OCMOD / jQuery. Cleared with preference.
 *
 * Final submit authority is unchanged (explicit scheme_key only).
 */
final class MtUniCreditProductBuyPreference
{
    const SESSION_KEY = 'mt_uni_credit_product_buy_preference';

    /** Session guard binding preference.navigation_id after proven activation. */
    const CHECKOUT_GUARD_KEY = 'mt_uni_credit_buy_checkout_guard';

    /** Request query/post/cookie name carrying the Buy→Checkout navigation token. */
    const NAV_PARAM = 'mt_uni_nav';

    const FLOW = 'product_buy';

    const STATE_PENDING = 'pending';

    const STATE_ACTIVE = 'active';

    const TTL_SECONDS = 1800;

    /**
     * @param array<string, mixed> $sessionData
     * @param array<string, mixed> $fields
     * @return string New navigation_id
     */
    public static function save(array &$sessionData, array $fields)
    {
        $schemeType = trim((string) (isset($fields['scheme_type']) ? $fields['scheme_type'] : ''));
        $kopCode = trim((string) (isset($fields['kop_code']) ? $fields['kop_code'] : ''));
        $months = (int) (isset($fields['months']) ? $fields['months'] : 0);
        $filterId = (int) (isset($fields['filter_id']) ? $fields['filter_id'] : 0);
        $schemeKey = trim((string) (isset($fields['scheme_key']) ? $fields['scheme_key'] : ''));
        if ($schemeKey === '' && $schemeType !== '' && $kopCode !== '' && $months > 0) {
            $schemeKey = MtUniCreditStorefrontCalculatorPresenter::schemeKey(
                $schemeType,
                $kopCode,
                $months
            );
        }

        // New Buy replaces any previous navigation binding.
        unset($sessionData[self::CHECKOUT_GUARD_KEY]);

        $navigationId = self::newNavigationId();
        $sessionData[self::SESSION_KEY] = array(
            'flow' => self::FLOW,
            'store_id' => (int) (isset($fields['store_id']) ? $fields['store_id'] : 0),
            'product_id' => (int) (isset($fields['product_id']) ? $fields['product_id'] : 0),
            'scheme_type' => $schemeType,
            'kop_code' => $kopCode,
            'months' => $months,
            'filter_id' => $filterId,
            'scheme_key' => $schemeKey,
            'prefer_payment' => true,
            'payment_code' => MtUniCreditConstants::EXTENSION_CODE,
            'navigation_id' => $navigationId,
            'state' => self::STATE_PENDING,
            'created_at' => time(),
        );

        self::issueNavigationCookie($navigationId);

        return $navigationId;
    }

    /**
     * Extract navigation token from request get/post/cookie (association token only).
     *
     * Priority: POST → GET → cookie. Cookie is the reliable Checkout AJAX transport
     * when theme OCMOD cannot inject JS (Journal / missing checkout.twig anchor).
     *
     * @param object|null $request OpenCart request with ->get / ->post arrays
     * @return string
     */
    public static function requestNavigationId($request)
    {
        $fromGet = '';
        $fromPost = '';
        if (is_object($request)) {
            if (isset($request->get) && is_array($request->get) && isset($request->get[self::NAV_PARAM])) {
                $fromGet = trim((string) $request->get[self::NAV_PARAM]);
            }
            if (isset($request->post) && is_array($request->post) && isset($request->post[self::NAV_PARAM])) {
                $fromPost = trim((string) $request->post[self::NAV_PARAM]);
            }
        }
        if ($fromPost !== '' && self::isValidNavigationId($fromPost)) {
            return $fromPost;
        }
        if ($fromGet !== '' && self::isValidNavigationId($fromGet)) {
            return $fromGet;
        }

        return self::navigationIdFromCookie();
    }

    /**
     * Issue short-lived HttpOnly cookie so Checkout AJAX carries mt_uni_nav without JS.
     *
     * @param string $navigationId
     * @return void
     */
    public static function issueNavigationCookie($navigationId)
    {
        $navigationId = trim((string) $navigationId);
        if (!self::isValidNavigationId($navigationId)) {
            return;
        }

        $_COOKIE[self::NAV_PARAM] = $navigationId;
        if (headers_sent()) {
            return;
        }

        setcookie(self::NAV_PARAM, $navigationId, array(
            'expires' => time() + self::TTL_SECONDS,
            'path' => '/',
            'secure' => self::cookieSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ));
    }

    /**
     * Expire and drop the Buy navigation cookie (after use / invalidation).
     *
     * @return void
     */
    public static function clearNavigationCookie()
    {
        unset($_COOKIE[self::NAV_PARAM]);
        if (headers_sent()) {
            return;
        }

        setcookie(self::NAV_PARAM, '', array(
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => self::cookieSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ));
    }

    /**
     * @param string $checkoutUrl Absolute or relative checkout URL
     * @param string $navigationId
     * @return string
     */
    public static function appendNavigationToCheckoutUrl($checkoutUrl, $navigationId)
    {
        $checkoutUrl = trim((string) $checkoutUrl);
        $navigationId = trim((string) $navigationId);
        if ($checkoutUrl === '' || !self::isValidNavigationId($navigationId)) {
            return $checkoutUrl;
        }
        $sep = (strpos($checkoutUrl, '?') === false) ? '?' : '&';

        return $checkoutUrl . $sep . self::NAV_PARAM . '=' . rawurlencode($navigationId);
    }

    /**
     * Load preference for a Checkout request context.
     *
     * AUD-018 F01: pending activates only when request navigation_id matches.
     * Active is returned only when request navigation_id matches the stored identity.
     * Missing/mismatched token → null (no session-wide inheritance). Active record
     * is left intact so a parallel tab without the token cannot destroy Tab A.
     *
     * @param array<string, mixed> $sessionData
     * @param int|null $storeId When set, store mismatch clears preference
     * @param string|null $requestNavigationId From mt_uni_nav (null = treat as empty)
     * @return array<string, mixed>|null
     */
    public static function load(array &$sessionData, $storeId = null, $requestNavigationId = null)
    {
        if (!isset($sessionData[self::SESSION_KEY]) || !is_array($sessionData[self::SESSION_KEY])) {
            return null;
        }

        $raw = $sessionData[self::SESSION_KEY];
        $flow = isset($raw['flow']) ? (string) $raw['flow'] : '';
        $createdAt = (int) (isset($raw['created_at']) ? $raw['created_at'] : 0);
        $storedStoreId = (int) (isset($raw['store_id']) ? $raw['store_id'] : -1);
        $navigationId = trim((string) (isset($raw['navigation_id']) ? $raw['navigation_id'] : ''));
        $state = (string) (isset($raw['state']) ? $raw['state'] : '');
        $requestNavigationId = trim((string) ($requestNavigationId === null ? '' : $requestNavigationId));

        if ($flow !== self::FLOW || $createdAt <= 0 || (time() - $createdAt) > self::TTL_SECONDS) {
            self::clear($sessionData);

            return null;
        }

        if ($storeId !== null && (int) $storeId !== $storedStoreId) {
            self::clear($sessionData);

            return null;
        }

        // Legacy TTL-only preferences (no navigation scope) must not affect later Checkout.
        if ($navigationId === '' || ($state !== self::STATE_PENDING && $state !== self::STATE_ACTIVE)) {
            self::clear($sessionData);

            return null;
        }

        // Token required — session guard alone is not authority for a new Checkout request.
        if ($requestNavigationId === '' || !hash_equals($navigationId, $requestNavigationId)) {
            return null;
        }

        if ($state === self::STATE_PENDING) {
            $raw['state'] = self::STATE_ACTIVE;
            $sessionData[self::SESSION_KEY] = $raw;
            $sessionData[self::CHECKOUT_GUARD_KEY] = $navigationId;

            return $raw;
        }

        // Active + matching request token: keep guard aligned and return preference.
        $sessionData[self::CHECKOUT_GUARD_KEY] = $navigationId;

        return $raw;
    }

    /**
     * Competing Checkout entry without matching navigation token.
     * Fail-closed: clear pending so it cannot activate later unexpectedly.
     * Active is left intact (multi-tab isolation — Tab B must not destroy Tab A).
     *
     * @param array<string, mixed> $sessionData
     * @param string $requestNavigationId
     * @return void
     */
    public static function onCheckoutEntryWithoutMatchingNav(array &$sessionData, $requestNavigationId)
    {
        if (!isset($sessionData[self::SESSION_KEY]) || !is_array($sessionData[self::SESSION_KEY])) {
            return;
        }
        $raw = $sessionData[self::SESSION_KEY];
        $state = (string) (isset($raw['state']) ? $raw['state'] : '');
        $navigationId = trim((string) (isset($raw['navigation_id']) ? $raw['navigation_id'] : ''));
        $requestNavigationId = trim((string) $requestNavigationId);

        if ($state === self::STATE_PENDING) {
            if (
                $navigationId === ''
                || $requestNavigationId === ''
                || !hash_equals($navigationId, $requestNavigationId)
            ) {
                self::clear($sessionData);
            }

            return;
        }
        // Active without matching token: do not clear (Scenario C).
    }

    /**
     * Release the Checkout visit guard without requiring a full preference clear.
     *
     * @param array<string, mixed> $sessionData
     * @return void
     */
    public static function releaseCheckoutGuard(array &$sessionData)
    {
        unset($sessionData[self::CHECKOUT_GUARD_KEY]);
    }

    /**
     * Drop preference only after it was activated for a Buy Checkout visit.
     * Pending handoff (just stashed on Product) is preserved.
     *
     * @param array<string, mixed> $sessionData
     * @return void
     */
    public static function clearIfActivated(array &$sessionData)
    {
        if (!isset($sessionData[self::SESSION_KEY]) || !is_array($sessionData[self::SESSION_KEY])) {
            unset($sessionData[self::CHECKOUT_GUARD_KEY]);

            return;
        }
        $state = (string) (isset($sessionData[self::SESSION_KEY]['state'])
            ? $sessionData[self::SESSION_KEY]['state']
            : '');
        if ($state === self::STATE_ACTIVE) {
            self::clear($sessionData);

            return;
        }
        // Pending Buy handoff: keep preference, drop stray guard.
        unset($sessionData[self::CHECKOUT_GUARD_KEY]);
    }

    /**
     * Unrelated storefront navigation while a Buy preference exists.
     * Active → clear. Pending → clear (abandoned handoff) except callers that
     * preserve pending via clearIfActivated / route exceptions.
     *
     * @param array<string, mixed> $sessionData
     * @return void
     */
    public static function clearOnUnrelatedStorefront(array &$sessionData)
    {
        self::clear($sessionData);
    }

    /**
     * @param array<string, mixed> $sessionData
     * @return void
     */
    public static function clear(array &$sessionData)
    {
        unset($sessionData[self::SESSION_KEY], $sessionData[self::CHECKOUT_GUARD_KEY]);
        self::clearNavigationCookie();
    }

    /**
     * @param array<string, mixed> $preference
     * @return bool
     */
    public static function shouldPreferPayment(array $preference)
    {
        return !empty($preference['prefer_payment']);
    }

    /**
     * Apply UniCredit into session.payment_method when Buy preference is active
     * and the method is present in discovered payment_methods.
     *
     * @param array<string, mixed> $sessionData
     * @param array<string, mixed> $paymentMethods
     * @param int $storeId
     * @param string $requestNavigationId
     * @return bool
     */
    public static function applyPaymentIfAvailable(
        array &$sessionData,
        array $paymentMethods,
        $storeId,
        $requestNavigationId = ''
    ) {
        $preference = self::load($sessionData, (int) $storeId, $requestNavigationId);
        if ($preference === null || !self::shouldPreferPayment($preference)) {
            return false;
        }

        $code = MtUniCreditConstants::EXTENSION_CODE;
        if (!isset($paymentMethods[$code]) || !is_array($paymentMethods[$code])) {
            return false;
        }

        $sessionData['payment_method'] = $paymentMethods[$code];

        return true;
    }

    /**
     * Clear Buy preference when the customer saves a different payment method.
     *
     * @param array<string, mixed> $sessionData
     * @return void
     */
    public static function clearIfPaymentChangedAway(array &$sessionData)
    {
        if (!isset($sessionData[self::SESSION_KEY]) || !is_array($sessionData[self::SESSION_KEY])) {
            return;
        }

        $code = '';
        if (isset($sessionData['payment_method']['code'])) {
            $code = (string) $sessionData['payment_method']['code'];
        }
        if ($code === MtUniCreditConstants::EXTENSION_CODE) {
            return;
        }

        self::clear($sessionData);
    }

    /**
     * @param string $navigationId
     * @return bool
     */
    public static function isValidNavigationId($navigationId)
    {
        return (bool) preg_match('/^[a-f0-9]+$/i', trim((string) $navigationId));
    }

    /**
     * @return string
     */
    private static function navigationIdFromCookie()
    {
        if (!isset($_COOKIE[self::NAV_PARAM])) {
            return '';
        }
        $fromCookie = trim((string) $_COOKIE[self::NAV_PARAM]);
        if ($fromCookie === '' || !self::isValidNavigationId($fromCookie)) {
            return '';
        }

        return $fromCookie;
    }

    /**
     * @return bool
     */
    private static function cookieSecure()
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
            return true;
        }

        return false;
    }

    /**
     * @return string
     */
    private static function newNavigationId()
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (Exception $ignored) {
            return sha1(uniqid('mtuc-buy-', true));
        }
    }
}
