<?php

require_once dirname(__DIR__) . '/fixtures/cp_shop_snapshot.php';

/**
 * Fake CP HTTP transport for Phase 4 offline tests (no live network).
 */
final class Phase4FakeCpHttpTransport implements MtUniCreditCpHttpTransport
{
    /** @var array<int, array<string, mixed>> */
    private $responses = array();

    /** @var array<int, array<string, mixed>> */
    public $requests = array();

    /** When true, auto PATCH /orders/status returns HTTP 500. */
    public $failStatusPatch = false;

    /**
     * @param int $status
     * @param string $body
     * @return void
     */
    public function enqueue($status, $body)
    {
        $this->responses[] = array('status' => (int) $status, 'body' => (string) $body);
    }

    /**
     * @param int $status
     * @param array<string, mixed> $payload
     * @return void
     */
    public function enqueueJson($status, array $payload)
    {
        $this->enqueue($status, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @return void
     */
    public function enqueueTimeout()
    {
        $this->responses[] = array('timeout' => true);
    }

    /**
     * @return void
     */
    public function enqueueConnectionFailure()
    {
        $this->responses[] = array('connection' => true);
    }

    /**
     * Simulate post-receive size abort (same exception class as curl transport).
     *
     * @return void
     */
    public function enqueueSizeAbort()
    {
        $this->responses[] = array('size_abort' => true);
    }

    /**
     * @param string $method
     * @param string $url
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $payload
     * @return MtUniCreditCpHttpResponse
     */
    public function request($method, $url, array $headers, $payload)
    {
        $this->requests[] = array(
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'payload' => $payload,
        );

        if ($this->responses !== array()) {
            $next = array_shift($this->responses);
            if (!empty($next['timeout'])) {
                throw new MtUniCreditCpTimeoutException('Fake Control Panel timeout.');
            }
            if (!empty($next['connection'])) {
                throw new MtUniCreditCpConnectionException('Fake Control Panel connection failure.');
            }
            if (!empty($next['size_abort'])) {
                throw new MtUniCreditCpInvalidPayloadException(
                    'The Control Panel response exceeded the allowed size.'
                );
            }

            $status = (int) $next['status'];
            $body = (string) $next['body'];
            if (
                $status >= 200
                && $status < 300
                && strtoupper((string) $method) === 'POST'
                && substr((string) $url, -7) === '/orders'
            ) {
                $body = $this->completeOrderCreateSuccessIdentity($body, $payload);
            }

            return new MtUniCreditCpHttpResponse($status, $body);
        }

        // Auto-respond to bank status sync when tests only queued login/create.
        // Echo submitted identity/state so PATCH confirmation validation can pass.
        if (strtoupper((string) $method) === 'PATCH' && strpos((string) $url, '/orders/status') !== false) {
            if ($this->failStatusPatch) {
                return new MtUniCreditCpHttpResponse(500, json_encode(array(
                    'error' => 'status_update_failed',
                    'message' => 'Forced PATCH failure for tests',
                ), JSON_THROW_ON_ERROR));
            }

            $orderId = (is_array($payload) && isset($payload['order_id'])) ? (string) $payload['order_id'] : '';
            $status = (is_array($payload) && isset($payload['status'])) ? (string) $payload['status'] : '';
            $statusId = (is_array($payload) && isset($payload['status_id'])) ? (string) $payload['status_id'] : '';

            return new MtUniCreditCpHttpResponse(200, json_encode(array(
                'success' => true,
                'message' => 'Статусът на поръчката е обновен успешно',
                'data' => array(
                    'id' => 1,
                    'order_id' => $orderId,
                    'shop_id' => 1,
                    'status' => $status,
                    'status_id' => $statusId,
                    'updated_at' => '2024-01-01 00:00:00',
                ),
            ), JSON_THROW_ON_ERROR));
        }

        throw new RuntimeException('FakeCpHttpTransport has no queued response for ' . $method . ' ' . $url);
    }

    /**
     * Fill missing Phase-A identity fields on success fixtures without overwriting
     * intentionally incomplete/mismatched values used by negative tests.
     *
     * @param string $body
     * @param array<string, mixed>|null $payload
     * @return string
     */
    private function completeOrderCreateSuccessIdentity($body, $payload)
    {
        try {
            $decoded = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return (string) $body;
        }

        if (!is_array($decoded) || !isset($decoded['success']) || $decoded['success'] !== true) {
            return (string) $body;
        }
        if (!isset($decoded['data']) || !is_array($decoded['data'])) {
            return (string) $body;
        }

        foreach (array_keys($decoded['data']) as $key) {
            if (!is_string($key)) {
                return (string) $body;
            }
        }

        // Legacy fixtures often queued only data.id. Complete identity only when none of the
        // Phase-A fields are present so negative "missing field" cases stay intentional.
        $hasOrderId = array_key_exists('order_id', $decoded['data']);
        $hasUnicid = array_key_exists('unicid', $decoded['data']);
        $hasShopId = array_key_exists('shop_id', $decoded['data']);
        if ($hasOrderId || $hasUnicid || $hasShopId) {
            return (string) $body;
        }

        if (is_array($payload) && array_key_exists('order_id', $payload)) {
            $decoded['data']['order_id'] = $payload['order_id'];
        }
        $decoded['data']['unicid'] = '123e4567-e89b-12d3-a456-426614174000';
        $decoded['data']['shop_id'] = 1;

        try {
            return json_encode($decoded, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return (string) $body;
        }
    }
}
