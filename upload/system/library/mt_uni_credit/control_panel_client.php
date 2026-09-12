<?php

/**
 * Control Panel HTTP client — login, refresh, logout, shop, orders (canonical envelopes).
 */
final class MtUniCreditControlPanelClient implements MtUniCreditControlPanelOrderStatusPort
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
     * @param MtUniCreditCpDestinationPolicy|null $destinationPolicy
     */
    public function __construct(
        MtUniCreditCredentialsRepository $credentials,
        MtUniCreditCpTokenRepository $tokens,
        MtUniCreditCpHttpTransport $transport,
        $shopName,
        $storeId,
        $baseUrl = null,
        $clock = null,
        $destinationPolicy = null
    ) {
        $this->credentials = $credentials;
        $this->tokens = $tokens;
        $this->transport = $transport;
        // Shop identity is the validated CP Shop.name (provider already applied historical rtrim).
        $this->shopName = trim((string) $shopName);
        $this->storeId = (int) $storeId;

        $policy = $destinationPolicy instanceof MtUniCreditCpDestinationPolicy
            ? $destinationPolicy
            : new MtUniCreditCpDestinationPolicy();

        if ($baseUrl !== null && trim($baseUrl) !== '') {
            $resolved = trim($baseUrl);
        } else {
            $resolved = (new MtUniCreditDeploymentEnvironment(null, $policy))->controlPanelApiBaseUrl();
        }

        // Defense-in-depth: never accept an unsafe override that bypasses DeploymentEnvironment.
        $this->baseUrl = $policy->assertTrustedApiBase($resolved);
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
        $this->storeTokenResponse($response, true);

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
            $this->storeTokenResponse($response, false);

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
            return MtUniCreditInboundApiEnvelope::success('Logged out locally.');
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
        $data = isset($response['data']) ? $response['data'] : null;
        if (!is_array($data) || !$this->isAssociativeObject($data)) {
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
     * POST /orders — financing order create (idempotent by shop_id + order_id).
     * One send only: no 401 re-login replay after a remote response.
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function createOrder(array $order)
    {
        $response = $this->authenticatedRequest('POST', '/orders', $order);
        $this->assertCreateOrderIdentity($response, $order);

        return $response;
    }

    /**
     * PATCH /orders/status after a definitive bank lifecycle transition.
     *
     * @param string $shopOrderId Shop order identifier — same value as POST /orders `order_id`
     *                            (local OpenCart order id), not the Control Panel internal PK.
     * @param string $statusLabel Human-readable CP status label
     * @param string $statusId Machine status id (e.g. bank_sent_process1)
     * @return array<string, mixed>
     */
    public function updateOrderStatus($shopOrderId, $statusLabel, $statusId)
    {
        $canonicalOrderId = MtUniCreditShopOrderId::tryNormalize($shopOrderId);
        $statusLabel = is_string($statusLabel) ? trim($statusLabel) : '';
        $statusId = is_string($statusId) ? trim($statusId) : '';
        if ($canonicalOrderId === null || $statusId === '' || $statusLabel === '') {
            throw new MtUniCreditCpInvalidPayloadException('Control Panel order status fields are incomplete.');
        }
        $payload = array(
            'order_id' => $canonicalOrderId,
            'status' => $statusLabel,
            'status_id' => $statusId,
        );
        $response = $this->authenticatedRequest('PATCH', '/orders/status', $payload);
        $this->assertStatusPatchConfirmed($response, $payload);

        return $response;
    }

    /**
     * Current shop UNICID from module credentials (same source as CP login).
     * Never derived from a financing attempt row.
     *
     * @return string
     */
    public function getConfiguredUnicid()
    {
        return trim((string) $this->credentials->getUnicid($this->storeId));
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
            // POST /orders must never auto-replay after a remote response — lifecycle owns create ambiguity.
            if (!$this->allowsAuthenticationRetry($method, $path)) {
                throw $exception;
            }

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
     * Automatic login-and-retry after a canonical 401 is allowed only for idempotent routes.
     * Unsafe create (POST /orders) is never auto-replayed once a remote response was received.
     *
     * @param string $method
     * @param string $path
     * @return bool
     */
    private function allowsAuthenticationRetry($method, $path)
    {
        $method = strtoupper((string) $method);
        $normalized = '/' . trim((string) $path, '/');

        if ($method === 'GET' && ($normalized === '/shop' || strpos($normalized, '/ssl/') === 0)) {
            return true;
        }

        if ($method === 'PATCH' && $normalized === '/orders/status') {
            return true;
        }

        return false;
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

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            // Including 401: bare/malformed bodies are not treated as safe auth evidence.
            throw $this->buildHttpFailure($response->getStatusCode(), $response->getBody());
        }

        return $this->decodeSuccessEnvelope($response->getBody());
    }

    /**
     * @param int $statusCode
     * @param string $body
     * @return Throwable
     */
    private function buildHttpFailure($statusCode, $body)
    {
        $statusCode = (int) $statusCode;

        try {
            $decodedObject = $this->decodeJsonAsObject($body);
        } catch (MtUniCreditCpMalformedJsonException $exception) {
            return $exception;
        }

        if ($decodedObject === null) {
            return new MtUniCreditCpMalformedJsonException('The Control Panel JSON error response is not an object.');
        }

        if (
            !property_exists($decodedObject, 'success')
            || $decodedObject->success !== false
            || !property_exists($decodedObject, 'error')
            || !is_string($decodedObject->error)
            || $decodedObject->error === ''
            || !preg_match('/^[a-z][a-z0-9_]*$/D', $decodedObject->error)
            || !property_exists($decodedObject, 'message')
            || !is_string($decodedObject->message)
            || !property_exists($decodedObject, 'data')
            || !($decodedObject->data instanceof stdClass)
        ) {
            return new MtUniCreditCpInvalidPayloadException('The Control Panel error response is not a canonical failure envelope.');
        }

        try {
            $decoded = json_decode(json_encode($decodedObject, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return new MtUniCreditCpMalformedJsonException('The Control Panel JSON error response is not an object.', 0, $exception);
        }
        if (!is_array($decoded)) {
            return new MtUniCreditCpMalformedJsonException('The Control Panel JSON error response is not an object.');
        }

        $message = is_string($decodedObject->message) ? $decodedObject->message : 'Control Panel HTTP error.';

        // Canonical 401 is structured auth failure evidence for safe-route retry policy.
        if ($statusCode === 401) {
            return new MtUniCreditCpAuthenticationException(
                $message !== '' ? $message : 'The Control Panel rejected the authentication.'
            );
        }

        return new MtUniCreditCpHttpException(
            $statusCode,
            $decoded,
            $message,
            true,
            $decodedObject->error
        );
    }

    /**
     * @param string $body
     * @return array<string, mixed>
     */
    private function decodeSuccessEnvelope($body)
    {
        $decodedObject = $this->decodeJsonAsObject($body);
        if ($decodedObject === null) {
            throw new MtUniCreditCpMalformedJsonException('The Control Panel JSON response is not an object.');
        }

        if (
            !property_exists($decodedObject, 'success')
            || $decodedObject->success !== true
            || !property_exists($decodedObject, 'error')
            || $decodedObject->error !== null
            || !property_exists($decodedObject, 'message')
            || !is_string($decodedObject->message)
            || !property_exists($decodedObject, 'data')
            || !($decodedObject->data instanceof stdClass)
        ) {
            throw new MtUniCreditCpInvalidPayloadException('The Control Panel response does not confirm success.');
        }

        try {
            $decoded = json_decode(json_encode($decodedObject, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MtUniCreditCpMalformedJsonException('The Control Panel JSON response is not an object.', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new MtUniCreditCpMalformedJsonException('The Control Panel JSON response is not an object.');
        }

        return $decoded;
    }

    /**
     * @param string $body
     * @return stdClass|null
     */
    private function decodeJsonAsObject($body)
    {
        try {
            $decoded = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MtUniCreditCpMalformedJsonException('The Control Panel returned malformed JSON.', 0, $exception);
        }

        return $decoded instanceof stdClass ? $decoded : null;
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
     * @param bool $requireShop
     * @return void
     */
    private function storeTokenResponse(array $response, $requireShop)
    {
        $data = isset($response['data']) ? $response['data'] : null;
        if (!is_array($data) || !$this->isAssociativeObject($data)) {
            $this->tokens->invalidate();
            throw new MtUniCreditCpInvalidPayloadException('The Control Panel token response has no valid data object.');
        }

        // Tokens ONLY from response.data — reject legacy top-level token fields when data.access_token is absent.
        if (isset($response['access_token']) || isset($response['token_type']) || isset($response['expires_in'])) {
            if (!isset($data['access_token'])) {
                $this->tokens->invalidate();
                throw new MtUniCreditCpInvalidPayloadException('The Control Panel token response uses legacy top-level token fields.');
            }
        }

        $accessToken = isset($data['access_token']) ? $data['access_token'] : null;
        $tokenType = isset($data['token_type']) ? $data['token_type'] : null;
        $expiresIn = isset($data['expires_in']) ? $data['expires_in'] : null;

        if (
            !is_string($accessToken) || $accessToken === ''
            || !is_string($tokenType) || strcasecmp($tokenType, 'Bearer') !== 0
            || !is_numeric($expiresIn) || (int) $expiresIn <= 0
        ) {
            $this->tokens->invalidate();
            throw new MtUniCreditCpInvalidPayloadException('The Control Panel token response is invalid.');
        }

        if ($requireShop) {
            $shop = isset($data['shop']) ? $data['shop'] : null;
            if (!is_array($shop)) {
                $this->tokens->invalidate();
                throw new MtUniCreditCpInvalidPayloadException('The Control Panel login response has no valid shop data.');
            }

            $responseUnicid = isset($shop['unicid']) ? $shop['unicid'] : null;
            $configuredUnicid = $this->credentials->getUnicid($this->storeId);
            if (
                !is_string($responseUnicid)
                || $responseUnicid === ''
                || $configuredUnicid === ''
                || !hash_equals($configuredUnicid, $responseUnicid)
            ) {
                $this->tokens->invalidate();
                throw new MtUniCreditCpInvalidPayloadException('The Control Panel login shop UNICID does not match configuration.');
            }
        }

        if (!$this->tokens->save($accessToken, $tokenType, $this->now() + (int) $expiresIn)) {
            $this->tokens->invalidate();
            throw new MtUniCreditCpInvalidPayloadException('The Control Panel token could not be stored.');
        }
    }

    /**
     * Strict response-owned identity for 2xx POST /orders success (create or equivalent replay).
     *
     * @param array<string, mixed> $response
     * @param array<string, mixed> $order Submitted create payload (frozen order_id)
     * @return void
     */
    private function assertCreateOrderIdentity(array $response, array $order)
    {
        $data = isset($response['data']) ? $response['data'] : null;
        if (!is_array($data) || !$this->isAssociativeObject($data)) {
            throw new MtUniCreditCpInvalidPayloadException('The Control Panel create-order response has no valid data object.');
        }

        $id = isset($data['id']) ? $data['id'] : null;
        if (!is_int($id) || $id <= 0) {
            throw new MtUniCreditCpInvalidPayloadException('The Control Panel create-order response has no order id.');
        }

        $sentOrderId = isset($order['order_id']) ? $order['order_id'] : null;
        if (!is_string($sentOrderId) || $sentOrderId === '') {
            throw new MtUniCreditCpInvalidPayloadException('The Control Panel create-order request order_id is invalid.');
        }
        $echoOrderId = isset($data['order_id']) ? $data['order_id'] : null;
        if (!is_string($echoOrderId) || $echoOrderId !== $sentOrderId) {
            throw new MtUniCreditCpInvalidPayloadException('The Control Panel create-order response order_id does not match the request.');
        }

        $configuredUnicid = $this->credentials->getUnicid($this->storeId);
        $echoUnicid = isset($data['unicid']) ? $data['unicid'] : null;
        if (
            !is_string($echoUnicid)
            || $echoUnicid === ''
            || $configuredUnicid === ''
            || !hash_equals($configuredUnicid, $echoUnicid)
        ) {
            throw new MtUniCreditCpInvalidPayloadException('The Control Panel create-order response unicid does not match configuration.');
        }

        $shopId = isset($data['shop_id']) ? $data['shop_id'] : null;
        if (!is_int($shopId) || $shopId <= 0) {
            throw new MtUniCreditCpInvalidPayloadException('The Control Panel create-order response has no valid shop_id.');
        }

        $createdAt = isset($data['created_at']) ? $data['created_at'] : null;
        if (!is_string($createdAt) || trim($createdAt) === '') {
            throw new MtUniCreditCpInvalidPayloadException('The Control Panel create-order response has no valid created_at.');
        }
    }

    /**
     * @param array<string, mixed> $response
     * @param array{order_id: string, status: string, status_id: string} $payload
     * @return void
     */
    private function assertStatusPatchConfirmed(array $response, array $payload)
    {
        $data = isset($response['data']) ? $response['data'] : null;
        if (!is_array($data) || !$this->isAssociativeObject($data)) {
            throw new MtUniCreditCpInvalidPayloadException('The Control Panel status response has no valid data object.');
        }

        $id = isset($data['id']) ? $data['id'] : null;
        if (!is_int($id) || $id <= 0) {
            throw new MtUniCreditCpInvalidPayloadException('The Control Panel status response has no valid id.');
        }

        $shopId = isset($data['shop_id']) ? $data['shop_id'] : null;
        if (!is_int($shopId) || $shopId <= 0) {
            throw new MtUniCreditCpInvalidPayloadException('The Control Panel status response has no valid shop_id.');
        }

        $echoOrderId = isset($data['order_id']) ? $data['order_id'] : null;
        $echoStatusId = isset($data['status_id']) ? $data['status_id'] : null;
        $echoStatus = isset($data['status']) ? $data['status'] : null;
        if (
            !is_string($echoOrderId) || $echoOrderId !== $payload['order_id']
            || !is_string($echoStatusId) || $echoStatusId !== $payload['status_id']
            || !is_string($echoStatus) || $echoStatus !== $payload['status']
        ) {
            throw new MtUniCreditCpInvalidPayloadException('The Control Panel status response does not echo the request identity.');
        }

        $updatedAt = isset($data['updated_at']) ? $data['updated_at'] : null;
        if (!is_string($updatedAt) || trim($updatedAt) === '') {
            throw new MtUniCreditCpInvalidPayloadException('The Control Panel status response has no valid updated_at.');
        }
    }

    /**
     * PHP 7.3 stand-in for !array_is_list(): JSON object → associative; empty `{}` OK.
     *
     * @param array<mixed> $value
     * @return bool
     */
    private function isAssociativeObject(array $value)
    {
        return $this->isJsonObjectArray($value);
    }

    /**
     * True for decoded JSON objects (incl. empty); false for JSON lists.
     *
     * @param array<mixed> $value
     * @return bool
     */
    private function isJsonObjectArray(array $value)
    {
        if ($value === array()) {
            return true;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }

    /**
     * @return int
     */
    private function now()
    {
        return (int) call_user_func($this->clock);
    }
}
