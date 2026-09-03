<?php

/**
 * Frozen UniCredit OpenCart 3 module identity and Phase 1 constants.
 *
 * @see docs/CONTRACTS.md MODULE-001, MODULE-005, MODULE-004, DEPLOY-001, PHP-001
 */
final class MtUniCreditConstants
{
    const EXTENSION_CODE = 'mt_uni_credit';

    const EXTENSION_TYPE_PAYMENT = 'payment';

    const EXTENSION_TYPE_MODULE = 'module';

    const VERSION = '2.0.2';

    const AUTHOR = 'Авалон ООД';

    const DISPLAY_NAME = 'УниКредит покупки на Кредит';

    const MODULE_DISPLAY_NAME = 'УниКредит покупки на Кредит';

    /** @deprecated Phase 1 legacy key; migrated to MODULE_SETTING_DEBUG on install */
    const MODULE_SETTING_DEBUG_LEGACY = 'module_mt_uni_credit_debug';

    /** @deprecated Removed from visible Module admin; reserved for Phase 4 internal use */
    const MODULE_SETTING_ENVIRONMENT_LEGACY = 'module_mt_uni_credit_environment';

    /** Fresh OC3 install seed maps English "Processing" to this ID; used only when name lookup fails. */
    const PROCESSING_ORDER_STATUS_FALLBACK_ID = '2';

    const PAYMENT_LISTING_IMAGE = 'view/image/payment/uni_logo.svg';

    const PAYMENT_LISTING_IMAGE_STYLE = 'max-width:200px;height:auto;border: 1px solid #EEEEEE;';

    const PHP_FLOOR = '7.3.0';

    const PHP_FLOOR_ID = 70300;

    const MODULE_SETTINGS_CODE = 'module_mt_uni_credit';

    const PAYMENT_SETTINGS_CODE = 'payment_mt_uni_credit';

    const MODULE_ADMIN_ROUTE = 'extension/module/mt_uni_credit';

    const PAYMENT_ADMIN_ROUTE = 'extension/payment/mt_uni_credit';

    /** Phase 5 checkout preparation continuation (not native checkout completion). */
    const CHECKOUT_PREPARED_ROUTE = 'extension/payment/mt_uni_credit/prepared';

    const CHECKOUT_SUBMIT_ROUTE = 'extension/payment/mt_uni_credit/submit';

    const PRODUCT_ROUTE = 'extension/mt_uni_credit/product';

    const CART_ROUTE = 'extension/mt_uni_credit/cart';

    const STOREFRONT_ASSET_CSS_RELATIVE = 'catalog/view/theme/default/template/extension/mt_uni_credit/storefront.css';

    const STOREFRONT_ASSET_JS_RELATIVE = 'catalog/view/theme/default/template/extension/mt_uni_credit/storefront.js';

    const STOREFRONT_ASSET_FONTS_CSS_RELATIVE = 'catalog/view/theme/default/template/extension/mt_uni_credit/storefront_fonts.css';

    const STOREFRONT_LOGO_STANDARD_RELATIVE = 'catalog/view/theme/default/template/extension/mt_uni_credit/image/uni_logo.svg';

    const STOREFRONT_LOGO_ALTERNATIVE_RELATIVE = 'catalog/view/theme/default/template/extension/mt_uni_credit/image/uni_logo_red.svg';

    const STOREFRONT_APPLY_BADGE_RELATIVE = 'catalog/view/theme/default/template/extension/mt_uni_credit/image/uni_mini_logo.png';

    const STOREFRONT_POPUP_CALC_BG_RELATIVE = 'catalog/view/theme/default/template/extension/mt_uni_credit/image/popup-calc-bg.png';

    const MODULE_ADMIN_PERMISSION = 'extension/module/mt_uni_credit';

    const PAYMENT_ADMIN_PERMISSION = 'extension/payment/mt_uni_credit';

    const MODULE_SETTING_STATUS = 'module_mt_uni_credit_status';

    const MODULE_SETTING_UNICID = 'module_mt_uni_credit_unicid';

    const MODULE_SETTING_ADVERTISING = 'module_mt_uni_credit_advertising_enabled';

    const MODULE_SETTING_DEBUG = 'module_mt_uni_credit_debug_enabled';

    const MODULE_SETTING_PRODUCT_BUTTON_ACTION = 'module_mt_uni_credit_product_button_action';

    const MODULE_SETTING_BUTTON_TOP_SPACING = 'module_mt_uni_credit_button_top_spacing';

    const MODULE_SETTING_SECRET = 'module_mt_uni_credit_secret';

    const MODULE_SETTING_SMARTUCF_USER = 'module_mt_uni_credit_smartucf_user';

    const MODULE_SETTING_SMARTUCF_PASSWORD = 'module_mt_uni_credit_smartucf_password';

    const BUTTON_ACTION_ADD_TO_CART = 'add_to_cart';

    const BUTTON_ACTION_BUY = 'buy';

    const DEFAULT_ADVERTISING_ENABLED = '0';

    const DEFAULT_DEBUG_ENABLED = '0';

    const DEFAULT_PRODUCT_BUTTON_ACTION = self::BUTTON_ACTION_ADD_TO_CART;

    const DEFAULT_BUTTON_TOP_SPACING = '0';

    const MAX_BUTTON_TOP_SPACING = 200;

    const PAYMENT_SETTING_ORDER_STATUS_ID = 'payment_mt_uni_credit_order_status_id';

    const PAYMENT_SETTING_GEO_ZONE_ID = 'payment_mt_uni_credit_geo_zone_id';

    const PAYMENT_SETTING_STATUS = 'payment_mt_uni_credit_status';

    const PAYMENT_SETTING_SORT_ORDER = 'payment_mt_uni_credit_sort_order';

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
     * @return array<string, string>
     */
    public static function defaultModuleSettings()
    {
        return array(
            self::MODULE_SETTING_STATUS => '0',
            self::MODULE_SETTING_UNICID => '',
            self::MODULE_SETTING_ADVERTISING => self::DEFAULT_ADVERTISING_ENABLED,
            self::MODULE_SETTING_DEBUG => self::DEFAULT_DEBUG_ENABLED,
            self::MODULE_SETTING_PRODUCT_BUTTON_ACTION => self::DEFAULT_PRODUCT_BUTTON_ACTION,
            self::MODULE_SETTING_BUTTON_TOP_SPACING => self::DEFAULT_BUTTON_TOP_SPACING,
        );
    }

    /**
     * @return array<string, string>
     */
    public static function defaultPaymentSettings()
    {
        return array(
            self::PAYMENT_SETTING_ORDER_STATUS_ID => '0',
            self::PAYMENT_SETTING_GEO_ZONE_ID => '0',
            self::PAYMENT_SETTING_STATUS => '0',
            self::PAYMENT_SETTING_SORT_ORDER => '1',
        );
    }

    /**
     * @return array<int, string>
     */
    public static function modulePersistedSettingKeys()
    {
        return array(
            self::MODULE_SETTING_STATUS,
            self::MODULE_SETTING_UNICID,
            self::MODULE_SETTING_ADVERTISING,
            self::MODULE_SETTING_DEBUG,
            self::MODULE_SETTING_PRODUCT_BUTTON_ACTION,
            self::MODULE_SETTING_BUTTON_TOP_SPACING,
            self::MODULE_SETTING_SECRET,
        );
    }

    /**
     * @return array<int, string>
     */
    public static function phase1ModulePersistedSettingKeys()
    {
        return array(
            self::MODULE_SETTING_STATUS,
            self::MODULE_SETTING_UNICID,
            self::MODULE_SETTING_ADVERTISING,
            self::MODULE_SETTING_DEBUG,
            self::MODULE_SETTING_PRODUCT_BUTTON_ACTION,
            self::MODULE_SETTING_BUTTON_TOP_SPACING,
        );
    }

    /**
     * @return array<int, string>
     */
    public static function paymentPersistedSettingKeys()
    {
        return array(
            self::PAYMENT_SETTING_ORDER_STATUS_ID,
            self::PAYMENT_SETTING_GEO_ZONE_ID,
            self::PAYMENT_SETTING_STATUS,
            self::PAYMENT_SETTING_SORT_ORDER,
        );
    }
}
