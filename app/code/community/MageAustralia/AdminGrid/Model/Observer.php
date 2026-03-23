<?php

declare(strict_types=1);

class MageAustralia_AdminGrid_Model_Observer
{
    /**
     * Per-request cache of EAV attribute options keyed by attribute_id.
     * Avoids repeated getAllOptions() calls when grids render multiple times (e.g. ShipEasy).
     *
     * @var array<int, array<string, string>>
     */
    private static array $_optionsCache = [];
    /**
     * Apply user's grid profile after columns are defined.
     *
     * Event: admingrid_prepare_columns_after
     */
    public function onGridPrepareColumnsAfter(Varien_Event_Observer $observer): void
    {
        try {
            $this->_doGridPrepareColumnsAfter($observer);
        } catch (\Exception $e) {
            Mage::logException($e);
        }
    }

    private function _doGridPrepareColumnsAfter(Varien_Event_Observer $observer): void
    {
        /** @var Mage_Adminhtml_Block_Widget_Grid $grid */
        $grid = $observer->getEvent()->getGrid();
        $gridBlockId = $observer->getEvent()->getGridBlockId();

        if (!$gridBlockId) {
            return;
        }

        $helper = Mage::helper('mageaustralia_admingrid');
        if (!$helper->isEnabled()) {
            return;
        }

        $userId = Mage::getSingleton('admin/session')->getUser()?->getId();
        if (!$userId) {
            return;
        }

        // Auto-register grid if first encounter
        $gridModel = $this->getOrCreateGrid($grid, $gridBlockId);

        // Pass grid metadata to JS
        $grid->setData('admingrid_grid_id', $gridModel->getId());
        $grid->setData('admingrid_user_id', $userId);

        // Add custom columns (Phase 4) and register for EAV hydration
        $customColumns = $this->addCustomColumns($grid, $gridModel);
        if (!empty($customColumns)) {
            $this->registerEavHydration($grid, $customColumns);
        }

        // Load active profile
        $profile = Mage::getModel('mageaustralia_admingrid/profile')
            ->loadActiveForUser((int) $gridModel->getId(), (int) $userId);

        if (!$profile->getId()) {
            return;
        }

        $grid->setData('admingrid_profile_id', $profile->getId());
        $config = $profile->getColumnConfig();
        if (empty($config)) {
            return;
        }

        $this->applyColumnConfig($grid, $config);
    }

    /**
     * Add custom columns defined in the database to the grid.
     * Does NOT add JOINs — EAV values are hydrated post-load.
     *
     * @return MageAustralia_AdminGrid_Model_Column[]
     */
    private function addCustomColumns(
        Mage_Adminhtml_Block_Widget_Grid $grid,
        MageAustralia_AdminGrid_Model_Grid $gridModel,
    ): array {
        $customColumns = Mage::getModel('mageaustralia_admingrid/column')
            ->getCollection()
            ->addActiveGridFilter((int) $gridModel->getId());

        if ($customColumns->getSize() === 0) {
            return [];
        }

        $added = [];
        $afterColumn = $this->getLastNativeColumnCode($grid);

        foreach ($customColumns as $customCol) {
            $code = $customCol->getData('column_code');

            // Skip if column already exists natively
            if ($grid->getColumn($code)) {
                continue;
            }

            $sourceType = $customCol->getData('source_type');
            $columnType = $this->mapColumnType($customCol->getData('column_type'));
            $filterIndex = $code;
            $sortable = false;
            $isEav = false;
            $options = null;
            $filterClass = false; // false = no filter

            if ($sourceType === 'eav_attribute') {
                // EAV: custom filter class, post-load hydration
                // Note: correlated subquery sorting disabled — EAV collections can't handle
                // Expr objects in setOrder() (crashes in _getMappedField).
                // Sorting by EAV columns requires a future approach (e.g. temp table).
                $isEav = true;
                $sortable = false;
                $filterClass = 'mageaustralia_admingrid/adminhtml_widget_grid_column_filter_eav';

                // Resolve options for select attributes
                $sourceConfig = $customCol->getSourceConfig();
                $attrCode = $sourceConfig['attribute_code'] ?? null;
                $entityType = $sourceConfig['entity_type'] ?? 'catalog_product';
                if ($attrCode) {
                    $attr = Mage::getSingleton('eav/config')->getAttribute($entityType, $attrCode);
                    if ($attr && $attr->getId() && $attr->usesSource()) {
                        $columnType = 'options';
                        $options = $this->getAttributeOptions($attr);
                    }
                }
            } elseif ($sourceType === 'static') {
                $sourceConfig = $customCol->getSourceConfig();
                $colName = $sourceConfig['column_name'] ?? $code;
                $relatedTable = $sourceConfig['related_table'] ?? null;

                if ($relatedTable) {
                    // Related table — post-load hydration for data, subquery for sort/filter
                    $sortExpr = $this->buildRelatedSortExpression($customCol);
                    if ($sortExpr) {
                        $filterIndex = $sortExpr;
                        $sortable = true;
                        $filterClass = 'mageaustralia_admingrid/adminhtml_widget_grid_column_filter_related';
                    }
                    // Register for hydration
                    $grid->setData('admingrid_related_columns', array_merge(
                        $grid->getData('admingrid_related_columns') ?: [],
                        [$customCol],
                    ));
                } else {
                    // Column exists in the primary flat table — native sort/filter
                    $filterIndex = $colName;
                    $sortable = true;
                    $filterClass = $this->getFilterClassForType($columnType);
                }
            }

            if ($sourceType === 'computed') {
                $columnType = 'text';
                $sortable = false;
                $filterClass = false;

                // Merge preset defaults (template, separator, style) if missing from DB config
                $blockId = $gridModel->getData('grid_block_id');
                if ($blockId) {
                    $presets = Mage::helper('mageaustralia_admingrid')->getCompositeColumns($blockId);
                    $presetKey = str_replace('custom_', '', $code);
                    if (isset($presets[$presetKey])) {
                        $sc = $customCol->getSourceConfig();
                        $defaults = $presets[$presetKey]['config'];
                        // Only add missing keys — don't override user customizations
                        foreach ($defaults as $k => $v) {
                            if (!isset($sc[$k])) {
                                $sc[$k] = $v;
                            }
                        }
                        $customCol->setData('source_config', json_encode($sc));
                    }
                }

                // Register for composite hydration
                $grid->setData('admingrid_composite_columns', array_merge(
                    $grid->getData('admingrid_composite_columns') ?: [],
                    [$customCol],
                ));
            }

            // Image override
            if ($customCol->getData('column_type') === 'image') {
                $columnType = 'text';
            }

            // Determine column index (what field to read data from)
            $columnIndex = $code;
            if ($sourceType === 'static') {
                $sc = $customCol->getSourceConfig();
                $columnIndex = $sc['column_name'] ?? $code;
            }

            $columnConfig = [
                'header'           => $customCol->getData('header'),
                'index'            => $columnIndex,
                'filter_index'     => $filterIndex,
                'type'             => $columnType,
                'sortable'         => $sortable,
                'filter'           => $filterClass,
                'is_system'        => false,
                'column_css_class' => 'admingrid-custom',
            ];

            if ($isEav || $sourceType === 'computed' || ($sourceType === 'static' && !empty($customCol->getSourceConfig()['related_table']))) {
                $columnConfig['admingrid_source_config'] = $customCol->getSourceConfig();
            }

            if ($options !== null) {
                $columnConfig['options'] = $options;
            }

            // Computed type: composite renderer
            if ($sourceType === 'computed') {
                $columnConfig['renderer'] = 'mageaustralia_admingrid/adminhtml_widget_grid_column_renderer_composite';
                $columnConfig['filter'] = false;
                $columnConfig['sortable'] = false;
            }

            // Image type: render as thumbnail
            if ($customCol->getData('column_type') === 'image') {
                $columnConfig['renderer'] = 'mageaustralia_admingrid/adminhtml_widget_grid_column_renderer_image';
                $columnConfig['width'] = '80';
                $columnConfig['filter'] = false;
            }

            $grid->addColumnAfter($code, $columnConfig, $afterColumn);
            $afterColumn = $code; // chain: each custom col goes after the previous one
            $added[] = $customCol;
        }

        if (!empty($added)) {
            $grid->sortColumnsByOrder();
        }

        return $added;
    }

    /**
     * Register a callback to hydrate EAV data after collection loads.
     * Uses the grid's collection load callback mechanism.
     *
     * @param MageAustralia_AdminGrid_Model_Column[] $customColumns
     */
    private function registerEavHydration(
        Mage_Adminhtml_Block_Widget_Grid $grid,
        array $customColumns,
    ): void {
        $eavColumns = [];
        foreach ($customColumns as $col) {
            if ($col->getData('source_type') === 'eav_attribute') {
                $eavColumns[] = $col;
            }
        }

        if (empty($eavColumns)) {
            return;
        }

        // Store for later hydration via _afterLoadCollection or toHtml override
        $grid->setData('admingrid_eav_columns', $eavColumns);
    }

    /**
     * Build a correlated subquery expression for sorting by an EAV attribute.
     * This avoids adding a JOIN to the main query while still enabling ORDER BY.
     *
     * Returns a Zend_Db_Expr like: (SELECT value FROM catalog_product_entity_varchar
     *   WHERE entity_id = e.entity_id AND attribute_id = 119 AND store_id = 0)
     */
    /**
     * Filter callback for EAV custom columns.
     * Adds a WHERE EXISTS subquery — no JOIN on the main collection.
     */
    public function filterEavColumn(Varien_Data_Collection_Db $collection, $column): void
    {
        $value = $column->getFilter()->getValue();
        if ($value === null || $value === '') {
            return;
        }

        $sourceConfig = $column->getData('admingrid_source_config');
        if (!$sourceConfig) {
            return;
        }

        $attrCode = $sourceConfig['attribute_code'] ?? null;
        $entityType = $sourceConfig['entity_type'] ?? 'catalog_product';
        if (!$attrCode) {
            return;
        }

        $attribute = Mage::getSingleton('eav/config')->getAttribute($entityType, $attrCode);
        if (!$attribute || !$attribute->getId() || !$attribute->getBackendTable()) {
            return;
        }

        $backendTable = $attribute->getBackendTable();
        $attrId = (int) $attribute->getId();
        $conn = $collection->getConnection();

        // For text/varchar: LIKE match. For select/int: exact match.
        if (is_array($value)) {
            // Range filter (from/to) for number/date types
            if (!empty($value['from'])) {
                $subquery = "SELECT 1 FROM {$backendTable} AS _eav"
                    . " WHERE _eav.entity_id = e.entity_id"
                    . " AND _eav.attribute_id = {$attrId}"
                    . " AND _eav.store_id = 0"
                    . " AND _eav.value >= " . $conn->quote($value['from']);
                $collection->getSelect()->where("EXISTS ({$subquery})");
            }
            if (!empty($value['to'])) {
                $subquery = "SELECT 1 FROM {$backendTable} AS _eav"
                    . " WHERE _eav.entity_id = e.entity_id"
                    . " AND _eav.attribute_id = {$attrId}"
                    . " AND _eav.store_id = 0"
                    . " AND _eav.value <= " . $conn->quote($value['to']);
                $collection->getSelect()->where("EXISTS ({$subquery})");
            }
        } else {
            // Exact match (options/select) or LIKE (text)
            $backendType = $attribute->getBackendType();
            if (in_array($backendType, ['int', 'decimal']) || $attribute->usesSource()) {
                $valueCond = "_eav.value = " . $conn->quote($value);
            } else {
                $valueCond = "_eav.value LIKE " . $conn->quote('%' . $value . '%');
            }

            $subquery = "SELECT 1 FROM {$backendTable} AS _eav"
                . " WHERE _eav.entity_id = e.entity_id"
                . " AND _eav.attribute_id = {$attrId}"
                . " AND _eav.store_id = 0"
                . " AND {$valueCond}";
            $collection->getSelect()->where("EXISTS ({$subquery})");
        }
    }

    /**
     * Build correlated subquery for sorting/filtering by a related table column.
     */
    private function buildRelatedSortExpression(MageAustralia_AdminGrid_Model_Column $customCol)
    {
        $sourceConfig = $customCol->getSourceConfig();
        $colName = $sourceConfig['column_name'] ?? null;
        $relatedTable = $sourceConfig['related_table'] ?? null;
        $joinOn = $sourceConfig['join_on'] ?? null;

        if (!$colName || !$relatedTable || !$joinOn) {
            return null;
        }

        $resource = Mage::getSingleton('core/resource');
        $table = $resource->getTableName($relatedTable);

        $joinParts = explode('=', $joinOn);
        if (count($joinParts) !== 2) {
            return null;
        }

        $localCol = trim($joinParts[0]);   // e.g. 'order_id'
        $remoteCol = trim($joinParts[1]);  // e.g. 'entity_id'

        return new Maho\Db\Expr(
            "(SELECT {$colName} FROM {$table}"
            . " WHERE {$remoteCol} = main_table.{$localCol}"
            . " LIMIT 1)"
        );
    }

    /**
     * @return Maho\Db\Expr|null
     */
    private function buildEavSortExpression(MageAustralia_AdminGrid_Model_Column $customCol)
    {
        $sourceConfig = $customCol->getSourceConfig();
        $attrCode = $sourceConfig['attribute_code'] ?? null;
        $entityType = $sourceConfig['entity_type'] ?? 'catalog_product';

        if (!$attrCode) {
            return null;
        }

        $attribute = Mage::getSingleton('eav/config')->getAttribute($entityType, $attrCode);
        if (!$attribute || !$attribute->getId() || !$attribute->getBackendTable()) {
            return null;
        }

        $backendTable = $attribute->getBackendTable();
        $attrId = (int) $attribute->getId();

        // Check if backend table has store_id (catalog does, customer doesn't)
        $read = Mage::getSingleton('core/resource')->getConnection('core_read');
        $storeClause = $read->tableColumnExists($backendTable, 'store_id')
            ? " AND store_id = 0"
            : "";

        return new Maho\Db\Expr(
            "(SELECT value FROM {$backendTable}"
            . " WHERE entity_id = e.entity_id"
            . " AND attribute_id = {$attrId}"
            . $storeClause
            . " LIMIT 1)"
        );
    }

    /**
     * After collection loads: apply related-table JOINs and hydrate EAV columns.
     *
     * Event: admingrid_collection_load_after
     */
    public function onCollectionLoadAfter(Varien_Event_Observer $observer): void
    {
        $grid = $observer->getEvent()->getGrid();
        $collection = $observer->getEvent()->getCollection();

        if (!$grid || !$collection) {
            return;
        }

        // Hydrate all custom columns — wrapped in try/catch so one failure doesn't break the grid
        $hydrationSets = [
            'admingrid_related_columns'  => 'hydrateRelatedColumn',
            'admingrid_composite_columns' => 'hydrateCompositeColumn',
        ];

        foreach ($hydrationSets as $dataKey => $method) {
            $columns = $grid->getData($dataKey);
            if (!empty($columns) && is_array($columns)) {
                foreach ($columns as $customCol) {
                    try {
                        $this->$method($collection, $customCol);
                    } catch (\Exception $e) {
                        Mage::logException($e);
                    }
                }
            }
        }

        // Hydrate EAV columns
        $eavColumns = $grid->getData('admingrid_eav_columns');
        if (!empty($eavColumns) && is_array($eavColumns)) {
            foreach ($eavColumns as $customCol) {
                try {
                    $this->hydrateEavColumn($collection, $customCol);
                } catch (\Exception $e) {
                    Mage::logException($e);
                }
            }
        }
    }

    /**
     * Apply LEFT JOINs for related-table columns.
     * Each join is deduplicated by alias.
     */
    private function applyRelatedJoins(Varien_Data_Collection_Db $collection, array $joins): void
    {
        $resource = Mage::getSingleton('core/resource');

        // Group columns by alias to do one JOIN per table
        $joinMap = []; // alias => ['table'=>..., 'join_on'=>..., 'columns'=>[...]]
        foreach ($joins as $join) {
            $alias = $join['alias'];
            if (!isset($joinMap[$alias])) {
                $joinMap[$alias] = [
                    'table'   => $join['table'],
                    'join_on' => $join['join_on'],
                    'columns' => [],
                ];
            }
            $joinMap[$alias]['columns'][] = $join['column'];
        }

        foreach ($joinMap as $alias => $joinInfo) {
            $table = $resource->getTableName($joinInfo['table']);
            $joinOnParts = explode('=', $joinInfo['join_on']);
            if (count($joinOnParts) !== 2) {
                continue;
            }

            $leftCol = trim($joinOnParts[0]);
            $rightCol = trim($joinOnParts[1]);

            // Select the specific columns we need from this joined table
            $columns = array_unique($joinInfo['columns']);

            $collection->getSelect()->joinLeft(
                [$alias => $table],
                "main_table.{$leftCol} = {$alias}.{$rightCol}",
                $columns,
            );
        }
    }

    /**
     * Post-load hydration for related table columns.
     * Batch-fetches values for visible rows from a related table (e.g. order data for invoices).
     */
    private function hydrateRelatedColumn(
        Varien_Data_Collection_Db $collection,
        MageAustralia_AdminGrid_Model_Column $customCol,
    ): void {
        $sourceConfig = $customCol->getSourceConfig();
        $colName = $sourceConfig['column_name'] ?? null;
        $relatedTable = $sourceConfig['related_table'] ?? null;
        $joinOn = $sourceConfig['join_on'] ?? null;

        if (!$colName || !$relatedTable || !$joinOn) {
            return;
        }

        // Parse join_on: "order_id = entity_id" → local=order_id, remote=entity_id
        $joinParts = explode('=', $joinOn);
        if (count($joinParts) !== 2) {
            return;
        }
        $localCol = trim($joinParts[0]);   // e.g. 'order_id'
        $remoteCol = trim($joinParts[1]);  // e.g. 'entity_id'

        // Gather local key values from the loaded collection
        $localIds = [];
        foreach ($collection as $item) {
            $id = $item->getData($localCol);
            if ($id) {
                $localIds[] = $id;
            }
        }

        if (empty($localIds)) {
            return;
        }

        $resource = Mage::getSingleton('core/resource');
        $read = $resource->getConnection('core_read');
        $table = $resource->getTableName($relatedTable);

        $select = $read->select()
            ->from($table, [$remoteCol, $colName])
            ->where("{$remoteCol} IN (?)", $localIds);

        $rows = $read->fetchPairs($select);

        // Inject into collection items
        $code = $customCol->getData('column_code');
        $columnIndex = $colName; // The grid column reads from this index
        foreach ($collection as $item) {
            $key = $item->getData($localCol);
            if ($key !== null && isset($rows[$key])) {
                $item->setData($columnIndex, $rows[$key]);
            }
        }
    }

    /**
     * Post-load hydration for composite columns.
     * Fetches multiple fields from a related table and injects as an array.
     */
    private function hydrateCompositeColumn(
        Varien_Data_Collection_Db $collection,
        MageAustralia_AdminGrid_Model_Column $customCol,
    ): void {
        $sourceConfig = $customCol->getSourceConfig();
        $table = $sourceConfig['table'] ?? null;
        $joinOn = $sourceConfig['join_on'] ?? null;
        $fields = $sourceConfig['fields'] ?? [];
        $filter = $sourceConfig['filter'] ?? [];
        $multiRow = $sourceConfig['multi_row'] ?? false;

        if (!$table || !$joinOn || empty($fields)) {
            return;
        }

        $joinParts = explode('=', $joinOn);
        if (count($joinParts) !== 2) {
            return;
        }

        $localCol = trim($joinParts[0]);
        $remoteCol = trim($joinParts[1]);

        // Gather local IDs
        $localIds = [];
        foreach ($collection as $item) {
            $id = $item->getData($localCol);
            if ($id) {
                $localIds[] = $id;
            }
        }

        if (empty($localIds)) {
            return;
        }

        $resource = Mage::getSingleton('core/resource');
        $read = $resource->getConnection('core_read');
        $tableName = $resource->getTableName($table);

        $select = $read->select()
            ->from($tableName, array_merge([$remoteCol], $fields))
            ->where("{$remoteCol} IN (?)", $localIds);

        // Apply filters (e.g. address_type = 'shipping')
        foreach ($filter as $filterCol => $filterVal) {
            if ($filterVal === null) {
                $select->where("{$filterCol} IS NULL");
            } else {
                $select->where("{$filterCol} = ?", $filterVal);
            }
        }

        $rows = $read->fetchAll($select);

        // If product_id is in the fields, batch-fetch thumbnails
        $thumbnails = [];
        if (in_array('product_id', $fields)) {
            $productIds = array_unique(array_column($rows, 'product_id'));
            if (!empty($productIds)) {
                $thumbnails = $this->fetchProductThumbnails($productIds);
            }
        }

        // Group by remote column (the join key)
        $grouped = [];
        foreach ($rows as $row) {
            // Inject thumbnail URL if available
            if (!empty($row['product_id']) && isset($thumbnails[$row['product_id']])) {
                $row['_thumbnail_url'] = $thumbnails[$row['product_id']];
            }
            $key = $row[$remoteCol];
            if ($multiRow) {
                $grouped[$key][] = $row;
            } else {
                $grouped[$key] = $row;
            }
        }

        // Inject into collection items
        $code = $customCol->getData('column_code');
        foreach ($collection as $item) {
            $key = $item->getData($localCol);
            if ($key === null || !isset($grouped[$key])) {
                continue;
            }

            $data = $grouped[$key];

            if ($multiRow) {
                $rows = [];
                foreach ($data as $row) {
                    $assoc = [];
                    foreach ($fields as $f) {
                        if (isset($row[$f])) {
                            $assoc[$f] = $row[$f];
                        }
                    }
                    // Pass thumbnail URL through
                    if (isset($row['_thumbnail_url'])) {
                        $assoc['_thumbnail_url'] = $row['_thumbnail_url'];
                    }
                    if (!empty($assoc)) {
                        $rows[] = $assoc;
                    }
                }
                $item->setData($code, $rows);
            } else {
                // Single-row: pass associative array keyed by field name
                $assoc = [];
                foreach ($fields as $f) {
                    if (isset($data[$f])) {
                        $assoc[$f] = $data[$f];
                    }
                }
                $item->setData($code, $assoc);
            }
        }
    }

    /**
     * Post-load hydration: Fetch EAV attribute values for visible page rows.
     * Zero JOINs on the main collection — batch fetch for 20-ish rows only.
     */
    private function hydrateEavColumn(
        Varien_Data_Collection_Db $collection,
        MageAustralia_AdminGrid_Model_Column $customCol,
    ): void {
        $sourceConfig = $customCol->getSourceConfig();
        $attributeCode = $sourceConfig['attribute_code'] ?? null;
        $entityType = $sourceConfig['entity_type'] ?? 'catalog_product';

        if (!$attributeCode) {
            return;
        }

        // Gather entity IDs from the loaded page
        $entityIds = [];
        foreach ($collection as $item) {
            $id = $item->getData('entity_id') ?: $item->getId();
            if ($id) {
                $entityIds[] = (int) $id;
            }
        }

        if (empty($entityIds)) {
            return;
        }

        // Batch fetch EAV values
        $values = $this->fetchEavValues($entityType, $attributeCode, $entityIds);

        // Inject into collection items
        $colCode = $customCol->getData('column_code');
        foreach ($collection as $item) {
            $id = (int) ($item->getData('entity_id') ?: $item->getId());
            if (isset($values[$id])) {
                $item->setData($colCode, $values[$id]);
            }
        }
    }

    /**
     * Fetch EAV attribute values for a batch of entity IDs.
     *
     * @return array<int, string> entityId => value
     */
    private function fetchEavValues(string $entityType, string $attributeCode, array $entityIds): array
    {
        $attribute = Mage::getSingleton('eav/config')->getAttribute($entityType, $attributeCode);
        if (!$attribute || !$attribute->getId()) {
            return [];
        }

        $resource = Mage::getSingleton('core/resource');
        $read = $resource->getConnection('core_read');

        $backendTable = $attribute->getBackendTable();
        if (!$backendTable) {
            return [];
        }

        $select = $read->select()
            ->from($backendTable, ['entity_id', 'value'])
            ->where('attribute_id = ?', $attribute->getId())
            ->where('entity_id IN (?)', $entityIds);

        // Only catalog entities have store_id scoping; customer entities don't
        if ($read->tableColumnExists($backendTable, 'store_id')) {
            $select->where('store_id = ?', 0);
        }

        $rows = $read->fetchPairs($select);

        // Resolve option labels for select/multiselect attributes
        if ($attribute->usesSource()) {
            $options = $this->getAttributeOptions($attribute);
            foreach ($rows as $entityId => $value) {
                if (isset($options[$value])) {
                    $rows[$entityId] = $options[$value];
                }
            }
        }

        return $rows;
    }

    private function getOrCreateGrid(
        Mage_Adminhtml_Block_Widget_Grid $grid,
        string $gridBlockId,
    ): MageAustralia_AdminGrid_Model_Grid {
        $blockType = Mage::helper('mageaustralia_admingrid')->getBlockTypeAlias($grid);

        return Mage::getModel('mageaustralia_admingrid/grid')
            ->loadOrCreate($gridBlockId, $blockType);
    }

    private function applyColumnConfig(Mage_Adminhtml_Block_Widget_Grid $grid, array $config): void
    {
        $configByCode = [];
        foreach ($config as $col) {
            if (isset($col['code'])) {
                $configByCode[$col['code']] = $col;
            }
        }

        // Apply visibility and width
        foreach ($grid->getColumns() as $columnId => $column) {
            if (isset($configByCode[$columnId])) {
                $colConfig = $configByCode[$columnId];

                if (isset($colConfig['visible']) && !$colConfig['visible']) {
                    $column->setData('is_hidden', true);
                }

                if (!empty($colConfig['width'])) {
                    $column->setData('width', $colConfig['width']);
                }
            }
        }

        // Apply ordering — directly rebuild the _columns array in the desired order.
        // We avoid addColumnsOrder/sortColumnsByOrder because accumulated constraints
        // from addCustomColumns + profile reordering conflict during sequential splicing.
        $configOrder = [];
        foreach ($config as $col) {
            if (isset($col['code'], $col['position'])) {
                $configOrder[$col['code']] = (int) $col['position'];
            }
        }

        if (empty($configOrder)) {
            return;
        }

        $currentColumns = $grid->getColumns(); // code => column object
        $configured = [];
        $unconfigured = [];

        foreach ($currentColumns as $code => $column) {
            if (isset($configOrder[$code])) {
                $configured[$code] = $configOrder[$code];
            } else {
                $unconfigured[$code] = $column;
            }
        }

        // Sort configured by saved position
        asort($configured);

        // Rebuild ordered columns array
        $ordered = [];
        foreach ($configured as $code => $pos) {
            if (isset($currentColumns[$code])) {
                $ordered[$code] = $currentColumns[$code];
            }
        }
        // Append any unconfigured columns at the end
        foreach ($unconfigured as $code => $column) {
            $ordered[$code] = $column;
        }

        // Replace grid's internal columns array
        // Use reflection since _columns is protected
        $ref = new \ReflectionProperty($grid, '_columns');
        $ref->setAccessible(true);
        $ref->setValue($grid, $ordered);
        $grid->setData('_lastColumnId', array_key_last($ordered));
    }

    /**
     * Get resolved options (value => label) for a source-backed attribute.
     * Results are cached per attribute_id for the duration of the request.
     *
     * @return array<string, string>
     */
    private function getAttributeOptions(Mage_Eav_Model_Entity_Attribute_Abstract $attr): array
    {
        $attrId = (int) $attr->getId();
        if (!isset(self::$_optionsCache[$attrId])) {
            $options = [];
            foreach ($attr->getSource()->getAllOptions(false) as $opt) {
                $options[$opt['value']] = $opt['label'];
            }
            self::$_optionsCache[$attrId] = $options;
        }
        return self::$_optionsCache[$attrId];
    }

    /**
     * Batch-fetch product thumbnail URLs for a set of product IDs.
     * Returns array of product_id => thumbnail_url.
     */
    private function fetchProductThumbnails(array $productIds): array
    {
        $resource = Mage::getSingleton('core/resource');
        $read = $resource->getConnection('core_read');

        $thumbnailAttr = Mage::getSingleton('eav/config')
            ->getAttribute('catalog_product', 'thumbnail');

        if (!$thumbnailAttr || !$thumbnailAttr->getId()) {
            return [];
        }

        $select = $read->select()
            ->from($thumbnailAttr->getBackendTable(), ['entity_id', 'value'])
            ->where('attribute_id = ?', $thumbnailAttr->getId())
            ->where('entity_id IN (?)', $productIds)
            ->where('store_id = ?', 0);

        $rows = $read->fetchPairs($select);
        $mediaUrl = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA) . 'catalog/product';

        $result = [];
        foreach ($rows as $entityId => $value) {
            if ($value && $value !== 'no_selection') {
                $result[$entityId] = $mediaUrl . $value;
            }
        }

        return $result;
    }

    private function getFilterClassForType(string $columnType): string
    {
        return match ($columnType) {
            'options' => 'adminhtml/widget_grid_column_filter_select',
            'number'  => 'adminhtml/widget_grid_column_filter_range',
            'date'    => 'adminhtml/widget_grid_column_filter_date',
            default   => 'adminhtml/widget_grid_column_filter_text',
        };
    }

    private function mapColumnType(string $type): string
    {
        return match ($type) {
            'number' => 'number',
            'date'   => 'date',
            'image'  => 'text',
            default  => 'text',
        };
    }

    private function getLastNativeColumnCode(Mage_Adminhtml_Block_Widget_Grid $grid): string
    {
        $columns = $grid->getColumns();
        $codes = array_keys($columns);
        $actionIdx = array_search('action', $codes);
        if ($actionIdx !== false && $actionIdx > 0) {
            return $codes[$actionIdx - 1];
        }
        return end($codes) ?: '';
    }
}
