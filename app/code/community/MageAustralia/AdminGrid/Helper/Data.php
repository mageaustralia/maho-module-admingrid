<?php

declare(strict_types=1);

class MageAustralia_AdminGrid_Helper_Data extends Mage_Core_Helper_Abstract
{
    public const XPATH_ENABLED = 'mageaustralia_admingrid/general/enabled';

    /**
     * Map grid block IDs to their entity type for attribute discovery.
     */
    private const GRID_ENTITY_MAP = [
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
        $class = get_class($grid);
        $config = Mage::getConfig();

        foreach (['adminhtml', 'mageaustralia_admingrid'] as $group) {
            $classPrefix = (string) $config->getNode("global/blocks/{$group}/class");
            if ($classPrefix && str_starts_with($class, $classPrefix)) {
                $suffix = substr($class, strlen($classPrefix) + 1);
                $suffix = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $suffix));
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
                    'fields'       => ['name', 'sku', 'qty_ordered', 'row_total', 'weight'],
                    'template'     => [['name'], ['sku', 'qty_ordered']],
                    'separator'    => ' x ',
                    'multi_row'    => true,
                    'style'        => 'plain',
                    'field_labels' => [
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
    private const GRID_TABLE_MAP = [
        'sales_order_grid'  => 'sales_flat_order_grid',
        'order_grid'        => 'sales_flat_order_grid',
        'customer_grid'     => 'customer_entity',
        'customerGrid'      => 'customer_entity',
    ];

    /**
     * Columns to skip — internal/system columns that aren't useful in grids.
     */
    private const SKIP_COLUMNS = [
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
            $decoded = json_decode($cached, true);
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
        foreach ($tables['related'] as $key => $relConfig) {
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
        if ($primary && $primary !== 'sales_flat_order_grid') {
            if (str_contains($primary, 'invoice') || str_contains($primary, 'shipment') || str_contains($primary, 'creditmemo')) {
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
     * @deprecated Use resolveTablesForGrid instead
     */
    private function resolveTableForGrid(string $gridBlockId): ?string
    {
        return $this->resolveTablesForGrid($gridBlockId)['primary'];
    }

    /**
     * Map MySQL column types to our grid column types.
     */
    private function mapDbTypeToColumnType(string $dbType): string
    {
        $dbType = strtolower($dbType);

        if (in_array($dbType, ['int', 'smallint', 'tinyint', 'mediumint', 'bigint'])) {
            return 'number';
        }
        if (in_array($dbType, ['decimal', 'float', 'double'])) {
            return 'number';
        }
        if (in_array($dbType, ['date', 'datetime', 'timestamp'])) {
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
}
