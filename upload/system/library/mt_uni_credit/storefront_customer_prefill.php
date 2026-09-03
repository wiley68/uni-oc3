<?php

/**
 * Product/Cart popup customer prefill (OC4 ProductPopupCustomerPrefill + OC3 address-book rules).
 *
 * Address selection (persistent customer address book only — not checkout session):
 * 1. Valid customer.address_id that exists in the customer's book.
 * 2. Else exactly one book address → that address (covers address_id=0 / stale default).
 * 3. Else multiple addresses with no valid default → empty (no arbitrary first-row pick).
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

        $book = $this->normalizeAddressBook($addresses);
        $address = $this->selectAddress($book, (int) $defaultAddressId);
        // Phone: customer telephone only when usable. Never invent from address/metadata.
        $telephone = '';
        if (isset($customer['telephone'])) {
            $telephone = trim((string) $customer['telephone']);
        }

        return array(
            // Applicant identity must come from the logged customer only.
            // Address-book recipient names must not override applicant names.
            'firstname' => trim((string) (isset($customer['firstname']) ? $customer['firstname'] : '')),
            'lastname' => trim((string) (isset($customer['lastname']) ? $customer['lastname'] : '')),
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
     * @param list<array<string, mixed>>|array<int|string, array<string, mixed>> $addresses
     * @return list<array<string, mixed>>
     */
    private function normalizeAddressBook($addresses)
    {
        $book = array();
        if (!is_array($addresses)) {
            return $book;
        }
        foreach ($addresses as $address) {
            if (!is_array($address)) {
                continue;
            }
            $id = (int) (isset($address['address_id']) ? $address['address_id'] : 0);
            if ($id <= 0) {
                continue;
            }
            $book[] = $address;
        }

        return $book;
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

        // No valid default (zero / stale / missing): single-address fallback only.
        if (count($addresses) === 1) {
            return $addresses[0];
        }

        // Multiple addresses without a valid default — do not guess.
        return array();
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
