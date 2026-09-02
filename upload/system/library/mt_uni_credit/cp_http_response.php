<?php

final class MtUniCreditCpHttpResponse
{
    /** @var int */
    private $statusCode;

    /** @var string */
    private $body;

    /**
     * @param int $statusCode
     * @param string $body
     */
    public function __construct($statusCode, $body)
    {
        $this->statusCode = (int) $statusCode;
        $this->body = (string) $body;
    }

    /**
     * @return int
     */
    public function getStatusCode()
    {
        return $this->statusCode;
    }

    /**
     * @return string
     */
    public function getBody()
    {
        return $this->body;
    }
}
