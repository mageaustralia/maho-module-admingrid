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

        $form = new \Maho\Data\Form([
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
                ['value' => 'stock_managed', 'label' => $this->__('Stock: Manage Stock (effective)')],
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
                ['value' => 'price',   'label' => $this->__('Price (currency + range filter)')],
                ['value' => 'date',    'label' => $this->__('Date')],
                ['value' => 'datetime', 'label' => $this->__('Date & Time')],
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

        // Archived options: only meaningful for an existing EAV column whose attribute has
        // options. Stored per attribute (shared by every column showing it), not per column.
        $archivedField = $this->getArchivedOptionsField($column);
        if ($archivedField !== null) {
            $fieldset->addField('archived_options_submitted', 'hidden', [
                'name'  => 'archived_options_submitted',
                'value' => '1',
            ]);
            $fieldset->addField('archived_options', 'multiselect', $archivedField);
        }

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

        $fieldset->addField('is_editable', 'select', [
            'name'   => 'is_editable',
            'label'  => $this->__('Editable'),
            'note'   => $this->__('Allow inline editing of this column\'s cells in the grid (EAV attributes only).'),
            'values' => [
                ['value' => '0', 'label' => $this->__('No')],
                ['value' => '1', 'label' => $this->__('Yes')],
            ],
        ]);

        if ($column && $column->getData()) {
            $data = $column->getData();
            // Pre-select the EAV attribute if editing an EAV column
            if ($column->getData('source_type') === 'eav_attribute') {
                $sourceConfig = $column->getSourceConfig();
                $data['eav_attribute_code'] = $sourceConfig['attribute_code'] ?? '';
                if ($archivedField !== null) {
                    $data['archived_options'] = $archivedField['value'];
                }
            }

            $form->setValues($data);
        }

        $form->setUseContainer(true);
        $this->setForm($form);

        return parent::_prepareForm();
    }

    /**
     * Multiselect config for the archived options of this column's attribute, or null when
     * the column is new, not an EAV column, or the attribute has no option list.
     *
     * @return array<string, mixed>|null
     */
    private function getArchivedOptionsField(?MageAustralia_AdminGrid_Model_Column $column): ?array
    {
        if (!$column || !$column->getId() || $column->getData('source_type') !== 'eav_attribute') {
            return null;
        }

        $sourceConfig = $column->getSourceConfig();
        $attrCode = (string) ($sourceConfig['attribute_code'] ?? '');
        $entityType = (string) ($sourceConfig['entity_type'] ?? 'catalog_product');
        if ($attrCode === '') {
            return null;
        }

        $attribute = Mage::getSingleton('eav/config')->getAttribute($entityType, $attrCode);
        if (!$attribute || !$attribute->getId() || !$attribute->usesSource()) {
            return null;
        }

        $values = [];
        /** @phpstan-ignore arguments.count */
        foreach ($attribute->getSource()->getAllOptions(false) as $option) {
            $values[] = ['value' => $option['value'], 'label' => $option['label']];
        }

        if ($values === []) {
            return null;
        }

        return [
            'name'   => 'archived_options[]',
            'label'  => $this->__('Archived Options'),
            'values' => $values,
            'value'  => Mage::helper('mageaustralia_admingrid')->getArchivedOptionIds($entityType, $attrCode),
            'note'   => $this->__('Hidden from the main filter list and shown under "Archived" instead. Existing data is unaffected. Applies to every grid showing this attribute.'),
            'style'  => 'height:200px;',
        ];
    }

    /**
     * Get product EAV attributes as option array, grouped by group.
     */
    private function getProductAttributeOptions(): array
    {
        $options = [['value' => '', 'label' => $this->__('-- Select Attribute --')]];

        Mage::getModel('eav/entity_type')->loadByCode('catalog_product');
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
