<?php

/**
 * Idempotent persistence schema installer — no DROP on uninstall.
 *
 * Uninstall policy: Module/Payment uninstall removes oc_setting rows only.
 * Extension-owned tables (nonces, locks, shop cache, future financing evidence) are preserved.
 */
final class MtUniCreditPersistenceSchema
{
    /** @var MtUniCreditDbAdapter */
    private $db;

    /**
     * @param MtUniCreditDbAdapter $db
     */
    public function __construct(MtUniCreditDbAdapter $db)
    {
        $this->db = $db;
    }

    /**
     * @param MtUniCreditDbAdapter $db
     * @return void
     */
    public static function install(MtUniCreditDbAdapter $db)
    {
        self::installAll($db);
    }

    /**
     * @param MtUniCreditDbAdapter $db
     * @return void
     */
    public static function installAll(MtUniCreditDbAdapter $db)
    {
        $installer = new self($db);
        $installer->installAllTables();
    }

    /**
     * @return void
     */
    public function installAllTables()
    {
        foreach (self::createAllTableStatements($this->db->getPrefix()) as $sql) {
            try {
                $this->db->query($sql);
            } catch (Exception $exception) {
                throw new MtUniCreditInstallationException(
                    'Persistence schema create failed.',
                    0,
                    $exception
                );
            }
        }
        $this->ensurePhase9Columns();
        $this->ensurePhase10Columns();
        $this->ensureAud007F02Columns();
        $this->ensureAud012Columns();
        $this->ensureAud014Columns();
        $this->ensureAud020Columns();
        $this->ensureAud027Columns();
        $this->completeRequiredSchema();
        $this->ensureCanonicalOrderIdColumnTypes();
        $this->verifyRequiredSchema();
    }

    /**
     * Canonical required schema inventory (logical table names, no prefix).
     *
     * @return array<string, array{columns: array<string, string>, indexes: array<int, array{name: string, unique: bool, columns: array<int, string>}>}>
     */
    public static function requiredTableInventory()
    {
        return MtUniCreditPersistenceSchemaInventory::tables();
    }

    /**
     * Inspect → ADD missing columns/indexes for every owned table.
     *
     * @return void
     */
    public function completeRequiredSchema()
    {
        $prefix = $this->db->getPrefix();
        foreach (self::requiredTableInventory() as $logical => $spec) {
            $table = $prefix . $logical;
            if (!$this->tableExists($table)) {
                throw new MtUniCreditInstallationException(
                    'Required persistence table missing after create: ' . $logical
                );
            }
            $this->completeTableColumns($table, $spec['columns']);
            $this->completeTableIndexes($table, $spec['indexes']);
        }
    }

    /**
     * Final verification pass — install must not succeed if incomplete.
     *
     * @return void
     */
    public function verifyRequiredSchema()
    {
        $prefix = $this->db->getPrefix();
        foreach (self::requiredTableInventory() as $logical => $spec) {
            $table = $prefix . $logical;
            if (!$this->tableExists($table)) {
                throw new MtUniCreditInstallationException(
                    'Schema verification failed: missing table ' . $logical
                );
            }
            $columns = $this->listColumnNames($table);
            foreach (array_keys($spec['columns']) as $column) {
                if (!isset($columns[$column])) {
                    throw new MtUniCreditInstallationException(
                        'Schema verification failed: missing column on ' . $logical
                    );
                }
            }
            $indexes = $this->listIndexes($table);
            foreach ($spec['indexes'] as $expected) {
                $match = $this->findMatchingIndex($indexes, $expected);
                if ($match === null) {
                    throw new MtUniCreditInstallationException(
                        'Schema verification failed: missing or mismatched index on ' . $logical
                    );
                }
            }
        }
        $this->verifyCanonicalOrderIdColumnTypes();
    }

    /**
     * Migrate UniPayment-owned canonical order_id columns from integer to VARCHAR(13).
     * Idempotent: already string-capable (≥13) columns are left unchanged.
     *
     * @return void
     */
    public function ensureCanonicalOrderIdColumnTypes()
    {
        foreach ($this->canonicalOrderIdColumnTargets() as $target) {
            $table = $this->db->getPrefix() . $target['table'];
            if (!$this->tableExists($table)) {
                throw new MtUniCreditInstallationException(
                    'Canonical order_id migration failed: missing table ' . $target['table']
                );
            }
            $type = $this->inspectColumnType($table, 'order_id');
            if ($type === null) {
                throw new MtUniCreditInstallationException(
                    'Canonical order_id migration failed: missing order_id on ' . $target['table']
                );
            }
            if ($this->isStringCapableOrderIdType($type)) {
                continue;
            }
            if (!$this->isIntegerLikeColumnType($type)) {
                throw new MtUniCreditInstallationException(
                    'Canonical order_id migration failed: unexpected type on ' . $target['table']
                );
            }
            $nullSql = !empty($target['nullable']) ? 'NULL' : 'NOT NULL';
            try {
                $this->db->query(
                    'ALTER TABLE `' . $table . '` MODIFY COLUMN `order_id` VARCHAR(13) ' . $nullSql
                );
            } catch (Exception $exception) {
                throw new MtUniCreditInstallationException(
                    'Canonical order_id MODIFY failed on ' . $target['table'],
                    0,
                    $exception
                );
            }
            $after = $this->inspectColumnType($table, 'order_id');
            if ($after === null || $this->isIntegerLikeColumnType($after) || !$this->isStringCapableOrderIdType($after)) {
                throw new MtUniCreditInstallationException(
                    'Canonical order_id migration did not yield VARCHAR on ' . $target['table']
                );
            }
        }
    }

    /**
     * @return void
     */
    private function verifyCanonicalOrderIdColumnTypes()
    {
        foreach ($this->canonicalOrderIdColumnTargets() as $target) {
            $table = $this->db->getPrefix() . $target['table'];
            $type = $this->inspectColumnType($table, 'order_id');
            if ($type === null || !$this->isStringCapableOrderIdType($type)) {
                throw new MtUniCreditInstallationException(
                    'Schema verification failed: order_id must be string-capable on ' . $target['table']
                );
            }
        }
    }

    /**
     * @return array<int, array{table: string, nullable: bool}>
     */
    private function canonicalOrderIdColumnTargets()
    {
        return array(
            array(
                'table' => MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT,
                'nullable' => true,
            ),
            array(
                'table' => MtUniCreditPersistenceTableNames::ORDER_BANK_STATUS,
                'nullable' => false,
            ),
            array(
                'table' => MtUniCreditPersistenceTableNames::OPERATION_ORDER_CLAIM,
                'nullable' => true,
            ),
            array(
                'table' => MtUniCreditPersistenceTableNames::DIAGNOSTIC_DEBUG_LOG,
                'nullable' => false,
            ),
        );
    }

    /**
     * @param string $table
     * @param string $column
     * @return string|null lowercase Type from SHOW COLUMNS
     */
    private function inspectColumnType($table, $column)
    {
        try {
            $result = $this->db->query('SHOW COLUMNS FROM `' . $table . '`');
        } catch (Exception $exception) {
            throw new MtUniCreditInstallationException(
                'Schema inspection failed (column types).',
                0,
                $exception
            );
        }
        if (!is_object($result) || !isset($result->rows) || !is_array($result->rows)) {
            return null;
        }
        foreach ($result->rows as $row) {
            if (!is_array($row) || !isset($row['Field']) || (string) $row['Field'] !== $column) {
                continue;
            }
            if (!isset($row['Type'])) {
                return null;
            }

            return strtolower((string) $row['Type']);
        }

        return null;
    }

    /**
     * @param string $type
     * @return bool
     */
    private function isStringCapableOrderIdType($type)
    {
        if (preg_match('/^(?:var)?char\((\d+)\)$/i', $type, $matches)) {
            return (int) $matches[1] >= 13;
        }

        return false;
    }

    /**
     * @param string $type
     * @return bool
     */
    private function isIntegerLikeColumnType($type)
    {
        return (bool) preg_match(
            '/^(?:tiny|small|medium|big)?int(?:eger)?(?:\s*\(\d+\))?(?:\s+unsigned)?$/i',
            trim($type)
        );
    }

    /**
     * @param string $table
     * @return bool
     */
    private function tableExists($table)
    {
        try {
            $result = $this->db->query('SHOW COLUMNS FROM `' . $table . '`');
        } catch (Exception $exception) {
            return false;
        }

        return is_object($result)
            && isset($result->rows)
            && is_array($result->rows)
            && $result->rows !== array();
    }

    /**
     * @param string $table
     * @return array<string, true>
     */
    private function listColumnNames($table)
    {
        $existing = array();
        try {
            $result = $this->db->query('SHOW COLUMNS FROM `' . $table . '`');
        } catch (Exception $exception) {
            throw new MtUniCreditInstallationException(
                'Schema inspection failed (columns).',
                0,
                $exception
            );
        }
        if (is_object($result) && isset($result->rows) && is_array($result->rows)) {
            foreach ($result->rows as $row) {
                if (isset($row['Field'])) {
                    $existing[(string) $row['Field']] = true;
                }
            }
        }

        return $existing;
    }

    /**
     * @param string $table
     * @return array<string, array{unique: bool, columns: array<int, string>}>
     */
    private function listIndexes($table)
    {
        $grouped = array();
        try {
            $result = $this->db->query('SHOW INDEX FROM `' . $table . '`');
        } catch (Exception $exception) {
            throw new MtUniCreditInstallationException(
                'Schema inspection failed (indexes).',
                0,
                $exception
            );
        }
        if (!is_object($result) || !isset($result->rows) || !is_array($result->rows)) {
            return $grouped;
        }
        foreach ($result->rows as $row) {
            $name = isset($row['Key_name']) ? (string) $row['Key_name'] : '';
            if ($name === '') {
                continue;
            }
            if (!isset($grouped[$name])) {
                $grouped[$name] = array(
                    'unique' => !isset($row['Non_unique']) || (int) $row['Non_unique'] === 0,
                    'columns' => array(),
                );
            }
            $seq = isset($row['Seq_in_index']) ? (int) $row['Seq_in_index'] : (count($grouped[$name]['columns']) + 1);
            $col = isset($row['Column_name']) ? (string) $row['Column_name'] : '';
            $grouped[$name]['columns'][$seq] = $col;
            if (isset($row['Non_unique'])) {
                $grouped[$name]['unique'] = (int) $row['Non_unique'] === 0;
            }
        }
        foreach ($grouped as $name => $meta) {
            ksort($meta['columns']);
            $grouped[$name]['columns'] = array_values($meta['columns']);
        }

        return $grouped;
    }

    /**
     * @param array<string, array{unique: bool, columns: array<int, string>}> $indexes
     * @param array{name: string, unique: bool, columns: array<int, string>} $expected
     * @return array{unique: bool, columns: array<int, string>}|null
     */
    private function findMatchingIndex(array $indexes, array $expected)
    {
        $name = (string) $expected['name'];
        if (!isset($indexes[$name])) {
            return null;
        }
        $actual = $indexes[$name];
        if ((bool) $actual['unique'] !== (bool) $expected['unique']) {
            return null;
        }
        if ($actual['columns'] !== array_values($expected['columns'])) {
            return null;
        }

        return $actual;
    }

    /**
     * @param string $table
     * @param array<string, string> $columns
     * @return void
     */
    private function completeTableColumns($table, array $columns)
    {
        $existing = $this->listColumnNames($table);
        foreach ($columns as $name => $definition) {
            if (isset($existing[$name])) {
                continue;
            }
            $sql = 'ALTER TABLE `' . $table . '` ADD COLUMN `' . $name . '` ' . $definition;
            try {
                $this->db->query($sql);
            } catch (Exception $exception) {
                $after = $this->listColumnNames($table);
                if (isset($after[$name])) {
                    continue;
                }
                throw new MtUniCreditInstallationException(
                    'Required schema column could not be added.',
                    0,
                    $exception
                );
            }
            $after = $this->listColumnNames($table);
            if (!isset($after[$name])) {
                throw new MtUniCreditInstallationException(
                    'Required schema column missing after ALTER.'
                );
            }
        }
    }

    /**
     * @param string $table
     * @param array<int, array{name: string, unique: bool, columns: array<int, string>}> $indexes
     * @return void
     */
    private function completeTableIndexes($table, array $indexes)
    {
        $existing = $this->listIndexes($table);
        foreach ($indexes as $expected) {
            $name = (string) $expected['name'];
            if ($name === 'PRIMARY') {
                if ($this->findMatchingIndex($existing, $expected) === null) {
                    throw new MtUniCreditInstallationException(
                        'Required PRIMARY KEY missing or mismatched.'
                    );
                }
                continue;
            }
            if (isset($existing[$name])) {
                if ($this->findMatchingIndex($existing, $expected) === null) {
                    throw new MtUniCreditInstallationException(
                        'Required index exists with wrong definition.'
                    );
                }
                continue;
            }
            $cols = array();
            foreach ($expected['columns'] as $col) {
                $cols[] = '`' . $col . '`';
            }
            $type = !empty($expected['unique']) ? 'UNIQUE KEY' : 'KEY';
            $sql = 'ALTER TABLE `' . $table . '` ADD ' . $type . ' `' . $name . '` (' . implode(', ', $cols) . ')';
            try {
                $this->db->query($sql);
            } catch (Exception $exception) {
                $after = $this->listIndexes($table);
                if ($this->findMatchingIndex($after, $expected) !== null) {
                    continue;
                }
                throw new MtUniCreditInstallationException(
                    'Required schema index could not be added.',
                    0,
                    $exception
                );
            }
            $after = $this->listIndexes($table);
            if ($this->findMatchingIndex($after, $expected) === null) {
                throw new MtUniCreditInstallationException(
                    'Required schema index missing after ALTER.'
                );
            }
        }
    }

    /**
     * Add SmartUCF lifecycle columns when missing (fresh CREATE already includes them).
     *
     * @return void
     */
    public function ensurePhase9Columns()
    {
        $this->ensureAlterColumns(self::createPhase9AlterStatements($this->db->getPrefix()));
    }

    /**
     * Add Process 2 lifecycle columns when missing.
     *
     * @return void
     */
    public function ensurePhase10Columns()
    {
        $this->ensureAlterColumns(self::createPhase10AlterStatements($this->db->getPrefix()));
    }

    /**
     * Add immutable application snapshot columns when missing (AUD-007-F02).
     *
     * @return void
     */
    public function ensureAud007F02Columns()
    {
        $this->ensureAlterColumns(self::createAud007F02AlterStatements($this->db->getPrefix()));
    }

    /**
     * AUD-012 Process 2 preparing claim + recipient mail durability.
     *
     * @return void
     */
    public function ensureAud012Columns()
    {
        $this->ensureAlterColumns(self::createAud012AlterStatements($this->db->getPrefix()));
        foreach (self::createAud012TableStatements($this->db->getPrefix()) as $sql) {
            try {
                $this->db->query($sql);
            } catch (Exception $exception) {
                // CREATE IF NOT EXISTS race: accept only when table is present.
                $prefix = $this->db->getPrefix();
                $table = $prefix . MtUniCreditPersistenceTableNames::PROCESS2_MAIL_RECIPIENT;
                if ($this->tableExists($table)) {
                    continue;
                }
                throw new MtUniCreditInstallationException(
                    'Persistence schema create failed.',
                    0,
                    $exception
                );
            }
        }
    }

    /**
     * AUD-014 native order finalization once-claim columns.
     *
     * @return void
     */
    public function ensureAud014Columns()
    {
        $this->ensureAlterColumns(self::createAud014AlterStatements($this->db->getPrefix()));
    }

    /**
     * AUD-020 Cart clear once-claim columns on financing_attempt.
     *
     * @return void
     */
    public function ensureAud020Columns()
    {
        $this->ensureAlterColumns(self::createAud020AlterStatements($this->db->getPrefix()));
    }

    /**
     * AUD-027 immutable retention timestamps for P2 ciphertext + presentation.
     *
     * @return void
     */
    public function ensureAud027Columns()
    {
        $this->ensureAlterColumns(self::createAud027AlterStatements($this->db->getPrefix()));
    }

    /**
     * @param array<int, string> $statements
     * @return void
     */
    private function ensureAlterColumns(array $statements)
    {
        foreach ($statements as $sql) {
            if (!preg_match('/ALTER TABLE `([^`]+)`/i', $sql, $tableMatch)) {
                continue;
            }
            $table = $tableMatch[1];
            if (preg_match("/ADD COLUMN `([^`]+)`/", $sql, $match)) {
                $existing = $this->listColumnNames($table);
                if (isset($existing[$match[1]])) {
                    continue;
                }
                try {
                    $this->db->query($sql);
                } catch (Exception $exception) {
                    $after = $this->listColumnNames($table);
                    if (isset($after[$match[1]])) {
                        continue;
                    }
                    throw new MtUniCreditInstallationException(
                        'Required schema column could not be added.',
                        0,
                        $exception
                    );
                }
                $after = $this->listColumnNames($table);
                if (!isset($after[$match[1]])) {
                    throw new MtUniCreditInstallationException(
                        'Required schema column missing after ALTER.'
                    );
                }
                continue;
            }
            if (preg_match("/ADD (?:UNIQUE )?KEY `([^`]+)`/i", $sql, $match)) {
                $indexes = $this->listIndexes($table);
                if (isset($indexes[$match[1]])) {
                    continue;
                }
                try {
                    $this->db->query($sql);
                } catch (Exception $exception) {
                    $after = $this->listIndexes($table);
                    if (isset($after[$match[1]])) {
                        continue;
                    }
                    throw new MtUniCreditInstallationException(
                        'Required schema index could not be added.',
                        0,
                        $exception
                    );
                }
                $after = $this->listIndexes($table);
                if (!isset($after[$match[1]])) {
                    throw new MtUniCreditInstallationException(
                        'Required schema index missing after ALTER.'
                    );
                }
            }
        }
    }


    /**
     * @return void
     */
    public function installPhase2Tables()
    {
        foreach (self::createPhase2TableStatements($this->db->getPrefix()) as $sql) {
            $this->db->query($sql);
        }
    }

    /**
     * @param string $prefix
     * @return array<int, string>
     */
    public static function createTableStatements($prefix)
    {
        return self::createPhase2TableStatements($prefix);
    }

    /**
     * @param string $prefix
     * @return array<int, string>
     */
    public static function createAllTableStatements($prefix)
    {
        return array_merge(
            self::createPhase2TableStatements($prefix),
            self::createPhase3TableStatements($prefix),
            self::createPhase6TableStatements($prefix),
            self::createPhase7TableStatements($prefix),
            self::createOperationOrderClaimTableStatements($prefix),
            self::createAud012TableStatements($prefix)
        );
    }

    /**
     * Idempotent Phase 9 column upgrades for financing_attempt.
     *
     * @param string $prefix
     * @return array<int, string>
     */
    public static function createPhase9AlterStatements($prefix)
    {
        $financingAttempt = $prefix . MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT;

        return array(
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `smartucf_state` VARCHAR(32) NOT NULL DEFAULT 'not_started'",
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `smartucf_session_id` VARCHAR(128) NULL",
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `smartucf_redirect_url` VARCHAR(768) NULL",
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `smartucf_http_code` INT NULL",
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `smartucf_error_class` VARCHAR(64) NULL",
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `smartucf_retryable` TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `smartucf_claimed_at` DATETIME NULL",
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `smartucf_completed_at` DATETIME NULL",
        );
    }

    /**
     * Idempotent Phase 10 column upgrades for financing_attempt.
     *
     * @param string $prefix
     * @return array<int, string>
     */
    public static function createPhase10AlterStatements($prefix)
    {
        $financingAttempt = $prefix . MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT;

        return array(
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `process2_state` VARCHAR(32) NOT NULL DEFAULT 'not_started'",
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `process2_sensitive_enc` MEDIUMTEXT NULL",
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `process2_mail_sent` TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `leasing_presentation_json` MEDIUMTEXT NULL",
        );
    }

    /**
     * Idempotent AUD-007-F02 column upgrades for financing_attempt.
     *
     * @param string $prefix
     * @return array<int, string>
     */
    public static function createAud007F02AlterStatements($prefix)
    {
        $financingAttempt = $prefix . MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT;

        return array(
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `application_snapshot_json` LONGTEXT NULL",
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `application_snapshot_hash` CHAR(64) NULL",
        );
    }

    /**
     * Idempotent AUD-012 column upgrades for financing_attempt.
     *
     * @param string $prefix
     * @return array<int, string>
     */
    public static function createAud012AlterStatements($prefix)
    {
        $financingAttempt = $prefix . MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT;

        return array(
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `process2_claimed_at` DATETIME NULL",
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `process2_claim_owner` CHAR(32) NULL",
        );
    }

    /**
     * Idempotent AUD-014 column upgrades for financing_attempt native finalization.
     *
     * @param string $prefix
     * @return array<int, string>
     */
    public static function createAud014AlterStatements($prefix)
    {
        $financingAttempt = $prefix . MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT;

        return array(
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `native_finalize_state` VARCHAR(32) NOT NULL DEFAULT 'not_started'",
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `native_finalize_claim_owner` CHAR(32) NULL",
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `native_finalize_claimed_at` DATETIME NULL",
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `native_finalize_applied_at` DATETIME NULL",
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `native_finalize_target_status` INT UNSIGNED NULL",
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `native_finalize_outcome` VARCHAR(64) NULL",
            "ALTER TABLE `{$financingAttempt}` ADD KEY `idx_mt_uni_credit_attempt_native_finalize` (`native_finalize_state`, `native_finalize_claimed_at`)",
        );
    }

    /**
     * Idempotent AUD-020 column upgrades for financing_attempt Cart clear once-claim.
     *
     * @param string $prefix
     * @return array<int, string>
     */
    public static function createAud020AlterStatements($prefix)
    {
        $financingAttempt = $prefix . MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT;

        return array(
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `cart_clear_state` VARCHAR(32) NOT NULL DEFAULT 'not_applied'",
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `cart_clear_claimed_at` DATETIME NULL",
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `cart_clear_applied_at` DATETIME NULL",
            "ALTER TABLE `{$financingAttempt}` ADD KEY `idx_mt_uni_credit_attempt_cart_clear` (`cart_clear_state`, `cart_clear_claimed_at`)",
        );
    }

    /**
     * Idempotent AUD-027 retention timestamp upgrades for financing_attempt.
     *
     * @param string $prefix
     * @return array<int, string>
     */
    public static function createAud027AlterStatements($prefix)
    {
        $financingAttempt = $prefix . MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT;

        return array(
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `process2_sensitive_created_at` DATETIME NULL",
            "ALTER TABLE `{$financingAttempt}` ADD COLUMN `leasing_presentation_created_at` DATETIME NULL",
            "ALTER TABLE `{$financingAttempt}` ADD KEY `idx_mt_uni_credit_attempt_p2_sensitive_created` (`process2_sensitive_created_at`)",
            "ALTER TABLE `{$financingAttempt}` ADD KEY `idx_mt_uni_credit_attempt_presentation_created` (`leasing_presentation_created_at`)",
        );
    }

    /**
     * Durable Process 2 mail recipient delivery state (AUD-012 F02/F03).
     *
     * @param string $prefix
     * @return array<int, string>
     */
    public static function createAud012TableStatements($prefix)
    {
        $table = $prefix . MtUniCreditPersistenceTableNames::PROCESS2_MAIL_RECIPIENT;

        return array(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `process2_mail_recipient_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `attempt_id` INT UNSIGNED NOT NULL,
                `audience` VARCHAR(16) NOT NULL,
                `recipient_key` VARCHAR(255) NOT NULL,
                `recipient_email` VARCHAR(255) NOT NULL,
                `state` VARCHAR(32) NOT NULL DEFAULT 'pending',
                `claim_owner_token` CHAR(32) NULL,
                `claimed_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`process2_mail_recipient_id`),
                UNIQUE KEY `uniq_mt_uni_credit_p2_mail_recipient` (`attempt_id`, `recipient_key`),
                KEY `idx_mt_uni_credit_p2_mail_recipient_state` (`state`, `claimed_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    /**
     * @param string $prefix
     * @return array<int, string>
     */
    public static function createPhase2TableStatements($prefix)
    {
        $apiNonce = $prefix . MtUniCreditPersistenceTableNames::API_NONCE;
        $operationLock = $prefix . MtUniCreditPersistenceTableNames::OPERATION_LOCK;

        return array(
            "CREATE TABLE IF NOT EXISTS `{$apiNonce}` (
                `api_nonce_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_id` INT UNSIGNED NOT NULL,
                `unicid` VARCHAR(64) NOT NULL,
                `nonce_hash` CHAR(64) NOT NULL,
                `used_at` DATETIME NOT NULL,
                `expires_at` DATETIME NOT NULL,
                PRIMARY KEY (`api_nonce_id`),
                UNIQUE KEY `uniq_mt_uni_credit_api_nonce` (`store_id`, `unicid`, `nonce_hash`),
                KEY `idx_mt_uni_credit_api_nonce_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `{$operationLock}` (
                `operation_lock_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_id` INT UNSIGNED NOT NULL,
                `entry_point` VARCHAR(16) NOT NULL,
                `operation_key_hash` CHAR(64) NOT NULL,
                `owner_token` CHAR(32) NOT NULL,
                `expires_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`operation_lock_id`),
                UNIQUE KEY `uniq_mt_uni_credit_operation_lock` (`store_id`, `entry_point`, `operation_key_hash`),
                KEY `idx_mt_uni_credit_operation_lock_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    /**
     * @param string $prefix
     * @return array<int, string>
     */
    public static function createPhase3TableStatements($prefix)
    {
        $shopCache = $prefix . MtUniCreditPersistenceTableNames::SHOP_CACHE;

        return array(
            "CREATE TABLE IF NOT EXISTS `{$shopCache}` (
                `shop_cache_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_id` INT UNSIGNED NOT NULL,
                `unicid` VARCHAR(64) NOT NULL,
                `shop_data` LONGTEXT NOT NULL,
                `fetched_at` DATETIME NOT NULL,
                `expires_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`shop_cache_id`),
                UNIQUE KEY `uniq_mt_uni_credit_shop_cache_store_unicid` (`store_id`, `unicid`),
                KEY `idx_mt_uni_credit_shop_cache_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    /**
     * @param string $prefix
     * @return array<int, string>
     */
    public static function createPhase6TableStatements($prefix)
    {
        $orderBankStatus = $prefix . MtUniCreditPersistenceTableNames::ORDER_BANK_STATUS;
        $diagnosticDebugLog = $prefix . MtUniCreditPersistenceTableNames::DIAGNOSTIC_DEBUG_LOG;

        return array(
            "CREATE TABLE IF NOT EXISTS `{$orderBankStatus}` (
                `order_bank_status_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_id` INT UNSIGNED NOT NULL,
                `order_id` VARCHAR(13) NOT NULL,
                `order_reference` VARCHAR(64) NOT NULL,
                `status_id` VARCHAR(255) NOT NULL,
                `status_label` VARCHAR(255) NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`order_bank_status_id`),
                UNIQUE KEY `uniq_mt_uni_credit_order_bank_store_order` (`store_id`, `order_id`),
                KEY `idx_mt_uni_credit_order_bank_reference` (`order_reference`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `{$diagnosticDebugLog}` (
                `diagnostic_debug_log_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_id` INT UNSIGNED NOT NULL,
                `order_id` VARCHAR(13) NOT NULL,
                `entry_point` VARCHAR(16) NOT NULL DEFAULT '',
                `event_code` VARCHAR(64) NOT NULL DEFAULT '',
                `http_status` INT NULL,
                `summary_json` LONGTEXT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`diagnostic_debug_log_id`),
                KEY `idx_mt_uni_credit_diag_store_order` (`store_id`, `order_id`),
                KEY `idx_mt_uni_credit_diag_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    /**
     * @param string $prefix
     * @return array<int, string>
     */
    public static function createPhase7TableStatements($prefix)
    {
        $financingAttempt = $prefix . MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT;

        return array(
            "CREATE TABLE IF NOT EXISTS `{$financingAttempt}` (
                `attempt_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_id` INT UNSIGNED NOT NULL,
                `entry_point` VARCHAR(16) NOT NULL,
                `operation_key_hash` CHAR(64) NOT NULL,
                `selection_hash` CHAR(64) NOT NULL,
                `request_fingerprint` CHAR(64) NOT NULL DEFAULT '',
                `state` VARCHAR(32) NOT NULL,
                `order_id` VARCHAR(13) NULL,
                `unicid` VARCHAR(64) NOT NULL DEFAULT '',
                `control_panel_order_id` BIGINT UNSIGNED NULL,
                `cp_payload` LONGTEXT NULL,
                `application_snapshot_json` LONGTEXT NULL,
                `application_snapshot_hash` CHAR(64) NULL,
                `last_error_class` VARCHAR(64) NULL,
                `smartucf_state` VARCHAR(32) NOT NULL DEFAULT 'not_started',
                `smartucf_session_id` VARCHAR(128) NULL,
                `smartucf_redirect_url` VARCHAR(768) NULL,
                `smartucf_http_code` INT NULL,
                `smartucf_error_class` VARCHAR(64) NULL,
                `smartucf_retryable` TINYINT(1) NOT NULL DEFAULT 0,
                `smartucf_claimed_at` DATETIME NULL,
                `smartucf_completed_at` DATETIME NULL,
                `cart_clear_state` VARCHAR(32) NOT NULL DEFAULT 'not_applied',
                `cart_clear_claimed_at` DATETIME NULL,
                `cart_clear_applied_at` DATETIME NULL,
                `cp_status_sync_state` VARCHAR(32) NOT NULL DEFAULT 'not_needed',
                `cp_status_sync_status_id` VARCHAR(255) NULL,
                `cp_status_sync_status` VARCHAR(255) NULL,
                `cp_status_sync_error_class` VARCHAR(64) NULL,
                `cp_status_sync_updated_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`attempt_id`),
                UNIQUE KEY `uniq_mt_uni_credit_store_order` (`store_id`, `order_id`),
                KEY `idx_mt_uni_credit_attempt_operation` (`store_id`, `entry_point`, `operation_key_hash`, `state`),
                KEY `idx_mt_uni_credit_attempt_state_updated` (`state`, `updated_at`),
                KEY `idx_mt_uni_credit_attempt_smartucf_state` (`smartucf_state`, `updated_at`),
                KEY `idx_mt_uni_credit_attempt_cart_clear` (`cart_clear_state`, `cart_clear_claimed_at`),
                KEY `idx_mt_uni_credit_attempt_cp_status_sync` (`cp_status_sync_state`),
                KEY `idx_mt_uni_credit_attempt_store_order_unicid` (`store_id`, `order_id`, `unicid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    /**
     * Durable product/cart operation → local order claim (AUD-006-F01).
     *
     * @param string $prefix
     * @return array<int, string>
     */
    public static function createOperationOrderClaimTableStatements($prefix)
    {
        $claim = $prefix . MtUniCreditPersistenceTableNames::OPERATION_ORDER_CLAIM;

        return array(
            "CREATE TABLE IF NOT EXISTS `{$claim}` (
                `operation_order_claim_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_id` INT UNSIGNED NOT NULL,
                `entry_point` VARCHAR(16) NOT NULL,
                `operation_key_hash` CHAR(64) NOT NULL,
                `state` VARCHAR(32) NOT NULL,
                `order_id` VARCHAR(13) NULL,
                `claim_owner_token` CHAR(32) NOT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`operation_order_claim_id`),
                UNIQUE KEY `uniq_mt_uni_credit_operation_order_claim` (`store_id`, `entry_point`, `operation_key_hash`),
                KEY `idx_mt_uni_credit_operation_order_claim_order` (`store_id`, `order_id`),
                KEY `idx_mt_uni_credit_operation_order_claim_state` (`state`, `updated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }
}
