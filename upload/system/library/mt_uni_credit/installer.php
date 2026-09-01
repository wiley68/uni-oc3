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
}
