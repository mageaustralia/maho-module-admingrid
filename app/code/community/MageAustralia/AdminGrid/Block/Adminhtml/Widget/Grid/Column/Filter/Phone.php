<?php

declare(strict_types=1);

/**
 * MageAustralia AdminGrid
 *
 * @package    MageAustralia_AdminGrid
 * @copyright  Copyright (c) 2026 MageAustralia
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * Locale-configurable phone-number filter for admin grid columns.
 *
 * Stored order/customer phone numbers are rarely normalised: a mix of local
 * (0…) and international (+CC…) forms, with spaces, dashes and a leading +.
 * This filter normalises the SEARCH INPUT to digits and, when a country calling
 * code is configured for the column, searches BOTH the local and international
 * forms so a record is found regardless of which form the operator typed. It is
 * meant to target a digits-only companion column (e.g. a generated
 * `phone_digits` column that strips non-digits) via the column's `filter_index`.
 *
 * Per-column config:
 *   phone_country_code   country calling code, e.g. '61' (AU), '44' (UK), '1' (US/CA).
 *                        When empty, no local/international expansion is done — the
 *                        input is searched as plain digits.
 *   phone_trunk_prefix   national trunk digit(s), default '0'. Set to '' for the
 *                        North American Numbering Plan (US/CA), which has no trunk.
 *
 * Each variant is an index-friendly prefix `LIKE 'variant%'`; multiple variants
 * are OR-ed (still index-assisted). A leading % or * opts into a broad
 * `LIKE '%variant%'` contains search per variant (deliberately slower).
 */
class MageAustralia_AdminGrid_Block_Adminhtml_Widget_Grid_Column_Filter_Phone extends MageAustralia_AdminGrid_Block_Adminhtml_Widget_Grid_Column_Filter_Prefix
{
    #[\Override]
    public function getCondition(): ?array
    {
        [$value, $broad] = $this->_parseValue();
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);
        if ($digits === null || $digits === '') {
            return null;
        }

        $conditions = [];
        foreach ($this->_phoneVariants($digits) as $variant) {
            $conditions[] = $this->_likeCondition($variant, $broad);
        }

        // A single condition is returned bare (OR of one); multiple variants
        // become an OR group, which the collection applies as an OR.
        return count($conditions) === 1 ? $conditions[0] : $conditions;
    }

    /**
     * Expand a digits-only search term into local + international forms, using
     * the column's configured calling code and trunk prefix.
     *
     * @return string[]
     */
    protected function _phoneVariants(string $digits): array
    {
        $countryCode = trim((string) $this->getColumn()->getData('phone_country_code'));
        $trunkPrefix = $this->getColumn()->hasData('phone_trunk_prefix')
            ? trim((string) $this->getColumn()->getData('phone_trunk_prefix'))
            : '0';

        $variants = [$digits];

        // No calling code configured: search the digits as-is (no expansion).
        if ($countryCode === '') {
            return $variants;
        }

        if (str_starts_with($digits, $countryCode)) {
            // International -> local: strip the calling code, prepend the trunk.
            $national = substr($digits, strlen($countryCode));
            $variants[] = $trunkPrefix . $national;
        } elseif ($trunkPrefix !== '' && str_starts_with($digits, $trunkPrefix)) {
            // Local -> international: strip the trunk, prepend the calling code.
            $national = substr($digits, strlen($trunkPrefix));
            $variants[] = $countryCode . $national;
        } else {
            // Bare national number (operator omitted trunk/calling code): try both.
            $variants[] = $countryCode . $digits;
            if ($trunkPrefix !== '') {
                $variants[] = $trunkPrefix . $digits;
            }
        }

        return array_values(array_unique(array_filter(
            $variants,
            static fn(string $v): bool => $v !== '',
        )));
    }
}
