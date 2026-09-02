<?php

/**
 * Authenticates CP → module inbound requests (HMAC + nonce claim).
 *
 * Invalid signature must not consume the nonce.
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
     * @param array<string, mixed> $payload
     * @param string $rawBody
     * @param array<string, string> $headers
     * @return string
     */
    public function authenticate(array $payload, $rawBody, array $headers)
    {
        if (!$this->moduleEnabled) {
            throw new MtUniCreditInboundApiException('Модулът е изключен.', 403, 'module_disabled');
        }

        $storedUnicid = $this->credentials->getUnicid($this->storeId);
        $storedSecret = $this->credentials->getSecret($this->storeId);
        if ($storedUnicid === '' || $storedSecret === null) {
            throw new MtUniCreditInboundApiException('Модулът не е конфигуриран.', 401, 'unknown_store');
        }

        $unicid = isset($payload['unicid']) ? $payload['unicid'] : null;
        if (!is_string($unicid) || trim($unicid) === '') {
            throw $this->authFailure();
        }

        if (!hash_equals($storedUnicid, trim($unicid))) {
            throw $this->authFailure();
        }

        try {
            $this->verifier->verify($storedSecret, $rawBody, $headers);
        } catch (MtUniCreditPersistenceValidationException $exception) {
            throw $this->authFailure();
        }

        $nonce = strtolower($this->verifier->extractNonce($headers));
        if (!$this->nonces->claim($this->storeId, $storedUnicid, $nonce)) {
            throw $this->authFailure();
        }

        return $storedUnicid;
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
