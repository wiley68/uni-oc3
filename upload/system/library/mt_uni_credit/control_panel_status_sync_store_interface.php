<?php

/**
 * Durable CP status-sync persistence with compare-and-set transitions.
 */
interface MtUniCreditControlPanelStatusSyncStoreInterface
{
    /**
     * @param int $attemptId
     * @return array<string, mixed>|null
     */
    public function findByAttempt($attemptId);

    /**
     * Install/replace pending target only if expected state/target still match.
     *
     * @param int $attemptId
     * @param string $expectedState
     * @param string|null $expectedStatusId
     * @param string|null $expectedStatus
     * @param string $newStatusId
     * @param string $newStatus
     * @return bool true when the row was updated
     */
    public function compareAndSetPendingTarget(
        $attemptId,
        $expectedState,
        $expectedStatusId,
        $expectedStatus,
        $newStatusId,
        $newStatus
    );

    /**
     * Confirm pending target T only if still pending with exact T.
     *
     * @param int $attemptId
     * @param string $expectedStatusId
     * @param string $expectedStatus
     * @return bool true when the row was updated
     */
    public function compareAndSetConfirmed($attemptId, $expectedStatusId, $expectedStatus);

    /**
     * Record pending/terminal failure for exact pending target T only.
     *
     * @param int $attemptId
     * @param string $expectedStatusId
     * @param string $expectedStatus
     * @param string $newState
     * @param string $errorClass
     * @return bool true when the row was updated
     */
    public function compareAndSetFailure(
        $attemptId,
        $expectedStatusId,
        $expectedStatus,
        $newState,
        $errorClass
    );
}
