<?php

/**
 * Redacts sensitive diagnostic payload keys before persistence or export.
 *
 * SmartUCF PII keys follow Woo Mtuc_Debug_Log::SMARTUCF_PII_KEYS (exact case),
 * except sucfOnlineSessionID which remains visible for bank/support log correlation
 * (OC4 + CP parity — bank-side session reference, not a credential).
 * Additional credential/token keys keep the broader OC3/OC4 safety net.
 */
final class MtUniCreditDiagnosticPayloadRedactor
{
    /** @var string */
    const REDACTED_VALUE = '[REDACTED]';

    /** @var string */
    const UNPARSEABLE_REQUEST_MARKER = '[UNPARSEABLE_REQUEST_REDACTED]';

    /** @var string */
    const NON_JSON_RESPONSE_MARKER = '[NON_JSON_RESPONSE_REDACTED]';

    /**
     * Exact SmartUCF request PII keys (case-sensitive).
     * sucfOnlineSessionID is intentionally NOT listed — support must see it.
     *
     * @var array<int, string>
     */
    private static $smartUcfPiiKeys = array(
        'user',
        'pass',
        'clientFirstName',
        'clientLastName',
        'clientPhone',
        'clientEmail',
        'clientDeliveryAddress',
    );

    /**
     * Broader credential / PII keys (case-insensitive).
     *
     * @var array<int, string>
     */
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
            if (is_string($key) && self::isSensitiveKey($key)) {
                $redacted[$key] = self::REDACTED_VALUE;
                continue;
            }
            $redacted[$key] = self::redact($item);
        }

        return $redacted;
    }

    /**
     * Decode JSON strings then redact; preserve null; marker non-JSON bodies safely.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function redactMixed($value)
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }
            if ($trimmed[0] === '{' || $trimmed[0] === '[') {
                $decoded = json_decode($value, true);
                if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
                    return self::redact($decoded);
                }

                return array(
                    'message' => self::UNPARSEABLE_REQUEST_MARKER,
                    'byte_length' => strlen($value),
                );
            }

            // Non-JSON response body — never persist raw transport text.
            return array(
                'message' => self::NON_JSON_RESPONSE_MARKER,
                'byte_length' => strlen($value),
            );
        }

        if (is_array($value)) {
            return self::redact($value);
        }

        return $value;
    }

    /**
     * @param string $key
     * @return bool
     */
    private static function isSensitiveKey($key)
    {
        if (in_array($key, self::$smartUcfPiiKeys, true)) {
            return true;
        }

        $normalized = strtolower($key);
        foreach (self::$forbiddenKeys as $forbidden) {
            if ($normalized === $forbidden) {
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
            return self::REDACTED_VALUE;
        }

        $value = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [REDACTED]', $value);
        if (!is_string($value)) {
            return self::REDACTED_VALUE;
        }

        $redacted = preg_replace(
            '/\b(secret|token|password|pass|private[_ -]?key)\b\s*[:=]\s*[^\s,;]+/i',
            '$1=[REDACTED]',
            $value
        );

        return is_string($redacted) ? $redacted : self::REDACTED_VALUE;
    }
}
