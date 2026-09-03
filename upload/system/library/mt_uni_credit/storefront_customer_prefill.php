<?php

/**
 * Product/Cart popup customer prefill (OC4 ProductPopupCustomerPrefill parity).
 */
final class MtUniCreditStorefrontCustomerPrefill
{
    /**
     * @param bool $isLogged
     * @param array<string, mixed> $customer
     * @param list<array<string, mixed>> $addresses
     * @param int $defaultAddressId
     * @return array<string, mixed>
     */
    public function present($isLogged, array $customer, array $addresses, $defaultAddressId = 0)
    {
        $empty = array(
            'firstname' => '',
            'lastname' => '',
            'address' => '',
            'telephone' => '',
            'email' => '',
            'address_id' => 0,
            'is_logged' => false,
            // Process 2 extras — empty unless a real source exists later.
            'phone2' => '',
            'egn' => '',
        );
        if (!$isLogged) {
            return $empty;
        }

        $address = $this->selectAddress($addresses, (int) $defaultAddressId);
        // Phone: customer telephone only when usable. Never invent from unrelated metadata.
        // Address rows in OC3 typically have no telephone; do not substitute phone2/EGN/custom fields.
        $telephone = '';
        if (isset($customer['telephone'])) {
            $telephone = trim((string) $customer['telephone']);
        }

        return array(
            'firstname' => trim((string) (isset($address['firstname']) ? $address['firstname'] : (isset($customer['firstname']) ? $customer['firstname'] : ''))),
            'lastname' => trim((string) (isset($address['lastname']) ? $address['lastname'] : (isset($customer['lastname']) ? $customer['lastname'] : ''))),
            'address' => $this->joinAddress($address),
            'telephone' => $telephone,
            'email' => trim((string) (isset($customer['email']) ? $customer['email'] : '')),
            'address_id' => (int) (isset($address['address_id']) ? $address['address_id'] : 0),
            'is_logged' => true,
            'phone2' => '',
            'egn' => '',
        );
    }

    /**
     * @param list<array<string, mixed>> $addresses
     * @param int $preferredId
     * @return array<string, mixed>
     */
    private function selectAddress(array $addresses, $preferredId)
    {
        if ($preferredId > 0) {
            foreach ($addresses as $address) {
                if ((int) (isset($address['address_id']) ? $address['address_id'] : 0) === $preferredId) {
                    return $address;
                }
            }
        }

        return isset($addresses[0]) && is_array($addresses[0]) ? $addresses[0] : array();
    }

    /**
     * @param array<string, mixed> $address
     * @return string
     */
    private function joinAddress(array $address)
    {
        $parts = array();
        foreach (array('address_1', 'address_2', 'city', 'postcode') as $field) {
            $value = trim((string) (isset($address[$field]) ? $address[$field] : ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return substr(implode(', ', $parts), 0, 256);
    }
}
