<?php
// If uninstall not called from WordPress, exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete all plugin options
$options = [
    'shopwalk_wc_api_key',
    'shopwalk_wc_shopwalk_api_key',
    'shopwalk_wc_shopwalk_api_url',
    'shopwalk_wc_enable_catalog',
    'shopwalk_wc_enable_checkout',
    'shopwalk_wc_enable_webhooks',
    'shopwalk_wc_settings',
    'shopwalk_wc_last_sync',
    'shopwalk_wc_sync_status',
    'shopwalk_wc_webhooks',
];
foreach ($options as $option) {
    delete_option($option);
}
