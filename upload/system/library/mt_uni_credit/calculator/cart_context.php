<?php

final class MtUniCreditCartContext
{
    /** @var MtUniCreditCartLine[] */
    public $lines;

    /** @var float */
    public $total;

    /** @var array<string, mixed> */
    public $checkoutState;

    /**
     * @param MtUniCreditCartLine[] $lines
     * @param float $total
     * @param array<string, mixed> $checkoutState
     */
    public function __construct(array $lines, $total, array $checkoutState = array())
    {
        $this->lines = array_values($lines);
        $this->total = round((float) $total, 2);
        $this->checkoutState = $checkoutState;
    }
}
