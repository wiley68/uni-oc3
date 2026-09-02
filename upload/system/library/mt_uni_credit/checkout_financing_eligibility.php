<?php

/** Whether UniCredit may appear as a checkout payment method. */
final class MtUniCreditCheckoutFinancingEligibility
{
    /** @var MtUniCreditCalculator */
    private $calculator;

    /** @var MtUniCreditCartSchemeResolver */
    private $resolver;

    /** @var MtUniCreditCurrencyGate */
    private $currencyGate;

    /**
     * @param MtUniCreditCalculator|null $calculator
     * @param MtUniCreditCartSchemeResolver|null $resolver
     * @param MtUniCreditCurrencyGate|null $currencyGate
     */
    public function __construct($calculator = null, $resolver = null, $currencyGate = null)
    {
        $this->calculator = $calculator instanceof MtUniCreditCalculator ? $calculator : new MtUniCreditCalculator();
        $this->resolver = $resolver instanceof MtUniCreditCartSchemeResolver
            ? $resolver
            : new MtUniCreditCartSchemeResolver($this->calculator);
        $this->currencyGate = $currencyGate instanceof MtUniCreditCurrencyGate
            ? $currencyGate
            : new MtUniCreditCurrencyGate();
    }

    /**
     * @param array<string, mixed> $shop
     * @param MtUniCreditCartContext $cart
     * @param string $currencyCode
     * @param bool $moduleEnabled
     * @param bool $paymentEnabled
     * @return bool
     */
    public function isEligible(array $shop, MtUniCreditCartContext $cart, $currencyCode, $moduleEnabled, $paymentEnabled)
    {
        if (!$moduleEnabled || !$paymentEnabled) {
            return false;
        }
        if (!$this->currencyGate->supports($shop, $currencyCode)) {
            return false;
        }
        if ($cart->lines === array() || $cart->total <= 0.0) {
            return false;
        }
        if (!$this->calculator->isAvailableForAmount($shop, $cart->total)) {
            return false;
        }

        $resolution = $this->resolver->resolve($shop, $cart);

        return $resolution->standardOffer !== null || $resolution->promoOffer !== null;
    }
}
