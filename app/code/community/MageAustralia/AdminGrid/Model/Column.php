<?php

declare(strict_types=1);

class MageAustralia_AdminGrid_Model_Column extends Mage_Core_Model_Abstract
{
    protected function _construct(): void
    {
        $this->_init('mageaustralia_admingrid/column');
    }

    /**
     * Decode source_config JSON.
     */
    public function getSourceConfig(): array
    {
        $json = $this->getData('source_config');
        if (!$json) {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
