<?php

declare(strict_types=1);

/**
 * MageAustralia_AdminGrid - declarative schema additions for sales_flat_order_grid.
 *
 * These live in a module sql/schema.php because `./maho migrate` DROPS any index on a
 * managed table that no module declares ("removed on convergence"). Custom search
 * indexes added by raw ALTER or a legacy setup script are therefore silently dropped
 * on the next migrate, regressing admin order-grid search to slow full scans (this bit
 * us: the phone index vanished). Declaring them here keeps them.
 *
 * All on the core (Mage_Sales-owned) sales_flat_order_grid:
 *   - FULLTEXT billing_name / shipping_name  -> fast word-based name search (MATCH ... AGAINST)
 *   - phone + phone_digits (VIRTUAL, digits-only) + their B-tree indexes
 *     -> fast, format-tolerant phone search (originally added via a ShipEasy upgrade)
 *
 * The closure runs after core modules, so the table already exists; Maho fingerprints
 * indexes by type+columns, so index names here need not match a prior ALTER.
 */

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return function (Schema $schema): void {
    if (!$schema->hasTable('sales_flat_order_grid')) {
        return;
    }

    $grid = $schema->getTable('sales_flat_order_grid');

    // --- Name search: FULLTEXT on billing/shipping name ---
    if (!$grid->hasIndex('FTX_SFOG_BILLING_NAME')) {
        $grid->addIndex(['billing_name'], 'FTX_SFOG_BILLING_NAME', ['fulltext']);
    }
    if (!$grid->hasIndex('FTX_SFOG_SHIPPING_NAME')) {
        $grid->addIndex(['shipping_name'], 'FTX_SFOG_SHIPPING_NAME', ['fulltext']);
    }

    // --- Order-number substring search: ngram FULLTEXT on increment_id ---
    // Declared here so `./maho migrate` KEEPS it (convergence drops undeclared indexes;
    // see header). Doctrine's addIndex cannot express WITH PARSER ngram, so the parser
    // itself is applied by the mageaustralia_admingrid_setup 1.1.3 upgrade. Migrate
    // fingerprints indexes by type+columns (fulltext + increment_id), so it treats this
    // declaration and the ngram index as the same and does not rebuild it as a plain
    // fulltext. Needed for the "search by order tail" fallback (e.g. 234531 of 9100234531).
    if (!$grid->hasIndex('FTX_SFOG_INCREMENT_NGRAM')) {
        $grid->addIndex(['increment_id'], 'FTX_SFOG_INCREMENT_NGRAM', ['fulltext']);
    }

    // --- Phone search: denormalized phone + VIRTUAL digits-only column, both indexed ---
    if (!$grid->hasColumn('phone')) {
        $grid->addColumn('phone', Types::STRING, ['length' => 255, 'notnull' => false]);
    }
    if (!$grid->hasColumn('phone_digits')) {
        // VIRTUAL generated column: digits only, stays in sync with phone. Declared via
        // columnDefinition (Maho's Canonicalizer includes columnDefinition) so migrate
        // keeps it as-is. No stored data (VIRTUAL), so a rebuild is harmless.
        $grid->addColumn('phone_digits', Types::STRING, [
            'length' => 32,
            'notnull' => false,
            'columnDefinition' => "varchar(32) GENERATED ALWAYS AS (regexp_replace(coalesce(`phone`,''),'[^0-9]','')) VIRTUAL",
        ]);
    }
    if (!$grid->hasIndex('IDX_SFOG_PHONE')) {
        $grid->addIndex(['phone'], 'IDX_SFOG_PHONE');
    }
    if (!$grid->hasIndex('IDX_SFOG_PHONE_DIGITS')) {
        $grid->addIndex(['phone_digits'], 'IDX_SFOG_PHONE_DIGITS');
    }
};
