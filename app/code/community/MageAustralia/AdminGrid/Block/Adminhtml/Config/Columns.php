<?php

declare(strict_types=1);

/**
 * Custom columns management for a specific grid.
 */
class MageAustralia_AdminGrid_Block_Adminhtml_Config_Columns extends Mage_Adminhtml_Block_Widget_Grid_Container
{
    public function __construct()
    {
        $this->_controller = 'adminhtml_config_columns';
        $this->_blockGroup = 'mageaustralia_admingrid';

        $gridId = (int) Mage::app()->getRequest()->getParam('grid_id');
        $grid = Mage::getModel('mageaustralia_admingrid/grid')->load($gridId);
        $blockId = $grid->getId() ? $grid->getData('grid_block_id') : '(unknown)';

        $this->_headerText = Mage::helper('mageaustralia_admingrid')->__(
            'Custom Columns — %s',
            $blockId,
        );

        parent::__construct();

        $this->_updateButton('add', 'label', $this->__('Add Custom Column'));
        $this->_updateButton('add', 'onclick', sprintf(
            "setLocation('%s')",
            $this->getUrl('*/*/newColumn', ['grid_id' => $gridId]),
        ));

        $this->_addButton('back', [
            'label'   => $this->__('Back'),
            'onclick' => sprintf("setLocation('%s')", $this->getUrl('*/*/index')),
            'class'   => 'back',
        ], -1, 0);
    }
}
