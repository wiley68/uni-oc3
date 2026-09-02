<?php

/** Platform-neutral product financing context (Phase 5 domain). */
final class MtUniCreditProductContext
{
    /** @var int */
    public $productId;

    /** @var int[] */
    public $categoryIds;

    /** @var float */
    public $price;

    /**
     * @param int $productId
     * @param int[] $categoryIds
     * @param float $price
     */
    public function __construct($productId, array $categoryIds, $price)
    {
        $this->productId = (int) $productId;
        $this->categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));
        $this->price = (float) $price;
    }
}
