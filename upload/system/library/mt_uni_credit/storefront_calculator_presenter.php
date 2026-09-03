<?php

/**
 * Customer-facing Product/Cart calculator presenter (fresh shop cache only).
 *
 * Scheme keys carry selection identity; customer labels avoid raw KOP internals.
 */
final class MtUniCreditStorefrontCalculatorPresenter
{
    /** @var MtUniCreditCalculator */
    private $calculator;

    /** @var MtUniCreditCartSchemeResolver */
    private $cartSchemes;

    /** @var MtUniCreditCurrencyGate */
    private $currencyGate;

    /**
     * @param MtUniCreditCalculator|null $calculator
     * @param MtUniCreditCartSchemeResolver|null $cartSchemes
     * @param MtUniCreditCurrencyGate|null $currencyGate
     */
    public function __construct($calculator = null, $cartSchemes = null, $currencyGate = null)
    {
        $this->calculator = $calculator instanceof MtUniCreditCalculator
            ? $calculator
            : new MtUniCreditCalculator();
        $this->cartSchemes = $cartSchemes instanceof MtUniCreditCartSchemeResolver
            ? $cartSchemes
            : new MtUniCreditCartSchemeResolver($this->calculator);
        $this->currencyGate = $currencyGate instanceof MtUniCreditCurrencyGate
            ? $currencyGate
            : new MtUniCreditCurrencyGate();
    }

    /**
     * @param string $type
     * @param string $kopCode
     * @param int $months
     * @param int $filterId
     * @return string
     */
    public static function schemeKey($type, $kopCode, $months, $filterId)
    {
        return implode('|', array(
            (string) $type,
            rawurlencode((string) $kopCode),
            (string) (int) $months,
            (string) (int) $filterId,
        ));
    }

    /**
     * @param MtUniCreditAvailableScheme $scheme
     * @return string
     */
    public static function keyForScheme(MtUniCreditAvailableScheme $scheme)
    {
        return self::schemeKey($scheme->type, $scheme->kopCode, $scheme->months, $scheme->filterId);
    }

    /**
     * @param string $schemeKey
     * @return array{type:string,kop_code:string,months:int,filter_id:int}|null
     */
    public static function parseSchemeKey($schemeKey)
    {
        $parts = explode('|', (string) $schemeKey);
        if (count($parts) !== 4) {
            return null;
        }

        return array(
            'type' => (string) $parts[0],
            'kop_code' => rawurldecode((string) $parts[1]),
            'months' => (int) $parts[2],
            'filter_id' => (int) $parts[3],
        );
    }

    /**
     * @param array<string, mixed> $shop
     * @param MtUniCreditProductContext|MtUniCreditProductLine $productOrLine
     * @param string $currency
     * @param array<string, mixed>|null $offers Unused reserved (preferred offers computed here)
     * @return array<string, mixed>|null
     */
    public function presentProduct(array $shop, $productOrLine, $currency, $offers = null)
    {
        unset($offers);
        if (!$this->currencyGate->supports($shop, $currency)) {
            return null;
        }

        if ($productOrLine instanceof MtUniCreditProductLine) {
            $product = $productOrLine->toProductContext();
            $price = $productOrLine->financingPrice;
            $productId = $productOrLine->productId;
        } elseif ($productOrLine instanceof MtUniCreditProductContext) {
            $product = $productOrLine;
            $price = $productOrLine->price;
            $productId = $productOrLine->productId;
        } else {
            return null;
        }

        if (!$this->calculator->isAvailableForAmount($shop, $price)) {
            return null;
        }

        $preferred = $this->calculator->resolvePreferredOffers($shop, $product);
        $presented = array();
        foreach (array('standard', 'promo') as $type) {
            if (!isset($preferred[$type]) || !$preferred[$type] instanceof MtUniCreditOffer) {
                continue;
            }
            $block = $this->presentOfferBlock($shop, $product, $price, $type, $preferred[$type], false);
            if ($block !== null) {
                $presented[$type] = $block;
            }
        }

        if ($presented === array()) {
            return null;
        }

        return $this->wrapPayload($shop, $productId, $price, $currency, $presented, null, false);
    }

    /**
     * @param array<string, mixed> $shop
     * @param MtUniCreditCartContext $cart
     * @param MtUniCreditCartResolution|null $resolution
     * @param string $currency
     * @param string|null $fingerprint
     * @return array<string, mixed>|null
     */
    public function presentCart(array $shop, MtUniCreditCartContext $cart, $resolution, $currency, $fingerprint = null)
    {
        if (!$this->currencyGate->supports($shop, $currency) || $cart->lines === array()) {
            return null;
        }
        if (!$this->calculator->isAvailableForAmount($shop, $cart->total)) {
            return null;
        }

        if (!$resolution instanceof MtUniCreditCartResolution) {
            $resolution = $this->cartSchemes->resolve($shop, $cart);
        }

        $presented = array();
        foreach (array('standard' => $resolution->standardOffer, 'promo' => $resolution->promoOffer) as $type => $preferred) {
            if (!$preferred instanceof MtUniCreditOffer) {
                continue;
            }
            $block = $this->presentOfferBlock(
                $shop,
                null,
                $cart->total,
                $type,
                $preferred,
                true,
                $resolution
            );
            if ($block !== null) {
                $presented[$type] = $block;
            }
        }

        if ($presented === array()) {
            return null;
        }

        if ($fingerprint === null || $fingerprint === '') {
            $fingerprint = MtUniCreditStorefrontOperationIdentity::cartFingerprintFromContext($cart, $currency);
        }

        return $this->wrapPayload($shop, 0, $cart->total, $currency, $presented, $fingerprint, true);
    }

    /**
     * @param array<string, mixed> $shop
     * @param MtUniCreditProductContext|null $product
     * @param float $price
     * @param string $type
     * @param MtUniCreditOffer $preferred
     * @param bool $isCart
     * @param MtUniCreditCartResolution|null $resolution
     * @return array<string, mixed>|null
     */
    private function presentOfferBlock(
        array $shop,
        $product,
        $price,
        $type,
        MtUniCreditOffer $preferred,
        $isCart,
        $resolution = null
    ) {
        $pool = array();
        if ($isCart && $resolution instanceof MtUniCreditCartResolution) {
            $pool = $type === 'promo'
                ? $resolution->promoSchemes
                : $this->cartSchemes->unifiedSchemes($resolution, $shop);
        } elseif ($product instanceof MtUniCreditProductContext) {
            if ($type === 'promo') {
                $pool = $this->calculator->availableSchemes($shop, $product, 'promo');
            } else {
                $pool = array_merge(
                    $this->calculator->availableSchemes($shop, $product, 'standard'),
                    $this->calculator->availableSchemes($shop, $product, 'promo')
                );
            }
            $pool = MtUniCreditSchemePresentationOrder::sort($pool, $shop);
        }

        $schemes = array();
        $seen = array();
        foreach ($pool as $scheme) {
            if (!$scheme instanceof MtUniCreditAvailableScheme || $scheme->firstInstallmentAmbiguous) {
                continue;
            }
            $key = self::keyForScheme($scheme);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            try {
                $result = $this->calculator->calculateScheme($shop, $price, $scheme, 0.0);
            } catch (Exception $exception) {
                continue;
            }
            $schemes[] = array(
                'key' => $key,
                'months' => $scheme->months,
                'monthly' => $result->monthlyInstallment,
                'monthly_installment' => $result->monthlyInstallment,
                'financed' => $result->financedAmount,
                'financed_amount' => $result->financedAmount,
                'total' => $result->totalPayable,
                'total_payable' => $result->totalPayable,
                'glp' => $result->glp,
                'gpr' => $result->gpr,
                'first_installment' => $result->firstInstallment->amount,
                'first_installment_locked' => !empty($result->firstInstallment->locked),
            );
        }

        if ($schemes === array()) {
            return null;
        }

        return array(
            'type' => $type,
            'months' => $preferred->months,
            'preferred_scheme_key' => self::schemeKey(
                $preferred->type,
                $preferred->kopCode,
                $preferred->months,
                $preferred->filterId
            ),
            'monthly_installment' => $preferred->monthlyInstallment,
            'installment_label' => $this->formatInstallmentLabel(
                $preferred->months,
                $preferred->monthlyInstallment,
                $shop
            ),
            'schemes' => $schemes,
        );
    }

    /**
     * @param array<string, mixed> $shop
     * @param int $productId
     * @param float $price
     * @param string $currency
     * @param array<string, mixed> $offers
     * @param string|null $fingerprint
     * @param bool $hideSecondary
     * @return array<string, mixed>
     */
    private function wrapPayload(array $shop, $productId, $price, $currency, array $offers, $fingerprint, $hideSecondary)
    {
        $payload = array(
            'product_id' => (int) $productId,
            'price' => (float) $price,
            'currency_iso' => strtoupper(trim((string) $currency)),
            'show_installment' => $this->flag(isset($shop['uni_vnoska']) ? $shop['uni_vnoska'] : 0),
            'button_type' => $this->flag(isset($shop['uni_vnoska']) ? $shop['uni_vnoska'] : 0) ? 'standard' : 'image',
            'show_first_installment' => $this->flag(isset($shop['uni_first_vnoska']) ? $shop['uni_first_vnoska'] : 0),
            'dark_button' => $this->flag(isset($shop['uni_type_button']) ? $shop['uni_type_button'] : 0),
            'design' => $this->flag(isset($shop['uni_type_button']) ? $shop['uni_type_button'] : 0) ? 'alternative' : 'standard',
            'buttons_in_row' => (int) (isset($shop['uni_button_row']) ? $shop['uni_button_row'] : 1) === 1,
            'button_width' => $this->dimension(isset($shop['uni_button_width']) ? $shop['uni_button_width'] : 290, 290, 100, 600),
            'button_height' => $this->dimension(isset($shop['uni_button_height']) ? $shop['uni_button_height'] : 56, 56, 30, 120),
            'heading' => trim((string) (isset($shop['uni_zaglavie']) ? $shop['uni_zaglavie'] : '')),
            'offers' => $offers,
        );

        if ($fingerprint !== null) {
            $payload['cart_fingerprint'] = (string) $fingerprint;
            $payload['source'] = 'cart';
        }
        if ($hideSecondary) {
            $payload['hide_secondary'] = true;
        }

        return $payload;
    }

    /**
     * @param int $months
     * @param float $monthly
     * @param array<string, mixed> $shop
     * @return string
     */
    private function formatInstallmentLabel($months, $monthly, array $shop)
    {
        $months = (int) $months;
        $amount = number_format((float) $monthly, 2, '.', '');
        $eurMode = (int) (isset($shop['uni_eur']) ? $shop['uni_eur'] : 0);
        $suffix = in_array($eurMode, array(2, 3), true) ? 'евро' : 'лв.';

        return $months . ' × ' . $amount . ' ' . $suffix;
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private function flag($value)
    {
        return in_array($value, array(1, '1', true, 'yes', 'on'), true);
    }

    /**
     * @param mixed $value
     * @param int $fallback
     * @param int $minimum
     * @param int $maximum
     * @return int
     */
    private function dimension($value, $fallback, $minimum, $maximum)
    {
        $dimension = (int) $value;
        if ($dimension < $minimum || $dimension > $maximum) {
            return (int) $fallback;
        }

        return $dimension;
    }

    /**
     * Recalculate a single selected scheme with optional first installment.
     *
     * @param array<string, mixed> $shop
     * @param float $price
     * @param MtUniCreditAvailableScheme $scheme
     * @param float $firstInstallment
     * @return array<string, mixed>
     */
    public function presentSchemeCalculation(array $shop, $price, MtUniCreditAvailableScheme $scheme, $firstInstallment = 0.0)
    {
        $result = $this->calculator->calculateScheme($shop, (float) $price, $scheme, (float) $firstInstallment);

        return array(
            'price' => (float) $price,
            'months' => (int) $scheme->months,
            'monthly' => $result->monthlyInstallment,
            'monthly_installment' => $result->monthlyInstallment,
            'financed' => $result->financedAmount,
            'financed_amount' => $result->financedAmount,
            'total' => $result->totalPayable,
            'total_payable' => $result->totalPayable,
            'glp' => $result->glp,
            'gpr' => $result->gpr,
            'first_installment' => $result->firstInstallment->amount,
            'first_installment_locked' => !empty($result->firstInstallment->locked),
            'show_first_installment' => $this->flag(isset($shop['uni_first_vnoska']) ? $shop['uni_first_vnoska'] : 0),
            'key' => self::keyForScheme($scheme),
        );
    }

    /**
     * @param array<string, mixed> $shop
     * @param MtUniCreditProductContext $product
     * @param array{type:string,kop_code:string,months:int,filter_id:int} $parsed
     * @return MtUniCreditAvailableScheme|null
     */
    public function findProductScheme(array $shop, MtUniCreditProductContext $product, array $parsed)
    {
        $schemes = $this->calculator->availableSchemes($shop, $product, $parsed['type']);
        foreach ($schemes as $scheme) {
            if (
                $scheme->kopCode === $parsed['kop_code']
                && $scheme->months === $parsed['months']
                && $scheme->filterId === $parsed['filter_id']
            ) {
                return $scheme;
            }
        }
        foreach ($schemes as $scheme) {
            if ($scheme->kopCode === $parsed['kop_code'] && $scheme->months === $parsed['months']) {
                return $scheme;
            }
        }

        return null;
    }

    /**
     * @param MtUniCreditCartResolution $resolution
     * @param array<string, mixed> $shop
     * @param array{type:string,kop_code:string,months:int,filter_id:int} $parsed
     * @return MtUniCreditAvailableScheme|null
     */
    public function findCartScheme(MtUniCreditCartResolution $resolution, array $shop, array $parsed)
    {
        foreach ($this->cartSchemes->unifiedSchemes($resolution, $shop) as $scheme) {
            if (
                $scheme->type === $parsed['type']
                && $scheme->kopCode === $parsed['kop_code']
                && $scheme->months === $parsed['months']
            ) {
                return $scheme;
            }
        }

        return null;
    }
}
