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
                'name' => __('Sync Status', 'shopwalk-for-woocommerce'),
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
     * Build a simple sync status display for the settings page.
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
            $status_html .= '<strong>' . esc_html__('Last sync:', 'shopwalk-for-woocommerce') . '</strong> ' . esc_html($last_sync);
        }
        if ($sync_status) {
            $status_html .= ' &mdash; ' . esc_html($sync_status);
        }
        if (!$status_html) {
            $status_html = esc_html__('No sync recorded yet. Products will sync automatically when saved.', 'shopwalk-for-woocommerce');
        }

        return $status_html;
    }
}
