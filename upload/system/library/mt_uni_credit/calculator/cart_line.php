<?php

final class MtUniCreditCartLine
{
    /** @var MtUniCreditProductContext */
    public $product;

    /** @var int */
    public $productAttributeId;

    /** @var int */
    public $quantity;

    /** @var float */
    public $lineTotal;

    /** @var list<int> Canonical product_option_value_id list for fingerprinting. */
    public $optionValueIds = array();

    /**
     * @param MtUniCreditProductContext $product
     * @param int $productAttributeId
     * @param int $quantity
     * @param float $lineTotal
     * @param list<int> $optionValueIds
     */
    public function __construct(
        MtUniCreditProductContext $product,
        $productAttributeId,
        $quantity,
        $lineTotal,
        array $optionValueIds = array()
    ) {
        $this->product = $product;
        $this->productAttributeId = max(0, (int) $productAttributeId);
        $this->quantity = max(1, (int) $quantity);
        $this->lineTotal = round((float) $lineTotal, 2);
        $ids = array_values(array_unique(array_map('intval', $optionValueIds)));
        sort($ids);
        $this->optionValueIds = $ids;
    }

    /**
     * @return list<int>
     */
    public function optionValueIds()
    {
        return $this->optionValueIds;
    }
}
