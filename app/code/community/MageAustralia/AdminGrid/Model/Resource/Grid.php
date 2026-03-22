<?php

declare(strict_types=1);

class MageAustralia_AdminGrid_Model_Resource_Grid extends Mage_Core_Model_Resource_Db_Abstract
{
    protected function _construct(): void
    {
        $this->_init('mageaustralia_admingrid/grid', 'grid_id');
    }

    public function loadByGridBlockId(
        MageAustralia_AdminGrid_Model_Grid $object,
        string $gridBlockId,
    ): self {
        $read = $this->_getReadAdapter();
        $select = $read->select()
            ->from($this->getMainTable())
            ->where('grid_block_id = ?', $gridBlockId)
            ->limit(1);

        $data = $read->fetchRow($select);
        if ($data) {
            $object->setData($data);
        }

        return $this;
    }
}
