<?php

/**
 * Phase 5/7 prepared-state access guard.
 *
 * After CP success the native order may leave status 0; revisits are allowed when a
 * financing attempt already exists for the exact store/order.
 */
final class MtUniCreditCheckoutPreparedBoundary
{
    /**
     * @param int $orderId
     * @param int $preparedOrderId
     * @param array<string, mixed>|null $order
     * @param int $storeId
     * @param array<string, mixed>|null $attempt
     * @return array{ok?: bool, error?: string}
     */
    public static function validateAccess($orderId, $preparedOrderId, $order, $storeId, $attempt = null)
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

        $orderStatusId = (int) (isset($order['order_status_id']) ? $order['order_status_id'] : -1);
        if ($orderStatusId !== 0) {
            if (!is_array($attempt) || (int) (isset($attempt['order_id']) ? $attempt['order_id'] : 0) !== $orderId) {
                return array('error' => 'order_already_processed');
            }
        }

        return array('ok' => true);
    }
}
