<?php

declare(strict_types=1);

class MageAustralia_AdminGrid_Model_Grid extends Mage_Core_Model_Abstract
{
    protected function _construct(): void
    {
        $this->_init('mageaustralia_admingrid/grid');
    }

    /**
     * Load grid by its block ID (e.g. 'sales_order_grid'), or create if not found.
     */
    public function loadOrCreate(string $gridBlockId, string $blockType): self
    {
        $this->getResource()->loadByGridBlockId($this, $gridBlockId);

        if (!$this->getId()) {
            $this->setData([
                'grid_block_id' => $gridBlockId,
                'block_type'    => $blockType,
            ]);
            $this->save();
        }

        return $this;
    }
}
