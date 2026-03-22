<?php

declare(strict_types=1);

class MageAustralia_AdminGrid_Block_Adminhtml_Config_Grid_Renderer_ColumnCount
    extends Mage_Adminhtml_Block_Widget_Grid_Column_Renderer_Abstract
{
    public function render(Varien_Object $row): string
    {
        $count = Mage::getModel('mageaustralia_admingrid/column')
            ->getCollection()
            ->addActiveGridFilter((int) $row->getId())
            ->getSize();

        return (string) $count;
    }
}
