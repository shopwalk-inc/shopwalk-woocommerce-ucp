<?php
/**
 * Plugin Name: Shopwalk for WooCommerce
 * Plugin URI:  https://shopwalk.com/woocommerce
 * Description: Connect your WooCommerce store to Shopwalk — the AI-powered shopping platform. Automatically sync your products and let Shopwalk's AI help customers discover and buy from your store.
 * Version:     1.0.0
 * Author:      Shopwalk, Inc.
 * Author URI:  https://shopwalk.com
 * Requires Plugins: woocommerce
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: shopwalk-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 9.4
 *
 * @package ShopwalkWC
 */

defined('ABSPATH') || exit;

define('SHOPWALK_WC_VERSION', '1.0.0');
define('SHOPWALK_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SHOPWALK_WC_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Check if WooCommerce is active.
 */
function shopwalk_wc_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p><strong>Shopwalk for WooCommerce</strong> requires WooCommerce to be installed and active.</p></div>';
        });
        return false;
    }
    return true;
}

/**
 * Initialize the plugin.
 */
function shopwalk_wc_init() {
    if (!shopwalk_wc_check_woocommerce()) {
        return;
    }

    // Load includes
    require_once SHOPWALK_WC_PLUGIN_DIR . 'includes/class-shopwalk-wc.php';
    require_once SHOPWALK_WC_PLUGIN_DIR . 'includes/class-shopwalk-wc-profile.php';
    require_once SHOPWALK_WC_PLUGIN_DIR . 'includes/class-shopwalk-wc-products.php';
    require_once SHOPWALK_WC_PLUGIN_DIR . 'includes/class-shopwalk-wc-checkout.php';
    require_once SHOPWALK_WC_PLUGIN_DIR . 'includes/class-shopwalk-wc-orders.php';
    require_once SHOPWALK_WC_PLUGIN_DIR . 'includes/class-shopwalk-wc-webhooks.php';
    require_once SHOPWALK_WC_PLUGIN_DIR . 'includes/class-shopwalk-wc-settings.php';
    require_once SHOPWALK_WC_PLUGIN_DIR . 'includes/class-shopwalk-wc-auth.php';
    require_once SHOPWALK_WC_PLUGIN_DIR . 'includes/class-shopwalk-wc-sync.php';
    require_once SHOPWALK_WC_PLUGIN_DIR . 'includes/class-shopwalk-wc-updater.php';

    // Boot
    Shopwalk_WC::instance();
}
add_action('plugins_loaded', 'shopwalk_wc_init');

/**
 * Activation hook.
 */
function shopwalk_wc_activate() {
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'shopwalk_wc_activate');

/**
 * Deactivation hook.
 */
function shopwalk_wc_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'shopwalk_wc_deactivate');
