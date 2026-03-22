<?php

declare(strict_types=1);

/**
 * Grid listing custom columns for a specific admin grid.
 */
class MageAustralia_AdminGrid_Block_Adminhtml_Config_Columns_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('admingridColumnsGrid');
        $this->setDefaultSort('sort_order');
        $this->setDefaultDir('ASC');
    }

    protected function _prepareCollection(): self
    {
        $gridId = (int) $this->getRequest()->getParam('grid_id');
        $collection = Mage::getModel('mageaustralia_admingrid/column')
            ->getCollection()
            ->addFieldToFilter('grid_id', $gridId);

        $this->setCollection($collection);
        return parent::_prepareCollection();
    }

    protected function _prepareColumns(): self
    {
        $this->addColumn('column_id', [
            'header' => $this->__('ID'),
            'index'  => 'column_id',
            'type'   => 'number',
            'width'  => '60px',
        ]);

        $this->addColumn('column_code', [
            'header' => $this->__('Code'),
            'index'  => 'column_code',
        ]);

        $this->addColumn('header', [
            'header' => $this->__('Header Label'),
            'index'  => 'header',
        ]);

        $this->addColumn('column_type', [
            'header'  => $this->__('Type'),
            'index'   => 'column_type',
            'type'    => 'options',
            'options' => [
                'text'    => 'Text',
                'number'  => 'Number',
                'date'    => 'Date',
                'options' => 'Options',
                'image'   => 'Image',
            ],
            'width' => '100px',
        ]);

        $this->addColumn('source_type', [
            'header'  => $this->__('Source'),
            'index'   => 'source_type',
            'type'    => 'options',
            'options' => [
                'eav_attribute' => 'EAV Attribute',
                'static'        => 'Static',
                'computed'       => 'Computed',
            ],
            'width' => '120px',
        ]);

        $this->addColumn('is_active', [
            'header'  => $this->__('Active'),
            'index'   => 'is_active',
            'type'    => 'options',
            'options' => [0 => 'No', 1 => 'Yes'],
            'width'   => '80px',
        ]);

        $this->addColumn('sort_order', [
            'header' => $this->__('Sort Order'),
            'index'  => 'sort_order',
            'type'   => 'number',
            'width'  => '80px',
        ]);

        $this->addColumn('action', [
            'header'   => $this->__('Action'),
            'type'     => 'action',
            'getter'   => 'getId',
            'actions'  => [
                [
                    'caption' => $this->__('Edit'),
                    'url'     => ['base' => '*/*/editColumn'],
                    'field'   => 'column_id',
                ],
                [
                    'caption' => $this->__('Delete'),
                    'url'     => ['base' => '*/*/deleteColumn'],
                    'field'   => 'column_id',
                    'confirm' => $this->__('Are you sure?'),
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
        return $this->getUrl('*/*/editColumn', ['column_id' => $row->getId()]);
    }
}
