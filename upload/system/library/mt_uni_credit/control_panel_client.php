<?php

/**
 * Control Panel HTTP client — login, refresh, logout, shop, orders, status (Phase 4/9).
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

    /** @var MtUniCreditDeploymentEnvironment */
    private $environment;

    /**
     * @param MtUniCreditCredentialsRepository $credentials
     * @param MtUniCreditCpTokenRepository $tokens
     * @param MtUniCreditCpHttpTransport $transport
     * @param string $shopName
     * @param int $storeId
     * @param string|null $baseUrl
     * @param callable|null $clock
     * @param string|null $environmentConfigPath
     */
    public function __construct(
        MtUniCreditCredentialsRepository $credentials,
        MtUniCreditCpTokenRepository $tokens,
        MtUniCreditCpHttpTransport $transport,
        $shopName,
        $storeId,
        $baseUrl = null,
        $clock = null,
        $environmentConfigPath = null
    ) {
        $this->credentials = $credentials;
        $this->tokens = $tokens;
        $this->transport = $transport;
        $this->shopName = rtrim(trim((string) $shopName), '/');
        $this->storeId = (int) $storeId;
        $this->environment = new MtUniCreditDeploymentEnvironment(
            $environmentConfigPath !== null && $environmentConfigPath !== ''
                ? (string) $environmentConfigPath
                : null
        );

        if ($baseUrl !== null && trim($baseUrl) !== '') {
            $resolved = trim($baseUrl);
        } else {
            $resolved = $this->environment->controlPanelApiBaseUrl();
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
     * @return array{
     *   available: bool,
     *   ssl_revision: string,
     *   certificate_sha256: string,
     *   private_key_sha256: string,
     *   not_before: string,
     *   not_after: string
     * }
     */
    public function getSslCertificateMetadata()
    {
        $response = $this->authenticatedRequest('GET', '/ssl/certificate');
        $data = isset($response['data']) ? $response['data'] : null;
        if (!is_array($data)) {
            throw new MtUniCreditCpInvalidPayloadException('The Control Panel SSL metadata response has no data object.');
        }

        return $this->normalizeSslMetadata($data);
    }

    /**
     * @return array{
     *   available: bool,
     *   ssl_revision: string,
     *   certificate_sha256: string,
     *   private_key_sha256: string,
     *   not_before: string,
     *   not_after: string,
     *   certificate_pem: string,
     *   private_key_pem: string
     * }
     */
    public function downloadSslCertificateBundle()
    {
        $response = $this->authenticatedRequest('GET', '/ssl/certificate/bundle');
        $data = isset($response['data']) ? $response['data'] : null;
        if (!is_array($data)) {
            throw new MtUniCreditCpInvalidPayloadException('The Control Panel SSL bundle response has no data object.');
        }
        foreach (array('certificate_pem', 'private_key_pem', 'certificate_sha256', 'private_key_sha256') as $field) {
            if (!isset($data[$field]) || !is_string($data[$field]) || trim((string) $data[$field]) === '') {
                throw new MtUniCreditCpInvalidPayloadException('The Control Panel SSL bundle is missing required fields.');
            }
        }
        $metadata = $this->normalizeSslMetadata(array_merge($data, array('available' => true)));

        return array_merge($metadata, array(
            'certificate_pem' => (string) $data['certificate_pem'],
            'private_key_pem' => (string) $data['private_key_pem'],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function createOrder(array $order)
    {
        $extraHeaders = array();
        if ($this->environment->forceTestCpCreate422()) {
            $extraHeaders[MtUniCreditDeploymentEnvironment::TEST_FAILURE_HEADER]
                = MtUniCreditDeploymentEnvironment::TEST_FAILURE_CP_CREATE_422;
        }

        $response = $this->authenticatedRequest('POST', '/orders', $order, $extraHeaders);
        if (!isset($response['data']) || !is_array($response['data'])) {
            throw new MtUniCreditCpInvalidPayloadException('The Control Panel order response has no valid data object.');
        }

        return $response;
    }

    /**
     * PATCH /orders/status after a definitive bank lifecycle transition.
     *
     * @param string $shopOrderId Shop order identifier — same value as POST /orders `order_id`
     *                            (local OpenCart order id), not the Control Panel internal PK.
     * @param string $statusLabel Human-readable CP status label
     * @param string $statusId Machine status id (e.g. bank_sent_process1)
     * @return void
     */
    public function updateOrderStatus($shopOrderId, $statusLabel, $statusId)
    {
        $shopOrderId = trim((string) $shopOrderId);
        $statusLabel = trim((string) $statusLabel);
        $statusId = trim((string) $statusId);
        if ($shopOrderId === '' || $statusId === '') {
            throw new MtUniCreditCpInvalidPayloadException('Control Panel order status fields are incomplete.');
        }
        $this->authenticatedRequest('PATCH', '/orders/status', array(
            'order_id' => $shopOrderId,
            'status' => $statusLabel,
            'status_id' => $statusId,
        ));
    }

    /**
     * @param string $method
     * @param string $path
     * @param array<string, mixed>|null $payload
     * @param array<string, string> $extraHeaders
     * @return array<string, mixed>
     */
    private function authenticatedRequest($method, $path, $payload = null, array $extraHeaders = array())
    {
        $token = $this->ensureToken();

        try {
            return $this->send($method, $path, $payload, $token, $extraHeaders);
        } catch (MtUniCreditCpAuthenticationException $exception) {
            $this->tokens->invalidate();
            $this->login();
            $retryToken = $this->tokens->getAccessToken();
            if ($retryToken === null) {
                throw new MtUniCreditCpAuthenticationException('Control Panel re-authentication did not provide a token.');
            }

            try {
                return $this->send($method, $path, $payload, $retryToken, $extraHeaders);
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
     * @param array<string, string> $extraHeaders
     * @return array<string, mixed>
     */
    private function send($method, $path, $payload = null, $token = null, array $extraHeaders = array())
    {
        $headers = array(
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        );
        if ($token !== null) {
            $headers['Authorization'] = $this->tokens->getTokenType() . ' ' . $token;
        }
        foreach ($extraHeaders as $name => $value) {
            $headers[(string) $name] = (string) $value;
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
     * @param array<string, mixed> $data
     * @return array{
     *   available: bool,
     *   ssl_revision: string,
     *   certificate_sha256: string,
     *   private_key_sha256: string,
     *   not_before: string,
     *   not_after: string
     * }
     */
    private function normalizeSslMetadata(array $data)
    {
        $available = !empty($data['available']);
        $certificateHash = strtolower(trim((string) (isset($data['certificate_sha256']) ? $data['certificate_sha256'] : '')));
        $privateKeyHash = strtolower(trim((string) (isset($data['private_key_sha256']) ? $data['private_key_sha256'] : '')));
        if (
            $available
            && (
                !preg_match('/^[a-f0-9]{64}$/', $certificateHash)
                || !preg_match('/^[a-f0-9]{64}$/', $privateKeyHash)
            )
        ) {
            throw new MtUniCreditCpInvalidPayloadException('The Control Panel SSL metadata hashes are invalid.');
        }

        return array(
            'available' => $available,
            'ssl_revision' => (string) (isset($data['ssl_revision']) ? $data['ssl_revision'] : ''),
            'certificate_sha256' => $certificateHash,
            'private_key_sha256' => $privateKeyHash,
            'not_before' => isset($data['not_before']) ? (string) $data['not_before'] : '',
            'not_after' => isset($data['not_after']) ? (string) $data['not_after'] : '',
        );
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
