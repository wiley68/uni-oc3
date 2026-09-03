<?php

final class MtUniCreditCertificateSyncException extends RuntimeException
{
    const REASON_CP_UNAVAILABLE = 'cp_unavailable';
    const REASON_CP_TRANSPORT = 'cp_transport';
    const REASON_REFRESH_FAILED = 'refresh_failed';
    const REASON_INVALID_BUNDLE = 'invalid_bundle';
    const REASON_LOCAL_FS = 'local_fs';
    const REASON_PASSPHRASE_NOT_CONFIGURED = 'passphrase_not_configured';

    /** @var string */
    private $reason;

    /**
     * @param string $message
     * @param string $reason
     * @param Throwable|null $previous
     */
    public function __construct($message, $reason = self::REASON_REFRESH_FAILED, $previous = null)
    {
        parent::__construct((string) $message, 0, $previous instanceof Throwable ? $previous : null);
        $this->reason = (string) $reason;
    }

    /**
     * @return string
     */
    public function reason()
    {
        return $this->reason;
    }
}
