<?php
// Reuses the AUD-030 event-table fixture for actual core integration probes.
final class CoreCompatibilityEventDb
{
    /** @var array<int, array<string, mixed>> */
    public $rows = array();

    /** @var int */
    private $nextId = 1;

    /** @var bool */
    public $failInsert = false;

    /**
     * @param mixed $value
     * @return string
     */
    public function escape($value): string
    {
        return addslashes((string) $value);
    }

    /**
     * @param string $sql
     * @return object
     */
    public function query(string $sql): object
    {
        $sql = (string) $sql;
        $ok = (object) array('num_rows' => 0, 'row' => array(), 'rows' => array());
        if (stripos($sql, 'SELECT') === 0) {
            if (preg_match("/WHERE `code` = '([^']+)'/", $sql, $m)) {
                $code = stripslashes($m[1]);
                $matched = array();
                foreach ($this->rows as $row) {
                    if ((string) $row['code'] === $code) {
                        $matched[] = $row;
                    }
                }

                return (object) array(
                    'num_rows' => count($matched),
                    'row' => $matched ? $matched[0] : array(),
                    'rows' => $matched,
                );
            }

            return (object) array('num_rows' => count($this->rows), 'row' => array(), 'rows' => $this->rows);
        }
        if (stripos($sql, 'INSERT') === 0) {
            if ($this->failInsert) {
                throw new Exception('event insert failed');
            }
            preg_match("/`code` = '([^']+)'/", $sql, $mCode);
            preg_match("/`trigger` = '([^']+)'/", $sql, $mTrigger);
            preg_match("/`action` = '([^']+)'/", $sql, $mAction);
            $this->rows[] = array(
                'event_id' => $this->nextId++,
                'code' => stripslashes($mCode[1]),
                'trigger' => isset($mTrigger[1]) ? stripslashes($mTrigger[1]) : '',
                'action' => isset($mAction[1]) ? stripslashes($mAction[1]) : '',
                'status' => 1,
                'sort_order' => 0,
            );

            return $ok;
        }
        if (stripos($sql, 'UPDATE') === 0 && preg_match('/WHERE `event_id` = (\d+)/', $sql, $mId)) {
            $id = (int) $mId[1];
            foreach ($this->rows as &$row) {
                if ((int) $row['event_id'] === $id) {
                    if (preg_match("/`trigger` = '([^']+)'/", $sql, $mTrigger)) {
                        $row['trigger'] = stripslashes($mTrigger[1]);
                    }
                    if (preg_match("/`action` = '([^']+)'/", $sql, $mAction)) {
                        $row['action'] = stripslashes($mAction[1]);
                    }
                    $row['status'] = 1;
                    $row['sort_order'] = 0;
                }
            }
            unset($row);

            return $ok;
        }
        if (stripos($sql, 'DELETE') === 0) {
            if (preg_match('/WHERE `event_id` = (\d+)/', $sql, $mId)) {
                $id = (int) $mId[1];
                $this->rows = array_values(array_filter($this->rows, function ($row) use ($id) {
                    return (int) $row['event_id'] !== $id;
                }));
            }

            return $ok;
        }

        return $ok;
    }
}

