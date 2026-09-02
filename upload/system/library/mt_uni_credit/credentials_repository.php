<?php

/**
 * Local UNICID and CP login secret (operator-configured, encrypted at rest).
 *
 * Semantic parity with reference-uni-oc4 ModuleCredentialsRepository.
 */
final class MtUniCreditCredentialsRepository
{
    /** @var MtUniCreditSettingStore */
    private $settings;

    /** @var MtUniCreditSettingCipher */
    private $cipher;

    /**
     * @param MtUniCreditSettingStore $settings
     * @param MtUniCreditSettingCipher $cipher
     */
    public function __construct(MtUniCreditSettingStore $settings, MtUniCreditSettingCipher $cipher)
    {
        $this->settings = $settings;
        $this->cipher = $cipher;
    }

    /**
     * @param int $storeId
     * @return string
     */
    public function getUnicid($storeId)
    {
        $value = $this->settings->get($storeId, MtUniCreditConstants::MODULE_SETTING_UNICID);

        return trim((string) ($value !== null ? $value : ''));
    }

    /**
     * @param int $storeId
     * @param string $unicid
     * @return void
     */
    public function setUnicid($storeId, $unicid)
    {
        $this->settings->set($storeId, MtUniCreditConstants::MODULE_SETTING_UNICID, trim($unicid));
    }

    /**
     * @param int $storeId
     * @return string|null
     */
    public function getSecret($storeId)
    {
        $storedSecret = (string) ($this->settings->get($storeId, MtUniCreditConstants::MODULE_SETTING_SECRET) ?? '');
        if ($storedSecret === '') {
            return null;
        }

        if (!MtUniCreditSettingCipher::hasEncryptedPrefix($storedSecret)) {
            return null;
        }

        try {
            $decrypted = $this->cipher->decrypt($storedSecret);
        } catch (Exception $exception) {
            return null;
        }

        return is_string($decrypted) && $decrypted !== '' ? $decrypted : null;
    }

    /**
     * @param int $storeId
     * @return bool
     */
    public function hasSecret($storeId)
    {
        return $this->getSecret($storeId) !== null;
    }

    /**
     * @param int $storeId
     * @return bool
     */
    public function isSecretReadable($storeId)
    {
        $storedSecret = (string) ($this->settings->get($storeId, MtUniCreditConstants::MODULE_SETTING_SECRET) ?? '');

        return $storedSecret === '' || $this->getSecret($storeId) !== null;
    }

    /**
     * @param int $storeId
     * @param string $plainSecret
     * @return void
     */
    public function saveSecret($storeId, $plainSecret)
    {
        $plainSecret = trim($plainSecret);
        if ($plainSecret === '') {
            return;
        }

        $this->settings->set(
            $storeId,
            MtUniCreditConstants::MODULE_SETTING_SECRET,
            $this->cipher->encrypt($plainSecret)
        );
    }

    /**
     * @param int $storeId
     * @return bool
     */
    public function hasCompleteCredentials($storeId)
    {
        return $this->getUnicid($storeId) !== '' && $this->hasSecret($storeId);
    }

    /**
     * @param int $storeId
     * @return string|null Stored encrypted envelope for inspection (never plaintext).
     */
    public function getStoredSecretEnvelope($storeId)
    {
        $stored = $this->settings->get($storeId, MtUniCreditConstants::MODULE_SETTING_SECRET);

        return $stored !== null ? (string) $stored : null;
    }
}
