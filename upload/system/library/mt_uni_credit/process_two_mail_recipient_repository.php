<?php

/**
 * Durable per-recipient Process 2 mail delivery claims/state.
 */
final class MtUniCreditProcessTwoMailRecipientRepository
{
    /** Stale sending claims become uncertain (no blind auto-resend). */
    const STALE_SENDING_SECONDS = 45;

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
     * @param string $email
     * @return string
     */
    public static function normalizeRecipientKey($email)
    {
        return strtolower(trim((string) $email));
    }

    /**
     * Ensure durable rows exist for each required recipient (INSERT IGNORE).
     *
     * @param int $attemptId
     * @param array<int, array{audience: string, email: string, recipient_key?: string}> $recipients
     * @return void
     */
    public function ensureRecipients($attemptId, array $recipients)
    {
        $attemptId = (int) $attemptId;
        if ($attemptId <= 0) {
            return;
        }
        $now = $this->now();
        $table = $this->tableName();
        foreach ($recipients as $recipient) {
            $audience = isset($recipient['audience']) ? (string) $recipient['audience'] : '';
            $email = isset($recipient['email']) ? trim((string) $recipient['email']) : '';
            $key = isset($recipient['recipient_key'])
                ? self::normalizeRecipientKey($recipient['recipient_key'])
                : self::normalizeRecipientKey($email);
            if ($audience === '' || $email === '' || $key === '') {
                continue;
            }
            $this->db->query(
                "INSERT IGNORE INTO `{$table}`"
                    . " (`attempt_id`, `audience`, `recipient_key`, `recipient_email`, `state`,"
                    . " `claim_owner_token`, `claimed_at`, `created_at`, `updated_at`)"
                    . " VALUES ("
                    . $attemptId . ","
                    . " '" . $this->db->escape($audience) . "',"
                    . " '" . $this->db->escape($key) . "',"
                    . " '" . $this->db->escape($email) . "',"
                    . " '" . MtUniCreditProcessTwoMailRecipientStates::PENDING . "',"
                    . " NULL, NULL,"
                    . " '" . $this->db->escape($now) . "',"
                    . " '" . $this->db->escape($now) . "'"
                    . ")"
            );
        }
    }

    /**
     * @param int $attemptId
     * @return array<int, array<string, mixed>>
     */
    public function listByAttempt($attemptId)
    {
        $attemptId = (int) $attemptId;
        if ($attemptId <= 0) {
            return array();
        }
        $result = $this->db->query(
            "SELECT * FROM `" . $this->tableName() . "`"
                . " WHERE `attempt_id` = " . $attemptId
                . " ORDER BY `process2_mail_recipient_id` ASC"
        );
        if (!is_object($result) || !isset($result->rows) || !is_array($result->rows)) {
            return array();
        }

        return $result->rows;
    }

    /**
     * @param int $attemptId
     * @param string $recipientKey
     * @return array<string, mixed>|null
     */
    public function find($attemptId, $recipientKey)
    {
        $attemptId = (int) $attemptId;
        $recipientKey = self::normalizeRecipientKey($recipientKey);
        if ($attemptId <= 0 || $recipientKey === '') {
            return null;
        }
        $result = $this->db->query(
            "SELECT * FROM `" . $this->tableName() . "`"
                . " WHERE `attempt_id` = " . $attemptId
                . " AND `recipient_key` = '" . $this->db->escape($recipientKey) . "'"
                . " LIMIT 1"
        );

        return is_object($result) && (int) $result->num_rows === 1 ? $result->row : null;
    }

    /**
     * Atomic claim: pending/failed → sending. Never claims sent/uncertain/active sending.
     *
     * @param int $attemptId
     * @param string $recipientKey
     * @param string $ownerToken
     * @return bool
     */
    public function claimForSending($attemptId, $recipientKey, $ownerToken)
    {
        $attemptId = (int) $attemptId;
        $recipientKey = self::normalizeRecipientKey($recipientKey);
        $ownerToken = (string) $ownerToken;
        if ($attemptId <= 0 || $recipientKey === '' || !MtUniCreditLockOwnerTokenGenerator::isValidFormat($ownerToken)) {
            return false;
        }
        $now = $this->now();
        $this->db->query(
            "UPDATE `" . $this->tableName() . "`"
                . " SET `state` = '" . MtUniCreditProcessTwoMailRecipientStates::SENDING . "',"
                . " `claim_owner_token` = '" . $this->db->escape($ownerToken) . "',"
                . " `claimed_at` = '" . $this->db->escape($now) . "',"
                . " `updated_at` = '" . $this->db->escape($now) . "'"
                . " WHERE `attempt_id` = " . $attemptId
                . " AND `recipient_key` = '" . $this->db->escape($recipientKey) . "'"
                . " AND `state` IN ("
                . " '" . MtUniCreditProcessTwoMailRecipientStates::PENDING . "',"
                . " '" . MtUniCreditProcessTwoMailRecipientStates::FAILED . "'"
                . ")"
        );

        return $this->db->countAffected() === 1;
    }

    /**
     * @param int $attemptId
     * @param string $recipientKey
     * @param string $ownerToken
     * @return bool
     */
    public function markSent($attemptId, $recipientKey, $ownerToken)
    {
        $attemptId = (int) $attemptId;
        $recipientKey = self::normalizeRecipientKey($recipientKey);
        $ownerToken = (string) $ownerToken;
        if ($attemptId <= 0 || $recipientKey === '') {
            return false;
        }
        $now = $this->now();
        $this->db->query(
            "UPDATE `" . $this->tableName() . "`"
                . " SET `state` = '" . MtUniCreditProcessTwoMailRecipientStates::SENT . "',"
                . " `claim_owner_token` = NULL,"
                . " `claimed_at` = NULL,"
                . " `updated_at` = '" . $this->db->escape($now) . "'"
                . " WHERE `attempt_id` = " . $attemptId
                . " AND `recipient_key` = '" . $this->db->escape($recipientKey) . "'"
                . " AND `state` = '" . MtUniCreditProcessTwoMailRecipientStates::SENDING . "'"
                . " AND `claim_owner_token` = '" . $this->db->escape($ownerToken) . "'"
        );

        return $this->db->countAffected() === 1;
    }

    /**
     * @param int $attemptId
     * @param string $recipientKey
     * @param string $ownerToken
     * @return void
     */
    public function markFailed($attemptId, $recipientKey, $ownerToken)
    {
        $attemptId = (int) $attemptId;
        $recipientKey = self::normalizeRecipientKey($recipientKey);
        $ownerToken = (string) $ownerToken;
        if ($attemptId <= 0 || $recipientKey === '') {
            return;
        }
        $now = $this->now();
        $this->db->query(
            "UPDATE `" . $this->tableName() . "`"
                . " SET `state` = '" . MtUniCreditProcessTwoMailRecipientStates::FAILED . "',"
                . " `claim_owner_token` = NULL,"
                . " `claimed_at` = NULL,"
                . " `updated_at` = '" . $this->db->escape($now) . "'"
                . " WHERE `attempt_id` = " . $attemptId
                . " AND `recipient_key` = '" . $this->db->escape($recipientKey) . "'"
                . " AND `state` = '" . MtUniCreditProcessTwoMailRecipientStates::SENDING . "'"
                . " AND `claim_owner_token` = '" . $this->db->escape($ownerToken) . "'"
        );
    }

    /**
     * Ambiguous send-before-marker / crash-after-send: never auto-retry as pending.
     *
     * @param int $attemptId
     * @param string $recipientKey
     * @return void
     */
    public function markUncertain($attemptId, $recipientKey)
    {
        $attemptId = (int) $attemptId;
        $recipientKey = self::normalizeRecipientKey($recipientKey);
        if ($attemptId <= 0 || $recipientKey === '') {
            return;
        }
        $now = $this->now();
        $this->db->query(
            "UPDATE `" . $this->tableName() . "`"
                . " SET `state` = '" . MtUniCreditProcessTwoMailRecipientStates::UNCERTAIN . "',"
                . " `claim_owner_token` = NULL,"
                . " `claimed_at` = NULL,"
                . " `updated_at` = '" . $this->db->escape($now) . "'"
                . " WHERE `attempt_id` = " . $attemptId
                . " AND `recipient_key` = '" . $this->db->escape($recipientKey) . "'"
                . " AND `state` = '" . MtUniCreditProcessTwoMailRecipientStates::SENDING . "'"
        );
    }

    /**
     * Stale sending rows → uncertain (crash between external send and durable marker).
     *
     * @param int $attemptId
     * @return int
     */
    public function normalizeStaleSendingToUncertain($attemptId)
    {
        $attemptId = (int) $attemptId;
        if ($attemptId <= 0) {
            return 0;
        }
        $cutoff = $this->clock->formatUtc($this->clock->now() - self::STALE_SENDING_SECONDS);
        $now = $this->now();
        $this->db->query(
            "UPDATE `" . $this->tableName() . "`"
                . " SET `state` = '" . MtUniCreditProcessTwoMailRecipientStates::UNCERTAIN . "',"
                . " `claim_owner_token` = NULL,"
                . " `claimed_at` = NULL,"
                . " `updated_at` = '" . $this->db->escape($now) . "'"
                . " WHERE `attempt_id` = " . $attemptId
                . " AND `state` = '" . MtUniCreditProcessTwoMailRecipientStates::SENDING . "'"
                . " AND (`claimed_at` IS NULL OR `claimed_at` <= '" . $this->db->escape($cutoff) . "')"
        );

        return $this->db->countAffected();
    }

    /**
     * True when every required recipient row is durably sent (or no recipients).
     *
     * @param int $attemptId
     * @return bool
     */
    public function areAllRecipientsSent($attemptId)
    {
        $rows = $this->listByAttempt($attemptId);
        if ($rows === array()) {
            return true;
        }
        foreach ($rows as $row) {
            if ((string) (isset($row['state']) ? $row['state'] : '') !== MtUniCreditProcessTwoMailRecipientStates::SENT) {
                return false;
            }
        }

        return true;
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
        return $this->db->getPrefix() . MtUniCreditPersistenceTableNames::PROCESS2_MAIL_RECIPIENT;
    }
}
