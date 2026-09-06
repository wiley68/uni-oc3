<?php

/**
 * Deterministic installation DB password seed for offline crypto tests.
 * Must stay out of upload/ (AUD-031 / F-031-02).
 */
final class MtUniCreditEncryptionTestSecret
{
    const INSTALLATION_TEST_SECRET = 'phase4-test-installation-db-password-secret';

    /**
     * @return string
     */
    public static function testSecretInput()
    {
        return self::INSTALLATION_TEST_SECRET;
    }
}
