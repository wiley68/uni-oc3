<?php

/**
 * OpenCart store scope contract.
 *
 * Store 0 is the default store; negative ids are invalid. No fallback from store N to store 0.
 */
final class MtUniCreditStoreScope
{
    /**
     * @param int $storeId
     * @return bool
     */
    public static function isValid($storeId)
    {
        return (int) $storeId >= 0;
    }

    /**
     * @param int $storeId
     * @return void
     */
    public static function requireStoreId($storeId)
    {
        if ((int) $storeId < 0) {
            throw new MtUniCreditPersistenceValidationException('Store scope is required.');
        }
    }
}
