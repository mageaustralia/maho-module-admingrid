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
     * "Has any value" / "Has no value" - presence rather than a specific option.
     *
     * Sentinels rather than real option ids, so they can never collide with an
     * attribute's own options (those are numeric). They live in the FILTER's option
     * list only, not the column's: the column's options also drive the cell renderer,
     * and a row can never hold one of these as data.
     */
    public const FILTER_HAS_ANY = 'has_any';
    public const FILTER_HAS_NONE = 'has_none';

    /**
     * The parent's list (blank first, then the attribute's options) with the two
     * presence choices directly under the blank, where they are found without
     * scrolling a long list such as Custom Stock Status.
     *
     * @return array<int, array{value: mixed, label: string}>
     */
    #[\Override]
    protected function _getOptions()
    {
        $options = parent::_getOptions();
        if ($options === []) {
            return $options;
        }

        $helper = Mage::helper('mageaustralia_admingrid');
        $presence = [
            ['value' => self::FILTER_HAS_ANY, 'label' => $helper->__('Has any value')],
            ['value' => self::FILTER_HAS_NONE, 'label' => $helper->__('Has no value')],
        ];

        // Index 0 is the blank "no filter" entry the parent prepends.
        array_splice($options, 1, 0, $presence);

        return $options;
    }

    /**
     * Render a plain text input unless the attribute genuinely has options.
     *
     * The parent is the Select filter, so every EAV column inherited a <select>.
     * For a free-text attribute (gtin, netsuite_id) that came out as an empty,
     * unusable dropdown. getCondition() below already does a LIKE for exactly
     * these attributes -- only the rendering was ever wrong.
     */
    #[\Override]
    public function getHtml()
    {
        $options = $this->getColumn()->getOptions();
        if (is_array($options) && count($options) > 0) {
            return parent::getHtml();
        }

        return '<div class="field-100"><input type="text" name="' . $this->_getHtmlName()
            . '" id="' . $this->_getHtmlId()
            . '" value="' . $this->getEscapedValue()
            . '" class="input-text no-changes"></div>';
    }

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
        $isPresence = in_array($value, [self::FILTER_HAS_ANY, self::FILTER_HAS_NONE], true);
        if ($isPresence) {
            // An attribute with nothing set usually has no row at all, but a cleared
            // one can leave a NULL behind, and a text one an empty string. Neither
            // counts as "has a value". The empty-string test is only meaningful (and
            // only valid) on character columns.
            $valueCond = in_array($backendType, ['varchar', 'text'], true)
                ? "(_eav.value IS NOT NULL AND _eav.value <> '')"
                : '_eav.value IS NOT NULL';
        } elseif (in_array($backendType, ['int', 'decimal']) || $attribute->usesSource()) {
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

        // "Has no value" is the same test negated: no row for this product carrying one.
        $exists = $value === self::FILTER_HAS_NONE ? 'NOT EXISTS' : 'EXISTS';
        $collection->getSelect()->where(sprintf('%s (%s)', $exists, $subquery));

        // Return null so the grid doesn't also call addFieldToFilter
        return null;
    }
}
