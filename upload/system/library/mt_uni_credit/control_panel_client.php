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
        // Auth/token bootstrap is pre-send for POST /orders. Response defects there
        // remain definitive InvalidPayload (and Uncertain from transport is remapped).
        try {
            $token = $this->ensureToken();
        } catch (MtUniCreditCpUncertainResponseException $exception) {
            throw new MtUniCreditCpInvalidPayloadException($exception->getMessage(), 0, $exception);
        }

        try {
            $response = $this->sendOrderCreate($order, $token);
        } catch (MtUniCreditCpAuthenticationException $exception) {
            // First order POST was rejected by auth middleware before persistence.
            // Re-login is bootstrap — must NOT enter order-response uncertainty conversion.
            $this->tokens->invalidate();
            $this->reloginForOrderRetry();
            $retryToken = $this->tokens->getAccessToken();
            if ($retryToken === null) {
                throw new MtUniCreditCpAuthenticationException(
                    'Control Panel re-authentication did not provide a token.'
                );
            }

            try {
                $response = $this->sendOrderCreate($order, $retryToken);
            } catch (MtUniCreditCpAuthenticationException $retryException) {
                $this->tokens->invalidate();
                throw $retryException;
            }
        }

        $this->assertOrderCreateSuccessIdentity($response, $order);

        return $response;
    }

    /**
     * Strict response-owned identity for 2xx POST /orders success (create or equivalent replay).
     * Failures are post-send uncertainty — CP may already have persisted.
     *
     * @param array<string, mixed> $response
     * @param array<string, mixed> $order Submitted create payload (frozen order_id)
     * @return void
     */
    private function assertOrderCreateSuccessIdentity(array $response, array $order)
    {
        if (
            !isset($response['data'])
            || !is_array($response['data'])
            || !$this->isJsonObjectArray($response['data'])
        ) {
            throw new MtUniCreditCpUncertainResponseException(
                'The Control Panel order response has no valid data object.'
            );
        }

        $data = $response['data'];

        if (!array_key_exists('id', $data) || !is_int($data['id']) || $data['id'] <= 0) {
            throw new MtUniCreditCpUncertainResponseException(
                'The Control Panel order response id is missing or invalid.'
            );
        }

        if (!array_key_exists('shop_id', $data) || !is_int($data['shop_id']) || $data['shop_id'] <= 0) {
            throw new MtUniCreditCpUncertainResponseException(
                'The Control Panel order response shop_id is missing or invalid.'
            );
        }

        $expectedOrderId = array_key_exists('order_id', $order) ? $order['order_id'] : null;
        if (!is_string($expectedOrderId)) {
            throw new MtUniCreditCpUncertainResponseException(
                'The Control Panel order request identity is incomplete.'
            );
        }
        if (
            !array_key_exists('order_id', $data)
            || !is_string($data['order_id'])
            || $data['order_id'] !== $expectedOrderId
        ) {
            throw new MtUniCreditCpUncertainResponseException(
                'The Control Panel order response order_id does not match the submitted order.'
            );
        }

        $expectedUnicid = $this->credentials->getUnicid($this->storeId);
        if (!is_string($expectedUnicid) || $expectedUnicid === '') {
            throw new MtUniCreditCpUncertainResponseException(
                'The Control Panel store identity is incomplete.'
            );
        }
        if (
            !array_key_exists('unicid', $data)
            || !is_string($data['unicid'])
            || $data['unicid'] !== $expectedUnicid
        ) {
            throw new MtUniCreditCpUncertainResponseException(
                'The Control Panel order response unicid does not match the configured store.'
            );
        }
    }

    /**
     * JSON object → associative array with string keys; JSON list → integer keys.
     *
     * @param array<mixed, mixed> $value
     * @return bool
     */
    private function isJsonObjectArray(array $value)
    {
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * POST /orders once and classify post-send InvalidPayload as persistence-uncertain.
     *
     * @param array<string, mixed> $order
     * @param string $token
     * @return array<string, mixed>
     */
    private function sendOrderCreate(array $order, $token)
    {
        try {
            return $this->send('POST', '/orders', $order, $token, true);
        } catch (MtUniCreditCpInvalidPayloadException $exception) {
            // Body received / size-aborted after this order POST may already have reached CP.
            throw new MtUniCreditCpUncertainResponseException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Re-authenticate after a definitive order 401. Failures stay non-ambiguous.
     *
     * @return void
     */
    private function reloginForOrderRetry()
    {
        try {
            $this->login();
        } catch (MtUniCreditCpMalformedJsonException $exception) {
            throw new MtUniCreditCpInvalidPayloadException($exception->getMessage(), 0, $exception);
        } catch (MtUniCreditCpTimeoutException $exception) {
            throw new MtUniCreditCpAuthenticationException(
                'Control Panel re-authentication timed out.',
                0,
                $exception
            );
        } catch (MtUniCreditCpConnectionException $exception) {
            throw new MtUniCreditCpAuthenticationException(
                'Control Panel re-authentication failed to connect.',
                0,
                $exception
            );
        }
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
        $response = $this->authenticatedRequest('PATCH', '/orders/status', array(
            'order_id' => $shopOrderId,
            'status' => $statusLabel,
            'status_id' => $statusId,
        ));
        $this->assertStatusPatchConfirmed($response, $shopOrderId, $statusLabel, $statusId);
    }

    /**
     * CP PATCH /orders/status success contract (ShopAuthController::updateOrderStatus):
     * {
     *   "success": true,
     *   "message": "...",
     *   "data": {
     *     "id": <int>,
     *     "order_id": <string>,
     *     "shop_id": <int>,
     *     "status": <string>,
     *     "status_id": <string|null>,
     *     "updated_at": "Y-m-d H:i:s"
     *   }
     * }
     *
     * This client always submits status_id; confirmation requires exact echo of
     * order_id, status, and status_id. Do not reconstruct missing fields from the request.
     *
     * @param array<string, mixed> $response
     * @param string $shopOrderId
     * @param string $statusLabel
     * @param string $statusId
     * @return void
     */
    private function assertStatusPatchConfirmed(array $response, $shopOrderId, $statusLabel, $statusId)
    {
        if (!isset($response['data']) || !is_array($response['data'])) {
            throw new MtUniCreditCpInvalidPayloadException(
                'The Control Panel status response has no valid data object.'
            );
        }
        if ($this->isListArray($response['data'])) {
            throw new MtUniCreditCpInvalidPayloadException(
                'The Control Panel status response data object is invalid.'
            );
        }

        $data = $response['data'];
        if (!isset($data['order_id']) || !is_string($data['order_id']) || $data['order_id'] === '') {
            throw new MtUniCreditCpInvalidPayloadException(
                'The Control Panel status response does not confirm order identity.'
            );
        }
        if ($data['order_id'] !== $shopOrderId) {
            throw new MtUniCreditCpInvalidPayloadException(
                'The Control Panel status response order identity does not match the request.'
            );
        }

        if (!isset($data['status']) || !is_string($data['status']) || $data['status'] === '') {
            throw new MtUniCreditCpInvalidPayloadException(
                'The Control Panel status response does not confirm status.'
            );
        }
        if ($data['status'] !== $statusLabel) {
            throw new MtUniCreditCpInvalidPayloadException(
                'The Control Panel status response status does not match the request.'
            );
        }

        // Request always includes status_id; CP success payload always includes status_id.
        if (!array_key_exists('status_id', $data) || !is_string($data['status_id']) || $data['status_id'] === '') {
            throw new MtUniCreditCpInvalidPayloadException(
                'The Control Panel status response does not confirm status_id.'
            );
        }
        if ($data['status_id'] !== $statusId) {
            throw new MtUniCreditCpInvalidPayloadException(
                'The Control Panel status response status_id does not match the request.'
            );
        }
    }

    /**
     * @param array<mixed> $value
     * @return bool
     */
    private function isListArray(array $value)
    {
        if ($value === array()) {
            return false;
        }

        return array_keys($value) === range(0, count($value) - 1);
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
     * @param bool $uncertainOnInvalidSuccess When true, a 2xx body that does not confirm
     *                                        success is treated as post-send uncertainty
     *                                        (order create). Other CP routes keep InvalidPayload.
     * @return array<string, mixed>
     */
    private function send($method, $path, $payload = null, $token = null, $uncertainOnInvalidSuccess = false)
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
            if ($uncertainOnInvalidSuccess) {
                throw new MtUniCreditCpUncertainResponseException(
                    'The Control Panel response does not confirm success.'
                );
            }
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
