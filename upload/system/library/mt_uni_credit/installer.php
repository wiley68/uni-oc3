<?php

/**
 * Shared idempotent install helper for Module and Payment admin extensions.
 */
final class MtUniCreditInstaller
{
    /**
     * Insert missing default settings without overwriting existing values.
     *
     * @param Model $model
     * @param string $settingsCode
     * @param array<string, string> $defaults
     * @param int $storeId
     * @return void
     */
    public static function ensureDefaults($model, $settingsCode, array $defaults, $storeId = 0)
    {
        $model->load->model('setting/setting');

        foreach ($defaults as $key => $value) {
            if ($model->model_setting_setting->getSettingValue($key, $storeId) === null) {
                $model->db->query(
                    "INSERT INTO `" . DB_PREFIX . "setting` SET store_id = '" . (int) $storeId
                        . "', `code` = '" . $model->db->escape($settingsCode)
                        . "', `key` = '" . $model->db->escape($key)
                        . "', `value` = '" . $model->db->escape((string) $value) . "'"
                );
            }
        }
    }

    /**
     * Resolve the store order status ID for the canonical English label "Processing".
     *
     * Prefers the admin language configured for the store, then English (language_id 1),
     * then PROCESSING_ORDER_STATUS_FALLBACK_ID documented in constants.
     *
     * @param Model $model
     * @param int $storeId
     * @return string
     */
    public static function resolveProcessingOrderStatusId($model, $storeId = 0)
    {
        $model->load->model('setting/setting');

        $languageId = (int) $model->model_setting_setting->getSettingValue('config_language_id', $storeId);
        if ($languageId < 1) {
            $languageId = 1;
        }

        $languageIds = array($languageId);
        if ($languageId !== 1) {
            $languageIds[] = 1;
        }

        foreach ($languageIds as $candidateLanguageId) {
            $query = $model->db->query(
                "SELECT `order_status_id` FROM `" . DB_PREFIX . "order_status`"
                    . " WHERE `language_id` = '" . (int) $candidateLanguageId . "'"
                    . " AND `name` = 'Processing' LIMIT 1"
            );

            if ($query->num_rows) {
                return (string) $query->row['order_status_id'];
            }
        }

        return MtUniCreditConstants::PROCESSING_ORDER_STATUS_FALLBACK_ID;
    }

    /**
     * Copy legacy debug flag to the normalized key when only the old setting exists.
     *
     * @param Model $model
     * @param string $settingsCode
     * @param int $storeId
     * @return void
     */
    public static function migrateLegacyDebugSetting($model, $settingsCode, $storeId = 0)
    {
        $model->load->model('setting/setting');

        $legacy = $model->model_setting_setting->getSettingValue(
            MtUniCreditConstants::MODULE_SETTING_DEBUG_LEGACY,
            $storeId
        );
        $current = $model->model_setting_setting->getSettingValue(
            MtUniCreditConstants::MODULE_SETTING_DEBUG,
            $storeId
        );

        if ($legacy !== null && $current === null) {
            $model->db->query(
                "INSERT INTO `" . DB_PREFIX . "setting` SET store_id = '" . (int) $storeId
                    . "', `code` = '" . $model->db->escape($settingsCode)
                    . "', `key` = '" . $model->db->escape(MtUniCreditConstants::MODULE_SETTING_DEBUG)
                    . "', `value` = '" . $model->db->escape((string) $legacy) . "'"
            );
        }
    }

    /**
     * Idempotent catalog event registration for Thank You leasing injection.
     *
     * @param object $model OpenCart Model with $db
     * @return void
     */
    public static function ensureCatalogEvents($model)
    {
        if (!isset($model->db) || !is_object($model->db) || !method_exists($model->db, 'query')) {
            return;
        }

        $events = array(
            array(
                'code' => 'mt_uni_credit_checkout_success_order',
                'trigger' => 'catalog/controller/checkout/success/before',
                'action' => 'extension/mt_uni_credit/checkout_success/before',
            ),
            array(
                'code' => 'mt_uni_credit_checkout_success_view',
                'trigger' => 'catalog/view/common/success/before',
                'action' => 'extension/mt_uni_credit/checkout_success/beforeView',
            ),
        );

        foreach ($events as $event) {
            $existing = $model->db->query(
                "SELECT `event_id` FROM `" . DB_PREFIX . "event`"
                    . " WHERE `code` = '" . $model->db->escape($event['code']) . "' LIMIT 1"
            );
            if (is_object($existing) && !empty($existing->num_rows)) {
                continue;
            }
            $model->db->query(
                "INSERT INTO `" . DB_PREFIX . "event` SET"
                    . " `code` = '" . $model->db->escape($event['code']) . "',"
                    . " `trigger` = '" . $model->db->escape($event['trigger']) . "',"
                    . " `action` = '" . $model->db->escape($event['action']) . "',"
                    . " `status` = '1',"
                    . " `sort_order` = '0'"
            );
        }
    }

    /**
     * @param object $model
     * @return void
     */
    public static function removeCatalogEvents($model)
    {
        if (!isset($model->db) || !is_object($model->db) || !method_exists($model->db, 'query')) {
            return;
        }
        $model->db->query(
            "DELETE FROM `" . DB_PREFIX . "event` WHERE `code` LIKE 'mt_uni_credit_checkout_success%'"
        );
    }
}
