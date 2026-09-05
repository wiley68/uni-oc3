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
     * Idempotent catalog event registration for Thank You + native mail enrichment.
     * Upserts trigger/action/status so older installs receive corrected rows.
     * Removes duplicate rows for the same code (keeps one healthy row).
     *
     * Pass the OpenCart DB object explicitly — never rely on isset() against
     * Registry-backed Model properties (OC3 Model/Controller use __get without __isset).
     *
     * @param mixed $db OpenCart DB (query/escape); non-object / incomplete DB returns invalid_db
     * @return array{inserted:int,updated:int,deleted_duplicates:int,healthy:bool,error:?string}
     */
    public static function ensureCatalogEvents($db)
    {
        $result = array(
            'inserted' => 0,
            'updated' => 0,
            'deleted_duplicates' => 0,
            'healthy' => false,
            'error' => null,
        );

        if (!is_object($db) || !method_exists($db, 'query') || !method_exists($db, 'escape')) {
            $result['error'] = 'invalid_db';

            return $result;
        }
        if (!class_exists('MtUniCreditCatalogEventRegistry', false)) {
            require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'catalog_event_registry.php';
        }
        if (!class_exists('MtUniCreditCatalogEventHealth', false)) {
            require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'catalog_event_health.php';
        }

        $prefix = defined('DB_PREFIX') ? DB_PREFIX : 'oc_';
        $knownCodes = array();

        try {
            foreach (MtUniCreditCatalogEventRegistry::definitions() as $event) {
                $code = (string) $event['code'];
                $knownCodes[] = $code;
                $existing = $db->query(
                    "SELECT `event_id` FROM `" . $prefix . "event`"
                        . " WHERE `code` = '" . $db->escape($code) . "'"
                        . " ORDER BY `event_id` ASC"
                );
                $ids = array();
                if (is_object($existing) && !empty($existing->rows) && is_array($existing->rows)) {
                    foreach ($existing->rows as $row) {
                        if (isset($row['event_id'])) {
                            $ids[] = (int) $row['event_id'];
                        }
                    }
                } elseif (is_object($existing) && !empty($existing->num_rows) && isset($existing->row['event_id'])) {
                    $ids[] = (int) $existing->row['event_id'];
                }

                if ($ids === array()) {
                    $db->query(
                        "INSERT INTO `" . $prefix . "event` SET"
                            . " `code` = '" . $db->escape($code) . "',"
                            . " `trigger` = '" . $db->escape($event['trigger']) . "',"
                            . " `action` = '" . $db->escape($event['action']) . "',"
                            . " `status` = '1',"
                            . " `sort_order` = '0'"
                    );
                    $result['inserted']++;
                    continue;
                }

                $keepId = array_shift($ids);
                $db->query(
                    "UPDATE `" . $prefix . "event` SET"
                        . " `trigger` = '" . $db->escape($event['trigger']) . "',"
                        . " `action` = '" . $db->escape($event['action']) . "',"
                        . " `status` = '1',"
                        . " `sort_order` = '0'"
                        . " WHERE `event_id` = " . (int) $keepId
                );
                $result['updated']++;
                foreach ($ids as $duplicateId) {
                    $db->query(
                        "DELETE FROM `" . $prefix . "event` WHERE `event_id` = " . (int) $duplicateId
                    );
                    $result['deleted_duplicates']++;
                }
            }

            // Drop obsolete presentation event codes from earlier builds (same family only).
            if ($knownCodes !== array()) {
                $escaped = array();
                foreach ($knownCodes as $known) {
                    $escaped[] = "'" . $db->escape($known) . "'";
                }
                $db->query(
                    "DELETE FROM `" . $prefix . "event` WHERE `code` LIKE 'mt_uni_credit_%'"
                        . " AND ("
                        . " `code` LIKE 'mt_uni_credit_checkout_success%'"
                        . " OR `code` LIKE 'mt_uni_credit_mail_order%'"
                        . " OR `code` LIKE 'mt_uni_credit_admin_order%'"
                        . " OR `code` LIKE 'mt_uni_credit_home%'"
                        . " OR `code` LIKE 'mt_uni_credit_buy_guard%'"
                        . ")"
                        . " AND `code` NOT IN (" . implode(',', $escaped) . ")"
                );
            }
        } catch (Exception $exception) {
            $result['error'] = 'db_query_failed';
            error_log(
                'mt_uni_credit: ensureCatalogEvents DB failure'
                    . ' type=' . get_class($exception)
                    . ' msg=' . self::sanitizeDbExceptionMessage($exception->getMessage())
            );

            return $result;
        }

        $health = MtUniCreditCatalogEventHealth::report($db, $prefix);
        $result['healthy'] = !empty($health['ok']);
        if (!$result['healthy'] && $result['error'] === null) {
            $result['error'] = 'post_write_unhealthy';
        }

        return $result;
    }

    /**
     * Remove this module's managed presentation events only.
     *
     * @param mixed $db OpenCart DB (query/escape); non-object / incomplete DB returns invalid_db
     * @return array{removed:bool,error:?string}
     */
    public static function removeCatalogEvents($db)
    {
        $result = array(
            'removed' => false,
            'error' => null,
        );

        if (!is_object($db) || !method_exists($db, 'query')) {
            $result['error'] = 'invalid_db';

            return $result;
        }

        $prefix = defined('DB_PREFIX') ? DB_PREFIX : 'oc_';

        try {
            $db->query(
                "DELETE FROM `" . $prefix . "event` WHERE `code` LIKE 'mt_uni_credit_%'"
                    . " AND ("
                    . " `code` LIKE 'mt_uni_credit_checkout_success%'"
                    . " OR `code` LIKE 'mt_uni_credit_mail_order%'"
                    . " OR `code` LIKE 'mt_uni_credit_admin_order%'"
                    . " OR `code` LIKE 'mt_uni_credit_home%'"
                    . " OR `code` LIKE 'mt_uni_credit_buy_guard%'"
                    . ")"
            );
            $result['removed'] = true;
        } catch (Exception $exception) {
            $result['error'] = 'db_query_failed';
            error_log(
                'mt_uni_credit: removeCatalogEvents DB failure'
                    . ' type=' . get_class($exception)
                    . ' msg=' . self::sanitizeDbExceptionMessage($exception->getMessage())
            );
        }

        return $result;
    }

    /**
     * Strip SQL payloads from OC3 DB exception text before logging.
     *
     * @param string $message
     * @return string
     */
    private static function sanitizeDbExceptionMessage($message)
    {
        $message = (string) $message;
        $cut = strpos($message, '<br');
        if ($cut !== false) {
            $message = substr($message, 0, $cut);
        }
        $message = preg_replace('/\s+/', ' ', $message);

        return substr(trim((string) $message), 0, 180);
    }
}
