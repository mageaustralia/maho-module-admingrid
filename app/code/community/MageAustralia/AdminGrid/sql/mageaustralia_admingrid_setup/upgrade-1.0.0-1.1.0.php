<?php

declare(strict_types=1);

/**
 * Add per-column "editable" flag to custom columns.
 *
 * When set, the module marks that column's grid cells as inline-editable:
 * the admin JS shows a hover pencil and an AJAX inline editor that saves the
 * value back to the underlying attribute at the correct store scope.
 *
 * Portable DDL — uses the DBAL addColumn builder (MySQL / PostgreSQL / SQLite).
 *
 * @var Mage_Core_Model_Resource_Setup $installer
 */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();
$columnTable = $installer->getTable('mageaustralia_admingrid/column');

if ($connection->isTableExists($columnTable)
    && !$connection->tableColumnExists($columnTable, 'is_editable')
) {
    $connection->addColumn(
        $columnTable,
        'is_editable',
        [
            'type'     => Maho\Db\Ddl\Table::TYPE_SMALLINT,
            'unsigned' => true,
            'nullable' => false,
            'default'  => 0,
            'comment'  => 'Cells inline-editable via AJAX',
        ],
    );
}

$installer->endSetup();
