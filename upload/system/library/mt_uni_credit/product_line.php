<?php

/**
 * Authoritative product financing line for calculator and order materialization.
 */
final class MtUniCreditProductLine
{
    /** @var int */
    public $productId;

    /** @var string */
    public $name;

    /** @var string */
    public $model;

    /** @var int[] */
    public $categories;

    /** @var int */
    public $quantity;

    /** @var float */
    public $unitExTax;

    /** @var float */
    public $unitWithTax;

    /** @var float */
    public $financingPrice;

    /** @var int */
    public $taxClassId;

    /** @var array<int, array<string, mixed>> */
    public $options;

    /** @var int */
    public $reward;

    /**
     * @param int $productId
     * @param string $name
     * @param string $model
     * @param int[] $categories
     * @param int $quantity
     * @param float $unitExTax
     * @param float $unitWithTax
     * @param float $financingPrice
     * @param int $taxClassId
     * @param array<int, array<string, mixed>> $options
     * @param int $reward
     */
    public function __construct(
        $productId,
        $name,
        $model,
        array $categories,
        $quantity,
        $unitExTax,
        $unitWithTax,
        $financingPrice,
        $taxClassId,
        array $options = array(),
        $reward = 0
    ) {
        $this->productId = (int) $productId;
        $this->name = (string) $name;
        $this->model = (string) $model;
        $this->categories = array_values(array_unique(array_map('intval', $categories)));
        $this->quantity = max(1, (int) $quantity);
        $this->unitExTax = (float) $unitExTax;
        $this->unitWithTax = (float) $unitWithTax;
        $this->financingPrice = (float) $financingPrice;
        $this->taxClassId = (int) $taxClassId;
        $this->options = array_values($options);
        $this->reward = (int) $reward;
    }

    /**
     * @return MtUniCreditProductContext
     */
    public function toProductContext()
    {
        return new MtUniCreditProductContext($this->productId, $this->categories, $this->financingPrice);
    }
}
