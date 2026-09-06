<?php

/**
 * Apply and verify Unix permission bits for certificate custody artifacts.
 *
 * Effective mode must be a subset of the allowed mask (stricter modes OK).
 * Example: allowed 0600 accepts 0600 or 0400; rejects 0644/0666/0640.
 */
final class MtUniCreditFileModeEnforcer
{
    const MODE_PRIVATE_KEY = 0600;

    const MODE_CERTIFICATE = 0640;

    const MODE_STAGING_DIR = 0700;

    const MODE_KEYS_DIR = 0770;

    const MODE_LEASE_DIR = 0700;

    const MODE_LEASE_FILE = 0600;

    /** @var callable|null function(string $path, int $mode): bool */
    private $chmodFn;

    /** @var callable|null function(string $path): int|false */
    private $filepermsFn;

    /** @var bool|null */
    private $posixModesCached;

    /**
     * @param callable|null $chmodFn
     * @param callable|null $filepermsFn
     */
    public function __construct($chmodFn = null, $filepermsFn = null)
    {
        $this->chmodFn = is_callable($chmodFn) ? $chmodFn : null;
        $this->filepermsFn = is_callable($filepermsFn) ? $filepermsFn : null;
    }

    /**
     * @param string $path
     * @param int $allowedMode
     * @return void
     */
    public function applyAndVerify($path, $allowedMode)
    {
        $path = (string) $path;
        $allowedMode = ((int) $allowedMode) & 0777;

        if ($path === '') {
            throw new MtUniCreditCertificateSyncException(
                'A required filesystem permission path is missing.',
                MtUniCreditCertificateSyncException::REASON_LOCAL_FS
            );
        }

        if ($this->filepermsFn === null && !is_file($path) && !is_dir($path)) {
            throw new MtUniCreditCertificateSyncException(
                'A required filesystem permission path is missing.',
                MtUniCreditCertificateSyncException::REASON_LOCAL_FS
            );
        }

        $chmod = $this->chmodFn !== null
            ? $this->chmodFn
            : function ($chmodPath, $mode) {
                return @chmod($chmodPath, $mode);
            };

        if (!call_user_func($chmod, $path, $allowedMode)) {
            throw new MtUniCreditCertificateSyncException(
                'A required filesystem permission could not be applied.',
                MtUniCreditCertificateSyncException::REASON_LOCAL_FS
            );
        }

        if (!$this->canVerifyEffectiveModes()) {
            // Non-POSIX hosts (typical Windows PHP) cannot expose Unix mode bits via fileperms().
            // chmod() was still requested; production target remains Linux/Unix custody.
            return;
        }

        $effective = $this->readModeBits($path);
        if (($effective & ~$allowedMode) !== 0) {
            throw new MtUniCreditCertificateSyncException(
                'A required filesystem permission could not be verified.',
                MtUniCreditCertificateSyncException::REASON_LOCAL_FS
            );
        }
    }

    /**
     * Create directory if needed, then enforce allowed mode (tighten existing dirs).
     *
     * @param string $path
     * @param int $allowedMode
     * @return void
     */
    public function ensureDirectory($path, $allowedMode)
    {
        $path = (string) $path;
        $allowedMode = ((int) $allowedMode) & 0777;

        if (!is_dir($path)) {
            if (!@mkdir($path, $allowedMode, true) && !is_dir($path)) {
                throw new MtUniCreditCertificateSyncException(
                    'A protected directory could not be created.',
                    MtUniCreditCertificateSyncException::REASON_LOCAL_FS
                );
            }
        }

        $this->applyAndVerify($path, $allowedMode);
    }

    /**
     * @param string $path
     * @return int mode bits 0-0777
     */
    public function readModeBits($path)
    {
        if ($this->filepermsFn !== null) {
            $raw = call_user_func($this->filepermsFn, $path);
        } else {
            clearstatcache(true, (string) $path);
            $raw = @fileperms((string) $path);
        }

        if ($raw === false || $raw === null) {
            throw new MtUniCreditCertificateSyncException(
                'A required filesystem permission could not be read.',
                MtUniCreditCertificateSyncException::REASON_LOCAL_FS
            );
        }

        return ((int) $raw) & 0777;
    }

    /**
     * @return bool
     */
    public function canVerifyEffectiveModes()
    {
        if ($this->chmodFn !== null || $this->filepermsFn !== null) {
            // Test doubles always participate in verify semantics.
            return true;
        }

        if ($this->posixModesCached !== null) {
            return $this->posixModesCached;
        }

        $probe = tempnam(sys_get_temp_dir(), 'mtucmode');
        if ($probe === false) {
            $this->posixModesCached = false;

            return false;
        }

        @file_put_contents($probe, '1');
        @chmod($probe, 0600);
        clearstatcache(true, $probe);
        $bits = @fileperms($probe);
        @unlink($probe);

        $this->posixModesCached = is_int($bits) && ((($bits & 0777) & 0077) === 0);

        return $this->posixModesCached;
    }
}
