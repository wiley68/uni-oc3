<?php

/**
 * Durable outbound CP PATCH /orders/status synchronization states.
 *
 * Local bank_sent_* remains "business handoff proven"; this tracks CP confirmation separately.
 */
final class MtUniCreditControlPanelStatusSyncStates
{
    const NOT_NEEDED = 'not_needed';

    const PENDING = 'pending';

    const CONFIRMED = 'confirmed';

    const TERMINAL_FAILED = 'terminal_failed';
}
