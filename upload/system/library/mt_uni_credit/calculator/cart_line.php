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
     * Raw OC3 cart/order option rows used for selection identity.
     *
     * @var array<int, array<string, mixed>>
     */
    public $options = array();

    /**
     * @param MtUniCreditProductContext $product
     * @param int $productAttributeId
     * @param int $quantity
     * @param float $lineTotal
     * @param list<int> $optionValueIds
     * @param array<int, array<string, mixed>> $options
     */
    public function __construct(
        MtUniCreditProductContext $product,
        $productAttributeId,
        $quantity,
        $lineTotal,
        array $optionValueIds = array(),
        array $options = array()
    ) {
        $this->product = $product;
        $this->productAttributeId = max(0, (int) $productAttributeId);
        $this->quantity = max(1, (int) $quantity);
        $this->lineTotal = round((float) $lineTotal, 2);
        $this->options = array_values($options);

        if ($this->options !== array()) {
            $ids = array();
            foreach ($this->options as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $optionValueId = (int) (isset($option['product_option_value_id']) ? $option['product_option_value_id'] : 0);
                if ($optionValueId > 0) {
                    $ids[] = $optionValueId;
                }
            }
            $ids = array_values(array_unique($ids));
            sort($ids);
            $this->optionValueIds = $ids;
        } else {
            $ids = array_values(array_unique(array_map('intval', $optionValueIds)));
            sort($ids);
            $this->optionValueIds = $ids;
        }
    }

    /**
     * @return list<int>
     */
    public function optionValueIds()
    {
        return $this->optionValueIds;
    }
}
