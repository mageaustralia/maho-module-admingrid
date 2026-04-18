<?php

declare(strict_types=1);

class MageAustralia_AdminGrid_Block_Adminhtml_Config_Grid_Renderer_ColumnCount extends Mage_Adminhtml_Block_Widget_Grid_Column_Renderer_Abstract
{
    #[\Override]
    public function render(\Maho\DataObject $row): string
    {
        $collection = Mage::getModel('mageaustralia_admingrid/column')
            ->getCollection();
        if ($collection === false) {
            return '0';
        }

        $count = $collection
            ->addActiveGridFilter((int) $row->getId())
            ->getSize();

        return (string) $count;
    }
}
