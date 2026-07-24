<?php

declare(strict_types=1);

/**
 * No schema change — version bump only.
 *
 * Broadens inline cell editing to native product-grid columns (resolved to
 * writable EAV attributes by the backend) and re-stamps the versioned admin
 * CSS/JS assets so browsers refetch them. Recorded here so the resource
 * version advances cleanly to 1.1.1.
 *
 * @var Mage_Core_Model_Resource_Setup $installer
 */
$installer = $this;
$installer->startSetup();
$installer->endSetup();
