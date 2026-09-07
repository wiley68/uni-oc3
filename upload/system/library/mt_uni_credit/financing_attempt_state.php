<?php

/**
 * Financing attempt lifecycle states (checkout CP create — Phase 7).
 */
final class MtUniCreditFinancingAttemptState
{
    const ORDER_CREATED = 'order_created';

    const CP_SUBMITTING = 'cp_submitting';

    const CP_CREATED = 'cp_created';

    const CP_FAILED_RETRYABLE = 'cp_failed_retryable';

    const CP_OUTCOME_UNKNOWN = 'cp_outcome_unknown';

    /**
     * CP proven an order already exists for this shop/order identity, but the
     * frozen local payload conflicts — automatic create retry is not permitted.
     */
    const CP_EXISTING_CONFLICT = 'cp_existing_conflict';

    /**
     * @return array<int, string>
     */
    public static function all()
    {
        return array(
            self::ORDER_CREATED,
            self::CP_SUBMITTING,
            self::CP_CREATED,
            self::CP_FAILED_RETRYABLE,
            self::CP_OUTCOME_UNKNOWN,
            self::CP_EXISTING_CONFLICT,
        );
    }

    /**
     * @param string $state
     * @return bool
     */
    public static function isValid($state)
    {
        return in_array($state, self::all(), true);
    }
}
