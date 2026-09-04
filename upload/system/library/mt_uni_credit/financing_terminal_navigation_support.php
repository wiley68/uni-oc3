<?php

/**
 * Process 2 (and similar shop-terminal) Thank You navigation for Product/Cart/Checkout.
 * Process 1 bank redirect is never rewritten here.
 */
final class MtUniCreditFinancingTerminalNavigationSupport
{
    const SESSION_SUCCESS_ORDER_ID = 'mt_uni_credit_success_order_id';

    /**
     * After Process 2 success: stash order ownership for checkout/success and force Thank You URL.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $sessionData
     * @param int $orderId
     * @param string $thankYouUrl
     * @return array<string, mixed>
     */
    public static function enrichProcess2ThankYou(array $payload, array &$sessionData, $orderId, $thankYouUrl)
    {
        $orderId = (int) $orderId;
        $thankYouUrl = trim((string) $thankYouUrl);
        if ($orderId <= 0 || $thankYouUrl === '') {
            return $payload;
        }
        if (empty($payload['success'])) {
            return $payload;
        }
        // Process 1 SmartUCF bank redirect must stay intact.
        if (!empty($payload['bank_redirect']) && !empty($payload['redirect'])) {
            return $payload;
        }

        $sessionData['order_id'] = $orderId;
        $sessionData[self::SESSION_SUCCESS_ORDER_ID] = $orderId;
        $payload['redirect'] = $thankYouUrl;
        $payload['bank_redirect'] = false;

        return $payload;
    }
}
