<?php

/**
 * Removes sensitive remote credential fields before shop cache JSON persistence.
 */
final class MtUniCreditShopSnapshotSanitizer
{
    /** @var array<int, string> */
    private static $stripKeys = array(
        'uni_password',
        'uni_user',
        'secret',
        'secret_key',
        'access_token',
        'refresh_token',
        'bearer_token',
        'private_key',
        'db_password',
    );

    /**
     * @param array<string, mixed> $shopData
     * @return array{sanitized: array<string, mixed>, smartucf_user: string|null, smartucf_password: string|null}
     */
    public static function partitionSensitiveFields(array $shopData)
    {
        $smartucfUser = null;
        $smartucfPassword = null;

        if (isset($shopData['uni_user']) && is_string($shopData['uni_user'])) {
            $trimmed = trim($shopData['uni_user']);
            if ($trimmed !== '') {
                $smartucfUser = $trimmed;
            }
        }

        if (isset($shopData['uni_password']) && is_string($shopData['uni_password'])) {
            $trimmed = trim($shopData['uni_password']);
            if ($trimmed !== '') {
                $smartucfPassword = $trimmed;
            }
        }

        $sanitized = self::sanitize($shopData);

        return array(
            'sanitized' => $sanitized,
            'smartucf_user' => $smartucfUser,
            'smartucf_password' => $smartucfPassword,
        );
    }

    /**
     * @param array<string, mixed> $shopData
     * @return array<string, mixed>
     */
    public static function sanitize(array $shopData)
    {
        $sanitized = $shopData;

        foreach (self::$stripKeys as $key) {
            if (array_key_exists($key, $sanitized)) {
                unset($sanitized[$key]);
            }
        }

        return $sanitized;
    }

    /**
     * @param string $encodedJson
     * @return bool
     */
    public static function encodedJsonContainsForbiddenPlaintext($encodedJson)
    {
        if (!is_string($encodedJson) || $encodedJson === '') {
            return false;
        }

        foreach (self::$stripKeys as $key) {
            if (stripos($encodedJson, '"' . $key . '"') !== false) {
                return true;
            }
        }

        return false;
    }
}
