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
     * @param array<int, mixed> $coefficients
     * @param string $kopCode
     * @param int $months
     * @return array<string, mixed>|null
     */
    public function find(array $coefficients, $kopCode, $months)
    {
        foreach ($coefficients as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (
                trim((string) (isset($entry['onlineProductCode']) ? $entry['onlineProductCode'] : '')) === $kopCode
                && (int) (isset($entry['installmentCount']) ? $entry['installmentCount'] : 0) === $months
            ) {
                return $entry;
            }
        }

        return null;
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
            $entry = $this->find($coefficients, $kopCode, $preferred);
            if ($entry !== null) {
                return $entry;
            }
        }

        $best = null;
        $bestMonths = 0;
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
            if ($entryMonths > $bestMonths) {
                $best = $entry;
                $bestMonths = $entryMonths;
            }
        }

        return $best;
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
}
