<?php

declare(strict_types=1);

/**
 * Admin Grid configuration — lists all discovered grids with their custom columns.
 */
class MageAustralia_AdminGrid_Block_Adminhtml_Config extends Mage_Adminhtml_Block_Widget_Grid_Container
{
    public function __construct()
    {
        $this->_controller = 'adminhtml_config';
        $this->_blockGroup = 'mageaustralia_admingrid';
        $this->_headerText = Mage::helper('mageaustralia_admingrid')->__('Admin Grid Configuration');
        parent::__construct();
        $this->_removeButton('add');
    }
}
