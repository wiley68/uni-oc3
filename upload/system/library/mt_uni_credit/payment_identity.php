<?php

/**
 * UniCredit payment method identity checks for store-scoped order authorization.
 */
final class MtUniCreditPaymentIdentity
{
    /**
     * @param mixed $storedPayment
     * @return bool
     */
    public static function matchesStoredPayment($storedPayment)
    {
        if (is_array($storedPayment)) {
            $code = isset($storedPayment['code']) ? (string) $storedPayment['code'] : '';

            return $code === MtUniCreditConstants::EXTENSION_CODE;
        }

        if (!is_string($storedPayment)) {
            return false;
        }

        $storedPayment = trim($storedPayment);
        if ($storedPayment === MtUniCreditConstants::EXTENSION_CODE) {
            return true;
        }

        $decoded = json_decode($storedPayment, true);
        if (is_array($decoded) && isset($decoded['code'])) {
            return (string) $decoded['code'] === MtUniCreditConstants::EXTENSION_CODE;
        }

        return stripos($storedPayment, MtUniCreditConstants::EXTENSION_CODE) !== false;
    }
}
