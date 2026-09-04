<?php

/**
 * Encrypts Process 2 EGN/phone2 for durable attempt storage.
 */
final class MtUniCreditProcessTwoSensitiveCipher
{
    const DERIVATION_INFO = 'mt_uni_credit/process2-sensitive/v1';

    /** @var MtUniCreditSettingCipher */
    private $cipher;

    /**
     * @param string|null $secretInputOverride
     */
    public function __construct($secretInputOverride = null)
    {
        if ($secretInputOverride !== null) {
            if ($secretInputOverride === '') {
                throw new RuntimeException('Process 2 sensitive encryption secret unavailable.');
            }
            $secretInput = $secretInputOverride;
        } else {
            $secretInput = (new MtUniCreditEncryptionKeyProvider())->resolveSecretInput();
        }

        $key = hash_hkdf('sha256', $secretInput, 32, self::DERIVATION_INFO);
        if ($key === false) {
            throw new RuntimeException('Process 2 sensitive key derivation failed.');
        }
        $this->cipher = new MtUniCreditSettingCipher($key);
    }

    /**
     * @param MtUniCreditProcessTwoSensitiveData $data
     * @return string
     */
    public function encrypt(MtUniCreditProcessTwoSensitiveData $data)
    {
        return $this->cipher->encrypt(json_encode($data->toArray(), JSON_THROW_ON_ERROR));
    }

    /**
     * @param string $encoded
     * @return MtUniCreditProcessTwoSensitiveData
     */
    public function decrypt($encoded)
    {
        $decoded = json_decode($this->cipher->decrypt($encoded), true);
        if (
            !is_array($decoded)
            || !isset($decoded['egn'], $decoded['phone2'])
            || !is_string($decoded['egn'])
            || !is_string($decoded['phone2'])
        ) {
            throw new RuntimeException('Process 2 sensitive payload is malformed.');
        }

        return new MtUniCreditProcessTwoSensitiveData($decoded['egn'], $decoded['phone2']);
    }
}
