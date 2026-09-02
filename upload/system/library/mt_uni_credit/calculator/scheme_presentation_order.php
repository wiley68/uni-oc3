<?php

/**
 * Single shared ordering entry point for Product / Cart / Checkout scheme lists.
 *
 * Sort tuple:
 * months ASC → business type rank (standard=0, promo-like=1) → presentation rank
 * → filterId → kopCode → type → scheme key.
 */
final class MtUniCreditSchemePresentationOrder
{
    /**
     * @param MtUniCreditAvailableScheme[] $schemes
     * @param array<string, mixed> $shop
     * @return MtUniCreditAvailableScheme[]
     */
    public static function sort(array $schemes, array $shop)
    {
        return MtUniCreditSchemePresentationCategory::sort($schemes, $shop);
    }

    /**
     * Re-assert canonical order on the final presenter DTO rows handed to Twig/JS.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $shop
     * @return list<array<string, mixed>>
     */
    public static function sortPresentedRows(array $rows, array $shop)
    {
        $schemes = array();
        $byKey = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = (string) (isset($row['key']) ? $row['key'] : '');
            $scheme = new MtUniCreditAvailableScheme(
                (string) (isset($row['scheme_type']) ? $row['scheme_type'] : 'standard'),
                (string) (isset($row['kop_code']) ? $row['kop_code'] : ''),
                (int) (isset($row['months']) ? $row['months'] : 0),
                (int) (isset($row['filter_id']) ? $row['filter_id'] : 0),
                array(
                    'uni_promo' => ((isset($row['scheme_type']) ? $row['scheme_type'] : '') === 'promo') ? 1 : 0,
                    'uni_kop_desc' => (string) (isset($row['description']) ? $row['description'] : ''),
                ),
                array(
                    'interestPercent' => !empty($row['zero_interest_promo']) ? 0.0 : 1.0,
                    'coeff' => 0.09,
                )
            );
            $schemes[] = $scheme;
            $byKey[MtUniCreditProductSchemeList::key($scheme)] = $row;
            if ($key !== '') {
                $byKey[$key] = $row;
            }
        }

        $sorted = array();
        $seen = array();
        foreach (self::sort($schemes, $shop) as $scheme) {
            $key = MtUniCreditProductSchemeList::key($scheme);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if (isset($byKey[$key])) {
                $sorted[] = $byKey[$key];
            }
        }

        return $sorted;
    }
}
