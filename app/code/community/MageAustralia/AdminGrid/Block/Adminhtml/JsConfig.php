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
            ],
        ];

        $json = json_encode($config, JSON_UNESCAPED_SLASHES);

        return "<script>window.ADMINGRID_CONFIG = {$json};</script>";
    }
}
