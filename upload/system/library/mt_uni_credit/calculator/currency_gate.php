<?php

final class MtUniCreditCurrencyGate
{
    /** Frozen BGN/EUR display conversion rate (CONTRACTS.md CALC/currency). */
    const DISPLAY_RATE = 1.95583;

    /**
     * @param array<string, mixed> $shop
     * @param string $currencyIso
     * @return bool
     */
    public function supports(array $shop, $currencyIso)
    {
        $iso = strtoupper(trim($currencyIso));
        $expected = in_array((int) (isset($shop['uni_eur']) ? $shop['uni_eur'] : 0), array(2, 3), true) ? 'EUR' : 'BGN';

        return in_array($iso, array('BGN', 'EUR'), true) && $iso === $expected;
    }

    /**
     * @param array<string, mixed> $shop
     * @return string
     */
    public function expectedIso(array $shop)
    {
        return in_array((int) (isset($shop['uni_eur']) ? $shop['uni_eur'] : 0), array(2, 3), true) ? 'EUR' : 'BGN';
    }
}
