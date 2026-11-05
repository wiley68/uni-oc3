<?php

/** Shared consent authority and immutable evidence verification. No persistence or network. */
final class MtUniCreditConsentGate
{
    // Request-only authorization for legacy v1 continuation; never historical acceptance.
    private static $legacy = array();

    public static function context(array $shop, MtUniCreditAvailableScheme $scheme)
    {
        return (new MtUniCreditStorefrontConsentResolver())->context($shop, $scheme->kopCode, $scheme->months, $scheme->coefficient);
    }

    public static function evidence(array $shop, MtUniCreditCalculationResult $calculation, array $input, array $binding)
    {
        $context = self::context($shop, $calculation->scheme);
        $accepted = (new MtUniCreditStorefrontConsentResolver())->validate($context,
            isset($input['consent']) ? $input['consent'] : array(), isset($input['consent_config_hash']) ? $input['consent_config_hash'] : null);
        $entry = $calculation->scheme->coefficient;
        return array('evidence_version' => 1, 'context' => $context,
            'coefficient' => isset($entry['coeff']) && MtUniCreditStorefrontConsentResolver::finite($entry['coeff']) ? (float) $entry['coeff'] : null,
            'interest_percent' => isset($entry['interestPercent']) && MtUniCreditStorefrontConsentResolver::finite($entry['interestPercent']) ? (float) $entry['interestPercent'] : null,
            'accepted_ids' => $accepted, 'binding' => $binding, 'accepted_at' => gmdate('Y-m-d\TH:i:s\Z'));
    }

    public static function binding(array $snapshot, $storeId, $orderId)
    {
        return array('store_id' => (int) $storeId, 'order_id' => (string) $orderId,
            'entry_point' => $snapshot['entry_point'], 'operation_key_hash' => $snapshot['operation_key_hash'],
            'selection_hash' => $snapshot['selection_hash']);
    }

    public static function v2(array $snapshot, array $row)
    {
        try {
            if (!isset($snapshot['version']) || $snapshot['version'] !== 2 || !isset($snapshot['consent_evidence']) || !is_array($snapshot['consent_evidence'])) { return false; }
            $e = $snapshot['consent_evidence'];
            if (!isset($e['evidence_version'], $e['context'], $e['accepted_ids'], $e['binding'], $e['accepted_at'])
                || !array_key_exists('coefficient', $e) || !array_key_exists('interest_percent', $e)
                || !isset($e['context']['consents']) || !is_array($e['context']['consents'])
                || $e['evidence_version'] !== 1 || !is_array($e['context']) || !is_string($e['accepted_at'])
                || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $e['accepted_at'])) { return false; }
            $scheme = $snapshot['scheme'];
            if (!is_array($scheme) || !isset($scheme['kop_code'], $scheme['months']) || !is_string($scheme['kop_code']) || $scheme['kop_code'] === ''
                || !is_int($scheme['months']) || $scheme['months'] < 3 || $scheme['months'] > 36) { return false; }
            $r = new MtUniCreditStorefrontConsentResolver();
            $context = $r->context(array('consents' => $e['context']['consents']), $scheme['kop_code'], $scheme['months'],
                array('coeff' => $e['coefficient'], 'interestPercent' => $e['interest_percent']));
            if ($context !== $e['context'] || !$context['valid'] || $e['accepted_ids'] !== $r->validate($context, $e['accepted_ids'], $context['config_hash'])) { return false; }
            return $e['binding'] === self::binding($snapshot, $row['store_id'], $row['order_id'])
                && $snapshot['operation_key_hash'] === $row['operation_key_hash']
                && $snapshot['selection_hash'] === $row['selection_hash'] && $snapshot['entry_point'] === $row['entry_point']
                && isset($snapshot['request_fingerprint'], $row['request_fingerprint']) && $snapshot['request_fingerprint'] === $row['request_fingerprint'];
        } catch (Throwable $exception) { return false; }
    }

    public static function snapshot(array $row)
    {
        $raw = isset($row['application_snapshot_json']) ? $row['application_snapshot_json'] : null;
        $snapshot = MtUniCreditApplicationSnapshot::decode($raw);
        if ($snapshot === null || !isset($row['application_snapshot_hash']) || !is_string($row['application_snapshot_hash'])
            || !hash_equals($row['application_snapshot_hash'], MtUniCreditApplicationSnapshot::hash($snapshot))
            || !in_array($snapshot['version'], array(1, 2), true)) { throw new InvalidArgumentException('consent_evidence'); }
        if ($snapshot['operation_key_hash'] !== $row['operation_key_hash'] || $snapshot['selection_hash'] !== $row['selection_hash']
            || $snapshot['entry_point'] !== $row['entry_point']
            || !isset($snapshot['request_fingerprint'], $row['request_fingerprint']) || $snapshot['request_fingerprint'] !== $row['request_fingerprint'] || !isset($snapshot['scheme']['kop_code'], $snapshot['scheme']['months'])
            || !is_string($snapshot['scheme']['kop_code']) || $snapshot['scheme']['kop_code'] === ''
            || !is_int($snapshot['scheme']['months']) || $snapshot['scheme']['months'] < 3 || $snapshot['scheme']['months'] > 36) {
            throw new InvalidArgumentException('consent_evidence');
        }
        if ($snapshot['version'] === 1 && array_key_exists('consent_evidence', $snapshot)) { throw new InvalidArgumentException('consent_evidence'); }
        if ($snapshot['version'] === 2 && !self::v2($snapshot, $row)) { throw new InvalidArgumentException('consent_evidence'); }
        return $snapshot;
    }

    private static function legacyKey(array $row, array $shop)
    {
        return hash('sha256', $row['store_id'] . '|' . $row['order_id'] . '|' . $row['operation_key_hash'] . '|'
            . $row['application_snapshot_hash'] . '|' . json_encode(array(isset($shop['consents']) ? $shop['consents'] : array(), isset($shop['coeff_list']) ? $shop['coeff_list'] : array())));
    }

    public static function authorizeLegacy(array $row, array $shop, array $input)
    {
        $snapshot = self::snapshot($row);
        if ($snapshot['version'] !== 1) { return; }
        $s = $snapshot['scheme'];
        $entry = (new MtUniCreditCoefficientResolver(new MtUniCreditMonthResolver()))->find(
            isset($shop['coeff_list']) && is_array($shop['coeff_list']) ? $shop['coeff_list'] : array(), $s['kop_code'], $s['months']);
        $r = new MtUniCreditStorefrontConsentResolver();
        $context = $r->context($shop, $s['kop_code'], $s['months'], is_array($entry) ? $entry : array());
        $r->validate($context, isset($input['consent']) ? $input['consent'] : array(), isset($input['consent_config_hash']) ? $input['consent_config_hash'] : null);
        self::$legacy[self::legacyKey($row, $shop)] = true;
        self::$legacy['attempt:' . $row['store_id'] . ':' . $row['order_id'] . ':' . $row['operation_key_hash'] . ':' . $row['application_snapshot_hash']] = true;
    }

    public static function permits(array $row, $shop = null)
    {
        try {
            $s = self::snapshot($row);
            if ($s['version'] === 2) { return true; }
            if (!is_array($shop)) { return isset(self::$legacy['attempt:' . $row['store_id'] . ':' . $row['order_id'] . ':' . $row['operation_key_hash'] . ':' . $row['application_snapshot_hash']]); }
            if (isset(self::$legacy[self::legacyKey($row, $shop)])) { return true; }
            // Empty/informational all-only lists require no acceptance; scoped unknown still refuses.
            self::authorizeLegacy($row, $shop, array());
            return true;
        } catch (Throwable $exception) { return false; }
    }

    public static function failure($code, $orderId = null)
    {
        if (!in_array($code, array('consent_required', 'consent_refresh', 'consent_unknown', 'consent_evidence', 'consent_ids', 'consent_configuration'), true)) { $code = 'consent_configuration'; }
        return array('success' => false, 'error' => $code, 'order_id' => $orderId,
            'message' => $orderId ? 'Продължаването на съществуващата заявка е блокирано. Обновете страницата; ако проблемът остане, свържете се с поддръжката. Не създавайте нова поръчка.'
                : ($code === 'consent_required' ? 'Приемете задължителните съгласия.' : 'Обновете страницата и проверете съгласията преди изпращане.'),
            'cart_unchanged' => true, 'refresh_required' => $code !== 'consent_required');
    }
}
