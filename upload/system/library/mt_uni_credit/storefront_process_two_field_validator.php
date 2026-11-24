<?php

/**
 * Primary-phone format shared by storefront applicants.
 */
final class MtUniCreditStorefrontProcessTwoFieldValidator
{
    /**
     * @param string $value
     * @return bool
     */
    public function isValidPhone($value)
    {
        return (bool) preg_match('/^[-0-9+() ]+$/', $value) && (bool) preg_match('/\d/', $value);
    }
}
