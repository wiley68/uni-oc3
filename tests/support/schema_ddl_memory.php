<?php

/**
 * Minimal offline MySQL DDL double for PersistenceSchema (AUD-030).
 * Supports CREATE TABLE / SHOW COLUMNS / SHOW INDEX / ALTER ADD COLUMN|KEY.
 */

class MtucSchemaDdlMemory
{
    /** @var array<string, array{columns: array<string, string>, indexes: array<string, array{unique: bool, columns: array<int, string>}>}> */
    public $tables = array();

    /** @var array<string, array<int, array<string, mixed>>> */
    public $rows = array();

    /** @var bool */
    public $failNextCreate = false;

    /** @var bool */
    public $failNextAlter = false;

    /** @var bool */
    public $alterFailOnce = false;

    /**
     * @param mixed $value
     * @return string
     */
    public function escape($value) {
        return addslashes((string) $value);
    }

    /**
     * @param string $sql
     * @return object
     */
    public function query( $sql) {
        $sql = trim($sql);

        if (preg_match('/^CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?`([^`]+)`\s*\(/is', $sql, $m)) {
            return $this->handleCreate($sql, (string) $m[2], $m[1] !== '');
        }

        if (preg_match('/^SHOW\s+COLUMNS\s+FROM\s+`([^`]+)`/i', $sql, $m)) {
            return $this->handleShowColumns((string) $m[1]);
        }

        if (preg_match('/^SHOW\s+INDEX(?:ES)?\s+FROM\s+`([^`]+)`/i', $sql, $m)) {
            return $this->handleShowIndex((string) $m[1]);
        }

        if (preg_match('/^ALTER\s+TABLE\s+`([^`]+)`\s+ADD\s+COLUMN\s+`([^`]+)`\s+(.+)$/is', $sql, $m)) {
            return $this->handleAddColumn((string) $m[1], (string) $m[2], trim((string) $m[3]));
        }

        if (preg_match('/^ALTER\s+TABLE\s+`([^`]+)`\s+ADD\s+UNIQUE\s+KEY\s+`([^`]+)`\s*\(([^)]+)\)/is', $sql, $m)) {
            return $this->handleAddIndex(
                (string) $m[1],
                (string) $m[2],
                $this->parseIndexColumns((string) $m[3]),
                true
            );
        }

        if (preg_match('/^ALTER\s+TABLE\s+`([^`]+)`\s+ADD\s+KEY\s+`([^`]+)`\s*\(([^)]+)\)/is', $sql, $m)) {
            return $this->handleAddIndex(
                (string) $m[1],
                (string) $m[2],
                $this->parseIndexColumns((string) $m[3]),
                false
            );
        }

        return (object) array('num_rows' => 0, 'row' => array(), 'rows' => array());
    }

    /**
     * @param string $table
     * @param string $name
     * @return void
     */
    public function dropIndex( $table, $name) {
        if (isset($this->tables[$table]['indexes'][$name])) {
            unset($this->tables[$table]['indexes'][$name]);
        }
    }

    /**
     * @param string $table
     * @param string $name
     * @return void
     */
    public function dropColumn( $table, $name) {
        if (isset($this->tables[$table]['columns'][$name])) {
            unset($this->tables[$table]['columns'][$name]);
        }
    }

    /**
     * @param string $table
     * @return void
     */
    public function dropTable( $table) {
        unset($this->tables[$table]);
    }

    /**
     * @param string $table
     * @param array<string, mixed> $row
     * @return void
     */
    public function seedRow( $table, $row) {
        if (!isset($this->rows[$table])) {
            $this->rows[$table] = array();
        }
        $this->rows[$table][] = $row;
    }

    /**
     * @param string $sql
     * @param string $table
     * @param bool $ifNotExists
     * @return object
     */
    private function handleCreate( $sql, $table, $ifNotExists) {
        if ($this->failNextCreate) {
            throw new Exception('create failed');
        }
        if (isset($this->tables[$table])) {
            if ($ifNotExists) {
                return (object) array('num_rows' => 0, 'row' => array(), 'rows' => array());
            }
            throw new Exception('table exists');
        }

        if (!preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`[^`]+`\s*\((.*)\)\s*(?:ENGINE|;|$)/is', $sql, $bodyMatch)) {
            throw new Exception('unparseable CREATE');
        }
        $body = (string) $bodyMatch[1];

        $columns = array();
        if (preg_match_all('/`([a-z0-9_]+)`\s+[A-Z]/i', $body, $colMatches)) {
            foreach ($colMatches[1] as $colName) {
                $columns[(string) $colName] = 'parsed';
            }
        }

        $indexes = array();
        if (preg_match_all('/PRIMARY\s+KEY\s*\(([^)]+)\)/i', $body, $pkMatches, PREG_SET_ORDER)) {
            foreach ($pkMatches as $pk) {
                $indexes['PRIMARY'] = array(
                    'unique' => true,
                    'columns' => $this->parseIndexColumns((string) $pk[1]),
                );
            }
        }
        if (preg_match_all('/UNIQUE\s+KEY\s+`([^`]+)`\s*\(([^)]+)\)/i', $body, $uqMatches, PREG_SET_ORDER)) {
            foreach ($uqMatches as $uq) {
                $indexes[(string) $uq[1]] = array(
                    'unique' => true,
                    'columns' => $this->parseIndexColumns((string) $uq[2]),
                );
            }
        }
        $bodySansUnique = preg_replace('/UNIQUE\s+KEY\s+`[^`]+`\s*\([^)]+\)/i', '', $body);
        $bodySansUnique = preg_replace('/PRIMARY\s+KEY\s*\([^)]+\)/i', '', (string) $bodySansUnique);
        if (preg_match_all('/KEY\s+`([^`]+)`\s*\(([^)]+)\)/i', (string) $bodySansUnique, $keyMatches, PREG_SET_ORDER)) {
            foreach ($keyMatches as $key) {
                $indexes[(string) $key[1]] = array(
                    'unique' => false,
                    'columns' => $this->parseIndexColumns((string) $key[2]),
                );
            }
        }

        $this->tables[$table] = array(
            'columns' => $columns,
            'indexes' => $indexes,
        );
        if (!isset($this->rows[$table])) {
            $this->rows[$table] = array();
        }

        return (object) array('num_rows' => 0, 'row' => array(), 'rows' => array());
    }

    /**
     * @param string $table
     * @return object
     */
    private function handleShowColumns( $table) {
        if (!isset($this->tables[$table])) {
            throw new Exception('Table \'' . $table . '\' doesn\'t exist');
        }
        $rows = array();
        foreach (array_keys($this->tables[$table]['columns']) as $field) {
            $rows[] = array('Field' => (string) $field);
        }

        return (object) array(
            'num_rows' => count($rows),
            'row' => $rows ? $rows[0] : array(),
            'rows' => $rows,
        );
    }

    /**
     * @param string $table
     * @return object
     */
    private function handleShowIndex( $table) {
        if (!isset($this->tables[$table])) {
            throw new Exception('Table \'' . $table . '\' doesn\'t exist');
        }
        $rows = array();
        foreach ($this->tables[$table]['indexes'] as $keyName => $meta) {
            $seq = 1;
            foreach ($meta['columns'] as $column) {
                $rows[] = array(
                    'Key_name' => (string) $keyName,
                    'Non_unique' => !empty($meta['unique']) ? 0 : 1,
                    'Seq_in_index' => $seq,
                    'Column_name' => (string) $column,
                );
                $seq++;
            }
        }

        return (object) array(
            'num_rows' => count($rows),
            'row' => $rows ? $rows[0] : array(),
            'rows' => $rows,
        );
    }

    /**
     * @param string $table
     * @param string $column
     * @param string $definition
     * @return object
     */
    private function handleAddColumn( $table, $column, $definition) {
        $this->maybeFailAlter();
        if (!isset($this->tables[$table])) {
            throw new Exception('Table \'' . $table . '\' doesn\'t exist');
        }
        if (!isset($this->tables[$table]['columns'][$column])) {
            $this->tables[$table]['columns'][$column] = $definition;
        }

        return (object) array('num_rows' => 0, 'row' => array(), 'rows' => array());
    }

    /**
     * @param string $table
     * @param string $name
     * @param array<int, string> $columns
     * @param bool $unique
     * @return object
     */
    private function handleAddIndex( $table, $name, $columns, $unique) {
        $this->maybeFailAlter();
        if (!isset($this->tables[$table])) {
            throw new Exception('Table \'' . $table . '\' doesn\'t exist');
        }
        if ($unique) {
            $this->assertNoDuplicateRows($table, $columns);
        }
        $this->tables[$table]['indexes'][$name] = array(
            'unique' => $unique,
            'columns' => array_values($columns),
        );

        return (object) array('num_rows' => 0, 'row' => array(), 'rows' => array());
    }

    /**
     * @return void
     */
    private function maybeFailAlter() {
        if ($this->failNextAlter) {
            throw new Exception('alter failed');
        }
        if ($this->alterFailOnce) {
            $this->alterFailOnce = false;
            throw new Exception('alter failed once');
        }
    }

    /**
     * @param string $table
     * @param array<int, string> $columns
     * @return void
     */
    private function assertNoDuplicateRows( $table, $columns) {
        if (empty($this->rows[$table])) {
            return;
        }
        $seen = array();
        foreach ($this->rows[$table] as $row) {
            $parts = array();
            foreach ($columns as $column) {
                $parts[] = array_key_exists($column, $row) ? (string) $row[$column] : '';
            }
            $key = implode("\0", $parts);
            if (isset($seen[$key])) {
                throw new Exception('Duplicate entry for key');
            }
            $seen[$key] = true;
        }
    }

    /**
     * @param string $list
     * @return array<int, string>
     */
    private function parseIndexColumns( $list) {
        $out = array();
        if (preg_match_all('/`([a-z0-9_]+)`/i', $list, $m)) {
            foreach ($m[1] as $col) {
                $out[] = (string) $col;
            }
        }

        return $out;
    }
}

