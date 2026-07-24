<?php

declare(strict_types=1);

class MageAustralia_AdminGrid_Helper_Data extends Mage_Core_Helper_Abstract
{
    public const XPATH_ENABLED = 'mageaustralia_admingrid/general/enabled';

    /**
     * Map grid block IDs to their entity type for attribute discovery.
     */
    private const array GRID_ENTITY_MAP = [
        'productGrid'          => 'catalog_product',
        'product_grid'         => 'catalog_product',
        'customer_grid'        => 'customer',
        'customerGrid'         => 'customer',
    ];

    public function isEnabled(): bool
    {
        return Mage::getStoreConfigFlag(self::XPATH_ENABLED);
    }

    /**
     * Get block type alias from a grid block class.
     */
    public function getBlockTypeAlias(Mage_Adminhtml_Block_Widget_Grid $grid): string
    {
        $class = $grid::class;
        $config = Mage::getConfig();

        foreach (['adminhtml', 'mageaustralia_admingrid'] as $group) {
            $classPrefix = (string) $config->getNode(sprintf('global/blocks/%s/class', $group));
            if ($classPrefix && str_starts_with($class, $classPrefix)) {
                $suffix = substr($class, strlen($classPrefix) + 1);
                $suffix = strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $suffix));
                return $group . '/' . $suffix;
            }
        }

        return $class;
    }

    /**
     * Get the entity type code for a grid, used for attribute discovery.
     */
    public function getEntityTypeForGrid(string $gridBlockId): ?string
    {
        // Direct mapping
        if (isset(self::GRID_ENTITY_MAP[$gridBlockId])) {
            return self::GRID_ENTITY_MAP[$gridBlockId];
        }

        // Heuristic: if the grid ID contains 'product', it's product
        $lower = strtolower($gridBlockId);
        if (str_contains($lower, 'product')) {
            return 'catalog_product';
        }

        if (str_contains($lower, 'customer')) {
            return 'customer';
        }

        return null;
    }

    public function isProductGrid(string $gridBlockId): bool
    {
        return $this->getEntityTypeForGrid($gridBlockId) === 'catalog_product';
    }

    /**
     * Get pre-built composite columns for a grid.
     * These are multi-field columns like "Shipping Address" that combine
     * multiple fields from a related table into a single stacked cell.
     */
    public function getCompositeColumns(string $gridBlockId): array
    {
        $lower = strtolower($gridBlockId);
        $composites = [];

        // Order-related grids get address composites
        $isOrderGrid = str_contains($lower, 'order') && !str_contains($lower, 'product');
        $isInvoiceGrid = str_contains($lower, 'invoice');
        $isShipmentGrid = str_contains($lower, 'shipment');
        $isCreditmemoGrid = str_contains($lower, 'creditmemo');

        if ($isOrderGrid || $isInvoiceGrid || $isShipmentGrid || $isCreditmemoGrid) {
            // Determine join column
            $joinLocal = $isOrderGrid ? 'entity_id' : 'order_id';

            $composites['composite_shipping_address'] = [
                'code'   => 'composite_shipping_address',
                'label'  => 'Shipping Address',
                'type'   => 'composite',
                'config' => [
                    'table'        => 'sales_flat_order_address',
                    'join_on'      => $joinLocal . ' = parent_id',
                    'filter'       => ['address_type' => 'shipping'],
                    'fields'       => ['firstname', 'lastname', 'company', 'street', 'city', 'region', 'postcode', 'country_id', 'telephone'],
                    'template'     => [['firstname', 'lastname'], ['company'], ['street'], ['city', 'region', 'postcode'], ['country_id']],
                    'separator'    => ' ',
                    'style'        => 'plain',
                    'field_labels' => [
                        'firstname'  => 'First Name',
                        'lastname'   => 'Last Name',
                        'company'    => 'Company',
                        'street'     => 'Street',
                        'city'       => 'City',
                        'region'     => 'State/Province',
                        'postcode'   => 'Postcode',
                        'country_id' => 'Country',
                        'telephone'  => 'Phone',
                    ],
                ],
            ];

            $composites['composite_billing_address'] = [
                'code'   => 'composite_billing_address',
                'label'  => 'Billing Address',
                'type'   => 'composite',
                'config' => [
                    'table'        => 'sales_flat_order_address',
                    'join_on'      => $joinLocal . ' = parent_id',
                    'filter'       => ['address_type' => 'billing'],
                    'fields'       => ['firstname', 'lastname', 'company', 'street', 'city', 'region', 'postcode', 'country_id', 'telephone'],
                    'template'     => [['firstname', 'lastname'], ['company'], ['street'], ['city', 'region', 'postcode'], ['country_id']],
                    'separator'    => ' ',
                    'style'        => 'plain',
                    'field_labels' => [
                        'firstname'  => 'First Name',
                        'lastname'   => 'Last Name',
                        'company'    => 'Company',
                        'street'     => 'Street',
                        'city'       => 'City',
                        'region'     => 'State/Province',
                        'postcode'   => 'Postcode',
                        'country_id' => 'Country',
                        'telephone'  => 'Phone',
                    ],
                ],
            ];

            $composites['composite_ordered_items'] = [
                'code'   => 'composite_ordered_items',
                'label'  => 'Ordered Items',
                'type'   => 'composite',
                'config' => [
                    'table'        => 'sales_flat_order_item',
                    'join_on'      => $joinLocal . ' = order_id',
                    'filter'       => ['parent_item_id' => null],
                    'fields'       => ['product_id', 'name', 'sku', 'qty_ordered', 'row_total', 'weight'],
                    'template'     => [['name'], ['sku', 'qty_ordered']],
                    'separator'    => ' x ',
                    'multi_row'    => true,
                    'style'        => 'card',
                    'thumbnail_size' => 40,
                    'field_labels' => [
                        'product_id'  => 'Thumbnail',
                        'name'        => 'Product Name',
                        'sku'         => 'SKU',
                        'qty_ordered' => 'Qty',
                        'row_total'   => 'Row Total',
                        'weight'      => 'Weight',
                    ],
                ],
            ];
        }

        return $composites;
    }

    /**
     * Get all available EAV attributes for an entity type.
     * Returns a flat array suitable for the JS columns dropdown.
     *
     * @return array<string, array{code: string, label: string, input: string, type: string}>
     */
    public function getAvailableAttributes(string $entityTypeCode): array
    {
        $attributes = [];

        if ($entityTypeCode === 'catalog_product') {
            $collection = Mage::getResourceModel('catalog/product_attribute_collection')
                ->addVisibleFilter()
                ->setOrder('frontend_label', 'ASC');
        } elseif ($entityTypeCode === 'customer') {
            $collection = Mage::getResourceModel('customer/attribute_collection')
                ->addVisibleFilter()
                ->setOrder('frontend_label', 'ASC');
        } else {
            return [];
        }

        foreach ($collection as $attr) {
            $code = $attr->getAttributeCode();
            $label = $attr->getFrontendLabel();
            if (!$label) {
                continue; // Skip attributes without a label
            }

            $input = $attr->getFrontendInput() ?: 'text';

            $attributes[$code] = [
                'code'  => $code,
                'label' => $label,
                'input' => $input,
                'type'  => $this->mapInputToColumnType($input),
            ];
        }

        return $attributes;
    }

    /**
     * Map grid block IDs to their underlying flat database tables.
     */
    private const array GRID_TABLE_MAP = [
        'sales_order_grid'  => 'sales_flat_order_grid',
        'order_grid'        => 'sales_flat_order_grid',
        'customer_grid'     => 'customer_entity',
        'customerGrid'      => 'customer_entity',
    ];

    /**
     * Columns to skip — internal/system columns that aren't useful in grids.
     */
    private const array SKIP_COLUMNS = [
        'entity_id', 'increment_id', 'store_id', 'created_at', 'updated_at',
        'is_active', 'entity_type_id', 'attribute_set_id', 'parent_id',
        'password_hash', 'rp_token', 'rp_token_created_at',
    ];

    /**
     * Get flat table columns available in a grid's collection.
     * Auto-discovers from the actual database table via DESCRIBE.
     * Cached in Maho cache for 1 hour — only re-scans periodically.
     */
    public function getCollectionColumns(string $gridBlockId): array
    {
        $cacheKey = 'admingrid_table_cols_' . md5($gridBlockId);
        $cached = Mage::app()->loadCache($cacheKey);
        if ($cached) {
            $decoded = json_decode((string) $cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $tables = $this->resolveTablesForGrid($gridBlockId);
        if (!$tables['primary']) {
            return [];
        }

        $conn = Mage::getSingleton('core/resource')->getConnection('core_read');
        $resource = Mage::getSingleton('core/resource');
        $columns = [];

        // Primary table columns
        $primaryTable = $resource->getTableName($tables['primary']);
        if ($conn->isTableExists($primaryTable)) {
            foreach ($conn->describeTable($primaryTable) as $colName => $colDef) {
                if (in_array($colName, self::SKIP_COLUMNS)) {
                    continue;
                }

                $columns[$colName] = [
                    'code'  => $colName,
                    'label' => $this->humanizeColumnName($colName),
                    'type'  => $this->mapDbTypeToColumnType($colDef['DATA_TYPE']),
                ];
            }
        }

        // Related table columns (e.g. order fields for invoice grids)
        foreach ($tables['related'] as $relConfig) {
            $relTable = $resource->getTableName($relConfig['table']);
            if (!$conn->isTableExists($relTable)) {
                continue;
            }

            $prefix = $relConfig['label'];
            foreach ($conn->describeTable($relTable) as $colName => $colDef) {
                if (in_array($colName, self::SKIP_COLUMNS)) {
                    continue;
                }

                // Skip columns that already exist in primary table
                if (isset($columns[$colName])) {
                    continue;
                }

                $columns[$colName] = [
                    'code'         => $colName,
                    'label'        => $prefix . ': ' . $this->humanizeColumnName($colName),
                    'type'         => $this->mapDbTypeToColumnType($colDef['DATA_TYPE']),
                    'related_table' => $relConfig['table'],
                    'join_on'      => $relConfig['join_on'],
                ];
            }
        }

        // Cache for 1 hour
        Mage::app()->saveCache(
            json_encode($columns),
            $cacheKey,
            ['admingrid'],
            3600,
        );

        return $columns;
    }

    /**
     * Resolve the flat database table(s) for a grid.
     * Returns the primary table + any related tables that can be JOINed.
     *
     * @return array{primary: string|null, related: array<string, array{table: string, join_on: string, label: string}>}
     */
    public function resolveTablesForGrid(string $gridBlockId): array
    {
        $lower = strtolower($gridBlockId);
        $primary = null;
        $related = [];

        // Direct mapping
        if (isset(self::GRID_TABLE_MAP[$gridBlockId])) {
            $primary = self::GRID_TABLE_MAP[$gridBlockId];
        }

        // Heuristic
        if (!$primary) {
            if (str_contains($lower, 'order') && !str_contains($lower, 'product')) {
                $primary = 'sales_flat_order_grid';
            } elseif (str_contains($lower, 'invoice')) {
                $primary = 'sales_flat_invoice_grid';
            } elseif (str_contains($lower, 'shipment')) {
                $primary = 'sales_flat_shipment_grid';
            } elseif (str_contains($lower, 'creditmemo')) {
                $primary = 'sales_flat_creditmemo_grid';
            }
        }

        // Related tables — invoice/shipment/creditmemo can JOIN to order grid + payment
        if ($primary && $primary !== 'sales_flat_order_grid' && (str_contains($primary, 'invoice') || str_contains($primary, 'shipment') || str_contains($primary, 'creditmemo'))) {
            $related['sales_flat_order_grid'] = [
                'table'   => 'sales_flat_order_grid',
                'join_on' => 'order_id = entity_id',
                'label'   => 'Order',
            ];
            $related['sales_flat_order'] = [
                'table'   => 'sales_flat_order',
                'join_on' => 'order_id = entity_id',
                'label'   => 'Order (Full)',
            ];
            $related['sales_flat_order_payment'] = [
                'table'   => 'sales_flat_order_payment',
                'join_on' => 'order_id = parent_id',
                'label'   => 'Payment',
            ];
        }

        // Order grid can access the full order table + payment
        if ($primary === 'sales_flat_order_grid') {
            $related['sales_flat_order'] = [
                'table'   => 'sales_flat_order',
                'join_on' => 'entity_id = entity_id',
                'label'   => 'Order (Full)',
            ];
            $related['sales_flat_order_payment'] = [
                'table'   => 'sales_flat_order_payment',
                'join_on' => 'entity_id = parent_id',
                'label'   => 'Payment',
            ];
        }

        return ['primary' => $primary, 'related' => $related];
    }

    /**
     * Map MySQL column types to our grid column types.
     */
    private function mapDbTypeToColumnType(string $dbType): string
    {
        $dbType = strtolower($dbType);

        if (in_array($dbType, ['int', 'smallint', 'tinyint', 'mediumint', 'bigint'], true)) {
            return 'number';
        }

        if (in_array($dbType, ['decimal', 'float', 'double'], true)) {
            return 'number';
        }

        if (in_array($dbType, ['date', 'datetime', 'timestamp'], true)) {
            return 'date';
        }

        return 'text';
    }

    /**
     * Convert snake_case column name to a human-readable label.
     * e.g. 'customer_email' → 'Customer Email', 'szy_status' → 'Szy Status'
     */
    private function humanizeColumnName(string $column): string
    {
        return ucwords(str_replace('_', ' ', $column));
    }

    private function mapInputToColumnType(string $input): string
    {
        return match ($input) {
            'price', 'weight'          => 'number',
            'date', 'datetime'         => 'date',
            'select', 'multiselect', 'boolean' => 'options',
            'media_image', 'gallery'   => 'image',
            default                    => 'text',
        };
    }

    // ── Security: identifier validation for custom-column SQL ─────────────

    /**
     * Strict identifier guard. Only [a-z0-9_], 1-64 chars.
     *
     * Custom-column source_config carries table/column/join identifiers that end
     * up in raw SQL fragments. Constraining them to this character set means a
     * stored value can never contain quotes, spaces, parentheses or semicolons,
     * so it cannot break out of an identifier position (SQL-injection defence).
     */
    public function isSafeIdentifier(string $identifier): bool
    {
        return preg_match('/^[a-z0-9_]{1,64}$/i', $identifier) === 1;
    }

    /**
     * Parse a "local = remote" join expression into two validated identifiers.
     *
     * @return array{0: string, 1: string}|null [localCol, remoteCol], or null if malformed/unsafe
     */
    public function parseJoinOn(?string $joinOn): ?array
    {
        if ($joinOn === null || $joinOn === '') {
            return null;
        }

        $parts = explode('=', $joinOn);
        if (count($parts) !== 2) {
            return null;
        }

        $local = trim($parts[0]);
        $remote = trim($parts[1]);
        if (!$this->isSafeIdentifier($local) || !$this->isSafeIdentifier($remote)) {
            return null;
        }

        return [$local, $remote];
    }

    /**
     * Validate a custom column's source_config against the trusted, server-derived
     * schema for the grid before it is persisted. Returns an error message, or null
     * when the config is safe to store.
     *
     * This is the primary defence against SQL injection via custom columns: only
     * identifiers the helper itself discovered (real DB columns / preset composites)
     * are ever allowed into source_config.
     */
    public function validateSourceConfig(string $gridBlockId, string $sourceType, array $config): ?string
    {
        return match ($sourceType) {
            'static'        => $this->validateStaticSourceConfig($gridBlockId, $config),
            'computed'      => $this->validateComputedSourceConfig($gridBlockId, $config),
            'eav_attribute' => $this->validateEavSourceConfig($config),
            'category'      => null,
            default         => 'Unknown source type: ' . $sourceType,
        };
    }

    private function validateStaticSourceConfig(string $gridBlockId, array $config): ?string
    {
        $colName = $config['column_name'] ?? null;
        if ($colName === null || $colName === '') {
            return null; // column reads from its own code; no extra identifier to vet
        }

        if (!$this->isSafeIdentifier((string) $colName)) {
            return 'Invalid column name.';
        }

        $allowed = $this->getCollectionColumns($gridBlockId);
        if (!isset($allowed[$colName])) {
            return 'Column not available for this grid: ' . $colName;
        }

        $expectedRelated = $allowed[$colName]['related_table'] ?? null;
        $postedRelated = $config['related_table'] ?? null;
        if ($postedRelated !== $expectedRelated) {
            return 'Related table mismatch for column: ' . $colName;
        }

        if ($postedRelated !== null
            && ($config['join_on'] ?? null) !== ($allowed[$colName]['join_on'] ?? null)
        ) {
            return 'Invalid join condition.';
        }

        return null;
    }

    private function validateComputedSourceConfig(string $gridBlockId, array $config): ?string
    {
        $table = $config['table'] ?? null;
        if ($table === null) {
            return null;
        }

        $preset = null;
        foreach ($this->getCompositeColumns($gridBlockId) as $candidate) {
            if (($candidate['config']['table'] ?? null) === $table) {
                $preset = $candidate['config'];
                break;
            }
        }

        if ($preset === null) {
            return 'Composite table not allowed: ' . $table;
        }

        if (($config['join_on'] ?? null) !== ($preset['join_on'] ?? null)) {
            return 'Invalid composite join condition.';
        }

        // SQL-touching keys must stay within the preset; fields/template are a subset.
        if (($config['filter'] ?? []) != ($preset['filter'] ?? [])) {
            return 'Composite filter cannot be modified.';
        }

        $allowedFields = $preset['fields'] ?? [];
        foreach (($config['fields'] ?? []) as $field) {
            if (!in_array($field, $allowedFields, true)) {
                return 'Field not allowed: ' . $field;
            }
        }

        foreach (($config['template'] ?? []) as $line) {
            foreach ((array) $line as $field) {
                if (!in_array($field, $allowedFields, true)) {
                    return 'Template field not allowed: ' . $field;
                }
            }
        }

        return null;
    }

    private function validateEavSourceConfig(array $config): ?string
    {
        $attrCode = $config['attribute_code'] ?? null;
        $entityType = $config['entity_type'] ?? 'catalog_product';

        if ($attrCode === null || $attrCode === '') {
            return 'Missing attribute code.';
        }

        if (!$this->isSafeIdentifier((string) $attrCode) || !$this->isSafeIdentifier((string) $entityType)) {
            return 'Invalid attribute or entity type.';
        }

        $attribute = Mage::getSingleton('eav/config')->getAttribute($entityType, $attrCode);
        if (!$attribute || !$attribute->getId()) {
            return 'Unknown attribute: ' . $attrCode;
        }

        return null;
    }

    // ── Inline cell editing ──────────────────────────────────────────────

    /**
     * Column codes that are never inline-editable.
     *
     *  - entity_id / id / massaction / action / checkbox: structural grid columns.
     *  - sku: EAV but static backend — not settable via updateAttributes; a
     *    dedicated resource save path would be needed (out of scope for now).
     *  - qty: cataloginventory stock item, not an EAV attribute — needs a stock
     *    save path (out of scope for now).
     *  - categories / websites: relation lists, not scalar attribute values.
     *  - set / type / attribute_set_id / entity_type_id: structural, not editable inline.
     */
    private const array NON_EDITABLE_CODES = [
        'entity_id', 'id', 'massaction', 'action', 'checkbox',
        'sku', 'qty', 'categories', 'websites',
        'set', 'type', 'attribute_set_id', 'entity_type_id',
    ];

    /**
     * Whether a grid column (native OR custom) maps to a writable catalog_product
     * EAV attribute and may therefore be made inline-editable. The backend is the
     * single source of truth for eligibility; the JS trusts this, not a name prefix.
     */
    public function isColumnEditableEligible(string $gridBlockId, string $columnCode): bool
    {
        return $this->resolveEligibleAttribute($gridBlockId, $columnCode) !== null;
    }

    /**
     * Resolve the writable EAV attribute a column maps to, or null if the column
     * is not eligible for inline editing. Handles both module-added custom columns
     * (attribute read from source_config) and native grid columns (column code ==
     * attribute code on the product grid).
     */
    public function resolveEligibleAttribute(string $gridBlockId, string $columnCode): ?Mage_Eav_Model_Entity_Attribute_Abstract
    {
        if ($columnCode === '' || in_array($columnCode, self::NON_EDITABLE_CODES, true)) {
            return null;
        }

        // Custom (module-added) columns: composite/category are never editable;
        // otherwise resolve the attribute declared in source_config.
        if (str_starts_with($columnCode, 'custom_')) {
            if (str_starts_with($columnCode, 'custom_composite_') || $columnCode === 'custom_categories') {
                return null;
            }

            $column = $this->loadGridColumn($gridBlockId, $columnCode);
            return $column ? $this->getEditableAttribute($column) : null;
        }

        // Native columns: inline editing supported on the product grid, where the
        // column code equals the attribute code (name, status, visibility, price, …).
        if (!$this->isProductGrid($gridBlockId) || !$this->isSafeIdentifier($columnCode)) {
            return null;
        }

        $attribute = Mage::getSingleton('eav/config')->getAttribute('catalog_product', $columnCode);
        if (!$attribute || !$attribute->getId() || $attribute->getBackendType() === 'static') {
            return null;
        }

        return $attribute;
    }

    /**
     * Load a grid record by its block id (null if not registered yet).
     */
    public function loadGridModel(string $gridBlockId): ?MageAustralia_AdminGrid_Model_Grid
    {
        /** @var MageAustralia_AdminGrid_Model_Grid $grid */
        $grid = Mage::getModel('mageaustralia_admingrid/grid');
        /** @var MageAustralia_AdminGrid_Model_Resource_Grid $gridResource */
        $gridResource = $grid->getResource();
        $gridResource->loadByGridBlockId($grid, $gridBlockId);

        return $grid->getId() ? $grid : null;
    }

    /**
     * Load a stored column record for a grid by code (null if absent).
     */
    public function loadGridColumn(string $gridBlockId, string $columnCode): ?MageAustralia_AdminGrid_Model_Column
    {
        $grid = $this->loadGridModel($gridBlockId);
        if (!$grid) {
            return null;
        }

        /** @var MageAustralia_AdminGrid_Model_Column $column */
        $column = Mage::getModel('mageaustralia_admingrid/column')->getCollection()
            ->addFieldToFilter('grid_id', $grid->getId())
            ->addFieldToFilter('column_code', $columnCode)
            ->getFirstItem();

        return $column->getId() ? $column : null;
    }

    /**
     * Load the editable-enabled column record for a grid by its code.
     *
     * Returns null unless a record exists, is flagged is_editable, is an
     * EAV-attribute column, and resolves to a real, non-static (writable)
     * attribute. Native columns become editable by an admin toggling them on,
     * which persists a lightweight marker record (see reconcileEditableColumns).
     * This is re-run server-side on every save — the client flag is never trusted.
     */
    public function loadEditableColumn(string $gridBlockId, string $columnCode): ?MageAustralia_AdminGrid_Model_Column
    {
        if ($columnCode === '' || in_array($columnCode, self::NON_EDITABLE_CODES, true)) {
            return null;
        }

        $column = $this->loadGridColumn($gridBlockId, $columnCode);
        if (!$column || !$column->isEditable()) {
            return null;
        }

        if ($column->getData('source_type') !== 'eav_attribute') {
            return null;
        }

        return $this->getEditableAttribute($column) ? $column : null;
    }

    /**
     * Resolve the writable EAV attribute behind an editable column, or null.
     */
    public function getEditableAttribute(MageAustralia_AdminGrid_Model_Column $column): ?Mage_Eav_Model_Entity_Attribute_Abstract
    {
        if ($column->getData('source_type') !== 'eav_attribute') {
            return null;
        }

        $config = $column->getSourceConfig();
        $attrCode = $config['attribute_code'] ?? null;
        $entityType = $config['entity_type'] ?? 'catalog_product';

        if (!$attrCode || !$this->isSafeIdentifier((string) $attrCode) || !$this->isSafeIdentifier((string) $entityType)) {
            return null;
        }

        $attribute = Mage::getSingleton('eav/config')->getAttribute($entityType, $attrCode);
        if (!$attribute || !$attribute->getId()) {
            return null;
        }

        // Static attributes (entity_id, sku, ...) are not writable via updateAttributes.
        if ($attribute->getBackendType() === 'static') {
            return null;
        }

        return $attribute;
    }

    /**
     * Build editor metadata (input kind, options, scope label) for an editable
     * column at a given store scope. Returns null when the column is not editable.
     *
     * @return array{input: string, options: array<int, array{value: string, label: string}>, scope_label: string, scope: string, store_id: int}|null
     */
    public function getEditorMeta(MageAustralia_AdminGrid_Model_Column $column, int $storeId): ?array
    {
        $attribute = $this->getEditableAttribute($column);
        if (!$attribute) {
            return null;
        }

        $options = [];
        $input = 'text';
        if ($attribute->usesSource()) {
            $input = 'select';
            /** @phpstan-ignore arguments.count */
            foreach ($attribute->getSource()->getAllOptions(true) as $opt) {
                $options[] = [
                    'value' => (string) $opt['value'],
                    'label' => (string) $opt['label'],
                ];
            }
        }

        return [
            'input'       => $input,
            'options'     => $options,
            'scope'       => $this->getAttributeScope($attribute),
            'scope_label' => $this->getScopeLabel($attribute, $storeId),
            'store_id'    => $this->resolveSaveStoreId($attribute, $storeId),
        ];
    }

    /**
     * Store id at which a value must be written given the attribute's scope.
     * Global attributes always write at the default (store 0).
     */
    public function resolveSaveStoreId(Mage_Eav_Model_Entity_Attribute_Abstract $attribute, int $storeId): int
    {
        if ($this->getAttributeScope($attribute) === 'global') {
            return 0;
        }

        return $storeId > 0 ? $storeId : 0;
    }

    /**
     * Attribute scope from the catalog is_global column (SCOPE_* constants).
     * Non-catalog EAV attributes (e.g. customer) carry no is_global and are
     * treated as global (saved at the default store).
     */
    private function getAttributeScope(Mage_Eav_Model_Entity_Attribute_Abstract $attribute): string
    {
        $isGlobal = $attribute->getData('is_global');
        if ($isGlobal === null) {
            return 'global';
        }

        return match ((int) $isGlobal) {
            Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_WEBSITE => 'website',
            Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_STORE   => 'store',
            default                                                  => 'global',
        };
    }

    private function getScopeLabel(Mage_Eav_Model_Entity_Attribute_Abstract $attribute, int $storeId): string
    {
        if ($this->getAttributeScope($attribute) === 'global') {
            return '[GLOBAL]';
        }

        if ($storeId > 0) {
            return '[' . Mage::app()->getStore($storeId)->getName() . ']';
        }

        return '[' . Mage::helper('adminhtml')->__('Default Values') . ']';
    }

    /**
     * Reconcile the grid's persisted editable-column state to exactly the given
     * set of codes. Called from the profile Save flow so that editability is
     * batched with visible/order/width rather than saved per checkbox toggle.
     *
     * Custom columns store the flag on their own record; native columns are
     * represented by a lightweight marker record that is created/removed here.
     * Only eligible (writable-EAV) codes are honoured — the client is not trusted.
     *
     * @param array<int, mixed> $codes column codes the user marked editable
     */
    public function reconcileEditableColumns(string $gridBlockId, array $codes): void
    {
        $grid = $this->loadGridModel($gridBlockId);
        if (!$grid) {
            return;
        }

        $gridId = (int) $grid->getId();

        // Desired set: only codes that resolve to a writable EAV attribute.
        $desired = [];
        foreach ($codes as $code) {
            $code = (string) $code;
            if ($code !== '' && $this->resolveEligibleAttribute($gridBlockId, $code)) {
                $desired[$code] = true;
            }
        }

        // Enable each desired column (set flag, or create a native marker).
        foreach (array_keys($desired) as $code) {
            $column = $this->loadGridColumn($gridBlockId, $code);
            if ($column) {
                if (!$column->isEditable()) {
                    $column->setData('is_editable', 1)->save();
                }

                continue;
            }

            if (!str_starts_with($code, 'custom_')) {
                $attribute = $this->resolveEligibleAttribute($gridBlockId, $code);
                if ($attribute) {
                    $this->createNativeMarker($gridId, $code, $attribute);
                }
            }
        }

        // Disable anything currently editable but no longer in the desired set.
        $current = Mage::getModel('mageaustralia_admingrid/column')->getCollection();
        if ($current === false) {
            return;
        }

        $current->addFieldToFilter('grid_id', $gridId)
            ->addFieldToFilter('is_editable', 1);

        foreach ($current as $column) {
            $code = (string) $column->getData('column_code');
            if (isset($desired[$code])) {
                continue;
            }

            // Native marker rows exist only to carry the flag → remove them.
            if (!str_starts_with($code, 'custom_') && (bool) ($column->getSourceConfig()['native_marker'] ?? false)) {
                $column->delete();
            } else {
                $column->setData('is_editable', 0)->save();
            }
        }
    }

    /**
     * Persist a marker record that flags a native grid column inline-editable.
     */
    private function createNativeMarker(int $gridId, string $columnCode, Mage_Eav_Model_Entity_Attribute_Abstract $attribute): void
    {
        Mage::getModel('mageaustralia_admingrid/column')->setData([
            'grid_id'       => $gridId,
            'column_code'   => $columnCode,
            'header'        => $attribute->getFrontendLabel() ?: $columnCode,
            'column_type'   => 'text',
            'source_type'   => 'eav_attribute',
            'source_config' => Mage::helper('core')->jsonEncode([
                'attribute_code' => $attribute->getAttributeCode(),
                'entity_type'    => 'catalog_product',
                'native_marker'  => true,
            ]),
            'sort_order'    => 0,
            'is_active'     => 1,
            'is_editable'   => 1,
        ])->save();
    }
}
