<?php

declare(strict_types=1);

/**
 * Composite column renderer — displays multiple field values stacked in a single cell.
 * Used for address columns (Ship to Name = firstname + lastname + street + postcode).
 */
class MageAustralia_AdminGrid_Block_Adminhtml_Widget_Grid_Column_Renderer_Composite
    extends Mage_Adminhtml_Block_Widget_Grid_Column_Renderer_Abstract
{
    public function render(Varien_Object $row): string
    {
        $colIndex = $this->getColumn()->getIndex();
        $data = $row->getData($colIndex);

        if (!$data || !is_array($data)) {
            return '';
        }

        // Filter out empty values and render as stacked lines
        $lines = array_filter($data, fn($v) => $v !== null && $v !== '');

        if (empty($lines)) {
            return '';
        }

        return implode('<br>', array_map('htmlspecialchars', $lines));
    }
}
