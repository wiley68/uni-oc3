<?php

/**
 * Encrypted store-scoped CP bearer token persistence in oc_setting.
 */
final class MtUniCreditCpTokenRepository
{
    const ACCESS_TOKEN = MtUniCreditConstants::MODULE_SETTINGS_CODE . '_cp_access_token';

    const TOKEN_TYPE = MtUniCreditConstants::MODULE_SETTINGS_CODE . '_cp_token_type';

    const EXPIRES_AT = MtUniCreditConstants::MODULE_SETTINGS_CODE . '_cp_token_expires_at';

    /** @var MtUniCreditSettingStore */
    private $settings;

    /** @var MtUniCreditSettingCipher */
    private $cipher;

    /** @var int */
    private $storeId;

    /**
     * @param MtUniCreditSettingStore $settings
     * @param MtUniCreditSettingCipher $cipher
     * @param int $storeId
     */
    public function __construct(MtUniCreditSettingStore $settings, MtUniCreditSettingCipher $cipher, $storeId)
    {
        $this->settings = $settings;
        $this->cipher = $cipher;
        $this->storeId = (int) $storeId;
    }

    /**
     * @param string $accessToken
     * @param string $tokenType
     * @param int $expiresAt
     * @return bool
     */
    public function save($accessToken, $tokenType, $expiresAt)
    {
        if ($accessToken === '' || $expiresAt <= 0) {
            return false;
        }

        $this->settings->set($this->storeId, self::ACCESS_TOKEN, $this->cipher->encrypt($accessToken));
        $this->settings->set($this->storeId, self::TOKEN_TYPE, $tokenType !== '' ? $tokenType : 'Bearer');
        $this->settings->set($this->storeId, self::EXPIRES_AT, (string) $expiresAt);

        return true;
    }

    /**
     * @return string|null
     */
    public function getAccessToken()
    {
        $stored = (string) ($this->settings->get($this->storeId, self::ACCESS_TOKEN) ?? '');
        if ($stored === '' || !MtUniCreditSettingCipher::hasEncryptedPrefix($stored)) {
            return null;
        }

        try {
            $token = $this->cipher->decrypt($stored);
        } catch (Exception $exception) {
            return null;
        }

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * @return string
     */
    public function getTokenType()
    {
        $tokenType = trim((string) ($this->settings->get($this->storeId, self::TOKEN_TYPE) ?? ''));

        return $tokenType !== '' ? $tokenType : 'Bearer';
    }

    /**
     * @return int
     */
    public function getExpiresAt()
    {
        return (int) ($this->settings->get($this->storeId, self::EXPIRES_AT) ?? 0);
    }

    /**
     * @return bool
     */
    public function hasToken()
    {
        return $this->getAccessToken() !== null;
    }

    /**
     * @return void
     */
    public function invalidate()
    {
        foreach (array(self::ACCESS_TOKEN, self::TOKEN_TYPE, self::EXPIRES_AT) as $key) {
            $this->settings->delete($this->storeId, $key);
        }
    }
}
