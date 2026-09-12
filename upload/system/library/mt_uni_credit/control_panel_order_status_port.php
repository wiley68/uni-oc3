<?php

/**
 * CP bank-status update surface used after SmartUCF Process 1 / Process 2 outcomes.
 */
interface MtUniCreditControlPanelOrderStatusPort
{
    /**
     * @param string $shopOrderId
     * @param string $statusLabel
     * @param string $statusId
     * @return array<string, mixed>
     */
    public function updateOrderStatus($shopOrderId, $statusLabel, $statusId);
}
