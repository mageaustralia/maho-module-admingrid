<?php

declare(strict_types=1);

/**
 * Grid listing all discovered admin grids.
 */
class MageAustralia_AdminGrid_Block_Adminhtml_Config_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('admingridConfigGrid');
        $this->setDefaultSort('grid_id');
        $this->setDefaultDir('ASC');
        $this->setSaveParametersInSession(true);
    }

    protected function _prepareCollection(): self
    {
        $collection = Mage::getModel('mageaustralia_admingrid/grid')->getCollection();
        $this->setCollection($collection);
        return parent::_prepareCollection();
    }

    protected function _prepareColumns(): self
    {
        $this->addColumn('grid_id', [
            'header' => $this->__('ID'),
            'index'  => 'grid_id',
            'type'   => 'number',
            'width'  => '60px',
        ]);

        $this->addColumn('grid_block_id', [
            'header' => $this->__('Grid Block ID'),
            'index'  => 'grid_block_id',
        ]);

        $this->addColumn('block_type', [
            'header' => $this->__('Block Type'),
            'index'  => 'block_type',
        ]);

        $this->addColumn('created_at', [
            'header' => $this->__('First Seen'),
            'index'  => 'created_at',
            'type'   => 'datetime',
            'width'  => '160px',
        ]);

        $this->addColumn('custom_columns', [
            'header'   => $this->__('Custom Columns'),
            'renderer' => 'mageaustralia_admingrid/adminhtml_config_grid_renderer_columnCount',
            'sortable' => false,
            'filter'   => false,
            'width'    => '120px',
            'align'    => 'center',
        ]);

        $this->addColumn('action', [
            'header'   => $this->__('Action'),
            'type'     => 'action',
            'getter'   => 'getId',
            'actions'  => [
                [
                    'caption' => $this->__('Manage Columns'),
                    'url'     => ['base' => '*/*/columns'],
                    'field'   => 'grid_id',
                ],
            ],
            'sortable' => false,
            'filter'   => false,
            'width'    => '120px',
        ]);

        return parent::_prepareColumns();
    }

    public function getRowUrl($row): string
    {
        return $this->getUrl('*/*/columns', ['grid_id' => $row->getId()]);
    }
}
