<?php

/**
 * Wraps OpenCart 3 DB for module persistence repositories.
 */
final class MtUniCreditDbAdapter
{
    /** @var object */
    private $db;

    /** @var string */
    private $prefix;

    /**
     * @param object $db OpenCart DB with query/escape/countAffected
     * @param string|null $prefix
     */
    public function __construct($db, $prefix = null)
    {
        $this->db = $db;
        $this->prefix = $prefix !== null ? (string) $prefix : (defined('DB_PREFIX') ? DB_PREFIX : 'oc_');
    }

    /**
     * @param string $sql
     * @return mixed
     */
    public function query($sql)
    {
        return $this->db->query($sql);
    }

    /**
     * @param string $value
     * @return string
     */
    public function escape($value)
    {
        return $this->db->escape($value);
    }

    /**
     * @return int
     */
    public function countAffected()
    {
        return (int) $this->db->countAffected();
    }

    /**
     * @return int
     */
    public function getLastId()
    {
        return (int) $this->db->getLastId();
    }

    /**
     * @return string
     */
    public function getPrefix()
    {
        return $this->prefix;
    }
}
