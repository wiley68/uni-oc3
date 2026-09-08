<?php

final class MtUniCreditCoefficientResolver
{
    /** @var MtUniCreditMonthResolver */
    private $months;

    public function __construct(MtUniCreditMonthResolver $months)
    {
        $this->months = $months;
    }

    /**
     * Exact kopCode + months lookup (AUD-016 F03).
     *
     * 0 matches → null
     * 1 match → coefficient
     * >1 matches with identical normalized coeff/interest → first (deterministic dedupe)
     * >1 matches with conflicting values → null (no order-dependent offer)
     *
     * @param array<int, mixed> $coefficients
     * @param string $kopCode
     * @param int $months
     * @return array<string, mixed>|null
     */
    public function find(array $coefficients, $kopCode, $months)
    {
        return $this->resolveMatches($this->collectMatches($coefficients, $kopCode, $months));
    }

    /**
     * @param array<int, mixed> $coefficients
     * @param string $kopCode
     * @param int[] $allowed
     * @param int $preferred
     * @return array<string, mixed>|null
     */
    public function findPreferredOrHighest(array $coefficients, $kopCode, array $allowed, $preferred)
    {
        if ($preferred > 0 && in_array($preferred, $allowed, true)) {
            $preferredMatches = $this->collectMatches($coefficients, $kopCode, $preferred);
            // Preferred month identity exists: resolve it (or null on conflict).
            // Do not fall back to another month when preferred rows are present but unusable.
            if ($preferredMatches !== array()) {
                return $this->resolveMatches($preferredMatches);
            }
        }

        $monthsSeen = array();
        foreach ($coefficients as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entryMonths = (int) (isset($entry['installmentCount']) ? $entry['installmentCount'] : 0);
            if (
                trim((string) (isset($entry['onlineProductCode']) ? $entry['onlineProductCode'] : '')) !== $kopCode
                || !$this->months->isValid($entryMonths)
                || !in_array($entryMonths, $allowed, true)
            ) {
                continue;
            }
            $monthsSeen[$entryMonths] = true;
        }

        if ($monthsSeen === array()) {
            return null;
        }

        $monthList = array_keys($monthsSeen);
        rsort($monthList, SORT_NUMERIC);
        foreach ($monthList as $entryMonths) {
            $entry = $this->find($coefficients, $kopCode, (int) $entryMonths);
            if ($entry !== null) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $entry
     * @return bool
     */
    public function isZeroInterest(array $entry)
    {
        return array_key_exists('interestPercent', $entry)
            && abs((float) $entry['interestPercent']) <= 0.00001;
    }

    /**
     * @param array<int, mixed> $coefficients
     * @param string $kopCode
     * @param int $months
     * @return array<int, array<string, mixed>>
     */
    private function collectMatches(array $coefficients, $kopCode, $months)
    {
        $matches = array();
        foreach ($coefficients as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (
                trim((string) (isset($entry['onlineProductCode']) ? $entry['onlineProductCode'] : '')) === $kopCode
                && (int) (isset($entry['installmentCount']) ? $entry['installmentCount'] : 0) === $months
            ) {
                $matches[] = $entry;
            }
        }

        return $matches;
    }

    /**
     * @param array<int, array<string, mixed>> $matches
     * @return array<string, mixed>|null
     */
    private function resolveMatches(array $matches)
    {
        if ($matches === array()) {
            return null;
        }

        $fingerprint = null;
        $chosen = null;
        foreach ($matches as $entry) {
            $current = $this->normalizeFingerprint($entry);
            if ($fingerprint === null) {
                $fingerprint = $current;
                $chosen = $entry;
                continue;
            }
            if ($current !== $fingerprint) {
                return null;
            }
        }

        return $chosen;
    }

    /**
     * @param array<string, mixed> $entry
     * @return string
     */
    private function normalizeFingerprint(array $entry)
    {
        $coeff = array_key_exists('coeff', $entry) ? (float) $entry['coeff'] : 0.0;
        $interest = array_key_exists('interestPercent', $entry) ? (float) $entry['interestPercent'] : 0.0;

        return sprintf('%.8F|%.8F', $coeff, $interest);
    }
}
