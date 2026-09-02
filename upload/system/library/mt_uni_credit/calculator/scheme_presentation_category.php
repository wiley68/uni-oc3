<?php

/**
 * Presentation-only scheme category. Does not change MtUniCreditAvailableScheme::type or identity.
 *
 * Canonical list order (Product / Cart / Checkout) — see MtUniCreditSchemePresentationOrder:
 * 1. months ASC
 * 2. business type rank — standard(0) BEFORE promo-like(1)
 * 3. presentation category rank — standard → nonzero_promo → zero_promo
 * 4. filterId ASC, kopCode, type, scheme key (stable)
 */
final class MtUniCreditSchemePresentationCategory
{
    const STANDARD = 'standard';

    const NONZERO_PROMO = 'nonzero_promo';

    const ZERO_PROMO = 'zero_promo';

    /**
     * @param MtUniCreditAvailableScheme $scheme
     * @param array<string, mixed> $shop
     * @return string
     */
    public static function classify(MtUniCreditAvailableScheme $scheme, array $shop)
    {
        $zeroInterest = self::isZeroInterest($scheme);
        $inPromoFlow = $scheme->type === 'promo'
            || (is_array($scheme->filter) && (int) (isset($scheme->filter['uni_promo']) ? $scheme->filter['uni_promo'] : 0) === 1);

        if ($inPromoFlow && $zeroInterest) {
            return self::ZERO_PROMO;
        }

        $defaultKop = self::defaultKop($shop);
        if ($defaultKop !== '' && $scheme->kopCode === $defaultKop) {
            return self::STANDARD;
        }

        if ($defaultKop !== '' && $scheme->kopCode !== $defaultKop && !$zeroInterest) {
            return self::NONZERO_PROMO;
        }

        // No reliable baseline: promotional description marks overlay schemes.
        if ($defaultKop === '' && self::hasPromotionalDescription($scheme, $shop) && !$zeroInterest) {
            return self::NONZERO_PROMO;
        }

        return self::STANDARD;
    }

    /**
     * @param string $category
     * @return int
     */
    public static function rank($category)
    {
        switch ($category) {
            case self::STANDARD:
                return 0;
            case self::NONZERO_PROMO:
                return 1;
            case self::ZERO_PROMO:
                return 2;
            default:
                return 99;
        }
    }

    /**
     * Explicit business bucket for MtUniCreditAvailableScheme::type: standard=0, promo=1.
     * Overlay schemes that remain type=standard are ordered via presentation rank.
     *
     * @param MtUniCreditAvailableScheme $scheme
     * @param array<string, mixed> $shop unused; kept for call-site uniformity
     * @return int
     */
    public static function typeRank(MtUniCreditAvailableScheme $scheme, array $shop = array())
    {
        unset($shop);

        return $scheme->type === 'promo' ? 1 : 0;
    }

    /**
     * @param MtUniCreditAvailableScheme $left
     * @param MtUniCreditAvailableScheme $right
     * @param array<string, mixed> $shop
     * @return int
     */
    public static function compare(MtUniCreditAvailableScheme $left, MtUniCreditAvailableScheme $right, array $shop)
    {
        if ($left->months !== $right->months) {
            return $left->months <=> $right->months;
        }

        $leftType = self::typeRank($left, $shop);
        $rightType = self::typeRank($right, $shop);
        if ($leftType !== $rightType) {
            return $leftType <=> $rightType;
        }

        $leftRank = self::rank(self::classify($left, $shop));
        $rightRank = self::rank(self::classify($right, $shop));
        if ($leftRank !== $rightRank) {
            return $leftRank <=> $rightRank;
        }

        if ($left->filterId !== $right->filterId) {
            return $left->filterId <=> $right->filterId;
        }

        $kop = strcmp($left->kopCode, $right->kopCode);
        if ($kop !== 0) {
            return $kop;
        }

        $type = strcmp($left->type, $right->type);
        if ($type !== 0) {
            return $type;
        }

        return strcmp(MtUniCreditProductSchemeList::key($left), MtUniCreditProductSchemeList::key($right));
    }

    /**
     * @param MtUniCreditAvailableScheme[] $schemes
     * @param array<string, mixed> $shop
     * @return MtUniCreditAvailableScheme[]
     */
    public static function sort(array $schemes, array $shop)
    {
        $sorted = array_values($schemes);
        usort(
            $sorted,
            function (MtUniCreditAvailableScheme $left, MtUniCreditAvailableScheme $right) use ($shop) {
                return self::compare($left, $right, $shop);
            }
        );

        return $sorted;
    }

    /**
     * @param MtUniCreditAvailableScheme $scheme
     * @return bool
     */
    public static function isZeroInterest(MtUniCreditAvailableScheme $scheme)
    {
        return array_key_exists('interestPercent', $scheme->coefficient)
            && abs((float) $scheme->coefficient['interestPercent']) <= 0.00001;
    }

    /**
     * @param MtUniCreditAvailableScheme $scheme
     * @param array<string, mixed> $shop
     * @return string
     */
    public static function presentationLabel(MtUniCreditAvailableScheme $scheme, array $shop)
    {
        return $scheme->months . ':' . self::classify($scheme, $shop);
    }

    /**
     * @param array<string, mixed> $shop
     * @return string
     */
    public static function defaultKop(array $shop)
    {
        $byDefault = is_array(isset($shop['kop']['by_default']) ? $shop['kop']['by_default'] : null) ? $shop['kop']['by_default'] : array();
        $configured = trim((string) (isset($byDefault['uni_kop_default']) ? $byDefault['uni_kop_default'] : ''));
        if ($configured !== '') {
            return $configured;
        }

        return self::inferBaselineKop($shop);
    }

    /**
     * Schema-mode shops often leave kop.by_default.uni_kop_default empty.
     * Prefer the broadest non-promo filter as the baseline KOP (PS parity intent).
     *
     * @param array<string, mixed> $shop
     * @return string
     */
    private static function inferBaselineKop(array $shop)
    {
        $filters = isset($shop['kop']['by_schema']['filters']) ? $shop['kop']['by_schema']['filters'] : array();
        if (!is_array($filters)) {
            return '';
        }

        $bestKop = '';
        $bestScore = -1;
        $bestFilterId = PHP_INT_MAX;
        foreach ($filters as $filter) {
            if (!is_array($filter) || (int) (isset($filter['uni_promo']) ? $filter['uni_promo'] : 0) === 1) {
                continue;
            }
            $kop = trim((string) (isset($filter['uni_kop']) ? $filter['uni_kop'] : ''));
            if ($kop === '') {
                continue;
            }
            $score = 0;
            if (self::isBlankFilterScope(isset($filter['product_id']) ? $filter['product_id'] : null)) {
                $score += 4;
            }
            if (self::isBlankFilterScope(isset($filter['category_id']) ? $filter['category_id'] : null)) {
                $score += 4;
            }
            if (self::isBlankFilterScope(isset($filter['uni_meseci']) ? $filter['uni_meseci'] : null)) {
                $score += 4;
            }
            $filterId = (int) (isset($filter['id']) ? $filter['id'] : 0);
            if ($score > $bestScore || ($score === $bestScore && $filterId < $bestFilterId)) {
                $bestScore = $score;
                $bestFilterId = $filterId;
                $bestKop = $kop;
            }
        }

        return $bestKop;
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private static function isBlankFilterScope($value)
    {
        return $value === null || $value === '' || $value === false;
    }

    /**
     * @param MtUniCreditAvailableScheme $scheme
     * @param array<string, mixed> $shop
     * @return bool
     */
    private static function hasPromotionalDescription(MtUniCreditAvailableScheme $scheme, array $shop)
    {
        return MtUniCreditProductSchemeList::description($shop, $scheme) !== '';
    }
}
