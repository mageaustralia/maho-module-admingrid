<?php

declare(strict_types=1);

/**
 * Outputs AdminGrid JS configuration as a <script> block.
 * Provides admin URLs with secret keys so JS can make valid requests.
 */
class MageAustralia_AdminGrid_Block_Adminhtml_JsConfig extends Mage_Core_Block_Template
{
    protected function _toHtml(): string
    {
        if (!Mage::helper('mageaustralia_admingrid')->isEnabled()) {
            return '';
        }

        $config = [
            'urls' => [
                'load'          => $this->getUrl('adminhtml/admingrid/load'),
                'saveProfile'   => $this->getUrl('adminhtml/admingrid/saveProfile'),
                'deleteProfile' => $this->getUrl('adminhtml/admingrid/deleteProfile'),
                'setDefault'    => $this->getUrl('adminhtml/admingrid/setDefault'),
                'categoryTree'  => $this->getUrl('adminhtml/admingrid/categoryTree'),
            ],
        ];

        $json = json_encode($config, JSON_UNESCAPED_SLASHES);

        // Version-stamp the asset URLs so a released change busts the browser
        // cache. We emit CSS + JS here (not via head addItem) because getSkinUrl()
        // runs the file through the fallback resolver — a "?v=" suffix on the
        // addItem name would fail that lookup and fall back to the stale base copy.
        $version = (string) Mage::getConfig()->getModuleConfig('MageAustralia_AdminGrid')->version;
        $cssUrl = $this->getSkinUrl('css/mageaustralia/admingrid.css') . '?v=' . $version;
        $jsUrl = $this->getSkinUrl('js/mageaustralia/admingrid.js') . '?v=' . $version;

        return '<link rel="stylesheet" type="text/css" href="' . $this->escapeUrl($cssUrl) . '" />'
            . sprintf('<script>window.ADMINGRID_CONFIG = %s;</script>', $json)
            . '<script src="' . $this->escapeUrl($jsUrl) . '"></script>';
    }
}
