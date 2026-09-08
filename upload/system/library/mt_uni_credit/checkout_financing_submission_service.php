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

        $validation = $this->revalidate($storeId, $orderId, $order, $orderProducts, $cartContext, $input);
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

        try {
            $attempt = $this->attempts->findOrCreateCheckoutAttempt(
                $storeId,
                $orderId,
                $unicid,
                $operationKeyHash,
                $selectionHash,
                $fingerprint
            );
        } catch (MtUniCreditPersistenceValidationException $exception) {
            return array(
                'success' => false,
                'error' => 'conflict',
                'message' => MtUniCreditControlPanelOrderLifecycleService::CUSTOMER_FAILURE_MESSAGE,
            );
        }

        if (!hash_equals((string) $attempt['operation_key_hash'], (string) $operationKeyHash)) {
            return array(
                'success' => false,
                'error' => 'conflict',
                'message' => MtUniCreditControlPanelOrderLifecycleService::CUSTOMER_FAILURE_MESSAGE,
            );
        }

        $liveSnapshot = MtUniCreditApplicationSnapshot::fromLive(
            $calculation,
            $order,
            $orderProducts,
            $shop,
            MtUniCreditOperationEntryPoint::CHECKOUT,
            $operationKeyHash,
            $selectionHash,
            $fingerprint
        );
        $bound = MtUniCreditApplicationSnapshot::bindToAttempt($this->attempts, $attempt, $liveSnapshot);
        if (empty($bound['ok'])) {
            return array(
                'success' => false,
                'error' => isset($bound['error']) ? (string) $bound['error'] : 'application_drift',
                'message' => MtUniCreditControlPanelOrderLifecycleService::CUSTOMER_FAILURE_MESSAGE,
                'attempt' => $attempt,
            );
        }
        $attempt = $bound['attempt'];
        $calculation = $bound['calculation'];

        $isProcess2 = MtUniCreditShopConfigurationFlags::isSecondaryProcess($shop);
        if ($isProcess2) {
            $posted = isset($input['process2']) && is_array($input['process2']) ? $input['process2'] : array();
            try {
                $sensitive = MtUniCreditProcessTwoSubmissionSupport::validateIfRequired($shop, $posted);
                if ($sensitive instanceof MtUniCreditProcessTwoSensitiveData) {
                    MtUniCreditProcessTwoSubmissionSupport::persist(
                        $sensitive,
                        (int) $attempt['attempt_id'],
                        $this->attempts->database()
                    );
                }
            } catch (InvalidArgumentException $exception) {
                return array(
                    'success' => false,
                    'error' => 'validation',
                    'message' => $exception->getMessage(),
                    'attempt' => $attempt,
                );
            } catch (RuntimeException $exception) {
                return array(
                    'success' => false,
                    'error' => 'process2_encryption_unavailable',
                    'message' => MtUniCreditControlPanelOrderLifecycleService::CUSTOMER_FAILURE_MESSAGE,
                    'attempt' => $attempt,
                );
            }
        }

        // Same final calculation used for Thank You — durable snapshot for native mail (P1 + P2).
        MtUniCreditProcessTwoSubmissionSupport::persistLeasingSnapshot(
            $calculation,
            $orderId,
            (int) $attempt['attempt_id'],
            $this->attempts->database(),
            isset($attempt['control_panel_order_id']) ? (int) $attempt['control_panel_order_id'] : null,
            $isProcess2
        );

        // Frozen application identity: reject drift before CP payload exists.
        if (
            $attempt['request_fingerprint'] !== ''
            && !hash_equals((string) $attempt['request_fingerprint'], $fingerprint)
            && MtUniCreditApplicationSnapshot::mustMatchLiveIntent($attempt)
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
            $bankStatus = MtUniCreditNativeOrderStatusSupport::resolveCheckoutHandoffBankStatus(
                $this->resolveBankStatusId($storeId, $orderId),
                $result,
                $shop
            );
            $out = array(
                'success' => true,
                'message' => MtUniCreditControlPanelOrderLifecycleService::CUSTOMER_SUCCESS_MESSAGE,
                'control_panel_order_id' => $result->controlPanelOrderId,
                'local_replay' => $result->localReplay,
                'cp_succeeded' => $result->cpSucceeded,
                'attempt' => $fresh !== null ? $fresh : $attempt,
                'apply_native_order_status' => $result->applyNativeOrderStatus,
                'bank_status' => $bankStatus,
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

        $failure = array(
            'success' => false,
            'error' => $result->errorClass !== null ? $result->errorClass : 'cp_submit_failed',
            'message' => $message,
            'order_id' => $orderId,
            'control_panel_order_id' => $result->controlPanelOrderId,
            'recoverable' => $result->recoverable,
            'ambiguous_blocked' => $result->ambiguousBlocked,
            'cp_succeeded' => $result->cpSucceeded,
            'attempt' => $fresh !== null ? $fresh : $attempt,
            'apply_native_order_status' => $result->applyNativeOrderStatus,
            'bank_status' => $this->resolveBankStatusId($storeId, $orderId),
            // Structural only — controller branch isolation / remote CP classification.
            'http_status' => $result->httpStatus,
        );

        // Checkout Woo/PS parity: definitive CP create failure → local bank_send_failed_cp
        // (no CP PATCH — no CP order). Native/Thank You only after durable status is proven (AUD-014 F03).
        if (MtUniCreditFinancingTerminalNavigationSupport::isCheckoutCpFailureNativeFinalizationCandidate($failure)) {
            if ($this->persistCheckoutCpFailureBankStatus($storeId, $orderId)) {
                $failure['bank_status'] = MtUniCreditBankStatus::SEND_FAILED_CP;
                $failure['apply_native_order_status'] = true;
                $failure['message'] = MtUniCreditFinancingLeasingPresenter::CP_TERMINAL_FAILURE_TITLE
                    . "\n\n"
                    . MtUniCreditFinancingLeasingPresenter::CP_TERMINAL_FAILURE_MESSAGE;
            }
        }

        return $failure;
    }

    /**
     * @param int $storeId
     * @param int $orderId
     * @param array<string, mixed>|null $order
     * @param array<int, array<string, mixed>> $orderProducts
     * @param MtUniCreditCartContext|null $cartContext
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function revalidate($storeId, $orderId, $order, array $orderProducts, $cartContext, array $input = array())
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

        $actor = isset($input['actor']) && is_array($input['actor']) ? $input['actor'] : array();
        $ownershipError = MtUniCreditCheckoutOrderActorOwnership::rejectReason($order, $actor);
        if ($ownershipError !== null) {
            return array('error' => $ownershipError);
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
                $durableBlock = $existing !== null && (
                    $existing['state'] === MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN
                    || $existing['state'] === MtUniCreditFinancingAttemptState::CP_EXISTING_CONFLICT
                );
                if (!$durableBlock) {
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

        $cartProducts = isset($input['cart_products']) && is_array($input['cart_products'])
            ? $input['cart_products']
            : $this->cartProductsFromContext($cartContext);
        $getOptions = isset($input['get_order_options']) && is_callable($input['get_order_options'])
            ? $input['get_order_options']
            : function () {
                return array();
            };
        $currencyCode = (string) (isset($input['currency_code']) ? $input['currency_code'] : '');
        $currencyValue = array_key_exists('currency_value', $input) ? $input['currency_value'] : null;
        if (!MtUniCreditCheckoutOrderCartParity::matchesCurrentCart(
            $order,
            $orderProducts,
            $getOptions,
            $cartProducts,
            (float) $cartContext->total,
            $currencyCode,
            $currencyValue
        )) {
            return array('error' => 'order_changed');
        }

        $resolution = $this->cartSchemes->resolve($shop, $cartContext);
        $schemeKey = trim((string) (isset($input['scheme_key']) ? $input['scheme_key'] : ''));
        $firstInstallment = isset($input['first_installment'])
            ? (float) $input['first_installment']
            : 0.0;
        // AUD-016 F02: submitted selection is authoritative identity — never replace with preferred.
        if ($schemeKey === '') {
            return array('error' => 'unavailable');
        }
        $parsed = MtUniCreditStorefrontCalculatorPresenter::parseSchemeKey($schemeKey);
        if (!is_array($parsed)) {
            return array('error' => 'unavailable');
        }
        $presenter = new MtUniCreditStorefrontCalculatorPresenter($this->calculator, $this->cartSchemes);
        $scheme = $presenter->findCartScheme($resolution, $shop, $parsed);
        if ($scheme === null) {
            return array('error' => 'unavailable');
        }

        try {
            $calculation = $this->calculator->calculateScheme($shop, $orderTotal, $scheme, $firstInstallment);
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

    /**
     * Rebuild cart product rows (with options) from CartContext for structural parity.
     *
     * @param MtUniCreditCartContext $cartContext
     * @return array<int, array<string, mixed>>
     */
    private function cartProductsFromContext(MtUniCreditCartContext $cartContext)
    {
        $products = array();
        foreach ($cartContext->lines as $line) {
            if (!$line instanceof MtUniCreditCartLine) {
                continue;
            }
            $products[] = array(
                'product_id' => (int) $line->product->productId,
                'quantity' => (int) $line->quantity,
                'option' => is_array($line->options) ? $line->options : array(),
            );
        }

        return $products;
    }

    /**
     * Durable local bank status after lifecycle (empty when none).
     *
     * @param int $storeId
     * @param int $orderId
     * @return string
     */
    private function resolveBankStatusId($storeId, $orderId)
    {
        $storeId = (int) $storeId;
        $orderId = (int) $orderId;
        if ($storeId < 0 || $orderId <= 0) {
            return '';
        }
        try {
            $row = MtUniCreditProcess1ServiceFactory::bankStatuses($this->attempts->database())
                ->findByOrderId($storeId, $orderId);
            if ($row === null) {
                return '';
            }

            return isset($row['status_id']) ? (string) $row['status_id'] : '';
        } catch (Exception $exception) {
            return '';
        }
    }

    /**
     * Persist local bank_send_failed_cp after definitive Checkout CP create failure.
     * No CP PATCH (no remote CP order). Returns true only when durable status is proven.
     *
     * @param int $storeId
     * @param int $orderId
     * @return bool
     */
    private function persistCheckoutCpFailureBankStatus($storeId, $orderId)
    {
        $storeId = (int) $storeId;
        $orderId = (int) $orderId;
        if ($storeId < 0 || $orderId <= 0) {
            return false;
        }
        try {
            $status = MtUniCreditBankStatus::controlPanelFailure(false);
            $repo = MtUniCreditProcess1ServiceFactory::bankStatuses($this->attempts->database());
            $updated = $repo->updateByOrderIdentifier(
                $storeId,
                (string) $orderId,
                $status['status_id'],
                $status['status_label'],
                MtUniCreditBankStatusTransitionPolicy::SOURCE_LOCAL_LIFECYCLE
            );
            if ($updated === null) {
                return false;
            }
            $row = $repo->findByOrderId($storeId, $orderId);
            if ($row === null) {
                return false;
            }

            return isset($row['status_id'])
                && (string) $row['status_id'] === MtUniCreditBankStatus::SEND_FAILED_CP;
        } catch (Exception $ignored) {
            return false;
        }
    }
}
