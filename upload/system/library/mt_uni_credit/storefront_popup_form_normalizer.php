<?php

/**
 * Maps Product/Cart Step 2 POST fields to OC3 order draft shape (OC4 ProductPopupFormNormalizer).
 *
 * The popup collects a single free-text address. Structured locality components
 * (city/postcode/country/zone) are not invented from merchant/store defaults.
 */
final class MtUniCreditStorefrontPopupFormNormalizer
{
    /**
     * @param array<string, mixed> $posted
     * @param array<string, mixed> $storeDefaults Unused for applicant locality (kept for call-site BC)
     * @return array<string, mixed>
     */
    public function normalize(array $posted, array $storeDefaults = array())
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

        // Intentionally do not copy city/postcode/country/zone from $storeDefaults.
        // Unknown structured components remain absent/empty for order-draft neutrality.
        unset($storeDefaults);

        return $normalized;
    }
}
