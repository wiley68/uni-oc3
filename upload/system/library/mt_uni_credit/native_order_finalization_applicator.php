<?php

/**
 * Claim → addOrderHistory → applied/uncertain (AUD-014 F01/F05).
 * History side effects are injected so tests can probe call counts.
 */
final class MtUniCreditNativeOrderFinalizationApplicator
{
    /**
     * @param MtUniCreditDbAdapter $db
     * @param int $orderId
     * @param int $statusId Already-validated existing positive status
     * @param array<string, mixed> $submit
     * @param callable $addOrderHistory function (int $orderId, int $statusId): void
     * @param MtUniCreditNativeOrderFinalizationRepository|null $repository
     * @return array<string, mixed> diagnostic fields
     */
    public static function apply(
        MtUniCreditDbAdapter $db,
        $orderId,
        $statusId,
        array $submit,
        $addOrderHistory,
        $repository = null
    ) {
        $orderId = (int) $orderId;
        $statusId = (int) $statusId;
        $diag = array(
            'order_id' => $orderId,
            'configured_status_id' => $statusId,
            'applied' => false,
            'history_called' => false,
            'claim_acquired' => false,
            'skipped_reason' => '',
            'finalize_state' => '',
            'attempt_id' => 0,
        );

        if ($orderId <= 0) {
            $diag['skipped_reason'] = 'order_id';

            return $diag;
        }
        if ($statusId <= 0) {
            $diag['skipped_reason'] = 'configured_status_id';

            return $diag;
        }
        if (!is_callable($addOrderHistory)) {
            $diag['skipped_reason'] = 'history_callback';

            return $diag;
        }

        $attemptId = 0;
        if (isset($submit['attempt']) && is_array($submit['attempt'])) {
            $attemptId = (int) (isset($submit['attempt']['attempt_id']) ? $submit['attempt']['attempt_id'] : 0);
        }
        $diag['attempt_id'] = $attemptId;
        if ($attemptId <= 0) {
            $diag['skipped_reason'] = 'attempt_missing';

            return $diag;
        }

        $finalization = $repository instanceof MtUniCreditNativeOrderFinalizationRepository
            ? $repository
            : new MtUniCreditNativeOrderFinalizationRepository($db);

        $finalization->normalizeStaleApplyingToUncertain($attemptId);
        if ($finalization->isOnceComplete($attemptId)) {
            $row = $finalization->findByAttempt($attemptId);
            $diag['finalize_state'] = is_array($row) ? (string) $row['native_finalize_state'] : '';
            $diag['skipped_reason'] = 'durable_once_complete';

            return $diag;
        }

        $outcome = MtUniCreditNativeOrderStatusSupport::resolveFinalizationOutcome($submit);
        $ownerToken = MtUniCreditLockOwnerTokenGenerator::generate();
        if (!$finalization->claimApplying($attemptId, $ownerToken, $statusId, $outcome)) {
            $diag['skipped_reason'] = 'claim_not_acquired';

            return $diag;
        }
        $diag['claim_acquired'] = true;
        $diag['finalize_state'] = MtUniCreditNativeOrderFinalizationStates::APPLYING;

        try {
            call_user_func($addOrderHistory, $orderId, $statusId);
            $diag['history_called'] = true;
            if (!$finalization->markApplied($attemptId, $ownerToken)) {
                $finalization->markUncertain($attemptId, $ownerToken);
                $diag['finalize_state'] = MtUniCreditNativeOrderFinalizationStates::UNCERTAIN;
                $diag['skipped_reason'] = 'applied_marker_failed';

                return $diag;
            }
            $diag['applied'] = true;
            $diag['finalize_state'] = MtUniCreditNativeOrderFinalizationStates::APPLIED;

            return $diag;
        } catch (Exception $exception) {
            $finalization->markUncertain($attemptId, $ownerToken);
            $diag['finalize_state'] = MtUniCreditNativeOrderFinalizationStates::UNCERTAIN;
            $diag['skipped_reason'] = 'history_ambiguous';

            return $diag;
        }
    }
}
