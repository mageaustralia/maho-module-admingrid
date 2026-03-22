<?php

declare(strict_types=1);

class MageAustralia_AdminGrid_Block_Adminhtml_Config_Column_Edit extends Mage_Adminhtml_Block_Widget_Form_Container
{
    public function __construct()
    {
        $this->_objectId = 'column_id';
        $this->_controller = 'adminhtml_config_column';
        $this->_blockGroup = 'mageaustralia_admingrid';

        parent::__construct();

        $this->_updateButton('save', 'label', $this->__('Save Column'));
        $this->_updateButton('delete', 'label', $this->__('Delete Column'));

        $column = Mage::registry('admingrid_custom_column');
        if ($column && $column->getId()) {
            $this->_headerText = $this->__('Edit Custom Column: %s', $column->getData('header'));
        } else {
            $this->_headerText = $this->__('New Custom Column');
            $this->_removeButton('delete');
        }
    }

    public function getBackUrl(): string
    {
        $column = Mage::registry('admingrid_custom_column');
        $gridId = $column ? $column->getData('grid_id') : $this->getRequest()->getParam('grid_id');
        return $this->getUrl('*/*/columns', ['grid_id' => $gridId]);
    }

    public function getDeleteUrl(): string
    {
        return $this->getUrl('*/*/deleteColumn', [
            $this->_objectId => $this->getRequest()->getParam($this->_objectId),
        ]);
    }

    /**
     * Add JS for auto-fill after form renders.
     */
    protected function _afterToHtml($html)
    {
        $attrMap = Mage::registry('admingrid_attr_map');
        if (!$attrMap) {
            return parent::_afterToHtml($html);
        }

        $mapJson = json_encode($attrMap, JSON_UNESCAPED_UNICODE);

        $js = <<<JS
<script>
(function() {
    const attrMap = {$mapJson};
    const typeMap = {
        'text': 'text', 'textarea': 'text', 'varchar': 'text',
        'int': 'number', 'decimal': 'number', 'price': 'number',
        'date': 'date', 'datetime': 'date',
        'select': 'options', 'multiselect': 'options', 'boolean': 'options',
        'media_image': 'image', 'gallery': 'image',
    };

    const eavSelect = document.getElementById('eav_attribute_code');
    const codeField = document.getElementById('column_code');
    const headerField = document.getElementById('header');
    const typeField = document.getElementById('column_type');
    const configField = document.getElementById('source_config');
    const sourceField = document.getElementById('source_type');

    if (eavSelect) {
        eavSelect.addEventListener('change', function() {
            const code = this.value;
            const meta = attrMap[code];
            if (!meta) return;

            if (codeField) codeField.value = 'custom_' + code;
            if (headerField) headerField.value = meta.label;
            if (typeField) typeField.value = typeMap[meta.input] || 'text';
            if (configField) configField.value = JSON.stringify({
                attribute_code: code,
                entity_type: 'catalog_product'
            }, null, 2);
            if (sourceField) sourceField.value = 'eav_attribute';
        });
    }

    // Show/hide EAV selector based on source type
    function toggleEavField() {
        const row = eavSelect?.closest('tr') || eavSelect?.closest('.field-row');
        if (!row) return;
        row.style.display = sourceField?.value === 'eav_attribute' ? '' : 'none';
    }

    if (sourceField) {
        sourceField.addEventListener('change', toggleEavField);
        toggleEavField();
    }
})();
</script>
JS;

        return parent::_afterToHtml($html) . $js;
    }
}
