<?php

/**
 * Process 2 leasing notifications (admin may receive EGN; customer never does).
 */
interface MtUniCreditProcessTwoMailPort
{
    /**
     * Resolve durable delivery targets for the current shop/order context.
     *
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $orderContext
     * @return array<int, array{audience: string, email: string, recipient_key: string}>
     */
    public function resolveProcess2Recipients(array $shop, array $orderContext);

    /**
     * Send one recipient notification. Audience controls EGN/phone2 policy.
     *
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $orderContext
     * @param MtUniCreditProcessTwoSensitiveData|null $sensitive
     * @param string $audience admin|customer
     * @param string $email
     * @return bool
     */
    public function sendProcess2Recipient(array $shop, array $orderContext, $sensitive, $audience, $email);

    /**
     * Aggregate helper (tests / legacy). Production lifecycle uses per-recipient API.
     *
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $orderContext
     * @param MtUniCreditProcessTwoSensitiveData|null $sensitive
     * @return bool true when required audience sends succeeded (or none configured)
     */
    public function sendProcess2Notifications(array $shop, array $orderContext, $sensitive);
}
