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

        if (!$this->locks->acquire($storeId, $entryPoint, $operationKeyHash, $lockOwnerToken)) {
            return array(
                'success' => false,
                'error' => 'duplicate_request',
                'message' => MtUniCreditControlPanelOrderLifecycleService::CUSTOMER_FAILURE_MESSAGE,
                'cart_unchanged' => true,
            );
        }

        try {
            $sessionData = isset($input['session']) && is_array($input['session']) ? $input['session'] : array();
            $orderId = $this->resolveBoundOrderId($sessionData, $operationKeyHash);
            $addOrder = isset($input['add_order']) && is_callable($input['add_order'])
                ? $input['add_order']
                : null;
            $loadOrder = isset($input['load_order']) && is_callable($input['load_order'])
                ? $input['load_order']
                : null;

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
            }

            $order = null;
            if ($loadOrder !== null) {
                $loaded = call_user_func($loadOrder, $orderId);
                $order = is_array($loaded) ? $loaded : null;
            }
            if ($order === null) {
                $order = array(
                    'order_id' => $orderId,
                    'store_id' => $storeId,
                    'total' => $orderTotal,
                    'payment_code' => MtUniCreditConstants::EXTENSION_CODE,
                    'payment_method' => MtUniCreditConstants::DISPLAY_NAME,
                    'order_status_id' => 0,
                    'currency_code' => $currency,
                );
            }
            $order['order_id'] = $orderId;
            $order['store_id'] = $storeId;

            $payloadPreview = $this->payloadBuilder->build($orderId, $order, $orderProducts, $calculation, $shop);
            $fingerprint = MtUniCreditControlPanelOrderPayloadBuilder::fingerprint($payloadPreview);
            $selectionHash = hash(
                'sha256',
                $calculation->scheme->kopCode . '|' . $calculation->scheme->months . '|' . $fingerprint
            );

            $attempt = $this->attempts->findOrCreateAttempt(
                $storeId,
                $orderId,
                $unicid,
                $operationKeyHash,
                $selectionHash,
                $fingerprint,
                $entryPoint
            );

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
                    'attempt' => $fresh !== null ? $fresh : $attempt,
                    'apply_native_order_status' => !$result->localReplay,
                    'cart_unchanged' => true,
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
                'attempt' => $fresh !== null ? $fresh : $attempt,
                'apply_native_order_status' => $result->cpSucceeded && !$result->localReplay,
                'cart_unchanged' => true,
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
     * @param string $operationKeyHash
     * @return int
     */
    private function resolveBoundOrderId(array $sessionData, $operationKeyHash)
    {
        if (
            !isset($sessionData[self::SESSION_ORDER_BIND_KEY])
            || !is_array($sessionData[self::SESSION_ORDER_BIND_KEY])
        ) {
            return 0;
        }
        $bind = $sessionData[self::SESSION_ORDER_BIND_KEY];

        return isset($bind[$operationKeyHash]) ? (int) $bind[$operationKeyHash] : 0;
    }

    /**
     * @param array<string, mixed> $sessionData
     * @param string $operationKeyHash
     * @param int $orderId
     * @return void
     */
    private function bindOrderId(array &$sessionData, $operationKeyHash, $orderId)
    {
        if (
            !isset($sessionData[self::SESSION_ORDER_BIND_KEY])
            || !is_array($sessionData[self::SESSION_ORDER_BIND_KEY])
        ) {
            $sessionData[self::SESSION_ORDER_BIND_KEY] = array();
        }
        $sessionData[self::SESSION_ORDER_BIND_KEY][$operationKeyHash] = (int) $orderId;
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
