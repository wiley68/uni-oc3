<?php

/**
 * Verifies inbound HMAC headers against the exact raw request body.
 */
final class MtUniCreditRequestSignatureVerifier
{
    /** @var callable|null */
    private $clock;

    /**
     * @param callable|null $clock Callable returning unix timestamp
     */
    public function __construct($clock = null)
    {
        $this->clock = $clock;
    }

    /**
     * @param string $secret
     * @param string $rawBody
     * @param array<string, string> $headers
     * @return void
     */
    public function verify($secret, $rawBody, array $headers)
    {
        $timestamp = $this->requireHeader($headers, MtUniCreditRequestSignatureProtocol::HEADER_TIMESTAMP);
        $nonce = $this->requireHeader($headers, MtUniCreditRequestSignatureProtocol::HEADER_NONCE);
        $signature = strtolower($this->requireHeader($headers, MtUniCreditRequestSignatureProtocol::HEADER_SIGNATURE));

        $this->assertFreshTimestamp($timestamp);
        $this->assertNonceFormat($nonce);

        $expected = MtUniCreditRequestSignatureProtocol::computeSignature($secret, $timestamp, $nonce, $rawBody);
        if (!hash_equals($expected, $signature)) {
            throw new MtUniCreditPersistenceValidationException(
                MtUniCreditRequestSignatureProtocol::AUTH_FAILURE_MESSAGE
            );
        }
    }

    /**
     * @param array<string, string> $headers
     * @return string
     */
    public function extractNonce(array $headers)
    {
        return strtolower($this->requireHeader($headers, MtUniCreditRequestSignatureProtocol::HEADER_NONCE));
    }

    /**
     * @param array<string, string> $headers
     * @param string $name
     * @return string
     */
    private function requireHeader(array $headers, $name)
    {
        $value = $this->headerValue($headers, $name);
        if ($value === null || $value === '') {
            throw new MtUniCreditPersistenceValidationException(
                MtUniCreditRequestSignatureProtocol::AUTH_FAILURE_MESSAGE
            );
        }

        return $value;
    }

    /**
     * @param array<string, string> $headers
     * @param string $name
     * @return string|null
     */
    private function headerValue(array $headers, $name)
    {
        foreach ($headers as $headerName => $headerValue) {
            if (strcasecmp((string) $headerName, $name) === 0 && is_string($headerValue)) {
                return $headerValue;
            }
        }

        return null;
    }

    /**
     * @param string $timestamp
     * @return void
     */
    private function assertFreshTimestamp($timestamp)
    {
        if (!ctype_digit($timestamp)) {
            throw new MtUniCreditPersistenceValidationException(
                MtUniCreditRequestSignatureProtocol::AUTH_FAILURE_MESSAGE
            );
        }

        $requestTimestamp = (int) $timestamp;
        $now = $this->clock !== null ? (int) call_user_func($this->clock) : time();
        if (abs($now - $requestTimestamp) > MtUniCreditRequestSignatureProtocol::TIMESTAMP_TOLERANCE_SECONDS) {
            throw new MtUniCreditPersistenceValidationException(
                MtUniCreditRequestSignatureProtocol::AUTH_FAILURE_MESSAGE
            );
        }
    }

    /**
     * @param string $nonce
     * @return void
     */
    private function assertNonceFormat($nonce)
    {
        if (!preg_match(
            '/^[0-9a-fA-F]{' . MtUniCreditRequestSignatureProtocol::NONCE_HEX_LENGTH . '}$/',
            $nonce
        )) {
            throw new MtUniCreditPersistenceValidationException(
                MtUniCreditRequestSignatureProtocol::AUTH_FAILURE_MESSAGE
            );
        }
    }
}
