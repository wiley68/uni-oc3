<?php

/**
 * Control Panel HTTP client — login, refresh, logout, GET /shop (Phase 4).
 */
final class MtUniCreditControlPanelClient
{
    /** @var MtUniCreditCredentialsRepository */
    private $credentials;

    /** @var MtUniCreditCpTokenRepository */
    private $tokens;

    /** @var MtUniCreditCpHttpTransport */
    private $transport;

    /** @var string */
    private $shopName;

    /** @var string */
    private $baseUrl;

    /** @var int */
    private $storeId;

    /** @var callable */
    private $clock;

    /**
     * @param MtUniCreditCredentialsRepository $credentials
     * @param MtUniCreditCpTokenRepository $tokens
     * @param MtUniCreditCpHttpTransport $transport
     * @param string $shopName
     * @param int $storeId
     * @param string|null $baseUrl
     * @param callable|null $clock
     */
    public function __construct(
        MtUniCreditCredentialsRepository $credentials,
        MtUniCreditCpTokenRepository $tokens,
        MtUniCreditCpHttpTransport $transport,
        $shopName,
        $storeId,
        $baseUrl = null,
        $clock = null
    ) {
        $this->credentials = $credentials;
        $this->tokens = $tokens;
        $this->transport = $transport;
        $this->shopName = rtrim(trim((string) $shopName), '/');
        $this->storeId = (int) $storeId;

        if ($baseUrl !== null && trim($baseUrl) !== '') {
            $resolved = trim($baseUrl);
        } else {
            $resolved = (new MtUniCreditDeploymentEnvironment())->controlPanelApiBaseUrl();
        }

        $this->baseUrl = rtrim($resolved, '/');
        $this->clock = is_callable($clock) ? $clock : function () {
            return time();
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function login()
    {
        $unicid = $this->credentials->getUnicid($this->storeId);
        $secret = $this->credentials->getSecret($this->storeId);
        if ($unicid === '' || $secret === null || $this->shopName === '') {
            $this->tokens->invalidate();
            throw new MtUniCreditCpAuthenticationException('The Control Panel credentials are incomplete.');
        }

        $response = $this->send('POST', '/auth/login', array(
            'unicid' => $unicid,
            'name' => $this->shopName,
            'secret' => $secret,
        ));
        $this->storeTokenResponse($response);

        if (!isset($response['shop']) || !is_array($response['shop'])) {
            $this->tokens->invalidate();
            throw new MtUniCreditCpInvalidPayloadException('The Control Panel login response has no valid shop data.');
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshToken()
    {
        $token = $this->tokens->getAccessToken();
        if ($token === null) {
            throw new MtUniCreditCpAuthenticationException('There is no Control Panel token to refresh.');
        }

        try {
            $response = $this->send('POST', '/auth/refresh', null, $token);
            $this->storeTokenResponse($response);

            return $response;
        } catch (MtUniCreditCpAuthenticationException $exception) {
            $this->tokens->invalidate();
            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function logout()
    {
        $token = $this->tokens->getAccessToken();
        if ($token === null) {
            return array('success' => true);
        }

        try {
            return $this->send('POST', '/auth/logout', null, $token);
        } finally {
            $this->tokens->invalidate();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getShop()
    {
        $response = $this->authenticatedRequest('GET', '/shop');
        if (!isset($response['data']) || !is_array($response['data'])) {
            throw new MtUniCreditCpInvalidPayloadException('The Control Panel shop response has no valid data object.');
        }

        return $response;
    }

    /**
     * @param string $method
     * @param string $path
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function authenticatedRequest($method, $path, $payload = null)
    {
        $token = $this->ensureToken();

        try {
            return $this->send($method, $path, $payload, $token);
        } catch (MtUniCreditCpAuthenticationException $exception) {
            $this->tokens->invalidate();
            $this->login();
            $retryToken = $this->tokens->getAccessToken();
            if ($retryToken === null) {
                throw new MtUniCreditCpAuthenticationException('Control Panel re-authentication did not provide a token.');
            }

            try {
                return $this->send($method, $path, $payload, $retryToken);
            } catch (MtUniCreditCpAuthenticationException $retryException) {
                $this->tokens->invalidate();
                throw $retryException;
            }
        }
    }

    /**
     * @return string
     */
    private function ensureToken()
    {
        $token = $this->tokens->getAccessToken();
        $now = $this->now();
        $expiresAt = $this->tokens->getExpiresAt();

        if ($token === null || $expiresAt <= $now) {
            $this->tokens->invalidate();
            $this->login();

            return (string) $this->tokens->getAccessToken();
        }

        if ($expiresAt <= $now + MtUniCreditCpHttpConstants::REFRESH_MARGIN_SECONDS) {
            try {
                $this->refreshToken();
            } catch (MtUniCreditCpAuthenticationException $exception) {
                $this->login();
            }

            return (string) $this->tokens->getAccessToken();
        }

        return $token;
    }

    /**
     * @param string $method
     * @param string $path
     * @param array<string, mixed>|null $payload
     * @param string|null $token
     * @return array<string, mixed>
     */
    private function send($method, $path, $payload = null, $token = null)
    {
        $headers = array(
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        );
        if ($token !== null) {
            $headers['Authorization'] = $this->tokens->getTokenType() . ' ' . $token;
        }

        $response = $this->transport->request(
            $method,
            $this->baseUrl . '/' . ltrim($path, '/'),
            $headers,
            $payload
        );

        if ($response->getStatusCode() === 401) {
            throw new MtUniCreditCpAuthenticationException('The Control Panel rejected the authentication.');
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new MtUniCreditCpHttpException(
                $response->getStatusCode(),
                $this->decodeErrorResponse($response->getBody())
            );
        }

        $decoded = $this->decode($response->getBody());

        if (!isset($decoded['success']) || $decoded['success'] !== true) {
            throw new MtUniCreditCpInvalidPayloadException('The Control Panel response does not confirm success.');
        }

        return $decoded;
    }

    /**
     * @param string $body
     * @return array<string, mixed>
     */
    private function decode($body)
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MtUniCreditCpMalformedJsonException('The Control Panel returned malformed JSON.', 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new MtUniCreditCpMalformedJsonException('The Control Panel JSON response is not an object.');
        }

        return $decoded;
    }

    /**
     * @param string $body
     * @return array<string, mixed>
     */
    private function decodeErrorResponse($body)
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return array();
        }

        return is_array($decoded) ? $decoded : array();
    }

    /**
     * @param array<string, mixed> $response
     * @return void
     */
    private function storeTokenResponse(array $response)
    {
        $accessToken = isset($response['access_token']) ? $response['access_token'] : null;
        $tokenType = isset($response['token_type']) ? $response['token_type'] : null;
        $expiresIn = isset($response['expires_in']) ? $response['expires_in'] : null;

        if (
            !is_string($accessToken) || $accessToken === ''
            || !is_string($tokenType) || strcasecmp($tokenType, 'Bearer') !== 0
            || !is_numeric($expiresIn) || (int) $expiresIn <= 0
        ) {
            $this->tokens->invalidate();
            throw new MtUniCreditCpInvalidPayloadException('The Control Panel token response is invalid.');
        }

        if (!$this->tokens->save($accessToken, $tokenType, $this->now() + (int) $expiresIn)) {
            $this->tokens->invalidate();
            throw new MtUniCreditCpInvalidPayloadException('The Control Panel token could not be stored.');
        }
    }

    /**
     * @return int
     */
    private function now()
    {
        return (int) call_user_func($this->clock);
    }
}
