<?php

/**
 * SmartUCF session create failures with retry/kind metadata.
 */
final class MtUniCreditSmartUcfSessionException extends RuntimeException
{
    const KIND_PRE_SEND = 'pre_send';
    const KIND_TRANSPORT = 'transport';
    const KIND_REMOTE = 'remote';
    const KIND_DUPLICATE = 'duplicate';

    /** @var bool */
    private $retryable;

    /** @var string */
    private $rawResponse;

    /** @var int */
    private $httpCode;

    /** @var string */
    private $failureKind;

    /** @var string Actual JSON body sent to SmartUCF when HTTP was attempted; empty when pre-send. */
    private $requestBody;

    /** @var string Trusted session-start endpoint URL when known. */
    private $endpoint;

    /**
     * @param string $message
     * @param bool $retryable
     * @param string $rawResponse
     * @param int $httpCode
     * @param string $failureKind
     * @param Throwable|null $previous
     * @param string $requestBody
     * @param string $endpoint
     */
    public function __construct(
        $message,
        $retryable = false,
        $rawResponse = '',
        $httpCode = 0,
        $failureKind = self::KIND_REMOTE,
        $previous = null,
        $requestBody = '',
        $endpoint = ''
    ) {
        parent::__construct($message, 0, $previous);
        $this->retryable = (bool) $retryable;
        $this->rawResponse = (string) $rawResponse;
        $this->httpCode = (int) $httpCode;
        $this->failureKind = (string) $failureKind;
        $this->requestBody = (string) $requestBody;
        $this->endpoint = (string) $endpoint;
    }

    /**
     * @return bool
     */
    public function isRetryable()
    {
        return $this->retryable;
    }

    /**
     * @return int
     */
    public function httpCode()
    {
        return $this->httpCode;
    }

    /**
     * @return string
     */
    public function rawResponse()
    {
        return $this->rawResponse;
    }

    /**
     * @return string
     */
    public function failureKind()
    {
        return $this->failureKind;
    }

    /**
     * @return string
     */
    public function requestBody()
    {
        return $this->requestBody;
    }

    /**
     * @return string
     */
    public function endpoint()
    {
        return $this->endpoint;
    }
}
