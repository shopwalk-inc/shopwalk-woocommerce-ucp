<?php
/**
 * Plugin Name: Shopwalk UCP for WooCommerce
 * Plugin URI:  https://shopwalk.com/woocommerce
 * Description: Implements the Universal Commerce Protocol (UCP) for WooCommerce, enabling AI shopping agents to discover, browse, checkout, and manage orders.
 * Version:     1.0.0
 * Author:      Shopwalk, Inc.
 * Author URI:  https://shopwalk.com
 * Developer:   Shopwalk, Inc.
 * Developer URI: https://shopwalk.com
 * Requires Plugins: woocommerce
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: shopwalk-ucp
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 *
 * @package ShopwalkUCP
 */

defined('ABSPATH') || exit;

define('SHOPWALK_UCP_VERSION', '1.0.0');
define('SHOPWALK_UCP_SPEC_VERSION', '2026-01-23');
define('SHOPWALK_UCP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SHOPWALK_UCP_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Check if WooCommerce is active.
 */
function shopwalk_ucp_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p><strong>Shopwalk UCP</strong> requires WooCommerce to be installed and active.</p></div>';
        });
        return false;
    }
    return true;
}

/**
 * Initialize the plugin.
 */
function shopwalk_ucp_init() {
    if (!shopwalk_ucp_check_woocommerce()) {
        return;
    }

    // Load includes
    require_once SHOPWALK_UCP_PLUGIN_DIR . 'includes/class-shopwalk-ucp.php';
    require_once SHOPWALK_UCP_PLUGIN_DIR . 'includes/class-shopwalk-ucp-profile.php';
    require_once SHOPWALK_UCP_PLUGIN_DIR . 'includes/class-shopwalk-ucp-products.php';
    require_once SHOPWALK_UCP_PLUGIN_DIR . 'includes/class-shopwalk-ucp-checkout.php';
    require_once SHOPWALK_UCP_PLUGIN_DIR . 'includes/class-shopwalk-ucp-orders.php';
    require_once SHOPWALK_UCP_PLUGIN_DIR . 'includes/class-shopwalk-ucp-webhooks.php';
    require_once SHOPWALK_UCP_PLUGIN_DIR . 'includes/class-shopwalk-ucp-settings.php';
    require_once SHOPWALK_UCP_PLUGIN_DIR . 'includes/class-shopwalk-ucp-auth.php';
    require_once SHOPWALK_UCP_PLUGIN_DIR . 'includes/class-shopwalk-ucp-sync.php';

    // Boot
    Shopwalk_UCP::instance();
}
add_action('plugins_loaded', 'shopwalk_ucp_init');

/**
 * Activation hook.
 */
function shopwalk_ucp_activate() {
    // Flush rewrite rules for /.well-known/ucp
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'shopwalk_ucp_activate');

/**
 * Deactivation hook.
 */
function shopwalk_ucp_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'shopwalk_ucp_deactivate');
