<?php

/**
 * Maho
 *
 * @category   MageAustralia
 * @package    MageAustralia_AdminGrid
 * @copyright  Copyright (c) 2026 Maho (https://mahocommerce.com) & MageAustralia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/OSL-3.0 Open Software License v. 3.0 (OSL-3.0)
 */

declare(strict_types=1);

/**
 * Yes/No filter for the effective "Manage Stock" column.
 *
 * Stock management is not a single column: an item follows the global default
 * when use_config_manage_stock = 1, and only overrides it with its own
 * manage_stock when that flag is 0. Filtering on manage_stock alone would be
 * wrong for every row that defers to config - on this store that is 36 products
 * whose stored manage_stock is 1 while stock is genuinely unmanaged.
 *
 * The condition resolves to a subselect of product_ids rather than an SQL
 * expression on the column, because the product grid runs on an EAV collection
 * and those cannot take a Maho\Db\Expr in setOrder()/addFieldToFilter (see the
 * note on EAV columns in the observer). Filtering entity_id IN (...) is
 * something the EAV collection handles natively, so display and filter agree.
 */
class MageAustralia_AdminGrid_Block_Adminhtml_Widget_Grid_Column_Filter_Stockmanaged extends Mage_Adminhtml_Block_Widget_Grid_Column_Filter_Select
{
    #[\Override]
    protected function _getOptions(): array
    {
        return [
            ['label' => '', 'value' => ''],
            ['label' => Mage::helper('mageaustralia_admingrid')->__('Yes'), 'value' => '1'],
            ['label' => Mage::helper('mageaustralia_admingrid')->__('No'), 'value' => '0'],
        ];
    }

    /**
     * @return array{in: list<int>}|null
     */
    #[\Override]
    public function getCondition()
    {
        $value = $this->getValue();
        if ($value === null || $value === '') {
            return null;
        }

        $wantManaged = (bool) (int) $value;
        $configDefault = (bool) Mage::getStoreConfig(
            Mage_CatalogInventory_Model_Stock_Item::XML_PATH_MANAGE_STOCK,
        );

        $resource = Mage::getSingleton('core/resource');
        $read = $resource->getConnection('core_read');

        $select = $read->select()
            ->from(['si' => $resource->getTableName('cataloginventory/stock_item')], ['product_id'])
            ->where('si.stock_id = ?', 1);

        // Effective value = use_config ? global default : the item's own flag.
        if ($configDefault) {
            // Default ON: managed unless the item overrides to 0.
            $condition = 'si.use_config_manage_stock = 1 OR si.manage_stock = 1';
        } else {
            // Default OFF: managed only where the item overrides to 1.
            $condition = 'si.use_config_manage_stock = 0 AND si.manage_stock = 1';
        }

        $select->where($wantManaged ? $condition : 'NOT (' . $condition . ')');

        // Resolve to concrete IDs: passing the Select through addFieldToFilter()
        // silently matched nothing on the EAV product collection.
        $ids = $read->fetchCol($select);

        return ['in' => $ids ?: [0]];
    }
}
