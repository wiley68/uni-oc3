<?php

/**
 * UTC datetime helpers for persistence repositories.
 */
final class MtUniCreditPersistenceClock
{
    /** @var callable|null */
    private $now;

    /**
     * @param callable|null $now Callable returning unix timestamp
     */
    public function __construct($now = null)
    {
        $this->now = $now;
    }

    /**
     * @return int
     */
    public function now()
    {
        if ($this->now !== null) {
            return (int) call_user_func($this->now);
        }

        return time();
    }

    /**
     * @param int $timestamp
     * @return string
     */
    public function formatUtc($timestamp)
    {
        return gmdate('Y-m-d H:i:s', (int) $timestamp);
    }
}
