<?php

/**
 * Shared financing domain calculator — product, cart and checkout consume this class.
 */
final class MtUniCreditCalculator
{
    /** @var MtUniCreditMonthResolver */
    private $months;

    /** @var MtUniCreditSchemaFilterMatcher */
    private $matcher;

    /** @var MtUniCreditCoefficientResolver */
    private $coefficients;

    /** @var MtUniCreditOfferFactory */
    private $offers;

    /** @var MtUniCreditPreferredOfferSelector */
    private $selector;

    /** @var MtUniCreditFirstInstallmentResolver */
    private $firstInstallment;

    /** @var MtUniCreditFinancialCalculator */
    private $financial;

    /**
     * @param string|null $today
     */
    public function __construct($today = null)
    {
        $this->months = new MtUniCreditMonthResolver();
        $this->matcher = new MtUniCreditSchemaFilterMatcher($this->months, $today);
        $this->coefficients = new MtUniCreditCoefficientResolver($this->months);
        $this->financial = new MtUniCreditFinancialCalculator();
        $this->offers = new MtUniCreditOfferFactory($this->financial);
        $this->selector = new MtUniCreditPreferredOfferSelector();
        $this->firstInstallment = new MtUniCreditFirstInstallmentResolver($this->months);
    }

    /**
     * @param array<string, mixed> $shop
     * @param float $price
     * @return bool
     */
    public function isAvailableForAmount(array $shop, $price)
    {
        if (!$this->months->isEnabledFlag(isset($shop['uni_status']) ? $shop['uni_status'] : 0)) {
            return false;
        }

        return $price >= (float) (isset($shop['uni_minstojnost']) ? $shop['uni_minstojnost'] : 0)
            && $price <= (float) (isset($shop['uni_maxstojnost']) ? $shop['uni_maxstojnost'] : 0);
    }

    /**
     * @param array<string, mixed> $shop
     * @param MtUniCreditProductContext $product
     * @return array{standard:?MtUniCreditOffer,promo:?MtUniCreditOffer}
     */
    public function resolvePreferredOffers(array $shop, MtUniCreditProductContext $product)
    {
        if (!$this->isAvailableForAmount($shop, $product->price)) {
            return array('standard' => null, 'promo' => null);
        }

        return array(
            'standard' => $this->resolvePreferredOffer($shop, $product, 'standard'),
            'promo' => $this->resolvePreferredOffer($shop, $product, 'promo'),
        );
    }

    /**
     * @param array<string, mixed> $shop
     * @param MtUniCreditProductContext $product
     * @param string $type
     * @return MtUniCreditOffer|null
     */
    public function resolvePreferredOffer(array $shop, MtUniCreditProductContext $product, $type)
    {
        $mode = (int) (isset($shop['uni_typekop']) ? $shop['uni_typekop'] : -1);
        if ($mode === 0 && $type === 'standard') {
            $byDefault = $this->byDefault($shop);
            $kop = trim((string) (isset($byDefault['uni_kop_default']) ? $byDefault['uni_kop_default'] : ''));
            $preferred = (int) (isset($shop['uni_shema_current']) ? $shop['uni_shema_current'] : 0);
            $entry = $this->coefficients->find($this->coefficientList($shop), $kop, $preferred);

            return $entry !== null
                ? $this->offers->create('standard', $kop, $preferred, $product->price, $entry)
                : null;
        }
        if ($mode === 0 && $type === 'promo') {
            $settings = $this->byDefault($shop);
            $kop = trim((string) (isset($settings['uni_kop_promo']) ? $settings['uni_kop_promo'] : ''));
            $allowed = $this->months->defaultPromoMonths(
                $settings,
                $product->price,
                range(MtUniCreditMonthResolver::MIN, MtUniCreditMonthResolver::MAX)
            );
            $entry = $this->coefficients->findPreferredOrHighest(
                $this->coefficientList($shop),
                $kop,
                $allowed,
                (int) (isset($shop['uni_shema_current']) ? $shop['uni_shema_current'] : 0)
            );
            if ($entry === null || !$this->coefficients->isZeroInterest($entry)) {
                return null;
            }

            return $this->offers->create(
                'promo',
                $kop,
                (int) (isset($entry['installmentCount']) ? $entry['installmentCount'] : 0),
                $product->price,
                $entry
            );
        }
        if ($mode !== 1) {
            return null;
        }

        $filters = isset($shop['kop']['by_schema']['filters']) ? $shop['kop']['by_schema']['filters'] : array();
        $candidates = array();
        foreach (is_array($filters) ? $filters : array() as $filter) {
            if (
                !is_array($filter)
                || (int) (isset($filter['uni_promo']) ? $filter['uni_promo'] : 0) !== ($type === 'promo' ? 1 : 0)
                || !$this->matcher->matches($filter, $product)
            ) {
                continue;
            }
            $kop = trim((string) (isset($filter['uni_kop']) ? $filter['uni_kop'] : ''));
            $allowed = $this->months->allowedForFilter($filter, $shop);
            $entry = $this->coefficients->findPreferredOrHighest(
                $this->coefficientList($shop),
                $kop,
                $allowed,
                (int) (isset($shop['uni_shema_current']) ? $shop['uni_shema_current'] : 0)
            );
            if ($kop === '' || $entry === null || ($type === 'promo' && !$this->coefficients->isZeroInterest($entry))) {
                continue;
            }
            $scheme = new MtUniCreditAvailableScheme(
                $type,
                $kop,
                (int) (isset($entry['installmentCount']) ? $entry['installmentCount'] : 0),
                (int) (isset($filter['id']) ? $filter['id'] : 0),
                $filter,
                $entry
            );
            $amount = $product->price;
            if ((int) (isset($filter['uni_parva']) ? $filter['uni_parva'] : 0) === 1) {
                $amount = round($product->price - round($product->price / $scheme->months, 2), 2);
            }
            $offer = $this->offers->create(
                $type,
                $scheme->kopCode,
                $scheme->months,
                $amount,
                $scheme->coefficient,
                $scheme->filterId
            );
            if ($offer !== null) {
                $candidates[] = $offer;
            }
        }

        return $this->selector->select($candidates, (int) (isset($shop['uni_shema_current']) ? $shop['uni_shema_current'] : 0));
    }

    /**
     * @param array<string, mixed> $shop
     * @param MtUniCreditProductContext $product
     * @param string $type
     * @param bool $shopMonthsOnly
     * @return MtUniCreditAvailableScheme[]
     */
    public function availableSchemes(
        array $shop,
        MtUniCreditProductContext $product,
        $type,
        $shopMonthsOnly = true
    ) {
        if (!in_array($type, array('standard', 'promo'), true) || !$this->isAvailableForAmount($shop, $product->price)) {
            return array();
        }
        $mode = (int) (isset($shop['uni_typekop']) ? $shop['uni_typekop'] : -1);

        return $mode === 0
            ? $this->defaultSchemes($shop, $product, $type, $shopMonthsOnly)
            : ($mode === 1 ? $this->schemaSchemes($shop, $product, $type) : array());
    }

    /**
     * @param array<string, mixed> $shop
     * @param MtUniCreditProductContext $product
     * @param int $months
     * @param string $type
     * @param float $requestedFirstInstallment
     * @param int $filterId
     * @return MtUniCreditCalculationResult
     */
    public function calculate(
        array $shop,
        MtUniCreditProductContext $product,
        $months,
        $type,
        $requestedFirstInstallment = 0.0,
        $filterId = 0
    ) {
        $matches = array_values(array_filter(
            $this->availableSchemes($shop, $product, $type),
            function (MtUniCreditAvailableScheme $scheme) use ($months, $filterId) {
                return $scheme->months === $months && ($filterId <= 0 || $scheme->filterId === $filterId);
            }
        ));
        if ($filterId <= 0 && count($matches) > 1) {
            usort($matches, function (MtUniCreditAvailableScheme $a, MtUniCreditAvailableScheme $b) {
                $aParva = (int) (is_array($a->filter) && isset($a->filter['uni_parva']) ? $a->filter['uni_parva'] : 0);
                $bParva = (int) (is_array($b->filter) && isset($b->filter['uni_parva']) ? $b->filter['uni_parva'] : 0);
                if ($aParva === $bParva) {
                    return 0;
                }

                return $bParva <=> $aParva;
            });
        }
        $scheme = isset($matches[0]) ? $matches[0] : null;
        if (!$scheme instanceof MtUniCreditAvailableScheme) {
            throw new MtUniCreditUnavailableSchemeException('The selected financing scheme is not available.');
        }

        return $this->calculateScheme($shop, $product->price, $scheme, $requestedFirstInstallment);
    }

    /**
     * @param array<string, mixed> $shop
     * @param float $price
     * @param MtUniCreditAvailableScheme $scheme
     * @param float $requestedFirstInstallment
     * @return MtUniCreditCalculationResult
     */
    public function calculateScheme(
        array $shop,
        $price,
        MtUniCreditAvailableScheme $scheme,
        $requestedFirstInstallment = 0.0
    ) {
        if ($scheme->firstInstallmentAmbiguous) {
            throw new MtUniCreditUnavailableSchemeException('The selected financing scheme has an ambiguous first-installment policy.');
        }
        $first = $this->firstInstallment->resolve(
            $shop,
            $price,
            $scheme->months,
            $requestedFirstInstallment,
            $scheme->filter
        );
        $financed = round($price - $first->amount, 2);
        $kimb = (float) (isset($scheme->coefficient['coeff']) ? $scheme->coefficient['coeff'] : 0);
        if ($financed <= 0 || $kimb <= 0) {
            throw new MtUniCreditUnavailableSchemeException('The selected financing scheme cannot be calculated.');
        }
        $monthly = round($financed * $kimb, 2);
        $gpr = $this->financial->calculateGpr($scheme->months, $monthly, $financed);

        return new MtUniCreditCalculationResult(
            $scheme,
            round($price, 2),
            $first,
            $financed,
            $monthly,
            round($monthly * $scheme->months, 2),
            round(abs((float) (isset($scheme->coefficient['interestPercent']) ? $scheme->coefficient['interestPercent'] : 0)), 2),
            $gpr <= 0.1 ? 0.0 : round($gpr, 2)
        );
    }

    /**
     * @param MtUniCreditAvailableScheme $scheme
     * @param float $amount
     * @param string $buttonType
     * @return MtUniCreditOffer|null
     */
    public function createButtonOffer(MtUniCreditAvailableScheme $scheme, $amount, $buttonType)
    {
        return $this->offers->create(
            $buttonType,
            $scheme->kopCode,
            $scheme->months,
            $amount,
            $scheme->coefficient,
            $scheme->filterId
        );
    }

    /**
     * @param MtUniCreditOffer[] $offers
     * @param int $preferredMonths
     * @return MtUniCreditOffer|null
     */
    public function selectPreferredOffer(array $offers, $preferredMonths)
    {
        return $this->selector->select($offers, $preferredMonths);
    }

    /**
     * @param array<string, mixed> $shop
     * @param MtUniCreditProductContext $product
     * @param string $type
     * @param bool $shopMonthsOnly
     * @return MtUniCreditAvailableScheme[]
     */
    private function defaultSchemes(array $shop, MtUniCreditProductContext $product, $type, $shopMonthsOnly)
    {
        $settings = $this->byDefault($shop);
        $kop = trim((string) (isset($settings[$type === 'promo' ? 'uni_kop_promo' : 'uni_kop_default']) ? $settings[$type === 'promo' ? 'uni_kop_promo' : 'uni_kop_default'] : ''));
        if ($kop === '') {
            return array();
        }
        $candidateMonths = $shopMonthsOnly
            ? $this->months->enabledMonths($shop)
            : range(MtUniCreditMonthResolver::MIN, MtUniCreditMonthResolver::MAX);
        if ($type === 'promo') {
            $candidateMonths = $this->months->defaultPromoMonths($settings, $product->price, $candidateMonths);
        }
        $result = array();
        foreach ($candidateMonths as $months) {
            $entry = $this->coefficients->find($this->coefficientList($shop), $kop, $months);
            if ($entry === null || ($type === 'promo' && !$this->coefficients->isZeroInterest($entry))) {
                continue;
            }
            $result[] = new MtUniCreditAvailableScheme($type, $kop, $months, 0, null, $entry);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $shop
     * @param MtUniCreditProductContext $product
     * @param string $type
     * @return MtUniCreditAvailableScheme[]
     */
    private function schemaSchemes(array $shop, MtUniCreditProductContext $product, $type)
    {
        $filters = isset($shop['kop']['by_schema']['filters']) ? $shop['kop']['by_schema']['filters'] : array();
        if (!is_array($filters)) {
            return array();
        }
        $result = array();
        foreach ($filters as $filter) {
            if (
                !is_array($filter)
                || (int) (isset($filter['uni_promo']) ? $filter['uni_promo'] : 0) !== ($type === 'promo' ? 1 : 0)
                || !$this->matcher->matches($filter, $product)
            ) {
                continue;
            }
            $kop = trim((string) (isset($filter['uni_kop']) ? $filter['uni_kop'] : ''));
            if ($kop === '') {
                continue;
            }
            foreach ($this->months->allowedForFilter($filter, $shop) as $months) {
                $entry = $this->coefficients->find($this->coefficientList($shop), $kop, $months);
                if ($entry === null || ($type === 'promo' && !$this->coefficients->isZeroInterest($entry))) {
                    continue;
                }
                $result[] = new MtUniCreditAvailableScheme(
                    $type,
                    $kop,
                    $months,
                    (int) (isset($filter['id']) ? $filter['id'] : 0),
                    $filter,
                    $entry
                );
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $shop
     * @return array<string, mixed>
     */
    private function byDefault(array $shop)
    {
        return is_array(isset($shop['kop']['by_default']) ? $shop['kop']['by_default'] : null) ? $shop['kop']['by_default'] : array();
    }

    /**
     * @param array<string, mixed> $shop
     * @return array<int, mixed>
     */
    private function coefficientList(array $shop)
    {
        return is_array(isset($shop['coeff_list']) ? $shop['coeff_list'] : null) ? $shop['coeff_list'] : array();
    }
}
