<?php

/**
 * Immutable financing application snapshot for one attempt (AUD-007-F02).
 *
 * Authority for financial terms after attempt creation. CP payload and SmartUCF
 * inputs must derive from this snapshot (or reject live drift before remote side effects).
 */
final class MtUniCreditApplicationSnapshot
{
    const VERSION = 1;

    const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * @param MtUniCreditCalculationResult $calculation
     * @param array<string, mixed> $order
     * @param array<int, array<string, mixed>> $orderProducts
     * @param array<string, mixed> $shop
     * @param string $entryPoint
     * @param string $operationKeyHash
     * @param string $selectionHash
     * @param string $requestFingerprint
     * @return array<string, mixed>
     */
    public static function fromLive(
        MtUniCreditCalculationResult $calculation,
        array $order,
        array $orderProducts,
        array $shop,
        $entryPoint,
        $operationKeyHash,
        $selectionHash,
        $requestFingerprint
    ) {
        $products = array();
        foreach ($orderProducts as $product) {
            if (!is_array($product)) {
                continue;
            }
            $products[] = array(
                'product_id' => (int) (isset($product['product_id']) ? $product['product_id'] : 0),
                'name' => (string) (isset($product['name']) ? $product['name'] : ''),
                'quantity' => max(1, (int) (isset($product['quantity']) ? $product['quantity'] : 1)),
                'total' => self::decimalString(
                    isset($product['total'])
                        ? $product['total']
                        : (isset($product['price']) ? $product['price'] : 0),
                    4
                ),
            );
        }

        $firstname = isset($order['firstname']) ? (string) $order['firstname'] : '';
        $lastname = isset($order['lastname']) ? (string) $order['lastname'] : '';
        $billing = self::formatAddress($order, 'payment_');
        $shipping = self::formatAddress($order, 'shipping_');
        if ($shipping === '') {
            $shipping = $billing;
        }

        $currency = strtoupper(trim(isset($order['currency_code']) ? (string) $order['currency_code'] : 'BGN'));
        if ($currency !== 'BGN' && $currency !== 'EUR') {
            $currency = 'BGN';
        }

        $first = 0.0;
        if (isset($calculation->firstInstallment) && is_object($calculation->firstInstallment)) {
            $first = (float) $calculation->firstInstallment->amount;
        }

        $scheme = $calculation->scheme;

        return array(
            'version' => self::VERSION,
            'entry_point' => (string) $entryPoint,
            'operation_key_hash' => (string) $operationKeyHash,
            'selection_hash' => (string) $selectionHash,
            'request_fingerprint' => (string) $requestFingerprint,
            'scheme' => array(
                'type' => (string) $scheme->type,
                'kop_code' => (string) $scheme->kopCode,
                'months' => (int) $scheme->months,
                'filter_id' => (int) $scheme->filterId,
                'first_installment_ambiguous' => !empty($scheme->firstInstallmentAmbiguous) ? 1 : 0,
            ),
            'financial' => array(
                'price' => self::decimalString($calculation->price, 2),
                'first_installment' => self::decimalString($first, 2),
                'financed_amount' => self::decimalString($calculation->financedAmount, 2),
                'monthly_installment' => self::decimalString($calculation->monthlyInstallment, 2),
                'total_payable' => self::decimalString($calculation->totalPayable, 2),
                'glp' => self::decimalString($calculation->glp, 2),
                'gpr' => self::decimalString($calculation->gpr, 2),
                'currency' => $currency,
            ),
            'customer' => array(
                'name' => trim($firstname . ' ' . $lastname),
                'phone' => isset($order['telephone']) ? (string) $order['telephone'] : '',
                'email' => isset($order['email']) ? (string) $order['email'] : '',
                'address' => $billing,
                'address2' => $shipping !== '' ? $shipping : '-',
            ),
            'products' => $products,
            'cp_type_client' => !empty($shop['_is_mobile']) ? 0 : 1,
        );
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return string
     */
    public static function encode(array $snapshot)
    {
        $encoded = json_encode($snapshot, self::JSON_FLAGS);
        if ($encoded === false) {
            throw new MtUniCreditPersistenceValidationException('Application snapshot cannot be encoded.');
        }

        return $encoded;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return string
     */
    public static function hash(array $snapshot)
    {
        return hash('sha256', self::encode($snapshot));
    }

    /**
     * @param mixed $raw
     * @return array<string, mixed>|null
     */
    public static function decode($raw)
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['version'], $decoded['financial'], $decoded['scheme'])) {
            return null;
        }

        return $decoded;
    }

    /**
     * Bind/persist immutable snapshot on an attempt and resolve the calculation authority.
     *
     * @param MtUniCreditFinancingAttemptRepository $attempts
     * @param array<string, mixed> $attempt
     * @param array<string, mixed> $liveSnapshot
     * @return array{
     *   ok:bool,
     *   error?:string,
     *   attempt?:array<string,mixed>,
     *   snapshot?:array<string,mixed>,
     *   calculation?:MtUniCreditCalculationResult
     * }
     */
    public static function bindToAttempt(
        MtUniCreditFinancingAttemptRepository $attempts,
        array $attempt,
        array $liveSnapshot
    ) {
        $liveHash = self::hash($liveSnapshot);
        $existing = self::decode(isset($attempt['application_snapshot_json']) ? $attempt['application_snapshot_json'] : null);

        if ($existing !== null) {
            $existingHash = isset($attempt['application_snapshot_hash'])
                ? (string) $attempt['application_snapshot_hash']
                : self::hash($existing);
            if (!hash_equals($existingHash, $liveHash)) {
                if (self::mustMatchLiveIntent($attempt)) {
                    return array('ok' => false, 'error' => 'application_drift');
                }

                return array(
                    'ok' => true,
                    'attempt' => $attempt,
                    'snapshot' => $existing,
                    'calculation' => self::toCalculationResult($existing),
                );
            }

            return array(
                'ok' => true,
                'attempt' => $attempt,
                'snapshot' => $existing,
                'calculation' => self::toCalculationResult($existing),
            );
        }

        // Pre-production: never invent a snapshot from live data after remote CP side effects.
        if (!self::mustMatchLiveIntent($attempt)) {
            return array('ok' => false, 'error' => 'application_drift');
        }

        if (
            !hash_equals(
                (string) (isset($attempt['selection_hash']) ? $attempt['selection_hash'] : ''),
                (string) (isset($liveSnapshot['selection_hash']) ? $liveSnapshot['selection_hash'] : '')
            )
        ) {
            return array('ok' => false, 'error' => 'application_drift');
        }
        $storedFp = isset($attempt['request_fingerprint']) ? (string) $attempt['request_fingerprint'] : '';
        $liveFp = isset($liveSnapshot['request_fingerprint']) ? (string) $liveSnapshot['request_fingerprint'] : '';
        if ($storedFp !== '' && !hash_equals($storedFp, $liveFp)) {
            return array('ok' => false, 'error' => 'fingerprint_drift');
        }

        try {
            $attempt = $attempts->persistApplicationSnapshot((int) $attempt['attempt_id'], $liveSnapshot);
        } catch (MtUniCreditPersistenceValidationException $exception) {
            return array('ok' => false, 'error' => 'application_drift');
        }

        $stored = self::decode(isset($attempt['application_snapshot_json']) ? $attempt['application_snapshot_json'] : null);
        if ($stored === null) {
            return array('ok' => false, 'error' => 'application_drift');
        }

        return array(
            'ok' => true,
            'attempt' => $attempt,
            'snapshot' => $stored,
            'calculation' => self::toCalculationResult($stored),
        );
    }

    /**
     * Rebuild a calculation object for CP/SmartUCF builders from the frozen snapshot.
     *
     * @param array<string, mixed> $snapshot
     * @return MtUniCreditCalculationResult
     */
    public static function toCalculationResult(array $snapshot)
    {
        $schemeData = isset($snapshot['scheme']) && is_array($snapshot['scheme']) ? $snapshot['scheme'] : array();
        $financial = isset($snapshot['financial']) && is_array($snapshot['financial']) ? $snapshot['financial'] : array();

        $scheme = new MtUniCreditAvailableScheme(
            isset($schemeData['type']) ? (string) $schemeData['type'] : 'standard',
            isset($schemeData['kop_code']) ? (string) $schemeData['kop_code'] : '',
            isset($schemeData['months']) ? (int) $schemeData['months'] : 0,
            isset($schemeData['filter_id']) ? (int) $schemeData['filter_id'] : 0,
            null,
            array(),
            !empty($schemeData['first_installment_ambiguous'])
        );

        $firstAmount = isset($financial['first_installment']) ? (float) $financial['first_installment'] : 0.0;

        return new MtUniCreditCalculationResult(
            $scheme,
            isset($financial['price']) ? (float) $financial['price'] : 0.0,
            new MtUniCreditFirstInstallmentState($firstAmount, true, true),
            isset($financial['financed_amount']) ? (float) $financial['financed_amount'] : 0.0,
            isset($financial['monthly_installment']) ? (float) $financial['monthly_installment'] : 0.0,
            isset($financial['total_payable']) ? (float) $financial['total_payable'] : 0.0,
            isset($financial['glp']) ? (float) $financial['glp'] : 0.0,
            isset($financial['gpr']) ? (float) $financial['gpr'] : 0.0
        );
    }

    /**
     * Whether live intent must match the frozen attempt (pre-CP side-effect window).
     *
     * @param array<string, mixed> $attempt
     * @return bool
     */
    public static function mustMatchLiveIntent(array $attempt)
    {
        if (!empty($attempt['cp_payload']) && is_string($attempt['cp_payload']) && $attempt['cp_payload'] !== '') {
            return false;
        }
        if ((int) (isset($attempt['control_panel_order_id']) ? $attempt['control_panel_order_id'] : 0) > 0) {
            return false;
        }
        $state = isset($attempt['state']) ? (string) $attempt['state'] : '';
        if (
            $state === MtUniCreditFinancingAttemptState::CP_CREATED
            || $state === MtUniCreditFinancingAttemptState::CP_OUTCOME_UNKNOWN
            || $state === MtUniCreditFinancingAttemptState::CP_SUBMITTING
        ) {
            return false;
        }

        return true;
    }

    /**
     * @param mixed $value
     * @param int $scale
     * @return string
     */
    public static function decimalString($value, $scale)
    {
        $scale = max(0, (int) $scale);
        $rounded = round((float) $value, $scale);

        return sprintf('%.' . $scale . 'F', $rounded);
    }

    /**
     * @param array<string, mixed> $order
     * @param string $prefix
     * @return string
     */
    private static function formatAddress(array $order, $prefix)
    {
        $parts = array(
            isset($order[$prefix . 'address_1']) ? (string) $order[$prefix . 'address_1'] : '',
            isset($order[$prefix . 'address_2']) ? (string) $order[$prefix . 'address_2'] : '',
            isset($order[$prefix . 'postcode']) ? (string) $order[$prefix . 'postcode'] : '',
            isset($order[$prefix . 'city']) ? (string) $order[$prefix . 'city'] : '',
            isset($order[$prefix . 'country']) ? (string) $order[$prefix . 'country'] : '',
        );

        return trim(implode(', ', array_filter($parts, function ($part) {
            return $part !== '';
        })));
    }
}
