<?php

class MtUniCreditCpException extends RuntimeException
{
    /**
     * @return bool
     */
    public function isTransient()
    {
        return false;
    }

    /**
     * @return bool
     */
    public function isPermanentAuthOrConfiguration()
    {
        return false;
    }
}

class MtUniCreditCpConnectionException extends MtUniCreditCpException
{
    /**
     * @return bool
     */
    public function isTransient()
    {
        return true;
    }
}

final class MtUniCreditCpTimeoutException extends MtUniCreditCpConnectionException {}

final class MtUniCreditCpMalformedJsonException extends MtUniCreditCpException {}

final class MtUniCreditCpAuthenticationException extends MtUniCreditCpException
{
    /**
     * @return bool
     */
    public function isPermanentAuthOrConfiguration()
    {
        return true;
    }
}

final class MtUniCreditCpHttpException extends MtUniCreditCpException
{
    /** @var int */
    private $statusCode;

    /** @var array<string, mixed> */
    private $errorPayload;

    /**
     * @param int $statusCode
     * @param array<string, mixed> $errorPayload
     * @param string $message
     */
    public function __construct($statusCode, array $errorPayload = array(), $message = 'Control Panel HTTP error.')
    {
        parent::__construct($message);
        $this->statusCode = (int) $statusCode;
        $this->errorPayload = $errorPayload;
    }

    /**
     * @return int
     */
    public function getStatusCode()
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getErrorPayload()
    {
        return $this->errorPayload;
    }

    /**
     * @return bool
     */
    public function isTransient()
    {
        return $this->statusCode >= 500;
    }

    /**
     * @return bool
     */
    public function isPermanentAuthOrConfiguration()
    {
        return in_array($this->statusCode, array(400, 401, 403, 404), true);
    }
}

final class MtUniCreditCpInvalidPayloadException extends MtUniCreditCpException
{
    /**
     * @return bool
     */
    public function isPermanentAuthOrConfiguration()
    {
        return true;
    }
}
