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

final class MtUniCreditCpMalformedJsonException extends MtUniCreditCpException
{
    /** @var int|null HTTP status when this was raised from a non-2xx CP response body */
    private $httpStatusCode;

    /**
     * @param string $message
     * @param int $code
     * @param Throwable|null $previous
     * @param int|null $httpStatusCode
     */
    public function __construct($message = '', $code = 0, $previous = null, $httpStatusCode = null)
    {
        parent::__construct($message, (int) $code, $previous);
        $this->httpStatusCode = $httpStatusCode !== null ? (int) $httpStatusCode : null;
    }

    /**
     * @return int|null
     */
    public function getHttpStatusCode()
    {
        return $this->httpStatusCode;
    }
}

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

    /** @var bool */
    private $canonicalFailure;

    /** @var string|null */
    private $canonicalError;

    /**
     * @param int $statusCode
     * @param array<string, mixed> $errorPayload Safe decoded error body without secrets.
     * @param string $message
     * @param bool $canonicalFailure
     * @param string|null $canonicalError
     */
    public function __construct(
        $statusCode,
        array $errorPayload = array(),
        $message = 'Control Panel HTTP error.',
        $canonicalFailure = false,
        $canonicalError = null
    ) {
        parent::__construct($message);
        $this->statusCode = (int) $statusCode;
        $this->errorPayload = $errorPayload;
        $this->canonicalFailure = (bool) $canonicalFailure;
        $this->canonicalError = $canonicalError !== null ? (string) $canonicalError : null;
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
    public function isCanonicalFailure()
    {
        return $this->canonicalFailure;
    }

    /**
     * @return string|null
     */
    public function getCanonicalError()
    {
        return $this->canonicalError;
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
    /** @var int|null HTTP status when this was raised from a non-2xx CP response body */
    private $httpStatusCode;

    /**
     * @param string $message
     * @param int $code
     * @param Throwable|null $previous
     * @param int|null $httpStatusCode
     */
    public function __construct($message = '', $code = 0, $previous = null, $httpStatusCode = null)
    {
        parent::__construct($message, (int) $code, $previous);
        $this->httpStatusCode = $httpStatusCode !== null ? (int) $httpStatusCode : null;
    }

    /**
     * @return int|null
     */
    public function getHttpStatusCode()
    {
        return $this->httpStatusCode;
    }

    /**
     * @return bool
     */
    public function isPermanentAuthOrConfiguration()
    {
        return true;
    }
}

/**
 * Post-send CP response defect: the request may already have been processed remotely,
 * so persistence cannot be disproven. Lifecycle must treat this as outcome-unknown.
 * Prefer InvalidPayload for create identity / envelope failures (mapped to outcome_unknown).
 */
final class MtUniCreditCpUncertainResponseException extends MtUniCreditCpException {}

/**
 * Pre-send Control Panel configuration / destination failure.
 * No HTTP request was attempted, so a remote CP order cannot exist.
 * Create-path lifecycle maps this to definitive terminal rejection.
 */
final class MtUniCreditCpConfigurationException extends MtUniCreditCpException
{
    /**
     * @return bool
     */
    public function isPermanentAuthOrConfiguration()
    {
        return true;
    }
}
