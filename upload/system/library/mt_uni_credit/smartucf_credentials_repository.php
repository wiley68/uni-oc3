<?php

/**
 * Encrypted SmartUCF credentials extracted from validated shop snapshots.
 */
final class MtUniCreditSmartucfCredentialsRepository
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
     * @param string|null $user
     * @param string|null $password
     * @return void
     */
    public function savePair($storeId, $user, $password)
    {
        if ($user === null && $password === null) {
            return;
        }

        if ($user !== null) {
            $this->settings->set(
                $storeId,
                MtUniCreditConstants::MODULE_SETTING_SMARTUCF_USER,
                $this->cipher->encrypt($user)
            );
        }

        if ($password !== null) {
            $this->settings->set(
                $storeId,
                MtUniCreditConstants::MODULE_SETTING_SMARTUCF_PASSWORD,
                $this->cipher->encrypt($password)
            );
        }
    }

    /**
     * Capture exact raw setting presence for both credential keys.
     *
     * null value means the setting row is absent (not "empty decrypted secret").
     *
     * @param int $storeId
     * @return array{user: string|null, password: string|null}
     */
    public function capturePairState($storeId)
    {
        return array(
            'user' => $this->settings->get($storeId, MtUniCreditConstants::MODULE_SETTING_SMARTUCF_USER),
            'password' => $this->settings->get($storeId, MtUniCreditConstants::MODULE_SETTING_SMARTUCF_PASSWORD),
        );
    }

    /**
     * Restore exact previous raw setting presence/absence for both keys.
     *
     * @param int $storeId
     * @param array{user?: string|null, password?: string|null} $state
     * @return void
     */
    public function restorePairState($storeId, array $state)
    {
        $this->restoreRawSetting(
            $storeId,
            MtUniCreditConstants::MODULE_SETTING_SMARTUCF_USER,
            array_key_exists('user', $state) ? $state['user'] : null
        );
        $this->restoreRawSetting(
            $storeId,
            MtUniCreditConstants::MODULE_SETTING_SMARTUCF_PASSWORD,
            array_key_exists('password', $state) ? $state['password'] : null
        );
    }

    /**
     * @param int $storeId
     * @return string|null
     */
    public function getUser($storeId)
    {
        return $this->decryptSetting($storeId, MtUniCreditConstants::MODULE_SETTING_SMARTUCF_USER);
    }

    /**
     * @param int $storeId
     * @return string|null
     */
    public function getPassword($storeId)
    {
        return $this->decryptSetting($storeId, MtUniCreditConstants::MODULE_SETTING_SMARTUCF_PASSWORD);
    }

    /**
     * @param int $storeId
     * @param string $key
     * @param string|null $previousRaw null = delete (restore absence)
     * @return void
     */
    private function restoreRawSetting($storeId, $key, $previousRaw)
    {
        if ($previousRaw === null) {
            $this->settings->delete($storeId, $key);

            return;
        }

        $this->settings->set($storeId, $key, (string) $previousRaw);
    }

    /**
     * @param int $storeId
     * @param string $key
     * @return string|null
     */
    private function decryptSetting($storeId, $key)
    {
        $stored = (string) ($this->settings->get($storeId, $key) ?? '');
        if ($stored === '' || !MtUniCreditSettingCipher::hasEncryptedPrefix($stored)) {
            return null;
        }

        try {
            $plain = $this->cipher->decrypt($stored);
        } catch (Exception $exception) {
            return null;
        }

        return is_string($plain) && $plain !== '' ? $plain : null;
    }
}
