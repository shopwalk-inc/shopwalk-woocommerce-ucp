<?php
/**
 * Uninstall — runs when the plugin is deleted via the WordPress admin.
 *
 * Removes all plugin options from wp_options. Does NOT delete product data
 * or orders — those belong to the store owner.
 *
 * @package ShopwalkAI
 * @license GPL-2.0-or-later
 * @copyright Copyright (c) 2024-2026 Shopwalk, Inc.
 */

// If uninstall not called from WordPress, exit immediately.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// All plugin options to remove on uninstall.
$options = [
    // Core settings
    'shopwalk_wc_plugin_key',
    'shopwalk_wc_api_key',
    'shopwalk_wc_enable_catalog',
    'shopwalk_wc_enable_checkout',
    'shopwalk_wc_enable_webhooks',
    'shopwalk_wc_settings',
    // Sync state
    'shopwalk_wc_last_sync',
    'shopwalk_wc_sync_status',
    'shopwalk_wc_license_status',
    // Webhooks registry
    'shopwalk_wc_webhooks',
    // Legacy keys (kept for clean migration from older versions)
    'shopwalk_wc_license_key',
    'shopwalk_wc_shopwalk_api_key',
    'shopwalk_wc_shopwalk_api_url',
];

foreach ($options as $option) {
    delete_option($option);
}

// Remove cached update transient.
delete_transient('shopwalk_wc_update_info');
