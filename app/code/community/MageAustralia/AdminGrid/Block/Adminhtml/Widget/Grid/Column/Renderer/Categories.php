<?php

declare(strict_types=1);

/**
 * Renderer for category column — displays comma-separated category names.
 * Data is hydrated post-load by the Observer (no JOIN).
 *
 * @category   MageAustralia
 * @package    MageAustralia_AdminGrid
 * @copyright  Copyright (c) 2026 MageAustralia
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class MageAustralia_AdminGrid_Block_Adminhtml_Widget_Grid_Column_Renderer_Categories
    extends Mage_Adminhtml_Block_Widget_Grid_Column_Renderer_Abstract
{
    public function render(\Maho\DataObject $row): string
    {
        $value = $row->getData($this->getColumn()->getIndex());
        if ($value === null || $value === '') {
            return '';
        }

        return $this->escapeHtml((string) $value);
    }
}
