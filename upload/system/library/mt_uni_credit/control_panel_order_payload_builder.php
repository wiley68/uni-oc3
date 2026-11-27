<?php

/**
 * Builds Control Panel POST /orders body from server-authoritative order + calculation.
 *
 * GAP-14: order_id is never truncated/coerced; free-text product names keep UTF-8,
 * apostrophes and underscores (no underscore→hyphen, no HTML-encode).
 */
final class MtUniCreditControlPanelOrderPayloadBuilder
{
    // The canonical EUR producer is present at 841d905 (2.0.3),
    // 9144637 (2.0.4) and de0cd59 (2.0.5).
    private const RECONSTRUCTION_VERSIONS = array('2.0.3', '2.0.4', '2.0.5');

    /**
     * @param int|string $localOrderId
     * @param array<string, mixed> $order OpenCart order row
     * @param array<int, array<string, mixed>> $orderProducts
     * @param MtUniCreditCalculationResult $calculation
     * @param array<string, mixed> $shop
     * @return array<string, mixed>
     */
    public function build($localOrderId, array $order, array $orderProducts, MtUniCreditCalculationResult $calculation, array $shop)
    {
        $orderIdString = $this->normalizeOrderId($localOrderId);
        if (MtUniCreditEurAmount::orderFactor(
            $order,
            isset($order['store_id']) ? $order['store_id'] : -1,
            $orderIdString
        ) === null || !MtUniCreditEurAmount::matchesCalculation($order, $calculation)) {
            throw new InvalidArgumentException('CP order requires proven EUR amounts.');
        }

        $firstname = isset($order['firstname']) ? (string) $order['firstname'] : '';
        $lastname = isset($order['lastname']) ? (string) $order['lastname'] : '';
        $name = trim($firstname . ' ' . $lastname);
        $phone = isset($order['telephone']) ? (string) $order['telephone'] : '';
        $email = isset($order['email']) ? (string) $order['email'] : '';

        $billing = $this->formatAddress($order, 'payment_');
        $shipping = $this->formatAddress($order, 'shipping_');
        if ($shipping === '') {
            $shipping = $billing;
        }
        if ($shipping === '') {
            $shipping = '-';
        }

        return $this->buildCanonical($orderIdString, array(
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'address' => $billing,
            'address2' => $shipping,
        ), $orderProducts, $calculation, !empty($shop['_is_mobile']) ? 0 : 1);
    }

    private function buildCanonical($orderId, array $customer, array $orderProducts, MtUniCreditCalculationResult $calculation, $clientType)
    {
        $ids = array();
        $names = array();
        $quantities = array();
        foreach ($orderProducts as $product) {
            if (!is_array($product)) {
                continue;
            }
            $ids[] = (int) (isset($product['product_id']) ? $product['product_id'] : 0);
            // Preserve free-text exactly (UTF-8, apostrophes, underscores). Delimiter between
            // multi-line names remains "_" at implode time only — never mutate the name itself.
            $names[] = (string) (isset($product['name']) ? $product['name'] : '');
            $quantities[] = max(1, (int) (isset($product['quantity']) ? $product['quantity'] : 1));
        }

        return array(
            'order_id' => $orderId,
            'name' => substr((string) $customer['name'], 0, 65),
            'phone' => substr((string) $customer['phone'], 0, 45),
            'email' => substr((string) $customer['email'], 0, 128),
            'address' => substr((string) $customer['address'], 0, 256),
            'address2' => substr((string) $customer['address2'], 0, 256),
            'price' => round((float) $calculation->price, 2),
            'vnoska' => round((float) $calculation->monthlyInstallment, 2),
            'gpr' => round((float) $calculation->gpr, 2),
            'vnoski' => (int) $calculation->scheme->months,
            'parva' => round((float) $calculation->firstInstallment->amount, 2),
            'products_id' => implode('_', $ids),
            // CP contract: products_name has no max (cp_max null). Never truncate or mutate.
            'products_name' => implode('_', $names),
            'products_q' => implode('_', $quantities),
            'type_client' => $clientType,
            'currency' => 'EUR',
            'version' => MtUniCreditConstants::VERSION,
        );
    }

    /**
     * Prove a live preview against the original request when the attempt still
     * requires live intent comparison. The caller must still compare snapshots.
     */
    public function proveLivePreview(array $preview, MtUniCreditCalculationResult $calculation, array $attempt)
    {
        if (!MtUniCreditApplicationSnapshot::mustMatchLiveIntent($attempt)) {
            return $preview;
        }

        $snapshot = MtUniCreditConsentGate::snapshot($attempt);
        return $this->matchOriginal($preview, $calculation, $snapshot['request_fingerprint'], self::RECONSTRUCTION_VERSIONS);
    }

    public function proveFrozenPayload($orderId, array $snapshot, MtUniCreditCalculationResult $calculation, $saved = null)
    {
        $customer = isset($snapshot['customer']) ? $snapshot['customer'] : null;
        if (!is_array($customer) || !isset($snapshot['products'], $snapshot['cp_type_client'], $snapshot['request_fingerprint'])
            || !is_array($snapshot['products']) || !in_array($snapshot['cp_type_client'], array(0, 1), true)) {
            return null;
        }
        foreach (array('name', 'phone', 'email', 'address', 'address2') as $field) {
            if (!isset($customer[$field]) || !is_string($customer[$field])) {
                return null;
            }
        }

        // Both snapshot customer shapes store the full, untruncated CP name
        // and the already resolved billing/shipping address strings.
        $preview = $this->buildCanonical($orderId, $customer, $snapshot['products'], $calculation, $snapshot['cp_type_client']);
        $original = $snapshot['request_fingerprint'];
        if ($saved === null) {
            return $this->matchOriginal($preview, $calculation, $original, self::RECONSTRUCTION_VERSIONS);
        }

        if (array_diff_key($preview, $saved) || array_diff_key($saved, $preview)
            || !is_string($saved['version']) || !preg_match('/\A[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\z/', $saved['version'])) {
            return null;
        }
        foreach ($preview as $field => $value) {
            if (is_float($value)) {
                if (!MtUniCreditEurAmount::validMoney($saved[$field])) {
                    return null;
                }
            } elseif (gettype($saved[$field]) !== gettype($value)) {
                return null;
            }
        }
        if (!is_string($original) || $original === '' || !hash_equals($original, self::fingerprint($saved))) {
            return null;
        }

        $candidate = $this->matchOriginal($preview, $calculation, $original, array($saved['version']));
        if ($candidate === null) {
            return null;
        }

        // CP compares numeric values as floats. The local fingerprint only proves cents.
        foreach (array('price', 'parva', 'vnoska', 'gpr') as $field) {
            if ((float) $saved[$field] !== (float) $candidate[$field]) {
                return null;
            }
        }

        return $saved;
    }

    private function matchOriginal(array $preview, MtUniCreditCalculationResult $calculation, $original, array $versions)
    {
        if (!is_string($original) || $original === '') {
            return null;
        }
        $gross = $preview['price'];
        $principal = round((float) $calculation->financedAmount, 2);
        $prices = $gross === $principal ? array($gross) : array($gross, $principal);
        $match = null;
        foreach ($versions as $version) {
            $preview['version'] = $version;
            foreach ($prices as $price) {
                if (!MtUniCreditEurAmount::validMoney($price)) {
                    continue;
                }
                $preview['price'] = $price;
                if (hash_equals($original, self::fingerprint($preview))) {
                    if ($match !== null) {
                        return null;
                    }
                    $match = $preview;
                }
            }
        }

        return $match;
    }

    /**
     * Stable fingerprint over frozen business fields (no timestamps).
     *
     * @param array<string, mixed> $payload
     * @return string
     */
    public static function fingerprint(array $payload)
    {
        $fields = array(
            'order_id',
            'name',
            'phone',
            'email',
            'address',
            'address2',
            'price',
            'vnoska',
            'gpr',
            'vnoski',
            'parva',
            'products_id',
            'products_name',
            'products_q',
            'type_client',
            'currency',
            'version',
        );
        $canonical = array();
        foreach ($fields as $field) {
            $value = isset($payload[$field]) ? $payload[$field] : null;
            if (in_array($field, array('price', 'vnoska', 'gpr', 'parva'), true)) {
                $value = number_format((float) $value, 2, '.', '');
            } elseif (in_array($field, array('vnoski', 'type_client'), true)) {
                $value = (string) (int) $value;
            } else {
                $value = (string) $value;
            }
            $canonical[$field] = $value;
        }

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param int|string $localOrderId
     * @return string
     */
    private function normalizeOrderId($localOrderId)
    {
        return MtUniCreditShopOrderId::requireNormalized($localOrderId);
    }

    /**
     * @param array<string, mixed> $order
     * @param string $prefix
     * @return string
     */
    private function formatAddress(array $order, $prefix)
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
