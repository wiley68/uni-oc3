<?php

/**
 * Immutable temporary cert/key snapshot used by one SmartUCF request.
 */
final class MtUniCreditCertificateConsumerLease
{
    /** @var string */
    private $directory;

    /** @var string */
    private $certificatePath;

    /** @var string */
    private $privateKeyPath;

    /** @var string */
    private $password;

    /** @var bool */
    private $released = false;

    public function __construct(string $directory, string $certificatePath, string $privateKeyPath, string $password)
    {
        $this->directory = (string) $directory;
        $this->certificatePath = (string) $certificatePath;
        $this->privateKeyPath = (string) $privateKeyPath;
        $this->password = (string) $password;
    }

    public function certificatePath()
    {
        return $this->certificatePath;
    }

    public function privateKeyPath()
    {
        return $this->privateKeyPath;
    }

    public function password()
    {
        return $this->password;
    }

    public function release()
    {
        if ($this->released) {
            return;
        }
        $this->released = true;

        if (is_file($this->certificatePath)) {
            @unlink($this->certificatePath);
        }
        if (is_file($this->privateKeyPath)) {
            @unlink($this->privateKeyPath);
        }
        if (is_dir($this->directory)) {
            @rmdir($this->directory);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
