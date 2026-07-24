<?php

declare(strict_types=1);

/**
 * No schema change — version bump only.
 *
 * The per-column Editable toggle is now batched with the profile Save flow
 * (like visible/order/width) instead of persisting on each click; the
 * versioned admin CSS/JS assets are re-stamped so browsers refetch them.
 * Recorded here so the resource version advances cleanly to 1.1.2.
 *
 * @var Mage_Core_Model_Resource_Setup $installer
 */
$installer = $this;
$installer->startSetup();
$installer->endSetup();
