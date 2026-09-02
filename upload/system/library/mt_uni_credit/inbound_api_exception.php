<?php

/**
 * JSON inbound API failure with HTTP status mapping.
 */
final class MtUniCreditInboundApiException extends Exception
{
    /** @var int */
    private $statusCode;

    /** @var string|null */
    private $errorCode;

    /** @var array<string, mixed>|null */
    private $responseData;

    /**
     * @param string $message
     * @param int $statusCode
     * @param string|null $errorCode
     * @param array<string, mixed>|null $responseData
     */
    public function __construct($message, $statusCode, $errorCode = null, $responseData = null)
    {
        parent::__construct($message);
        $this->statusCode = (int) $statusCode;
        $this->errorCode = $errorCode;
        $this->responseData = $responseData;
    }

    /**
     * @return int
     */
    public function getStatusCode()
    {
        return $this->statusCode;
    }

    /**
     * @return string|null
     */
    public function getErrorCode()
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getResponseData()
    {
        return $this->responseData;
    }
}
