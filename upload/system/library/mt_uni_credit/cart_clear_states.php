<?php

/**
 * Durable Cart clear once-state for a financing attempt (AUD-020-F01-R1).
 *
 * Safety trade-off: `applying` never re-authorizes clear (prefer possible uncleared
 * original cart after rare crash over destructive clear of a new cart).
 */
final class MtUniCreditCartClearStates
{
    const NOT_APPLIED = 'not_applied';

    const APPLYING = 'applying';

    const APPLIED = 'applied';

    /**
     * @param string $state
     * @return bool
     */
    public static function isValid($state)
    {
        $state = (string) $state;

        return $state === self::NOT_APPLIED
            || $state === self::APPLYING
            || $state === self::APPLIED;
    }

    /**
     * States that must never authorize another destructive clear.
     *
     * @param string $state
     * @return bool
     */
    public static function isConsumed($state)
    {
        $state = (string) $state;

        return $state === self::APPLYING || $state === self::APPLIED;
    }
}
