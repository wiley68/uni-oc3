<?php

/**
 * In-memory OpenCart DB double for Phase 2 offline repository tests.
 */
final class Phase2MemoryDb
{
    /** @var array<int, array<string, array<string, string>>> */
    private $settings = array();

    /** @var array<int, array<string, mixed>> */
    private $apiNonces = array();

    /** @var array<int, array<string, mixed>> */
    private $operationLocks = array();

    /** @var int */
    private $affected = 0;

    /** @var int */
    private $nextNonceId = 1;

    /** @var int */
    private $nextLockId = 1;

    /** @var string */
    private $prefix = 'oc_';

    /**
     * @return void
     */
    public function reset()
    {
        $this->settings = array();
        $this->apiNonces = array();
        $this->operationLocks = array();
        $this->affected = 0;
        $this->nextNonceId = 1;
        $this->nextLockId = 1;
    }

    /**
     * @return int
     */
    public function apiNonceCount()
    {
        return count($this->apiNonces);
    }

    /**
     * @return string
     */
    public function firstApiNonceExpiresAt()
    {
        foreach ($this->apiNonces as $row) {
            return (string) $row['expires_at'];
        }

        return '';
    }

    /**
     * @param string $sql
     * @return object
     */
    public function query($sql)
    {
        $this->affected = 0;
        $sql = trim($sql);

        if (stripos($sql, 'INSERT INTO') === 0 && strpos($sql, 'api_nonce') !== false) {
            return $this->insertApiNonce($sql);
        }

        if (stripos($sql, 'INSERT IGNORE INTO') === 0 && strpos($sql, 'operation_lock') !== false) {
            return $this->insertIgnoreOperationLock($sql);
        }

        if (stripos($sql, 'INSERT INTO') === 0 && strpos($sql, 'setting') !== false) {
            return $this->insertSetting($sql);
        }

        if (stripos($sql, 'UPDATE') === 0 && strpos($sql, 'operation_lock') !== false) {
            return $this->updateOperationLock($sql);
        }

        if (stripos($sql, 'UPDATE') === 0 && strpos($sql, 'setting') !== false) {
            return $this->updateSetting($sql);
        }

        if (stripos($sql, 'DELETE FROM') === 0 && strpos($sql, 'operation_lock') !== false) {
            return $this->deleteOperationLock($sql);
        }

        if (stripos($sql, 'DELETE FROM') === 0 && strpos($sql, 'api_nonce') !== false) {
            return $this->deleteApiNonces($sql);
        }

        if (stripos($sql, 'SELECT') === 0 && strpos($sql, 'setting') !== false) {
            return $this->selectSetting($sql);
        }

        if (stripos($sql, 'SELECT') === 0 && strpos($sql, 'operation_lock') !== false) {
            return $this->selectOperationLock($sql);
        }

        return $this->emptyResult();
    }

    /**
     * @param string $value
     * @return string
     */
    public function escape($value)
    {
        return str_replace(array('\\', "'"), array('\\\\', "\\'"), (string) $value);
    }

    /**
     * @return int
     */
    public function countAffected()
    {
        return $this->affected;
    }

    /**
     * @return int
     */
    public function getLastId()
    {
        return max(0, $this->nextNonceId - 1, $this->nextLockId - 1);
    }

    /**
     * @return string
     */
    public function getPrefix()
    {
        return $this->prefix;
    }

    /**
     * @param string $sql
     * @return object
     */
    private function insertApiNonce($sql)
    {
        $fields = $this->parseInsertValues($sql);
        $storeId = (int) $fields['store_id'];
        $unicid = (string) $fields['unicid'];
        $nonceHash = (string) $fields['nonce_hash'];

        foreach ($this->apiNonces as $row) {
            if (
                (int) $row['store_id'] === $storeId
                && (string) $row['unicid'] === $unicid
                && (string) $row['nonce_hash'] === $nonceHash
            ) {
                throw new Exception('Duplicate entry \'uniq_mt_uni_credit_api_nonce\' for key 1062');
            }
        }

        $this->apiNonces[$this->nextNonceId++] = array(
            'store_id' => $storeId,
            'unicid' => $unicid,
            'nonce_hash' => $nonceHash,
            'used_at' => (string) $fields['used_at'],
            'expires_at' => (string) $fields['expires_at'],
        );
        $this->affected = 1;

        return $this->emptyResult();
    }

    /**
     * @param string $sql
     * @return object
     */
    private function insertIgnoreOperationLock($sql)
    {
        $fields = $this->parseInsertValues($sql);
        $key = $this->lockKey($fields);

        if (isset($this->operationLocks[$key])) {
            $this->affected = 0;

            return $this->emptyResult();
        }

        $this->operationLocks[$key] = array(
            'operation_lock_id' => $this->nextLockId++,
            'store_id' => (int) $fields['store_id'],
            'entry_point' => (string) $fields['entry_point'],
            'operation_key_hash' => (string) $fields['operation_key_hash'],
            'owner_token' => (string) $fields['owner_token'],
            'expires_at' => (string) $fields['expires_at'],
            'created_at' => (string) $fields['created_at'],
            'updated_at' => (string) $fields['updated_at'],
        );
        $this->affected = 1;

        return $this->emptyResult();
    }

    /**
     * @param string $sql
     * @return object
     */
    private function updateOperationLock($sql)
    {
        $now = $this->extractQuoted($sql, 'expires_at` <= \'', '\'');
        if ($now === '') {
            $now = $this->extractQuoted($sql, 'expires_at <= \'', '\'');
        }
        $ownerToken = $this->extractSetValue($sql, 'owner_token');
        $expiresAt = $this->extractSetValue($sql, 'expires_at');
        $updatedAt = $this->extractSetValue($sql, 'updated_at');
        $storeId = (int) $this->extractWhereInt($sql, 'store_id');
        $entryPoint = $this->extractWhereQuoted($sql, 'entry_point');
        $operationKeyHash = $this->extractWhereQuoted($sql, 'operation_key_hash');

        $key = $storeId . '|' . $entryPoint . '|' . $operationKeyHash;
        if (!isset($this->operationLocks[$key])) {
            return $this->emptyResult();
        }

        $row = $this->operationLocks[$key];
        if ($row['expires_at'] > $now) {
            return $this->emptyResult();
        }

        $row['owner_token'] = $ownerToken;
        $row['expires_at'] = $expiresAt;
        $row['updated_at'] = $updatedAt;
        $this->operationLocks[$key] = $row;
        $this->affected = 1;

        return $this->emptyResult();
    }

    /**
     * @param string $sql
     * @return object
     */
    private function deleteOperationLock($sql)
    {
        $storeId = (int) $this->extractWhereInt($sql, 'store_id');
        $entryPoint = $this->extractWhereQuoted($sql, 'entry_point');
        $operationKeyHash = $this->extractWhereQuoted($sql, 'operation_key_hash');
        $ownerToken = $this->extractWhereQuoted($sql, 'owner_token');

        $key = $storeId . '|' . $entryPoint . '|' . $operationKeyHash;
        if (!isset($this->operationLocks[$key])) {
            return $this->emptyResult();
        }

        if ((string) $this->operationLocks[$key]['owner_token'] !== $ownerToken) {
            return $this->emptyResult();
        }

        unset($this->operationLocks[$key]);
        $this->affected = 1;

        return $this->emptyResult();
    }

    /**
     * @param string $sql
     * @return object
     */
    private function deleteApiNonces($sql)
    {
        $cutoff = $this->extractQuoted($sql, 'expires_at` <= \'', '\'');
        if ($cutoff === '') {
            $cutoff = $this->extractQuoted($sql, 'expires_at <= \'', '\'');
        }
        $deleted = 0;
        foreach ($this->apiNonces as $id => $row) {
            if ((string) $row['expires_at'] <= $cutoff) {
                unset($this->apiNonces[$id]);
                $deleted++;
            }
        }
        $this->affected = $deleted;

        return $this->emptyResult();
    }

    /**
     * @param string $sql
     * @return object
     */
    private function insertSetting($sql)
    {
        $fields = $this->parseInsertValues($sql);
        $storeId = (int) $fields['store_id'];
        $key = (string) $fields['key'];
        $this->settings[$storeId][$key] = (string) $fields['value'];
        $this->affected = 1;

        return $this->emptyResult();
    }

    /**
     * @param string $sql
     * @return object
     */
    private function updateSetting($sql)
    {
        $value = $this->extractSetValue($sql, 'value');
        $storeId = (int) $this->extractWhereInt($sql, 'store_id');
        $key = $this->extractWhereQuoted($sql, 'key');
        if (!isset($this->settings[$storeId][$key])) {
            return $this->emptyResult();
        }
        $this->settings[$storeId][$key] = $value;
        $this->affected = 1;

        return $this->emptyResult();
    }

    /**
     * @param string $sql
     * @return object
     */
    private function selectSetting($sql)
    {
        $storeId = (int) $this->extractWhereInt($sql, 'store_id');
        $key = $this->extractWhereQuoted($sql, 'key');
        if (!isset($this->settings[$storeId][$key])) {
            return $this->emptyResult();
        }

        return $this->singleRow(array('value' => $this->settings[$storeId][$key]));
    }

    /**
     * @param string $sql
     * @return object
     */
    private function selectOperationLock($sql)
    {
        $storeId = (int) $this->extractWhereInt($sql, 'store_id');
        $entryPoint = $this->extractWhereQuoted($sql, 'entry_point');
        $operationKeyHash = $this->extractWhereQuoted($sql, 'operation_key_hash');
        $key = $storeId . '|' . $entryPoint . '|' . $operationKeyHash;
        if (!isset($this->operationLocks[$key])) {
            return $this->emptyResult();
        }

        return $this->singleRow($this->operationLocks[$key]);
    }

    /**
     * @param array<string, mixed> $fields
     * @return string
     */
    private function lockKey(array $fields)
    {
        return (int) $fields['store_id'] . '|' . (string) $fields['entry_point'] . '|' . (string) $fields['operation_key_hash'];
    }

    /**
     * @param string $sql
     * @return array<string, string>
     */
    private function parseInsertValues($sql)
    {
        $fields = array();
        if (!preg_match('/\(([^)]+)\)\s*VALUES\s*\(([^)]+)\)/is', $sql, $matches)) {
            return $fields;
        }

        $columns = array_map('trim', explode(',', str_replace('`', '', $matches[1])));
        $values = array();
        preg_match_all("/'(?:\\\\'|[^'])*'|[^,]+/", $matches[2], $valueMatches);
        foreach ($valueMatches[0] as $raw) {
            $raw = trim($raw);
            if ($raw !== '' && $raw[0] === "'") {
                $values[] = stripcslashes(substr($raw, 1, -1));
            } else {
                $values[] = trim($raw);
            }
        }

        foreach ($columns as $index => $column) {
            if (isset($values[$index])) {
                $fields[$column] = $values[$index];
            }
        }

        return $fields;
    }

    /**
     * @param string $sql
     * @param string $column
     * @return string
     */
    private function extractSetValue($sql, $column)
    {
        return $this->extractQuoted($sql, '`' . $column . '` = \'', '\'');
    }

    /**
     * @param string $sql
     * @param string $column
     * @return int
     */
    private function extractWhereInt($sql, $column)
    {
        if (preg_match('/`' . preg_quote($column, '/') . '`\s*=\s*(\d+)/', $sql, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    /**
     * @param string $sql
     * @param string $column
     * @return string
     */
    private function extractWhereQuoted($sql, $column)
    {
        return $this->extractQuoted($sql, '`' . $column . '` = \'', '\'');
    }

    /**
     * @param string $sql
     * @param string $start
     * @param string $end
     * @return string
     */
    private function extractQuoted($sql, $start, $end)
    {
        $pos = strpos($sql, $start);
        if ($pos === false) {
            return '';
        }
        $pos += strlen($start);
        $endPos = strpos($sql, $end, $pos);
        if ($endPos === false) {
            return '';
        }

        return stripcslashes(substr($sql, $pos, $endPos - $pos));
    }

    /**
     * @return object
     */
    private function emptyResult()
    {
        return (object) array('num_rows' => 0, 'row' => array());
    }

    /**
     * @param array<string, mixed> $row
     * @return object
     */
    private function singleRow(array $row)
    {
        return (object) array('num_rows' => 1, 'row' => $row);
    }
}
