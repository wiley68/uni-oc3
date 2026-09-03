<?php

/**
 * Checkout financing submission — binds native OC order to CP create lifecycle.
 *
 * Never calls OpenCart addOrder().
 */
final class MtUniCreditCheckoutFinancingSubmissionService
{
    /** @var MtUniCreditFinancingAttemptRepository */
    private $attempts;

    /** @var MtUniCreditControlPanelOrderLifecycleService */
    private $lifecycle;

    /** @var MtUniCreditCredentialsRepository */
    private $credentials;

    /** @var MtUniCreditShopConfigurationCache */
    private $shopCache;

    /** @var MtUniCreditCalculator */
    private $calculator;

    /** @var MtUniCreditCartSchemeResolver */
    private $cartSchemes;

    /** @var MtUniCreditControlPanelOrderPayloadBuilder */
    private $payloadBuilder;

    /**
     * @param MtUniCreditFinancingAttemptRepository $attempts
     * @param MtUniCreditControlPanelOrderLifecycleService $lifecycle
     * @param MtUniCreditCredentialsRepository $credentials
     * @param MtUniCreditShopConfigurationCache $shopCache
     * @param MtUniCreditCalculator|null $calculator
     * @param MtUniCreditCartSchemeResolver|null $cartSchemes
     */
    public function __construct(
        MtUniCreditFinancingAttemptRepository $attempts,
        MtUniCreditControlPanelOrderLifecycleService $lifecycle,
        MtUniCreditCredentialsRepository $credentials,
        MtUniCreditShopConfigurationCache $shopCache,
        $calculator = null,
        $cartSchemes = null
    ) {
        $this->attempts = $attempts;
        $this->lifecycle = $lifecycle;
        $this->credentials = $credentials;
        $this->shopCache = $shopCache;
        $this->calculator = $calculator instanceof MtUniCreditCalculator
            ? $calculator
            : new MtUniCreditCalculator();
        $this->cartSchemes = $cartSchemes instanceof MtUniCreditCartSchemeResolver
            ? $cartSchemes
            : new MtUniCreditCartSchemeResolver($this->calculator);
        $this->payloadBuilder = new MtUniCreditControlPanelOrderPayloadBuilder();
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function submit(array $input)
    {
        $storeId = (int) (isset($input['store_id']) ? $input['store_id'] : -1);
        $orderId = (int) (isset($input['order_id']) ? $input['order_id'] : 0);
        $order = isset($input['order']) && is_array($input['order']) ? $input['order'] : null;
        $orderProducts = isset($input['order_products']) && is_array($input['order_products'])
            ? $input['order_products']
            : array();
        $cartContext = isset($input['cart_context']) && $input['cart_context'] instanceof MtUniCreditCartContext
            ? $input['cart_context']
            : null;
        $lockOwnerToken = isset($input['lock_owner_token'])
            ? (string) $input['lock_owner_token']
            : MtUniCreditLockOwnerTokenGenerator::generate();

        $validation = $this->revalidate($storeId, $orderId, $order, $orderProducts, $cartContext);
        if (isset($validation['error'])) {
            return array(
                'success' => false,
                'error' => $validation['error'],
                'message' => MtUniCreditControlPanelOrderLifecycleService::CUSTOMER_FAILURE_MESSAGE,
            );
        }

        /** @var array<string, mixed> $shop */
        $shop = $validation['shop'];
        /** @var MtUniCreditCalculationResult $calculation */
        $calculation = $validation['calculation'];
        $unicid = (string) $validation['unicid'];

        $payloadPreview = $this->payloadBuilder->build($orderId, $order, $orderProducts, $calculation, $shop);
        $fingerprint = MtUniCreditControlPanelOrderPayloadBuilder::fingerprint($payloadPreview);
        $selectionHash = hash('sha256', $calculation->scheme->kopCode . '|' . $calculation->scheme->months . '|' . $fingerprint);
        $operationKeyHash = hash('sha256', 'checkout|' . $storeId . '|' . $orderId);

        $attempt = $this->attempts->findOrCreateCheckoutAttempt(
            $storeId,
            $orderId,
            $unicid,
            $operationKeyHash,
            $selectionHash,
            $fingerprint
        );

        if (
            $attempt['request_fingerprint'] !== ''
            && $attempt['cp_payload']
            && !hash_equals((string) $attempt['request_fingerprint'], $fingerprint)
            && (int) $attempt['control_panel_order_id'] <= 0
            && $attempt['state'] !== MtUniCreditFinancingAttemptState::CP_CREATED
        ) {
            return array(
                'success' => false,
                'error' => 'fingerprint_drift',
                'message' => MtUniCreditControlPanelOrderLifecycleService::CUSTOMER_FAILURE_MESSAGE,
                'attempt' => $attempt,
            );
        }

        $result = $this->lifecycle->submitOrRecover(
            $attempt,
            $order,
            $orderProducts,
            $calculation,
            $shop,
            $lockOwnerToken
        );

        $fresh = $this->attempts->findById((int) $attempt['attempt_id']);

        if ($result->success) {
            $out = array(
                'success' => true,
                'message' => MtUniCreditControlPanelOrderLifecycleService::CUSTOMER_SUCCESS_MESSAGE,
                'control_panel_order_id' => $result->controlPanelOrderId,
                'local_replay' => $result->localReplay,
                'attempt' => $fresh !== null ? $fresh : $attempt,
                'apply_native_order_status' => !$result->localReplay,
            );
            if ($result->redirectUrl !== '') {
                $out['redirect'] = $result->redirectUrl;
                $out['bank_redirect'] = true;
            }

            return $out;
        }

        $message = $result->customerMessage !== null && $result->customerMessage !== ''
            ? $result->customerMessage
            : ($result->ambiguousBlocked
                ? MtUniCreditControlPanelOrderLifecycleService::CUSTOMER_AMBIGUOUS_MESSAGE
                : MtUniCreditControlPanelOrderLifecycleService::CUSTOMER_FAILURE_MESSAGE);

        return array(
            'success' => false,
            'error' => $result->errorClass !== null ? $result->errorClass : 'cp_submit_failed',
            'message' => $message,
            'control_panel_order_id' => $result->controlPanelOrderId,
            'recoverable' => $result->recoverable,
            'ambiguous_blocked' => $result->ambiguousBlocked,
            'attempt' => $fresh !== null ? $fresh : $attempt,
            'apply_native_order_status' => $result->cpSucceeded && !$result->localReplay,
        );
    }

    /**
     * @param int $storeId
     * @param int $orderId
     * @param array<string, mixed>|null $order
     * @param array<int, array<string, mixed>> $orderProducts
     * @param MtUniCreditCartContext|null $cartContext
     * @return array<string, mixed>
     */
    private function revalidate($storeId, $orderId, $order, array $orderProducts, $cartContext)
    {
        MtUniCreditStoreScope::requireStoreId($storeId);
        if ($orderId <= 0 || !is_array($order)) {
            return array('error' => 'order_missing');
        }
        if ((int) (isset($order['order_id']) ? $order['order_id'] : 0) !== $orderId) {
            return array('error' => 'order_missing');
        }
        if ((int) (isset($order['store_id']) ? $order['store_id'] : -1) !== (int) $storeId) {
            return array('error' => 'order_store_mismatch');
        }

        $paymentCode = isset($order['payment_code']) ? (string) $order['payment_code'] : '';
        if (
            $paymentCode !== MtUniCreditConstants::EXTENSION_CODE
            && !MtUniCreditPaymentIdentity::matchesStoredPayment(isset($order['payment_method']) ? $order['payment_method'] : '')
        ) {
            return array('error' => 'payment_method_mismatch');
        }

        $existing = $this->attempts->findByStoreOrder($storeId, $orderId);
        $orderStatusId = (int) (isset($order['order_status_id']) ? $order['order_status_id'] : -1);
        if ($orderStatusId !== 0) {
            if ($existing === null || (int) $existing['control_panel_order_id'] <= 0) {
                if ($existing === null || $existing['state'] !== MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN) {
                    return array('error' => 'order_already_processed');
                }
            }
        }

        $unicid = $this->credentials->getUnicid($storeId);
        if ($unicid === '') {
            return array('error' => 'not_configured');
        }

        $shop = $this->shopCache->getFreshShopData($storeId, $unicid);
        if (!is_array($shop) || $shop === array()) {
            return array('error' => 'shop_cache_stale');
        }

        if ($cartContext === null || $cartContext->lines === array()) {
            return array('error' => 'unavailable');
        }

        $orderTotal = round((float) (isset($order['total']) ? $order['total'] : 0), 2);
        if (abs($orderTotal - round((float) $cartContext->total, 2)) > 0.009) {
            return array('error' => 'amount_changed');
        }

        $resolution = $this->cartSchemes->resolve($shop, $cartContext);
        $preferred = $resolution->promoOffer !== null
            ? $resolution->promoOffer
            : $resolution->standardOffer;
        if (!$preferred instanceof MtUniCreditOffer) {
            return array('error' => 'unavailable');
        }

        $scheme = null;
        foreach ($this->cartSchemes->unifiedSchemes($resolution, $shop) as $candidate) {
            if ($candidate->kopCode === $preferred->kopCode && $candidate->months === $preferred->months) {
                $scheme = $candidate;
                break;
            }
        }
        if ($scheme === null) {
            return array('error' => 'unavailable');
        }

        try {
            $calculation = $this->calculator->calculateScheme($shop, $orderTotal, $scheme, 0.0);
        } catch (Exception $exception) {
            return array('error' => 'unavailable');
        }

        if (abs($calculation->price - $orderTotal) > 0.009) {
            return array('error' => 'amount_changed');
        }

        return array(
            'shop' => $shop,
            'calculation' => $calculation,
            'unicid' => $unicid,
        );
    }
}
