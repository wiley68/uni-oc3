<?php

/**
 * Product/Cart financing submission — materialize ONE local OC order + shared Phase 7 lifecycle.
 */
final class MtUniCreditStorefrontFinancingSubmissionService
{
    const SESSION_ORDER_BIND_KEY = 'mt_uni_credit_storefront_order_bind';

    /** @var MtUniCreditFinancingAttemptRepository */
    private $attempts;

    /** @var MtUniCreditOperationLockRepository */
    private $locks;

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

    /** @var MtUniCreditStorefrontOrderDraftBuilder */
    private $draftBuilder;

    /**
     * @param MtUniCreditFinancingAttemptRepository $attempts
     * @param MtUniCreditOperationLockRepository $locks
     * @param MtUniCreditControlPanelOrderLifecycleService $lifecycle
     * @param MtUniCreditCredentialsRepository $credentials
     * @param MtUniCreditShopConfigurationCache $shopCache
     * @param MtUniCreditCalculator|null $calculator
     * @param MtUniCreditCartSchemeResolver|null $cartSchemes
     */
    public function __construct(
        MtUniCreditFinancingAttemptRepository $attempts,
        MtUniCreditOperationLockRepository $locks,
        MtUniCreditControlPanelOrderLifecycleService $lifecycle,
        MtUniCreditCredentialsRepository $credentials,
        MtUniCreditShopConfigurationCache $shopCache,
        $calculator = null,
        $cartSchemes = null
    ) {
        $this->attempts = $attempts;
        $this->locks = $locks;
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
        $this->draftBuilder = new MtUniCreditStorefrontOrderDraftBuilder();
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function submit(array $input)
    {
        $entryPoint = isset($input['entry_point']) ? (string) $input['entry_point'] : '';
        if (!in_array($entryPoint, array(MtUniCreditOperationEntryPoint::PRODUCT, MtUniCreditOperationEntryPoint::CART), true)) {
            return $this->fail('validation', false);
        }

        $storeId = (int) (isset($input['store_id']) ? $input['store_id'] : -1);
        try {
            MtUniCreditStoreScope::requireStoreId($storeId);
        } catch (Exception $exception) {
            return $this->fail('validation', false);
        }

        $currency = strtoupper(trim((string) (isset($input['currency_code']) ? $input['currency_code'] : '')));
        $schemeKey = trim((string) (isset($input['scheme_key']) ? $input['scheme_key'] : ''));
        $parsed = MtUniCreditStorefrontCalculatorPresenter::parseSchemeKey($schemeKey);
        if ($parsed === null) {
            return $this->fail('unavailable', false);
        }

        $unicid = $this->credentials->getUnicid($storeId);
        if ($unicid === '') {
            return $this->fail('not_configured', false);
        }

        $shop = $this->shopCache->getFreshShopData($storeId, $unicid);
        if (!is_array($shop) || $shop === array()) {
            return $this->fail('shop_cache_stale', false);
        }

        $orderProducts = array();
        $orderTotal = 0.0;
        $calculation = null;
        $operationKeyHash = '';
        $draftInput = isset($input['draft']) && is_array($input['draft']) ? $input['draft'] : array();

        if ($entryPoint === MtUniCreditOperationEntryPoint::PRODUCT) {
            if (!isset($input['product_line']) || !$input['product_line'] instanceof MtUniCreditProductLine) {
                return $this->fail('unavailable', false);
            }
            /** @var MtUniCreditProductLine $line */
            $line = $input['product_line'];
            $orderTotal = $line->financingPrice;
            $product = $line->toProductContext();
            $scheme = $this->findScheme($shop, $product, $parsed);
            if ($scheme === null) {
                return $this->fail('unavailable', false);
            }
            try {
                $calculation = $this->calculator->calculateScheme($shop, $orderTotal, $scheme, 0.0);
            } catch (Exception $exception) {
                return $this->fail('unavailable', false);
            }
            $optionsNormalized = array();
            foreach ($line->options as $option) {
                $poId = (int) (isset($option['product_option_id']) ? $option['product_option_id'] : 0);
                $povId = isset($option['product_option_value_id']) ? $option['product_option_value_id'] : '';
                if ($poId > 0) {
                    $optionsNormalized[$poId] = $povId;
                }
            }
            $operationKeyHash = MtUniCreditStorefrontOperationIdentity::productHash(
                $storeId,
                $line->productId,
                $optionsNormalized,
                $line->quantity,
                $currency
            );
            $draftInput['product_line'] = $line;
            $draftInput['order_total'] = $orderTotal;
            $orderProducts = array(
                array(
                    'product_id' => $line->productId,
                    'name' => $line->name,
                    'model' => $line->model,
                    'quantity' => $line->quantity,
                    'price' => $line->unitExTax,
                    'total' => round($line->unitExTax * $line->quantity, 4),
                    'tax' => max(0.0, $line->unitWithTax - $line->unitExTax),
                    'reward' => $line->reward,
                ),
            );
        } else {
            if (!isset($input['cart_context']) || !$input['cart_context'] instanceof MtUniCreditCartContext) {
                return $this->fail('unavailable', false);
            }
            /** @var MtUniCreditCartContext $cart */
            $cart = $input['cart_context'];
            $fingerprint = isset($input['cart_fingerprint'])
                ? (string) $input['cart_fingerprint']
                : MtUniCreditStorefrontOperationIdentity::cartFingerprintFromContext($cart, $currency);
            $liveFingerprint = MtUniCreditStorefrontOperationIdentity::cartFingerprintFromContext($cart, $currency);
            if (!hash_equals($liveFingerprint, $fingerprint)) {
                return $this->fail('cart_changed', false);
            }
            $resolution = $this->cartSchemes->resolve($shop, $cart);
            $scheme = $this->findCartScheme($resolution, $shop, $parsed);
            if ($scheme === null) {
                return $this->fail('unavailable', false);
            }
            $orderTotal = $cart->total;
            try {
                $calculation = $this->calculator->calculateScheme($shop, $orderTotal, $scheme, 0.0);
            } catch (Exception $exception) {
                return $this->fail('unavailable', false);
            }
            $operationKeyHash = MtUniCreditStorefrontOperationIdentity::cartHash($storeId, $currency, $fingerprint);
            $draftInput['products'] = isset($input['products']) && is_array($input['products']) ? $input['products'] : array();
            $draftInput['order_total'] = $orderTotal;
            foreach ($draftInput['products'] as $product) {
                if (!is_array($product)) {
                    continue;
                }
                $orderProducts[] = $product;
            }
        }

        $lockOwnerToken = isset($input['lock_owner_token'])
            ? (string) $input['lock_owner_token']
            : MtUniCreditLockOwnerTokenGenerator::generate();

        $sessionData = isset($input['session']) && is_array($input['session']) ? $input['session'] : array();
        $applicationToken = isset($input['application_token']) ? (string) $input['application_token'] : '';
        if (!MtUniCreditStorefrontApplicationToken::accepts($sessionData, $applicationToken)) {
            return $this->fail('validation', true);
        }
        // Product/cart selection identity stays stable; application token scopes ONE submit lifecycle.
        $selectionIdentityHash = $operationKeyHash;
        $operationKeyHash = MtUniCreditStorefrontApplicationToken::bindKey($selectionIdentityHash, $applicationToken);
        $correlationId = substr(hash('sha256', $operationKeyHash . '|' . $lockOwnerToken), 0, 12);

        if (!$this->locks->acquire($storeId, $entryPoint, $operationKeyHash, $lockOwnerToken)) {
            $this->logDecision($correlationId, $entryPoint, $operationKeyHash, 0, 0, '', 'reject_locked', 'lock_busy');

            return array(
                'success' => false,
                'error' => 'duplicate_request',
                'message' => MtUniCreditControlPanelOrderLifecycleService::CUSTOMER_FAILURE_MESSAGE,
                'cart_unchanged' => true,
            );
        }

        try {
            $orderId = $this->resolveBoundOrderId($sessionData, $operationKeyHash);
            $addOrder = isset($input['add_order']) && is_callable($input['add_order'])
                ? $input['add_order']
                : null;
            $loadOrder = isset($input['load_order']) && is_callable($input['load_order'])
                ? $input['load_order']
                : null;

            $decision = 'fresh';
            $reuseReason = '';
            $order = null;
            if ($orderId > 0) {
                if ($loadOrder === null) {
                    $this->unbindOrderId($sessionData, $operationKeyHash);
                    $orderId = 0;
                } else {
                    $loaded = call_user_func($loadOrder, $orderId);
                    $order = is_array($loaded) ? $loaded : null;
                    if (!$this->isReusableBoundOrder($order, $orderId, $storeId)) {
                        $this->unbindOrderId($sessionData, $operationKeyHash);
                        $orderId = 0;
                        $order = null;
                        $reuseReason = 'stale_missing_order';
                    } else {
                        $existingAttempt = $this->attempts->findByStoreOrder($storeId, $orderId);
                        if (
                            $existingAttempt !== null
                            && !hash_equals(
                                (string) $existingAttempt['operation_key_hash'],
                                (string) $operationKeyHash
                            )
                        ) {
                            // Cross-operation guard: never resume unrelated attempt.
                            $this->unbindOrderId($sessionData, $operationKeyHash);
                            $this->logDecision(
                                $correlationId,
                                $entryPoint,
                                $operationKeyHash,
                                $orderId,
                                (int) $existingAttempt['attempt_id'],
                                (string) $existingAttempt['state'],
                                'reject_stale',
                                'attempt_operation_mismatch'
                            );
                            $orderId = 0;
                            $order = null;
                            $reuseReason = 'attempt_operation_mismatch';
                        } else {
                            $decision = 'replay';
                            $reuseReason = 'session_bind_match';
                        }
                    }
                }
            }

            // Ignore legacy product/cart-only binds from other applications.
            $this->pruneLegacyBareBinds($sessionData, $selectionIdentityHash, $operationKeyHash);

            if ($orderId <= 0) {
                if ($addOrder === null) {
                    return $this->fail('order_missing', true);
                }
                $draftInput['store_id'] = $storeId;
                $draftInput['currency_code'] = $currency;
                if (!isset($draftInput['customer']) && isset($input['customer']) && is_array($input['customer'])) {
                    $draftInput['customer'] = $input['customer'];
                }
                foreach (
                    array(
                        'invoice_prefix',
                        'store_name',
                        'store_url',
                        'language_id',
                        'currency_id',
                        'currency_value',
                        'ip',
                        'forwarded_ip',
                        'user_agent',
                        'accept_language',
                        'payment_method',
                        'shipping_method',
                        'shipping_code',
                        'comment',
                    ) as $key
                ) {
                    if (!isset($draftInput[$key]) && isset($input[$key])) {
                        $draftInput[$key] = $input[$key];
                    }
                }
                $orderData = $this->draftBuilder->buildOrderData($draftInput);
                $orderId = (int) call_user_func($addOrder, $orderData);
                if ($orderId <= 0) {
                    return $this->fail('order_missing', true);
                }
                $this->bindOrderId($sessionData, $operationKeyHash, $orderId);
                $decision = 'fresh';
                $reuseReason = $reuseReason !== '' ? $reuseReason : 'no_bind';
                $order = null;
                if ($loadOrder !== null) {
                    $loaded = call_user_func($loadOrder, $orderId);
                    $order = is_array($loaded) ? $loaded : null;
                }
            }

            // Never continue financing against a synthetic stand-in for a missing OC order.
            if (!is_array($order) || !$this->isReusableBoundOrder($order, $orderId, $storeId)) {
                return $this->fail('order_missing', true);
            }
            $order['order_id'] = $orderId;
            $order['store_id'] = $storeId;

            $payloadPreview = $this->payloadBuilder->build($orderId, $order, $orderProducts, $calculation, $shop);
            $fingerprint = MtUniCreditControlPanelOrderPayloadBuilder::fingerprint($payloadPreview);
            $selectionHash = hash(
                'sha256',
                $calculation->scheme->kopCode . '|' . $calculation->scheme->months . '|' . $fingerprint
            );

            try {
                $attempt = $this->attempts->findOrCreateAttempt(
                    $storeId,
                    $orderId,
                    $unicid,
                    $operationKeyHash,
                    $selectionHash,
                    $fingerprint,
                    $entryPoint
                );
            } catch (MtUniCreditPersistenceValidationException $exception) {
                $this->logDecision(
                    $correlationId,
                    $entryPoint,
                    $operationKeyHash,
                    $orderId,
                    0,
                    '',
                    'reject_stale',
                    'find_or_create_identity_mismatch'
                );

                return $this->fail('conflict', true);
            }

            if (!hash_equals((string) $attempt['operation_key_hash'], (string) $operationKeyHash)) {
                $this->logDecision(
                    $correlationId,
                    $entryPoint,
                    $operationKeyHash,
                    $orderId,
                    (int) $attempt['attempt_id'],
                    (string) $attempt['state'],
                    'reject_stale',
                    'attempt_hash_guard'
                );

                return $this->fail('conflict', true);
            }

            $this->logDecision(
                $correlationId,
                $entryPoint,
                $operationKeyHash,
                $orderId,
                (int) $attempt['attempt_id'],
                (string) $attempt['state'],
                $decision,
                $reuseReason,
                isset($attempt['control_panel_order_id']) ? (int) $attempt['control_panel_order_id'] : 0
            );

            if (MtUniCreditShopConfigurationFlags::isSecondaryProcess($shop)) {
                $posted = isset($input['customer']) && is_array($input['customer']) ? $input['customer'] : array();
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
                    return $this->fail('validation', true);
                } catch (RuntimeException $exception) {
                    return $this->fail('process2_encryption_unavailable', true);
                }
                MtUniCreditProcessTwoSubmissionSupport::persistLeasingSnapshot(
                    $calculation,
                    $orderId,
                    (int) $attempt['attempt_id'],
                    $this->attempts->database()
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
                    'order_id' => $orderId,
                    'control_panel_order_id' => $result->controlPanelOrderId,
                    'local_replay' => $result->localReplay,
                    'cp_succeeded' => $result->cpSucceeded,
                    'attempt' => $fresh !== null ? $fresh : $attempt,
                    'apply_native_order_status' => $result->applyNativeOrderStatus,
                    'cart_unchanged' => true,
                    'bank_status' => $this->resolveBankStatusId($storeId, $orderId),
                    'session' => $sessionData,
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
                'order_id' => $orderId,
                'control_panel_order_id' => $result->controlPanelOrderId,
                'recoverable' => $result->recoverable,
                'ambiguous_blocked' => $result->ambiguousBlocked,
                'cp_succeeded' => $result->cpSucceeded,
                'attempt' => $fresh !== null ? $fresh : $attempt,
                'apply_native_order_status' => $result->applyNativeOrderStatus,
                'cart_unchanged' => true,
                'bank_status' => $this->resolveBankStatusId($storeId, $orderId),
                'session' => $sessionData,
            );
        } finally {
            $this->locks->release($storeId, $entryPoint, $operationKeyHash, $lockOwnerToken);
        }
    }

    /**
     * @param array<string, mixed> $shop
     * @param MtUniCreditProductContext $product
     * @param array{type:string,kop_code:string,months:int,filter_id:int} $parsed
     * @return MtUniCreditAvailableScheme|null
     */
    private function findScheme(array $shop, MtUniCreditProductContext $product, array $parsed)
    {
        $schemes = $this->calculator->availableSchemes($shop, $product, $parsed['type']);
        foreach ($schemes as $scheme) {
            if (
                $scheme->kopCode === $parsed['kop_code']
                && $scheme->months === $parsed['months']
                && $scheme->filterId === $parsed['filter_id']
            ) {
                return $scheme;
            }
        }
        // Prefer identity match without filter when filter drifted to lowest id.
        foreach ($schemes as $scheme) {
            if ($scheme->kopCode === $parsed['kop_code'] && $scheme->months === $parsed['months']) {
                return $scheme;
            }
        }

        return null;
    }

    /**
     * @param MtUniCreditCartResolution $resolution
     * @param array<string, mixed> $shop
     * @param array{type:string,kop_code:string,months:int,filter_id:int} $parsed
     * @return MtUniCreditAvailableScheme|null
     */
    private function findCartScheme(MtUniCreditCartResolution $resolution, array $shop, array $parsed)
    {
        foreach ($this->cartSchemes->unifiedSchemes($resolution, $shop) as $scheme) {
            if (
                $scheme->type === $parsed['type']
                && $scheme->kopCode === $parsed['kop_code']
                && $scheme->months === $parsed['months']
            ) {
                return $scheme;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $sessionData
     * @param string $bindKey application-scoped bind key
     * @return int
     */
    private function resolveBoundOrderId(array $sessionData, $bindKey)
    {
        if (
            !isset($sessionData[self::SESSION_ORDER_BIND_KEY])
            || !is_array($sessionData[self::SESSION_ORDER_BIND_KEY])
        ) {
            return 0;
        }
        $bind = $sessionData[self::SESSION_ORDER_BIND_KEY];
        if (!isset($bind[$bindKey])) {
            return 0;
        }
        $value = $bind[$bindKey];
        // Legacy bare int under product hash is ignored by callers via pruneLegacyBareBinds.
        if (is_array($value) && isset($value['order_id'])) {
            return (int) $value['order_id'];
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $sessionData
     * @param string $bindKey
     * @param int $orderId
     * @return void
     */
    private function bindOrderId(array &$sessionData, $bindKey, $orderId)
    {
        if (
            !isset($sessionData[self::SESSION_ORDER_BIND_KEY])
            || !is_array($sessionData[self::SESSION_ORDER_BIND_KEY])
        ) {
            $sessionData[self::SESSION_ORDER_BIND_KEY] = array();
        }
        $sessionData[self::SESSION_ORDER_BIND_KEY][$bindKey] = (int) $orderId;
    }

    /**
     * Remove only the binding for this bind key — leave unrelated session financing bindings.
     *
     * @param array<string, mixed> $sessionData
     * @param string $bindKey
     * @return void
     */
    private function unbindOrderId(array &$sessionData, $bindKey)
    {
        if (
            !isset($sessionData[self::SESSION_ORDER_BIND_KEY])
            || !is_array($sessionData[self::SESSION_ORDER_BIND_KEY])
        ) {
            return;
        }
        unset($sessionData[self::SESSION_ORDER_BIND_KEY][$bindKey]);
    }

    /**
     * Drop legacy product-hash→order_id binds that are not application-scoped.
     *
     * @param array<string, mixed> $sessionData
     * @param string $operationKeyHash
     * @param string $currentBindKey
     * @return void
     */
    private function pruneLegacyBareBinds(array &$sessionData, $operationKeyHash, $currentBindKey)
    {
        if (
            !isset($sessionData[self::SESSION_ORDER_BIND_KEY])
            || !is_array($sessionData[self::SESSION_ORDER_BIND_KEY])
        ) {
            return;
        }
        if (
            isset($sessionData[self::SESSION_ORDER_BIND_KEY][$operationKeyHash])
            && (string) $operationKeyHash !== (string) $currentBindKey
        ) {
            unset($sessionData[self::SESSION_ORDER_BIND_KEY][$operationKeyHash]);
        }
    }

    /**
     * @param string $correlationId
     * @param string $entryPoint
     * @param string $operationKeyHash
     * @param int $boundOrderId
     * @param int $attemptId
     * @param string $attemptState
     * @param string $decision
     * @param string $reuseReason
     * @param int $cpOrderId
     * @return void
     */
    private function logDecision(
        $correlationId,
        $entryPoint,
        $operationKeyHash,
        $boundOrderId,
        $attemptId,
        $attemptState,
        $decision,
        $reuseReason = '',
        $cpOrderId = 0
    ) {
        error_log(
            'mt_uni_credit: storefront_submit'
                . ' correlation=' . $correlationId
                . ' entry=' . $entryPoint
                . ' op=' . substr((string) $operationKeyHash, 0, 12)
                . ' order_id=' . (int) $boundOrderId
                . ' attempt_id=' . (int) $attemptId
                . ' attempt_state=' . (string) $attemptState
                . ' cp_order_id=' . (int) $cpOrderId
                . ' decision=' . (string) $decision
                . ($reuseReason !== '' ? ' reuse_reason=' . $reuseReason : '')
        );
    }

    /**
     * Bound Product/Cart order_id is reusable only when the real OC order still exists.
     *
     * @param array<string, mixed>|null $order
     * @param int $orderId
     * @param int $storeId
     * @return bool
     */
    private function isReusableBoundOrder($order, $orderId, $storeId)
    {
        $orderId = (int) $orderId;
        $storeId = (int) $storeId;
        if ($orderId <= 0 || !is_array($order) || $order === array()) {
            return false;
        }
        $loadedId = (int) (isset($order['order_id']) ? $order['order_id'] : 0);
        if ($loadedId > 0 && $loadedId !== $orderId) {
            return false;
        }
        if ((int) (isset($order['store_id']) ? $order['store_id'] : -1) !== $storeId) {
            return false;
        }
        $paymentCode = isset($order['payment_code']) ? $order['payment_code'] : null;
        if ($paymentCode !== null && $paymentCode !== '' && !MtUniCreditPaymentIdentity::matchesStoredPayment($paymentCode)) {
            return false;
        }

        return true;
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
     * @param string $error
     * @param bool $cartUnchanged
     * @return array<string, mixed>
     */
    private function fail($error, $cartUnchanged)
    {
        return array(
            'success' => false,
            'error' => $error,
            'message' => MtUniCreditControlPanelOrderLifecycleService::CUSTOMER_FAILURE_MESSAGE,
            'cart_unchanged' => (bool) $cartUnchanged,
            'apply_native_order_status' => false,
        );
    }
}
