<?php

declare(strict_types=1);

class MageAustralia_AdminGrid_Model_Resource_Profile_Collection extends Mage_Core_Model_Resource_Db_Collection_Abstract
{
    protected function _construct(): void
    {
        $this->_init('mageaustralia_admingrid/profile');
    }

    /**
     * Filter by grid + user.
     */
    public function addUserGridFilter(int $gridId, int $userId): self
    {
        $this->addFieldToFilter('grid_id', $gridId);
        $this->addFieldToFilter('user_id', $userId);
        return $this;
    }
}
