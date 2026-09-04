<?php

/**
 * Safe presentation-event health report for admin / remote verification (no PII).
 */
final class MtUniCreditCatalogEventHealth
{
    /**
     * @param object $db OpenCart DB (query/escape)
     * @param string $prefix
     * @return array{
     *   ok: bool,
     *   events: array<int, array<string, mixed>>,
     *   summary: array<string, string>
     * }
     */
    public static function report($db, $prefix = 'oc_')
    {
        $prefix = (string) $prefix;
        $events = array();
        $summary = array();
        $ok = true;

        if (!class_exists('MtUniCreditCatalogEventRegistry', false)) {
            require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'catalog_event_registry.php';
        }

        foreach (MtUniCreditCatalogEventRegistry::definitions() as $definition) {
            $code = (string) $definition['code'];
            $expectedTrigger = (string) $definition['trigger'];
            $expectedAction = (string) $definition['action'];
            $rows = array();
            try {
                $result = $db->query(
                    "SELECT `event_id`, `code`, `trigger`, `action`, `status`, `sort_order`"
                        . " FROM `" . $prefix . "event`"
                        . " WHERE `code` = '" . $db->escape($code) . "'"
                );
                if (is_object($result) && !empty($result->rows) && is_array($result->rows)) {
                    $rows = $result->rows;
                } elseif (is_object($result) && !empty($result->num_rows) && isset($result->row)) {
                    $rows = array($result->row);
                }
            } catch (Exception $ignored) {
                $rows = array();
            }

            $duplicateCount = count($rows);
            $registered = $duplicateCount > 0;
            $enabled = false;
            $action = '';
            $trigger = '';
            $status = 0;
            if ($registered) {
                $row = $rows[0];
                $trigger = (string) (isset($row['trigger']) ? $row['trigger'] : '');
                $action = (string) (isset($row['action']) ? $row['action'] : '');
                $status = (int) (isset($row['status']) ? $row['status'] : 0);
                $enabled = $status === 1;
            }

            $match = $registered
                && $enabled
                && $trigger === $expectedTrigger
                && $action === $expectedAction
                && $duplicateCount === 1;
            if (!$match) {
                $ok = false;
            }

            $key = self::summaryKey($code);
            $summary[$key] = $match
                ? 'registered/enabled'
                : (!$registered ? 'missing' : (!$enabled ? 'disabled' : 'stale_or_duplicate'));

            $events[] = array(
                'code' => $code,
                'expected_trigger' => $expectedTrigger,
                'expected_action' => $expectedAction,
                'registered' => $registered ? 'yes' : 'no',
                'registered_trigger' => $trigger,
                'registered_action' => $action,
                'enabled' => $enabled ? 'yes' : 'no',
                'status' => $status,
                'duplicate_count' => $duplicateCount,
                'healthy' => $match ? 'yes' : 'no',
            );
        }

        return array(
            'ok' => $ok,
            'events' => $events,
            'summary' => $summary,
        );
    }

    /**
     * Compact one-line summary for logs / docs.
     *
     * @param array{summary?: array<string, string>} $report
     * @return string
     */
    public static function formatSummaryLine(array $report)
    {
        $summary = isset($report['summary']) && is_array($report['summary']) ? $report['summary'] : array();
        $parts = array();
        foreach ($summary as $key => $state) {
            $parts[] = $key . ': ' . $state;
        }

        return implode('; ', $parts);
    }

    /**
     * @param string $code
     * @return string
     */
    private static function summaryKey($code)
    {
        $map = array(
            'mt_uni_credit_checkout_success_order' => 'thankyou_stash',
            'mt_uni_credit_checkout_success_view' => 'thankyou_before',
            'mt_uni_credit_checkout_success_view_after' => 'thankyou_after',
            'mt_uni_credit_mail_order_add' => 'mail_customer',
            'mt_uni_credit_mail_order_alert' => 'mail_admin',
        );

        return isset($map[$code]) ? $map[$code] : $code;
    }
}
