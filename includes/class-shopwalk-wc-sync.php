<?php
/**
 * Product Sync — pushes product changes to Shopwalk API.
 *
 * @package ShopwalkAI
 * @license GPL-2.0-or-later
 * @copyright Copyright (c) 2024-2026 Shopwalk, Inc.
 */

defined('ABSPATH') || exit;

class Shopwalk_WC_Sync {

    private static ?self $instance = null;

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Sync on product save (create/update)
        add_action('woocommerce_update_product', [$this, 'sync_product'], 10, 2);
        add_action('woocommerce_new_product', [$this, 'sync_product'], 10, 2);

        // Sync on product delete/trash
        add_action('woocommerce_delete_product', [$this, 'delete_product'], 10, 1);
        add_action('wp_trash_post', [$this, 'trash_product'], 10, 1);
    }

    /**
     * Get the Shopwalk plugin key from settings (unified key).
     */
    private function get_api_key(): string {
        return get_option('shopwalk_wc_plugin_key', '');
    }

    /**
     * Get the Shopwalk API base URL (hardcoded).
     */
    private function get_api_url(): string {
        return 'https://api.shopwalk.com/api/v1/products/ingest';
    }

    /**
     * Sync a product to Shopwalk.
     */
    public function sync_product(int $product_id, $product = null): void {
        // Respect the "Sync products to Shopwalk" toggle in plugin settings.
        if (get_option('shopwalk_wc_enable_sync', 'yes') !== 'yes') {
            return;
        }

        $api_key = $this->get_api_key();
        if (empty($api_key)) {
            return; // No API key configured — skip sync
        }

        if (!$product) {
            $product = wc_get_product($product_id);
        }
        if (!$product || !$product instanceof WC_Product) {
            return;
        }

        // Skip drafts and private products
        if ($product->get_status() !== 'publish') {
            return;
        }

        $payload = $this->build_product_payload($product);

        $response = wp_remote_post($this->get_api_url(), [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-Key'    => $api_key,
            ],
            'body' => wp_json_encode($payload),
        ]);

        // Record sync status
        $timestamp = current_time('Y-m-d H:i:s');
        update_option('shopwalk_wc_last_sync', $timestamp);
        if (is_wp_error($response)) {
            update_option('shopwalk_wc_sync_status', 'Error: ' . $response->get_error_message());
        } else {
            update_option('shopwalk_wc_sync_status', 'OK');
        }
    }

    /**
     * Notify Shopwalk when a product is deleted.
     */
    public function delete_product(int $product_id): void {
        if (get_option('shopwalk_wc_enable_sync', 'yes') !== 'yes') {
            return;
        }

        $api_key = $this->get_api_key();
        if (empty($api_key)) {
            return;
        }

        $merchant_id = wp_parse_url(home_url(), PHP_URL_HOST);
        $url = 'https://api.shopwalk.com/api/v1/products/ingest'
            . '?external_id=' . urlencode((string) $product_id)
            . '&provider=woocommerce'
            . '&merchant_id=' . urlencode($merchant_id);

        wp_remote_request($url, [
            'method'  => 'DELETE',
            'timeout' => 10,
            'headers' => [
                'X-API-Key' => $api_key,
            ],
        ]);
    }

    /**
     * Handle trashed products.
     */
    public function trash_product(int $post_id): void {
        if (get_post_type($post_id) !== 'product') {
            return;
        }
        $this->delete_product($post_id);
    }

    /**
     * Build the ingest payload for a product.
     */
    private function build_product_payload(WC_Product $product): array {
        $categories = [];
        $terms = get_the_terms($product->get_id(), 'product_cat');
        if ($terms && !is_wp_error($terms)) {
            $categories = array_map(fn($t) => $t->name, $terms);
        }

        $images = [];
        $image_id = $product->get_image_id();
        if ($image_id) {
            $url = wp_get_attachment_url($image_id);
            if ($url) {
                $images[] = [
                    'url'      => $url,
                    'alt_text' => get_post_meta($image_id, '_wp_attachment_image_alt', true) ?: $product->get_name(),
                    'position' => 0,
                ];
            }
        }

        // Gallery images
        $gallery_ids = $product->get_gallery_image_ids();
        foreach ($gallery_ids as $i => $gid) {
            $url = wp_get_attachment_url($gid);
            if ($url) {
                $images[] = [
                    'url'      => $url,
                    'alt_text' => get_post_meta($gid, '_wp_attachment_image_alt', true) ?: '',
                    'position' => $i + 1,
                ];
            }
        }

        return [
            'external_id'       => (string) $product->get_id(),
            'provider'          => 'woocommerce',
            'merchant_id'       => wp_parse_url(home_url(), PHP_URL_HOST),
            'name'              => $product->get_name(),
            'description'       => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'category'          => !empty($categories) ? $categories[0] : '',
            'base_price'        => (float) $product->get_price(),
            'compare_at_price'  => $product->get_regular_price() !== $product->get_sale_price()
                ? (float) $product->get_regular_price()
                : null,
            'currency'          => get_woocommerce_currency(),
            'in_stock'          => $product->is_in_stock(),
            'stock_quantity'    => $product->get_stock_quantity() ?? 0,
            'source_url'        => get_permalink($product->get_id()),
            'images'            => $images,
        ];
    }
}

// Boot the sync
add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce')) {
        Shopwalk_WC_Sync::instance();
    }
}, 20);
