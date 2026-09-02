<?php

/**
 * Resolves store-scoped UniCredit order ownership without cross-store fallback.
 */
final class MtUniCreditOrderOwnershipResolver
{
    /** @var MtUniCreditDbAdapter */
    private $db;

    /**
     * @param MtUniCreditDbAdapter $db
     */
    public function __construct(MtUniCreditDbAdapter $db)
    {
        $this->db = $db;
    }

    /**
     * @param int $storeId
     * @param string $orderReference
     * @return int|null
     */
    public function resolveAuthorizedOrderId($storeId, $orderReference)
    {
        MtUniCreditStoreScope::requireStoreId($storeId);
        $orderReference = trim($orderReference);
        if ($orderReference === '' || !ctype_digit($orderReference)) {
            return null;
        }

        $orderId = (int) $orderReference;
        if ($orderId <= 0) {
            return null;
        }

        $orderTable = $this->db->getPrefix() . 'order';
        $result = $this->db->query(
            "SELECT `order_id`, `store_id`, `payment_code`, `payment_method`"
                . " FROM `{$orderTable}`"
                . " WHERE `order_id` = " . (int) $orderId
                . " LIMIT 1"
        );

        if (!is_object($result) || !isset($result->num_rows) || (int) $result->num_rows !== 1) {
            return null;
        }

        $row = $result->row;
        if ((int) (isset($row['store_id']) ? $row['store_id'] : -1) !== (int) $storeId) {
            return null;
        }

        $paymentCode = isset($row['payment_code']) ? (string) $row['payment_code'] : '';
        if ($paymentCode === MtUniCreditConstants::EXTENSION_CODE) {
            return $orderId;
        }

        $paymentMethod = isset($row['payment_method']) ? $row['payment_method'] : '';
        if (MtUniCreditPaymentIdentity::matchesStoredPayment($paymentMethod)) {
            return $orderId;
        }

        return null;
    }
}
