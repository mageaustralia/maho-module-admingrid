<?php

declare(strict_types=1);

class MageAustralia_AdminGrid_Model_Profile extends Mage_Core_Model_Abstract
{
    protected function _construct(): void
    {
        $this->_init('mageaustralia_admingrid/profile');
    }

    /**
     * Load the active (default) profile for a user on a specific grid.
     */
    public function loadActiveForUser(int $gridId, int $userId): self
    {
        $this->getResource()->loadActiveForUser($this, $gridId, $userId);
        return $this;
    }

    /**
     * Decode column_config JSON to array.
     *
     * @return array<int, array{code: string, visible: bool, position: int, width?: string}>
     */
    public function getColumnConfig(): array
    {
        $json = $this->getData('column_config');
        if (!$json) {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Encode and set column config from array.
     */
    public function setColumnConfig(array $config): self
    {
        $this->setData('column_config', json_encode($config, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * Ensure only one default profile per grid+user.
     */
    protected function _beforeSave(): self
    {
        parent::_beforeSave();

        if ($this->getData('is_default')) {
            $this->getResource()->clearDefaultFlag(
                (int) $this->getData('grid_id'),
                (int) $this->getData('user_id'),
                $this->getId() ? (int) $this->getId() : null,
            );
        }

        return $this;
    }
}
