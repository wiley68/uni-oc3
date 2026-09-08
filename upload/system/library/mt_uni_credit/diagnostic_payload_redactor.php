<?php

/**
 * Redacts sensitive diagnostic payload keys before persistence or export.
 *
 * SmartUCF PII keys follow Woo Mtuc_Debug_Log::SMARTUCF_PII_KEYS (exact case),
 * except sucfOnlineSessionID which remains visible for bank/support log correlation
 * (OC4 + CP parity — bank-side session reference, not a credential).
 * Additional credential/token keys keep the broader OC3/OC4 safety net.
 *
 * AUD-028: recursively redacts valid nested JSON strings (bounded depth) and
 * normalizes camelCase / kebab-case / snake_case sensitive key aliases.
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
     * Max nested JSON-string decode depth (outer document = 0).
     *
     * @var int
     */
    const MAX_NESTED_JSON_DEPTH = 4;

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
     * Broader credential / PII keys (canonical snake_case, matched after normalizeKey).
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
        'api_token',
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
        'credential',
        'credentials',
        'user',
        'uni_password',
        'uni_user',
    );

    /**
     * @param mixed $value
     * @param int $depth nested JSON-string decode depth
     * @return mixed
     */
    public static function redact($value, $depth = 0)
    {
        $depth = (int) $depth;
        if (!is_array($value)) {
            if (is_string($value)) {
                return self::redactStringMaybeJson($value, $depth);
            }

            return $value;
        }

        $redacted = array();
        foreach ($value as $key => $item) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $redacted[$key] = self::REDACTED_VALUE;
                continue;
            }
            $redacted[$key] = self::redact($item, $depth);
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
                    return self::redact($decoded, 0);
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
            return self::redact($value, 0);
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

        $normalized = self::normalizeKey($key);
        foreach (self::$forbiddenKeys as $forbidden) {
            if ($normalized === $forbidden) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize camelCase / kebab-case / snake_case / CASE variants to snake_case tokens.
     *
     * Does NOT use broad substring matching (e.g. "session", "id", "key").
     *
     * @param string $key
     * @return string
     */
    private static function normalizeKey($key)
    {
        $key = (string) $key;
        $withSeparators = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key);
        if (!is_string($withSeparators)) {
            $withSeparators = $key;
        }
        $lower = strtolower(str_replace(array('-', ' '), '_', $withSeparators));
        $collapsed = preg_replace('/_+/', '_', $lower);

        return is_string($collapsed) ? trim($collapsed, '_') : strtolower($key);
    }

    /**
     * @param string $value
     * @param int $depth
     * @return mixed
     */
    private static function redactStringMaybeJson($value, $depth)
    {
        $trimmed = trim($value);
        if (
            $depth < self::MAX_NESTED_JSON_DEPTH
            && $trimmed !== ''
            && ($trimmed[0] === '{' || $trimmed[0] === '[')
        ) {
            $decoded = json_decode($value, true);
            if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
                $redacted = self::redact($decoded, $depth + 1);
                $encoded = json_encode($redacted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                return is_string($encoded) ? $encoded : self::REDACTED_VALUE;
            }
        }

        return self::redactString($value);
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
            '/\b(secret|token|password|pass|private[_ -]?key|api[_ -]?token|access[_ -]?token)\b\s*[:=]\s*[^\s,;]+/i',
            '$1=[REDACTED]',
            $value
        );

        return is_string($redacted) ? $redacted : self::REDACTED_VALUE;
    }
}
