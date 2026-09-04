<?php

/**
 * Process 2 lifecycle columns on financing_attempt.
 */
final class MtUniCreditProcessTwoLifecycleRepository
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
     * @param string $encryptedPayload
     * @return void
     */
    public function persistSensitiveEncrypted($attemptId, $encryptedPayload)
    {
        $attemptId = (int) $attemptId;
        $encryptedPayload = (string) $encryptedPayload;
        if ($attemptId <= 0 || $encryptedPayload === '') {
            throw new MtUniCreditPersistenceValidationException('Process 2 sensitive payload could not be stored.');
        }
        $now = $this->now();
        $this->db->query(
            "UPDATE `" . $this->tableName() . "`
             SET `process2_sensitive_enc` = '" . $this->db->escape($encryptedPayload) . "',
                 `updated_at` = '" . $this->db->escape($now) . "'
             WHERE `attempt_id` = " . $attemptId
        );
        if ($this->db->countAffected() !== 1) {
            throw new MtUniCreditPersistenceException('Process 2 sensitive payload update failed.');
        }
    }

    /**
     * @param int $attemptId
     * @param string $json
     * @return void
     */
    public function persistLeasingPresentationJson($attemptId, $json)
    {
        $attemptId = (int) $attemptId;
        $json = (string) $json;
        if ($attemptId <= 0 || $json === '') {
            return;
        }
        $now = $this->now();
        $this->db->query(
            "UPDATE `" . $this->tableName() . "`
             SET `leasing_presentation_json` = '" . $this->db->escape($json) . "',
                 `updated_at` = '" . $this->db->escape($now) . "'
             WHERE `attempt_id` = " . $attemptId
        );
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
            "SELECT `attempt_id`, `process2_state`, `process2_sensitive_enc`, `process2_mail_sent`,
                    `leasing_presentation_json`,
                    `store_id`, `order_id`, `control_panel_order_id`, `state`
             FROM `" . $this->tableName() . "`
             WHERE `attempt_id` = " . $attemptId . ' LIMIT 1'
        );

        return is_object($result) && (int) $result->num_rows === 1 ? $result->row : null;
    }

    /**
     * @param int $attemptId
     * @return bool
     */
    public function claimPreparing($attemptId)
    {
        $attemptId = (int) $attemptId;
        $now = $this->now();
        $this->db->query(
            "UPDATE `" . $this->tableName() . "`
             SET `process2_state` = '" . MtUniCreditProcessTwoLifecycleStates::PREPARING . "',
                 `updated_at` = '" . $this->db->escape($now) . "'
             WHERE `attempt_id` = " . $attemptId . "
               AND `process2_state` IN (
                    '" . MtUniCreditProcessTwoLifecycleStates::NOT_STARTED . "',
                    '" . MtUniCreditProcessTwoLifecycleStates::FAILED . "'
               )"
        );

        return $this->db->countAffected() === 1;
    }

    /**
     * @param int $attemptId
     * @return void
     */
    public function markPrepared($attemptId)
    {
        $attemptId = (int) $attemptId;
        $now = $this->now();
        $this->db->query(
            "UPDATE `" . $this->tableName() . "`
             SET `process2_state` = '" . MtUniCreditProcessTwoLifecycleStates::PREPARED . "',
                 `updated_at` = '" . $this->db->escape($now) . "'
             WHERE `attempt_id` = " . $attemptId . "
               AND `process2_state` IN (
                    '" . MtUniCreditProcessTwoLifecycleStates::PREPARING . "',
                    '" . MtUniCreditProcessTwoLifecycleStates::PREPARED . "'
               )"
        );
        if ($this->db->countAffected() < 1) {
            $row = $this->findByAttempt($attemptId);
            if (
                $row !== null
                && (string) (isset($row['process2_state']) ? $row['process2_state'] : '')
                    === MtUniCreditProcessTwoLifecycleStates::PREPARED
            ) {
                return;
            }
            throw new MtUniCreditPersistenceException('Process 2 prepared transition failed.');
        }
    }

    /**
     * @param int $attemptId
     * @return void
     */
    public function markFailed($attemptId)
    {
        $attemptId = (int) $attemptId;
        $now = $this->now();
        $this->db->query(
            "UPDATE `" . $this->tableName() . "`
             SET `process2_state` = '" . MtUniCreditProcessTwoLifecycleStates::FAILED . "',
                 `updated_at` = '" . $this->db->escape($now) . "'
             WHERE `attempt_id` = " . $attemptId . "
               AND `process2_state` = '" . MtUniCreditProcessTwoLifecycleStates::PREPARING . "'"
        );
    }

    /**
     * @param int $attemptId
     * @return bool
     */
    public function isMailSent($attemptId)
    {
        $row = $this->findByAttempt((int) $attemptId);

        return $row !== null && !empty($row['process2_mail_sent']);
    }

    /**
     * @param int $attemptId
     * @return void
     */
    public function markMailSent($attemptId)
    {
        $attemptId = (int) $attemptId;
        $now = $this->now();
        $this->db->query(
            "UPDATE `" . $this->tableName() . "`
             SET `process2_mail_sent` = 1,
                 `updated_at` = '" . $this->db->escape($now) . "'
             WHERE `attempt_id` = " . $attemptId
        );
    }

    /**
     * @param int $retentionDays
     * @param int $limit
     * @return int
     */
    public function redactExpiredSensitiveBatch($retentionDays = 180, $limit = 100)
    {
        $retentionDays = max(1, (int) $retentionDays);
        $limit = max(1, min(500, (int) $limit));
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($retentionDays * 86400));
        $this->db->query(
            "UPDATE `" . $this->tableName() . "`
             SET `process2_sensitive_enc` = NULL,
                 `updated_at` = '" . $this->db->escape($this->now()) . "'
             WHERE `process2_sensitive_enc` IS NOT NULL
               AND `updated_at` < '" . $this->db->escape($cutoff) . "'
             LIMIT " . $limit
        );

        return $this->db->countAffected();
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
