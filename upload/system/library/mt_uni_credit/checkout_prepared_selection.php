<?php

/**
 * Exact Checkout financing selection bound to a prepared native order (AUD-021-F01).
 *
 * Server-side session authority for scheme_key + first_installment across
 * confirm → prepared → submit. Client/hidden posts are transport only and never
 * become authority when they disagree with the bound selection.
 */
final class MtUniCreditCheckoutPreparedSelection
{
    const SESSION_KEY = 'mt_uni_credit_checkout_prepared_selection';

    /**
     * Persist a validated selection after prepareCheckoutConfirm succeeds.
     *
     * @param array<string, mixed> $sessionData
     * @param int $storeId
     * @param int $orderId
     * @param int $preparedOrderId
     * @param string $schemeKey
     * @param float|int|string $firstInstallment
     * @return bool
     */
    public static function store(
        array &$sessionData,
        $storeId,
        $orderId,
        $preparedOrderId,
        $schemeKey,
        $firstInstallment
    ) {
        $storeId = (int) $storeId;
        $orderId = (int) $orderId;
        $preparedOrderId = (int) $preparedOrderId;
        $schemeKey = trim((string) $schemeKey);
        $firstInstallment = (float) $firstInstallment;

        if ($storeId < 0 || $orderId <= 0 || $preparedOrderId !== $orderId) {
            self::clear($sessionData);

            return false;
        }
        if ($schemeKey === '' || MtUniCreditStorefrontCalculatorPresenter::parseSchemeKey($schemeKey) === null) {
            self::clear($sessionData);

            return false;
        }

        $sessionData[self::SESSION_KEY] = array(
            'store_id' => $storeId,
            'order_id' => $orderId,
            'prepared_order_id' => $preparedOrderId,
            'scheme_key' => $schemeKey,
            'first_installment' => $firstInstallment,
        );

        return true;
    }

    /**
     * Load authoritative selection for the current prepared context.
     *
     * @param array<string, mixed> $sessionData
     * @param int $storeId
     * @param int $orderId
     * @param int $preparedOrderId
     * @return array{scheme_key:string,first_installment:float}|null
     */
    public static function load(array $sessionData, $storeId, $orderId, $preparedOrderId)
    {
        $row = self::readRow($sessionData);
        if ($row === null) {
            return null;
        }

        $storeId = (int) $storeId;
        $orderId = (int) $orderId;
        $preparedOrderId = (int) $preparedOrderId;
        if ($storeId < 0 || $orderId <= 0 || $preparedOrderId !== $orderId) {
            return null;
        }
        if ((int) $row['store_id'] !== $storeId) {
            return null;
        }
        if ((int) $row['order_id'] !== $orderId) {
            return null;
        }
        if ((int) $row['prepared_order_id'] !== $preparedOrderId) {
            return null;
        }

        return array(
            'scheme_key' => (string) $row['scheme_key'],
            'first_installment' => (float) $row['first_installment'],
        );
    }

    /**
     * Resolve submit selection: bound server state wins over posted client values.
     *
     * Posted scheme_key / first_installment are ignored when they differ; they
     * cannot become authority. Missing bound selection → null (submit BLOCK).
     *
     * @param array<string, mixed> $sessionData
     * @param int $storeId
     * @param int $orderId
     * @param int $preparedOrderId
     * @param array<string, mixed> $posted ignored for authority; accepted for API symmetry
     * @return array{scheme_key:string,first_installment:float}|null
     */
    public static function resolveForSubmit(
        array $sessionData,
        $storeId,
        $orderId,
        $preparedOrderId,
        array $posted = array()
    ) {
        // $posted intentionally unused for authority (AUD-021-F01).
        unset($posted);

        return self::load($sessionData, $storeId, $orderId, $preparedOrderId);
    }

    /**
     * @param array<string, mixed> $sessionData
     * @return void
     */
    public static function clear(array &$sessionData)
    {
        if (isset($sessionData[self::SESSION_KEY])) {
            unset($sessionData[self::SESSION_KEY]);
        }
    }

    /**
     * @param array<string, mixed> $sessionData
     * @return array{
     *   store_id:int,
     *   order_id:int,
     *   prepared_order_id:int,
     *   scheme_key:string,
     *   first_installment:float
     * }|null
     */
    private static function readRow(array $sessionData)
    {
        if (!isset($sessionData[self::SESSION_KEY]) || !is_array($sessionData[self::SESSION_KEY])) {
            return null;
        }
        $row = $sessionData[self::SESSION_KEY];
        if (
            !isset($row['store_id'], $row['order_id'], $row['prepared_order_id'], $row['scheme_key'])
            || !array_key_exists('first_installment', $row)
        ) {
            return null;
        }
        $schemeKey = trim((string) $row['scheme_key']);
        if ($schemeKey === '' || MtUniCreditStorefrontCalculatorPresenter::parseSchemeKey($schemeKey) === null) {
            return null;
        }

        return array(
            'store_id' => (int) $row['store_id'],
            'order_id' => (int) $row['order_id'],
            'prepared_order_id' => (int) $row['prepared_order_id'],
            'scheme_key' => $schemeKey,
            'first_installment' => (float) $row['first_installment'],
        );
    }
}
