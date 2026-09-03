<?php

/**
 * Outcome of a Process 1 SmartUCF coordination attempt.
 */
final class MtUniCreditSmartUcfCoordinationResult
{
    const KIND_CREATED = 'created';
    const KIND_PROCESSING = 'processing';
    const KIND_OUTCOME_UNKNOWN = 'outcome_unknown';
    const KIND_FAILED = 'failed';
    const KIND_PROCESS2 = 'process2';

    /** @var string */
    private $kind;

    /** @var string */
    private $redirectUrl;

    /** @var string */
    private $sessionId;

    /** @var string */
    private $customerMessage;

    /** @var bool */
    private $retryable;

    /** @var string */
    private $errorClass;

    /**
     * @param string $kind
     * @param string $redirectUrl
     * @param string $sessionId
     * @param string $customerMessage
     * @param bool $retryable
     * @param string $errorClass
     */
    private function __construct(
        $kind,
        $redirectUrl = '',
        $sessionId = '',
        $customerMessage = '',
        $retryable = false,
        $errorClass = ''
    ) {
        $this->kind = (string) $kind;
        $this->redirectUrl = (string) $redirectUrl;
        $this->sessionId = (string) $sessionId;
        $this->customerMessage = (string) $customerMessage;
        $this->retryable = (bool) $retryable;
        $this->errorClass = (string) $errorClass;
    }

    /**
     * @param string $redirectUrl
     * @param string $sessionId
     * @return self
     */
    public static function created($redirectUrl, $sessionId)
    {
        return new self(self::KIND_CREATED, $redirectUrl, $sessionId);
    }

    /**
     * @param string $message
     * @return self
     */
    public static function processing($message)
    {
        return new self(self::KIND_PROCESSING, '', '', $message);
    }

    /**
     * @param string $message
     * @return self
     */
    public static function outcomeUnknown($message)
    {
        return new self(self::KIND_OUTCOME_UNKNOWN, '', '', $message);
    }

    /**
     * @param string $message
     * @param bool $retryable
     * @param string $errorClass
     * @return self
     */
    public static function failed($message, $retryable = false, $errorClass = '')
    {
        return new self(self::KIND_FAILED, '', '', $message, $retryable, $errorClass);
    }

    /**
     * @return self
     */
    public static function process2()
    {
        return new self(self::KIND_PROCESS2);
    }

    /**
     * @return bool
     */
    public function isCreated()
    {
        return $this->kind === self::KIND_CREATED;
    }

    /**
     * @return bool
     */
    public function isProcessing()
    {
        return $this->kind === self::KIND_PROCESSING;
    }

    /**
     * @return bool
     */
    public function isOutcomeUnknown()
    {
        return $this->kind === self::KIND_OUTCOME_UNKNOWN;
    }

    /**
     * @return bool
     */
    public function isFailed()
    {
        return $this->kind === self::KIND_FAILED;
    }

    /**
     * @return bool
     */
    public function isProcess2()
    {
        return $this->kind === self::KIND_PROCESS2;
    }

    /**
     * @return string
     */
    public function redirectUrl()
    {
        return $this->redirectUrl;
    }

    /**
     * @return string
     */
    public function sessionId()
    {
        return $this->sessionId;
    }

    /**
     * @return string
     */
    public function customerMessage()
    {
        return $this->customerMessage;
    }

    /**
     * @return bool
     */
    public function isRetryable()
    {
        return $this->retryable;
    }

    /**
     * @return string
     */
    public function errorClass()
    {
        return $this->errorClass;
    }
}
