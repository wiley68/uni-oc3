<?php

/**
 * Fixture-driven golden parity runner for calculator_golden.json.
 */
final class Phase3GoldenRunner
{
    /** @var array<string, mixed> */
    private $golden;

    /** @var MtUniCreditCalculator */
    private $calculator;

    /** @var string[] */
    private $executedIds = array();

    /** @var string[] */
    private $failures = array();

    /** @var int */
    private $passes = 0;

    /** @var array<string, string> */
    private $caseResults = array();

    /**
     * @param array<string, mixed> $golden
     * @param MtUniCreditCalculator $calculator
     */
    public function __construct(array $golden, MtUniCreditCalculator $calculator)
    {
        $this->golden = $golden;
        $this->calculator = $calculator;
    }

    /**
     * @return array{executed:string[],failures:string[],passes:int,case_results:array<string,string>,fixture_count:int}
     */
    public function runAll()
    {
        if (!isset($this->golden['cases']) || !is_array($this->golden['cases'])) {
            $this->fail('[GOLDEN] fixture missing cases array');
            return $this->result();
        }

        foreach ($this->golden['cases'] as $case) {
            if (!is_array($case) || !isset($case['id']) || !is_string($case['id']) || $case['id'] === '') {
                $this->fail('[GOLDEN] fixture case missing id');
                continue;
            }

            $caseId = $case['id'];
            $this->executedIds[] = $caseId;
            $caseFailed = false;

            try {
                $this->runCase($case);
            } catch (Throwable $exception) {
                $caseFailed = true;
                $this->fail('[GOLDEN ' . $caseId . '] exception: ' . $exception->getMessage());
            }

            $this->caseResults[$caseId] = $caseFailed || $this->caseHasFailure($caseId) ? 'FAIL' : 'PASS';
        }

        return $this->result();
    }

    /**
     * @return array{executed:string[],failures:string[],passes:int,case_results:array<string,string>,fixture_count:int}
     */
    public function result()
    {
        return array(
            'executed' => $this->executedIds,
            'failures' => $this->failures,
            'passes' => $this->passes,
            'case_results' => $this->caseResults,
            'fixture_count' => isset($this->golden['cases']) && is_array($this->golden['cases'])
                ? count($this->golden['cases'])
                : 0,
        );
    }

    /**
     * @param array<string, mixed> $case
     * @return void
     */
    private function runCase(array $case)
    {
        switch ($case['id']) {
            case 'standard_preferred':
                $this->runStandardPreferred($case);
                return;
            case 'promo_0_percent':
                $this->runPromoZeroPercent($case);
                return;
            case 'nonzero_interest_promo_rejected':
                $this->runNonzeroInterestPromoRejected($case);
                return;
            case 'first_installment_locked_uni_parva':
                $this->runFirstInstallmentLocked($case);
                return;
            case 'first_installment_user_requested':
                $this->runFirstInstallmentUserRequested($case);
                return;
            case 'price_boundary_shop':
                $this->runPriceBoundaryShop($case);
                return;
            case 'price_boundary_promo_min':
                $this->runPriceBoundaryPromoMin($case);
                return;
            case 'month_selection':
                $this->runMonthSelection($case);
                return;
            case 'preferred_offer_tie_break':
                $this->runPreferredOfferTieBreak($case);
                return;
            case 'preferred_offer_fallback_highest_months':
                $this->runPreferredOfferFallback($case);
                return;
            case 'scheme_ordering':
                $this->runSchemeOrdering($case);
                return;
            case 'filter_eligibility':
                $this->runFilterEligibility($case);
                return;
            case 'cart_intersection':
                $this->runCartIntersection($case);
                return;
            case 'currency_gate':
                $this->runCurrencyGate($case);
                return;
            default:
                throw new RuntimeException('Unsupported golden case id: ' . $case['id']);
        }
    }

    /**
     * @param array<string, mixed> $case
     * @return void
     */
    private function runStandardPreferred(array $case)
    {
        $expect = $case['expect'];
        $input = $case['input'];
        $shop = mtuc3_golden_shop();
        $product = mtuc3_golden_product(array(
            'productId' => 42,
            'categoryIds' => array(7, 9),
            'price' => (float) $input['price'],
        ));
        $offer = $this->calculator->resolvePreferredOffer($shop, $product, (string) $input['type']);

        $this->assertGolden($case['id'], 'present', !empty($expect['present']), $offer !== null);
        if ($offer === null) {
            return;
        }

        $this->assertGoldenSame($case['id'], 'kop_code', $expect['kop_code'], $offer->kopCode);
        $this->assertGoldenSame($case['id'], 'months', $expect['months'], $offer->months);
        $this->assertGoldenFloat($case['id'], 'monthly_installment', $expect['monthly_installment'], $offer->monthlyInstallment);
        $this->assertGoldenFloat($case['id'], 'coeff', $expect['coeff'], $offer->coefficient, 6);
        $this->assertGoldenFloat($case['id'], 'glp', $expect['glp'], $offer->glp);
        $this->assertGoldenFloat($case['id'], 'gpr_offerFactory', $expect['gpr_offerFactory'], $offer->gpr);
        $this->assertGoldenFloat($case['id'], 'financed_amount', $expect['financed_amount'], $offer->financedAmount);
    }

    /**
     * @param array<string, mixed> $case
     * @return void
     */
    private function runPromoZeroPercent(array $case)
    {
        $expect = $case['expect'];
        $input = $case['input'];
        $shop = mtuc3_golden_shop();
        $product = mtuc3_golden_product(array(
            'productId' => 42,
            'categoryIds' => array(7, 9),
            'price' => (float) $input['price'],
        ));
        $offer = $this->calculator->resolvePreferredOffer($shop, $product, (string) $input['type']);

        $this->assertGolden($case['id'], 'present', !empty($expect['present']), $offer !== null);
        if ($offer === null) {
            return;
        }

        $this->assertGoldenSame($case['id'], 'kop_code', $expect['kop_code'], $offer->kopCode);
        $this->assertGoldenSame($case['id'], 'months', $expect['months'], $offer->months);
        $this->assertGoldenFloat($case['id'], 'monthly_installment', $expect['monthly_installment'], $offer->monthlyInstallment);
        $this->assertGoldenFloat($case['id'], 'coeff', $expect['coeff'], $offer->coefficient, 6);
        $this->assertGoldenFloat($case['id'], 'glp', $expect['glp'], $offer->glp);
        $this->assertGoldenFloat($case['id'], 'gpr_offerFactory', $expect['gpr_offerFactory'], $offer->gpr);

        $calculation = $this->calculator->calculate($shop, $product, (int) $expect['months'], 'promo');
        $this->assertGoldenFloat($case['id'], 'gpr_calculateScheme', $expect['gpr_calculateScheme'], $calculation->gpr);
    }

    /**
     * @param array<string, mixed> $case
     * @return void
     */
    private function runNonzeroInterestPromoRejected(array $case)
    {
        $shop = mtuc3_golden_shop();
        $shop['coeff_list'][3]['interestPercent'] = (float) $case['input']['promo_interestPercent'];
        $offer = $this->calculator->resolvePreferredOffer($shop, mtuc3_golden_product(), 'promo');
        $expectedPresent = !empty($case['expect']['promo_present']);
        $this->assertGolden($case['id'], 'promo_present', $expectedPresent, $offer !== null);
    }

    /**
     * @param array<string, mixed> $case
     * @return void
     */
    private function runFirstInstallmentLocked(array $case)
    {
        $input = $case['input'];
        $expect = $case['expect'];
        $shop = mtuc3_schema_filter_shop();
        $product = mtuc3_golden_product(array(
            'productId' => 42,
            'categoryIds' => array(7, 9),
            'price' => (float) $input['price'],
        ));
        $result = $this->calculator->calculate(
            $shop,
            $product,
            (int) $input['months'],
            (string) $input['type'],
            (float) $input['requested_first'],
            (int) $input['filter_id']
        );

        $this->assertGoldenFloat($case['id'], 'first_installment', $expect['first_installment'], $result->firstInstallment->amount);
        $this->assertGolden($case['id'], 'locked', !empty($expect['locked']), $result->firstInstallment->locked);
        $this->assertGolden($case['id'], 'visible', !empty($expect['visible']), $result->firstInstallment->visible);
        $this->assertGoldenFloat($case['id'], 'financed_amount', $expect['financed_amount'], $result->financedAmount);
        $this->assertGoldenFloat($case['id'], 'monthly_installment', $expect['monthly_installment'], $result->monthlyInstallment);
        $this->assertGoldenFloat($case['id'], 'total_payable', $expect['total_payable'], $result->totalPayable);
        $this->assertGoldenFloat($case['id'], 'gpr_calculateScheme', $expect['gpr_calculateScheme'], $result->gpr);
    }

    /**
     * @param array<string, mixed> $case
     * @return void
     */
    private function runFirstInstallmentUserRequested(array $case)
    {
        $input = $case['input'];
        $expect = $case['expect'];
        $shop = mtuc3_golden_shop(array('uni_first_vnoska' => 1));
        $product = mtuc3_golden_product(array(
            'productId' => 42,
            'categoryIds' => array(7, 9),
            'price' => (float) $input['price'],
        ));
        $result = $this->calculator->calculate(
            $shop,
            $product,
            (int) $input['months'],
            (string) $input['type'],
            (float) $input['requested_first']
        );

        $this->assertGoldenFloat($case['id'], 'first_installment', $expect['first_installment'], $result->firstInstallment->amount);
        $this->assertGolden($case['id'], 'locked', !empty($expect['locked']), $result->firstInstallment->locked);
        $this->assertGolden($case['id'], 'visible', !empty($expect['visible']), $result->firstInstallment->visible);
        $this->assertGoldenFloat($case['id'], 'financed_amount', $expect['financed_amount'], $result->financedAmount);
        $this->assertGoldenFloat($case['id'], 'monthly_installment', $expect['monthly_installment'], $result->monthlyInstallment);
        $this->assertGoldenFloat($case['id'], 'total_payable', $expect['total_payable'], $result->totalPayable);
    }

    /**
     * @param array<string, mixed> $case
     * @return void
     */
    private function runPriceBoundaryShop(array $case)
    {
        $expect = $case['expect'];
        $shop = mtuc3_golden_shop();
        $months = new MtUniCreditMonthResolver();

        $this->assertGolden(
            $case['id'],
            'inactive_shop',
            empty($expect['inactive_shop']),
            $months->isEnabledFlag(isset($shop['uni_status']) ? $shop['uni_status'] : 0)
        );
        $this->assertGolden(
            $case['id'],
            'price_100_inclusive',
            !empty($expect['price_100_inclusive']),
            $this->calculator->isAvailableForAmount($shop, 100.0)
        );
        $this->assertGolden(
            $case['id'],
            'price_10000_inclusive',
            !empty($expect['price_10000_inclusive']),
            $this->calculator->isAvailableForAmount($shop, 10000.0)
        );
        $this->assertGolden(
            $case['id'],
            'price_10000_01_rejected',
            !empty($expect['price_10000_01_rejected']),
            !$this->calculator->isAvailableForAmount($shop, 10000.01)
        );

        $productOver = mtuc3_golden_product(array(
            'productId' => 42,
            'categoryIds' => array(7, 9),
            'price' => 10000.01,
        ));
        $offersOver = $this->calculator->resolvePreferredOffers($shop, $productOver);
        $this->assertGolden($case['id'], 'over_max_standard_null', true, $offersOver['standard'] === null);
    }

    /**
     * @param array<string, mixed> $case
     * @return void
     */
    private function runPriceBoundaryPromoMin(array $case)
    {
        $expect = $case['expect'];
        $shop = mtuc3_golden_shop();
        $promoPrice = (float) (isset($shop['kop']['by_default']['uni_promo_price']) ? $shop['kop']['by_default']['uni_promo_price'] : 0);

        $this->assertGoldenNumber($case['id'], 'uni_promo_price', $expect['uni_promo_price'], $promoPrice);
        $this->assertGolden(
            $case['id'],
            'price_499_99_promo_null',
            !empty($expect['price_499_99_promo_null']),
            $this->calculator->resolvePreferredOffer(
                $shop,
                mtuc3_golden_product(array('productId' => 42, 'categoryIds' => array(7, 9), 'price' => 499.99)),
                'promo'
            ) === null
        );
        $this->assertGolden(
            $case['id'],
            'price_500_promo_present',
            !empty($expect['price_500_promo_present']),
            $this->calculator->resolvePreferredOffer(
                $shop,
                mtuc3_golden_product(array('productId' => 42, 'categoryIds' => array(7, 9), 'price' => 500.0)),
                'promo'
            ) !== null
        );
    }

    /**
     * @param array<string, mixed> $case
     * @return void
     */
    private function runMonthSelection(array $case)
    {
        $expect = $case['expect'];
        $months = new MtUniCreditMonthResolver();
        $shop = mtuc3_golden_shop();

        $this->assertGoldenSame($case['id'], 'enabled_shop_months', $expect['enabled_shop_months'], $months->enabledMonths($shop));
        $this->assertGoldenSame($case['id'], 'range_min', $expect['range_min'], MtUniCreditMonthResolver::MIN);
        $this->assertGoldenSame($case['id'], 'range_max', $expect['range_max'], MtUniCreditMonthResolver::MAX);

        $greateqShop = mtuc3_golden_shop(array(
            'kop' => array(
                'by_default' => array(
                    'uni_promo_meseci_znak' => 'greateq',
                    'uni_promo_meseci' => '12',
                ),
            ),
        ));
        $this->assertGoldenSame(
            $case['id'],
            'promo_greateq_12',
            $expect['promo_greateq_12'],
            $months->defaultPromoMonths(
                $greateqShop['kop']['by_default'],
                1000.0,
                $months->enabledMonths($greateqShop)
            )
        );

        $fallbackShop = mtuc3_golden_shop(array('uni_shema_current' => 18));
        $promo = $this->calculator->resolvePreferredOffer($fallbackShop, mtuc3_golden_product(), 'promo');
        $this->assertGolden($case['id'], 'preferred_18_promo_present', true, $promo !== null);
        if ($promo !== null) {
            $this->assertGoldenSame(
                $case['id'],
                'preferred_18_missing_falls_back_to_highest_promo_month',
                $expect['preferred_18_missing_falls_back_to_highest_promo_month'],
                $promo->months
            );
        }

        $invalidPreferredShop = mtuc3_golden_shop(array(
            'coeff_list' => array(
                array('onlineProductCode' => 'STD', 'installmentCount' => 12, 'coeff' => 0.095, 'interestPercent' => 18),
                array('onlineProductCode' => 'PROMO', 'installmentCount' => 12, 'coeff' => 0, 'interestPercent' => 0),
                array('onlineProductCode' => 'PROMO', 'installmentCount' => 24, 'coeff' => 0.041667, 'interestPercent' => 0),
            ),
        ));
        $invalidPromo = $this->calculator->resolvePreferredOffer($invalidPreferredShop, mtuc3_golden_product(), 'promo');
        $this->assertGolden(
            $case['id'],
            'invalid_preferred_promo_coeff_does_not_fallback',
            !empty($expect['invalid_preferred_promo_coeff_does_not_fallback']),
            $invalidPromo === null
        );

        if (!empty($expect['disabled_month_12_calculate_throws'])) {
            $disabledShop = mtuc3_golden_shop(array('uni_meseci_12' => 0));
            $threw = false;
            try {
                $this->calculator->calculate($disabledShop, mtuc3_golden_product(), 12, 'standard');
            } catch (MtUniCreditUnavailableSchemeException $exception) {
                $threw = true;
            }
            $this->assertGolden($case['id'], 'disabled_month_12_calculate_throws', true, $threw);
        }
    }

    /**
     * @param array<string, mixed> $case
     * @return void
     */
    private function runPreferredOfferTieBreak(array $case)
    {
        $this->runPreferredOfferSelectorCase($case);
    }

    /**
     * @param array<string, mixed> $case
     * @return void
     */
    private function runPreferredOfferFallback(array $case)
    {
        $this->runPreferredOfferSelectorCase($case);
    }

    /**
     * @param array<string, mixed> $case
     * @return void
     */
    private function runPreferredOfferSelectorCase(array $case)
    {
        $selector = new MtUniCreditPreferredOfferSelector();
        $offers = array();
        foreach ($case['input']['candidates'] as $row) {
            $offers[] = new MtUniCreditOffer(
                (string) $row['type'],
                (string) $row['kop'],
                (int) $row['months'],
                (float) $row['monthly'],
                10.0,
                11.0,
                1000.0,
                0.09
            );
        }
        $picked = $selector->select($offers, (int) $case['input']['preferred_months']);
        $this->assertGolden($case['id'], 'selector_result_present', true, $picked !== null);
        if ($picked === null) {
            return;
        }
        $this->assertGoldenSame($case['id'], 'kop', $case['expect']['kop'], $picked->kopCode);
        $this->assertGoldenSame($case['id'], 'months', $case['expect']['months'], $picked->months);
    }

    /**
     * @param array<string, mixed> $case
     * @return void
     */
    private function runSchemeOrdering(array $case)
    {
        $shop = mtuc3_golden_shop();
        $schemes = array();
        foreach ($case['input'] as $row) {
            $schemes[] = new MtUniCreditAvailableScheme(
                (string) $row['type'],
                (string) $row['kop'],
                (int) $row['months'],
                (int) $row['filterId'],
                array('uni_promo' => (int) $row['uni_promo']),
                array('interestPercent' => (float) $row['interestPercent'], 'coeff' => 0.1)
            );
        }
        $sorted = MtUniCreditSchemePresentationOrder::sort($schemes, $shop);
        $labels = array();
        foreach ($sorted as $scheme) {
            $labels[] = MtUniCreditSchemePresentationCategory::presentationLabel($scheme, $shop);
        }
        $this->assertGoldenSame($case['id'], 'expect_labels', $case['expect_labels'], $labels);
    }

    /**
     * @param array<string, mixed> $case
     * @return void
     */
    private function runFilterEligibility(array $case)
    {
        $expect = $case['expect'];
        $shop = mtuc3_schema_filter_shop();
        $product = mtuc3_golden_product();
        $standards = $this->calculator->availableSchemes($shop, $product, 'standard');
        $promos = $this->calculator->availableSchemes($shop, $product, 'promo');

        $this->assertGoldenSame(
            $case['id'],
            'product_42_cat_7_9_standard_scheme_count',
            $expect['product_42_cat_7_9_standard_scheme_count'],
            count($standards)
        );
        if ($standards !== array()) {
            $this->assertGoldenSame($case['id'], 'first_filter_id', $expect['first_filter_id'], $standards[0]->filterId);
            $this->assertGoldenSame(
                $case['id'],
                'last_filter_id',
                $expect['last_filter_id'],
                $standards[count($standards) - 1]->filterId
            );
        }
        $this->assertGoldenSame($case['id'], 'promo_scheme_count', $expect['promo_scheme_count'], count($promos));
        if ($promos !== array()) {
            $this->assertGoldenSame($case['id'], 'promo_filter_id', $expect['promo_filter_id'], $promos[0]->filterId);
        }

        $bothFilterShop = mtuc3_typekop1_shop(array(array(
            'id' => 99,
            'category_id' => 7,
            'product_id' => 42,
            'uni_meseci' => '12',
            'uni_promo' => 0,
            'uni_kop' => 'CAT',
        )));
        $this->assertGolden(
            $case['id'],
            'category_and_product_together_rejected',
            !empty($expect['category_and_product_together_rejected']),
            $this->calculator->availableSchemes($bothFilterShop, $product, 'standard') === array()
        );
        $this->assertGolden(
            $case['id'],
            'outside_category_empty',
            !empty($expect['outside_category_empty']),
            $this->calculator->availableSchemes($shop, new MtUniCreditProductContext(50, array(8), 1000.0), 'standard') === array()
        );
        $this->assertGolden(
            $case['id'],
            'price_499_99_empty',
            !empty($expect['price_499_99_empty']),
            $this->calculator->availableSchemes($shop, new MtUniCreditProductContext(50, array(7), 499.99), 'standard') === array()
        );
        $this->assertGoldenSame(
            $case['id'],
            'price_500_standard_count',
            $expect['price_500_standard_count'],
            count($this->calculator->availableSchemes($shop, new MtUniCreditProductContext(50, array(7), 500.0), 'standard'))
        );

        $futureCalculator = new MtUniCreditCalculator('2026-09-01');
        $this->assertGolden(
            $case['id'],
            'date_2026_09_01_empty_for_category_only',
            !empty($expect['date_2026_09_01_empty_for_category_only']),
            $futureCalculator->availableSchemes($shop, new MtUniCreditProductContext(50, array(7), 1000.0), 'standard') === array()
        );
    }

    /**
     * @param array<string, mixed> $case
     * @return void
     */
    private function runCartIntersection(array $case)
    {
        $expect = $case['expect'];
        $shop = mtuc3_golden_shop();
        $resolver = new MtUniCreditCartSchemeResolver($this->calculator);

        $single = $resolver->resolve($shop, new MtUniCreditCartContext(array(
            mtuc3_cart_line(1, array(7), 1000.0),
        ), 1000.0));
        $this->assertGoldenSame(
            $case['id'],
            'single_product_standard_count',
            $expect['single_product_standard_count'],
            count($single->standardSchemes)
        );

        $same = $resolver->resolve($shop, new MtUniCreditCartContext(array(
            mtuc3_cart_line(1, array(7), 1000.0, 1, 400.0),
            mtuc3_cart_line(2, array(8), 1000.0, 1, 600.0),
        ), 1000.0));
        $this->assertGoldenSame(
            $case['id'],
            'same_standard_common_count',
            $expect['same_standard_common_count'],
            count($same->standardSchemes)
        );
        $this->assertGolden($case['id'], 'same_standard_offer_present', true, $same->standardOffer !== null);
        if ($same->standardOffer !== null) {
            $this->assertGoldenSame(
                $case['id'],
                'same_standard_preferred_months',
                $expect['same_standard_preferred_months'],
                $same->standardOffer->months
            );
        }
        $this->assertGoldenSame(
            $case['id'],
            'same_promo_common_count',
            $expect['same_promo_common_count'],
            count($same->promoSchemes)
        );
        $this->assertGolden($case['id'], 'same_promo_offer_present', true, $same->promoOffer !== null);

        $this->assertGoldenSame($case['id'], 'lcm_6_12', $expect['lcm_6_12'], $resolver->lcm(array(6, 12)));
        $this->assertGoldenSame($case['id'], 'lcm_6_8', $expect['lcm_6_8'], $resolver->lcm(array(6, 8)));

        $differentKop = mtuc3_typekop1_shop(array(
            array('id' => 1, 'product_id' => 1, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 0, 'uni_kop' => 'CAT'),
            array('id' => 2, 'product_id' => 2, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 0, 'uni_kop' => 'PRODUCT'),
        ));
        $this->assertGolden(
            $case['id'],
            'different_kops_empty',
            !empty($expect['different_kops_empty']),
            $resolver->resolve($differentKop, new MtUniCreditCartContext(array(
                mtuc3_cart_line(1, array(), 1000.0),
                mtuc3_cart_line(2, array(), 1000.0),
            ), 1000.0))->standardSchemes === array()
        );

        $differentMonths = mtuc3_typekop1_shop(array(
            array('id' => 3, 'product_id' => 1, 'category_id' => null, 'uni_meseci' => '6', 'uni_promo' => 0, 'uni_kop' => 'CAT'),
            array('id' => 4, 'product_id' => 2, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 0, 'uni_kop' => 'CAT'),
        ));
        $this->assertGolden(
            $case['id'],
            'different_months_empty',
            !empty($expect['different_months_empty']),
            $resolver->resolve($differentMonths, new MtUniCreditCartContext(array(
                mtuc3_cart_line(1, array(), 1000.0),
                mtuc3_cart_line(2, array(), 1000.0),
            ), 1000.0))->standardSchemes === array()
        );

        $filterIdShop = mtuc3_typekop1_shop(array(
            array('id' => 31, 'product_id' => 1, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 0, 'uni_parva' => 0, 'uni_kop' => 'CAT'),
            array('id' => 32, 'product_id' => 2, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 0, 'uni_parva' => 0, 'uni_kop' => 'CAT'),
        ));
        $filterIdResult = $resolver->resolve($filterIdShop, new MtUniCreditCartContext(array(
            mtuc3_cart_line(1, array(), 1000.0),
            mtuc3_cart_line(2, array(), 1000.0),
        ), 1000.0));
        $this->assertGolden($case['id'], 'filter_id_result_count', true, count($filterIdResult->standardSchemes) === 1);
        if ($filterIdResult->standardSchemes !== array()) {
            $this->assertGoldenSame(
                $case['id'],
                'filter_id_is_metadata_lowest_wins',
                $expect['filter_id_is_metadata_lowest_wins'],
                $filterIdResult->standardSchemes[0]->filterId
            );
        }

        $this->assertGolden(
            $case['id'],
            'cart_total_99_empty',
            !empty($expect['cart_total_99_empty']),
            $resolver->resolve($shop, new MtUniCreditCartContext(array(
                mtuc3_cart_line(1, array(), 99.0),
            ), 99.0))->standardSchemes === array()
        );
        $this->assertGolden(
            $case['id'],
            'cart_total_10000_ok',
            !empty($expect['cart_total_10000_ok']),
            $resolver->resolve($shop, new MtUniCreditCartContext(array(
                mtuc3_cart_line(1, array(), 10000.0),
            ), 10000.0))->standardSchemes !== array()
        );
        $this->assertGolden(
            $case['id'],
            'cart_total_10000_01_empty',
            !empty($expect['cart_total_10000_01_empty']),
            $resolver->resolve($shop, new MtUniCreditCartContext(array(
                mtuc3_cart_line(1, array(), 10000.01),
            ), 10000.01))->standardSchemes === array()
        );

        $promoFilters = mtuc3_typekop1_shop(array(
            array('id' => 51, 'product_id' => 1, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 1, 'uni_kop' => 'ZERO'),
            array('id' => 52, 'product_id' => 2, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 1, 'uni_kop' => 'ZERO'),
        ));
        $this->assertGoldenSame(
            $case['id'],
            'common_zero_promo_count',
            $expect['common_zero_promo_count'],
            count($resolver->resolve($promoFilters, new MtUniCreditCartContext(array(
                mtuc3_cart_line(1, array(), 1000.0),
                mtuc3_cart_line(2, array(), 1000.0),
            ), 1000.0))->promoSchemes)
        );
    }

    /**
     * @param array<string, mixed> $case
     * @return void
     */
    private function runCurrencyGate(array $case)
    {
        $expect = $case['expect'];
        $gate = new MtUniCreditCurrencyGate();

        foreach (array(0, 1) as $mode) {
            $shop = mtuc3_golden_shop(array('uni_eur' => $mode));
            $this->assertGoldenSame(
                $case['id'],
                'uni_eur_' . $mode . '_expected_iso',
                $expect['uni_eur_0_or_1_expected_iso'],
                $gate->expectedIso($shop)
            );
            $this->assertGolden($case['id'], 'uni_eur_' . $mode . '_supports_bgn', true, $gate->supports($shop, 'BGN'));
            $this->assertGolden($case['id'], 'uni_eur_' . $mode . '_rejects_eur', true, !$gate->supports($shop, 'EUR'));
        }

        foreach (array(2, 3) as $mode) {
            $shop = mtuc3_golden_shop(array('uni_eur' => $mode));
            $this->assertGoldenSame(
                $case['id'],
                'uni_eur_' . $mode . '_expected_iso',
                $expect['uni_eur_2_or_3_expected_iso'],
                $gate->expectedIso($shop)
            );
            $this->assertGolden($case['id'], 'uni_eur_' . $mode . '_supports_eur', true, $gate->supports($shop, 'EUR'));
            $this->assertGolden($case['id'], 'uni_eur_' . $mode . '_rejects_bgn', true, !$gate->supports($shop, 'BGN'));
        }

        $this->assertGoldenSame($case['id'], 'supported_iso', $expect['supported_iso'], array('BGN', 'EUR'));
        $this->assertGoldenFloat($case['id'], 'display_rate', $expect['display_rate'], MtUniCreditCurrencyGate::DISPLAY_RATE, 5);
    }

    /**
     * @param string $caseId
     * @return bool
     */
    private function caseHasFailure($caseId)
    {
        foreach ($this->failures as $failure) {
            if (strpos($failure, '[GOLDEN ' . $caseId . ']') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $caseId
     * @param string $field
     * @param mixed $expected
     * @param mixed $actual
     * @return void
     */
    private function assertGolden($caseId, $field, $expected, $actual)
    {
        if ($expected === $actual) {
            $this->passes++;
            return;
        }

        $this->fail('[GOLDEN ' . $caseId . '] ' . $field . ' expected ' . $this->exportValue($expected) . ', actual ' . $this->exportValue($actual));
    }

    /**
     * @param string $caseId
     * @param string $field
     * @param mixed $expected
     * @param mixed $actual
     * @return void
     */
    private function assertGoldenSame($caseId, $field, $expected, $actual)
    {
        if ($expected === $actual) {
            $this->passes++;
            return;
        }

        $this->fail('[GOLDEN ' . $caseId . '] ' . $field . ' expected ' . $this->exportValue($expected) . ', actual ' . $this->exportValue($actual));
    }

    /**
     * @param string $caseId
     * @param string $field
     * @param float|int $expected
     * @param float|int $actual
     * @param int $precision
     * @return void
     */
    private function assertGoldenFloat($caseId, $field, $expected, $actual, $precision = 2)
    {
        $expectedRounded = round((float) $expected, $precision);
        $actualRounded = round((float) $actual, $precision);
        if ($expectedRounded === $actualRounded) {
            $this->passes++;
            return;
        }

        $this->fail(
            '[GOLDEN ' . $caseId . '] ' . $field
                . ' expected ' . $this->exportValue($expectedRounded)
                . ', actual ' . $this->exportValue($actualRounded)
        );
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function exportValue($value)
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }

    /**
     * @param string $caseId
     * @param string $field
     * @param float|int $expected
     * @param float|int $actual
     * @return void
     */
    private function assertGoldenNumber($caseId, $field, $expected, $actual)
    {
        if ((float) $expected === (float) $actual) {
            $this->passes++;
            return;
        }

        $this->fail(
            '[GOLDEN ' . $caseId . '] ' . $field
                . ' expected ' . $this->exportValue($expected)
                . ', actual ' . $this->exportValue($actual)
        );
    }

    /**
     * @param string $message
     * @return void
     */
    private function fail($message)
    {
        $this->failures[] = $message;
    }
}
