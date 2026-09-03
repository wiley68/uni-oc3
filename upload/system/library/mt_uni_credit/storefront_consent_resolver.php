<?php

/**
 * Normalizes CP shop consents for Step 2 (OC4 ConsentResolver parity).
 */
final class MtUniCreditStorefrontConsentResolver
{
    /**
     * @param array<string, mixed> $shop
     * @return list<array{id:int,name:string,url:string,mandatory:bool,has_checkbox:bool}>
     */
    public function normalize(array $shop)
    {
        $raw = isset($shop['consents']) ? $shop['consents'] : array();
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : array();
        }

        $result = array();
        foreach (is_array($raw) ? $raw : array() as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim(strip_tags((string) (isset($item['name']) ? $item['name'] : '')));
            if ($name === '') {
                continue;
            }
            $mandatory = $this->flag(isset($item['mandatory']) ? $item['mandatory'] : 0);
            $url = isset($item['url']) ? (string) $item['url'] : '';
            $validUrl = filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
            $result[] = array(
                'id' => max(1, (int) (isset($item['id']) ? $item['id'] : ($index + 1))),
                'name' => $name,
                'url' => $validUrl,
                'mandatory' => $mandatory,
                'has_checkbox' => $mandatory,
            );
        }

        usort($result, function ($a, $b) {
            return $a['id'] - $b['id'];
        });

        return $result;
    }

    /**
     * @param array<string, mixed> $shop
     * @param mixed $accepted
     * @return bool
     */
    public function isSatisfied(array $shop, $accepted)
    {
        $consents = $this->normalize($shop);
        if ($consents === array()) {
            // Language fallback single checkbox: accept "1"/on.
            return $this->legacyConsentAccepted($accepted);
        }

        $acceptedIds = array();
        if (is_array($accepted)) {
            foreach ($accepted as $value) {
                $acceptedIds[] = (int) $value;
            }
        } elseif (is_string($accepted) && $accepted !== '') {
            foreach (explode(',', $accepted) as $value) {
                $acceptedIds[] = (int) $value;
            }
        }
        $acceptedIds = array_values(array_unique(array_filter($acceptedIds)));

        foreach ($consents as $consent) {
            if ($consent['mandatory'] && !in_array($consent['id'], $acceptedIds, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param mixed $consent
     * @return bool
     */
    public function legacyConsentAccepted($consent)
    {
        if (is_array($consent)) {
            foreach ($consent as $value) {
                if ($value === '1' || $value === 1 || $value === true || $value === 'on') {
                    return true;
                }
            }

            return false;
        }

        return $consent === '1' || $consent === 1 || $consent === true || $consent === 'on' || $consent === 'yes';
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private function flag($value)
    {
        return in_array($value, array(1, '1', true, 'yes', 'on', 'true'), true);
    }
}
