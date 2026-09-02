<?php

/**
 * Durable CP submission error taxonomy (Phase 7).
 */
final class MtUniCreditControlPanelErrorClass
{
    const AUTH_FAILED = 'cp_auth_failed';

    const TRANSPORT_FAILED = 'cp_transport_failed';

    const TIMEOUT = 'cp_timeout';

    const INVALID_RESPONSE = 'cp_invalid_response';

    const REJECTED = 'cp_rejected';

    const CONFLICT = 'cp_conflict';

    const RECOVERY_FAILED = 'cp_recovery_failed';

    const VALIDATION_FAILED = 'cp_validation_failed';

    const AMBIGUOUS_BLOCKED = 'cp_ambiguous_blocked';

    /**
     * @return array<int, string>
     */
    public static function all()
    {
        return array(
            self::AUTH_FAILED,
            self::TRANSPORT_FAILED,
            self::TIMEOUT,
            self::INVALID_RESPONSE,
            self::REJECTED,
            self::CONFLICT,
            self::RECOVERY_FAILED,
            self::VALIDATION_FAILED,
            self::AMBIGUOUS_BLOCKED,
        );
    }

    /**
     * @param string $code
     * @return bool
     */
    public static function isValid($code)
    {
        return in_array($code, self::all(), true);
    }
}
