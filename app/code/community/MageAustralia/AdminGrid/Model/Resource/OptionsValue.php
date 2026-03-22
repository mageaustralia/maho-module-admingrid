<?php

declare(strict_types=1);

class MageAustralia_AdminGrid_Model_Resource_OptionsValue extends Mage_Core_Model_Resource_Db_Abstract
{
    protected function _construct(): void
    {
        $this->_init('mageaustralia_admingrid/options_value', 'value_id');
    }
}
