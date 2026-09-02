<?php

/**
 * Redacts sensitive diagnostic payload keys before persistence or export.
 */
final class MtUniCreditDiagnosticPayloadRedactor
{
    /** @var array<int, string> */
    private static $forbiddenKeys = array(
        'egn',
        'clientegn',
        'email',
        'clientemail',
        'telephone',
        'phone',
        'phone_number',
        'clientphone',
        'phone2',
        'address',
        'address1',
        'address2',
        'address_1',
        'address_2',
        'clientdeliveryaddress',
        'clientfirstname',
        'clientlastname',
        'authorization',
        'access_token',
        'refresh_token',
        'secret',
        'secret_key',
        'cp_secret',
        'encryption_key',
        'password',
        'pass',
        'passphrase',
        'private_key',
        'private_key_pem',
        'certificate',
        'certificate_pem',
        'certificate_password',
        'bearer',
        'token',
        'user',
        'uni_password',
        'uni_user',
    );

    /**
     * @param mixed $value
     * @return mixed
     */
    public static function redact($value)
    {
        if (!is_array($value)) {
            return is_string($value) ? self::redactString($value) : $value;
        }

        $redacted = array();
        foreach ($value as $key => $item) {
            if (is_string($key) && self::isForbiddenKey($key)) {
                $redacted[$key] = '[REDACTED]';
                continue;
            }
            $redacted[$key] = self::redact($item);
        }

        return $redacted;
    }

    /**
     * @param string $key
     * @return bool
     */
    private static function isForbiddenKey($key)
    {
        $normalized = strtolower($key);
        foreach (self::$forbiddenKeys as $forbidden) {
            if ($normalized === $forbidden || strpos($normalized, $forbidden) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $value
     * @return string
     */
    private static function redactString($value)
    {
        if (preg_match('/^\d{10}$/', $value)) {
            return '[REDACTED]';
        }

        $value = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [REDACTED]', $value);
        if (!is_string($value)) {
            return '[REDACTED]';
        }

        $redacted = preg_replace(
            '/\b(secret|token|password|pass|private[_ -]?key)\b\s*[:=]\s*[^\s,;]+/i',
            '$1=[REDACTED]',
            $value
        );

        return is_string($redacted) ? $redacted : '[REDACTED]';
    }
}
