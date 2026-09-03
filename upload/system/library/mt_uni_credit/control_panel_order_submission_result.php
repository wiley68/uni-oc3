<?php

/**
 * Result of a CP order create/resume attempt (and optional Process 1 SmartUCF).
 */
final class MtUniCreditControlPanelOrderSubmissionResult
{
    /** @var bool Overall customer-facing success (CP+Process1 when Process 1). */
    public $success;

    /** @var int */
    public $controlPanelOrderId;

    /** @var bool */
    public $localReplay;

    /** @var string|null */
    public $errorClass;

    /** @var bool */
    public $recoverable;

    /** @var int|null */
    public $httpStatus;

    /** @var bool */
    public $ambiguousBlocked;

    /** @var string Trusted SmartUCF application redirect when Process 1 succeeded. */
    public $redirectUrl;

    /** @var bool True when CP order id is known successful (even if SmartUCF failed). */
    public $cpSucceeded;

    /** @var string|null Customer-safe message override (Process 1 failures). */
    public $customerMessage;

    /**
     * @param bool $success
     * @param int $controlPanelOrderId
     * @param bool $localReplay
     * @param string|null $errorClass
     * @param bool $recoverable
     * @param int|null $httpStatus
     * @param bool $ambiguousBlocked
     * @param string $redirectUrl
     * @param bool $cpSucceeded
     * @param string|null $customerMessage
     */
    public function __construct(
        $success,
        $controlPanelOrderId = 0,
        $localReplay = false,
        $errorClass = null,
        $recoverable = false,
        $httpStatus = null,
        $ambiguousBlocked = false,
        $redirectUrl = '',
        $cpSucceeded = false,
        $customerMessage = null
    ) {
        $this->success = (bool) $success;
        $this->controlPanelOrderId = (int) $controlPanelOrderId;
        $this->localReplay = (bool) $localReplay;
        $this->errorClass = $errorClass;
        $this->recoverable = (bool) $recoverable;
        $this->httpStatus = $httpStatus === null ? null : (int) $httpStatus;
        $this->ambiguousBlocked = (bool) $ambiguousBlocked;
        $this->redirectUrl = (string) $redirectUrl;
        $this->cpSucceeded = (bool) $cpSucceeded;
        $this->customerMessage = $customerMessage;
    }

    /**
     * @param int $cpId
     * @param bool $localReplay
     * @param string $redirectUrl
     * @return self
     */
    public static function ok($cpId, $localReplay = false, $redirectUrl = '')
    {
        return new self(true, $cpId, $localReplay, null, false, null, false, $redirectUrl, true);
    }

    /**
     * @param string $errorClass
     * @param bool $recoverable
     * @param int|null $httpStatus
     * @param bool $ambiguousBlocked
     * @return self
     */
    public static function fail($errorClass, $recoverable, $httpStatus = null, $ambiguousBlocked = false)
    {
        return new self(false, 0, false, $errorClass, $recoverable, $httpStatus, $ambiguousBlocked, '', false);
    }

    /**
     * CP succeeded but Process 1 SmartUCF did not reach confirmed success.
     *
     * @param int $cpId
     * @param bool $localReplay
     * @param string $errorClass
     * @param bool $recoverable
     * @param bool $ambiguousBlocked
     * @param string|null $customerMessage
     * @return self
     */
    public static function failAfterCp(
        $cpId,
        $localReplay,
        $errorClass,
        $recoverable,
        $ambiguousBlocked = false,
        $customerMessage = null
    ) {
        return new self(
            false,
            (int) $cpId,
            (bool) $localReplay,
            $errorClass,
            $recoverable,
            null,
            $ambiguousBlocked,
            '',
            true,
            $customerMessage
        );
    }
}
