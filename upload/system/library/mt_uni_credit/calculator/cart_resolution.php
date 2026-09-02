<?php

final class MtUniCreditCartResolution
{
    /** @var MtUniCreditAvailableScheme[] */
    public $standardSchemes;

    /** @var MtUniCreditAvailableScheme[] */
    public $promoSchemes;

    /** @var MtUniCreditOffer|null */
    public $standardOffer;

    /** @var MtUniCreditOffer|null */
    public $promoOffer;

    /**
     * @param MtUniCreditAvailableScheme[] $standardSchemes
     * @param MtUniCreditAvailableScheme[] $promoSchemes
     * @param MtUniCreditOffer|null $standardOffer
     * @param MtUniCreditOffer|null $promoOffer
     */
    public function __construct(
        array $standardSchemes,
        array $promoSchemes,
        $standardOffer,
        $promoOffer
    ) {
        $this->standardSchemes = array_values($standardSchemes);
        $this->promoSchemes = array_values($promoSchemes);
        $this->standardOffer = $standardOffer;
        $this->promoOffer = $promoOffer;
    }
}
