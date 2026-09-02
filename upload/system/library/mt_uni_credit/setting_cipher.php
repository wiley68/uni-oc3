<?php

/**
 * AES-256-GCM encryption for values stored in oc_setting.
 *
 * Semantic parity with reference-uni-oc4 ModuleSettingCipher.
 */
final class MtUniCreditSettingCipher
{
    const ENCRYPTED_PREFIX = 'enc:v1:';

    /** @var string 32-byte key */
    private $key;

    /**
     * @param string $derivedKey 32-byte binary key from MtUniCreditEncryptionKeyProvider
     */
    public function __construct($derivedKey)
    {
        if (strlen($derivedKey) !== 32) {
            throw new InvalidArgumentException('AES-256 key must be exactly 32 bytes.');
        }

        $this->key = $derivedKey;
    }

    /**
     * @return string
     */
    public static function encryptedPrefix()
    {
        return self::ENCRYPTED_PREFIX;
    }

    /**
     * @param string $plaintext
     * @return string
     */
    public function encrypt($plaintext)
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('Setting encryption failed.');
        }

        return self::ENCRYPTED_PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * @param string $encoded
     * @return string
     */
    public function decrypt($encoded)
    {
        if (strncmp($encoded, self::ENCRYPTED_PREFIX, strlen(self::ENCRYPTED_PREFIX)) !== 0) {
            throw new RuntimeException('Encrypted setting has invalid prefix.');
        }

        $raw = base64_decode(substr($encoded, strlen(self::ENCRYPTED_PREFIX)), true);
        if ($raw === false || strlen($raw) < 28) {
            throw new RuntimeException('Encrypted setting payload is invalid.');
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new RuntimeException('Setting decryption failed.');
        }

        return $plaintext;
    }

    /**
     * @param string $encoded
     * @return bool
     */
    public static function hasEncryptedPrefix($encoded)
    {
        return strncmp((string) $encoded, self::ENCRYPTED_PREFIX, strlen(self::ENCRYPTED_PREFIX)) === 0;
    }
}
