<?php

/**
 * Process 2 leasing notifications (admin may receive EGN; customer never does).
 */
interface MtUniCreditProcessTwoMailPort
{
    /**
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $orderContext
     * @param MtUniCreditProcessTwoSensitiveData|null $sensitive
     * @return bool true when required audience sends succeeded (or none configured)
     */
    public function sendProcess2Notifications(array $shop, array $orderContext, $sensitive);
}
