<?php

/**
 * Process 2 handoff states on financing_attempt.
 */
final class MtUniCreditProcessTwoLifecycleStates
{
    const NOT_STARTED = 'not_started';
    const PREPARING = 'process2_preparing';
    const PREPARED = 'process2_prepared';
    const FAILED = 'process2_failed';

    /**
     * @return array<int, string>
     */
    public static function all()
    {
        return array(
            self::NOT_STARTED,
            self::PREPARING,
            self::PREPARED,
            self::FAILED,
        );
    }
}
