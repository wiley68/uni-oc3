<?php

/**
 * SQL CAS store for durable CP status sync columns on financing_attempt.
 */
final class MtUniCreditControlPanelStatusSyncRepository implements MtUniCreditControlPanelStatusSyncStoreInterface
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
     * @return array<string, mixed>|null
     */
    public function findByAttempt($attemptId)
    {
        $attemptId = (int) $attemptId;
        if ($attemptId <= 0) {
            return null;
        }

        $table = $this->tableName();
        $result = $this->db->query(
            "SELECT `attempt_id`,
                    `cp_status_sync_state`,
                    `cp_status_sync_status_id`,
                    `cp_status_sync_status`,
                    `cp_status_sync_error_class`,
                    `cp_status_sync_updated_at`
             FROM `{$table}`
             WHERE `attempt_id` = " . $attemptId . ' LIMIT 1'
        );

        return is_object($result) && (int) $result->num_rows === 1 ? $result->row : null;
    }

    /**
     * @param int $attemptId
     * @param string $expectedState
     * @param string|null $expectedStatusId
     * @param string|null $expectedStatus
     * @param string $newStatusId
     * @param string $newStatus
     * @return bool
     */
    public function compareAndSetPendingTarget(
        $attemptId,
        $expectedState,
        $expectedStatusId,
        $expectedStatus,
        $newStatusId,
        $newStatus
    ) {
        $now = $this->now();
        $table = $this->tableName();
        $this->db->query(
            "UPDATE `{$table}` SET
                `cp_status_sync_state` = '" . $this->db->escape(MtUniCreditControlPanelStatusSyncStates::PENDING) . "',
                `cp_status_sync_status_id` = '" . $this->db->escape((string) $newStatusId) . "',
                `cp_status_sync_status` = '" . $this->db->escape((string) $newStatus) . "',
                `cp_status_sync_error_class` = NULL,
                `cp_status_sync_updated_at` = '" . $this->db->escape($now) . "',
                `updated_at` = '" . $this->db->escape($now) . "'
             WHERE `attempt_id` = " . (int) $attemptId . "
               AND `cp_status_sync_state` = '" . $this->db->escape((string) $expectedState) . "'
               AND " . $this->nullSafeEqualsSql('cp_status_sync_status_id', $expectedStatusId) . '
               AND ' . $this->nullSafeEqualsSql('cp_status_sync_status', $expectedStatus)
        );

        return $this->db->countAffected() > 0;
    }

    /**
     * @param int $attemptId
     * @param string $expectedStatusId
     * @param string $expectedStatus
     * @return bool
     */
    public function compareAndSetConfirmed($attemptId, $expectedStatusId, $expectedStatus)
    {
        $now = $this->now();
        $table = $this->tableName();
        $this->db->query(
            "UPDATE `{$table}` SET
                `cp_status_sync_state` = '" . $this->db->escape(MtUniCreditControlPanelStatusSyncStates::CONFIRMED) . "',
                `cp_status_sync_status_id` = '" . $this->db->escape((string) $expectedStatusId) . "',
                `cp_status_sync_status` = '" . $this->db->escape((string) $expectedStatus) . "',
                `cp_status_sync_error_class` = NULL,
                `cp_status_sync_updated_at` = '" . $this->db->escape($now) . "',
                `updated_at` = '" . $this->db->escape($now) . "'
             WHERE `attempt_id` = " . (int) $attemptId . "
               AND `cp_status_sync_state` = '" . $this->db->escape(MtUniCreditControlPanelStatusSyncStates::PENDING) . "'
               AND `cp_status_sync_status_id` = '" . $this->db->escape((string) $expectedStatusId) . "'
               AND `cp_status_sync_status` = '" . $this->db->escape((string) $expectedStatus) . "'"
        );

        return $this->db->countAffected() > 0;
    }

    /**
     * @param int $attemptId
     * @param string $expectedStatusId
     * @param string $expectedStatus
     * @param string $newState
     * @param string $errorClass
     * @return bool
     */
    public function compareAndSetFailure(
        $attemptId,
        $expectedStatusId,
        $expectedStatus,
        $newState,
        $errorClass
    ) {
        if (!in_array(
            $newState,
            array(
                MtUniCreditControlPanelStatusSyncStates::PENDING,
                MtUniCreditControlPanelStatusSyncStates::TERMINAL_FAILED,
            ),
            true
        )) {
            return false;
        }

        $now = $this->now();
        $table = $this->tableName();
        $this->db->query(
            "UPDATE `{$table}` SET
                `cp_status_sync_state` = '" . $this->db->escape((string) $newState) . "',
                `cp_status_sync_error_class` = '" . $this->db->escape((string) $errorClass) . "',
                `cp_status_sync_updated_at` = '" . $this->db->escape($now) . "',
                `updated_at` = '" . $this->db->escape($now) . "'
             WHERE `attempt_id` = " . (int) $attemptId . "
               AND `cp_status_sync_state` = '" . $this->db->escape(MtUniCreditControlPanelStatusSyncStates::PENDING) . "'
               AND `cp_status_sync_status_id` = '" . $this->db->escape((string) $expectedStatusId) . "'
               AND `cp_status_sync_status` = '" . $this->db->escape((string) $expectedStatus) . "'"
        );

        return $this->db->countAffected() > 0;
    }

    /**
     * Mirror OC4 nullSafeEqualsSql: NULL/empty expected matches NULL or '' column.
     *
     * @param string $column
     * @param string|null $value
     * @return string
     */
    private function nullSafeEqualsSql($column, $value)
    {
        if ($value === null || $value === '') {
            return '(`' . $column . '` IS NULL OR `' . $column . "` = '')";
        }

        return '`' . $column . "` <=> '" . $this->db->escape((string) $value) . "'";
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
