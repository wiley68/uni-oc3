<?php

/**
 * Checkout order.total vs live cart amount for session.order_id parity.
 *
 * OpenCart cart->getTotal() is merchandise + product tax only. Native confirm writes
 * order.total via the checkout totals pipeline (sub_total + shipping + tax + extensions).
 */
final class MtUniCreditCheckoutLiveGrandTotal
{
    /**
     * @param callable $getTotals callable(array &$totals, array &$taxes, float &$total): void
     * @param array<int, float> $taxes
     * @return float
     */
    public static function compute($getTotals, array $taxes)
    {
        $totals = array();
        $total = 0.0;
        call_user_func_array($getTotals, array(&$totals, &$taxes, &$total));

        return round((float) $total, 2);
    }
}
