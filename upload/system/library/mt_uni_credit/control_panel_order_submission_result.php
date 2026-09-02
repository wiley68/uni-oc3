<?php

/**
 * Result of a CP order create/resume attempt.
 */
final class MtUniCreditControlPanelOrderSubmissionResult
{
    /** @var bool */
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

    /**
     * @param bool $success
     * @param int $controlPanelOrderId
     * @param bool $localReplay
     * @param string|null $errorClass
     * @param bool $recoverable
     * @param int|null $httpStatus
     * @param bool $ambiguousBlocked
     */
    public function __construct(
        $success,
        $controlPanelOrderId = 0,
        $localReplay = false,
        $errorClass = null,
        $recoverable = false,
        $httpStatus = null,
        $ambiguousBlocked = false
    ) {
        $this->success = (bool) $success;
        $this->controlPanelOrderId = (int) $controlPanelOrderId;
        $this->localReplay = (bool) $localReplay;
        $this->errorClass = $errorClass;
        $this->recoverable = (bool) $recoverable;
        $this->httpStatus = $httpStatus === null ? null : (int) $httpStatus;
        $this->ambiguousBlocked = (bool) $ambiguousBlocked;
    }

    /**
     * @param int $cpId
     * @param bool $localReplay
     * @return self
     */
    public static function ok($cpId, $localReplay = false)
    {
        return new self(true, $cpId, $localReplay);
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
        return new self(false, 0, false, $errorClass, $recoverable, $httpStatus, $ambiguousBlocked);
    }
}
