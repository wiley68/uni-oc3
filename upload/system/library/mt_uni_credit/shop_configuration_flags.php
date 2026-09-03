<?php

/**
 * Shop configuration boolean flags from the CP snapshot.
 */
final class MtUniCreditShopConfigurationFlags
{
    /**
     * Secondary checkout process when uni_proces === 1
     * (inverted relative to the human process number).
     *
     * @param array<string, mixed> $shop
     * @return bool
     */
    public static function isSecondaryProcess(array $shop)
    {
        return ((int) (isset($shop['uni_proces']) ? $shop['uni_proces'] : 0)) === 1;
    }

    /**
     * @param mixed $value
     * @return bool
     */
    public static function isYesFlag($value)
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), array('1', 'yes', 'on', 'true'), true);
    }

    /**
     * @param array<string, mixed> $shop
     * @return bool
     */
    public static function usesSmartUcfCertificate(array $shop)
    {
        return self::isYesFlag(isset($shop['uni_sertificat']) ? $shop['uni_sertificat'] : 0);
    }

    /**
     * @param array<string, mixed> $shop
     * @return bool
     */
    public static function isTestEnvironment(array $shop)
    {
        return ((int) (isset($shop['uni_env']) ? $shop['uni_env'] : 1)) === 0;
    }
}
