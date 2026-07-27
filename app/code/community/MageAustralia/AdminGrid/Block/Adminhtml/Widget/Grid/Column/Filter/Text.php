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
 * Wildcard-aware text filter for admin grid column searches.
 *
 * The default Maho text filter escapes the whole term and builds LIKE '%term%',
 * so a `*` or `%` typed by the user matches literally. This filter treats BOTH
 * `*` and `%` as multi-character wildcards anywhere in the term, so
 * `foo*bar` (or `foo%bar`) becomes LIKE '%foo%bar%' and matches "foo ... bar".
 * It restores the wildcard search merchants relied on under BL_CustomGrid.
 *
 * It only changes behaviour when the term actually contains `*` or `%`; any other
 * term defers to the parent's default escaped LIKE. A literal `_` is escaped so
 * SKUs and codes containing underscores still match as typed.
 *
 * Registered as the core `adminhtml/widget_grid_column_filter_text` rewrite, so it
 * applies to every standard text-filtered grid column (product name, etc.).
 */
class MageAustralia_AdminGrid_Block_Adminhtml_Widget_Grid_Column_Filter_Text extends Mage_Adminhtml_Block_Widget_Grid_Column_Filter_Text
{
    #[\Override]
    public function getCondition()
    {
        $value = (string) $this->getValue();

        // No wildcard typed -> keep the default (safely escaped) LIKE behaviour.
        if (!str_contains($value, '*') && !str_contains($value, '%')) {
            return parent::getCondition();
        }

        $helper = Mage::helper('core/string');
        $length = $helper->strlen($value);
        $expr   = '';

        for ($i = 0; $i < $length; $i++) {
            $char = $helper->substr($value, $i, 1);
            if ($char === '*' || $char === '%') {
                $expr .= '%';            // both act as a multi-character wildcard
            } elseif ($char === '_') {
                $expr .= '\\_';          // literal underscore (SKUs etc.)
            } elseif ($char === '\\') {
                $expr .= '\\\\';
            } else {
                $expr .= $char;
            }
        }

        return ['like' => '%' . $expr . '%'];
    }
}
