<?php

/**
 * Authenticates CP → module inbound requests (HMAC + nonce claim).
 *
 * Invalid signature must not consume the nonce.
 * HMAC is verified over exact raw body bytes before JSON is decoded.
 */
final class MtUniCreditRequestAuthenticator
{
    /** @var MtUniCreditCredentialsRepository */
    private $credentials;

    /** @var MtUniCreditApiNonceRepository */
    private $nonces;

    /** @var MtUniCreditRequestSignatureVerifier */
    private $verifier;

    /** @var int */
    private $storeId;

    /** @var bool */
    private $moduleEnabled;

    /**
     * @param MtUniCreditCredentialsRepository $credentials
     * @param MtUniCreditApiNonceRepository $nonces
     * @param int $storeId
     * @param bool $moduleEnabled
     * @param MtUniCreditRequestSignatureVerifier|null $verifier
     */
    public function __construct(
        MtUniCreditCredentialsRepository $credentials,
        MtUniCreditApiNonceRepository $nonces,
        $storeId,
        $moduleEnabled,
        $verifier = null
    ) {
        $this->credentials = $credentials;
        $this->nonces = $nonces;
        $this->storeId = (int) $storeId;
        $this->moduleEnabled = (bool) $moduleEnabled;
        $this->verifier = $verifier instanceof MtUniCreditRequestSignatureVerifier
            ? $verifier
            : new MtUniCreditRequestSignatureVerifier();
    }

    /**
     * Verify HMAC over untouched raw body. Does not decode JSON and does not claim nonce.
     *
     * Store/secret scope comes from constructor store context, not from JSON fields.
     *
     * @param string $rawBody
     * @param array<string, string> $headers
     * @return string Stored UNICID for this store
     */
    public function authenticate($rawBody, array $headers)
    {
        if (!$this->moduleEnabled) {
            throw new MtUniCreditInboundApiException('Модулът е изключен.', 403, 'module_disabled');
        }

        $storedUnicid = $this->credentials->getUnicid($this->storeId);
        $storedSecret = $this->credentials->getSecret($this->storeId);
        if ($storedUnicid === '' || $storedSecret === null) {
            throw new MtUniCreditInboundApiException('Модулът не е конфигуриран.', 401, 'unknown_store');
        }

        try {
            $this->verifier->verify($storedSecret, $rawBody, $headers);
        } catch (MtUniCreditPersistenceValidationException $exception) {
            throw $this->authFailure();
        }

        return $storedUnicid;
    }

    /**
     * After successful authentication and JSON decode: bind payload UNICID and claim nonce.
     *
     * @param array<string, mixed> $payload
     * @param string $authenticatedUnicid
     * @param array<string, string> $headers
     * @return string
     */
    public function finalizeAuthenticatedRequest(array $payload, $authenticatedUnicid, array $headers)
    {
        $unicid = isset($payload['unicid']) ? $payload['unicid'] : null;
        if (!is_string($unicid) || trim($unicid) === '') {
            throw $this->authFailure();
        }

        if (!hash_equals((string) $authenticatedUnicid, trim($unicid))) {
            throw $this->authFailure();
        }

        $nonce = strtolower($this->verifier->extractNonce($headers));
        if (!$this->nonces->claim($this->storeId, (string) $authenticatedUnicid, $nonce)) {
            throw $this->authFailure();
        }

        return (string) $authenticatedUnicid;
    }

    /**
     * @return MtUniCreditInboundApiException
     */
    private function authFailure()
    {
        return new MtUniCreditInboundApiException(
            MtUniCreditRequestSignatureProtocol::AUTH_FAILURE_MESSAGE,
            401,
            'invalid_signature'
        );
    }
}
