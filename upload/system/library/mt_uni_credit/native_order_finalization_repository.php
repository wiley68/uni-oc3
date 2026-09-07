<?php

/**
 * Atomic claim + durable once-marker for Checkout native addOrderHistory (AUD-014 F01/F05).
 *
 * Claim predicate (exactly one affected row required):
 *   native_finalize_state = not_started
 * Stale applying is normalized to uncertain (never auto-retryable).
 */
final class MtUniCreditNativeOrderFinalizationRepository
{
    /** Applying claims older than this become uncertain (not reclaimable). */
    const STALE_APPLYING_SECONDS = 45;

    /** @var MtUniCreditDbAdapter */
    private $db;

    /** @var MtUniCreditPersistenceClock */
    private $clock;

    /**
     * @param MtUniCreditDbAdapter $db
     * @param MtUniCreditPersistenceClock|null $clock
     */
    public function __construct(MtUniCreditDbAdapter $db, $clock = null)
    {
        $this->db = $db;
        $this->clock = $clock instanceof MtUniCreditPersistenceClock
            ? $clock
            : new MtUniCreditPersistenceClock();
    }

    /**
     * @param int $attemptId
     * @return array<string, mixed>|null
     */
    public function findByAttempt($attemptId)
    {
        $attemptId = (int) $attemptId;
        if ($attemptId <= 0) {
            return null;
        }
        $result = $this->db->query(
            "SELECT `attempt_id`, `store_id`, `order_id`,
                    `native_finalize_state`, `native_finalize_claim_owner`,
                    `native_finalize_claimed_at`, `native_finalize_applied_at`,
                    `native_finalize_target_status`, `native_finalize_outcome`
             FROM `" . $this->tableName() . "`
             WHERE `attempt_id` = " . $attemptId . " LIMIT 1"
        );
        if (!is_object($result) || empty($result->num_rows) || !isset($result->row) || !is_array($result->row)) {
            return null;
        }

        return $this->normalizeRow($result->row);
    }

    /**
     * @param int $attemptId
     * @return bool
     */
    public function isOnceComplete($attemptId)
    {
        $row = $this->findByAttempt($attemptId);
        if ($row === null) {
            return false;
        }

        return MtUniCreditNativeOrderFinalizationStates::isOnceComplete(
            (string) $row['native_finalize_state']
        );
    }

    /**
     * Stale applying → uncertain. Never returns the attempt to not_started.
     *
     * @param int $attemptId
     * @return int affected rows (0 or 1)
     */
    public function normalizeStaleApplyingToUncertain($attemptId)
    {
        $attemptId = (int) $attemptId;
        if ($attemptId <= 0) {
            return 0;
        }
        $cutoff = $this->clock->formatUtc($this->clock->now() - self::STALE_APPLYING_SECONDS);
        $now = $this->now();
        $this->db->query(
            "UPDATE `" . $this->tableName() . "`
             SET `native_finalize_state` = '" . MtUniCreditNativeOrderFinalizationStates::UNCERTAIN . "',
                 `native_finalize_claim_owner` = NULL,
                 `updated_at` = '" . $this->db->escape($now) . "'
             WHERE `attempt_id` = " . $attemptId . "
               AND `native_finalize_state` = '" . MtUniCreditNativeOrderFinalizationStates::APPLYING . "'
               AND (
                    `native_finalize_claimed_at` IS NULL
                    OR `native_finalize_claimed_at` <= '" . $this->db->escape($cutoff) . "'
               )"
        );

        return (int) $this->db->countAffected();
    }

    /**
     * Atomic claim before addOrderHistory().
     *
     * Predicate: native_finalize_state = not_started (only).
     *
     * @param int $attemptId
     * @param string $ownerToken
     * @param int $targetStatusId
     * @param string $outcome
     * @return bool true when this worker owns the claim
     */
    public function claimApplying($attemptId, $ownerToken, $targetStatusId, $outcome)
    {
        $attemptId = (int) $attemptId;
        $ownerToken = (string) $ownerToken;
        $targetStatusId = (int) $targetStatusId;
        $outcome = trim((string) $outcome);
        if ($attemptId <= 0 || $targetStatusId <= 0 || $outcome === '') {
            return false;
        }
        if ($ownerToken === '' || !MtUniCreditLockOwnerTokenGenerator::isValidFormat($ownerToken)) {
            $ownerToken = MtUniCreditLockOwnerTokenGenerator::generate();
        }
        $now = $this->now();
        $this->db->query(
            "UPDATE `" . $this->tableName() . "`
             SET `native_finalize_state` = '" . MtUniCreditNativeOrderFinalizationStates::APPLYING . "',
                 `native_finalize_claim_owner` = '" . $this->db->escape($ownerToken) . "',
                 `native_finalize_claimed_at` = '" . $this->db->escape($now) . "',
                 `native_finalize_target_status` = " . $targetStatusId . ",
                 `native_finalize_outcome` = '" . $this->db->escape($outcome) . "',
                 `updated_at` = '" . $this->db->escape($now) . "'
             WHERE `attempt_id` = " . $attemptId . "
               AND `native_finalize_state` = '" . MtUniCreditNativeOrderFinalizationStates::NOT_STARTED . "'"
        );

        return $this->db->countAffected() === 1;
    }

    /**
     * @param int $attemptId
     * @param string $ownerToken
     * @return bool
     */
    public function markApplied($attemptId, $ownerToken)
    {
        $attemptId = (int) $attemptId;
        $ownerToken = (string) $ownerToken;
        if ($attemptId <= 0 || $ownerToken === '') {
            return false;
        }
        $now = $this->now();
        $this->db->query(
            "UPDATE `" . $this->tableName() . "`
             SET `native_finalize_state` = '" . MtUniCreditNativeOrderFinalizationStates::APPLIED . "',
                 `native_finalize_applied_at` = '" . $this->db->escape($now) . "',
                 `native_finalize_claim_owner` = NULL,
                 `updated_at` = '" . $this->db->escape($now) . "'
             WHERE `attempt_id` = " . $attemptId . "
               AND `native_finalize_state` = '" . MtUniCreditNativeOrderFinalizationStates::APPLYING . "'
               AND `native_finalize_claim_owner` = '" . $this->db->escape($ownerToken) . "'"
        );

        return $this->db->countAffected() === 1;
    }

    /**
     * After ambiguous/partial addOrderHistory — never auto-retry.
     *
     * @param int $attemptId
     * @param string $ownerToken Empty token skips owner match (best-effort crash marker).
     * @return bool
     */
    public function markUncertain($attemptId, $ownerToken = '')
    {
        $attemptId = (int) $attemptId;
        $ownerToken = (string) $ownerToken;
        if ($attemptId <= 0) {
            return false;
        }
        $now = $this->now();
        $ownerClause = '';
        if ($ownerToken !== '') {
            $ownerClause = " AND `native_finalize_claim_owner` = '" . $this->db->escape($ownerToken) . "'";
        }
        $this->db->query(
            "UPDATE `" . $this->tableName() . "`
             SET `native_finalize_state` = '" . MtUniCreditNativeOrderFinalizationStates::UNCERTAIN . "',
                 `native_finalize_claim_owner` = NULL,
                 `updated_at` = '" . $this->db->escape($now) . "'
             WHERE `attempt_id` = " . $attemptId . "
               AND `native_finalize_state` = '" . MtUniCreditNativeOrderFinalizationStates::APPLYING . "'"
                . $ownerClause
        );

        return $this->db->countAffected() === 1;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row)
    {
        return array(
            'attempt_id' => (int) $row['attempt_id'],
            'store_id' => (int) $row['store_id'],
            'order_id' => isset($row['order_id']) ? (int) $row['order_id'] : 0,
            'native_finalize_state' => isset($row['native_finalize_state'])
                ? (string) $row['native_finalize_state']
                : MtUniCreditNativeOrderFinalizationStates::NOT_STARTED,
            'native_finalize_claim_owner' => isset($row['native_finalize_claim_owner'])
                ? $row['native_finalize_claim_owner']
                : null,
            'native_finalize_claimed_at' => isset($row['native_finalize_claimed_at'])
                ? $row['native_finalize_claimed_at']
                : null,
            'native_finalize_applied_at' => isset($row['native_finalize_applied_at'])
                ? $row['native_finalize_applied_at']
                : null,
            'native_finalize_target_status' => isset($row['native_finalize_target_status'])
                && $row['native_finalize_target_status'] !== null
                ? (int) $row['native_finalize_target_status']
                : null,
            'native_finalize_outcome' => isset($row['native_finalize_outcome'])
                ? (string) $row['native_finalize_outcome']
                : '',
        );
    }

    /**
     * @return string
     */
    private function now()
    {
        return $this->clock->formatUtc($this->clock->now());
    }

    /**
     * @return string
     */
    private function tableName()
    {
        return $this->db->getPrefix() . MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT;
    }
}
