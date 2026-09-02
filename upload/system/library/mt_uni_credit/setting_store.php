<?php

/**
 * Store-scoped OpenCart setting access for module secrets.
 */
final class MtUniCreditSettingStore
{
    /** @var MtUniCreditDbAdapter */
    private $db;

    /** @var string */
    private $settingsCode;

    /**
     * @param MtUniCreditDbAdapter $db
     * @param string $settingsCode
     */
    public function __construct(MtUniCreditDbAdapter $db, $settingsCode)
    {
        $this->db = $db;
        $this->settingsCode = (string) $settingsCode;
    }

    /**
     * @param int $storeId
     * @param string $key
     * @return string|null
     */
    public function get($storeId, $key)
    {
        if (!MtUniCreditStoreScope::isValid($storeId)) {
            return null;
        }

        $table = $this->db->getPrefix() . 'setting';
        $result = $this->db->query(
            "SELECT `value` FROM `{$table}`"
                . " WHERE `store_id` = " . (int) $storeId
                . " AND `key` = '" . $this->db->escape($key) . "'"
                . " LIMIT 1"
        );

        if (!is_object($result) || !isset($result->num_rows) || (int) $result->num_rows !== 1) {
            return null;
        }

        $value = isset($result->row['value']) ? $result->row['value'] : null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param int $storeId
     * @param string $key
     * @param string $value
     * @return void
     */
    public function set($storeId, $key, $value)
    {
        MtUniCreditStoreScope::requireStoreId($storeId);

        $table = $this->db->getPrefix() . 'setting';
        $existing = $this->get($storeId, $key);
        if ($existing === null) {
            $this->db->query(
                "INSERT INTO `{$table}` (`store_id`, `code`, `key`, `value`, `serialized`)"
                    . " VALUES ("
                    . (int) $storeId . ","
                    . " '" . $this->db->escape($this->settingsCode) . "',"
                    . " '" . $this->db->escape($key) . "',"
                    . " '" . $this->db->escape($value) . "',"
                    . " 0)"
            );

            return;
        }

        $this->db->query(
            "UPDATE `{$table}` SET `value` = '" . $this->db->escape($value) . "'"
                . " WHERE `store_id` = " . (int) $storeId
                . " AND `key` = '" . $this->db->escape($key) . "'"
        );
    }

    /**
     * @param int $storeId
     * @param string $key
     * @return void
     */
    public function delete($storeId, $key)
    {
        if (!MtUniCreditStoreScope::isValid($storeId)) {
            return;
        }

        $table = $this->db->getPrefix() . 'setting';
        $this->db->query(
            "DELETE FROM `{$table}`"
                . " WHERE `store_id` = " . (int) $storeId
                . " AND `key` = '" . $this->db->escape($key) . "'"
        );
    }
}
