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
    public function onGridPrepareColumnsAfter(\Maho\Event\Observer $observer): void
    {
        try {
            $this->_doGridPrepareColumnsAfter($observer);
        } catch (\Exception $exception) {
            Mage::logException($exception);
        }
    }

    private function _doGridPrepareColumnsAfter(\Maho\Event\Observer $observer): void
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

        $userId = Mage::getSingleton('admin/session')->getUser()->getId();
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
        if ($customColumns !== []) {
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
            ->getCollection();
        // getCollection() is typed ...|false in the Maho stubs — keep this guard for PHPStan.
        if ($customColumns === false) {
            return [];
        }

        $customColumns->addActiveGridFilter((int) $gridModel->getId());

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
                    if ($sortExpr instanceof \Maho\Db\Expr) {
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

            if ($sourceType === 'category') {
                $sortable = false;
                $filterClass = 'mageaustralia_admingrid/adminhtml_widget_grid_column_filter_category';
                $grid->setData('admingrid_category_columns', array_merge(
                    $grid->getData('admingrid_category_columns') ?: [],
                    [$customCol],
                ));
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

            // Category type: category tree filter + name renderer
            if ($sourceType === 'category') {
                $columnConfig['renderer'] = 'mageaustralia_admingrid/adminhtml_widget_grid_column_renderer_categories';
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

        if ($added !== []) {
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

        if ($eavColumns === []) {
            return;
        }

        // Store for later hydration via _afterLoadCollection or toHtml override
        $grid->setData('admingrid_eav_columns', $eavColumns);
    }

    /**
     * Build correlated subquery for sorting/filtering by a related table column.
     */
    private function buildRelatedSortExpression(MageAustralia_AdminGrid_Model_Column $customCol): \Maho\Db\Expr|null
    {
        $sourceConfig = $customCol->getSourceConfig();
        $colName = $sourceConfig['column_name'] ?? null;
        $relatedTable = $sourceConfig['related_table'] ?? null;
        $joinOn = $sourceConfig['join_on'] ?? null;

        if (!$colName || !$relatedTable || !$joinOn) {
            return null;
        }

        // Validate identifiers before they reach SQL (config may predate write-time checks).
        $helper = Mage::helper('mageaustralia_admingrid');
        $parsedJoin = $helper->parseJoinOn((string) $joinOn);
        if (!$parsedJoin || !$helper->isSafeIdentifier((string) $colName)) {
            return null;
        }

        [$localCol, $remoteCol] = $parsedJoin;

        $resource = Mage::getSingleton('core/resource');
        $read = $resource->getConnection('core_read');
        $table = $resource->getTableName($relatedTable);

        return new Maho\Db\Expr(
            '(SELECT ' . $read->quoteIdentifier($colName)
            . ' FROM ' . $read->quoteIdentifier($table)
            . ' WHERE ' . $read->quoteIdentifier($remoteCol)
            . ' = ' . $read->quoteIdentifier('main_table.' . $localCol)
            . ' LIMIT 1)',
        );
    }

    /**
     * Collect non-empty values of $localCol across the loaded collection,
     * used as the IN(...) key set for batch hydration.
     *
     * @return array<int, mixed>
     */
    private function collectKeys(\Maho\Data\Collection\Db $collection, string $localCol): array
    {
        $keys = [];
        foreach ($collection as $item) {
            $id = $item->getData($localCol);
            if ($id) {
                $keys[] = $id;
            }
        }

        return $keys;
    }

    /**
     * After collection loads: apply related-table JOINs and hydrate EAV columns.
     *
     * Event: admingrid_collection_load_after
     */
    public function onCollectionLoadAfter(\Maho\Event\Observer $observer): void
    {
        $grid = $observer->getEvent()->getGrid();
        $collection = $observer->getEvent()->getCollection();

        if (!$grid || !$collection) {
            return;
        }

        // Hydrate all custom columns — wrapped in try/catch so one failure doesn't break the grid
        $hydrationSets = [
            'admingrid_related_columns'   => 'hydrateRelatedColumn',
            'admingrid_composite_columns' => 'hydrateCompositeColumn',
            'admingrid_category_columns'  => 'hydrateCategoryColumn',
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
     * Post-load hydration for related table columns.
     * Batch-fetches values for visible rows from a related table (e.g. order data for invoices).
     */
    private function hydrateRelatedColumn(
        \Maho\Data\Collection\Db $collection,
        MageAustralia_AdminGrid_Model_Column $customCol,
    ): void {
        $sourceConfig = $customCol->getSourceConfig();
        $colName = $sourceConfig['column_name'] ?? null;
        $relatedTable = $sourceConfig['related_table'] ?? null;
        $joinOn = $sourceConfig['join_on'] ?? null;

        if (!$colName || !$relatedTable || !$joinOn) {
            return;
        }

        // Validate identifiers before they reach SQL (config may predate write-time checks).
        $helper = Mage::helper('mageaustralia_admingrid');
        $parsedJoin = $helper->parseJoinOn((string) $joinOn);
        if (!$parsedJoin || !$helper->isSafeIdentifier((string) $colName)) {
            return;
        }

        [$localCol, $remoteCol] = $parsedJoin;

        $localIds = $this->collectKeys($collection, $localCol);
        if ($localIds === []) {
            return;
        }

        $resource = Mage::getSingleton('core/resource');
        $read = $resource->getConnection('core_read');
        $table = $resource->getTableName($relatedTable);

        $select = $read->select()
            ->from($table, [$remoteCol, $colName])
            ->where($read->quoteIdentifier($remoteCol) . ' IN (?)', $localIds);

        $rows = $read->fetchPairs($select);

        // Inject into collection items — the grid column reads from the colName index.
        foreach ($collection as $item) {
            $key = $item->getData($localCol);
            if ($key !== null && isset($rows[$key])) {
                $item->setData($colName, $rows[$key]);
            }
        }
    }

    /**
     * Post-load hydration for composite columns.
     * Fetches multiple fields from a related table and injects as an array.
     */
    private function hydrateCompositeColumn(
        \Maho\Data\Collection\Db $collection,
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

        // Validate identifiers before they reach SQL (config may predate write-time checks).
        $helper = Mage::helper('mageaustralia_admingrid');
        $parsedJoin = $helper->parseJoinOn((string) $joinOn);
        if (!$parsedJoin || !is_string($table)) {
            return;
        }

        [$localCol, $remoteCol] = $parsedJoin;

        $fields = array_values(array_filter(
            (array) $fields,
            fn($field): bool => $helper->isSafeIdentifier((string) $field),
        ));
        if ($fields === []) {
            return;
        }

        $localIds = $this->collectKeys($collection, $localCol);
        if ($localIds === []) {
            return;
        }

        $resource = Mage::getSingleton('core/resource');
        $read = $resource->getConnection('core_read');
        $tableName = $resource->getTableName($table);

        $select = $read->select()
            ->from($tableName, array_merge([$remoteCol], $fields))
            ->where($read->quoteIdentifier($remoteCol) . ' IN (?)', $localIds);

        // Apply filters (e.g. address_type = 'shipping') — skip unsafe column names.
        foreach ($filter as $filterCol => $filterVal) {
            if (!$helper->isSafeIdentifier((string) $filterCol)) {
                continue;
            }

            if ($filterVal === null) {
                $select->where($read->quoteIdentifier($filterCol) . ' IS NULL');
            } else {
                $select->where($read->quoteIdentifier($filterCol) . ' = ?', $filterVal);
            }
        }

        $rows = $read->fetchAll($select);

        // If product_id is in the fields, batch-fetch thumbnails
        $thumbnails = [];
        if (in_array('product_id', $fields)) {
            $productIds = array_unique(array_column($rows, 'product_id'));
            if ($productIds !== []) {
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
            if ($key === null) {
                continue;
            }

            if (!isset($grouped[$key])) {
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

                    if ($assoc !== []) {
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
        \Maho\Data\Collection\Db $collection,
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

        if ($entityIds === []) {
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

        $rows = [];

        if ($entityType === 'catalog_product') {
            $collection = Mage::getResourceModel('catalog/product_collection')
                ->addAttributeToSelect($attributeCode)
                ->addFieldToFilter('entity_id', ['in' => $entityIds]);
            foreach ($collection as $product) {
                $rows[(int) $product->getId()] = $product->getData($attributeCode);
            }
        } else {
            // Non-catalog entity types (e.g. customer) — no canonical collection wrapper
            // for arbitrary entity types, so direct EAV read remains.
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
        }

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

        if ($configOrder === []) {
            return;
        }

        $currentColumns = $grid->getColumns(); // code => column object
        $configured = [];
        $unconfigured = [];

        foreach ($currentColumns as $code => $column) {
            // Skip massaction — Maho prepends it after our event, so don't include in reorder
            if ($code === 'massaction') {
                continue;
            }

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
        foreach (array_keys($configured) as $code) {
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
        $ref->setValue($grid, $ordered);

        $grid->setData('_lastColumnId', array_key_last($ordered));
    }

    /**
     * Post-load hydration for category columns.
     * Batch-fetches category names for visible products from catalog_category_product.
     */
    private function hydrateCategoryColumn(
        \Maho\Data\Collection\Db $collection,
        MageAustralia_AdminGrid_Model_Column $customCol,
    ): void {
        $entityIds = [];
        foreach ($collection as $item) {
            $id = $item->getData('entity_id') ?: $item->getId();
            if ($id) {
                $entityIds[] = (int) $id;
            }
        }

        if ($entityIds === []) {
            return;
        }

        // catalog_category_product is the FK pivot (product_id, category_id, position).
        // There is no canonical collection wrapper for this join table, so a raw select
        // remains, but the table name resolves through the resource model.
        $resource = Mage::getSingleton('core/resource');
        $read = $resource->getConnection('core_read');
        $ccpTable = $resource->getTableName('catalog/category_product');

        $select = $read->select()
            ->from($ccpTable, ['product_id', 'category_id'])
            ->where('product_id IN (?)', $entityIds);
        $rows = $read->fetchAll($select);

        $catIds = array_unique(array_column($rows, 'category_id'));
        if ($catIds === []) {
            return;
        }

        // Batch-fetch category names via category collection
        $catColl = Mage::getResourceModel('catalog/category_collection')
            ->addAttributeToSelect('name')
            ->addFieldToFilter('entity_id', ['in' => $catIds]);
        $catNames = [];
        foreach ($catColl as $cat) {
            $catNames[(int) $cat->getId()] = (string) $cat->getName();
        }

        // Group by product_id
        $productCats = [];
        foreach ($rows as $row) {
            $pid = (int) $row['product_id'];
            $cid = (int) $row['category_id'];
            if (isset($catNames[$cid])) {
                $productCats[$pid][] = $catNames[$cid];
            }
        }

        $code = $customCol->getData('column_code');
        foreach ($collection as $item) {
            $id = (int) ($item->getData('entity_id') ?: $item->getId());
            if (isset($productCats[$id])) {
                $item->setData($code, implode(', ', array_unique($productCats[$id])));
            }
        }
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
            /** @phpstan-ignore arguments.count */
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
        $mediaUrl = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA) . 'catalog/product';

        $coll = Mage::getResourceModel('catalog/product_collection')
            ->addAttributeToSelect('thumbnail')
            ->addFieldToFilter('entity_id', ['in' => $productIds]);

        $result = [];
        foreach ($coll as $product) {
            $value = $product->getData('thumbnail');
            if ($value && $value !== 'no_selection') {
                $result[(int) $product->getId()] = $mediaUrl . $value;
            }
        }

        // Find product IDs that had no thumbnail
        $missingIds = array_diff(array_map(intval(...), $productIds), array_keys($result));

        // Fallback: for products without their own thumbnail, use the configurable parent's.
        // getParentIdsByChild() returns a FLAT list of parent ids with no child mapping, so
        // query catalog_product_super_link directly to get child => parent pairs.
        if ($missingIds !== []) {
            $resource = Mage::getSingleton('core/resource');
            $read = $resource->getConnection('core_read');
            $childToParent = $read->fetchPairs(
                $read->select()
                    ->from($resource->getTableName('catalog/product_super_link'), ['product_id', 'parent_id'])
                    ->where('product_id IN (?)', $missingIds),
            );

            if ($childToParent !== []) {
                $parentIds = array_unique(array_map('intval', array_values($childToParent)));
                $parentColl = Mage::getResourceModel('catalog/product_collection')
                    ->addAttributeToSelect('thumbnail')
                    ->addFieldToFilter('entity_id', ['in' => $parentIds]);
                $parentThumbs = [];
                foreach ($parentColl as $parent) {
                    $parentThumbs[(int) $parent->getId()] = $parent->getData('thumbnail');
                }

                foreach ($childToParent as $childId => $parentId) {
                    $parentVal = $parentThumbs[(int) $parentId] ?? null;
                    if ($parentVal && $parentVal !== 'no_selection') {
                        $result[(int) $childId] = $mediaUrl . $parentVal;
                    }
                }
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
        $actionIdx = array_search('action', $codes, true);
        if ($actionIdx !== false && $actionIdx > 0) {
            return $codes[$actionIdx - 1];
        }

        return end($codes) ?: '';
    }
}
