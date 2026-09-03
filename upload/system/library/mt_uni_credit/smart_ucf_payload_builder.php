<?php

/**
 * Builds the SmartUCF session-start JSON payload (Process 1 only).
 */
final class MtUniCreditSmartUcfPayloadBuilder
{
    /**
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $order
     * @param array<int, array<string, mixed>> $orderProducts
     * @param MtUniCreditCalculationResult $calculation
     * @param int|string $localOrderId
     * @return array<string, mixed>
     */
    public function build(array $shop, array $order, array $orderProducts, MtUniCreditCalculationResult $calculation, $localOrderId)
    {
        $deliveryAddress = trim(implode(', ', array_filter(array(
            isset($order['payment_address_1']) ? (string) $order['payment_address_1'] : '',
            isset($order['payment_address_2']) ? (string) $order['payment_address_2'] : '',
            isset($order['payment_city']) ? (string) $order['payment_city'] : '',
            isset($order['payment_postcode']) ? (string) $order['payment_postcode'] : '',
        ), array($this, 'isNonEmptyTrimmed'))));

        $currencyIso = isset($order['currency_code']) ? (string) $order['currency_code'] : '';

        $payload = array(
            'user' => (string) (isset($shop['uni_user']) ? $shop['uni_user'] : ''),
            'pass' => (string) (isset($shop['uni_password']) ? $shop['uni_password'] : ''),
            'orderNo' => (string) $localOrderId,
            'clientFirstName' => $this->clean(isset($order['firstname']) ? (string) $order['firstname'] : ''),
            'clientLastName' => $this->clean(isset($order['lastname']) ? (string) $order['lastname'] : ''),
            'clientPhone' => $this->clean(isset($order['telephone']) ? (string) $order['telephone'] : ''),
            'clientEmail' => $this->clean(isset($order['email']) ? (string) $order['email'] : ''),
            'clientDeliveryAddress' => $this->clean($deliveryAddress),
            'onlineProductCode' => $calculation->scheme->kopCode,
            'totalPrice' => $this->formatAmount($calculation->price),
            'initialPayment' => $this->formatAmount($calculation->firstInstallment->amount),
            'installmentCount' => $calculation->scheme->months,
            'monthlyPayment' => $this->formatAmount($calculation->monthlyInstallment),
            'items' => $this->buildItems($orderProducts, $shop, $currencyIso),
        );

        foreach (array_keys($payload) as $key) {
            if (preg_match('/egn|phone2/i', (string) $key)) {
                throw new LogicException('Sensitive Process 2 field leaked into SmartUCF payload.');
            }
        }

        return $payload;
    }

    /**
     * @param string $value
     * @return bool
     */
    private function isNonEmptyTrimmed($value)
    {
        return trim((string) $value) !== '';
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, mixed> $shop
     * @param string $currencyIso
     * @return array<int, array<string, mixed>>
     */
    private function buildItems(array $lines, array $shop, $currencyIso)
    {
        $items = array();
        foreach ($lines as $line) {
            $quantity = max(1, (int) (isset($line['quantity']) ? $line['quantity'] : 1));
            $lineTotal = isset($line['total']) ? $line['total'] : (isset($line['price']) ? $line['price'] : 0);
            $unitPrice = ((float) $lineTotal) / $quantity;
            $uniEur = (int) (isset($shop['uni_eur']) ? $shop['uni_eur'] : 0);
            if ($uniEur === 1 && strtoupper($currencyIso) === 'EUR') {
                $unitPrice *= 1.95583;
            } elseif (in_array($uniEur, array(2, 3), true) && strtoupper($currencyIso) === 'BGN') {
                $unitPrice /= 1.95583;
            }
            $items[] = array(
                'name' => $this->clean(isset($line['name']) ? (string) $line['name'] : ''),
                'code' => (int) (isset($line['product_id']) ? $line['product_id'] : 0),
                'type' => 0,
                'count' => $quantity,
                'singlePrice' => $this->formatAmount($unitPrice),
            );
        }

        return $items;
    }

    /**
     * @param float $amount
     * @return string
     */
    private function formatAmount($amount)
    {
        return number_format(abs((float) $amount), 2, '.', '');
    }

    /**
     * @param string $value
     * @return string
     */
    private function clean($value)
    {
        return str_replace(array("'", "\u{2019}"), '', trim($value));
    }
}
