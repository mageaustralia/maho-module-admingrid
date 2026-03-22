<?php

declare(strict_types=1);

class MageAustralia_AdminGrid_Model_Resource_OptionsSource_Collection extends Mage_Core_Model_Resource_Db_Collection_Abstract
{
    protected function _construct(): void
    {
        $this->_init('mageaustralia_admingrid/options_source');
    }
}
