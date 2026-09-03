<?php

/**
 * Classified SmartUCF failure outcome for lifecycle transitions.
 */
final class MtUniCreditSmartUcfFailureClassification
{
    const CLASS_PRE_SEND = 'pre_send';
    const CLASS_REMOTE_REJECT = 'remote_reject';
    const CLASS_TRANSPORT_AMBIGUOUS = 'transport_ambiguous';
    const CLASS_DUPLICATE_ORDER_NO = 'duplicate_order_no';

    /** @var string */
    private $targetState;

    /** @var bool */
    private $retryable;

    /** @var string */
    private $errorClass;

    /** @var int */
    private $httpCode;

    /**
     * @param string $targetState
     * @param bool $retryable
     * @param string $errorClass
     * @param int $httpCode
     */
    public function __construct($targetState, $retryable, $errorClass, $httpCode = 0)
    {
        $this->targetState = (string) $targetState;
        $this->retryable = (bool) $retryable;
        $this->errorClass = (string) $errorClass;
        $this->httpCode = (int) $httpCode;
    }

    /**
     * @return string
     */
    public function targetState()
    {
        return $this->targetState;
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

    /**
     * @return int
     */
    public function httpCode()
    {
        return $this->httpCode;
    }
}
