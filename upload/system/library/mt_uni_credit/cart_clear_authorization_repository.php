<?php

/**
 * Atomic durable one-shot Cart clear claim on financing_attempt (AUD-020-F01-R1).
 *
 * Claim predicate: cart_clear_state = not_applied only.
 * applying / applied never authorize another clear.
 */
final class MtUniCreditCartClearAuthorizationRepository
{
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
     * @return string
     */
    public function getState($attemptId)
    {
        $attemptId = (int) $attemptId;
        if ($attemptId <= 0) {
            return '';
        }
        $result = $this->db->query(
            "SELECT `cart_clear_state`
             FROM `" . $this->tableName() . "`
             WHERE `attempt_id` = " . $attemptId . " LIMIT 1"
        );
        if (!is_object($result) || empty($result->num_rows) || !isset($result->row['cart_clear_state'])) {
            return MtUniCreditCartClearStates::NOT_APPLIED;
        }

        $state = (string) $result->row['cart_clear_state'];

        return MtUniCreditCartClearStates::isValid($state)
            ? $state
            : MtUniCreditCartClearStates::NOT_APPLIED;
    }

    /**
     * Atomic claim before $cart->clear().
     *
     * @param int $attemptId
     * @return bool true when this request owns the clear claim
     */
    public function claimApplying($attemptId)
    {
        $attemptId = (int) $attemptId;
        if ($attemptId <= 0) {
            return false;
        }
        $now = $this->now();
        $this->db->query(
            "UPDATE `" . $this->tableName() . "`
             SET `cart_clear_state` = '" . MtUniCreditCartClearStates::APPLYING . "',
                 `cart_clear_claimed_at` = '" . $this->db->escape($now) . "',
                 `updated_at` = '" . $this->db->escape($now) . "'
             WHERE `attempt_id` = " . $attemptId . "
               AND `cart_clear_state` = '" . MtUniCreditCartClearStates::NOT_APPLIED . "'"
        );

        return $this->db->countAffected() === 1;
    }

    /**
     * Persist applied after successful cart->clear().
     *
     * @param int $attemptId
     * @return bool
     */
    public function markApplied($attemptId)
    {
        $attemptId = (int) $attemptId;
        if ($attemptId <= 0) {
            return false;
        }
        $now = $this->now();
        $this->db->query(
            "UPDATE `" . $this->tableName() . "`
             SET `cart_clear_state` = '" . MtUniCreditCartClearStates::APPLIED . "',
                 `cart_clear_applied_at` = '" . $this->db->escape($now) . "',
                 `updated_at` = '" . $this->db->escape($now) . "'
             WHERE `attempt_id` = " . $attemptId . "
               AND `cart_clear_state` = '" . MtUniCreditCartClearStates::APPLYING . "'"
        );

        return $this->db->countAffected() === 1;
    }

    /**
     * Test/harness helper: force a durable state without going through clear.
     *
     * @param int $attemptId
     * @param string $state
     * @return bool
     */
    public function forceState($attemptId, $state)
    {
        $attemptId = (int) $attemptId;
        $state = (string) $state;
        if ($attemptId <= 0 || !MtUniCreditCartClearStates::isValid($state)) {
            return false;
        }
        $now = $this->now();
        $claimed = 'NULL';
        $applied = 'NULL';
        if ($state === MtUniCreditCartClearStates::APPLYING || $state === MtUniCreditCartClearStates::APPLIED) {
            $claimed = "'" . $this->db->escape($now) . "'";
        }
        if ($state === MtUniCreditCartClearStates::APPLIED) {
            $applied = "'" . $this->db->escape($now) . "'";
        }
        $this->db->query(
            "UPDATE `" . $this->tableName() . "`
             SET `cart_clear_state` = '" . $this->db->escape($state) . "',
                 `cart_clear_claimed_at` = " . $claimed . ",
                 `cart_clear_applied_at` = " . $applied . ",
                 `updated_at` = '" . $this->db->escape($now) . "'
             WHERE `attempt_id` = " . $attemptId
        );

        return $this->db->countAffected() === 1;
    }

    /**
     * @return string
     */
    private function tableName()
    {
        return $this->db->getPrefix() . MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT;
    }

    /**
     * @return string
     */
    private function now()
    {
        return $this->clock->formatUtc($this->clock->now());
    }
}
