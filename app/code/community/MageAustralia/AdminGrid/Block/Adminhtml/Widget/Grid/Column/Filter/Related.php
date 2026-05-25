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

        // Re-validate every identifier before it reaches raw SQL — stored config
        // could predate write-time validation, so the render path must be self-safe.
        $helper = Mage::helper('mageaustralia_admingrid');
        $parsedJoin = $helper->parseJoinOn((string) $joinOn);
        if (!$parsedJoin || !$helper->isSafeIdentifier((string) $colName)) {
            return null;
        }

        [$localCol, $remoteCol] = $parsedJoin;

        $collection = $this->getColumn()->getGrid()->getCollection();
        if (!$collection) {
            return null;
        }

        $resource = Mage::getSingleton('core/resource');
        $table = $resource->getTableName($relatedTable);
        $conn = $collection->getConnection();

        // Text LIKE filter — identifiers quoted, value parameter-quoted.
        $valueCond = $conn->quoteIdentifier('_rel.' . $colName) . ' LIKE ' . $conn->quote('%' . $value . '%');

        $subquery = 'SELECT 1 FROM ' . $conn->quoteIdentifier($table) . ' AS _rel'
            . ' WHERE ' . $conn->quoteIdentifier('_rel.' . $remoteCol)
            . ' = ' . $conn->quoteIdentifier('main_table.' . $localCol)
            . (' AND ' . $valueCond);

        $collection->getSelect()->where(sprintf('EXISTS (%s)', $subquery));

        return null;
    }
}
