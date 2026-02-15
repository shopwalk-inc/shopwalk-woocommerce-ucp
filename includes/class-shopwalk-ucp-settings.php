<?php
/**
 * Plugin Settings — WooCommerce settings tab for Shopwalk UCP configuration.
 *
 * @package ShopwalkUCP
 */

defined('ABSPATH') || exit;

class Shopwalk_UCP_Settings {

    private static ?self $instance = null;

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter('woocommerce_settings_tabs_array', [$this, 'add_settings_tab'], 50);
        add_action('woocommerce_settings_tabs_shopwalk_ucp', [$this, 'settings_tab']);
        add_action('woocommerce_update_options_shopwalk_ucp', [$this, 'update_settings']);
    }

    public function add_settings_tab(array $tabs): array {
        $tabs['shopwalk_ucp'] = __('Shopwalk UCP', 'shopwalk-ucp');
        return $tabs;
    }

    public function settings_tab(): void {
        woocommerce_admin_fields($this->get_settings());
    }

    public function update_settings(): void {
        woocommerce_update_options($this->get_settings());

        // Also save as a grouped option for easy access
        $settings = [
            'api_key'          => get_option('shopwalk_ucp_api_key', ''),
            'enable_catalog'   => get_option('shopwalk_ucp_enable_catalog', 'yes'),
            'enable_checkout'  => get_option('shopwalk_ucp_enable_checkout', 'yes'),
            'enable_webhooks'  => get_option('shopwalk_ucp_enable_webhooks', 'yes'),
        ];
        update_option('shopwalk_ucp_settings', $settings);

        // Flush rewrite rules when settings change
        flush_rewrite_rules();
    }

    private function get_settings(): array {
        return [
            'section_title' => [
                'name' => __('Shopwalk UCP Settings', 'shopwalk-ucp'),
                'type' => 'title',
                'desc' => __('Configure the Universal Commerce Protocol (UCP) integration. This allows AI shopping agents like Shopwalk to discover, browse, and purchase from your store.', 'shopwalk-ucp'),
                'id'   => 'shopwalk_ucp_section_title',
            ],
            'api_key' => [
                'name'     => __('API Key', 'shopwalk-ucp'),
                'type'     => 'text',
                'desc'     => __('Set an API key to secure UCP checkout and order endpoints. Leave blank to allow open access (not recommended for production).', 'shopwalk-ucp'),
                'id'       => 'shopwalk_ucp_api_key',
                'default'  => '',
                'desc_tip' => true,
            ],
            'enable_catalog' => [
                'name'    => __('Enable Catalog API', 'shopwalk-ucp'),
                'type'    => 'checkbox',
                'desc'    => __('Allow AI agents to browse your product catalog via UCP.', 'shopwalk-ucp'),
                'id'      => 'shopwalk_ucp_enable_catalog',
                'default' => 'yes',
            ],
            'enable_checkout' => [
                'name'    => __('Enable Checkout API', 'shopwalk-ucp'),
                'type'    => 'checkbox',
                'desc'    => __('Allow AI agents to create checkout sessions and place orders.', 'shopwalk-ucp'),
                'id'      => 'shopwalk_ucp_enable_checkout',
                'default' => 'yes',
            ],
            'enable_webhooks' => [
                'name'    => __('Enable Webhooks', 'shopwalk-ucp'),
                'type'    => 'checkbox',
                'desc'    => __('Send order status notifications to registered platforms.', 'shopwalk-ucp'),
                'id'      => 'shopwalk_ucp_enable_webhooks',
                'default' => 'yes',
            ],
            'section_end' => [
                'type' => 'sectionend',
                'id'   => 'shopwalk_ucp_section_end',
            ],
            'info_title' => [
                'name' => __('UCP Endpoints', 'shopwalk-ucp'),
                'type' => 'title',
                'desc' => sprintf(
                    __('Your UCP Business Profile is available at: <code>%s</code><br>REST API base: <code>%s</code>', 'shopwalk-ucp'),
                    home_url('/.well-known/ucp'),
                    rest_url('shopwalk-ucp/v1')
                ),
                'id' => 'shopwalk_ucp_info_title',
            ],
            'info_end' => [
                'type' => 'sectionend',
                'id'   => 'shopwalk_ucp_info_end',
            ],
        ];
    }
}
