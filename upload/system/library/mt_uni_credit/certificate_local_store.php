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

    public function __construct($paths = null, $validator = null)
    {
        $this->paths = $paths instanceof MtUniCreditCertificateLocalPaths
            ? $paths
            : new MtUniCreditCertificateLocalPaths();
        $this->validator = $validator instanceof MtUniCreditCertificatePairValidator
            ? $validator
            : new MtUniCreditCertificatePairValidator();
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
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new MtUniCreditCertificateSyncException(
                'The certificate keys directory could not be created.',
                MtUniCreditCertificateSyncException::REASON_LOCAL_FS
            );
        }
        @chmod($directory, 0770);

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

    public function replacePair(string $certificatePem, string $privateKeyPem, array $metadata, string $passphrase)
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
        if (!is_dir($incoming) && !@mkdir($incoming, 0770, true) && !is_dir($incoming)) {
            throw new MtUniCreditCertificateSyncException(
                'The certificate staging directory could not be created.',
                MtUniCreditCertificateSyncException::REASON_LOCAL_FS
            );
        }

        $suffix = bin2hex(random_bytes(8));
        $stageCert = $incoming . DIRECTORY_SEPARATOR . 'certificate-' . $suffix . '.pem';
        $stageKey = $incoming . DIRECTORY_SEPARATOR . 'private-key-' . $suffix . '.pem';
        $backupCert = $incoming . DIRECTORY_SEPARATOR . 'certificate-backup-' . $suffix . '.pem';
        $backupKey = $incoming . DIRECTORY_SEPARATOR . 'private-key-backup-' . $suffix . '.pem';
        $certPath = $this->certificatePath();
        $keyPath = $this->privateKeyPath();
        $hadCert = is_file($certPath);
        $hadKey = is_file($keyPath);

        try {
            if ($hadCert && !@copy($certPath, $backupCert)) {
                throw new MtUniCreditCertificateSyncException(
                    'The existing certificate could not be backed up.',
                    MtUniCreditCertificateSyncException::REASON_LOCAL_FS
                );
            }
            if ($hadKey && !@copy($keyPath, $backupKey)) {
                throw new MtUniCreditCertificateSyncException(
                    'The existing private key could not be backed up.',
                    MtUniCreditCertificateSyncException::REASON_LOCAL_FS
                );
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
            @chmod($stageCert, 0640);
            @chmod($stageKey, 0600);

            if (!@rename($stageCert, $certPath) || !@rename($stageKey, $keyPath)) {
                throw new MtUniCreditCertificateSyncException(
                    'The staged certificate pair could not be promoted.',
                    MtUniCreditCertificateSyncException::REASON_LOCAL_FS
                );
            }
            @chmod($certPath, 0640);
            @chmod($keyPath, 0600);
        } catch (Throwable $exception) {
            $this->restore($backupCert, $certPath, $hadCert);
            $this->restore($backupKey, $keyPath, $hadKey);
            if ($exception instanceof MtUniCreditCertificateSyncException) {
                throw $exception;
            }
            throw new MtUniCreditCertificateSyncException(
                'Certificate pair replacement failed.',
                MtUniCreditCertificateSyncException::REASON_LOCAL_FS,
                $exception
            );
        } finally {
            @unlink($stageCert);
            @unlink($stageKey);
            @unlink($backupCert);
            @unlink($backupKey);
            @rmdir($incoming);
        }
    }

    public function createConsumerPairLease(string $passphrase)
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
        if (!@mkdir($directory, 0700) && !is_dir($directory)) {
            throw new MtUniCreditCertificateSyncException(
                'The certificate lease directory could not be created.',
                MtUniCreditCertificateSyncException::REASON_LOCAL_FS
            );
        }

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
            @chmod($certificatePath, 0600);
            @chmod($privateKeyPath, 0600);
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

    private function withLock(int $mode, callable $callback)
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

    private function restore(string $backup, string $destination, bool $existed)
    {
        if ($existed && is_file($backup)) {
            @copy($backup, $destination);

            return;
        }
        if (!$existed) {
            @unlink($destination);
        }
    }
}
