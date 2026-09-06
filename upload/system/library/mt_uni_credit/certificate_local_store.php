<?php

/**
 * Filesystem store for SmartUCF certificate pair with atomic replacement.
 */
final class MtUniCreditCertificateLocalStore
{
    const LOCK_FILENAME = '.sync.lock';

    /** @var MtUniCreditCertificateLocalPaths */
    private $paths;

    /** @var MtUniCreditCertificatePairValidator */
    private $validator;

    /** @var MtUniCreditFileModeEnforcer */
    private $modes;

    /**
     * @param MtUniCreditCertificateLocalPaths|null $paths
     * @param MtUniCreditCertificatePairValidator|null $validator
     * @param MtUniCreditFileModeEnforcer|null $modes
     */
    public function __construct($paths = null, $validator = null, $modes = null)
    {
        $this->paths = $paths instanceof MtUniCreditCertificateLocalPaths
            ? $paths
            : new MtUniCreditCertificateLocalPaths();
        $this->validator = $validator instanceof MtUniCreditCertificatePairValidator
            ? $validator
            : new MtUniCreditCertificatePairValidator();
        $this->modes = $modes instanceof MtUniCreditFileModeEnforcer
            ? $modes
            : new MtUniCreditFileModeEnforcer();
    }

    public function keysDirectory()
    {
        return $this->paths->keysDirectory();
    }

    public function certificatePath()
    {
        return $this->paths->certificatePath();
    }

    public function privateKeyPath()
    {
        return $this->paths->privateKeyPath();
    }

    public function ensureProtectionFiles()
    {
        $directory = $this->keysDirectory();
        $this->modes->ensureDirectory($directory, MtUniCreditFileModeEnforcer::MODE_KEYS_DIR);

        $htaccess = $directory . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($htaccess)) {
            $written = @file_put_contents(
                $htaccess,
                "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
                    . "<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n"
            );
            if ($written === false) {
                throw new MtUniCreditCertificateSyncException(
                    'The certificate directory protection file could not be created.',
                    MtUniCreditCertificateSyncException::REASON_LOCAL_FS
                );
            }
        }

        $index = $directory . DIRECTORY_SEPARATOR . 'index.php';
        if (!is_file($index) && @file_put_contents($index, "<?php\nhttp_response_code(403);\nexit;\n") === false) {
            throw new MtUniCreditCertificateSyncException(
                'The certificate directory index protection could not be created.',
                MtUniCreditCertificateSyncException::REASON_LOCAL_FS
            );
        }
    }

    public function assertWritableStore()
    {
        $this->ensureProtectionFiles();
        $directory = $this->keysDirectory();
        if (!is_writable($directory)) {
            throw new MtUniCreditCertificateSyncException(
                'The certificate keys directory is not writable.',
                MtUniCreditCertificateSyncException::REASON_LOCAL_FS
            );
        }
    }

    public function readPairBytes()
    {
        return $this->paths->readPairBytes();
    }

    /**
     * @param string $passphrase
     * @return array{certificate_sha256:string,private_key_sha256:string,not_after:string}|null
     */
    public function validateLocalPair($passphrase)
    {
        $pair = $this->readPairBytes();
        if ($pair === null) {
            return null;
        }
        try {
            $validated = $this->validator->validatePemPair(
                (string) $pair['certificate_pem'],
                (string) $pair['private_key_pem'],
                (string) $passphrase
            );
        } catch (Throwable $exception) {
            return null;
        }

        return array(
            'certificate_sha256' => $this->validator->sha256((string) $pair['certificate_pem']),
            'private_key_sha256' => $this->validator->sha256((string) $pair['private_key_pem']),
            'not_after' => (string) $validated['not_after'],
        );
    }

    /**
     * @param string $certificatePem
     * @param string $privateKeyPem
     * @param array<string, mixed> $metadata
     * @param string $passphrase
     * @return void
     */
    public function replacePair($certificatePem, $privateKeyPem, array $metadata, $passphrase)
    {
        $this->ensureProtectionFiles();

        try {
            $validated = $this->validator->validatePemPair(
                (string) $certificatePem,
                (string) $privateKeyPem,
                (string) $passphrase
            );
        } catch (Throwable $exception) {
            throw new MtUniCreditCertificateSyncException(
                'The certificate pair could not be validated.',
                MtUniCreditCertificateSyncException::REASON_INVALID_BUNDLE,
                $exception
            );
        }

        $directory = $this->keysDirectory();
        $incoming = $directory . DIRECTORY_SEPARATOR . '.incoming';
        // Sensitive staging/update work uses 0700 (not the permanent keys 0770 contract).
        $this->modes->ensureDirectory($incoming, MtUniCreditFileModeEnforcer::MODE_STAGING_DIR);

        $suffix = bin2hex(random_bytes(8));
        $stageCert = $incoming . DIRECTORY_SEPARATOR . 'certificate-' . $suffix . '.pem';
        $stageKey = $incoming . DIRECTORY_SEPARATOR . 'private-key-' . $suffix . '.pem';
        $backupCert = $incoming . DIRECTORY_SEPARATOR . 'certificate-backup-' . $suffix . '.pem';
        $backupKey = $incoming . DIRECTORY_SEPARATOR . 'private-key-backup-' . $suffix . '.pem';
        $certPath = $this->certificatePath();
        $keyPath = $this->privateKeyPath();
        $hadCert = is_file($certPath);
        $hadKey = is_file($keyPath);
        $published = false;

        try {
            if ($hadCert && !@copy($certPath, $backupCert)) {
                throw new MtUniCreditCertificateSyncException(
                    'The existing certificate could not be backed up.',
                    MtUniCreditCertificateSyncException::REASON_LOCAL_FS
                );
            }
            if ($hadCert) {
                $this->modes->applyAndVerify($backupCert, MtUniCreditFileModeEnforcer::MODE_CERTIFICATE);
            }

            if ($hadKey && !@copy($keyPath, $backupKey)) {
                throw new MtUniCreditCertificateSyncException(
                    'The existing private key could not be backed up.',
                    MtUniCreditCertificateSyncException::REASON_LOCAL_FS
                );
            }
            if ($hadKey) {
                // Central F-004-01 control: backup private key must be 0600 before continuing.
                $this->modes->applyAndVerify($backupKey, MtUniCreditFileModeEnforcer::MODE_PRIVATE_KEY);
            }

            if (
                @file_put_contents($stageCert, (string) $validated['certificate_pem'], LOCK_EX) === false
                || @file_put_contents($stageKey, (string) $validated['private_key_pem'], LOCK_EX) === false
            ) {
                throw new MtUniCreditCertificateSyncException(
                    'The staged certificate pair could not be written.',
                    MtUniCreditCertificateSyncException::REASON_LOCAL_FS
                );
            }
            $this->modes->applyAndVerify($stageCert, MtUniCreditFileModeEnforcer::MODE_CERTIFICATE);
            $this->modes->applyAndVerify($stageKey, MtUniCreditFileModeEnforcer::MODE_PRIVATE_KEY);

            if (!@rename($stageCert, $certPath) || !@rename($stageKey, $keyPath)) {
                throw new MtUniCreditCertificateSyncException(
                    'The staged certificate pair could not be promoted.',
                    MtUniCreditCertificateSyncException::REASON_LOCAL_FS
                );
            }

            $this->modes->applyAndVerify($certPath, MtUniCreditFileModeEnforcer::MODE_CERTIFICATE);
            $this->modes->applyAndVerify($keyPath, MtUniCreditFileModeEnforcer::MODE_PRIVATE_KEY);

            $pair = $this->readPairBytes();
            if (
                $pair === null
                || (string) $pair['certificate_pem'] !== (string) $validated['certificate_pem']
                || (string) $pair['private_key_pem'] !== (string) $validated['private_key_pem']
            ) {
                throw new MtUniCreditCertificateSyncException(
                    'The promoted certificate pair is inconsistent.',
                    MtUniCreditCertificateSyncException::REASON_LOCAL_FS
                );
            }

            $published = true;
        } catch (Throwable $exception) {
            $this->restore($backupCert, $certPath, $hadCert, MtUniCreditFileModeEnforcer::MODE_CERTIFICATE);
            $this->restore($backupKey, $keyPath, $hadKey, MtUniCreditFileModeEnforcer::MODE_PRIVATE_KEY);
            if ($exception instanceof MtUniCreditCertificateSyncException) {
                throw $exception;
            }
            throw new MtUniCreditCertificateSyncException(
                'Certificate pair replacement failed.',
                MtUniCreditCertificateSyncException::REASON_LOCAL_FS,
                $exception
            );
        } finally {
            $this->cleanupStagingArtifacts(
                array(
                    $stageKey => MtUniCreditFileModeEnforcer::MODE_PRIVATE_KEY,
                    $backupKey => MtUniCreditFileModeEnforcer::MODE_PRIVATE_KEY,
                    $stageCert => MtUniCreditFileModeEnforcer::MODE_CERTIFICATE,
                    $backupCert => MtUniCreditFileModeEnforcer::MODE_CERTIFICATE,
                ),
                $incoming,
                $published
            );
        }
    }

    /**
     * @param string $passphrase
     * @return MtUniCreditCertificateConsumerLease
     */
    public function createConsumerPairLease($passphrase)
    {
        $pair = $this->readPairBytes();
        if ($pair === null) {
            throw new MtUniCreditCertificateSyncException(
                'The local certificate pair is missing or unreadable.',
                MtUniCreditCertificateSyncException::REASON_LOCAL_FS
            );
        }

        try {
            $this->validator->validatePemPair(
                (string) $pair['certificate_pem'],
                (string) $pair['private_key_pem'],
                (string) $passphrase
            );
        } catch (Throwable $exception) {
            throw new MtUniCreditCertificateSyncException(
                'The local certificate pair is not usable.',
                MtUniCreditCertificateSyncException::REASON_LOCAL_FS,
                $exception
            );
        }

        $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'mt-uni-credit-ssl-' . bin2hex(random_bytes(8));
        $this->modes->ensureDirectory($directory, MtUniCreditFileModeEnforcer::MODE_LEASE_DIR);

        $certificatePath = $directory . DIRECTORY_SEPARATOR . 'certificate.pem';
        $privateKeyPath = $directory . DIRECTORY_SEPARATOR . 'private_key.pem';
        try {
            if (
                @file_put_contents($certificatePath, (string) $pair['certificate_pem'], LOCK_EX) === false
                || @file_put_contents($privateKeyPath, (string) $pair['private_key_pem'], LOCK_EX) === false
            ) {
                throw new MtUniCreditCertificateSyncException(
                    'The certificate lease files could not be written.',
                    MtUniCreditCertificateSyncException::REASON_LOCAL_FS
                );
            }
            $this->modes->applyAndVerify($certificatePath, MtUniCreditFileModeEnforcer::MODE_LEASE_FILE);
            $this->modes->applyAndVerify($privateKeyPath, MtUniCreditFileModeEnforcer::MODE_LEASE_FILE);
        } catch (Throwable $exception) {
            @unlink($certificatePath);
            @unlink($privateKeyPath);
            @rmdir($directory);
            throw $exception;
        }

        return new MtUniCreditCertificateConsumerLease($directory, $certificatePath, $privateKeyPath, (string) $passphrase);
    }

    public function withExclusiveLock(callable $callback)
    {
        return $this->withLock(LOCK_EX, $callback);
    }

    public function withSharedLock(callable $callback)
    {
        return $this->withLock(LOCK_SH, $callback);
    }

    /**
     * @param int $mode
     * @param callable $callback
     * @return mixed
     */
    private function withLock($mode, callable $callback)
    {
        $this->ensureProtectionFiles();
        $lockFile = $this->keysDirectory() . DIRECTORY_SEPARATOR . self::LOCK_FILENAME;
        $handle = @fopen($lockFile, 'c+');
        if ($handle === false) {
            throw new MtUniCreditCertificateSyncException(
                'The certificate sync lock could not be opened.',
                MtUniCreditCertificateSyncException::REASON_LOCAL_FS
            );
        }

        $deadline = microtime(true) + 15.0;
        $locked = false;
        while (microtime(true) < $deadline) {
            if (flock($handle, $mode | LOCK_NB)) {
                $locked = true;
                break;
            }
            usleep(50000);
        }
        if (!$locked) {
            fclose($handle);
            throw new MtUniCreditCertificateSyncException(
                'The certificate sync lock timed out.',
                MtUniCreditCertificateSyncException::REASON_LOCAL_FS
            );
        }

        try {
            return call_user_func($callback);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param string $backup
     * @param string $destination
     * @param bool $existed
     * @param int $mode
     * @return void
     */
    private function restore($backup, $destination, $existed, $mode)
    {
        if ($existed && is_file($backup)) {
            if (!@copy($backup, $destination)) {
                return;
            }
            try {
                $this->modes->applyAndVerify($destination, (int) $mode);
            } catch (Throwable $ignored) {
                // Best-effort custody after rollback copy.
            }

            return;
        }
        if (!$existed) {
            @unlink($destination);
        }
    }

    /**
     * Protect then remove staging residue. Does not corrupt an already-published active pair.
     *
     * @param array<string, int> $pathsToModes
     * @param string $incomingDir
     * @param bool $published
     * @return void
     */
    private function cleanupStagingArtifacts(array $pathsToModes, $incomingDir, $published)
    {
        foreach ($pathsToModes as $path => $mode) {
            if (!is_file($path)) {
                continue;
            }
            try {
                $this->modes->applyAndVerify($path, (int) $mode);
            } catch (Throwable $ignored) {
                // Still attempt unlink; residue may remain but we tried to tighten first.
            }
            @unlink($path);
            if (is_file($path)) {
                // Residue remains — must stay mode-protected for private-key paths.
                try {
                    $this->modes->applyAndVerify($path, (int) $mode);
                } catch (Throwable $ignored) {
                }
            }
        }

        if (is_dir($incomingDir)) {
            try {
                $this->modes->applyAndVerify($incomingDir, MtUniCreditFileModeEnforcer::MODE_STAGING_DIR);
            } catch (Throwable $ignored) {
            }
            @rmdir($incomingDir);
        }

        // Publication success is independent of cleanup; do not throw here and undo auth state.
        unset($published);
    }
}
