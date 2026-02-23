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

        // Sync All — AJAX handler (logged-in admin)
        add_action('wp_ajax_shopwalk_wc_sync_all', [$this, 'ajax_sync_all']);

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

    public function update_settings(): void {
        woocommerce_update_options($this->get_settings());

        // Also save as a grouped option for easy access
        $settings = [
            'api_key'          => get_option('shopwalk_wc_api_key', ''),
            'shopwalk_api_key' => get_option('shopwalk_wc_shopwalk_api_key', ''),
            'shopwalk_api_url' => get_option('shopwalk_wc_shopwalk_api_url', 'https://api.shopwalk.com'),
            'enable_catalog'   => get_option('shopwalk_wc_enable_catalog', 'yes'),
            'enable_checkout'  => get_option('shopwalk_wc_enable_checkout', 'yes'),
            'enable_webhooks'  => get_option('shopwalk_wc_enable_webhooks', 'yes'),
        ];
        update_option('shopwalk_wc_settings', $settings);

        // Flush rewrite rules when settings change
        flush_rewrite_rules();
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

        $api_key = get_option('shopwalk_wc_shopwalk_api_key', '');
        if (empty($api_key)) {
            wp_send_json_error(['message' => 'No Shopwalk API key configured.'], 400);
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
     * Enqueue admin JS only on the Shopwalk settings tab.
     */
    public function enqueue_admin_scripts(string $hook): void {
        if ($hook !== 'woocommerce_page_wc-settings') {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification
        if (($GLOBALS['current_tab'] ?? '') !== 'shopwalk' && (isset($_GET['tab']) && $_GET['tab'] !== 'shopwalk')) {
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
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('shopwalk_wc_sync_all'),
            'i18n'    => [
                'syncing'  => __('Syncing...', 'shopwalk-for-woocommerce'),
                'syncNow'  => __('Sync All Products Now', 'shopwalk-for-woocommerce'),
                'success'  => __('Sync complete!', 'shopwalk-for-woocommerce'),
                'error'    => __('Sync failed. Check your API key.', 'shopwalk-for-woocommerce'),
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
            'shopwalk_api_key' => [
                'name'        => __('Shopwalk API Key', 'shopwalk-for-woocommerce'),
                'type'        => 'password',
                'desc'        => __('Your Shopwalk API key for syncing products. Get one free at shopwalk.com → Profile → API Keys.', 'shopwalk-for-woocommerce'),
                'id'          => 'shopwalk_wc_shopwalk_api_key',
                'default'     => '',
                'desc_tip'    => true,
                'placeholder' => 'sk_live_...',
            ],
            'shopwalk_api_url' => [
                'name'        => __('Shopwalk API URL', 'shopwalk-for-woocommerce'),
                'type'        => 'text',
                'desc'        => __('Shopwalk API base URL. Leave as default unless instructed otherwise.', 'shopwalk-for-woocommerce'),
                'id'          => 'shopwalk_wc_shopwalk_api_url',
                'default'     => 'https://api.shopwalk.com',
                'desc_tip'    => true,
                'placeholder' => 'https://api.shopwalk.com',
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
            'status_title' => [
                'name' => __('Product Sync', 'shopwalk-for-woocommerce'),
                'type' => 'title',
                'desc' => $this->get_sync_status_html(),
                'id'   => 'shopwalk_wc_status_title',
            ],
            'status_end' => [
                'type' => 'sectionend',
                'id'   => 'shopwalk_wc_status_end',
            ],
        ];
    }

    /**
     * Build sync status + Sync Now button for the settings page.
     */
    private function get_sync_status_html(): string {
        $last_sync   = get_option('shopwalk_wc_last_sync', '');
        $sync_status = get_option('shopwalk_wc_sync_status', '');
        $api_key     = get_option('shopwalk_wc_shopwalk_api_key', '');

        if (empty($api_key)) {
            return '<span style="color:#999;">' . esc_html__('Enter your Shopwalk API key above to start syncing products.', 'shopwalk-for-woocommerce') . '</span>';
        }

        $status_html = '';
        if ($last_sync) {
            $color        = ($sync_status === 'OK') ? '#46b450' : '#dc3232';
            $status_label = ($sync_status === 'OK') ? '✓ OK' : esc_html($sync_status);
            $status_html .= '<p><strong>' . esc_html__('Last sync:', 'shopwalk-for-woocommerce') . '</strong> '
                . esc_html($last_sync)
                . ' &mdash; <span style="color:' . $color . ';">' . $status_label . '</span></p>';
        } else {
            $status_html .= '<p style="color:#999;">' . esc_html__('Products sync automatically when saved. No manual sync recorded yet.', 'shopwalk-for-woocommerce') . '</p>';
        }

        // Sync Now button + result area
        $status_html .= '<p>'
            . '<button type="button" id="shopwalk-sync-now" class="button button-secondary">'
            . esc_html__('Sync All Products Now', 'shopwalk-for-woocommerce')
            . '</button>'
            . ' <span id="shopwalk-sync-result" style="margin-left:10px;"></span>'
            . '</p>';

        return $status_html;
    }
}
