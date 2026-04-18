<?php

declare(strict_types=1);

/**
 * Custom filter for EAV attribute columns.
 * Applies EXISTS subquery directly to the collection select,
 * bypassing addFieldToFilter (which can't handle Expr filter_index).
 */
class MageAustralia_AdminGrid_Block_Adminhtml_Widget_Grid_Column_Filter_Eav extends Mage_Adminhtml_Block_Widget_Grid_Column_Filter_Select
{
    /**
     * Return null to prevent the grid from calling addFieldToFilter.
     * Instead, we apply the filter directly in getCondition via the collection.
     */
    #[\Override]
    public function getCondition(): ?array
    {
        $value = $this->getValue();
        if ($value === null || $value === '') {
            return null;
        }

        $sourceConfig = $this->getColumn()->getData('admingrid_source_config');
        if (!$sourceConfig) {
            return null;
        }

        $attrCode = $sourceConfig['attribute_code'] ?? null;
        $entityType = $sourceConfig['entity_type'] ?? 'catalog_product';
        if (!$attrCode) {
            return null;
        }

        $attribute = Mage::getSingleton('eav/config')->getAttribute($entityType, $attrCode);
        if (!$attribute || !$attribute->getId() || !$attribute->getBackendTable()) {
            return null;
        }

        // Apply the filter directly to the collection's select
        $collection = $this->getColumn()->getGrid()->getCollection();
        if (!$collection) {
            return null;
        }

        $backendTable = $attribute->getBackendTable();
        $attrId = (int) $attribute->getId();
        $conn = $collection->getConnection();

        $backendType = $attribute->getBackendType();
        if (in_array($backendType, ['int', 'decimal']) || $attribute->usesSource()) {
            $valueCond = '_eav.value = ' . $conn->quote($value);
        } else {
            $valueCond = '_eav.value LIKE ' . $conn->quote('%' . $value . '%');
        }

        $storeClause = $conn->tableColumnExists($backendTable, 'store_id')
            ? ' AND _eav.store_id = 0'
            : '';

        $subquery = sprintf('SELECT 1 FROM %s AS _eav', $backendTable)
            . ' WHERE _eav.entity_id = e.entity_id'
            . (' AND _eav.attribute_id = ' . $attrId)
            . $storeClause
            . (' AND ' . $valueCond);

        $collection->getSelect()->where(sprintf('EXISTS (%s)', $subquery));

        // Return null so the grid doesn't also call addFieldToFilter
        return null;
    }
}
