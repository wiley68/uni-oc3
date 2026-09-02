<?php

/**
 * Phase 5 checkout confirm preparation — reuse native order, no addOrder(), no CP traffic.
 */
final class MtUniCreditCheckoutConfirmPreparation
{
    const SESSION_PREPARED_ORDER_ID = 'mt_uni_credit_checkout_prepared_order_id';

    /** @var MtUniCreditCheckoutPaymentAvailability */
    private $availability;

    /** @var MtUniCreditOperationLockRepository */
    private $locks;

    /**
     * @param MtUniCreditCheckoutPaymentAvailability $availability
     * @param MtUniCreditOperationLockRepository $locks
     */
    public function __construct(MtUniCreditCheckoutPaymentAvailability $availability, MtUniCreditOperationLockRepository $locks)
    {
        $this->availability = $availability;
        $this->locks = $locks;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success?: bool, continuation_route?: string, error?: string, prepared_order_id?: int}
     */
    public function prepare(array $input)
    {
        $paymentCode = isset($input['payment_code']) ? (string) $input['payment_code'] : '';
        if ($paymentCode !== MtUniCreditConstants::EXTENSION_CODE) {
            return array('error' => 'payment_method_mismatch');
        }

        $orderId = (int) (isset($input['order_id']) ? $input['order_id'] : 0);
        if ($orderId <= 0) {
            return array('error' => 'order_missing');
        }

        $preparedOrderId = (int) (isset($input['prepared_order_id']) ? $input['prepared_order_id'] : 0);
        if ($preparedOrderId === $orderId) {
            return array(
                'success' => true,
                'continuation_route' => MtUniCreditConstants::CHECKOUT_PREPARED_ROUTE,
            );
        }

        $order = isset($input['order']) && is_array($input['order']) ? $input['order'] : null;
        if ($order === null) {
            return array('error' => 'order_missing');
        }

        $storeId = (int) (isset($input['store_id']) ? $input['store_id'] : 0);
        $orderStoreId = (int) (isset($order['store_id']) ? $order['store_id'] : -1);
        if ($orderStoreId !== $storeId) {
            return array('error' => 'order_store_mismatch');
        }

        if ((int) (isset($order['order_id']) ? $order['order_id'] : 0) !== $orderId) {
            return array('error' => 'order_missing');
        }

        $orderPaymentCode = trim((string) (isset($order['payment_code']) ? $order['payment_code'] : ''));
        if ($orderPaymentCode !== '' && $orderPaymentCode !== MtUniCreditConstants::EXTENSION_CODE) {
            return array('error' => 'payment_method_mismatch');
        }

        $orderStatusId = (int) (isset($order['order_status_id']) ? $order['order_status_id'] : 0);
        if ($orderStatusId !== 0) {
            return array('error' => 'order_already_processed');
        }

        $cartProducts = isset($input['cart_products']) && is_array($input['cart_products']) ? $input['cart_products'] : array();
        $orderProducts = isset($input['order_products']) && is_array($input['order_products']) ? $input['order_products'] : array();
        $getOptions = isset($input['get_order_options']) && is_callable($input['get_order_options'])
            ? $input['get_order_options']
            : function () {
                return array();
            };
        $checkoutGrandTotal = (float) (isset($input['checkout_grand_total']) ? $input['checkout_grand_total'] : 0.0);
        $currencyCode = (string) (isset($input['currency_code']) ? $input['currency_code'] : '');

        if (!MtUniCreditCheckoutOrderCartParity::matchesCurrentCart(
            $order,
            $orderProducts,
            $getOptions,
            $cartProducts,
            $checkoutGrandTotal,
            $currencyCode
        )) {
            return array('error' => 'order_changed');
        }

        if (!$this->availability->isEligibleForPreparedOrder(
            $storeId,
            $checkoutGrandTotal,
            $currencyCode,
            $cartProducts,
            !empty($input['module_enabled']),
            !empty($input['payment_enabled'])
        )) {
            return array('error' => 'unavailable');
        }

        $ownerToken = MtUniCreditLockOwnerTokenGenerator::generate();
        $operationKeyHash = hash('sha256', 'checkout:' . $storeId . ':' . $orderId);
        if (!$this->locks->acquire($storeId, MtUniCreditOperationEntryPoint::CHECKOUT, $operationKeyHash, $ownerToken)) {
            return array('error' => 'duplicate_request');
        }

        try {
            if (!$this->availability->isEligibleForPreparedOrder(
                $storeId,
                $checkoutGrandTotal,
                $currencyCode,
                $cartProducts,
                !empty($input['module_enabled']),
                !empty($input['payment_enabled'])
            )) {
                return array('error' => 'unavailable');
            }

            return array(
                'success' => true,
                'continuation_route' => MtUniCreditConstants::CHECKOUT_PREPARED_ROUTE,
                'prepared_order_id' => $orderId,
            );
        } finally {
            $this->locks->release($storeId, MtUniCreditOperationEntryPoint::CHECKOUT, $operationKeyHash, $ownerToken);
        }
    }
}
