<?php

/**
 * Checkout native-order actor ownership (logged-in customer vs guest identity).
 *
 * Authority is OpenCart session/customer + persisted order fields — never posted IDs.
 */
final class MtUniCreditCheckoutOrderActorOwnership
{
    /**
     * @param array<string, mixed> $order Native order row
     * @param array<string, mixed> $actor Keys: customer_id (int), is_guest (bool), guest_email (string)
     * @return string|null Error code when blocked; null when ownership matches
     */
    public static function rejectReason(array $order, array $actor)
    {
        $orderCustomerId = (int) (isset($order['customer_id']) ? $order['customer_id'] : 0);
        $activeCustomerId = (int) (isset($actor['customer_id']) ? $actor['customer_id'] : 0);
        $isGuest = !empty($actor['is_guest']) || $activeCustomerId <= 0;

        if (!$isGuest && $activeCustomerId > 0) {
            if ($orderCustomerId <= 0 || $orderCustomerId !== $activeCustomerId) {
                return 'order_ownership_mismatch';
            }

            return null;
        }

        // Guest actor: order must be guest-owned and email must match session guest identity.
        if ($orderCustomerId !== 0) {
            return 'order_ownership_mismatch';
        }

        $orderEmail = self::normalizeEmail(isset($order['email']) ? $order['email'] : '');
        $guestEmail = self::normalizeEmail(isset($actor['guest_email']) ? $actor['guest_email'] : '');
        if ($orderEmail === '' || $guestEmail === '' || $orderEmail !== $guestEmail) {
            return 'order_ownership_mismatch';
        }

        return null;
    }

    /**
     * @param string $email
     * @return string
     */
    public static function normalizeEmail($email)
    {
        return strtolower(trim((string) $email));
    }
}
