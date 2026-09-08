<?php

final class MtUniCreditProductSchemeList
{
    /** @var MtUniCreditCalculator */
    private $calculator;

    public function __construct(MtUniCreditCalculator $calculator)
    {
        $this->calculator = $calculator;
    }

    /**
     * @param array<string, mixed> $shop
     * @param MtUniCreditProductContext $product
     * @param string $popupType
     * @return MtUniCreditAvailableScheme[]
     */
    public function schemes(array $shop, MtUniCreditProductContext $product, $popupType)
    {
        if ($popupType === 'promo') {
            return MtUniCreditSchemePresentationOrder::sort(
                $this->calculator->availableSchemes($shop, $product, 'promo'),
                $shop
            );
        }
        if ($popupType !== 'standard') {
            return array();
        }

        return MtUniCreditSchemePresentationOrder::sort(array_merge(
            $this->calculator->availableSchemes($shop, $product, 'standard'),
            $this->calculator->availableSchemes($shop, $product, 'promo')
        ), $shop);
    }

    /**
     * @param MtUniCreditAvailableScheme $scheme
     * @return string
     */
    public static function key(MtUniCreditAvailableScheme $scheme)
    {
        return self::keyFromParts($scheme->type, $scheme->kopCode, $scheme->months);
    }

    /**
     * Public/domain selection identity (AUD-016 F01): type|urlencoded(kopCode)|months.
     *
     * @param string $type
     * @param string $kopCode
     * @param int $months
     * @return string
     */
    public static function keyFromParts($type, $kopCode, $months)
    {
        return implode('|', array(
            (string) $type,
            rawurlencode((string) $kopCode),
            (string) (int) $months,
        ));
    }

    /**
     * @param array<string, mixed> $shop
     * @param MtUniCreditAvailableScheme $scheme
     * @return string
     */
    public static function description(array $shop, MtUniCreditAvailableScheme $scheme)
    {
        if (is_array($scheme->filter)) {
            return trim((string) (isset($scheme->filter['uni_kop_desc']) ? $scheme->filter['uni_kop_desc'] : ''));
        }
        $settings = is_array(isset($shop['kop']['by_default']) ? $shop['kop']['by_default'] : null) ? $shop['kop']['by_default'] : array();

        return trim((string) (isset($settings[$scheme->type === 'promo' ? 'uni_kop_promo_desc' : 'uni_kop_default_desc']) ? $settings[$scheme->type === 'promo' ? 'uni_kop_promo_desc' : 'uni_kop_default_desc'] : ''));
    }

    /**
     * Exact kop+months identity; lowest filterId when duplicates share identity.
     *
     * @param MtUniCreditAvailableScheme[] $schemes
     * @param string $kopCode
     * @param int $months
     * @return MtUniCreditAvailableScheme|null
     */
    public static function find(array $schemes, $kopCode, $months)
    {
        $match = null;
        foreach ($schemes as $scheme) {
            if ($scheme->kopCode === $kopCode && $scheme->months === $months) {
                if ($match === null || $scheme->filterId < $match->filterId) {
                    $match = $scheme;
                }
            }
        }

        return $match;
    }
}
