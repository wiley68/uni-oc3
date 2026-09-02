<?php

/**
 * Derives the AES-256 key for encrypting module settings at rest.
 *
 * Semantic parity with reference-uni-oc4 ModuleEncryptionKeyProvider.
 *
 * @see docs/CONTRACTS.md DEPLOY-002
 */
final class MtUniCreditEncryptionKeyProvider
{
    const DERIVATION_INFO = 'mt_uni_credit/settings-encryption/v1';

    const KEY_LENGTH = 32;

    /**
     * @return string
     */
    public function resolveSecretInput()
    {
        if (defined('DB_PASSWORD') && DB_PASSWORD !== '') {
            return DB_PASSWORD;
        }

        throw new RuntimeException('Module encryption secret unavailable.');
    }

    /**
     * @param string|null $secretInputOverride Test-only override of installation secret.
     * @return string 32-byte binary key
     */
    public function resolveDerivedKey($secretInputOverride = null)
    {
        $secret = $secretInputOverride !== null ? $secretInputOverride : $this->resolveSecretInput();
        $derived = hash_hkdf('sha256', $secret, self::KEY_LENGTH, self::DERIVATION_INFO);
        if ($derived === false) {
            throw new RuntimeException('Module encryption key derivation failed.');
        }

        return $derived;
    }

    /**
     * Deterministic test input shared with OC4 Phase 4 tests.
     *
     * @return string
     */
    public static function testSecretInput()
    {
        return 'phase4-test-installation-db-password-secret';
    }
}
