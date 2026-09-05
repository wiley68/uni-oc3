<?php

/**
 * Shared native OC order-status application helpers for Checkout (Product/Cart parity).
 *
 * Reads order_status_id via direct SQL so Missing Orders (status 0) are visible even
 * if a storefront getOrder() wrapper filters them. Does not invent a second setting key.
 */
final class MtUniCreditNativeOrderStatusSupport
{
    const DIAG_EVENT = 'checkout.native_order_status';

    /**
     * Configured payment order status id (same key Product/Cart use).
     *
     * @param object $config OpenCart config with get()
     * @return int
     */
    public static function configuredStatusId($config)
    {
        if (!is_object($config) || !method_exists($config, 'get')) {
            return 0;
        }

        return (int) $config->get(MtUniCreditConstants::PAYMENT_SETTING_ORDER_STATUS_ID);
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
     * Whether addOrderHistory should run for this order/status pair.
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
