<?php

/**
 * Durable UniCredit-owned native order finalization once-state (AUD-014).
 *
 * Independent of mutable OC3 order.order_status_id.
 */
final class MtUniCreditNativeOrderFinalizationStates
{
    const NOT_STARTED = 'not_started';

    const APPLYING = 'applying';

    const APPLIED = 'applied';

    const UNCERTAIN = 'uncertain';

    /**
     * @param string $state
     * @return bool
     */
    public static function isOnceComplete($state)
    {
        $state = (string) $state;

        return $state === self::APPLIED || $state === self::UNCERTAIN;
    }
}
