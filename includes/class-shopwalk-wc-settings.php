<?php
/**
 * Plugin Settings — WooCommerce settings tab for Shopwalk configuration.
 *
 * @package ShopwalkWC
 */

defined('ABSPATH') || exit;

class Shopwalk_WC_Settings {

    private static ?self $instance = null;

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter('woocommerce_settings_tabs_array', [$this, 'add_settings_tab'], 50);
        add_action('woocommerce_settings_tabs_shopwalk', [$this, 'settings_tab']);
        add_action('woocommerce_update_options_shopwalk', [$this, 'update_settings']);

        // Migrate legacy keys on every settings load
        add_action('admin_init', [$this, 'maybe_migrate_legacy_keys']);

        // AJAX handlers (logged-in admin)
        add_action('wp_ajax_shopwalk_wc_sync_all',         [$this, 'ajax_sync_all']);
        add_action('wp_ajax_shopwalk_wc_test_connection',  [$this, 'ajax_test_connection']);

        // Enqueue admin JS on our settings tab
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    public function add_settings_tab(array $tabs): array {
        $tabs['shopwalk'] = __('Shopwalk', 'shopwalk-for-woocommerce');
        return $tabs;
    }

    public function settings_tab(): void {
        woocommerce_admin_fields($this->get_settings());
    }

    /**
     * Backward-compat migration: if old keys exist and plugin_key is empty, copy value over.
     */
    public function maybe_migrate_legacy_keys(): void {
        $plugin_key  = get_option('shopwalk_wc_plugin_key', '');
        if (!empty($plugin_key)) {
            return; // Already set — nothing to migrate
        }

        // Prefer the old license_key; fall back to shopwalk_api_key
        $old_license = get_option('shopwalk_wc_license_key', '');
        $old_api_key = get_option('shopwalk_wc_shopwalk_api_key', '');
        $migrate     = $old_license ?: $old_api_key;

        if (!empty($migrate)) {
            update_option('shopwalk_wc_plugin_key', $migrate);
        }
    }

    public function update_settings(): void {
        $old_plugin_key = get_option('shopwalk_wc_plugin_key', '');
        woocommerce_update_options($this->get_settings());
        $new_plugin_key = get_option('shopwalk_wc_plugin_key', '');

        // Update grouped option
        $settings = [
            'plugin_key'      => $new_plugin_key,
            'api_key'         => get_option('shopwalk_wc_api_key', ''),
            'shopwalk_api_url'=> 'https://api.shopwalk.com',
            'enable_catalog'  => get_option('shopwalk_wc_enable_catalog', 'yes'),
            'enable_checkout' => get_option('shopwalk_wc_enable_checkout', 'yes'),
            'enable_webhooks' => get_option('shopwalk_wc_enable_webhooks', 'yes'),
        ];
        update_option('shopwalk_wc_settings', $settings);

        // If plugin key was added/changed, activate it with Shopwalk
        if (!empty($new_plugin_key) && $new_plugin_key !== $old_plugin_key) {
            $this->activate_license($new_plugin_key);
        }

        // Flush rewrite rules when settings change
        flush_rewrite_rules();
    }

    /**
     * Call the Shopwalk API to activate the plugin key for this site.
     */
    private function activate_license(string $plugin_key): void {
        $response = wp_remote_post('https://api.shopwalk.com/api/v1/plugin/activate', [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'plugin_key' => $plugin_key,
                'site_url'   => home_url(),
            ]),
            'timeout' => 15,
        ]);
        if (is_wp_error($response)) {
            update_option('shopwalk_wc_license_status', 'error');
        } else {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            update_option('shopwalk_wc_license_status', ($body['status'] ?? '') === 'ok' ? 'active' : 'invalid');
        }
    }

    /**
     * AJAX: sync all published products to Shopwalk.
     * Returns JSON {synced, failed, total}.
     */
    public function ajax_sync_all(): void {
        check_ajax_referer('shopwalk_wc_sync_all', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }

        $plugin_key = get_option('shopwalk_wc_plugin_key', '');
        if (empty($plugin_key)) {
            wp_send_json_error(['message' => 'No Plugin Key configured.'], 400);
        }

        $product_ids = wc_get_products([
            'status' => 'publish',
            'limit'  => -1,
            'return' => 'ids',
        ]);

        $total  = count($product_ids);
        $synced = 0;
        $failed = 0;
        $sync   = Shopwalk_WC_Sync::instance();

        foreach ($product_ids as $id) {
            try {
                $sync->sync_product($id);
                $synced++;
            } catch (\Exception $e) {
                $failed++;
            }
        }

        $status = get_option('shopwalk_wc_sync_status', '');
        if ($status !== 'OK') {
            wp_send_json_error([
                'message' => 'Sync completed but API returned an error: ' . $status,
                'total'   => $total,
                'synced'  => $synced,
                'failed'  => $failed,
            ]);
        }

        wp_send_json_success([
            'message' => sprintf('%d of %d products synced successfully.', $synced, $total),
            'total'   => $total,
            'synced'  => $synced,
            'failed'  => $failed,
            'last'    => get_option('shopwalk_wc_last_sync', ''),
        ]);
    }

    /**
     * AJAX: re-run all AI Commerce Status checks and return JSON.
     */
    public function ajax_test_connection(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }

        wp_send_json_success($this->run_status_checks());
    }

    /**
     * Run all AI Commerce Status checks and return an array keyed by check name.
     * Each entry: ['status' => 'green'|'red'|'yellow', 'label' => string]
     */
    private function run_status_checks(): array {
        $checks = [];

        // 1. Plugin Key
        $license_status = get_option('shopwalk_wc_license_status', '');
        $checks['plugin_key'] = [
            'status' => ($license_status === 'active') ? 'green' : 'red',
            'label'  => ($license_status === 'active') ? 'Valid' : 'Not activated',
        ];

        // 2. Shopwalk API reachability
        $health_resp = wp_remote_head('https://api.shopwalk.com/api/v1/health', ['timeout' => 8]);
        $api_ok = !is_wp_error($health_resp) && wp_remote_retrieve_response_code($health_resp) === 200;
        $checks['api'] = [
            'status' => $api_ok ? 'green' : 'red',
            'label'  => $api_ok ? 'Connected' : 'Unreachable',
        ];

        // 3. Product Catalog count
        $product_ids = function_exists('wc_get_products') ? wc_get_products(['status' => 'publish', 'limit' => -1, 'return' => 'ids']) : [];
        $product_count = count($product_ids);
        $checks['catalog'] = [
            'status' => $product_count > 0 ? 'green' : 'yellow',
            'label'  => $product_count > 0 ? $product_count . ' published products' : 'No products',
        ];

        // 4. UCP Discovery
        $ucp_path  = ABSPATH . '.well-known/ucp';
        $ucp_local = file_exists($ucp_path);
        if (!$ucp_local) {
            $ucp_resp  = wp_remote_get(home_url('/.well-known/ucp'), ['timeout' => 8, 'redirection' => 0]);
            $ucp_local = !is_wp_error($ucp_resp) && in_array(wp_remote_retrieve_response_code($ucp_resp), [200, 301, 302], true);
        }
        $checks['ucp'] = [
            'status' => $ucp_local ? 'green' : 'yellow',
            'label'  => $ucp_local ? 'Live' : 'Not found',
        ];

        // 5. AI Browsing REST route
        $browsing_ok = (bool) rest_get_server()->get_routes()['/' . Shopwalk_WC_Products::REST_NAMESPACE . '/products'] ?? false;
        // Fallback: just check that rest routes include our namespace
        if (!$browsing_ok) {
            $routes = rest_get_server()->get_routes();
            foreach (array_keys($routes) as $route) {
                if (strpos($route, 'shopwalk-wc/v1/products') !== false) {
                    $browsing_ok = true;
                    break;
                }
            }
        }
        $checks['browsing'] = [
            'status' => $browsing_ok ? 'green' : 'yellow',
            'label'  => $browsing_ok ? 'Available' : 'Not registered',
        ];

        // 6. AI Checkout REST route
        $checkout_ok = false;
        $routes = rest_get_server()->get_routes();
        foreach (array_keys($routes) as $route) {
            if (strpos($route, 'shopwalk-wc/v1/checkout-sessions') !== false) {
                $checkout_ok = true;
                break;
            }
        }
        $checks['checkout'] = [
            'status' => $checkout_ok ? 'green' : 'yellow',
            'label'  => $checkout_ok ? 'Ready' : 'Not registered',
        ];

        // 7. Order Webhooks
        $webhooks_enabled = get_option('shopwalk_wc_enable_webhooks', 'yes') === 'yes';
        $checks['webhooks'] = [
            'status' => $webhooks_enabled ? 'green' : 'yellow',
            'label'  => $webhooks_enabled ? 'Active' : 'Disabled',
        ];

        return $checks;
    }

    /**
     * Build the AI Commerce Status HTML for the settings page.
     */
    private function get_ai_status_html(): string {
        $checks = $this->run_status_checks();

        $rows_map = [
            'plugin_key' => 'Plugin Key',
            'api'        => 'Shopwalk API',
            'catalog'    => 'Product Catalog',
            'ucp'        => 'UCP Discovery',
            'browsing'   => 'AI Browsing',
            'checkout'   => 'AI Checkout',
            'webhooks'   => 'Order Webhooks',
        ];

        $html  = '<style>';
        $html .= '.shopwalk-status-green { color: #46b450; font-size: 18px; }';
        $html .= '.shopwalk-status-red   { color: #dc3232; font-size: 18px; }';
        $html .= '.shopwalk-status-yellow{ color: #f56e28; font-size: 18px; }';
        $html .= '.shopwalk-status-table td { border-bottom: 1px solid #f0f0f0; }';
        $html .= '</style>';

        $html .= '<table class="shopwalk-status-table" style="border-collapse:collapse;width:100%;max-width:600px;">';
        foreach ($rows_map as $key => $name) {
            $check  = $checks[$key] ?? ['status' => 'yellow', 'label' => '—'];
            $color  = 'shopwalk-status-' . $check['status'];
            $html  .= '<tr>';
            $html  .= '<td style="padding:8px 12px;">';
            $html  .= '<span class="shopwalk-status-dot ' . esc_attr($color) . '">&#9679;</span> ';
            $html  .= '<strong>' . esc_html($name) . '</strong>';
            $html  .= '</td>';
            $html  .= '<td style="padding:8px 12px;color:#666;">' . esc_html($check['label']) . '</td>';
            $html  .= '</tr>';
        }
        $html .= '</table>';

        $html .= '<p style="margin-top:12px;">';
        $html .= '<button type="button" id="shopwalk-test-connection" class="button button-secondary">'
            . esc_html__('Test Connection', 'shopwalk-for-woocommerce')
            . '</button>';
        $html .= '<button type="button" id="shopwalk-sync-now" class="button button-secondary" style="margin-left:8px;">'
            . esc_html__('Sync Products Now', 'shopwalk-for-woocommerce')
            . '</button>';
        $html .= '<span id="shopwalk-connection-result" style="margin-left:10px;"></span>';
        $html .= '</p>';

        return $html;
    }

    /**
     * Enqueue admin JS only on the Shopwalk settings tab.
     */
    public function enqueue_admin_scripts(string $hook): void {
        if ($hook !== 'woocommerce_page_wc-settings') {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification
        if (($GLOBALS['current_tab'] ?? '') !== 'shopwalk' && (!isset($_GET['tab']) || $_GET['tab'] !== 'shopwalk')) {
            return;
        }

        wp_enqueue_script(
            'shopwalk-wc-admin',
            SHOPWALK_WC_PLUGIN_URL . 'assets/admin.js',
            ['jquery'],
            SHOPWALK_WC_VERSION,
            true
        );

        wp_localize_script('shopwalk-wc-admin', 'shopwalkWC', [
            'ajaxUrl'         => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce('shopwalk_wc_sync_all'),
            'testNonce'       => wp_create_nonce('shopwalk_wc_test_connection'),
            'i18n'            => [
                'syncing'    => __('Syncing...', 'shopwalk-for-woocommerce'),
                'syncNow'    => __('Sync Products Now', 'shopwalk-for-woocommerce'),
                'testing'    => __('Testing...', 'shopwalk-for-woocommerce'),
                'testConn'   => __('Test Connection', 'shopwalk-for-woocommerce'),
                'success'    => __('Sync complete!', 'shopwalk-for-woocommerce'),
                'error'      => __('Request failed. Check your Plugin Key.', 'shopwalk-for-woocommerce'),
            ],
        ]);
    }

    private function get_settings(): array {
        return [
            'section_title' => [
                'name' => __('Shopwalk Settings', 'shopwalk-for-woocommerce'),
                'type' => 'title',
                'desc' => __('Connect your store to Shopwalk — the AI shopping platform that automatically syncs your products and helps customers discover and buy from you.', 'shopwalk-for-woocommerce'),
                'id'   => 'shopwalk_wc_section_title',
            ],
            'plugin_key' => [
                'name'        => __('Plugin Key', 'shopwalk-for-woocommerce'),
                'type'        => 'password',
                'desc'        => __('Your Shopwalk plugin key. Get one at shopwalk.com/plugin.', 'shopwalk-for-woocommerce'),
                'id'          => 'shopwalk_wc_plugin_key',
                'default'     => '',
                'desc_tip'    => true,
                'placeholder' => 'sw_plugin_...',
            ],
            'api_key' => [
                'name'     => __('Inbound API Key', 'shopwalk-for-woocommerce'),
                'type'     => 'text',
                'desc'     => __('Set an API key to secure checkout and order endpoints. Leave blank to allow open access (not recommended for production).', 'shopwalk-for-woocommerce'),
                'id'       => 'shopwalk_wc_api_key',
                'default'  => '',
                'desc_tip' => true,
            ],
            'enable_catalog' => [
                'name'    => __('Enable Catalog API', 'shopwalk-for-woocommerce'),
                'type'    => 'checkbox',
                'desc'    => __('Allow Shopwalk AI to browse your product catalog.', 'shopwalk-for-woocommerce'),
                'id'      => 'shopwalk_wc_enable_catalog',
                'default' => 'yes',
            ],
            'enable_checkout' => [
                'name'    => __('Enable Checkout API', 'shopwalk-for-woocommerce'),
                'type'    => 'checkbox',
                'desc'    => __('Allow Shopwalk AI to create checkout sessions and place orders.', 'shopwalk-for-woocommerce'),
                'id'      => 'shopwalk_wc_enable_checkout',
                'default' => 'yes',
            ],
            'enable_webhooks' => [
                'name'    => __('Enable Webhooks', 'shopwalk-for-woocommerce'),
                'type'    => 'checkbox',
                'desc'    => __('Send order status notifications to Shopwalk.', 'shopwalk-for-woocommerce'),
                'id'      => 'shopwalk_wc_enable_webhooks',
                'default' => 'yes',
            ],
            'section_end' => [
                'type' => 'sectionend',
                'id'   => 'shopwalk_wc_section_end',
            ],
            'ai_status_title' => [
                'name' => __('AI Commerce Status', 'shopwalk-for-woocommerce'),
                'type' => 'title',
                'desc' => $this->get_ai_status_html(),
                'id'   => 'shopwalk_wc_ai_status_title',
            ],
            'status_end' => [
                'type' => 'sectionend',
                'id'   => 'shopwalk_wc_status_end',
            ],
        ];
    }
}
