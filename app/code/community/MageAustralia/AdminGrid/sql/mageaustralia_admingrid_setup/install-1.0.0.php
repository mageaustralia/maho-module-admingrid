<?php

declare(strict_types=1);

/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();

// ── Grid registry (auto-discovered) ──

$gridTable = $installer->getTable('mageaustralia_admingrid/grid');

if (!$connection->isTableExists($gridTable)) {
    $table = $connection
        ->newTable($gridTable)
        ->addColumn(
            'grid_id',
            Varien_Db_Ddl_Table::TYPE_INTEGER,
            null,
            [
                'identity' => true,
                'unsigned' => true,
                'nullable' => false,
                'primary'  => true,
            ],
            'Grid ID',
        )
        ->addColumn(
            'block_type',
            Varien_Db_Ddl_Table::TYPE_TEXT,
            255,
            ['nullable' => false],
            'Grid Block Class Alias',
        )
        ->addColumn(
            'grid_block_id',
            Varien_Db_Ddl_Table::TYPE_TEXT,
            100,
            ['nullable' => false],
            'Grid Block HTML ID',
        )
        ->addColumn(
            'created_at',
            Varien_Db_Ddl_Table::TYPE_TIMESTAMP,
            null,
            [
                'nullable' => false,
                'default'  => Varien_Db_Ddl_Table::TIMESTAMP_INIT,
            ],
            'Created At',
        )
        ->addIndex(
            $installer->getIdxName($gridTable, ['grid_block_id']),
            ['grid_block_id'],
            ['type' => 'unique'],
        )
        ->setComment('MageAustralia AdminGrid — Grid Registry');

    $connection->createTable($table);
}

// ── User profiles ──

$profileTable = $installer->getTable('mageaustralia_admingrid/profile');

if (!$connection->isTableExists($profileTable)) {
    $table = $connection
        ->newTable($profileTable)
        ->addColumn(
            'profile_id',
            Varien_Db_Ddl_Table::TYPE_INTEGER,
            null,
            [
                'identity' => true,
                'unsigned' => true,
                'nullable' => false,
                'primary'  => true,
            ],
            'Profile ID',
        )
        ->addColumn(
            'grid_id',
            Varien_Db_Ddl_Table::TYPE_INTEGER,
            null,
            ['unsigned' => true, 'nullable' => false],
            'Grid ID',
        )
        ->addColumn(
            'user_id',
            Varien_Db_Ddl_Table::TYPE_INTEGER,
            null,
            ['unsigned' => true, 'nullable' => false],
            'Admin User ID',
        )
        ->addColumn(
            'profile_name',
            Varien_Db_Ddl_Table::TYPE_TEXT,
            100,
            ['nullable' => false, 'default' => 'Default'],
            'Profile Name',
        )
        ->addColumn(
            'is_default',
            Varien_Db_Ddl_Table::TYPE_SMALLINT,
            null,
            ['unsigned' => true, 'nullable' => false, 'default' => 1],
            'Is Default Profile',
        )
        ->addColumn(
            'column_config',
            Varien_Db_Ddl_Table::TYPE_TEXT,
            '64k',
            ['nullable' => false],
            'Column Config JSON',
        )
        ->addColumn(
            'created_at',
            Varien_Db_Ddl_Table::TYPE_TIMESTAMP,
            null,
            [
                'nullable' => false,
                'default'  => Varien_Db_Ddl_Table::TIMESTAMP_INIT,
            ],
            'Created At',
        )
        ->addColumn(
            'updated_at',
            Varien_Db_Ddl_Table::TYPE_TIMESTAMP,
            null,
            [
                'nullable' => false,
                'default'  => Varien_Db_Ddl_Table::TIMESTAMP_INIT_UPDATE,
            ],
            'Updated At',
        )
        ->addIndex(
            $installer->getIdxName($profileTable, ['grid_id', 'user_id', 'is_default']),
            ['grid_id', 'user_id', 'is_default'],
        )
        ->addForeignKey(
            $installer->getFkName($profileTable, 'grid_id', $gridTable, 'grid_id'),
            'grid_id',
            $gridTable,
            'grid_id',
            Varien_Db_Ddl_Table::ACTION_CASCADE,
        )
        ->addForeignKey(
            $installer->getFkName($profileTable, 'user_id', 'admin/user', 'user_id'),
            'user_id',
            $installer->getTable('admin/user'),
            'user_id',
            Varien_Db_Ddl_Table::ACTION_CASCADE,
        )
        ->setComment('MageAustralia AdminGrid — User Profiles');

    $connection->createTable($table);
}

// ── Custom column definitions ──

$columnTable = $installer->getTable('mageaustralia_admingrid/column');

if (!$connection->isTableExists($columnTable)) {
    $table = $connection
        ->newTable($columnTable)
        ->addColumn(
            'column_id',
            Varien_Db_Ddl_Table::TYPE_INTEGER,
            null,
            [
                'identity' => true,
                'unsigned' => true,
                'nullable' => false,
                'primary'  => true,
            ],
            'Column ID',
        )
        ->addColumn(
            'grid_id',
            Varien_Db_Ddl_Table::TYPE_INTEGER,
            null,
            ['unsigned' => true, 'nullable' => false],
            'Grid ID',
        )
        ->addColumn(
            'column_code',
            Varien_Db_Ddl_Table::TYPE_TEXT,
            100,
            ['nullable' => false],
            'Column Code',
        )
        ->addColumn(
            'header',
            Varien_Db_Ddl_Table::TYPE_TEXT,
            255,
            ['nullable' => false],
            'Column Header Label',
        )
        ->addColumn(
            'column_type',
            Varien_Db_Ddl_Table::TYPE_TEXT,
            50,
            ['nullable' => false, 'default' => 'text'],
            'Column Type (text, options, image, date, number)',
        )
        ->addColumn(
            'source_type',
            Varien_Db_Ddl_Table::TYPE_TEXT,
            50,
            ['nullable' => false],
            'Source Type (eav_attribute, computed, static)',
        )
        ->addColumn(
            'source_config',
            Varien_Db_Ddl_Table::TYPE_TEXT,
            '16k',
            ['nullable' => true],
            'JSON Config for the Source',
        )
        ->addColumn(
            'sort_order',
            Varien_Db_Ddl_Table::TYPE_INTEGER,
            null,
            ['nullable' => false, 'default' => 0],
            'Sort Order',
        )
        ->addColumn(
            'is_active',
            Varien_Db_Ddl_Table::TYPE_SMALLINT,
            null,
            ['unsigned' => true, 'nullable' => false, 'default' => 1],
            'Is Active',
        )
        ->addIndex(
            $installer->getIdxName($columnTable, ['grid_id', 'column_code']),
            ['grid_id', 'column_code'],
            ['type' => 'unique'],
        )
        ->addForeignKey(
            $installer->getFkName($columnTable, 'grid_id', $gridTable, 'grid_id'),
            'grid_id',
            $gridTable,
            'grid_id',
            Varien_Db_Ddl_Table::ACTION_CASCADE,
        )
        ->setComment('MageAustralia AdminGrid — Custom Column Definitions');

    $connection->createTable($table);
}

// ── Options sources ──

$optionsSourceTable = $installer->getTable('mageaustralia_admingrid/options_source');

if (!$connection->isTableExists($optionsSourceTable)) {
    $table = $connection
        ->newTable($optionsSourceTable)
        ->addColumn(
            'source_id',
            Varien_Db_Ddl_Table::TYPE_INTEGER,
            null,
            [
                'identity' => true,
                'unsigned' => true,
                'nullable' => false,
                'primary'  => true,
            ],
            'Source ID',
        )
        ->addColumn(
            'name',
            Varien_Db_Ddl_Table::TYPE_TEXT,
            100,
            ['nullable' => false],
            'Source Name',
        )
        ->addColumn(
            'type',
            Varien_Db_Ddl_Table::TYPE_TEXT,
            50,
            ['nullable' => false, 'default' => 'list'],
            'Source Type (list, model)',
        )
        ->addColumn(
            'model_class',
            Varien_Db_Ddl_Table::TYPE_TEXT,
            255,
            ['nullable' => true],
            'Model Class Alias',
        )
        ->addColumn(
            'model_method',
            Varien_Db_Ddl_Table::TYPE_TEXT,
            100,
            ['nullable' => true],
            'Model Method Name',
        )
        ->setComment('MageAustralia AdminGrid — Options Sources');

    $connection->createTable($table);
}

// ── Options source values ──

$optionsValueTable = $installer->getTable('mageaustralia_admingrid/options_value');

if (!$connection->isTableExists($optionsValueTable)) {
    $table = $connection
        ->newTable($optionsValueTable)
        ->addColumn(
            'value_id',
            Varien_Db_Ddl_Table::TYPE_INTEGER,
            null,
            [
                'identity' => true,
                'unsigned' => true,
                'nullable' => false,
                'primary'  => true,
            ],
            'Value ID',
        )
        ->addColumn(
            'source_id',
            Varien_Db_Ddl_Table::TYPE_INTEGER,
            null,
            ['unsigned' => true, 'nullable' => false],
            'Source ID',
        )
        ->addColumn(
            'option_value',
            Varien_Db_Ddl_Table::TYPE_TEXT,
            255,
            ['nullable' => false],
            'Option Value',
        )
        ->addColumn(
            'option_label',
            Varien_Db_Ddl_Table::TYPE_TEXT,
            255,
            ['nullable' => false],
            'Option Label',
        )
        ->addColumn(
            'sort_order',
            Varien_Db_Ddl_Table::TYPE_INTEGER,
            null,
            ['nullable' => false, 'default' => 0],
            'Sort Order',
        )
        ->addForeignKey(
            $installer->getFkName($optionsValueTable, 'source_id', $optionsSourceTable, 'source_id'),
            'source_id',
            $optionsSourceTable,
            'source_id',
            Varien_Db_Ddl_Table::ACTION_CASCADE,
        )
        ->setComment('MageAustralia AdminGrid — Options Source Values');

    $connection->createTable($table);
}

$installer->endSetup();
