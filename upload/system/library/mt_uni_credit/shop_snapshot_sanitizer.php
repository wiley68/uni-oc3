<?php

/**
 * Removes sensitive remote credential fields before shop cache JSON persistence.
 *
 * Narrow shop-cache sanitizer: retain safe unknown fields, strip unknown secret-like keys.
 * Known Process 1 credentials (uni_user / uni_password) are retained for schema/partition —
 * partitionSensitiveFields extracts them and removes them from the cached snapshot.
 * Nested objects and list elements that are arrays are sanitized recursively.
 * Key matching normalizes camelCase / PascalCase / kebab-case / snake_case.
 */
final class MtUniCreditShopSnapshotSanitizer
{
    /** @var array<int, string> */
    private static $knownSensitiveSchemaKeys = array(
        'uni_user',
        'uni_password',
    );

    /**
     * Normalized token patterns that indicate secrets.
     * Short tokens require exact/prefix/suffix match to avoid stripping harmless fields
     * (e.g. "temp_email" must not match "pem").
     *
     * @var array<int, string>
     */
    private static $secretTokensStrict = array(
        'pem',
        'cert',
        'pass',
        'token',
        'secret',
        'bearer',
    );

    /** @var array<int, string> */
    private static $secretTokensContains = array(
        'apikey',
        'accesstoken',
        'refreshtoken',
        'privatekey',
        'privatekeypem',
        'clientsecret',
        'beartoken',
        'bearertoken',
        'authorization',
        'password',
        'passwd',
        'passphrase',
        'certificate',
    );

    /**
     * Partition SmartUCF credentials from the shop snapshot.
     *
     * Pair states:
     * - absent: neither uni_user nor uni_password keys present → preserve stored pair
     * - complete: both keys present as non-empty strings → replace stored pair
     * - invalid: any other presence/type/empty combination → reject (no mutation)
     *
     * @param array<string, mixed> $shopData
     * @return array{
     *   sanitized: array<string, mixed>,
     *   pair_state: string,
     *   smartucf_user: string|null,
     *   smartucf_password: string|null
     * }
     */
    public static function partitionSensitiveFields(array $shopData)
    {
        $userPresent = array_key_exists('uni_user', $shopData);
        $passwordPresent = array_key_exists('uni_password', $shopData);
        $pairState = 'absent';
        $smartucfUser = null;
        $smartucfPassword = null;

        if ($userPresent || $passwordPresent) {
            $userOk = $userPresent
                && is_string($shopData['uni_user'])
                && trim($shopData['uni_user']) !== '';
            $passwordOk = $passwordPresent
                && is_string($shopData['uni_password'])
                && trim($shopData['uni_password']) !== '';
            if ($userPresent && $passwordPresent && $userOk && $passwordOk) {
                $pairState = 'complete';
                $smartucfUser = trim($shopData['uni_user']);
                $smartucfPassword = trim($shopData['uni_password']);
            } else {
                $pairState = 'invalid';
            }
        }

        $sanitized = self::sanitize($shopData);
        unset($sanitized['uni_user'], $sanitized['uni_password']);

        return array(
            'sanitized' => $sanitized,
            'pair_state' => $pairState,
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
        return self::sanitizeNode($shopData);
    }

    /**
     * @param array<mixed> $node
     * @return array<mixed>
     */
    private static function sanitizeNode(array $node)
    {
        $out = array();
        $isList = self::isList($node);

        foreach ($node as $key => $value) {
            if (!$isList) {
                if (!is_string($key)) {
                    continue;
                }
                if (self::shouldStripUnknownSensitive($key)) {
                    continue;
                }
            }

            if (is_array($value)) {
                $value = self::sanitizeNode($value);
            }

            if ($isList) {
                $out[] = $value;
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param string $key
     * @return bool
     */
    private static function shouldStripUnknownSensitive($key)
    {
        $key = (string) $key;
        if (in_array($key, self::$knownSensitiveSchemaKeys, true)) {
            return false;
        }

        $lowerExact = strtolower($key);
        if (in_array($lowerExact, self::$knownSensitiveSchemaKeys, true)) {
            return false;
        }

        $normalized = self::normalizeKey($key);
        if ($normalized === 'uniuser' || $normalized === 'unipassword') {
            return false;
        }

        foreach (self::$secretTokensContains as $token) {
            if ($normalized === $token || strpos($normalized, $token) !== false) {
                return true;
            }
        }

        foreach (self::$secretTokensStrict as $token) {
            $len = strlen($token);
            if (
                $normalized === $token
                || strpos($normalized, $token) === 0
                || ($len > 0 && substr($normalized, -$len) === $token)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $key
     * @return string
     */
    private static function normalizeKey($key)
    {
        $spaced = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', (string) $key);
        if (!is_string($spaced)) {
            $spaced = (string) $key;
        }
        $spaced = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $spaced);
        if (!is_string($spaced)) {
            $spaced = (string) $key;
        }

        return strtolower(str_replace(array('_', '-', ' '), '', $spaced));
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

        $probeKeys = array(
            'uni_password',
            'access_token',
            'refresh_token',
            'bearer_token',
            'private_key',
            'client_secret',
            'api_key',
            'authorization',
            'password',
            'secret',
            'token',
        );

        foreach ($probeKeys as $key) {
            if (stripos($encodedJson, '"' . $key . '"') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * PHP 7.3 polyfill for array_is_list().
     *
     * @param array<mixed> $array
     * @return bool
     */
    private static function isList(array $array)
    {
        if ($array === array()) {
            return true;
        }

        $expected = 0;
        foreach ($array as $key => $_value) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }

        return true;
    }
}
