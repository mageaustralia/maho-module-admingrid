<?php

declare(strict_types=1);

class MageAustralia_AdminGrid_Model_OptionsSource extends Mage_Core_Model_Abstract
{
    protected function _construct(): void
    {
        $this->_init('mageaustralia_admingrid/options_source');
    }

    /**
     * Get options as value => label array.
     */
    public function toOptionArray(): array
    {
        if ($this->getData('type') === 'model' && $this->getData('model_class')) {
            $model = Mage::getModel($this->getData('model_class'));
            $method = $this->getData('model_method') ?: 'toOptionArray';
            if (method_exists($model, $method)) {
                return $model->$method();
            }
            return [];
        }

        // List type — load from options_value table
        $values = Mage::getModel('mageaustralia_admingrid/optionsValue')
            ->getCollection()
            ->addFieldToFilter('source_id', $this->getId())
            ->setOrder('sort_order', 'ASC');

        $options = [];
        foreach ($values as $value) {
            $options[$value->getData('option_value')] = $value->getData('option_label');
        }

        return $options;
    }
}
