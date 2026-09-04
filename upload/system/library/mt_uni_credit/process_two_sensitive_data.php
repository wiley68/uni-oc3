<?php

/**
 * Process 2 supplemental fields — EGN + phone2 only.
 */
final class MtUniCreditProcessTwoSensitiveData
{
    /** @var string */
    public $egn;

    /** @var string */
    public $phone2;

    /**
     * @param string $egn
     * @param string $phone2
     */
    public function __construct($egn, $phone2)
    {
        $this->egn = (string) $egn;
        $this->phone2 = (string) $phone2;
    }

    /**
     * @return array{egn: string, phone2: string}
     */
    public function toArray()
    {
        return array(
            'egn' => $this->egn,
            'phone2' => $this->phone2,
        );
    }
}
