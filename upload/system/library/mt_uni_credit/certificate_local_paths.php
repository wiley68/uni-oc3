<?php

/**
 * Deterministic filesystem paths for manually deployed certificate material.
 *
 * Prefers the protected deployment root (keys/ + secrets/) when available.
 */
final class MtUniCreditCertificateLocalPaths
{
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
}
