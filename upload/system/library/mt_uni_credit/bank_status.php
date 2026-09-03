<?php

/**
 * Local bank status identifiers for Process 1 / SmartUCF outcomes.
 */
final class MtUniCreditBankStatus
{
    const SENT_PROCESS1 = 'bank_sent_process1';
    const SENT_PROCESS2 = 'bank_sent_process2';
    const SEND_FAILED_SMARTUCF = 'bank_send_failed_smartucf';

    const LABEL_SENT_PROCESS1 = 'Изпратен Банка - Процес 1';
    const LABEL_SENT_PROCESS2 = 'Изпратен Банка - Процес 2';
    const LABEL_SEND_FAILED_SMARTUCF = 'Неуспешно изпратен Банка - SmartUCF';

    /**
     * @return array{status_id: string, status_label: string}
     */
    public static function process1Sent()
    {
        return array(
            'status_id' => self::SENT_PROCESS1,
            'status_label' => self::LABEL_SENT_PROCESS1,
        );
    }

    /**
     * @return array{status_id: string, status_label: string}
     */
    public static function process2Sent()
    {
        return array(
            'status_id' => self::SENT_PROCESS2,
            'status_label' => self::LABEL_SENT_PROCESS2,
        );
    }

    /**
     * @return array{status_id: string, status_label: string}
     */
    public static function smartUcfFailure()
    {
        return array(
            'status_id' => self::SEND_FAILED_SMARTUCF,
            'status_label' => self::LABEL_SEND_FAILED_SMARTUCF,
        );
    }
}
