<?php

/**
 * Localized Product/Cart applicant + Process 2 validation copy (BG/EN language keys).
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
     * Messages for MtUniCreditStorefrontProcessTwoFieldValidator.
     *
     * @param object $language OpenCart language loader with get($key)
     * @return array<string, string>
     */
    public static function processTwoMessages($language)
    {
        return array(
            'egn_required' => (string) $language->get('error_field_required'),
            'egn_invalid' => (string) $language->get('error_egn_invalid'),
            'phone2_required' => (string) $language->get('error_field_required'),
            'phone2_invalid' => (string) $language->get('error_phone2_invalid'),
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
            'error_egn_invalid' => (string) $language->get('error_egn_invalid'),
            'error_phone2_invalid' => (string) $language->get('error_phone2_invalid'),
            'error_validation_incomplete' => (string) $language->get('error_validation_incomplete'),
            'error_request_failed' => (string) $language->get('error_request_failed'),
            'error_recalculate' => (string) $language->get('error_recalculate'),
            'error_validation' => (string) $language->get('error_validation'),
        );
    }
}
