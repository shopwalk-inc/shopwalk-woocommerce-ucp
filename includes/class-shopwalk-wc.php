<?php
/**
 * Main plugin class — singleton orchestrator.
 *
 * @package ShopwalkWC
 */

defined('ABSPATH') || exit;

class Shopwalk_WC {

    private static ?Shopwalk_WC $instance = null;

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
            Shopwalk_WC_Settings::instance();
        }

        // Auto-updater (checks shopwalk.com for plugin updates)
        Shopwalk_WC_Updater::instance();

        // Add version header to all Shopwalk REST responses
        add_filter('rest_post_dispatch', function($result, $server, $request) {
            if (strpos($request->get_route(), '/shopwalk-wc/') !== false) {
                $result->header('X-Shopwalk-WC-Version', SHOPWALK_WC_VERSION);
            }
            return $result;
        }, 10, 3);
    }

    /**
     * Register REST API routes under /wp-json/shopwalk-wc/v1/
     */
    public function register_routes(): void {
        $namespace = 'shopwalk-wc/v1';

        // Products / Catalog
        $products = new Shopwalk_WC_Products();
        $products->register_routes($namespace);

        // Checkout Sessions
        $checkout = new Shopwalk_WC_Checkout();
        $checkout->register_routes($namespace);

        // Orders
        $orders = new Shopwalk_WC_Orders();
        $orders->register_routes($namespace);

        // Webhooks
        $webhooks = new Shopwalk_WC_Webhooks();
        $webhooks->register_routes($namespace);
    }

    /**
     * Register the /.well-known/ucp rewrite rule.
     */
    public function register_well_known(): void {
        add_rewrite_rule(
            '^\.well-known/ucp/?$',
            'index.php?shopwalk_wc_well_known=1',
            'top'
        );
    }

    public function add_query_vars(array $vars): array {
        $vars[] = 'shopwalk_wc_well_known';
        return $vars;
    }

    /**
     * Serve the Business Profile at /.well-known/ucp
     */
    public function handle_well_known(): void {
        if (!get_query_var('shopwalk_wc_well_known')) {
            return;
        }

        $profile = Shopwalk_WC_Profile::get_business_profile();

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        header('Access-Control-Allow-Origin: *');
        echo wp_json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
