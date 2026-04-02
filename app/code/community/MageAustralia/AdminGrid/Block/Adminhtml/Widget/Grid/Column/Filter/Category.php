<?php

declare(strict_types=1);

/**
 * Category tree filter for product grid columns.
 *
 * Renders a read-only text field + button that opens a category tree popup.
 * Selected category IDs are stored in a hidden input. On filter submit,
 * applies an EXISTS subquery against catalog_category_product.
 *
 * @category   MageAustralia
 * @package    MageAustralia_AdminGrid
 * @copyright  Copyright (c) 2026 MageAustralia
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class MageAustralia_AdminGrid_Block_Adminhtml_Widget_Grid_Column_Filter_Category
    extends Mage_Adminhtml_Block_Widget_Grid_Column_Filter_Abstract
{
    public function getHtml(): string
    {
        $htmlId = $this->escapeHtml($this->_getHtmlId());
        $htmlName = $this->escapeHtml($this->_getHtmlName());
        $value = $this->escapeHtml((string) $this->getValue());

        return '<div class="admingrid-category-filter">'
            . '<input type="text" readonly class="admingrid-category-display input-text"'
            . ' id="' . $htmlId . '_display" value="" placeholder="All categories"'
            . ' onclick="AdminGridCategoryFilter.open(\'' . $htmlId . '\')" />'
            . '<input type="hidden" name="' . $htmlName . '" id="' . $htmlId . '"'
            . ' value="' . $value . '" />'
            . '<button type="button" class="admingrid-category-btn scalable"'
            . ' onclick="AdminGridCategoryFilter.open(\'' . $htmlId . '\')"'
            . ' title="Choose categories">&hellip;</button>'
            . '</div>';
    }

    /**
     * Apply EXISTS subquery filter against catalog_category_product.
     * Expands selected categories to include all descendants.
     * Returns null to prevent grid's own addFieldToFilter call.
     */
    public function getCondition(): ?array
    {
        $value = $this->getValue();
        if ($value === null || $value === '') {
            return null;
        }

        $categoryIds = array_filter(array_map('intval', explode(',', (string) $value)));
        if (empty($categoryIds)) {
            return null;
        }

        $collection = $this->getColumn()->getGrid()->getCollection();
        if (!$collection) {
            return null;
        }

        // Expand selected categories to include all descendants (single query)
        $resource = Mage::getSingleton('core/resource');
        $cceTable = $resource->getTableName('catalog/category');
        $conn = $collection->getConnection();

        $allIds = $categoryIds;
        $paths = $conn->fetchCol(
            $conn->select()
                ->from($cceTable, ['path'])
                ->where('entity_id IN (?)', $categoryIds)
        );

        if (!empty($paths)) {
            $orConditions = [];
            foreach ($paths as $path) {
                $orConditions[] = $conn->quoteInto('path LIKE ?', $path . '/%');
            }
            $descendantIds = $conn->fetchCol(
                $conn->select()
                    ->from($cceTable, ['entity_id'])
                    ->where(implode(' OR ', $orConditions))
            );
            $allIds = array_merge($allIds, $descendantIds);
        }

        $allIds = array_unique(array_map('intval', $allIds));
        $ccpTable = $resource->getTableName('catalog/category_product');
        $idList = implode(',', $allIds);

        // Determine primary key alias from the collection's FROM clause
        $pkField = 'e.entity_id';
        $fromPart = $collection->getSelect()->getPart(\Maho\Db\Select::FROM);
        if (isset($fromPart['e'])) {
            $pkField = 'e.entity_id';
        } elseif (isset($fromPart['main_table'])) {
            $pkField = 'main_table.entity_id';
        }

        $subquery = "SELECT 1 FROM {$ccpTable} AS _ccp"
            . " WHERE _ccp.product_id = {$pkField}"
            . " AND _ccp.category_id IN ({$idList})";

        $collection->getSelect()->where("EXISTS ({$subquery})");

        return null;
    }
}
