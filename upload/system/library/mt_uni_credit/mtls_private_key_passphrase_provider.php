<?php

/**
 * Loads the SmartUCF mTLS private-key passphrase from a PHP secrets file.
 *
 * Never log the passphrase value.
 */
final class MtUniCreditMtlsPrivateKeyPassphraseProvider
{
    const ARRAY_KEY = 'passphrase';

    /**
     * @param string $secretsFilePath Absolute path to secrets/smartucf-key.php
     * @return string
     */
    public function requirePassphrase($secretsFilePath)
    {
        $secretsFilePath = (string) $secretsFilePath;
        if ($secretsFilePath === '' || !is_file($secretsFilePath) || !is_readable($secretsFilePath)) {
            throw new RuntimeException('SmartUCF key passphrase file is missing or unreadable.');
        }

        /** @var mixed $loaded */
        $loaded = include $secretsFilePath;
        if (!is_array($loaded) || !array_key_exists(self::ARRAY_KEY, $loaded)) {
            throw new RuntimeException('SmartUCF key passphrase is not configured.');
        }

        $raw = $loaded[self::ARRAY_KEY];
        if (!is_string($raw)) {
            throw new RuntimeException('SmartUCF key passphrase is not configured.');
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            throw new RuntimeException('SmartUCF key passphrase is not configured.');
        }

        return $trimmed;
    }
}
