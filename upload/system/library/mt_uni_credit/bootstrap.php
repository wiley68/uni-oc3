<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'constants.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'health.php';

/**
 * Phase 1 bootstrap helpers for mt_uni_credit.
 */
final class MtUniCreditBootstrap
{
    /**
     * @return bool
     */
    public static function ensureLoaded()
    {
        return class_exists('MtUniCreditConstants', false)
            && class_exists('MtUniCreditHealth', false);
    }

    /**
     * Candidate protected roots for deployment secrets and certificates.
     *
     * @return array<int, string>
     */
    public static function protectedRootCandidates()
    {
        $candidates = array();

        if (defined('DIR_STORAGE')) {
            $candidates[] = rtrim(DIR_STORAGE, '/\\') . DIRECTORY_SEPARATOR . 'mt_uni_credit';
        }

        if (defined('DIR_SYSTEM')) {
            $candidates[] = dirname(DIR_SYSTEM) . DIRECTORY_SEPARATOR . 'mt_uni_credit';
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Resolve the first existing protected root or null when none is configured.
     *
     * @return string|null
     */
    public static function resolveProtectedRoot()
    {
        foreach (self::protectedRootCandidates() as $root) {
            if ($root !== '' && is_dir($root)) {
                return $root;
            }
        }

        return null;
    }

    /**
     * @param string|null $protectedRoot
     * @return array<string, string>
     */
    public static function deploymentRelativePaths($protectedRoot = null)
    {
        return array(
            'passphrase' => MtUniCreditConstants::RELATIVE_PASSPHRASE,
            'certificate' => MtUniCreditConstants::RELATIVE_CERTIFICATE,
            'private_key' => MtUniCreditConstants::RELATIVE_PRIVATE_KEY,
            'protected_root' => $protectedRoot === null ? '' : $protectedRoot,
        );
    }
}
