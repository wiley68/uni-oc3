<?php

/**
 * Transient Product Buy handoff preference (checkout payment preselect UX).
 *
 * Not a financing attempt — only soft checkout preference after native cart.add.
 */
final class MtUniCreditProductBuyPreference
{
    const SESSION_KEY = 'mt_uni_credit_product_buy_preference';

    const FLOW = 'product_buy';

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

        if ($flow !== self::FLOW || $createdAt <= 0 || (time() - $createdAt) > self::TTL_SECONDS) {
            self::clear($sessionData);

            return null;
        }

        if ($storeId !== null && (int) $storeId !== $storedStoreId) {
            self::clear($sessionData);

            return null;
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $sessionData
     * @return void
     */
    public static function clear(array &$sessionData)
    {
        unset($sessionData[self::SESSION_KEY]);
    }
}
