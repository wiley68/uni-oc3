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

            return new MtUniCreditCpHttpResponse((int) $next['status'], (string) $next['body']);
        }

        throw new RuntimeException('FakeCpHttpTransport has no queued response for ' . $method . ' ' . $url);
    }
}
