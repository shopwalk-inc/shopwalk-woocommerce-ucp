<?php
/**
 * Product Sync — pushes product changes to Shopwalk API.
 *
 * @package ShopwalkUCP
 */

defined('ABSPATH') || exit;

class Shopwalk_UCP_Sync {

    private static ?self $instance = null;
    private string $api_url = 'https://api.shopwalk.com/api/v1/products/ingest';

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
     * Get the Shopwalk API key from settings.
     */
    private function get_api_key(): string {
        return get_option('shopwalk_ucp_shopwalk_api_key', '');
    }

    /**
     * Sync a product to Shopwalk.
     */
    public function sync_product(int $product_id, $product = null): void {
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

        wp_remote_post($this->api_url, [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-Key'    => $api_key,
            ],
            'body' => wp_json_encode($payload),
        ]);
    }

    /**
     * Notify Shopwalk when a product is deleted.
     */
    public function delete_product(int $product_id): void {
        // Future: call a delete endpoint on Shopwalk
        // For now, products will just stop syncing
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
            'external_id'      => (string) $product->get_id(),
            'provider'         => 'woocommerce',
            'merchant_id'      => wp_parse_url(home_url(), PHP_URL_HOST),
            'name'             => $product->get_name(),
            'description'      => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'category'         => !empty($categories) ? $categories[0] : '',
            'base_price'       => (float) $product->get_price(),
            'compare_at_price' => $product->get_regular_price() !== $product->get_sale_price()
                ? (float) $product->get_regular_price()
                : null,
            'currency'         => get_woocommerce_currency(),
            'in_stock'         => $product->is_in_stock(),
            'stock_quantity'   => $product->get_stock_quantity() ?? 0,
            'source_url'       => get_permalink($product->get_id()),
            'images'           => $images,
        ];
    }
}

// Boot the sync
add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce')) {
        Shopwalk_UCP_Sync::instance();
    }
}, 20);
