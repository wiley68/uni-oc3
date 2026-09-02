<?php

/**
 * Accepted inbound bank / CP status_id vocabulary for order-bank-status.
 */
final class MtUniCreditInboundBankStatusVocabulary
{
    /** @var array<int, string> */
    private static $allowedStatusIds = array(
        'cp_sent',
        'smartucf_sent',
        'bank_sent_process1',
        'bank_sent_process2',
        'bank_send_failed',
        'bank_send_failed_cp',
        'bank_send_failed_smartucf',
    );

    /**
     * @param string $statusId
     * @return bool
     */
    public static function isAccepted($statusId)
    {
        $statusId = strtolower(trim($statusId));
        if ($statusId === '') {
            return false;
        }

        if (in_array($statusId, self::$allowedStatusIds, true)) {
            return true;
        }

        return (bool) preg_match('/^\d{1,3}$/', $statusId);
    }
}
