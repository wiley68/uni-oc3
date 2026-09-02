<?php

final class MtUniCreditCartSchemeResolver
{
    /** @var MtUniCreditCalculator */
    private $calculator;

    public function __construct(MtUniCreditCalculator $calculator)
    {
        $this->calculator = $calculator;
    }

    /**
     * @param array<string, mixed> $shop
     * @param MtUniCreditCartContext $cart
     * @return MtUniCreditCartResolution
     */
    public function resolve(array $shop, MtUniCreditCartContext $cart)
    {
        if ($cart->lines === array() || !$this->calculator->isAvailableForAmount($shop, $cart->total)) {
            return new MtUniCreditCartResolution(array(), array(), null, null);
        }

        $standardSets = array();
        $promoSets = array();
        foreach ($cart->lines as $line) {
            $product = clone $line->product;
            $product->price = $cart->total;
            $standard = $this->calculator->availableSchemes($shop, $product, 'standard');
            $promo = $this->calculator->availableSchemes($shop, $product, 'promo');
            if ((int) (isset($shop['uni_typekop']) ? $shop['uni_typekop'] : -1) === 1) {
                $standard = array_merge($standard, $promo);
            }
            $standardSets[] = $standard;
            $promoSets[] = $promo;
        }

        $standard = $this->intersect($standardSets, $shop);
        $promo = $this->intersect($promoSets, $shop);
        $preferredMonths = (int) (isset($shop['uni_shema_current']) ? $shop['uni_shema_current'] : 0);

        return new MtUniCreditCartResolution(
            $standard,
            $promo,
            $this->preferred($standard, $shop, $cart->total, 'standard', $preferredMonths, $standardSets),
            $this->preferred($promo, $shop, $cart->total, 'promo', $preferredMonths, $promoSets)
        );
    }

    /**
     * @param MtUniCreditCartResolution $resolution
     * @param array<string, mixed> $shop
     * @return MtUniCreditAvailableScheme[]
     */
    public function unifiedSchemes(MtUniCreditCartResolution $resolution, array $shop = array())
    {
        $schemes = array();
        $seen = array();
        foreach ($resolution->standardSchemes as $scheme) {
            if ($scheme->firstInstallmentAmbiguous) {
                continue;
            }
            $schemes[] = $scheme;
            $seen[$this->key($scheme)] = true;
        }
        foreach ($resolution->promoSchemes as $scheme) {
            if ($scheme->firstInstallmentAmbiguous || isset($seen[$this->key($scheme)])) {
                continue;
            }
            $schemes[] = $scheme;
            $seen[$this->key($scheme)] = true;
        }

        return MtUniCreditSchemePresentationOrder::sort($schemes, $shop);
    }

    /**
     * @param array<int, MtUniCreditAvailableScheme[]> $sets
     * @param array<string, mixed> $shop
     * @return MtUniCreditAvailableScheme[]
     */
    public function intersect(array $sets, array $shop = array())
    {
        if ($sets === array()) {
            return array();
        }
        $common = array_values($sets[0]);
        foreach ($sets as $set) {
            $keys = array();
            foreach ($set as $scheme) {
                $keys[$this->key($scheme)] = true;
            }
            $common = array_values(array_filter(
                $common,
                function (MtUniCreditAvailableScheme $scheme) use ($keys) {
                    return isset($keys[$scheme->identityKey()]);
                }
            ));
            if ($common === array()) {
                return array();
            }
        }

        $existing = array();
        foreach ($common as $scheme) {
            $existing[$this->key($scheme)] = true;
        }
        foreach ($this->groups($sets) as $group) {
            $lineMonthSets = array();
            foreach ($sets as $set) {
                $months = array();
                foreach ($set as $scheme) {
                    if ($scheme->type === $group['type'] && $scheme->kopCode === $group['kop']) {
                        $months[] = $scheme->months;
                    }
                }
                if ($months === array()) {
                    continue 2;
                }
                $lineMonthSets[] = $months;
            }
            $lineLcms = array_map(array($this, 'lcm'), $lineMonthSets);
            $target = $this->lcm($lineLcms);
            $key = $group['type'] . '|' . $group['kop'] . '|' . $target;
            if ($target <= 0 || isset($existing[$key])) {
                continue;
            }
            $template = null;
            foreach ($sets as $set) {
                $found = null;
                foreach ($set as $scheme) {
                    if ($this->key($scheme) === $key) {
                        $found = $scheme;
                        break;
                    }
                }
                if ($found === null) {
                    continue 2;
                }
                $template = $found;
            }
            if ($template !== null) {
                $common[] = $template;
                $existing[$key] = true;
            }
        }

        $self = $this;
        $common = array_map(
            function (MtUniCreditAvailableScheme $scheme) use ($self, $sets) {
                return $self->reconcileCommonScheme($scheme, $sets);
            },
            $common
        );

        return MtUniCreditSchemePresentationOrder::sort($common, $shop);
    }

    /**
     * @param MtUniCreditAvailableScheme $seed
     * @param array<int, MtUniCreditAvailableScheme[]> $sets
     * @return MtUniCreditAvailableScheme
     */
    private function reconcileCommonScheme(MtUniCreditAvailableScheme $seed, array $sets)
    {
        $contributors = array();
        $key = $this->key($seed);
        foreach ($sets as $set) {
            foreach ($set as $candidate) {
                if ($this->key($candidate) === $key) {
                    $contributors[] = $candidate;
                }
            }
        }
        if ($contributors === array()) {
            return $seed;
        }

        $policies = array();
        foreach ($contributors as $candidate) {
            $policies[] = is_array($candidate->filter) && (int) (isset($candidate->filter['uni_parva']) ? $candidate->filter['uni_parva'] : 0) === 1 ? 1 : 0;
        }
        if (count(array_values(array_unique($policies))) > 1) {
            return new MtUniCreditAvailableScheme(
                $seed->type,
                $seed->kopCode,
                $seed->months,
                0,
                null,
                $seed->coefficient,
                true
            );
        }

        usort($contributors, function (MtUniCreditAvailableScheme $left, MtUniCreditAvailableScheme $right) {
            if ($left->filterId === $right->filterId) {
                return 0;
            }

            return $left->filterId < $right->filterId ? -1 : 1;
        });
        $chosen = $contributors[0];

        return new MtUniCreditAvailableScheme(
            $chosen->type,
            $chosen->kopCode,
            $chosen->months,
            $chosen->filterId,
            $chosen->filter,
            $chosen->coefficient,
            false
        );
    }

    /**
     * @param MtUniCreditAvailableScheme[] $schemes
     * @param array<string, mixed> $shop
     * @param float $total
     * @param string $buttonType
     * @param int $preferredMonths
     * @param array<int, MtUniCreditAvailableScheme[]> $lineSets
     * @return MtUniCreditOffer|null
     */
    private function preferred(
        array $schemes,
        array $shop,
        $total,
        $buttonType,
        $preferredMonths,
        array $lineSets
    ) {
        $offers = array();
        foreach ($schemes as $scheme) {
            if ($buttonType === 'promo' && abs((float) (isset($scheme->coefficient['interestPercent']) ? $scheme->coefficient['interestPercent'] : -1)) > 0.00001) {
                continue;
            }
            if (
                $buttonType === 'standard'
                && MtUniCreditSchemePresentationCategory::classify($scheme, $shop) === MtUniCreditSchemePresentationCategory::ZERO_PROMO
            ) {
                continue;
            }
            if ($scheme->firstInstallmentAmbiguous || $this->hasConflictingAutomaticFirstInstallment($scheme, $lineSets)) {
                continue;
            }
            try {
                $calculation = $this->calculator->calculateScheme($shop, $total, $scheme);
            } catch (MtUniCreditUnavailableSchemeException $e) {
                continue;
            }
            $offer = $this->calculator->createButtonOffer($scheme, $calculation->financedAmount, $buttonType);
            if ($offer !== null) {
                $offers[] = $offer;
            }
        }

        return $this->calculator->selectPreferredOffer($offers, $preferredMonths);
    }

    /**
     * @param MtUniCreditAvailableScheme $scheme
     * @param array<int, MtUniCreditAvailableScheme[]> $lineSets
     * @return bool
     */
    private function hasConflictingAutomaticFirstInstallment(MtUniCreditAvailableScheme $scheme, array $lineSets)
    {
        if ($lineSets === array()) {
            return false;
        }

        $policies = array();
        $key = $this->key($scheme);
        foreach ($lineSets as $set) {
            $linePolicies = array();
            foreach ($set as $candidate) {
                if ($this->key($candidate) !== $key) {
                    continue;
                }
                $linePolicies[] = is_array($candidate->filter) && (int) (isset($candidate->filter['uni_parva']) ? $candidate->filter['uni_parva'] : 0) === 1 ? 1 : 0;
            }
            if ($linePolicies === array()) {
                continue;
            }
            $unique = array_values(array_unique($linePolicies));
            if (count($unique) !== 1) {
                return true;
            }
            $policies[] = $unique[0];
        }

        return count(array_unique($policies)) > 1;
    }

    /**
     * @param MtUniCreditAvailableScheme $scheme
     * @return string
     */
    private function key(MtUniCreditAvailableScheme $scheme)
    {
        return $scheme->identityKey();
    }

    /**
     * @param array<int, MtUniCreditAvailableScheme[]> $sets
     * @return array<int, array{type:string,kop:string}>
     */
    private function groups(array $sets)
    {
        $groups = array();
        foreach ($sets as $set) {
            foreach ($set as $scheme) {
                $groupKey = $scheme->type . '|' . $scheme->kopCode;
                $groups[$groupKey] = array('type' => $scheme->type, 'kop' => $scheme->kopCode);
            }
        }

        return array_values($groups);
    }

    /**
     * @param int[] $values
     * @return int
     */
    public function lcm(array $values)
    {
        $values = array_values(array_filter(array_map('abs', $values)));
        if ($values === array()) {
            return 0;
        }
        $result = (int) $values[0];
        foreach (array_slice($values, 1) as $value) {
            $result = (int) (($result / $this->gcd($result, (int) $value)) * $value);
        }

        return $result;
    }

    /**
     * @param int $a
     * @param int $b
     * @return int
     */
    private function gcd($a, $b)
    {
        while ($b !== 0) {
            $temp = $b;
            $b = $a % $b;
            $a = $temp;
        }

        return max(1, abs($a));
    }
}
