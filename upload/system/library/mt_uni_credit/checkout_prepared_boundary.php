<?php

/**
 * Phase 5 prepared-state access guard — read-only continuation boundary for Phase 7+.
 */
final class MtUniCreditCheckoutPreparedBoundary
{
    /**
     * @param int $orderId
     * @param int $preparedOrderId
     * @param array<string, mixed>|null $order
     * @param int $storeId
     * @return array{ok?: bool, error?: string}
     */
    public static function validateAccess($orderId, $preparedOrderId, $order, $storeId)
    {
        $orderId = (int) $orderId;
        $preparedOrderId = (int) $preparedOrderId;
        if ($orderId <= 0 || $preparedOrderId <= 0 || $preparedOrderId !== $orderId) {
            return array('error' => 'prepared_state_missing');
        }

        if (!is_array($order)) {
            return array('error' => 'order_missing');
        }

        if ((int) (isset($order['order_id']) ? $order['order_id'] : 0) !== $orderId) {
            return array('error' => 'order_missing');
        }

        if ((int) (isset($order['store_id']) ? $order['store_id'] : -1) !== (int) $storeId) {
            return array('error' => 'order_store_mismatch');
        }

        if ((int) (isset($order['order_status_id']) ? $order['order_status_id'] : -1) !== 0) {
            return array('error' => 'order_already_processed');
        }

        return array('ok' => true);
    }
}
