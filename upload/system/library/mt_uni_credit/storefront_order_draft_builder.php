<?php

/**
 * Builds OC3 model_checkout_order->addOrder() array for Product/Cart storefront.
 */
final class MtUniCreditStorefrontOrderDraftBuilder
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function buildOrderData(array $input)
    {
        $customer = isset($input['customer']) && is_array($input['customer']) ? $input['customer'] : array();
        $firstname = trim((string) (isset($customer['firstname']) ? $customer['firstname'] : ''));
        $lastname = trim((string) (isset($customer['lastname']) ? $customer['lastname'] : ''));
        $email = trim((string) (isset($customer['email']) ? $customer['email'] : ''));
        $telephone = trim((string) (isset($customer['telephone']) ? $customer['telephone'] : ''));
        $address1 = trim((string) (isset($customer['address_1']) ? $customer['address_1'] : ''));
        $address2 = trim((string) (isset($customer['address_2']) ? $customer['address_2'] : ''));
        $city = trim((string) (isset($customer['city']) ? $customer['city'] : ''));
        $postcode = trim((string) (isset($customer['postcode']) ? $customer['postcode'] : ''));
        $country = trim((string) (isset($customer['country']) ? $customer['country'] : ''));
        $countryId = (int) (isset($customer['country_id']) ? $customer['country_id'] : 0);
        $zone = trim((string) (isset($customer['zone']) ? $customer['zone'] : ''));
        $zoneId = (int) (isset($customer['zone_id']) ? $customer['zone_id'] : 0);
        $company = trim((string) (isset($customer['company']) ? $customer['company'] : ''));

        $products = array();
        if (isset($input['product_line']) && $input['product_line'] instanceof MtUniCreditProductLine) {
            /** @var MtUniCreditProductLine $line */
            $line = $input['product_line'];
            $unitTax = max(0.0, $line->unitWithTax - $line->unitExTax);
            $products[] = array(
                'product_id' => $line->productId,
                'name' => $line->name,
                'model' => $line->model,
                'quantity' => $line->quantity,
                'price' => $line->unitExTax,
                'total' => round($line->unitExTax * $line->quantity, 4),
                'tax' => $unitTax,
                'reward' => $line->reward,
                'option' => $line->options,
            );
            $orderTotal = isset($input['order_total'])
                ? (float) $input['order_total']
                : $line->financingPrice;
            $subTotal = round($line->unitExTax * $line->quantity, 4);
        } else {
            $cartProducts = isset($input['products']) && is_array($input['products']) ? $input['products'] : array();
            foreach ($cartProducts as $product) {
                if (!is_array($product)) {
                    continue;
                }
                $products[] = array(
                    'product_id' => (int) (isset($product['product_id']) ? $product['product_id'] : 0),
                    'name' => (string) (isset($product['name']) ? $product['name'] : ''),
                    'model' => (string) (isset($product['model']) ? $product['model'] : ''),
                    'quantity' => (int) (isset($product['quantity']) ? $product['quantity'] : 1),
                    'price' => (float) (isset($product['price']) ? $product['price'] : 0),
                    'total' => (float) (isset($product['total']) ? $product['total'] : 0),
                    'tax' => (float) (isset($product['tax']) ? $product['tax'] : 0),
                    'reward' => (int) (isset($product['reward']) ? $product['reward'] : 0),
                    'option' => isset($product['option']) && is_array($product['option']) ? $product['option'] : array(),
                );
            }
            $orderTotal = (float) (isset($input['order_total']) ? $input['order_total'] : 0);
            $subTotal = 0.0;
            foreach ($products as $product) {
                $subTotal += (float) $product['total'];
            }
            $subTotal = round($subTotal, 4);
        }

        $paymentMethod = isset($input['payment_method'])
            ? (string) $input['payment_method']
            : MtUniCreditConstants::DISPLAY_NAME;

        $order = array(
            'invoice_prefix' => (string) (isset($input['invoice_prefix']) ? $input['invoice_prefix'] : ''),
            'store_id' => (int) (isset($input['store_id']) ? $input['store_id'] : 0),
            'store_name' => (string) (isset($input['store_name']) ? $input['store_name'] : ''),
            'store_url' => (string) (isset($input['store_url']) ? $input['store_url'] : ''),
            'customer_id' => (int) (isset($customer['customer_id']) ? $customer['customer_id'] : 0),
            'customer_group_id' => (int) (isset($customer['customer_group_id']) ? $customer['customer_group_id'] : 0),
            'firstname' => $firstname,
            'lastname' => $lastname,
            'email' => $email,
            'telephone' => $telephone,
            'custom_field' => isset($customer['custom_field']) && is_array($customer['custom_field'])
                ? $customer['custom_field']
                : array(),
            'payment_firstname' => $firstname,
            'payment_lastname' => $lastname,
            'payment_company' => $company,
            'payment_address_1' => $address1,
            'payment_address_2' => $address2,
            'payment_city' => $city,
            'payment_postcode' => $postcode,
            'payment_country' => $country,
            'payment_country_id' => $countryId,
            'payment_zone' => $zone,
            'payment_zone_id' => $zoneId,
            'payment_address_format' => '',
            'payment_custom_field' => array(),
            'payment_method' => $paymentMethod,
            'payment_code' => MtUniCreditConstants::EXTENSION_CODE,
            'shipping_firstname' => $firstname,
            'shipping_lastname' => $lastname,
            'shipping_company' => $company,
            'shipping_address_1' => $address1,
            'shipping_address_2' => $address2,
            'shipping_city' => $city,
            'shipping_postcode' => $postcode,
            'shipping_country' => $country,
            'shipping_country_id' => $countryId,
            'shipping_zone' => $zone,
            'shipping_zone_id' => $zoneId,
            'shipping_address_format' => '',
            'shipping_custom_field' => array(),
            'shipping_method' => (string) (isset($input['shipping_method']) ? $input['shipping_method'] : ''),
            'shipping_code' => (string) (isset($input['shipping_code']) ? $input['shipping_code'] : ''),
            'comment' => (string) (isset($input['comment']) ? $input['comment'] : ''),
            'total' => round((float) $orderTotal, 4),
            'affiliate_id' => 0,
            'commission' => 0,
            'marketing_id' => 0,
            'tracking' => '',
            'language_id' => (int) (isset($input['language_id']) ? $input['language_id'] : 0),
            'currency_id' => (int) (isset($input['currency_id']) ? $input['currency_id'] : 0),
            'currency_code' => (string) (isset($input['currency_code']) ? $input['currency_code'] : ''),
            'currency_value' => (float) (isset($input['currency_value']) ? $input['currency_value'] : 1),
            'ip' => (string) (isset($input['ip']) ? $input['ip'] : ''),
            'forwarded_ip' => (string) (isset($input['forwarded_ip']) ? $input['forwarded_ip'] : ''),
            'user_agent' => (string) (isset($input['user_agent']) ? $input['user_agent'] : ''),
            'accept_language' => (string) (isset($input['accept_language']) ? $input['accept_language'] : ''),
            'products' => $products,
            'totals' => array(
                array(
                    'code' => 'sub_total',
                    'title' => 'Sub-Total',
                    'value' => $subTotal,
                    'sort_order' => 1,
                ),
                array(
                    'code' => 'total',
                    'title' => 'Total',
                    'value' => round((float) $orderTotal, 4),
                    'sort_order' => 9,
                ),
            ),
        );

        if (empty($order['shipping_firstname']) && empty($order['shipping_address_1'])) {
            $order['shipping_firstname'] = '';
            $order['shipping_lastname'] = '';
            $order['shipping_company'] = '';
            $order['shipping_address_1'] = '';
            $order['shipping_address_2'] = '';
            $order['shipping_city'] = '';
            $order['shipping_postcode'] = '';
            $order['shipping_country'] = '';
            $order['shipping_country_id'] = 0;
            $order['shipping_zone'] = '';
            $order['shipping_zone_id'] = 0;
        }

        return $order;
    }
}
