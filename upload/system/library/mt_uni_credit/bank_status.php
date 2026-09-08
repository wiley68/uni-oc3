<?php

/**
 * Local bank status identifiers for Process 1 / SmartUCF outcomes.
 */
final class MtUniCreditBankStatus
{
    const SENT_PROCESS1 = 'bank_sent_process1';
    const SENT_PROCESS2 = 'bank_sent_process2';
    const SEND_FAILED = 'bank_send_failed';
    const SEND_FAILED_CP = 'bank_send_failed_cp';
    const SEND_FAILED_SMARTUCF = 'bank_send_failed_smartucf';
    const CP_SENT = 'cp_sent';
    const SMARTUCF_SENT = 'smartucf_sent';

    const LABEL_SENT_PROCESS1 = 'Изпратен Банка - Процес 1';
    const LABEL_SENT_PROCESS2 = 'Изпратен Банка - Процес 2';
    const LABEL_SEND_FAILED = 'Неуспешно изпратен Банка';
    const LABEL_SEND_FAILED_CP = 'Неуспешно изпратен Банка - КП';
    const LABEL_SEND_FAILED_SMARTUCF = 'Неуспешно изпратен Банка - SmartUCF';
    const LABEL_CP_SENT = 'Създаден в КП Банка';
    const LABEL_SMARTUCF_SENT = 'Създаден в SmartUCF';

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

    /**
     * Local CP-create failure (no CP order). Woo/PS8/PS9 authority.
     * Checkout OC3 uses SEND_FAILED_CP for both Process 1 and Process 2
     * (cross-module manual parity; not the current OC4 stay-on-Checkout defect).
     *
     * @param bool $process2 Unused for Checkout OC3 parity (always CP label).
     * @return array{status_id: string, status_label: string}
     */
    public static function controlPanelFailure($process2 = false)
    {
        // Cross-module Checkout broken-CP authority: always bank_send_failed_cp.
        // PS Process-2 generic bank_send_failed is intentionally not used here.
        unset($process2);

        return array(
            'status_id' => self::SEND_FAILED_CP,
            'status_label' => self::LABEL_SEND_FAILED_CP,
        );
    }

    /**
     * Server-side canonical BG label for named status codes (AUD-015 F04).
     * Returns null for numeric / unknown codes (display-only inbound label may apply).
     *
     * @param string $statusId
     * @return string|null
     */
    public static function canonicalLabel($statusId)
    {
        $statusId = strtolower(trim((string) $statusId));
        switch ($statusId) {
            case self::SENT_PROCESS1:
                return self::LABEL_SENT_PROCESS1;
            case self::SENT_PROCESS2:
                return self::LABEL_SENT_PROCESS2;
            case self::SEND_FAILED_SMARTUCF:
                return self::LABEL_SEND_FAILED_SMARTUCF;
            case self::SEND_FAILED_CP:
                return self::LABEL_SEND_FAILED_CP;
            case self::SEND_FAILED:
                return self::LABEL_SEND_FAILED;
            case self::CP_SENT:
                return self::LABEL_CP_SENT;
            case self::SMARTUCF_SENT:
                return self::LABEL_SMARTUCF_SENT;
            default:
                return null;
        }
    }

    /**
     * Resolve display label: canonical for named codes; otherwise trimmed inbound/display label.
     *
     * @param string $statusId
     * @param string $fallbackLabel
     * @return string
     */
    public static function resolveLabel($statusId, $fallbackLabel = '')
    {
        $canonical = self::canonicalLabel($statusId);
        if ($canonical !== null) {
            return $canonical;
        }

        return trim((string) $fallbackLabel);
    }
}
