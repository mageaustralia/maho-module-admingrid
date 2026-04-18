<?php

declare(strict_types=1);

/**
 * Thumbnail image renderer for grid columns.
 * Used for product thumbnail custom columns.
 */
class MageAustralia_AdminGrid_Block_Adminhtml_Widget_Grid_Column_Renderer_Image extends Mage_Adminhtml_Block_Widget_Grid_Column_Renderer_Abstract
{
    #[\Override]
    public function render(\Maho\DataObject $row): string
    {
        $value = $row->getData($this->getColumn()->getIndex());
        if (!$value || $value === 'no_selection') {
            return '';
        }

        $mediaUrl = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA);
        $src = $mediaUrl . 'catalog/product' . $value;

        return sprintf(
            '<img src="%s" alt="" style="max-width:60px;max-height:60px;object-fit:contain;" loading="lazy">',
            htmlspecialchars($src),
        );
    }
}
