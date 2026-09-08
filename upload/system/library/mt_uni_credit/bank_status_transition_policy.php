<?php

/**
 * Authoritative local bank-status transition policy (AUD-015 F01).
 *
 * P1 and P2 are alternative process outcomes, not ranked levels.
 * Inbound callbacks must not regress locally proven durable terminals.
 */
final class MtUniCreditBankStatusTransitionPolicy
{
    const SOURCE_LOCAL_LIFECYCLE = 'local_lifecycle';
    const SOURCE_INBOUND_CALLBACK = 'inbound_callback';

    const DECISION_ALLOW = 'allow';
    const DECISION_NOOP = 'noop';
    const DECISION_REJECT = 'reject';

    /**
     * @param string|null $currentStatusId empty/null = no row
     * @param string $proposedStatusId
     * @param string $source
     * @return string DECISION_*
     */
    public static function decide($currentStatusId, $proposedStatusId, $source)
    {
        $proposed = strtolower(trim((string) $proposedStatusId));
        $source = self::normalizeSource($source);
        if ($proposed === '' || !self::isRecognizedStatusId($proposed)) {
            return self::DECISION_REJECT;
        }

        $current = $currentStatusId === null ? '' : strtolower(trim((string) $currentStatusId));
        if ($current === '') {
            return self::DECISION_ALLOW;
        }

        if ($current === $proposed) {
            return self::DECISION_NOOP;
        }

        $currentDurable = self::isDurableTerminal($current);
        $proposedDurable = self::isDurableTerminal($proposed);

        // Durable terminal/success/failure must not be overwritten by intermediate/stale data.
        if ($currentDurable && !$proposedDurable) {
            return self::DECISION_REJECT;
        }

        // Durable ↔ different durable: never via inbound; never cross-process / failure↔sent.
        if ($currentDurable && $proposedDurable) {
            return self::DECISION_REJECT;
        }

        // Intermediate/numeric → intermediate/numeric: allow.
        if (!$currentDurable && !$proposedDurable) {
            return self::DECISION_ALLOW;
        }

        // Intermediate/numeric → durable terminal.
        // Local lifecycle always may advance. Inbound may also advance from intermediate,
        // but never regress a durable (handled above).
        if (!$currentDurable && $proposedDurable) {
            return self::DECISION_ALLOW;
        }

        return self::DECISION_REJECT;
    }

    /**
     * Current status_id values from which $proposed may be applied (for CAS UPDATE).
     *
     * @param string $proposedStatusId
     * @param string $source
     * @return array<int, string>
     */
    public static function allowedFromStatusIds($proposedStatusId, $source)
    {
        $proposed = strtolower(trim((string) $proposedStatusId));
        $allowed = array();
        foreach (self::knownNamedStatusIds() as $from) {
            if (self::decide($from, $proposed, $source) === self::DECISION_ALLOW) {
                $allowed[] = $from;
            }
        }

        return $allowed;
    }

    /**
     * @param string $statusId
     * @return bool
     */
    public static function isDurableTerminal($statusId)
    {
        $statusId = strtolower(trim((string) $statusId));

        return $statusId === MtUniCreditBankStatus::SENT_PROCESS1
            || $statusId === MtUniCreditBankStatus::SENT_PROCESS2
            || $statusId === MtUniCreditBankStatus::SEND_FAILED_SMARTUCF
            || $statusId === MtUniCreditBankStatus::SEND_FAILED_CP;
    }

    /**
     * @param string $statusId
     * @return bool
     */
    public static function isIntermediateNamed($statusId)
    {
        $statusId = strtolower(trim((string) $statusId));

        return $statusId === MtUniCreditBankStatus::CP_SENT
            || $statusId === MtUniCreditBankStatus::SMARTUCF_SENT
            || $statusId === MtUniCreditBankStatus::SEND_FAILED;
    }

    /**
     * @param string $statusId
     * @return bool
     */
    public static function isNumericExternal($statusId)
    {
        return (bool) preg_match('/^\d{1,3}$/', strtolower(trim((string) $statusId)));
    }

    /**
     * @param string $statusId
     * @return bool
     */
    public static function isRecognizedStatusId($statusId)
    {
        $statusId = strtolower(trim((string) $statusId));
        if (in_array($statusId, self::knownNamedStatusIds(), true)) {
            return true;
        }

        return self::isNumericExternal($statusId);
    }

    /**
     * @return array<int, string>
     */
    public static function knownNamedStatusIds()
    {
        return array(
            MtUniCreditBankStatus::CP_SENT,
            MtUniCreditBankStatus::SMARTUCF_SENT,
            MtUniCreditBankStatus::SENT_PROCESS1,
            MtUniCreditBankStatus::SENT_PROCESS2,
            MtUniCreditBankStatus::SEND_FAILED,
            MtUniCreditBankStatus::SEND_FAILED_CP,
            MtUniCreditBankStatus::SEND_FAILED_SMARTUCF,
        );
    }

    /**
     * @param string $source
     * @return string
     */
    public static function normalizeSource($source)
    {
        $source = trim((string) $source);
        if ($source === self::SOURCE_LOCAL_LIFECYCLE) {
            return self::SOURCE_LOCAL_LIFECYCLE;
        }

        return self::SOURCE_INBOUND_CALLBACK;
    }
}
