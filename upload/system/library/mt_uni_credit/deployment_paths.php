<?php

/**
 * Safe resolution of deployment secret/certificate relative paths.
 *
 * @see docs/CONTRACTS.md DEPLOY-001, DEPLOY-003
 */
final class MtUniCreditDeploymentPaths
{
    /**
     * Resolve an absolute path under the protected root; rejects traversal.
     *
     * @param string $protectedRoot Absolute directory path
     * @param string $relativePath Relative path from DEPLOY-001
     * @return string|null Absolute path when safe, null when invalid
     */
    public static function resolveUnderRoot($protectedRoot, $relativePath)
    {
        $root = self::normalizeDirectory($protectedRoot);
        if ($root === '') {
            return null;
        }

        $relative = self::normalizeRelative($relativePath);
        if ($relative === null) {
            return null;
        }

        $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $resolvedRoot = realpath($root);
        if ($resolvedRoot === false) {
            $resolvedRoot = $root;
        }

        $resolvedCandidate = realpath($candidate);
        if ($resolvedCandidate !== false) {
            if (strncmp($resolvedCandidate, $resolvedRoot, strlen($resolvedRoot)) !== 0) {
                return null;
            }

            return $resolvedCandidate;
        }

        $parent = dirname($candidate);
        $resolvedParent = realpath($parent);
        if ($resolvedParent !== false && strncmp($resolvedParent, $resolvedRoot, strlen($resolvedRoot)) !== 0) {
            return null;
        }

        return $candidate;
    }

    /**
     * @param string|null $protectedRoot
     * @return array<string, string>
     */
    public static function materialPaths($protectedRoot = null)
    {
        if ($protectedRoot === null) {
            $protectedRoot = MtUniCreditBootstrap::resolveProtectedRoot();
        }

        $paths = array(
            'passphrase' => MtUniCreditConstants::RELATIVE_PASSPHRASE,
            'certificate' => MtUniCreditConstants::RELATIVE_CERTIFICATE,
            'private_key' => MtUniCreditConstants::RELATIVE_PRIVATE_KEY,
        );

        if ($protectedRoot === null || $protectedRoot === '') {
            return $paths;
        }

        foreach ($paths as $key => $relative) {
            $resolved = self::resolveUnderRoot($protectedRoot, $relative);
            $paths[$key . '_absolute'] = $resolved !== null ? $resolved : '';
        }

        return $paths;
    }

    /**
     * @param string $directory
     * @return string
     */
    private static function normalizeDirectory($directory)
    {
        return rtrim(str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, (string) $directory), DIRECTORY_SEPARATOR);
    }

    /**
     * @param string $relativePath
     * @return string|null
     */
    private static function normalizeRelative($relativePath)
    {
        $relative = str_replace('\\', '/', trim((string) $relativePath));
        if ($relative === '' || $relative[0] === '/') {
            return null;
        }

        $parts = explode('/', $relative);
        $normalized = array();
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return null;
            }
            $normalized[] = $part;
        }

        return implode('/', $normalized);
    }
}
