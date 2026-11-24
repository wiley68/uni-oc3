<?php

/**
 * Localized Product/Cart applicant validation copy (BG/EN language keys).
 */
final class MtUniCreditStorefrontValidationCopy
{
    /**
     * Messages for MtUniCreditStorefrontApplicantFieldValidator.
     *
     * @param object $language OpenCart language loader with get($key)
     * @return array<string, string>
     */
    public static function applicantMessages($language)
    {
        return array(
            'required' => (string) $language->get('error_field_required'),
            'name_length' => (string) $language->get('error_name_length'),
            'address_length' => (string) $language->get('error_address_length'),
            'phone_length' => (string) $language->get('error_phone_length'),
            'phone_invalid' => (string) $language->get('error_phone_invalid'),
            'email_invalid' => (string) $language->get('error_email_invalid'),
            'email_length' => (string) $language->get('error_email_length'),
            'invalid_encoding' => (string) $language->get('error_invalid_characters'),
        );
    }

    /**
     * Client bootstrap i18n bag (JSON-safe strings).
     *
     * @param object $language OpenCart language loader with get($key)
     * @return array<string, string>
     */
    public static function bootstrapI18n($language)
    {
        return array(
            'error_field_required' => (string) $language->get('error_field_required'),
            'error_phone_invalid' => (string) $language->get('error_phone_invalid'),
            'error_email_invalid' => (string) $language->get('error_email_invalid'),
            'error_validation_incomplete' => (string) $language->get('error_validation_incomplete'),
            'error_request_failed' => (string) $language->get('error_request_failed'),
            'error_consent_unknown' => (string) $language->get('error_consent_unknown'),
            'error_consent_refresh' => (string) $language->get('error_consent_refresh'),
            'error_recalculate' => (string) $language->get('error_recalculate'),
            'error_validation' => (string) $language->get('error_validation'),
            'error_required_options' => (string) $language->get('error_required_options'),
            'error_invalid_option' => (string) $language->get('error_invalid_option'),
            'error_quantity_minimum' => (string) $language->get('error_quantity_minimum'),
            'error_buy_cart_failed' => (string) $language->get('error_buy_cart_failed'),
        );
    }
}
