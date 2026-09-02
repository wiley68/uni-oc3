<?php

final class MtUniCreditPreferredOfferSelector
{
    /**
     * @param MtUniCreditOffer[] $offers
     * @param int $preferredMonths
     * @return MtUniCreditOffer|null
     */
    public function select(array $offers, $preferredMonths)
    {
        if ($offers === array()) {
            return null;
        }
        $matches = $preferredMonths > 0
            ? array_values(array_filter(
                $offers,
                function (MtUniCreditOffer $offer) use ($preferredMonths) {
                    return $offer->months === $preferredMonths;
                }
            ))
            : array();
        if ($matches === array()) {
            $highest = max(array_map(
                function (MtUniCreditOffer $offer) {
                    return $offer->months;
                },
                $offers
            ));
            $matches = array_values(array_filter(
                $offers,
                function (MtUniCreditOffer $offer) use ($highest) {
                    return $offer->months === $highest;
                }
            ));
        }
        usort($matches, function (MtUniCreditOffer $a, MtUniCreditOffer $b) {
            if ($a->monthlyInstallment === $b->monthlyInstallment) {
                return 0;
            }

            return $a->monthlyInstallment < $b->monthlyInstallment ? -1 : 1;
        });

        return isset($matches[0]) ? $matches[0] : null;
    }
}
