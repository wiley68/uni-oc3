<?php

/**
 * Deterministic filesystem paths for manually deployed certificate material.
 *
 * Prefers the protected deployment root (keys/ + secrets/) when available.
 */
final class MtUniCreditCertificateLocalPaths
{
    const CERT_FILENAME = 'avalon_cert.pem';

    const KEY_FILENAME = 'avalon_private_key.pem';

    const RELATIVE_KEYS_DIR = 'keys';

    /** @var string */
    private $extensionRoot;

    /**
     * @param callable|string|null $extensionRootResolver Callable returning root, root path string, or null for protected root
     */
    public function __construct($extensionRootResolver = null)
    {
        if ($extensionRootResolver === null) {
            $root = MtUniCreditBootstrap::resolveProtectedRoot();
            if ($root === null || $root === '') {
                $root = MtUniCreditExtensionRoot::path();
            }
        } elseif (is_callable($extensionRootResolver)) {
            $root = call_user_func($extensionRootResolver);
        } else {
            $root = $extensionRootResolver;
        }
        $this->extensionRoot = rtrim((string) $root, '/\\');
    }

    /**
     * @return string
     */
    public function extensionRoot()
    {
        return $this->extensionRoot;
    }

    /**
     * @return string
     */
    public function keysDirectory()
    {
        return $this->extensionRoot
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::RELATIVE_KEYS_DIR);
    }

    /**
     * @return string
     */
    public function certificatePath()
    {
        return $this->extensionRoot
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, MtUniCreditConstants::RELATIVE_CERTIFICATE);
    }

    /**
     * @return string
     */
    public function privateKeyPath()
    {
        return $this->extensionRoot
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, MtUniCreditConstants::RELATIVE_PRIVATE_KEY);
    }

    /**
     * @return string
     */
    public function passphrasePath()
    {
        return $this->extensionRoot
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, MtUniCreditConstants::RELATIVE_PASSPHRASE);
    }

    /**
     * @return string
     */
    public function certificateRelativePath()
    {
        return self::RELATIVE_KEYS_DIR . '/' . self::CERT_FILENAME;
    }

    /**
     * @return string
     */
    public function privateKeyRelativePath()
    {
        return self::RELATIVE_KEYS_DIR . '/' . self::KEY_FILENAME;
    }

    /**
     * @return array{certificate_pem: string, private_key_pem: string}|null
     */
    public function readPairBytes()
    {
        $certPath = $this->certificatePath();
        $keyPath = $this->privateKeyPath();
        if (!is_file($certPath) || !is_file($keyPath) || !is_readable($certPath) || !is_readable($keyPath)) {
            return null;
        }

        $cert = file_get_contents($certPath);
        $key = file_get_contents($keyPath);
        if (!is_string($cert) || !is_string($key) || trim($cert) === '' || trim($key) === '') {
            return null;
        }

        return array(
            'certificate_pem' => $cert,
            'private_key_pem' => $key,
        );
    }
}
