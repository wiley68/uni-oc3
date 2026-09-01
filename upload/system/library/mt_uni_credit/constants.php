<?php

/**
 * Frozen UniCredit OpenCart 3 module identity and Phase 1 constants.
 *
 * @see docs/CONTRACTS.md MODULE-001, MODULE-004, DEPLOY-001, PHP-001
 */
final class MtUniCreditConstants
{
    const EXTENSION_CODE = 'mt_uni_credit';

    const EXTENSION_TYPE = 'payment';

    const VERSION = '2.0.2';

    const AUTHOR = 'Авалон ООД';

    const DISPLAY_NAME = 'УниКредит покупки на Кредит';

    const PHP_FLOOR = '7.3.0';

    const PHP_FLOOR_ID = 70300;

    const SETTINGS_CODE = 'payment_mt_uni_credit';

    const ADMIN_ROUTE = 'extension/payment/mt_uni_credit';

    const ADMIN_PERMISSION = 'extension/payment/mt_uni_credit';

    const SETTING_STATUS = 'payment_mt_uni_credit_status';

    const SETTING_SORT_ORDER = 'payment_mt_uni_credit_sort_order';

    const SETTING_ENVIRONMENT = 'payment_mt_uni_credit_environment';

    const SETTING_DEBUG = 'payment_mt_uni_credit_debug';

    const SETTING_UNICID = 'payment_mt_uni_credit_unicid';

    const SETTING_SECRET = 'payment_mt_uni_credit_secret';

    const ENVIRONMENT_TEST = '0';

    const ENVIRONMENT_PRODUCTION = '1';

    const RELATIVE_PASSPHRASE = 'secrets/smartucf-key.php';

    const RELATIVE_CERTIFICATE = 'keys/avalon_cert.pem';

    const RELATIVE_PRIVATE_KEY = 'keys/avalon_private_key.pem';

    const CP_API_PREFIX = '/api/v1';

    const HEALTH_READY = 'ready';

    const HEALTH_WARNING = 'warning';

    const HEALTH_NOT_CONFIGURED = 'not_configured';

    const HEALTH_UNAVAILABLE = 'unavailable';

    const HEALTH_FUTURE_PHASE = 'future_phase';

    /**
     * @return array<string, mixed>
     */
    public static function defaultSettings()
    {
        return array(
            self::SETTING_STATUS => '0',
            self::SETTING_SORT_ORDER => '1',
            self::SETTING_ENVIRONMENT => self::ENVIRONMENT_TEST,
            self::SETTING_DEBUG => '0',
            self::SETTING_UNICID => '',
        );
    }

    /**
     * @return array<int, string>
     */
    public static function persistedSettingKeys()
    {
        return array(
            self::SETTING_STATUS,
            self::SETTING_SORT_ORDER,
            self::SETTING_ENVIRONMENT,
            self::SETTING_DEBUG,
            self::SETTING_UNICID,
            self::SETTING_SECRET,
        );
    }

    /**
     * @return array<int, string>
     */
    public static function phase1PersistedSettingKeys()
    {
        return array(
            self::SETTING_STATUS,
            self::SETTING_SORT_ORDER,
            self::SETTING_ENVIRONMENT,
            self::SETTING_DEBUG,
            self::SETTING_UNICID,
        );
    }
}
