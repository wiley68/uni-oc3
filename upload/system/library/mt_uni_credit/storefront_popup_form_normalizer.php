<?php

/**
 * Maps Product/Cart Step 2 POST fields to OC3 order draft shape (OC4 ProductPopupFormNormalizer).
 */
final class MtUniCreditStorefrontPopupFormNormalizer
{
    /**
     * @param array<string, mixed> $posted
     * @param array<string, mixed> $storeDefaults country_id, zone_id, country, zone, city, postcode
     * @return array<string, mixed>
     */
    public function normalize(array $posted, array $storeDefaults)
    {
        $normalized = $posted;

        if (isset($posted['first_name']) && !isset($posted['firstname'])) {
            $normalized['firstname'] = $posted['first_name'];
        }
        if (isset($posted['last_name']) && !isset($posted['lastname'])) {
            $normalized['lastname'] = $posted['last_name'];
        }
        if (isset($posted['phone']) && !isset($posted['telephone'])) {
            $normalized['telephone'] = $posted['phone'];
        }

        $addressLine = trim((string) (isset($posted['address']) ? $posted['address'] : ''));
        if ($addressLine !== '' && trim((string) (isset($posted['address_1']) ? $posted['address_1'] : '')) === '') {
            $normalized['address_1'] = $addressLine;
        }

        foreach (array('city', 'postcode', 'country_id', 'zone_id', 'country', 'zone') as $field) {
            $current = trim((string) (isset($normalized[$field]) ? $normalized[$field] : ''));
            if ($current === '' && isset($storeDefaults[$field])) {
                $normalized[$field] = $storeDefaults[$field];
            }
        }

        return $normalized;
    }
}
