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
        return self::keyFromParts($scheme->type, $scheme->kopCode, $scheme->months, $scheme->filterId);
    }

    /**
     * @param string $type
     * @param string $kopCode
     * @param int $months
     * @param int $filterId
     * @return string
     */
    public static function keyFromParts($type, $kopCode, $months, $filterId)
    {
        return implode('|', array(
            $type,
            rawurlencode($kopCode),
            (string) $months,
            (string) $filterId,
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
     * @param MtUniCreditAvailableScheme[] $schemes
     * @param string $kopCode
     * @param int $months
     * @param int $filterId
     * @return MtUniCreditAvailableScheme|null
     */
    public static function find(array $schemes, $kopCode, $months, $filterId)
    {
        foreach ($schemes as $scheme) {
            if ($scheme->kopCode === $kopCode && $scheme->months === $months && $scheme->filterId === $filterId) {
                return $scheme;
            }
        }

        return null;
    }
}
