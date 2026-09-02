<?php

/**
 * Read-only view-model for GET prepared page (no network / no mutations).
 */
final class MtUniCreditCheckoutPreparedViewState
{
    const MODE_READY = 'ready';

    const MODE_SUCCESS = 'success';

    const MODE_RETRYABLE = 'retryable';

    const MODE_AMBIGUOUS = 'ambiguous';

    const MODE_IN_PROGRESS = 'in_progress';

    /**
     * @param array<string, mixed>|null $attempt
     * @return array{
     *   mode: string,
     *   success: bool,
     *   ambiguous: bool,
     *   can_submit: bool,
     *   message_key: string
     * }
     */
    public static function fromAttempt($attempt)
    {
        if (!is_array($attempt) || $attempt === array()) {
            return array(
                'mode' => self::MODE_READY,
                'success' => false,
                'ambiguous' => false,
                'can_submit' => true,
                'message_key' => 'text_prepared_ready',
            );
        }

        $state = isset($attempt['state']) ? (string) $attempt['state'] : '';

        if ($state === MtUniCreditFinancingAttemptState::CP_CREATED) {
            return array(
                'mode' => self::MODE_SUCCESS,
                'success' => true,
                'ambiguous' => false,
                'can_submit' => false,
                'message_key' => 'text_prepared_success',
            );
        }

        if ($state === MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN) {
            return array(
                'mode' => self::MODE_AMBIGUOUS,
                'success' => false,
                'ambiguous' => true,
                'can_submit' => false,
                'message_key' => 'text_prepared_ambiguous',
            );
        }

        if ($state === MtUniCreditFinancingAttemptState::CP_FAILED_RETRYABLE) {
            return array(
                'mode' => self::MODE_RETRYABLE,
                'success' => false,
                'ambiguous' => false,
                'can_submit' => true,
                'message_key' => 'text_prepared_retryable',
            );
        }

        if ($state === MtUniCreditFinancingAttemptState::CP_SUBMITTING) {
            return array(
                'mode' => self::MODE_IN_PROGRESS,
                'success' => false,
                'ambiguous' => false,
                'can_submit' => false,
                'message_key' => 'text_prepared_in_progress',
            );
        }

        // order_created or unknown → ready to submit
        return array(
            'mode' => self::MODE_READY,
            'success' => false,
            'ambiguous' => false,
            'can_submit' => true,
            'message_key' => 'text_prepared_ready',
        );
    }
}
