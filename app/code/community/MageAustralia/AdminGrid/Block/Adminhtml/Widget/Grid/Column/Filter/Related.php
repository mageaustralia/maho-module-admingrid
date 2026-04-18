<?php

declare(strict_types=1);

/**
 * Custom filter for related table columns.
 * Applies EXISTS subquery directly to the collection select.
 */
class MageAustralia_AdminGrid_Block_Adminhtml_Widget_Grid_Column_Filter_Related extends Mage_Adminhtml_Block_Widget_Grid_Column_Filter_Text
{
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

        $colName = $sourceConfig['column_name'] ?? null;
        $relatedTable = $sourceConfig['related_table'] ?? null;
        $joinOn = $sourceConfig['join_on'] ?? null;

        if (!$colName || !$relatedTable || !$joinOn) {
            return null;
        }

        $collection = $this->getColumn()->getGrid()->getCollection();
        if (!$collection) {
            return null;
        }

        $resource = Mage::getSingleton('core/resource');
        $table = $resource->getTableName($relatedTable);
        $conn = $collection->getConnection();

        $joinParts = explode('=', (string) $joinOn);
        if (count($joinParts) !== 2) {
            return null;
        }

        $localCol = trim($joinParts[0]);
        $remoteCol = trim($joinParts[1]);

        // Text LIKE filter
        $valueCond = sprintf('_rel.%s LIKE ', $colName) . $conn->quote('%' . $value . '%');

        $subquery = sprintf('SELECT 1 FROM %s AS _rel', $table)
            . sprintf(' WHERE _rel.%s = main_table.%s', $remoteCol, $localCol)
            . (' AND ' . $valueCond);

        $collection->getSelect()->where(sprintf('EXISTS (%s)', $subquery));

        return null;
    }
}
