<?php

declare(strict_types=1);

class MageAustralia_AdminGrid_Model_Resource_Profile extends Mage_Core_Model_Resource_Db_Abstract
{
    protected function _construct(): void
    {
        $this->_init('mageaustralia_admingrid/profile', 'profile_id');
    }

    /**
     * Load the default profile for a user on a grid.
     */
    public function loadActiveForUser(
        MageAustralia_AdminGrid_Model_Profile $object,
        int $gridId,
        int $userId,
    ): self {
        $read = $this->_getReadAdapter();
        $select = $read->select()
            ->from($this->getMainTable())
            ->where('grid_id = ?', $gridId)
            ->where('user_id = ?', $userId)
            ->where('is_default = ?', 1)
            ->limit(1);

        $data = $read->fetchRow($select);
        if ($data) {
            $object->setData($data);
        }

        return $this;
    }

    /**
     * Clear the is_default flag on all other profiles for this grid+user.
     */
    public function clearDefaultFlag(int $gridId, int $userId, ?int $excludeProfileId = null): void
    {
        $write = $this->_getWriteAdapter();
        $where = [
            'grid_id = ?'    => $gridId,
            'user_id = ?'    => $userId,
            'is_default = ?' => 1,
        ];

        if ($excludeProfileId !== null) {
            $where['profile_id != ?'] = $excludeProfileId;
        }

        $write->update($this->getMainTable(), ['is_default' => 0], $where);
    }
}
