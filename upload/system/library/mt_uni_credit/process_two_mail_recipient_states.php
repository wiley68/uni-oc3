<?php

/**
 * Durable Process 2 mail delivery states (per recipient).
 */
final class MtUniCreditProcessTwoMailRecipientStates
{
    const PENDING = 'pending';
    const SENDING = 'sending';
    const SENT = 'sent';
    const FAILED = 'failed';
    const UNCERTAIN = 'uncertain';

    /**
     * @return array<int, string>
     */
    public static function all()
    {
        return array(
            self::PENDING,
            self::SENDING,
            self::SENT,
            self::FAILED,
            self::UNCERTAIN,
        );
    }
}
