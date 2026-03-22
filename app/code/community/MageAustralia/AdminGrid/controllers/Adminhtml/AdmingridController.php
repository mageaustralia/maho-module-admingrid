<?php

declare(strict_types=1);

class MageAustralia_AdminGrid_Adminhtml_AdmingridController extends Mage_Adminhtml_Controller_Action
{
    protected function _isAllowed(): bool
    {
        return Mage::getSingleton('admin/session')
            ->isAllowed('system/mageaustralia_admingrid');
    }

    /**
     * Skip secret key validation for AJAX actions.
     * These are protected by form_key + session auth instead.
     */
    protected function _validateSecretKey(): bool
    {
        $action = $this->getRequest()->getActionName();
        $ajaxActions = ['load', 'saveProfile', 'deleteProfile', 'setDefault', 'availableColumns', 'addColumn', 'removeColumn', 'renameColumn'];

        if (in_array($action, $ajaxActions) && $this->getRequest()->getParam('isAjax')) {
            return true;
        }

        return parent::_validateSecretKey();
    }

    // ── Phase 5: Admin Config UI ─────────────────────────────────────

    /**
     * Grid registry list.
     */
    public function indexAction(): void
    {
        $this->loadLayout();
        $this->_title($this->__('System'))
            ->_title($this->__('Admin Grid Configuration'));
        $this->renderLayout();
    }

    /**
     * Custom columns list for a specific grid.
     */
    public function columnsAction(): void
    {
        $gridId = (int) $this->getRequest()->getParam('grid_id');
        $grid = Mage::getModel('mageaustralia_admingrid/grid')->load($gridId);
        if (!$grid->getId()) {
            $this->_getSession()->addError($this->__('Grid not found.'));
            $this->_redirect('*/*/index');
            return;
        }

        $this->loadLayout();
        $this->_title($this->__('System'))
            ->_title($this->__('Custom Columns — %s', $grid->getData('grid_block_id')));
        $this->renderLayout();
    }

    /**
     * New custom column form.
     */
    public function newColumnAction(): void
    {
        $this->_forward('editColumn');
    }

    /**
     * Edit custom column form.
     */
    public function editColumnAction(): void
    {
        $columnId = (int) $this->getRequest()->getParam('column_id');
        $column = Mage::getModel('mageaustralia_admingrid/column');

        if ($columnId) {
            $column->load($columnId);
            if (!$column->getId()) {
                $this->_getSession()->addError($this->__('Column not found.'));
                $this->_redirect('*/*/index');
                return;
            }
        }

        Mage::register('admingrid_custom_column', $column);

        $this->loadLayout();
        $this->_title($this->__('System'))
            ->_title($column->getId()
                ? $this->__('Edit Column: %s', $column->getData('header'))
                : $this->__('New Custom Column'));
        $this->renderLayout();
    }

    /**
     * Save custom column.
     */
    public function saveColumnAction(): void
    {
        if (!$this->getRequest()->isPost()) {
            $this->_redirect('*/*/index');
            return;
        }

        $data = $this->getRequest()->getPost();
        $columnId = isset($data['column_id']) ? (int) $data['column_id'] : null;
        $gridId = (int) ($data['grid_id'] ?? 0);

        $column = Mage::getModel('mageaustralia_admingrid/column');
        if ($columnId) {
            $column->load($columnId);
        }

        // Validate source_config is valid JSON if provided
        if (!empty($data['source_config'])) {
            $decoded = json_decode($data['source_config'], true);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                $this->_getSession()->addError($this->__('Source Config must be valid JSON.'));
                $this->_redirectReferer();
                return;
            }
        }

        $column->addData([
            'grid_id'       => $gridId,
            'column_code'   => $data['column_code'] ?? '',
            'header'        => $data['header'] ?? '',
            'column_type'   => $data['column_type'] ?? 'text',
            'source_type'   => $data['source_type'] ?? 'static',
            'source_config' => $data['source_config'] ?? null,
            'sort_order'    => (int) ($data['sort_order'] ?? 0),
            'is_active'     => (int) ($data['is_active'] ?? 1),
        ]);

        try {
            $column->save();
            $this->_getSession()->addSuccess($this->__('Column saved.'));
            $this->_redirect('*/*/columns', ['grid_id' => $gridId]);
        } catch (Exception $e) {
            Mage::logException($e);
            $this->_getSession()->addError($this->__('Failed to save: %s', $e->getMessage()));
            $this->_redirectReferer();
        }
    }

    /**
     * Delete custom column.
     */
    public function deleteColumnAction(): void
    {
        $columnId = (int) $this->getRequest()->getParam('column_id');
        $column = Mage::getModel('mageaustralia_admingrid/column')->load($columnId);

        if (!$column->getId()) {
            $this->_getSession()->addError($this->__('Column not found.'));
            $this->_redirect('*/*/index');
            return;
        }

        $gridId = $column->getData('grid_id');

        try {
            $column->delete();
            $this->_getSession()->addSuccess($this->__('Column deleted.'));
        } catch (Exception $e) {
            Mage::logException($e);
            $this->_getSession()->addError($this->__('Failed to delete.'));
        }

        $this->_redirect('*/*/columns', ['grid_id' => $gridId]);
    }

    // ── Phase 3: JSON API for JS ─────────────────────────────────────

    /**
     * GET: Load profile data + column definitions for a grid.
     */
    public function loadAction(): void
    {
        $gridBlockId = $this->getRequest()->getParam('grid_block_id');
        if (!$gridBlockId) {
            $this->_sendJson(['error' => 'Missing grid_block_id'], 400);
            return;
        }

        $userId = (int) Mage::getSingleton('admin/session')->getUser()->getId();

        $grid = Mage::getModel('mageaustralia_admingrid/grid');
        $grid->getResource()->loadByGridBlockId($grid, $gridBlockId);

        if (!$grid->getId()) {
            $this->_sendJson(['error' => 'Grid not found'], 404);
            return;
        }

        $gridId = (int) $grid->getId();

        $profiles = Mage::getModel('mageaustralia_admingrid/profile')
            ->getCollection()
            ->addUserGridFilter($gridId, $userId)
            ->setOrder('is_default', 'DESC')
            ->setOrder('profile_name', 'ASC');

        $profilesData = [];
        $activeProfile = null;

        foreach ($profiles as $profile) {
            $profilesData[] = [
                'id'        => (int) $profile->getId(),
                'name'      => $profile->getData('profile_name'),
                'isDefault' => (bool) $profile->getData('is_default'),
            ];
            if ($profile->getData('is_default')) {
                $activeProfile = $profile;
            }
        }

        if (!$activeProfile && count($profilesData) > 0) {
            $activeProfile = $profiles->getFirstItem();
        }

        $this->_sendJson([
            'gridId'      => $gridId,
            'profileId'   => $activeProfile ? (int) $activeProfile->getId() : null,
            'profileName' => $activeProfile ? $activeProfile->getData('profile_name') : null,
            'profiles'    => $profilesData,
            'config'      => $activeProfile ? $activeProfile->getColumnConfig() : [],
        ]);
    }

    /**
     * POST: Save profile (create or update).
     */
    public function saveProfileAction(): void
    {
        if (!$this->getRequest()->isPost()) {
            $this->_sendJson(['error' => 'POST required'], 405);
            return;
        }

        $userId = (int) Mage::getSingleton('admin/session')->getUser()->getId();
        $gridId = (int) $this->getRequest()->getParam('grid_id');
        $profileId = $this->getRequest()->getParam('profile_id');
        $profileName = trim((string) $this->getRequest()->getParam('profile_name', 'Default'));
        $columnConfig = $this->getRequest()->getParam('column_config');
        $isDefault = (int) $this->getRequest()->getParam('is_default', 1);

        if (!$gridId || !$columnConfig) {
            $this->_sendJson(['error' => 'Missing required fields'], 400);
            return;
        }

        $decoded = json_decode($columnConfig, true);
        if (!is_array($decoded)) {
            $this->_sendJson(['error' => 'Invalid column_config JSON'], 400);
            return;
        }

        $grid = Mage::getModel('mageaustralia_admingrid/grid')->load($gridId);
        if (!$grid->getId()) {
            $this->_sendJson(['error' => 'Grid not found'], 404);
            return;
        }

        $profile = Mage::getModel('mageaustralia_admingrid/profile');

        if ($profileId) {
            $profile->load($profileId);
            if ((int) $profile->getData('user_id') !== $userId) {
                $this->_sendJson(['error' => 'Access denied'], 403);
                return;
            }
        }

        $profile->addData([
            'grid_id'       => $gridId,
            'user_id'       => $userId,
            'profile_name'  => $profileName,
            'column_config' => $columnConfig,
            'is_default'    => $isDefault,
        ]);

        try {
            $profile->save();
            $this->_sendJson([
                'success'   => true,
                'profileId' => (int) $profile->getId(),
            ]);
        } catch (Exception $e) {
            Mage::logException($e);
            $this->_sendJson(['error' => 'Failed to save profile'], 500);
        }
    }

    /**
     * POST: Delete a profile.
     */
    public function deleteProfileAction(): void
    {
        if (!$this->getRequest()->isPost()) {
            $this->_sendJson(['error' => 'POST required'], 405);
            return;
        }

        $userId = (int) Mage::getSingleton('admin/session')->getUser()->getId();
        $profileId = (int) $this->getRequest()->getParam('profile_id');

        if (!$profileId) {
            $this->_sendJson(['error' => 'Missing profile_id'], 400);
            return;
        }

        $profile = Mage::getModel('mageaustralia_admingrid/profile')->load($profileId);
        if (!$profile->getId() || (int) $profile->getData('user_id') !== $userId) {
            $this->_sendJson(['error' => 'Profile not found or access denied'], 404);
            return;
        }

        try {
            $profile->delete();
            $this->_sendJson(['success' => true]);
        } catch (Exception $e) {
            Mage::logException($e);
            $this->_sendJson(['error' => 'Failed to delete profile'], 500);
        }
    }

    /**
     * POST: Set a profile as default (active).
     */
    public function setDefaultAction(): void
    {
        if (!$this->getRequest()->isPost()) {
            $this->_sendJson(['error' => 'POST required'], 405);
            return;
        }

        $userId = (int) Mage::getSingleton('admin/session')->getUser()->getId();
        $profileId = (int) $this->getRequest()->getParam('profile_id');

        $profile = Mage::getModel('mageaustralia_admingrid/profile')->load($profileId);
        if (!$profile->getId() || (int) $profile->getData('user_id') !== $userId) {
            $this->_sendJson(['error' => 'Profile not found or access denied'], 404);
            return;
        }

        $profile->setData('is_default', 1);

        try {
            $profile->save();
            $this->_sendJson(['success' => true]);
        } catch (Exception $e) {
            Mage::logException($e);
            $this->_sendJson(['error' => 'Failed to set default'], 500);
        }
    }

    /**
     * GET: Return available (not-yet-added) attributes for a grid.
     * The JS uses this to populate the "Add Column" section of the dropdown.
     */
    public function availableColumnsAction(): void
    {
        $gridBlockId = $this->getRequest()->getParam('grid_block_id');
        if (!$gridBlockId) {
            $this->_sendJson(['error' => 'Missing grid_block_id'], 400);
            return;
        }

        $helper = Mage::helper('mageaustralia_admingrid');
        $entityType = $helper->getEntityTypeForGrid($gridBlockId);

        $available = [];
        $existingCodes = [];

        // Load grid and existing custom columns
        $grid = Mage::getModel('mageaustralia_admingrid/grid');
        $grid->getResource()->loadByGridBlockId($grid, $gridBlockId);

        if ($grid->getId()) {
            $existing = Mage::getModel('mageaustralia_admingrid/column')
                ->getCollection()
                ->addFieldToFilter('grid_id', $grid->getId());
            foreach ($existing as $col) {
                $cfg = json_decode($col->getData('source_config') ?: '{}', true);
                if (!empty($cfg['attribute_code'])) {
                    $existingCodes[] = $cfg['attribute_code'];
                }
                if (!empty($cfg['column_name'])) {
                    $existingCodes[] = $cfg['column_name'];
                }
            }
        }

        // EAV attributes (product/customer grids)
        if ($entityType) {
            $attrs = $helper->getAvailableAttributes($entityType);
            foreach ($attrs as $code => $attr) {
                if (!in_array($code, $existingCodes)) {
                    $available[] = [
                        'code'       => $code,
                        'label'      => $attr['label'],
                        'type'       => $attr['type'],
                        'input'      => $attr['input'],
                        'group'      => 'attribute',
                        'entityType' => $entityType,
                    ];
                }
            }
        }

        // Collection columns (flat table fields from DB + related tables)
        $collectionCols = $helper->getCollectionColumns($gridBlockId);
        foreach ($collectionCols as $colCode => $col) {
            if (!in_array($colCode, $existingCodes)) {
                $entry = array_merge($col, ['group' => 'collection']);
                // Pass related table info for cross-table JOINs
                if (!empty($col['related_table'])) {
                    $entry['relatedTable'] = $col['related_table'];
                    $entry['joinOn'] = $col['join_on'];
                }
                $available[] = $entry;
            }
        }

        // Composite columns (address views, ordered items)
        $composites = $helper->getCompositeColumns($gridBlockId);
        foreach ($composites as $code => $comp) {
            if (!in_array($code, $existingCodes)) {
                $available[] = [
                    'code'      => $code,
                    'label'     => $comp['label'],
                    'type'      => 'composite',
                    'group'     => 'composite',
                    'config'    => $comp['config'],
                ];
            }
        }

        $this->_sendJson(['available' => $available]);
    }

    /**
     * POST: Quick-add an attribute as a custom column from the JS dropdown.
     * No admin config page needed — creates the column record automatically.
     */
    public function addColumnAction(): void
    {
        if (!$this->getRequest()->isPost()) {
            $this->_sendJson(['error' => 'POST required'], 405);
            return;
        }

        $gridBlockId = $this->getRequest()->getParam('grid_block_id');
        $attrCode = $this->getRequest()->getParam('attribute_code');
        $group = $this->getRequest()->getParam('group', 'attribute'); // 'attribute' or 'collection'
        $entityType = $this->getRequest()->getParam('entity_type', 'catalog_product');
        $label = $this->getRequest()->getParam('label', $attrCode);
        $colType = $this->getRequest()->getParam('column_type', 'text');

        if (!$gridBlockId || !$attrCode) {
            $this->_sendJson(['error' => 'Missing required fields'], 400);
            return;
        }

        $grid = Mage::getModel('mageaustralia_admingrid/grid');
        $grid->getResource()->loadByGridBlockId($grid, $gridBlockId);
        if (!$grid->getId()) {
            $this->_sendJson(['error' => 'Grid not registered yet'], 404);
            return;
        }

        $columnCode = 'custom_' . $attrCode;

        $existing = Mage::getModel('mageaustralia_admingrid/column')->getCollection()
            ->addFieldToFilter('grid_id', $grid->getId())
            ->addFieldToFilter('column_code', $columnCode)
            ->getFirstItem();

        if ($existing->getId()) {
            $this->_sendJson(['error' => 'Column already exists', 'columnCode' => $columnCode], 409);
            return;
        }

        // Determine source type and config based on group
        if ($group === 'composite') {
            $sourceType = 'computed';
            $configRaw = $this->getRequest()->getParam('config');
            $sourceConfig = is_string($configRaw) ? $configRaw : json_encode($configRaw);
            $colType = 'text'; // rendered by composite renderer
        } elseif ($group === 'collection') {
            $sourceType = 'static';
            $configData = ['column_name' => $attrCode];
            $relatedTable = $this->getRequest()->getParam('related_table');
            $joinOn = $this->getRequest()->getParam('join_on');
            if ($relatedTable) {
                $configData['related_table'] = $relatedTable;
                $configData['join_on'] = $joinOn;
            }
            $sourceConfig = json_encode($configData);
        } else {
            $sourceType = 'eav_attribute';
            $sourceConfig = json_encode([
                'attribute_code' => $attrCode,
                'entity_type'    => $entityType,
            ]);
        }

        $column = Mage::getModel('mageaustralia_admingrid/column');
        $column->setData([
            'grid_id'       => $grid->getId(),
            'column_code'   => $columnCode,
            'header'        => $label,
            'column_type'   => $colType,
            'source_type'   => $sourceType,
            'source_config' => $sourceConfig,
            'sort_order'    => 0,
            'is_active'     => 1,
        ]);

        try {
            $column->save();
            $this->_sendJson([
                'success'    => true,
                'columnCode' => $columnCode,
                'columnId'   => (int) $column->getId(),
            ]);
        } catch (Exception $e) {
            Mage::logException($e);
            $this->_sendJson(['error' => 'Failed to add column'], 500);
        }
    }

    /**
     * POST: Rename a custom column's header.
     */
    public function renameColumnAction(): void
    {
        if (!$this->getRequest()->isPost()) {
            $this->_sendJson(['error' => 'POST required'], 405);
            return;
        }

        $gridBlockId = $this->getRequest()->getParam('grid_block_id');
        $columnCode = $this->getRequest()->getParam('column_code');
        $header = trim((string) $this->getRequest()->getParam('header'));

        if (!$gridBlockId || !$columnCode || !$header) {
            $this->_sendJson(['error' => 'Missing required fields'], 400);
            return;
        }

        $grid = Mage::getModel('mageaustralia_admingrid/grid');
        $grid->getResource()->loadByGridBlockId($grid, $gridBlockId);
        if (!$grid->getId()) {
            $this->_sendJson(['error' => 'Grid not found'], 404);
            return;
        }

        $column = Mage::getModel('mageaustralia_admingrid/column')->getCollection()
            ->addFieldToFilter('grid_id', $grid->getId())
            ->addFieldToFilter('column_code', $columnCode)
            ->getFirstItem();

        if (!$column->getId()) {
            $this->_sendJson(['error' => 'Column not found'], 404);
            return;
        }

        try {
            $column->setData('header', $header)->save();
            $this->_sendJson(['success' => true]);
        } catch (Exception $e) {
            Mage::logException($e);
            $this->_sendJson(['error' => 'Failed to rename'], 500);
        }
    }

    /**
     * POST: Remove a custom column by code + grid_block_id.
     */
    public function removeColumnAction(): void
    {
        if (!$this->getRequest()->isPost()) {
            $this->_sendJson(['error' => 'POST required'], 405);
            return;
        }

        $gridBlockId = $this->getRequest()->getParam('grid_block_id');
        $columnCode = $this->getRequest()->getParam('column_code');

        if (!$gridBlockId || !$columnCode) {
            $this->_sendJson(['error' => 'Missing required fields'], 400);
            return;
        }

        $grid = Mage::getModel('mageaustralia_admingrid/grid');
        $grid->getResource()->loadByGridBlockId($grid, $gridBlockId);
        if (!$grid->getId()) {
            $this->_sendJson(['error' => 'Grid not found'], 404);
            return;
        }

        $column = Mage::getModel('mageaustralia_admingrid/column')->getCollection()
            ->addFieldToFilter('grid_id', $grid->getId())
            ->addFieldToFilter('column_code', $columnCode)
            ->getFirstItem();

        if (!$column->getId()) {
            $this->_sendJson(['error' => 'Column not found'], 404);
            return;
        }

        try {
            $column->delete();
            $this->_sendJson(['success' => true]);
        } catch (Exception $e) {
            Mage::logException($e);
            $this->_sendJson(['error' => 'Failed to remove column'], 500);
        }
    }

    private function _sendJson(array $data, int $httpCode = 200): void
    {
        $this->getResponse()
            ->setHttpResponseCode($httpCode)
            ->setHeader('Content-Type', 'application/json', true)
            ->setBody(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
