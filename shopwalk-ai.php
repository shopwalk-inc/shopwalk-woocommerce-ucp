<?php
/**
 * Plugin Name: Shopwalk AI
 * Plugin URI:  https://shopwalk.com/woocommerce
 * Description: AI-enable your WooCommerce store in minutes. Shopwalk AI syncs your products and opens your store to AI-powered discovery, browsing, and checkout.
 * Version:     1.0.0
 * Author:      Shopwalk, Inc.
 * Author URI:  https://shopwalk.com
 * Requires Plugins: woocommerce
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: shopwalk-ai
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 9.4
 *
 * @package ShopwalkAI
 */

/*
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

defined('ABSPATH') || exit;

define('SHOPWALK_AI_VERSION',    '1.0.0');
define('SHOPWALK_AI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SHOPWALK_AI_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Declare WooCommerce HPOS (High-Performance Order Storage) compatibility.
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

/**
 * Check if WooCommerce is active.
 */
function shopwalk_ai_check_woocommerce(): bool {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p><strong>' . esc_html__('Shopwalk AI', 'shopwalk-ai') . '</strong> '
                . esc_html__('requires WooCommerce to be installed and active.', 'shopwalk-ai') . '</p></div>';
        });
        return false;
    }
    return true;
}

/**
 * Initialize the plugin.
 */
function shopwalk_ai_init(): void {
    if (!shopwalk_ai_check_woocommerce()) {
        return;
    }

    // Load includes
    require_once SHOPWALK_AI_PLUGIN_DIR . 'includes/class-shopwalk-wc.php';
    require_once SHOPWALK_AI_PLUGIN_DIR . 'includes/class-shopwalk-wc-profile.php';
    require_once SHOPWALK_AI_PLUGIN_DIR . 'includes/class-shopwalk-wc-products.php';
    require_once SHOPWALK_AI_PLUGIN_DIR . 'includes/class-shopwalk-wc-checkout.php';
    require_once SHOPWALK_AI_PLUGIN_DIR . 'includes/class-shopwalk-wc-orders.php';
    require_once SHOPWALK_AI_PLUGIN_DIR . 'includes/class-shopwalk-wc-webhooks.php';
    require_once SHOPWALK_AI_PLUGIN_DIR . 'includes/class-shopwalk-wc-settings.php';
    require_once SHOPWALK_AI_PLUGIN_DIR . 'includes/class-shopwalk-wc-auth.php';
    require_once SHOPWALK_AI_PLUGIN_DIR . 'includes/class-shopwalk-wc-sync.php';
    require_once SHOPWALK_AI_PLUGIN_DIR . 'includes/class-shopwalk-wc-updater.php';

    // Boot
    Shopwalk_WC::instance();
}
add_action('plugins_loaded', 'shopwalk_ai_init');

/**
 * Activation hook.
 */
function shopwalk_ai_activate(): void {
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'shopwalk_ai_activate');

/**
 * Deactivation hook.
 */
function shopwalk_ai_deactivate(): void {
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'shopwalk_ai_deactivate');
