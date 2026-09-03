<?php

/**
 * Normalized Process 1 / Process 2 selection from the CP shop snapshot.
 *
 * Frozen OC4 mapping (do not invert):
 *   uni_proces === 1 → Process 2 (secondary)
 *   otherwise        → Process 1
 */
final class MtUniCreditShopProcessContext
{
    const PROCESS_1 = 1;

    const PROCESS_2 = 2;

    /**
     * @param array<string, mixed> $shop
     * @return mixed
     */
    public static function rawUniProces(array $shop)
    {
        return array_key_exists('uni_proces', $shop) ? $shop['uni_proces'] : null;
    }

    /**
     * @param array<string, mixed> $shop
     * @return int
     */
    public static function normalized(array $shop)
    {
        return MtUniCreditShopConfigurationFlags::isSecondaryProcess($shop)
            ? self::PROCESS_2
            : self::PROCESS_1;
    }

    /**
     * Restore SmartUCF user/password stripped from the shop cache (Phase 6 sanitizer).
     *
     * @param array<string, mixed> $shop
     * @param int $storeId
     * @param MtUniCreditSmartucfCredentialsRepository|null $credentials
     * @return array<string, mixed>
     */
    public static function hydrateSmartUcfCredentials(array $shop, $storeId, $credentials)
    {
        if (!$credentials instanceof MtUniCreditSmartucfCredentialsRepository) {
            return $shop;
        }
        try {
            $user = $credentials->getUser((int) $storeId);
            $password = $credentials->getPassword((int) $storeId);
        } catch (Exception $exception) {
            return $shop;
        }
        if (is_string($user) && $user !== '') {
            $shop['uni_user'] = $user;
        }
        if (is_string($password) && $password !== '') {
            $shop['uni_password'] = $password;
        }

        return $shop;
    }
}
