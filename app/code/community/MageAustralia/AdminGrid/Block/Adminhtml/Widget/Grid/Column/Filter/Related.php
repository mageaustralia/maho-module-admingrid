<?php

declare(strict_types=1);

/**
 * Custom filter for related table columns.
 * Applies EXISTS subquery directly to the collection select.
 */
class MageAustralia_AdminGrid_Block_Adminhtml_Widget_Grid_Column_Filter_Related
    extends Mage_Adminhtml_Block_Widget_Grid_Column_Filter_Text
{
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

        $joinParts = explode('=', $joinOn);
        if (count($joinParts) !== 2) {
            return null;
        }

        $localCol = trim($joinParts[0]);
        $remoteCol = trim($joinParts[1]);

        // Text LIKE filter
        $valueCond = "_rel.{$colName} LIKE " . $conn->quote('%' . $value . '%');

        $subquery = "SELECT 1 FROM {$table} AS _rel"
            . " WHERE _rel.{$remoteCol} = main_table.{$localCol}"
            . " AND {$valueCond}";

        $collection->getSelect()->where("EXISTS ({$subquery})");

        return null;
    }
}
