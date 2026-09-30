<?php

final class MtUniCreditCurrencyGate
{
    /**
     * @param array<string, mixed> $shop
     * @param string $currencyIso
     * @return bool
     */
    public function supports(array $shop, $currencyIso)
    {
        return MtUniCreditEurAmount::isEur($currencyIso);
    }
}
