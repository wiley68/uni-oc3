<?php

/** Pure canonical consent policy. No browser-provided interest/scope authority. PHP 7.3+. */
final class MtUniCreditStorefrontConsentResolver
{
    public function normalize(array $shop)
    {
        $raw = array_key_exists('consents', $shop) ? $shop['consents'] : array();
        if (is_string($raw)) {
            if (!is_array(json_decode($raw, false, 512, JSON_THROW_ON_ERROR))) { throw new InvalidArgumentException('consent_configuration'); }
            $raw = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        }
        if (!is_array($raw) || array_keys($raw) !== ($raw === array() ? array() : range(0, count($raw) - 1))) {
            throw new InvalidArgumentException('consent_configuration');
        }
        $result = array();
        foreach ($raw as $item) {
            if (!is_array($item) || !isset($item['name']) || !is_string($item['name']) || trim(strip_tags($item['name'])) === '') {
                throw new InvalidArgumentException('consent_configuration');
            }
            $id = $this->id(isset($item['id']) ? $item['id'] : null);
            if (isset($result[$id]) || (isset($item['url']) && !is_string($item['url']))) {
                throw new InvalidArgumentException('consent_configuration');
            }
            $scope = isset($item['interest_scope']) && is_string($item['interest_scope'])
                && in_array($item['interest_scope'], array('all', 'zero_interest', 'nonzero_interest'), true)
                ? $item['interest_scope'] : 'all';
            $mandatory = in_array(isset($item['mandatory']) ? $item['mandatory'] : 0, array(1, '1', true, 'yes', 'on', 'true'), true);
            $url = isset($item['url']) ? $item['url'] : '';
            $result[$id] = array('id' => $id, 'name' => trim(strip_tags($item['name'])),
                'url' => MtUniCreditStorefrontHttpUrl::sanitize($url), 'mandatory' => $mandatory,
                'has_checkbox' => $mandatory, 'interest_scope' => $scope);
        }
        ksort($result, SORT_NUMERIC);
        return array_values($result);
    }

    private function id($value)
    {
        if (is_int($value) && $value > 0) { return $value; }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) && (string) (int) $value === $value && (int) $value > 0) {
            return (int) $value;
        }
        throw new InvalidArgumentException('consent_ids');
    }

    public function acceptedIds($accepted)
    {
        if (!is_array($accepted) || array_keys($accepted) !== ($accepted === array() ? array() : range(0, count($accepted) - 1))) { throw new InvalidArgumentException('consent_ids'); }
        $ids = array();
        foreach ($accepted as $value) { $ids[] = $this->id($value); }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    public static function finite($value)
    {
        return (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) && is_finite((float) $value);
    }

    public static function classify(array $entry)
    {
        if (!isset($entry['coeff'], $entry['interestPercent']) || !self::finite($entry['coeff'])
            || (float) $entry['coeff'] <= 0 || !self::finite($entry['interestPercent'])) { return 'unknown'; }
        return abs((float) $entry['interestPercent']) <= 0.00001 ? 'zero' : 'nonzero';
    }

    public function context(array $shop, $kop, $months, array $entry)
    {
        $consents = $this->normalize($shop);
        $class = self::classify($entry);
        $applicable = $mandatory = array();
        $valid = true;
        foreach ($consents as $consent) {
            $scope = $consent['interest_scope'];
            if ($scope !== 'all' && $class === 'unknown') { $valid = false; }
            if ($scope === 'all' || ($scope === 'zero_interest' && $class === 'zero') || ($scope === 'nonzero_interest' && $class === 'nonzero')) {
                $applicable[] = $consent['id'];
                if ($consent['mandatory']) { $mandatory[] = $consent['id']; }
            }
        }
        return array('policy_version' => 1, 'kop_code' => (string) $kop, 'months' => (int) $months,
            'interest_class' => $class, 'valid' => $valid, 'consents' => $consents,
            'applicable_ids' => $applicable, 'mandatory_ids' => $mandatory,
            'config_hash' => hash('sha256', json_encode($consents, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }

    public function validate(array $context, $accepted, $renderedHash = null)
    {
        if (!$context['valid']) { throw new InvalidArgumentException('consent_unknown'); }
        if ($context['mandatory_ids'] !== array() && (!is_string($renderedHash) || !hash_equals($context['config_hash'], $renderedHash))) {
            throw new InvalidArgumentException('consent_refresh');
        }
        $ids = $this->acceptedIds($accepted);
        if (array_diff($context['mandatory_ids'], $ids)) { throw new InvalidArgumentException('consent_required'); }
        // Only applicable checkbox consents are evidence of explicit acceptance.
        return array_values(array_intersect($ids, $context['mandatory_ids']));
    }

    public function isSatisfied(array $shop, $accepted)
    {
        try {
            $context = $this->context($shop, '', 0, array());
            $this->validate($context, $accepted, $context['config_hash']);
            return true;
        } catch (Throwable $exception) { return false; }
    }

    /** Explicit compatibility utility; canonical empty/missing lists never call this. */
    public function legacyConsentAccepted($consent)
    {
        return in_array($consent, array(1, '1', true, 'on', 'yes'), true);
    }
}
