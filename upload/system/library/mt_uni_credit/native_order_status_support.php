<?php

/**
 * Shared native OC order-status application helpers for Checkout (Product/Cart parity).
 *
 * Reads order_status_id via direct SQL so Missing Orders (status 0) are visible even
 * if a storefront getOrder() wrapper filters them. Does not invent a second setting key.
 *
 * AUD-014: configured status is strictly parsed and must exist in order_status;
 * Checkout once-idempotency uses UniCredit durable finalization state, not mutable status.
 */
final class MtUniCreditNativeOrderStatusSupport
{
    const DIAG_EVENT = 'checkout.native_order_status';

    /**
     * Strict positive integer parse for payment_mt_uni_credit_order_status_id.
     * Rejects null/empty/zero/negative/non-numeric/numeric-prefix garbage ("5x").
     *
     * @param mixed $raw
     * @return int 0 when invalid
     */
    public static function parseConfiguredStatusIdStrict($raw)
    {
        if ($raw === null) {
            return 0;
        }
        if (is_bool($raw)) {
            return 0;
        }
        if (is_int($raw)) {
            return $raw > 0 ? $raw : 0;
        }
        if (is_float($raw)) {
            if ($raw <= 0 || floor($raw) !== $raw) {
                return 0;
            }

            return (int) $raw;
        }
        if (!is_string($raw) && !is_numeric($raw)) {
            return 0;
        }
        $s = trim((string) $raw);
        if ($s === '' || !preg_match('/^[1-9][0-9]*$/', $s)) {
            return 0;
        }

        return (int) $s;
    }

    /**
     * Configured payment order status id (strict parse only — no existence check).
     *
     * @param object $config OpenCart config with get()
     * @return int
     */
    public static function configuredStatusId($config)
    {
        if (!is_object($config) || !method_exists($config, 'get')) {
            return 0;
        }

        return self::parseConfiguredStatusIdStrict(
            $config->get(MtUniCreditConstants::PAYMENT_SETTING_ORDER_STATUS_ID)
        );
    }

    /**
     * Resolve a strictly valid configured status that exists in native order_status.
     * No fallback to config_order_status_id / Processing / invented IDs.
     *
     * Validation path:
     *   SELECT `order_status_id` FROM `{prefix}order_status`
     *   WHERE `order_status_id` = N LIMIT 1
     *
     * @param object $config
     * @param object $db OpenCart DB or MtUniCreditDbAdapter
     * @return int 0 when missing/invalid/non-existent
     */
    public static function resolveExistingConfiguredStatusId($config, $db)
    {
        $statusId = self::configuredStatusId($config);
        if ($statusId <= 0) {
            return 0;
        }
        if (!self::orderStatusExists($db, $statusId)) {
            return 0;
        }

        return $statusId;
    }

    /**
     * Language-independent existence of a native order_status row.
     *
     * @param object $db
     * @param int $statusId
     * @return bool
     */
    public static function orderStatusExists($db, $statusId)
    {
        $statusId = (int) $statusId;
        if ($statusId <= 0 || !is_object($db) || !method_exists($db, 'query')) {
            return false;
        }

        $prefix = '';
        if ($db instanceof MtUniCreditDbAdapter) {
            $prefix = $db->getPrefix();
        } elseif (defined('DB_PREFIX')) {
            $prefix = DB_PREFIX;
        }

        $result = $db->query(
            "SELECT `order_status_id` FROM `" . $prefix . "order_status`"
                . " WHERE `order_status_id` = '" . $statusId . "' LIMIT 1"
        );

        return is_object($result) && !empty($result->num_rows);
    }

    /**
     * Direct DB read of order.order_status_id. Returns -1 when the row is missing.
     *
     * @param object $db OpenCart DB (query) or MtUniCreditDbAdapter
     * @param int $orderId
     * @return int
     */
    public static function readOrderStatusId($db, $orderId)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0 || !is_object($db) || !method_exists($db, 'query')) {
            return -1;
        }

        $prefix = '';
        if ($db instanceof MtUniCreditDbAdapter) {
            $prefix = $db->getPrefix();
        } elseif (defined('DB_PREFIX')) {
            $prefix = DB_PREFIX;
        }

        $result = $db->query(
            "SELECT `order_status_id` FROM `" . $prefix . "order`"
                . " WHERE `order_id` = '" . $orderId . "' LIMIT 1"
        );

        if (!is_object($result) || empty($result->num_rows)) {
            return -1;
        }

        return (int) (isset($result->row['order_status_id']) ? $result->row['order_status_id'] : 0);
    }

    /**
     * Whether addOrderHistory should run for this order/status pair (Product/Cart legacy gate).
     * Checkout uses durable native finalization claim instead (AUD-014).
     *
     * @param int $currentStatusId -1 means order missing
     * @param int $configuredStatusId
     * @return bool
     */
    public static function shouldApplyHistory($currentStatusId, $configuredStatusId)
    {
        $configuredStatusId = (int) $configuredStatusId;
        $currentStatusId = (int) $currentStatusId;
        if ($configuredStatusId <= 0 || $currentStatusId < 0) {
            return false;
        }

        return $currentStatusId !== $configuredStatusId;
    }

    /**
     * Outcome identity bound into the durable finalization claim.
     *
     * @param array<string, mixed> $submit
     * @return string
     */
    public static function resolveFinalizationOutcome(array $submit)
    {
        if (!empty($submit['success'])) {
            $bank = isset($submit['bank_status']) ? (string) $submit['bank_status'] : '';
            if ($bank === MtUniCreditBankStatus::SENT_PROCESS2) {
                return 'success_p2';
            }

            return 'success_p1';
        }
        if (MtUniCreditFinancingTerminalNavigationSupport::isDefinitiveRemoteRejectTerminal($submit)) {
            return 'smartucf_remote_reject';
        }
        if (
            isset($submit['bank_status'])
            && (string) $submit['bank_status'] === MtUniCreditBankStatus::SEND_FAILED_CP
        ) {
            return 'cp_terminal_failed';
        }

        return 'native_terminal';
    }

    /**
     * Ensure Checkout submit exposes a durable bank_status for handoff gating.
     * Prefer the persisted row; fall back only for proven success shapes.
     *
     * @param string $resolved From bank-status repository (may be empty)
     * @param object $lifecycleResult MtUniCreditControlPanelOrderSubmissionResult
     * @param array<string, mixed> $shop
     * @return string
     */
    public static function resolveCheckoutHandoffBankStatus($resolved, $lifecycleResult, array $shop)
    {
        $resolved = trim((string) $resolved);
        if (
            $resolved === MtUniCreditBankStatus::SENT_PROCESS1
            || $resolved === MtUniCreditBankStatus::SENT_PROCESS2
        ) {
            return $resolved;
        }

        if (!is_object($lifecycleResult) || empty($lifecycleResult->success)) {
            return $resolved;
        }

        // P1 SmartUCF success always carries a bank redirect URL.
        if (isset($lifecycleResult->redirectUrl) && (string) $lifecycleResult->redirectUrl !== '') {
            return MtUniCreditBankStatus::SENT_PROCESS1;
        }

        // P2 success: no SmartUCF redirect; durable handoff is bank_sent_process2.
        if (MtUniCreditShopConfigurationFlags::isSecondaryProcess($shop)) {
            return MtUniCreditBankStatus::SENT_PROCESS2;
        }

        return $resolved;
    }

    /**
     * Safe diagnostic summary (no PII).
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function diagnosticSummary(array $fields)
    {
        $allowed = array(
            'order_id',
            'handoff',
            'success',
            'bank_status',
            'setting_key',
            'configured_status_id',
            'current_status_id',
            'applied',
            'skipped_reason',
            'history_called',
            'attempt_id',
            'finalize_state',
            'claim_acquired',
        );
        $out = array();
        foreach ($allowed as $key) {
            if (array_key_exists($key, $fields)) {
                $out[$key] = $fields[$key];
            }
        }
        $out['setting_key'] = MtUniCreditConstants::PAYMENT_SETTING_ORDER_STATUS_ID;

        return $out;
    }
}
