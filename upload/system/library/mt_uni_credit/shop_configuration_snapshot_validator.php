<?php

/**
 * Canonical structural/business validation for CP shop configuration snapshots (AUD-005).
 * Used by both pull and push. Does not enforce SmartUCF outbound allowlist (AUD-003).
 */
final class MtUniCreditShopConfigurationSnapshotValidator
{
    const MONTH_MIN = 3;
    const MONTH_MAX = 36;
    const PROMO_OPERATORS = array('eq', 'greateq', '');

    /** @var array<int, array<string, string>> */
    private $violations = array();

    /**
     * @param array<string, mixed> $shopData
     * @param string $authenticatedUnicid
     * @return void
     * @throws MtUniCreditShopSnapshotValidationException
     */
    public function validate(array $shopData, $authenticatedUnicid = '')
    {
        $this->violations = array();

        if ($shopData === array()) {
            $this->add('', 'required');
            $this->throwIfAny();
        }

        $this->validateUnicid($shopData, $authenticatedUnicid);
        $this->requireEnumInt($shopData, 'uni_status', array(0, 1), true);
        $this->requireEnumInt($shopData, 'uni_typekop', array(0, 1), false);
        $this->requireEnumInt($shopData, 'uni_proces', array(0, 1), true);
        $this->requireEnumInt($shopData, 'uni_env', array(0, 1), true);
        $this->requireEnumInt($shopData, 'uni_eur', array(0, 1, 2, 3), true);

        $min = $this->requireFiniteNumber($shopData, 'uni_minstojnost');
        $max = $this->requireFiniteNumber($shopData, 'uni_maxstojnost');
        if ($min !== null && $max !== null && $min > $max) {
            $this->add('uni_minstojnost', 'invalid_range');
        }

        $this->validateMonthFlags($shopData);
        $this->validateYesFlagIfPresent($shopData, 'uni_first_vnoska');
        $this->validateYesFlagIfPresent($shopData, 'uni_sertificat');
        $this->validateShemaCurrent($shopData);
        $this->validateKop($shopData);
        $this->validateCoeffList($shopData);
        $this->validateConsents($shopData);
        $this->validateProcess1SmartUcf($shopData);

        $this->throwIfAny();
    }

    /**
     * @param array<string, mixed> $shopData
     * @param string $authenticatedUnicid
     * @return void
     */
    private function validateUnicid(array $shopData, $authenticatedUnicid)
    {
        if (!array_key_exists('unicid', $shopData)) {
            return;
        }
        if (!is_string($shopData['unicid']) || trim($shopData['unicid']) === '') {
            $this->add('unicid', 'invalid_type');

            return;
        }
        if ($authenticatedUnicid !== '' && !hash_equals($authenticatedUnicid, $shopData['unicid'])) {
            $this->add('unicid', 'mismatch');
        }
    }

    /**
     * @param array<string, mixed> $shopData
     * @return void
     */
    private function validateMonthFlags(array $shopData)
    {
        for ($m = self::MONTH_MIN; $m <= self::MONTH_MAX; ++$m) {
            $key = 'uni_meseci_' . $m;
            if (!array_key_exists($key, $shopData)) {
                $this->add($key, 'required');
                continue;
            }
            if (!$this->isYesFlagCompatible($shopData[$key])) {
                $this->add($key, 'invalid_type');
            }
        }
    }

    /**
     * @param array<string, mixed> $shopData
     * @return void
     */
    private function validateShemaCurrent(array $shopData)
    {
        if (!array_key_exists('uni_shema_current', $shopData)) {
            return;
        }
        if (!$this->isNumericScalar($shopData['uni_shema_current'])) {
            $this->add('uni_shema_current', 'invalid_type');

            return;
        }
        $months = (int) $shopData['uni_shema_current'];
        if ($months !== 0 && ($months < self::MONTH_MIN || $months > self::MONTH_MAX)) {
            $this->add('uni_shema_current', 'invalid_months');
        }
    }

    /**
     * @param array<string, mixed> $shopData
     * @return void
     */
    private function validateKop(array $shopData)
    {
        if (!isset($shopData['kop']) || !is_array($shopData['kop'])) {
            $this->add('kop', 'required');

            return;
        }

        $kop = $shopData['kop'];
        if (!isset($kop['by_default']) || !is_array($kop['by_default'])) {
            $this->add('kop.by_default', 'required');
        } else {
            $this->validateByDefault($kop['by_default'], (int) (isset($shopData['uni_typekop']) ? $shopData['uni_typekop'] : -1));
        }

        if (!isset($kop['by_schema']) || !is_array($kop['by_schema'])) {
            $this->add('kop.by_schema', 'required');
        } else {
            $this->validateBySchema($kop['by_schema'], (int) (isset($shopData['uni_typekop']) ? $shopData['uni_typekop'] : -1));
        }
    }

    /**
     * @param array<string, mixed> $byDefault
     * @param int $typeKop
     * @return void
     */
    private function validateByDefault(array $byDefault, $typeKop)
    {
        if ($typeKop === 0) {
            $defaultKop = isset($byDefault['uni_kop_default']) ? $byDefault['uni_kop_default'] : null;
            if (!is_string($defaultKop) || trim($defaultKop) === '') {
                $this->add('kop.by_default.uni_kop_default', 'required');
            }
            if (
                array_key_exists('uni_kop_promo', $byDefault)
                && $byDefault['uni_kop_promo'] !== null
                && !is_string($byDefault['uni_kop_promo'])
            ) {
                $this->add('kop.by_default.uni_kop_promo', 'invalid_type');
            }
        } else {
            foreach (array('uni_kop_default', 'uni_kop_promo') as $key) {
                if (
                    array_key_exists($key, $byDefault)
                    && $byDefault[$key] !== null
                    && !is_string($byDefault[$key])
                ) {
                    $this->add('kop.by_default.' . $key, 'invalid_type');
                }
            }
        }

        if (array_key_exists('uni_promo_price', $byDefault) && $byDefault['uni_promo_price'] !== null) {
            if (!$this->isFiniteNumber($byDefault['uni_promo_price'])) {
                $this->add('kop.by_default.uni_promo_price', 'invalid_numeric');
            }
        }

        if (array_key_exists('uni_promo_meseci_znak', $byDefault) && $byDefault['uni_promo_meseci_znak'] !== null) {
            if (!is_string($byDefault['uni_promo_meseci_znak'])) {
                $this->add('kop.by_default.uni_promo_meseci_znak', 'invalid_type');
            } else {
                $op = strtolower(trim($byDefault['uni_promo_meseci_znak']));
                if (!in_array($op, self::PROMO_OPERATORS, true)) {
                    $this->add('kop.by_default.uni_promo_meseci_znak', 'invalid_enum');
                }
            }
        }

        if (array_key_exists('uni_promo_meseci', $byDefault) && $byDefault['uni_promo_meseci'] !== null) {
            if (!is_string($byDefault['uni_promo_meseci']) && !$this->isNumericScalar($byDefault['uni_promo_meseci'])) {
                $this->add('kop.by_default.uni_promo_meseci', 'invalid_type');
            }
        }

        foreach (array('uni_kop_default_desc', 'uni_kop_promo_desc') as $descKey) {
            if (
                array_key_exists($descKey, $byDefault)
                && $byDefault[$descKey] !== null
                && !is_string($byDefault[$descKey])
            ) {
                $this->add('kop.by_default.' . $descKey, 'invalid_type');
            }
        }
    }

    /**
     * @param array<string, mixed> $bySchema
     * @param int $typeKop
     * @return void
     */
    private function validateBySchema(array $bySchema, $typeKop)
    {
        if (!array_key_exists('filters', $bySchema)) {
            $this->add('kop.by_schema.filters', 'required');

            return;
        }
        if (!is_array($bySchema['filters'])) {
            $this->add('kop.by_schema.filters', 'invalid_type');

            return;
        }

        if ($typeKop === 1 && $bySchema['filters'] === array()) {
            // Empty filters array is structurally valid (no schemes); do not reject.
        }

        foreach ($bySchema['filters'] as $index => $filter) {
            $path = 'kop.by_schema.filters[' . $index . ']';
            if (!is_array($filter)) {
                $this->add($path, 'invalid_type');
                continue;
            }
            $this->validateFilter($filter, $path);
        }
    }

    /**
     * @param array<string, mixed> $filter
     * @param string $path
     * @return void
     */
    private function validateFilter(array $filter, $path)
    {
        if (!array_key_exists('id', $filter) || !$this->isNumericScalar($filter['id']) || (int) $filter['id'] <= 0) {
            $this->add($path . '.id', 'required');
        }

        $kop = isset($filter['uni_kop']) ? $filter['uni_kop'] : null;
        if (!is_string($kop) || trim($kop) === '') {
            $this->add($path . '.uni_kop', 'required');
        }

        if (
            array_key_exists('uni_kop_desc', $filter)
            && $filter['uni_kop_desc'] !== null
            && !is_string($filter['uni_kop_desc'])
        ) {
            $this->add($path . '.uni_kop_desc', 'invalid_type');
        }

        if (array_key_exists('uni_meseci', $filter) && $filter['uni_meseci'] !== null && $filter['uni_meseci'] !== '') {
            if (!is_string($filter['uni_meseci']) && !$this->isNumericScalar($filter['uni_meseci'])) {
                $this->add($path . '.uni_meseci', 'invalid_type');
            }
        }

        $priceFrom = null;
        $priceTo = null;
        if ($this->hasMeaningfulValue(isset($filter['uni_price_from']) ? $filter['uni_price_from'] : null)) {
            if (!$this->isFiniteNumber($filter['uni_price_from'])) {
                $this->add($path . '.uni_price_from', 'invalid_numeric');
            } else {
                $priceFrom = (float) $filter['uni_price_from'];
            }
        }
        if ($this->hasMeaningfulValue(isset($filter['uni_price_to']) ? $filter['uni_price_to'] : null)) {
            if (!$this->isFiniteNumber($filter['uni_price_to'])) {
                $this->add($path . '.uni_price_to', 'invalid_numeric');
            } else {
                $priceTo = (float) $filter['uni_price_to'];
            }
        }
        if ($priceFrom !== null && $priceTo !== null && $priceFrom > $priceTo) {
            $this->add($path . '.uni_price_from', 'invalid_range');
        }

        $dateFrom = $this->normalizeDate(isset($filter['uni_date_from']) ? $filter['uni_date_from'] : null, $path . '.uni_date_from');
        $dateTo = $this->normalizeDate(isset($filter['uni_date_to']) ? $filter['uni_date_to'] : null, $path . '.uni_date_to');
        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            $this->add($path . '.uni_date_from', 'invalid_range');
        }

        foreach (array('uni_promo', 'uni_parva') as $flagKey) {
            if (
                array_key_exists($flagKey, $filter) && $filter[$flagKey] !== null
                && !$this->isYesFlagCompatible($filter[$flagKey])
                && !$this->isNumericScalar($filter[$flagKey])
            ) {
                $this->add($path . '.' . $flagKey, 'invalid_type');
            }
        }

        foreach (array('category_id', 'product_id', 'shop_id') as $idKey) {
            if (!$this->hasMeaningfulValue(isset($filter[$idKey]) ? $filter[$idKey] : null)) {
                continue;
            }
            if (!$this->isNumericScalar($filter[$idKey])) {
                $this->add($path . '.' . $idKey, 'invalid_type');
            }
        }
    }

    /**
     * @param array<string, mixed> $shopData
     * @return void
     */
    private function validateCoeffList(array $shopData)
    {
        if (!array_key_exists('coeff_list', $shopData)) {
            $this->add('coeff_list', 'required');

            return;
        }
        if (!is_array($shopData['coeff_list'])) {
            $this->add('coeff_list', 'invalid_type');

            return;
        }

        // Empty list is structurally valid and MUST be allowed to replace previous data.
        foreach ($shopData['coeff_list'] as $index => $entry) {
            $path = 'coeff_list[' . $index . ']';
            if (!is_array($entry)) {
                $this->add($path, 'invalid_type');
                continue;
            }
            $code = isset($entry['onlineProductCode']) ? $entry['onlineProductCode'] : null;
            if (!is_string($code) || trim($code) === '') {
                $this->add($path . '.onlineProductCode', 'required');
            }
            if (!array_key_exists('installmentCount', $entry) || !$this->isNumericScalar($entry['installmentCount'])) {
                $this->add($path . '.installmentCount', 'invalid_type');
            } else {
                $months = (int) $entry['installmentCount'];
                if ($months < self::MONTH_MIN || $months > self::MONTH_MAX) {
                    $this->add($path . '.installmentCount', 'invalid_months');
                }
            }
            if (!array_key_exists('coeff', $entry) || !$this->isFiniteNumber($entry['coeff'])) {
                $this->add($path . '.coeff', 'invalid_numeric');
            }
            if (!array_key_exists('interestPercent', $entry) || !$this->isFiniteNumber($entry['interestPercent'])) {
                $this->add($path . '.interestPercent', 'invalid_numeric');
            }
        }
    }

    /**
     * @param array<string, mixed> $shopData
     * @return void
     */
    private function validateConsents(array $shopData)
    {
        if (!array_key_exists('consents', $shopData)) {
            return;
        }
        $raw = $shopData['consents'];
        if (is_string($raw)) {
            try {
                $raw = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                $this->add('consents', 'invalid_type');

                return;
            }
        }
        if (!is_array($raw)) {
            $this->add('consents', 'invalid_type');

            return;
        }

        $seenIds = array();
        foreach ($raw as $index => $item) {
            $path = 'consents[' . $index . ']';
            if (!is_array($item)) {
                // Non-array optional noise — skip like runtime; not mandatory.
                continue;
            }

            $mandatory = $this->isTruthyFlag(isset($item['mandatory']) ? $item['mandatory'] : 0);
            $name = isset($item['name']) ? trim(strip_tags((string) $item['name'])) : '';
            $hasId = array_key_exists('id', $item) && $this->isNumericScalar($item['id']) && (int) $item['id'] > 0;

            if ($mandatory) {
                if ($name === '') {
                    $this->add($path . '.name', 'required');
                }
                if (!$hasId) {
                    $this->add($path . '.id', 'required');
                }
                if (array_key_exists('url', $item) && $item['url'] !== null && $item['url'] !== '' && !is_string($item['url'])) {
                    $this->add($path . '.url', 'invalid_type');
                }
            } else {
                // Optional consent: skip unusable entries (runtime semantics).
                if ($name === '') {
                    continue;
                }
                if (!$hasId) {
                    continue;
                }
            }

            if ($hasId) {
                $id = (int) $item['id'];
                if (isset($seenIds[$id])) {
                    $this->add($path . '.id', 'duplicate');
                }
                $seenIds[$id] = true;
            }
        }
    }

    /**
     * @param array<string, mixed> $shopData
     * @return void
     */
    private function validateProcess1SmartUcf(array $shopData)
    {
        $process2 = ((int) (isset($shopData['uni_proces']) ? $shopData['uni_proces'] : 0)) === 1;
        if ($process2) {
            return;
        }

        $isTest = ((int) (isset($shopData['uni_env']) ? $shopData['uni_env'] : 1)) === 0;
        $serviceKey = $isTest ? 'uni_test_service' : 'uni_production_service';
        $applicationKey = $isTest ? 'uni_test_application' : 'uni_production_application';

        foreach (array($serviceKey, $applicationKey, 'uni_user', 'uni_password') as $key) {
            if (!array_key_exists($key, $shopData) || !is_string($shopData[$key]) || trim($shopData[$key]) === '') {
                $this->add($key, 'required');
            }
        }

        // Presence of alternate env URLs: if present, must be strings (structure only — not AUD-003).
        foreach (array('uni_test_service', 'uni_test_application', 'uni_production_service', 'uni_production_application') as $key) {
            if (!array_key_exists($key, $shopData) || $shopData[$key] === null) {
                continue;
            }
            if (!is_string($shopData[$key])) {
                $this->add($key, 'invalid_type');
            }
        }
    }

    /**
     * @param array<string, mixed> $shopData
     * @param string $key
     * @param array<int, int> $allowed
     * @param bool $defaultable
     * @return void
     */
    private function requireEnumInt(array $shopData, $key, array $allowed, $defaultable)
    {
        if (!array_key_exists($key, $shopData)) {
            if (!$defaultable) {
                $this->add($key, 'required');
            }

            return;
        }
        if (!$this->isNumericScalar($shopData[$key])) {
            $this->add($key, 'invalid_type');

            return;
        }
        if (!in_array((int) $shopData[$key], $allowed, true)) {
            $this->add($key, 'invalid_enum');
        }
    }

    /**
     * @param array<string, mixed> $shopData
     * @param string $key
     * @return float|null
     */
    private function requireFiniteNumber(array $shopData, $key)
    {
        if (!array_key_exists($key, $shopData)) {
            $this->add($key, 'required');

            return null;
        }
        if (!$this->isFiniteNumber($shopData[$key])) {
            $this->add($key, 'invalid_numeric');

            return null;
        }

        return (float) $shopData[$key];
    }

    /**
     * @param array<string, mixed> $shopData
     * @param string $key
     * @return void
     */
    private function validateYesFlagIfPresent(array $shopData, $key)
    {
        if (!array_key_exists($key, $shopData) || $shopData[$key] === null) {
            return;
        }
        if (!$this->isYesFlagCompatible($shopData[$key])) {
            $this->add($key, 'invalid_type');
        }
    }

    /**
     * @param mixed $value
     * @param string $path
     * @return string|null
     */
    private function normalizeDate($value, $path)
    {
        if (!$this->hasMeaningfulValue($value)) {
            return null;
        }
        if (!is_string($value) && !$this->isNumericScalar($value)) {
            $this->add($path, 'invalid_type');

            return null;
        }
        $date = substr(trim((string) $value), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->add($path, 'invalid_type');

            return null;
        }

        return $date;
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private function isYesFlagCompatible($value)
    {
        if (is_bool($value)) {
            return true;
        }
        if ($this->isNumericScalar($value)) {
            $int = (int) $value;

            return $int === 0 || $int === 1;
        }
        if (!is_string($value)) {
            return false;
        }
        $normalized = strtolower(trim($value));

        return in_array($normalized, array('0', '1', 'yes', 'no', 'on', 'off', 'true', 'false', ''), true);
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private function isTruthyFlag($value)
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($this->isNumericScalar($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), array('1', 'yes', 'on', 'true'), true);
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private function isNumericScalar($value)
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value);
        }
        if (is_string($value) && is_numeric($value)) {
            return is_finite((float) $value);
        }

        return false;
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private function isFiniteNumber($value)
    {
        return $this->isNumericScalar($value);
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private function hasMeaningfulValue($value)
    {
        return $value !== null && trim((string) $value) !== '';
    }

    /**
     * @param string $path
     * @param string $code
     * @return void
     */
    private function add($path, $code)
    {
        $this->violations[] = array(
            'path' => $path,
            'code' => $code,
        );
    }

    /**
     * @return void
     * @throws MtUniCreditShopSnapshotValidationException
     */
    private function throwIfAny()
    {
        if ($this->violations === array()) {
            return;
        }

        throw new MtUniCreditShopSnapshotValidationException($this->violations);
    }
}
