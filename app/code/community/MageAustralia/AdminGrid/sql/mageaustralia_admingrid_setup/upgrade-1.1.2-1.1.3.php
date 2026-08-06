<?php

declare(strict_types=1);

/**
 * Order-number substring search: FULLTEXT index on sales_flat_order_grid.increment_id
 * WITH PARSER ngram.
 *
 * The Order # grid search now does a fast prefix match first and, if nothing starts
 * with the term, falls back to a substring search so the shopper can search by the tail
 * (e.g. "234531" of "9100234531"). That fallback uses MATCH(increment_id) AGAINST(...)
 * which needs a FULLTEXT index built with the *ngram* parser — a plain fulltext on the
 * single numeric increment_id token only matches the whole value / a prefix, never the
 * tail.
 *
 * sql/schema.php declares a fulltext on increment_id so `./maho migrate` KEEPS the index
 * (it fingerprints by type+columns), but Doctrine cannot express WITH PARSER ngram, so
 * the parser is enforced here. Idempotent: a no-op once the index is already ngram; it
 * only pays the (~20s on ~300k rows) rebuild when the parser is missing.
 *
 * @var Mage_Core_Model_Resource_Setup $installer
 */
/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this; // @phpstan-ignore variable.undefined (setup scripts are include()d with $this = the setup model)
$installer->startSetup();

$conn  = $installer->getConnection();
$table = $installer->getTable('sales/order_grid'); // sales_flat_order_grid

$ddl = '';
$row = $conn->fetchRow('SHOW CREATE TABLE ' . $conn->quoteIdentifier($table));
if (is_array($row)) {
    $ddl = (string) ($row['Create Table'] ?? '');
}

$hasIndex = stripos($ddl, 'FTX_SFOG_INCREMENT_NGRAM') !== false;
$hasNgram = $hasIndex && (bool) preg_match('/FTX_SFOG_INCREMENT_NGRAM.{0,120}PARSER\s+`?ngram/is', $ddl);

if (!$hasNgram) {
    if ($hasIndex) {
        $conn->query('ALTER TABLE ' . $conn->quoteIdentifier($table) . ' DROP INDEX FTX_SFOG_INCREMENT_NGRAM');
    }
    $conn->query(
        'ALTER TABLE ' . $conn->quoteIdentifier($table)
        . ' ADD FULLTEXT INDEX FTX_SFOG_INCREMENT_NGRAM (increment_id) WITH PARSER ngram',
    );
}

$installer->endSetup();
