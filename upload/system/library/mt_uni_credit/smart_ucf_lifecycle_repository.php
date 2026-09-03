<?php

/**
 * SmartUCF lifecycle columns on the financing attempt table.
 */
final class MtUniCreditSmartUcfLifecycleRepository
{
    const STALE_SUBMITTING_SECONDS = 45;

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
            "SELECT * FROM `{$this->tableName()}` WHERE `attempt_id` = " . $attemptId . ' LIMIT 1'
        );

        return is_object($result) && (int) $result->num_rows === 1 ? $result->row : null;
    }

    /**
     * @param int $attemptId
     * @return array<string, mixed>|null
     */
    public function readAndNormalize($attemptId)
    {
        $row = $this->findByAttempt($attemptId);
        if ($row !== null && $this->isStaleSubmitting($row)) {
            try {
                $this->markOutcomeUnknown(
                    $attemptId,
                    MtUniCreditSmartUcfFailureClassification::CLASS_TRANSPORT_AMBIGUOUS,
                    (int) (isset($row['smartucf_http_code']) ? $row['smartucf_http_code'] : 0)
                );
            } catch (Throwable $exception) {
                // Never authorize another remote create when the stale-state write races.
            }
            $refreshed = $this->findByAttempt($attemptId);
            $row = $refreshed !== null ? $refreshed : $row;
        }

        return $row;
    }

    /**
     * @param int $attemptId
     * @return array<string, mixed>|null
     */
    public function claimForSubmitting($attemptId)
    {
        $now = $this->now();
        $table = $this->tableName();
        $this->db->query(
            "UPDATE `{$table}`
             SET `smartucf_state` = '" . MtUniCreditSmartUcfLifecycleStates::SUBMITTING . "',
                 `smartucf_claimed_at` = '" . $this->db->escape($now) . "',
                 `smartucf_error_class` = NULL,
                 `smartucf_http_code` = NULL,
                 `smartucf_retryable` = 0,
                 `smartucf_completed_at` = NULL,
                 `updated_at` = '" . $this->db->escape($now) . "'
             WHERE `attempt_id` = " . (int) $attemptId . "
               AND (
                    `smartucf_state` = '" . MtUniCreditSmartUcfLifecycleStates::NOT_STARTED . "'
                    OR (`smartucf_state` = '" . MtUniCreditSmartUcfLifecycleStates::FAILED . "' AND `smartucf_retryable` = 1)
               )"
        );

        return $this->db->countAffected() === 1 ? $this->findByAttempt($attemptId) : null;
    }

    /**
     * @param int $attemptId
     * @param string $sessionId
     * @param string $redirectUrl
     * @param int $httpCode
     * @return void
     */
    public function markCreated($attemptId, $sessionId, $redirectUrl, $httpCode)
    {
        $this->transitionTerminal($attemptId, MtUniCreditSmartUcfLifecycleStates::CREATED, array(
            'smartucf_session_id' => substr($sessionId, 0, 128),
            'smartucf_redirect_url' => substr($redirectUrl, 0, 768),
            'smartucf_http_code' => $httpCode > 0 ? (int) $httpCode : null,
            'smartucf_error_class' => null,
            'smartucf_retryable' => 0,
        ));
    }

    /**
     * @param int $attemptId
     * @param string $errorClass
     * @param int $httpCode
     * @return void
     */
    public function markOutcomeUnknown($attemptId, $errorClass, $httpCode = 0)
    {
        $this->transitionTerminal($attemptId, MtUniCreditSmartUcfLifecycleStates::OUTCOME_UNKNOWN, array(
            'smartucf_error_class' => substr($errorClass, 0, 64),
            'smartucf_http_code' => $httpCode > 0 ? (int) $httpCode : null,
            'smartucf_retryable' => 0,
        ), array(
            MtUniCreditSmartUcfLifecycleStates::SUBMITTING,
            MtUniCreditSmartUcfLifecycleStates::NOT_STARTED,
        ));
    }

    /**
     * @param int $attemptId
     * @param string $errorClass
     * @param bool $retryable
     * @param int $httpCode
     * @return void
     */
    public function markFailed($attemptId, $errorClass, $retryable, $httpCode = 0)
    {
        $this->transitionTerminal($attemptId, MtUniCreditSmartUcfLifecycleStates::FAILED, array(
            'smartucf_error_class' => substr($errorClass, 0, 64),
            'smartucf_http_code' => $httpCode > 0 ? (int) $httpCode : null,
            'smartucf_retryable' => $retryable ? 1 : 0,
        ), array(
            MtUniCreditSmartUcfLifecycleStates::SUBMITTING,
            MtUniCreditSmartUcfLifecycleStates::NOT_STARTED,
        ));
    }

    /**
     * @param array<string, mixed> $row
     * @return bool
     */
    public function isStaleSubmitting(array $row)
    {
        if ((string) (isset($row['smartucf_state']) ? $row['smartucf_state'] : '') !== MtUniCreditSmartUcfLifecycleStates::SUBMITTING) {
            return false;
        }
        $timestamp = strtotime((string) (isset($row['smartucf_claimed_at']) ? $row['smartucf_claimed_at'] : '') . ' UTC');

        return $timestamp === false || time() - $timestamp >= self::STALE_SUBMITTING_SECONDS;
    }

    /**
     * @param int $attemptId
     * @param string $state
     * @param array<string, mixed> $values
     * @param array<int, string>|null $fromStates
     * @return void
     */
    private function transitionTerminal($attemptId, $state, array $values, $fromStates = null)
    {
        if (!is_array($fromStates)) {
            $fromStates = array(MtUniCreditSmartUcfLifecycleStates::SUBMITTING);
        }
        $now = $this->now();
        $assignments = array(
            "`smartucf_state` = '" . $this->db->escape($state) . "'",
            "`smartucf_completed_at` = '" . $this->db->escape($now) . "'",
            "`updated_at` = '" . $this->db->escape($now) . "'",
        );
        foreach ($values as $column => $value) {
            if ($value === null) {
                $assignments[] = '`' . $column . '` = NULL';
            } elseif (is_int($value)) {
                $assignments[] = '`' . $column . '` = ' . (string) $value;
            } else {
                $assignments[] = '`' . $column . '` = \'' . $this->db->escape((string) $value) . '\'';
            }
        }
        $escapedStates = array();
        foreach ($fromStates as $fromState) {
            $escapedStates[] = "'" . $this->db->escape($fromState) . "'";
        }
        $states = implode(', ', $escapedStates);
        $this->db->query(
            "UPDATE `{$this->tableName()}` SET " . implode(', ', $assignments)
                . ' WHERE `attempt_id` = ' . (int) $attemptId . " AND `smartucf_state` IN ({$states})"
        );
        if ($this->db->countAffected() !== 1) {
            throw new MtUniCreditPersistenceException('SmartUCF lifecycle transition did not update exactly one row.');
        }
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
