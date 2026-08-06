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
 * Australian preset of the generic, locale-configurable phone filter.
 *
 * Thin subclass of the general Phone filter that defaults the column to the
 * Australian calling code (+61) and trunk prefix (0), so an operator finds an
 * order whether they type the local (04xx…) or international (+61 4xx…) form.
 * Kept as a store-local convenience; the generic Phone filter is the one shipped
 * in the open-source module. Explicit per-column config still wins.
 *
 * @see MageAustralia_AdminGrid_Block_Adminhtml_Widget_Grid_Column_Filter_Phone
 */
class MageAustralia_AdminGrid_Block_Adminhtml_Widget_Grid_Column_Filter_Phoneau extends MageAustralia_AdminGrid_Block_Adminhtml_Widget_Grid_Column_Filter_Phone
{
    #[\Override]
    protected function _phoneVariants(string $digits): array
    {
        $column = $this->getColumn();
        if (trim((string) $column->getData('phone_country_code')) === '') {
            $column->setData('phone_country_code', '61');
        }
        if (!$column->hasData('phone_trunk_prefix')) {
            $column->setData('phone_trunk_prefix', '0');
        }

        return parent::_phoneVariants($digits);
    }
}
