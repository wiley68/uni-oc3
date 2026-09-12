<?php

/**
 * Authorizes inbound CP order references against financing attempts (store-scoped).
 *
 * Fail-closed cardinality on (store_id, order_id, unicid): 0 → null; 1 → attempt; 2+ → ambiguous.
 * Wrong UNICID for the same store/order → null (not found), never a cross-shop disclosure.
 * No payment-method fallback.
 *
 * Boundary: order_id must already be a canonical string (max 13). No int/float coercion.
 */
final class MtUniCreditFinancingOrderResolver
{
    /** @var MtUniCreditDbAdapter */
    private $db;

    /** @var MtUniCreditPersistenceClock */
    private $clock;

    /**
     * @param MtUniCreditDbAdapter $db
     * @param MtUniCreditPersistenceClock|null $clock
     */
    public function __construct(MtUniCreditDbAdapter $db, $clock = null)
    {
        $this->db = $db;
        $this->clock = $clock instanceof MtUniCreditPersistenceClock
            ? $clock
            : new MtUniCreditPersistenceClock();
    }

    /**
     * @param int $storeId
     * @param string $unicid
     * @param string $orderIdString Canonical shop order id string (not int/float)
     * @return array{attempt: array<string, mixed>, order_id: string}|null
     */
    public function resolve($storeId, $unicid, $orderIdString)
    {
        MtUniCreditStoreScope::requireStoreId($storeId);
        if (!is_string($unicid)) {
            return null;
        }
        $unicid = trim($unicid);

        $canonicalOrderId = MtUniCreditShopOrderId::tryNormalizeStrictString($orderIdString);
        if ($unicid === '' || $canonicalOrderId === null) {
            return null;
        }

        $table = $this->db->getPrefix() . MtUniCreditPersistenceTableNames::FINANCING_ATTEMPT;
        $result = $this->db->query(
            "SELECT *
             FROM `{$table}`
             WHERE `store_id` = " . (int) $storeId . "
               AND `order_id` = " . MtUniCreditShopOrderId::sqlQuoted($this->db, $canonicalOrderId) . "
               AND `unicid` = '" . $this->db->escape($unicid) . "'"
        );

        if (!is_object($result) || (int) $result->num_rows === 0) {
            return null;
        }

        if ((int) $result->num_rows > 1) {
            throw new MtUniCreditFinancingOrderAmbiguousException(
                'Multiple financing attempts match this store order id.'
            );
        }

        /** @var array<string, mixed> $row */
        $row = $result->row;

        return array(
            'attempt' => $row,
            'order_id' => $canonicalOrderId,
        );
    }
}
