<?php

/**
 * In-memory OpenCart DB double for Phase 2 offline repository tests.
 */
final class Phase2MemoryDb
{
    /** @var array<int, array<string, array<string, string>>> */
    private $settings = array();

    /** @var array<int, array<string, string>> */
    private $settingCodes = array();

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

    /** @var array<string, array<string, mixed>> */
    private $shopCache = array();

    /** @var array<int, array<string, mixed>> */
    private $zoneToGeoZone = array();

    /** @var array<int, array<string, mixed>> */
    private $orders = array();

    /** @var array<string, array<string, mixed>> */
    private $orderBankStatus = array();

    /** @var array<int, array<string, mixed>> */
    private $diagnosticLogs = array();

    /** @var array<int, array<string, mixed>> */
    private $financingAttempts = array();

    /** @var int */
    private $nextShopCacheId = 1;

    /** @var int */
    private $nextBankStatusId = 1;

    /** @var int */
    private $nextDiagnosticLogId = 1;

    /** @var int */
    private $nextFinancingAttemptId = 1;

    /** @var string */
    private $prefix = 'oc_';

    /**
     * @return void
     */
    public function reset()
    {
        $this->settings = array();
        $this->settingCodes = array();
        $this->apiNonces = array();
        $this->operationLocks = array();
        $this->shopCache = array();
        $this->zoneToGeoZone = array();
        $this->orders = array();
        $this->orderBankStatus = array();
        $this->diagnosticLogs = array();
        $this->financingAttempts = array();
        $this->affected = 0;
        $this->nextNonceId = 1;
        $this->nextLockId = 1;
        $this->nextShopCacheId = 1;
        $this->nextBankStatusId = 1;
        $this->nextDiagnosticLogId = 1;
        $this->nextFinancingAttemptId = 1;
    }

    /**
     * @param int $orderId
     * @param int $storeId
     * @param string $paymentCode
     * @param mixed $paymentMethod
     * @return void
     */
    public function seedOrder($orderId, $storeId, $paymentCode = 'mt_uni_credit', $paymentMethod = '')
    {
        $this->orders[(int) $orderId] = array(
            'order_id' => (int) $orderId,
            'store_id' => (int) $storeId,
            'payment_code' => (string) $paymentCode,
            'payment_method' => $paymentMethod,
        );
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

        if (stripos($sql, 'INSERT INTO') === 0 && strpos($sql, 'shop_cache') !== false) {
            return $this->upsertShopCache($sql);
        }

        if (stripos($sql, 'INSERT INTO') === 0 && strpos($sql, 'order_bank_status') !== false) {
            return $this->upsertOrderBankStatus($sql);
        }

        if (stripos($sql, 'INSERT INTO') === 0 && strpos($sql, 'diagnostic_debug_log') !== false) {
            return $this->insertDiagnosticLog($sql);
        }

        if (stripos($sql, 'INSERT INTO') === 0 && strpos($sql, 'financing_attempt') !== false) {
            return $this->insertFinancingAttempt($sql);
        }

        if (stripos($sql, 'INSERT INTO') === 0 && strpos($sql, 'setting') !== false) {
            return $this->insertSetting($sql);
        }

        if (stripos($sql, 'UPDATE') === 0 && strpos($sql, 'operation_lock') !== false) {
            return $this->updateOperationLock($sql);
        }

        if (stripos($sql, 'UPDATE') === 0 && strpos($sql, 'financing_attempt') !== false) {
            return $this->updateFinancingAttempt($sql);
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

        if (stripos($sql, 'DELETE FROM') === 0 && strpos($sql, 'shop_cache') !== false) {
            return $this->deleteShopCache($sql);
        }

        if (stripos($sql, 'DELETE FROM') === 0 && strpos($sql, 'setting') !== false) {
            return $this->deleteSetting($sql);
        }

        if (stripos($sql, 'SELECT') === 0 && strpos($sql, 'shop_cache') !== false) {
            return $this->selectShopCache($sql);
        }

        if (stripos($sql, 'SELECT') === 0 && preg_match('/FROM `[^`]*order`/i', $sql)) {
            return $this->selectOrder($sql);
        }

        if (stripos($sql, 'SELECT') === 0 && strpos($sql, 'order_bank_status') !== false) {
            return $this->selectOrderBankStatus($sql);
        }

        if (stripos($sql, 'SELECT') === 0 && strpos($sql, 'diagnostic_debug_log') !== false) {
            return $this->selectDiagnosticLog($sql);
        }

        if (stripos($sql, 'SELECT') === 0 && strpos($sql, 'financing_attempt') !== false) {
            return $this->selectFinancingAttempt($sql);
        }

        if (stripos($sql, 'SELECT') === 0 && strpos($sql, 'setting') !== false) {
            return $this->selectSetting($sql);
        }

        if (stripos($sql, 'SELECT') === 0 && strpos($sql, 'zone_to_geo_zone') !== false) {
            return $this->selectZoneToGeoZone($sql);
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
        return max(
            0,
            $this->nextNonceId - 1,
            $this->nextLockId - 1,
            $this->nextDiagnosticLogId - 1,
            $this->nextFinancingAttemptId - 1
        );
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
        $hasDuplicateNoop = stripos($sql, 'ON DUPLICATE KEY UPDATE') !== false;
        $normalized = preg_replace('/\s+ON DUPLICATE KEY UPDATE.+$/is', '', $sql);
        $fields = $this->parseInsertValues($normalized);
        $storeId = (int) $fields['store_id'];
        $unicid = (string) $fields['unicid'];
        $nonceHash = (string) $fields['nonce_hash'];

        foreach ($this->apiNonces as $row) {
            if (
                (int) $row['store_id'] === $storeId
                && (string) $row['unicid'] === $unicid
                && (string) $row['nonce_hash'] === $nonceHash
            ) {
                if ($hasDuplicateNoop) {
                    // MySQL no-op ON DUPLICATE KEY UPDATE → affected_rows = 0 (no warning).
                    $this->affected = 0;

                    return $this->emptyResult();
                }

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
        if ($fields === array()) {
            $fields = array(
                'store_id' => (string) $this->extractWhereInt(str_replace('WHERE', 'SET', $sql), 'store_id'),
                'code' => $this->extractQuoted($sql, '`code` = \'', '\''),
                'key' => $this->extractQuoted($sql, '`key` = \'', '\''),
                'value' => $this->extractQuoted($sql, '`value` = \'', '\''),
            );
            if ($fields['code'] === '') {
                $fields['code'] = $this->extractQuoted($sql, "code` = '", "'");
            }
            if ($fields['key'] === '') {
                $fields['key'] = $this->extractQuoted($sql, "key` = '", "'");
            }
            if ($fields['value'] === '') {
                $fields['value'] = $this->extractQuoted($sql, "value` = '", "'");
            }
            if (!isset($fields['store_id']) || $fields['store_id'] === '0' || $fields['store_id'] === 0) {
                if (preg_match("/store_id\\s*=\\s*'(\\d+)'/", $sql, $m)) {
                    $fields['store_id'] = $m[1];
                } elseif (preg_match("/store_id\\s*=\\s*(\\d+)/", $sql, $m)) {
                    $fields['store_id'] = $m[1];
                }
            }
        }

        $storeId = (int) $fields['store_id'];
        $key = (string) $fields['key'];
        if ($key === '') {
            return $this->emptyResult();
        }
        $this->settings[$storeId][$key] = (string) $fields['value'];
        $this->settingCodes[$storeId][$key] = isset($fields['code']) ? (string) $fields['code'] : '';
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
    private function deleteSetting($sql)
    {
        $storeId = (int) $this->extractWhereInt($sql, 'store_id');
        $key = $this->extractWhereQuoted($sql, 'key');
        $code = $this->extractWhereQuoted($sql, 'code');

        if ($key !== '') {
            if (!isset($this->settings[$storeId][$key])) {
                return $this->emptyResult();
            }
            unset($this->settings[$storeId][$key]);
            unset($this->settingCodes[$storeId][$key]);
            $this->affected = 1;

            return $this->emptyResult();
        }

        if ($code !== '' && isset($this->settings[$storeId])) {
            $deleted = 0;
            foreach (array_keys($this->settings[$storeId]) as $settingKey) {
                $settingCode = isset($this->settingCodes[$storeId][$settingKey])
                    ? (string) $this->settingCodes[$storeId][$settingKey]
                    : '';
                if ($settingCode === $code) {
                    unset($this->settings[$storeId][$settingKey]);
                    unset($this->settingCodes[$storeId][$settingKey]);
                    $deleted++;
                }
            }
            $this->affected = $deleted;
        }

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
        $code = $this->extractWhereQuoted($sql, 'code');

        if ($key !== '') {
            if (!isset($this->settings[$storeId][$key])) {
                return $this->emptyResult();
            }

            return $this->singleRow(array('value' => $this->settings[$storeId][$key]));
        }

        if ($code !== '' && isset($this->settings[$storeId])) {
            $rows = array();
            foreach ($this->settings[$storeId] as $settingKey => $value) {
                $settingCode = isset($this->settingCodes[$storeId][$settingKey])
                    ? (string) $this->settingCodes[$storeId][$settingKey]
                    : '';
                if ($settingCode !== $code) {
                    continue;
                }
                $rows[] = array(
                    'store_id' => $storeId,
                    'code' => $code,
                    'key' => (string) $settingKey,
                    'value' => (string) $value,
                    'serialized' => '0',
                );
            }

            return $this->rowsResult($rows);
        }

        return $this->emptyResult();
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
        // Quoted SQL literals must win over the unquoted branch; otherwise JSON starting
        // with '{"..."}' is split at the first comma by [^,]+.
        preg_match_all("/'(?:\\\\'|[^'])*'|\\d+|[^',\\s][^,]*/", $matches[2], $valueMatches);
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
        if (preg_match('/(?:^|\\s)' . preg_quote($column, '/') . '\\s*=\\s*\'(\\d+)\'/', $sql, $matches)) {
            return (int) $matches[1];
        }
        if (preg_match('/(?:^|\\s)' . preg_quote($column, '/') . '\\s*=\\s*(\\d+)/', $sql, $matches)) {
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
     * @param string $sql
     * @return object
     */
    private function upsertShopCache($sql)
    {
        $normalized = preg_replace('/\s+ON DUPLICATE KEY UPDATE.+$/is', '', $sql);
        $fields = $this->parseInsertValues($normalized);
        $storeId = (int) $fields['store_id'];
        $unicid = (string) $fields['unicid'];
        $key = $this->shopCacheKey($storeId, $unicid);
        $existing = isset($this->shopCache[$key]);
        $this->shopCache[$key] = array(
            'shop_cache_id' => $existing ? $this->shopCache[$key]['shop_cache_id'] : $this->nextShopCacheId++,
            'store_id' => $storeId,
            'unicid' => $unicid,
            'shop_data' => (string) $fields['shop_data'],
            'fetched_at' => (string) $fields['fetched_at'],
            'expires_at' => (string) $fields['expires_at'],
            'created_at' => $existing ? $this->shopCache[$key]['created_at'] : (string) $fields['created_at'],
            'updated_at' => (string) $fields['updated_at'],
        );
        $this->affected = 1;

        return $this->emptyResult();
    }

    /**
     * @param string $sql
     * @return object
     */
    private function selectShopCache($sql)
    {
        $storeId = (int) $this->extractWhereInt($sql, 'store_id');
        $unicid = $this->extractWhereQuoted($sql, 'unicid');
        $key = $this->shopCacheKey($storeId, $unicid);
        if (!isset($this->shopCache[$key])) {
            return $this->emptyResult();
        }

        $row = $this->shopCache[$key];
        $cutoff = '';
        if (preg_match("/`expires_at`\\s*>\\s*'([^']+)'/", $sql, $matches)) {
            $cutoff = (string) $matches[1];
        } elseif (preg_match("/expires_at\\s*>\\s*'([^']+)'/", $sql, $matches)) {
            $cutoff = (string) $matches[1];
        }
        if ($cutoff !== '' && (string) $row['expires_at'] <= $cutoff) {
            return $this->emptyResult();
        }

        if (
            strpos($sql, '`shop_data`') === false
            && (strpos($sql, 'fetched_at`, `expires_at') !== false || strpos($sql, '`fetched_at`, `expires_at`') !== false)
        ) {
            return $this->singleRow(array(
                'fetched_at' => $row['fetched_at'],
                'expires_at' => $row['expires_at'],
            ));
        }

        return $this->singleRow(array(
            'shop_data' => $row['shop_data'],
            'fetched_at' => $row['fetched_at'],
            'expires_at' => $row['expires_at'],
        ));
    }

    /**
     * @param string $sql
     * @return object
     */
    private function deleteShopCache($sql)
    {
        $storeId = (int) $this->extractWhereInt($sql, 'store_id');
        if (strpos($sql, 'expires_at <= ') !== false) {
            $cutoff = $this->extractQuoted($sql, 'expires_at <= \'', '\'');
            if ($cutoff === '') {
                $cutoff = $this->extractQuoted($sql, 'expires_at` <= \'', '\'');
            }
            $deleted = 0;
            foreach ($this->shopCache as $key => $row) {
                if ((string) $row['expires_at'] <= $cutoff) {
                    unset($this->shopCache[$key]);
                    $deleted++;
                }
            }
            $this->affected = $deleted;

            return $this->emptyResult();
        }

        $unicid = $this->extractWhereQuoted($sql, 'unicid');
        $key = $this->shopCacheKey($storeId, $unicid);
        if (isset($this->shopCache[$key])) {
            unset($this->shopCache[$key]);
            $this->affected = 1;
        }

        return $this->emptyResult();
    }

    /**
     * @param int $storeId
     * @param string $unicid
     * @return string
     */
    private function shopCacheKey($storeId, $unicid)
    {
        return (int) $storeId . '|' . (string) $unicid;
    }

    /**
     * @param int $geoZoneId
     * @param int $countryId
     * @param int $zoneId
     * @return void
     */
    public function seedGeoZone($geoZoneId, $countryId, $zoneId)
    {
        $this->zoneToGeoZone[] = array(
            'geo_zone_id' => (int) $geoZoneId,
            'country_id' => (int) $countryId,
            'zone_id' => (int) $zoneId,
        );
    }

    /**
     * @param string $sql
     * @return object
     */
    private function selectZoneToGeoZone($sql)
    {
        $geoZoneId = (int) $this->extractWhereInt($sql, 'geo_zone_id');
        $countryId = (int) $this->extractWhereInt($sql, 'country_id');
        $zoneId = (int) $this->extractWhereInt($sql, 'zone_id');
        foreach ($this->zoneToGeoZone as $row) {
            if ((int) $row['geo_zone_id'] !== $geoZoneId || (int) $row['country_id'] !== $countryId) {
                continue;
            }
            if ((int) $row['zone_id'] === $zoneId || (int) $row['zone_id'] === 0) {
                return $this->singleRow($row);
            }
        }

        return $this->emptyResult();
    }

    /**
     * @param string $sql
     * @return object
     */
    private function upsertOrderBankStatus($sql)
    {
        $normalized = preg_replace('/\s+ON DUPLICATE KEY UPDATE.+$/is', '', $sql);
        $fields = $this->parseInsertValues($normalized);
        $storeId = (int) $fields['store_id'];
        $orderId = (int) $fields['order_id'];
        $key = $storeId . '|' . $orderId;
        $existing = isset($this->orderBankStatus[$key]);
        $this->orderBankStatus[$key] = array(
            'order_bank_status_id' => $existing ? $this->orderBankStatus[$key]['order_bank_status_id'] : $this->nextBankStatusId++,
            'store_id' => $storeId,
            'order_id' => $orderId,
            'order_reference' => (string) $fields['order_reference'],
            'status_id' => (string) $fields['status_id'],
            'status_label' => (string) $fields['status_label'],
            'updated_at' => (string) $fields['updated_at'],
        );
        $this->affected = 1;

        return $this->emptyResult();
    }

    /**
     * @param string $sql
     * @return object
     */
    private function insertDiagnosticLog($sql)
    {
        $fields = $this->parseInsertValues($sql);
        $id = $this->nextDiagnosticLogId++;
        $this->diagnosticLogs[$id] = array(
            'diagnostic_debug_log_id' => $id,
            'store_id' => (int) $fields['store_id'],
            'order_id' => (int) $fields['order_id'],
            'entry_point' => (string) $fields['entry_point'],
            'event_code' => (string) $fields['event_code'],
            'http_status' => isset($fields['http_status']) && strtoupper((string) $fields['http_status']) !== 'NULL'
                ? (int) $fields['http_status']
                : null,
            'summary_json' => (string) $fields['summary_json'],
            'created_at' => (string) $fields['created_at'],
        );
        $this->affected = 1;

        return (object) array('num_rows' => 0, 'row' => array(), 'insert_id' => $id);
    }

    /**
     * @param string $sql
     * @return object
     */
    private function selectOrder($sql)
    {
        $orderId = (int) $this->extractWhereInt($sql, 'order_id');
        if (!isset($this->orders[$orderId])) {
            return $this->emptyResult();
        }

        return $this->singleRow($this->orders[$orderId]);
    }

    /**
     * @param string $sql
     * @return object
     */
    private function selectOrderBankStatus($sql)
    {
        $storeId = (int) $this->extractWhereInt($sql, 'store_id');
        $orderId = (int) $this->extractWhereInt($sql, 'order_id');
        $key = $storeId . '|' . $orderId;
        if (!isset($this->orderBankStatus[$key])) {
            return $this->emptyResult();
        }

        return $this->singleRow($this->orderBankStatus[$key]);
    }

    /**
     * @param string $sql
     * @return object
     */
    private function selectDiagnosticLog($sql)
    {
        $storeId = (int) $this->extractWhereInt($sql, 'store_id');
        $orderId = (int) $this->extractWhereInt($sql, 'order_id');
        $latest = null;
        foreach ($this->diagnosticLogs as $row) {
            if ((int) $row['store_id'] !== $storeId || (int) $row['order_id'] !== $orderId) {
                continue;
            }
            if ($latest === null || (int) $row['diagnostic_debug_log_id'] > (int) $latest['diagnostic_debug_log_id']) {
                $latest = $row;
            }
        }

        if ($latest === null) {
            return $this->emptyResult();
        }

        return $this->singleRow($latest);
    }

    /**
     * @param string $sql
     * @return object
     */
    private function insertFinancingAttempt($sql)
    {
        $fields = $this->parseInsertValues($sql);
        $storeId = (int) $fields['store_id'];
        $orderId = isset($fields['order_id']) ? (int) $fields['order_id'] : 0;
        foreach ($this->financingAttempts as $row) {
            if ((int) $row['store_id'] === $storeId && (int) $row['order_id'] === $orderId && $orderId > 0) {
                throw new Exception('Duplicate entry \'uniq_mt_uni_credit_store_order\' for key 1062');
            }
        }

        $id = $this->nextFinancingAttemptId++;
        $this->financingAttempts[$id] = array(
            'attempt_id' => $id,
            'store_id' => $storeId,
            'entry_point' => (string) $fields['entry_point'],
            'operation_key_hash' => (string) $fields['operation_key_hash'],
            'selection_hash' => (string) $fields['selection_hash'],
            'request_fingerprint' => isset($fields['request_fingerprint']) ? (string) $fields['request_fingerprint'] : '',
            'state' => (string) $fields['state'],
            'order_id' => $orderId,
            'unicid' => isset($fields['unicid']) ? (string) $fields['unicid'] : '',
            'control_panel_order_id' => null,
            'cp_payload' => null,
            'last_error_class' => null,
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
    private function updateFinancingAttempt($sql)
    {
        $attemptId = (int) $this->extractWhereInt($sql, 'attempt_id');
        if ($attemptId <= 0 || !isset($this->financingAttempts[$attemptId])) {
            return $this->emptyResult();
        }

        $row = $this->financingAttempts[$attemptId];

        if (preg_match("/AND `state` IN \\(([^)]+)\\)/", $sql, $stateMatch)) {
            $allowed = array();
            if (preg_match_all("/'([^']+)'/", $stateMatch[1], $parts)) {
                $allowed = $parts[1];
            }
            if (!in_array((string) $row['state'], $allowed, true)) {
                return $this->emptyResult();
            }
        }

        if (strpos($sql, 'AND (`cp_payload` IS NULL OR `cp_payload` = \'\')') !== false) {
            if ($row['cp_payload'] !== null && $row['cp_payload'] !== '') {
                return $this->emptyResult();
            }
        }

        if (strpos($sql, 'AND (`control_panel_order_id` IS NULL OR `control_panel_order_id` = 0)') !== false) {
            if (!empty($row['control_panel_order_id'])) {
                return $this->emptyResult();
            }
        }

        foreach (array('state', 'updated_at', 'cp_payload', 'request_fingerprint', 'last_error_class') as $column) {
            $value = $this->extractSetValue($sql, $column);
            if ($value !== '') {
                if ($column === 'last_error_class' && stripos($sql, '`last_error_class` = NULL') !== false) {
                    $row[$column] = null;
                } else {
                    $row[$column] = $value;
                }
            }
        }

        if (stripos($sql, '`last_error_class` = NULL') !== false) {
            $row['last_error_class'] = null;
        }

        if (preg_match('/`control_panel_order_id`\\s*=\\s*(\\d+)/', $sql, $cpMatch)) {
            $row['control_panel_order_id'] = (int) $cpMatch[1];
        }

        $this->financingAttempts[$attemptId] = $row;
        $this->affected = 1;

        return $this->emptyResult();
    }

    /**
     * @param string $sql
     * @return object
     */
    private function selectFinancingAttempt($sql)
    {
        if (preg_match('/`attempt_id`\\s*=\\s*(\\d+)/', $sql, $idMatch)) {
            $id = (int) $idMatch[1];
            if (!isset($this->financingAttempts[$id])) {
                return $this->emptyResult();
            }

            return $this->singleRow($this->financingAttempts[$id]);
        }

        $storeId = (int) $this->extractWhereInt($sql, 'store_id');
        $orderId = (int) $this->extractWhereInt($sql, 'order_id');
        foreach ($this->financingAttempts as $row) {
            if ((int) $row['store_id'] === $storeId && (int) $row['order_id'] === $orderId) {
                return $this->singleRow($row);
            }
        }

        return $this->emptyResult();
    }

    /**
     * @return object
     */
    private function emptyResult()
    {
        return (object) array('num_rows' => 0, 'row' => array(), 'rows' => array());
    }

    /**
     * @param array<string, mixed> $row
     * @return object
     */
    private function singleRow(array $row)
    {
        return (object) array('num_rows' => 1, 'row' => $row, 'rows' => array($row));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return object
     */
    private function rowsResult(array $rows)
    {
        $first = $rows === array() ? array() : $rows[0];

        return (object) array(
            'num_rows' => count($rows),
            'row' => $first,
            'rows' => $rows,
        );
    }
}
