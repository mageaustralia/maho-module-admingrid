<?php

declare(strict_types=1);

class MageAustralia_AdminGrid_Block_Adminhtml_Config_Column_Edit_Form extends Mage_Adminhtml_Block_Widget_Form
{
    protected function _prepareForm(): self
    {
        $column = Mage::registry('admingrid_custom_column');
        $gridId = $column && $column->getData('grid_id')
            ? $column->getData('grid_id')
            : $this->getRequest()->getParam('grid_id');

        $form = new Varien_Data_Form([
            'id'      => 'edit_form',
            'action'  => $this->getUrl('*/*/saveColumn'),
            'method'  => 'post',
        ]);

        $fieldset = $form->addFieldset('base', [
            'legend' => $this->__('Column Settings'),
        ]);

        $fieldset->addField('grid_id', 'hidden', [
            'name'  => 'grid_id',
            'value' => $gridId,
        ]);

        if ($column && $column->getId()) {
            $fieldset->addField('column_id', 'hidden', [
                'name'  => 'column_id',
                'value' => $column->getId(),
            ]);
        }

        $fieldset->addField('source_type', 'select', [
            'name'     => 'source_type',
            'label'    => $this->__('Data Source'),
            'required' => true,
            'values'   => [
                ['value' => 'eav_attribute', 'label' => $this->__('EAV Attribute (auto-discovered)')],
                ['value' => 'static',        'label' => $this->__('Static (from collection)')],
                ['value' => 'computed',       'label' => $this->__('Computed')],
            ],
            'note' => $this->__('EAV = post-load hydration (no JOINs, safe for large tables)'),
        ]);

        // EAV attribute selector — auto-discovered from catalog_product
        $fieldset->addField('eav_attribute_code', 'select', [
            'name'   => 'eav_attribute_code',
            'label'  => $this->__('EAV Attribute'),
            'values' => $this->getProductAttributeOptions(),
            'note'   => $this->__('Select an attribute — code, header, and type will auto-fill'),
        ]);

        $fieldset->addField('column_code', 'text', [
            'name'     => 'column_code',
            'label'    => $this->__('Column Code'),
            'title'    => $this->__('Column Code'),
            'required' => true,
            'note'     => $this->__('Unique identifier. Auto-filled for EAV attributes.'),
        ]);

        $fieldset->addField('header', 'text', [
            'name'     => 'header',
            'label'    => $this->__('Header Label'),
            'title'    => $this->__('Header Label'),
            'required' => true,
        ]);

        $fieldset->addField('column_type', 'select', [
            'name'     => 'column_type',
            'label'    => $this->__('Column Type'),
            'required' => true,
            'values'   => [
                ['value' => 'text',    'label' => $this->__('Text')],
                ['value' => 'number',  'label' => $this->__('Number')],
                ['value' => 'date',    'label' => $this->__('Date')],
                ['value' => 'options', 'label' => $this->__('Options (Dropdown)')],
                ['value' => 'image',   'label' => $this->__('Image / Thumbnail')],
            ],
        ]);

        $fieldset->addField('source_config', 'textarea', [
            'name'  => 'source_config',
            'label' => $this->__('Source Config (JSON)'),
            'note'  => $this->__('Auto-filled for EAV. For computed columns, provide custom JSON.'),
            'style' => 'font-family:monospace; height:80px;',
        ]);

        $fieldset->addField('sort_order', 'text', [
            'name'  => 'sort_order',
            'label' => $this->__('Sort Order'),
            'value' => '0',
            'class' => 'validate-number',
        ]);

        $fieldset->addField('is_active', 'select', [
            'name'   => 'is_active',
            'label'  => $this->__('Active'),
            'values' => [
                ['value' => '1', 'label' => $this->__('Yes')],
                ['value' => '0', 'label' => $this->__('No')],
            ],
        ]);

        if ($column && $column->getData()) {
            $data = $column->getData();
            // Pre-select the EAV attribute if editing an EAV column
            if ($column->getData('source_type') === 'eav_attribute') {
                $sourceConfig = $column->getSourceConfig();
                $data['eav_attribute_code'] = $sourceConfig['attribute_code'] ?? '';
            }
            $form->setValues($data);
        }

        $form->setUseContainer(true);
        $this->setForm($form);

        return parent::_prepareForm();
    }

    /**
     * Get product EAV attributes as option array, grouped by group.
     */
    private function getProductAttributeOptions(): array
    {
        $options = [['value' => '', 'label' => $this->__('-- Select Attribute --')]];

        $entityType = Mage::getModel('eav/entity_type')->loadByCode('catalog_product');
        $collection = Mage::getResourceModel('catalog/product_attribute_collection')
            ->addVisibleFilter()
            ->setOrder('frontend_label', 'ASC');

        // Build attribute map with metadata for JS auto-fill
        $attrMap = [];
        foreach ($collection as $attr) {
            $code = $attr->getAttributeCode();
            $label = $attr->getFrontendLabel() ?: $code;
            $input = $attr->getFrontendInput();

            $options[] = [
                'value' => $code,
                'label' => sprintf('%s (%s) [%s]', $label, $code, $input),
            ];

            $attrMap[$code] = [
                'label' => $label,
                'input' => $input,
            ];
        }

        // Store attribute metadata for JS — injected via a script block
        Mage::register('admingrid_attr_map', $attrMap, true);

        return $options;
    }
}
