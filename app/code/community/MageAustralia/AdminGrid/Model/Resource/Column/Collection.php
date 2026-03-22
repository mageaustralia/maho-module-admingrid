<?php

declare(strict_types=1);

class MageAustralia_AdminGrid_Model_Resource_Column_Collection extends Mage_Core_Model_Resource_Db_Collection_Abstract
{
    protected function _construct(): void
    {
        $this->_init('mageaustralia_admingrid/column');
    }

    /**
     * Filter by grid and active status.
     */
    public function addActiveGridFilter(int $gridId): self
    {
        $this->addFieldToFilter('grid_id', $gridId);
        $this->addFieldToFilter('is_active', 1);
        $this->setOrder('sort_order', 'ASC');
        return $this;
    }
}
