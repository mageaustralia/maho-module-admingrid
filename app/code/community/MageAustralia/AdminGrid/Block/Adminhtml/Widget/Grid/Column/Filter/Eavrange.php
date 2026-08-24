<?php

/**
 * Maho
 *
 * @package    MageAustralia_AdminGrid
 * @copyright  Copyright (c) 2026 Maho (https://mahocommerce.com)
 * @license    https://opensource.org/licenses/OSL-3.0 Open Software License v. 3.0 (OSL-3.0)
 */

declare(strict_types=1);

/**
 * From/to range filter for numeric EAV attributes (price, cost, weight).
 *
 * The plain EAV filter renders one box and matches a single value, which is the
 * wrong tool for money: nobody wants products priced exactly 49.95, they want
 * everything under 50. The core price filter renders the range but resolves
 * against a real table column, which an EAV attribute does not have - hence this
 * pairing of the core filter's markup with the EAV filter's EXISTS subquery.
 */
class MageAustralia_AdminGrid_Block_Adminhtml_Widget_Grid_Column_Filter_Eavrange extends MageAustralia_AdminGrid_Block_Adminhtml_Widget_Grid_Column_Filter_Eav
{
    #[\Override]
    public function getHtml()
    {
        $name = $this->_getHtmlName();
        $id   = $this->_getHtmlId();
        $from = $this->getRangePart('from');
        $to   = $this->getRangePart('to');

        return '<div class="range">'
            . '<div class="range-line"><span class="label">&ge;</span>'
            . '<input type="text" name="' . $name . '[from]" id="' . $id . '_from"'
            . ' value="' . $this->escapeHtml($from) . '" class="input-text no-changes"></div>'
            . '<div class="range-line"><span class="label">&le;</span>'
            . '<input type="text" name="' . $name . '[to]" id="' . $id . '_to"'
            . ' value="' . $this->escapeHtml($to) . '" class="input-text no-changes"></div>'
            . '</div>';
    }

    public function getRangePart(string $part): string
    {
        $value = $this->getValue();

        return is_array($value) ? trim((string) ($value[$part] ?? '')) : '';
    }

    /**
     * Applies the range directly to the collection and returns null, so the grid
     * does not also attempt addFieldToFilter on a column that isn't in the table.
     */
    #[\Override]
    public function getCondition(): ?array
    {
        $from = $this->getRangePart('from');
        $to   = $this->getRangePart('to');
        if ($from === '' && $to === '') {
            return null;
        }

        $sourceConfig = $this->getColumn()->getData('admingrid_source_config');
        $attrCode     = $sourceConfig['attribute_code'] ?? null;
        $entityType   = $sourceConfig['entity_type'] ?? 'catalog_product';
        if (!$attrCode) {
            return null;
        }

        $attribute = Mage::getSingleton('eav/config')->getAttribute($entityType, $attrCode);
        if (!$attribute || !$attribute->getId() || !$attribute->getBackendTable()) {
            return null;
        }

        $collection = $this->getColumn()->getGrid()->getCollection();
        if (!$collection) {
            return null;
        }

        $conn         = $collection->getConnection();
        $backendTable = $attribute->getBackendTable();
        $bounds       = [];

        if ($from !== '' && is_numeric($from)) {
            $bounds[] = '_eav.value >= ' . $conn->quote((float) $from);
        }
        if ($to !== '' && is_numeric($to)) {
            $bounds[] = '_eav.value <= ' . $conn->quote((float) $to);
        }
        if ($bounds === []) {
            return null;
        }

        $storeClause = $conn->tableColumnExists($backendTable, 'store_id')
            ? ' AND _eav.store_id = 0'
            : '';

        $subquery = sprintf('SELECT 1 FROM %s AS _eav', $backendTable)
            . ' WHERE _eav.entity_id = e.entity_id'
            . ' AND _eav.attribute_id = ' . (int) $attribute->getId()
            . $storeClause
            . ' AND ' . implode(' AND ', $bounds);

        $collection->getSelect()->where(sprintf('EXISTS (%s)', $subquery));

        return null;
    }
}
