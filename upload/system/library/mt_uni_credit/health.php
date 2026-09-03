<?php

/**
 * Phase 1 admin health/readiness evaluation (offline, no secret exposure).
 */
final class MtUniCreditHealth
{
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function evaluate(array $context = array())
    {
        $moduleVersion = MtUniCreditConstants::VERSION;
        $phpVersion = PHP_VERSION;
        $phpOk = PHP_VERSION_ID >= MtUniCreditConstants::PHP_FLOOR_ID;

        $secretConfigured = !empty($context['secret_configured']);
        $unicidConfigured = trim((string) (isset($context['unicid']) ? $context['unicid'] : '')) !== '';

        $protectedRoot = isset($context['protected_root']) ? $context['protected_root'] : MtUniCreditBootstrap::resolveProtectedRoot();
        $paths = MtUniCreditBootstrap::deploymentRelativePaths($protectedRoot);
        $material = MtUniCreditDeploymentPaths::materialPaths($protectedRoot);
        $keysDir = isset($material['certificate_absolute']) ? dirname((string) $material['certificate_absolute']) : '';
        $keysWritable = $keysDir !== '' && is_dir($keysDir) && is_writable($keysDir);
        $passphrasePath = isset($material['passphrase_absolute']) && $material['passphrase_absolute'] !== ''
            ? (string) $material['passphrase_absolute']
            : (isset($paths['protected_root']) && $paths['protected_root'] !== ''
                ? rtrim((string) $paths['protected_root'], '/\\') . DIRECTORY_SEPARATOR . MtUniCreditConstants::RELATIVE_PASSPHRASE
                : MtUniCreditExtensionRoot::path() . DIRECTORY_SEPARATOR . MtUniCreditConstants::RELATIVE_PASSPHRASE);
        $passphrases = new MtUniCreditMtlsPrivateKeyPassphraseProvider();
        $passphraseConfigured = true;
        try {
            $passphrases->requirePassphrase($passphrasePath);
        } catch (Exception $exception) {
            $passphraseConfigured = false;
        }
        $pairValid = false;
        if (
            isset($material['certificate_absolute']) && isset($material['private_key_absolute'])
            && $material['certificate_absolute'] !== '' && $material['private_key_absolute'] !== '' && $passphraseConfigured
        ) {
            try {
                $validator = new MtUniCreditCertificatePairValidator();
                $validation = $validator->validate(
                    (string) $material['certificate_absolute'],
                    (string) $material['private_key_absolute'],
                    $passphrases->requirePassphrase($passphrasePath)
                );
                $pairValid = !empty($validation['ok']);
            } catch (Exception $exception) {
                $pairValid = false;
            }
        }

        $checks = array(
            self::buildCheck(
                'module_identity',
                MtUniCreditConstants::HEALTH_READY,
                MtUniCreditConstants::EXTENSION_CODE . ' v' . $moduleVersion
            ),
            self::buildCheck(
                'php_version',
                $phpOk ? MtUniCreditConstants::HEALTH_READY : MtUniCreditConstants::HEALTH_UNAVAILABLE,
                $phpVersion . ' (floor ' . MtUniCreditConstants::PHP_FLOOR . '+)'
            ),
            self::buildCheck(
                'php_extension_curl',
                extension_loaded('curl') ? MtUniCreditConstants::HEALTH_READY : MtUniCreditConstants::HEALTH_UNAVAILABLE,
                extension_loaded('curl') ? 'loaded' : 'missing'
            ),
            self::buildCheck(
                'php_extension_openssl',
                extension_loaded('openssl') ? MtUniCreditConstants::HEALTH_READY : MtUniCreditConstants::HEALTH_UNAVAILABLE,
                extension_loaded('openssl') ? 'loaded' : 'missing'
            ),
            self::buildCheck(
                'php_extension_json',
                extension_loaded('json') ? MtUniCreditConstants::HEALTH_READY : MtUniCreditConstants::HEALTH_UNAVAILABLE,
                extension_loaded('json') ? 'loaded' : 'missing'
            ),
            self::buildCheck(
                'php_feature_hash_hmac',
                function_exists('hash_hmac') ? MtUniCreditConstants::HEALTH_READY : MtUniCreditConstants::HEALTH_UNAVAILABLE,
                function_exists('hash_hmac') ? 'available' : 'missing'
            ),
            self::buildCheck(
                'protected_root',
                $protectedRoot ? MtUniCreditConstants::HEALTH_READY : MtUniCreditConstants::HEALTH_NOT_CONFIGURED,
                $protectedRoot ? $protectedRoot : 'not resolved (deployment verification)'
            ),
            self::buildCheck(
                'passphrase_file',
                self::filePresenceStatus($protectedRoot, MtUniCreditConstants::RELATIVE_PASSPHRASE),
                self::filePresenceDetail($protectedRoot, MtUniCreditConstants::RELATIVE_PASSPHRASE)
            ),
            self::buildCheck(
                'passphrase_configured',
                $passphraseConfigured ? MtUniCreditConstants::HEALTH_READY : MtUniCreditConstants::HEALTH_WARNING,
                $passphraseConfigured ? 'configured' : 'missing/invalid'
            ),
            self::buildCheck(
                'certificate_file',
                self::filePresenceStatus($protectedRoot, MtUniCreditConstants::RELATIVE_CERTIFICATE),
                self::filePresenceDetail($protectedRoot, MtUniCreditConstants::RELATIVE_CERTIFICATE)
            ),
            self::buildCheck(
                'private_key_file',
                self::filePresenceStatus($protectedRoot, MtUniCreditConstants::RELATIVE_PRIVATE_KEY),
                self::filePresenceDetail($protectedRoot, MtUniCreditConstants::RELATIVE_PRIVATE_KEY)
            ),
            self::buildCheck(
                'keys_directory_writable',
                $keysWritable ? MtUniCreditConstants::HEALTH_READY : MtUniCreditConstants::HEALTH_WARNING,
                $keysWritable ? 'writable' : 'not writable or missing'
            ),
            self::buildCheck(
                'certificate_pair_valid',
                $pairValid ? MtUniCreditConstants::HEALTH_READY : MtUniCreditConstants::HEALTH_WARNING,
                $pairValid ? 'valid' : 'not validated'
            ),
            self::buildCheck(
                'cp_credentials',
                $unicidConfigured ? MtUniCreditConstants::HEALTH_WARNING : MtUniCreditConstants::HEALTH_NOT_CONFIGURED,
                $unicidConfigured ? 'UNICID present; CP connectivity Phase 4' : 'UNICID not configured'
            ),
            self::buildCheck(
                'cp_secret_storage',
                MtUniCreditConstants::HEALTH_FUTURE_PHASE,
                $secretConfigured ? 'stored secret marker present; encryption verify Phase 2' : 'secure persistence requires Phase 2'
            ),
            self::buildCheck(
                'settings_encryption',
                MtUniCreditConstants::HEALTH_FUTURE_PHASE,
                'HKDF/AES-256-GCM (Phase 2)'
            ),
            self::buildCheck(
                'cp_client',
                MtUniCreditConstants::HEALTH_FUTURE_PHASE,
                'Outbound CP client (Phase 4)'
            ),
            self::buildCheck(
                'inbound_api',
                MtUniCreditConstants::HEALTH_FUTURE_PHASE,
                'Authenticated inbound callbacks (Phase 6)'
            ),
            self::buildCheck(
                'smartucf',
                MtUniCreditConstants::HEALTH_FUTURE_PHASE,
                'Process 1 SmartUCF (Phase 9)'
            ),
            self::buildCheck(
                'storefront',
                MtUniCreditConstants::HEALTH_FUTURE_PHASE,
                'Product/Cart/Checkout financing (Phase 5+)'
            ),
        );

        foreach ($checks as $check) {
            self::assertNoSecretLeak(isset($check['detail']) ? (string) $check['detail'] : '');
        }

        return array(
            'summary' => self::summarize($checks, $phpOk),
            'checks' => $checks,
            'paths' => $paths,
            'secret_configured' => $secretConfigured,
        );
    }

    /**
     * @param array<int, array<string, string>> $checks
     * @param bool $phpOk
     * @return array<string, string>
     */
    private static function summarize(array $checks, $phpOk)
    {
        $overall = MtUniCreditConstants::HEALTH_READY;

        if (!$phpOk) {
            $overall = MtUniCreditConstants::HEALTH_UNAVAILABLE;
        }

        foreach ($checks as $check) {
            if ($check['status'] === MtUniCreditConstants::HEALTH_UNAVAILABLE) {
                $overall = MtUniCreditConstants::HEALTH_UNAVAILABLE;
                break;
            }

            if ($check['status'] === MtUniCreditConstants::HEALTH_WARNING && $overall === MtUniCreditConstants::HEALTH_READY) {
                $overall = MtUniCreditConstants::HEALTH_WARNING;
            }
        }

        if ($overall === MtUniCreditConstants::HEALTH_READY) {
            $detail = 'Phase 1 skeleton ready; financing features disabled until later phases.';
        } elseif ($overall === MtUniCreditConstants::HEALTH_WARNING) {
            $detail = 'Core runtime acceptable; complete CP/deployment prerequisites before enabling.';
        } else {
            $detail = 'Runtime prerequisites missing; module remains disabled.';
        }

        return array(
            'status' => $overall,
            'detail' => $detail,
        );
    }

    /**
     * @param string $id
     * @param string $status
     * @param string $detail
     * @return array<string, string>
     */
    private static function buildCheck($id, $status, $detail)
    {
        return array(
            'id' => $id,
            'status' => $status,
            'detail' => $detail,
        );
    }

    /**
     * @param string|null $root
     * @param string $relative
     * @return string
     */
    private static function filePresenceStatus($root, $relative)
    {
        if (!$root) {
            return MtUniCreditConstants::HEALTH_NOT_CONFIGURED;
        }

        $path = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

        if (!is_file($path)) {
            return MtUniCreditConstants::HEALTH_NOT_CONFIGURED;
        }

        if (!is_readable($path)) {
            return MtUniCreditConstants::HEALTH_WARNING;
        }

        return MtUniCreditConstants::HEALTH_READY;
    }

    /**
     * @param string|null $root
     * @param string $relative
     * @return string
     */
    private static function filePresenceDetail($root, $relative)
    {
        if (!$root) {
            return 'protected root not resolved';
        }

        $path = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

        if (!is_file($path)) {
            return 'missing at expected relative path';
        }

        $readable = is_readable($path) ? 'readable' : 'not readable';
        $writable = is_writable($path) ? 'writable' : 'not writable';

        return $relative . ' (' . $readable . ', ' . $writable . ')';
    }

    /**
     * @param string $detail
     * @return void
     */
    public static function assertNoSecretLeak($detail)
    {
        $needles = array(
            'BEGIN PRIVATE KEY',
            'BEGIN CERTIFICATE',
            'passphrase',
            'Bearer ',
            'enc:v1:',
        );

        foreach ($needles as $needle) {
            if (stripos($detail, $needle) !== false) {
                throw new RuntimeException('Health detail must not expose sensitive material.');
            }
        }
    }
}
