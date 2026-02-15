<?php
/**
 * Main plugin class — singleton orchestrator.
 *
 * @package ShopwalkUCP
 */

defined('ABSPATH') || exit;

class Shopwalk_UCP {

    private static ?Shopwalk_UCP $instance = null;

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Cloning is forbidden.
     */
    public function __clone() {
        wc_doing_it_wrong(__FUNCTION__, 'Cloning is forbidden.', '1.0.0');
    }

    /**
     * Unserializing is forbidden.
     */
    public function __wakeup() {
        wc_doing_it_wrong(__FUNCTION__, 'Unserializing is forbidden.', '1.0.0');
    }

    private function init_hooks(): void {
        // Register REST API routes
        add_action('rest_api_init', [$this, 'register_routes']);

        // Register /.well-known/ucp rewrite
        add_action('init', [$this, 'register_well_known']);
        add_filter('query_vars', [$this, 'add_query_vars']);
        add_action('template_redirect', [$this, 'handle_well_known']);

        // Admin settings
        if (is_admin()) {
            Shopwalk_UCP_Settings::instance();
        }
    }

    /**
     * Register REST API routes under /wp-json/shopwalk-ucp/v1/
     */
    public function register_routes(): void {
        $namespace = 'shopwalk-ucp/v1';

        // Products / Catalog
        $products = new Shopwalk_UCP_Products();
        $products->register_routes($namespace);

        // Checkout Sessions
        $checkout = new Shopwalk_UCP_Checkout();
        $checkout->register_routes($namespace);

        // Orders
        $orders = new Shopwalk_UCP_Orders();
        $orders->register_routes($namespace);

        // Webhooks
        $webhooks = new Shopwalk_UCP_Webhooks();
        $webhooks->register_routes($namespace);
    }

    /**
     * Register the /.well-known/ucp rewrite rule.
     */
    public function register_well_known(): void {
        add_rewrite_rule(
            '^\.well-known/ucp/?$',
            'index.php?shopwalk_ucp_well_known=1',
            'top'
        );
    }

    public function add_query_vars(array $vars): array {
        $vars[] = 'shopwalk_ucp_well_known';
        return $vars;
    }

    /**
     * Serve the UCP Business Profile at /.well-known/ucp
     */
    public function handle_well_known(): void {
        if (!get_query_var('shopwalk_ucp_well_known')) {
            return;
        }

        $profile = Shopwalk_UCP_Profile::get_business_profile();

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        header('Access-Control-Allow-Origin: *');
        echo wp_json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
