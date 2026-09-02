<?php

/**
 * Frozen CP ↔ module inbound HMAC contract.
 *
 * @see docs/CONTRACTS.md SEC-HMAC-001
 */
final class MtUniCreditRequestSignatureProtocol
{
    const HEADER_TIMESTAMP = 'X-UniPayment-Timestamp';

    const HEADER_NONCE = 'X-UniPayment-Nonce';

    const HEADER_SIGNATURE = 'X-UniPayment-Signature';

    const TIMESTAMP_TOLERANCE_SECONDS = 300;

    const NONCE_HEX_LENGTH = 64;

    const AUTH_FAILURE_MESSAGE = 'Невалидна или изтекла заявка към модула.';

    /**
     * @param string $timestamp
     * @param string $nonce
     * @param string $rawBody
     * @return string
     */
    public static function buildCanonicalString($timestamp, $nonce, $rawBody)
    {
        return $timestamp . "\n" . $nonce . "\n" . $rawBody;
    }

    /**
     * @param string $secret
     * @param string $timestamp
     * @param string $nonce
     * @param string $rawBody
     * @return string Lowercase hex HMAC-SHA256
     */
    public static function computeSignature($secret, $timestamp, $nonce, $rawBody)
    {
        return hash_hmac(
            'sha256',
            self::buildCanonicalString($timestamp, $nonce, $rawBody),
            $secret
        );
    }
}
