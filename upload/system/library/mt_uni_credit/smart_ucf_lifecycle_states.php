<?php

/**
 * Durable SmartUCF lifecycle states on the financing attempt row.
 */
final class MtUniCreditSmartUcfLifecycleStates
{
    const NOT_STARTED = 'not_started';
    const SUBMITTING = 'submitting';
    const CREATED = 'created';
    const FAILED = 'failed';
    const OUTCOME_UNKNOWN = 'outcome_unknown';
}
