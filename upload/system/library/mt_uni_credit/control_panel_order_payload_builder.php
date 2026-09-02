<?php

/**
 * Builds Control Panel POST /orders body from server-authoritative order + calculation.
 */
final class MtUniCreditControlPanelOrderPayloadBuilder
{
    /**
     * @param int $localOrderId
     * @param array<string, mixed> $order OpenCart order row
     * @param array<int, array<string, mixed>> $orderProducts
     * @param MtUniCreditCalculationResult $calculation
     * @param array<string, mixed> $shop
     * @return array<string, mixed>
     */
    public function build($localOrderId, array $order, array $orderProducts, MtUniCreditCalculationResult $calculation, array $shop)
    {
        $ids = array();
        $names = array();
        $quantities = array();
        foreach ($orderProducts as $product) {
            if (!is_array($product)) {
                continue;
            }
            $ids[] = (int) (isset($product['product_id']) ? $product['product_id'] : 0);
            $names[] = str_replace('_', '-', (string) (isset($product['name']) ? $product['name'] : ''));
            $quantities[] = max(1, (int) (isset($product['quantity']) ? $product['quantity'] : 1));
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

        $currency = strtoupper(trim(isset($order['currency_code']) ? (string) $order['currency_code'] : 'BGN'));
        if ($currency !== 'BGN' && $currency !== 'EUR') {
            $currency = 'BGN';
        }

        return array(
            'order_id' => substr((string) (int) $localOrderId, 0, 13),
            'name' => substr($name, 0, 65),
            'phone' => substr($phone, 0, 45),
            'email' => substr($email, 0, 128),
            'address' => substr($billing, 0, 256),
            'address2' => substr($shipping, 0, 256),
            'price' => round((float) $calculation->financedAmount, 2),
            'vnoska' => round((float) $calculation->monthlyInstallment, 2),
            'gpr' => round((float) $calculation->gpr, 2),
            'vnoski' => (int) $calculation->scheme->months,
            'parva' => round((float) $calculation->firstInstallment->amount, 2),
            'products_id' => implode('_', $ids),
            'products_name' => substr(implode('_', $names), 0, 255),
            'products_q' => implode('_', $quantities),
            'type_client' => !empty($shop['_is_mobile']) ? 0 : 1,
            'currency' => $currency,
            'version' => MtUniCreditConstants::VERSION,
        );
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
