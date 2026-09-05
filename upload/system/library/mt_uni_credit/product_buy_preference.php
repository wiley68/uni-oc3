<?php

/**
 * Transient Product Buy handoff preference (checkout payment + scheme UX).
 *
 * Not a financing attempt. Scoped to one Buy→Checkout navigation:
 * - pending until first Checkout use (payment/scheme resolve)
 * - active while session checkout guard matches navigation_id
 * - ignored/cleared for later unrelated Checkout (guard released on leave)
 *
 * AJAX re-renders of the same Checkout keep the guard and therefore the preference.
 */
final class MtUniCreditProductBuyPreference
{
    const SESSION_KEY = 'mt_uni_credit_product_buy_preference';

    /** Session guard binding preference.navigation_id to the active Buy Checkout visit. */
    const CHECKOUT_GUARD_KEY = 'mt_uni_credit_buy_checkout_guard';

    const FLOW = 'product_buy';

    const STATE_PENDING = 'pending';

    const STATE_ACTIVE = 'active';

    const TTL_SECONDS = 1800;

    /**
     * @param array<string, mixed> $sessionData
     * @param array<string, mixed> $fields
     * @return void
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
                $months,
                $filterId
            );
        }

        // New Buy replaces any previous navigation binding.
        unset($sessionData[self::CHECKOUT_GUARD_KEY]);

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
            'navigation_id' => self::newNavigationId(),
            'state' => self::STATE_PENDING,
            'created_at' => time(),
        );
    }

    /**
     * @param array<string, mixed> $sessionData
     * @param int|null $storeId When set, store mismatch clears preference
     * @return array<string, mixed>|null
     */
    public static function load(array &$sessionData, $storeId = null)
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

        if ($state === self::STATE_PENDING) {
            $raw['state'] = self::STATE_ACTIVE;
            $sessionData[self::SESSION_KEY] = $raw;
            $sessionData[self::CHECKOUT_GUARD_KEY] = $navigationId;

            return $raw;
        }

        // Active: only valid while the Buy Checkout guard still matches (same visit).
        $guard = isset($sessionData[self::CHECKOUT_GUARD_KEY])
            ? trim((string) $sessionData[self::CHECKOUT_GUARD_KEY])
            : '';
        if ($guard === '' || !hash_equals($navigationId, $guard)) {
            self::clear($sessionData);

            return null;
        }

        return $raw;
    }

    /**
     * Release the Checkout visit guard without requiring a full preference clear.
     * Next load() of an active preference will clear it (unrelated Checkout).
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
     * @param array<string, mixed> $sessionData
     * @return void
     */
    public static function clear(array &$sessionData)
    {
        unset($sessionData[self::SESSION_KEY], $sessionData[self::CHECKOUT_GUARD_KEY]);
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
     * @return bool
     */
    public static function applyPaymentIfAvailable(array &$sessionData, array $paymentMethods, $storeId)
    {
        $preference = self::load($sessionData, (int) $storeId);
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
