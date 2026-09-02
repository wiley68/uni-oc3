<?php

final class MtUniCreditFirstInstallmentState
{
    /** @var float */
    public $amount;

    /** @var bool */
    public $locked;

    /** @var bool */
    public $visible;

    public function __construct(float $amount, bool $locked, bool $visible)
    {
        $this->amount = $amount;
        $this->locked = $locked;
        $this->visible = $visible;
    }
}
