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

    /** Sync API endpoint. */
    private const SYNC_ENDPOINT = 'https://api.shopwalk.com/api/v1/sync/event';

    /** Queue option name. */
    private const QUEUE_OPTION = 'shopwalk_wc_sync_queue';

    /** Max queue size (events). */
    private const QUEUE_CAP = 500;

    /** Max events to flush per init call. */
    private const QUEUE_FLUSH_BATCH = 50;

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

        // Fallback: catch permanent deletes for variable products and other edge cases
        add_action('before_delete_post', [$this, 'maybe_delete_product'], 10, 1);

        // Stock-only updates (skip vector re-embedding on the API side)
        add_action('woocommerce_product_set_stock_status', [$this, 'sync_stock_update'], 10, 3);
        add_action('woocommerce_product_set_stock', [$this, 'sync_stock_update_quantity'], 10, 3);

        // Price/sale updates
        add_action('woocommerce_product_object_updated_props', [$this, 'sync_price_update'], 10, 2);

        // Coupon sync
        add_action('woocommerce_new_coupon', [$this, 'sync_coupon_upsert'], 10, 2);
        add_action('woocommerce_update_coupon', [$this, 'sync_coupon_upsert'], 10, 2);
        add_action('woocommerce_delete_coupon', [$this, 'sync_coupon_delete'], 10, 2);

        // Retry queue — flush up to QUEUE_FLUSH_BATCH events on every init
        add_action('init', [$this, 'flush_sync_queue'], 20);
    }

    // =========================================================================
    // Configuration helpers
    // =========================================================================

    /**
     * Get the Shopwalk plugin key from settings (unified key).
     */
    private function get_api_key(): string {
        return get_option('shopwalk_wc_plugin_key', '');
    }

    /**
     * Get the merchant ID.
     * Uses the configured option, or auto-derives from the site URL.
     */
    private function get_merchant_id(): string {
        $configured = get_option('shopwalk_wc_merchant_id', '');
        if (!empty($configured)) {
            return $configured;
        }
        // Auto-derive from site URL
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        return str_replace('.', '-', $host ?? 'unknown');
    }

    // =========================================================================
    // Transport layer
    // =========================================================================

    /**
     * POST an event payload to the Shopwalk sync endpoint.
     * On failure (WP_Error or non-2xx), queues the payload for retry.
     *
     * @param  array $payload Structured event array.
     * @return bool  true on success, false on failure.
     */
    private function send_event(array $payload): bool {
        $api_key = $this->get_api_key();
        if (empty($api_key)) {
            return false;
        }

        $body = wp_json_encode($payload);

        $response = wp_remote_post(self::SYNC_ENDPOINT, [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-Key'    => $api_key,
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            $this->push_to_queue($body);
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            $this->push_to_queue($body);
            return false;
        }

        return true;
    }

    // =========================================================================
    // Retry queue
    // =========================================================================

    /**
     * Push a failed payload JSON string onto the retry queue.
     * Silently drops the event when the queue is at capacity.
     *
     * @param string $payload_json JSON-encoded event payload.
     */
    private function push_to_queue(string $payload_json): void {
        $queue = get_option(self::QUEUE_OPTION, []);
        if (!is_array($queue)) {
            $queue = [];
        }

        if (count($queue) >= self::QUEUE_CAP) {
            return; // Queue full — drop event rather than unbounded growth
        }

        $queue[] = $payload_json;
        update_option(self::QUEUE_OPTION, $queue, false);
    }

    /**
     * Flush up to QUEUE_FLUSH_BATCH events from the retry queue.
     * Successfully sent events are removed; failures stay in the queue.
     * Registered on the 'init' hook (priority 20).
     */
    public function flush_sync_queue(): void {
        $api_key = $this->get_api_key();
        if (empty($api_key)) {
            return;
        }

        $queue = get_option(self::QUEUE_OPTION, []);
        if (empty($queue) || !is_array($queue)) {
            return;
        }

        $batch     = array_slice($queue, 0, self::QUEUE_FLUSH_BATCH);
        $remaining = array_slice($queue, self::QUEUE_FLUSH_BATCH);
        $failed    = [];

        foreach ($batch as $payload_json) {
            $response = wp_remote_post(self::SYNC_ENDPOINT, [
                'timeout' => 10,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-API-Key'    => $api_key,
                ],
                'body' => $payload_json,
            ]);

            $ok = !is_wp_error($response);
            if ($ok) {
                $code = wp_remote_retrieve_response_code($response);
                $ok   = ($code >= 200 && $code < 300);
            }

            if (!$ok) {
                $failed[] = $payload_json; // Keep for next attempt
            }
        }

        // Put failures back in front, then the untouched tail
        $new_queue = array_merge($failed, $remaining);
        update_option(self::QUEUE_OPTION, $new_queue, false);
    }

    // =========================================================================
    // Product sync handlers
    // =========================================================================

    /**
     * Sync a product to Shopwalk (product.upsert).
     */
    public function sync_product(int $product_id, $product = null): void {
        if (get_option('shopwalk_wc_enable_sync', 'yes') !== 'yes') {
            return;
        }

        $api_key = $this->get_api_key();
        if (empty($api_key)) {
            return;
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

        $payload = $this->build_upsert_event($product);
        $success = $this->send_event($payload);

        // Record sync status
        update_option('shopwalk_wc_last_sync', current_time('Y-m-d H:i:s'));
        update_option('shopwalk_wc_sync_status', $success ? 'OK' : 'Error: sync failed');
    }

    /**
     * Notify Shopwalk when a product is permanently deleted (product.delete).
     */
    public function delete_product(int $product_id): void {
        if (get_option('shopwalk_wc_enable_sync', 'yes') !== 'yes') {
            return;
        }

        if (empty($this->get_api_key())) {
            return;
        }

        $payload = [
            'event_type'  => 'product.delete',
            'source'      => 'plugin',
            'merchant_id' => $this->get_merchant_id(),
            'product'     => [
                'external_id' => (string) $product_id,
            ],
        ];

        $this->send_event($payload);
    }

    /**
     * Handle trashed products — treat as a delete signal.
     */
    public function trash_product(int $post_id): void {
        if (get_post_type($post_id) !== 'product') {
            return;
        }
        $this->delete_product($post_id);
    }

    /**
     * Fallback permanent-delete hook.
     * Catches variable products and any type missed by woocommerce_delete_product.
     */
    public function maybe_delete_product(int $post_id): void {
        if (get_post_type($post_id) !== 'product') {
            return;
        }
        $this->delete_product($post_id);
    }

    // =========================================================================
    // Stock update handlers
    // =========================================================================

    /**
     * Sync a stock-status change (product.stock_update).
     * Hooked to woocommerce_product_set_stock_status.
     *
     * @param int        $product_id
     * @param string     $status      'instock'|'outofstock'|'onbackorder'
     * @param WC_Product $product
     */
    public function sync_stock_update(int $product_id, string $status, WC_Product $product): void {
        if (get_option('shopwalk_wc_enable_sync', 'yes') !== 'yes') {
            return;
        }

        if (empty($this->get_api_key())) {
            return;
        }

        $payload = [
            'event_type'  => 'product.stock_update',
            'source'      => 'plugin',
            'merchant_id' => $this->get_merchant_id(),
            'product'     => [
                'external_id'    => (string) $product_id,
                'in_stock'       => ($status === 'instock'),
                'stock_quantity' => (int) ($product->get_stock_quantity() ?? 0),
                'stock_status'   => $status,
            ],
        ];

        $this->send_event($payload);
    }

    /**
     * Sync a stock-quantity change (product.stock_update).
     * Hooked to woocommerce_product_set_stock.
     *
     * @param WC_Product $product
     * @param int|null   $stock_quantity  New stock quantity
     * @param int|null   $old_stock       Previous stock quantity
     */
    public function sync_stock_update_quantity($product, $stock_quantity, $old_stock): void {
        if (get_option('shopwalk_wc_enable_sync', 'yes') !== 'yes') {
            return;
        }

        if (!$product instanceof WC_Product) {
            return;
        }

        if (empty($this->get_api_key())) {
            return;
        }

        $status = $product->get_stock_status();

        $payload = [
            'event_type'  => 'product.stock_update',
            'source'      => 'plugin',
            'merchant_id' => $this->get_merchant_id(),
            'product'     => [
                'external_id'    => (string) $product->get_id(),
                'in_stock'       => ($status === 'instock'),
                'stock_quantity' => (int) ($stock_quantity ?? 0),
                'stock_status'   => $status,
            ],
        ];

        $this->send_event($payload);
    }

    // =========================================================================
    // Price update handler
    // =========================================================================

    /**
     * Sync a price/sale change (product.price_update).
     * Hooked to woocommerce_product_object_updated_props.
     * Only fires if price-related props were among the changed properties.
     *
     * @param WC_Product $product
     * @param string[]   $updated_props
     */
    public function sync_price_update(WC_Product $product, array $updated_props): void {
        $price_props = ['regular_price', 'sale_price', 'price'];
        if (empty(array_intersect($price_props, $updated_props))) {
            return; // No price-related change — skip
        }

        if (get_option('shopwalk_wc_enable_sync', 'yes') !== 'yes') {
            return;
        }

        if (empty($this->get_api_key())) {
            return;
        }

        $regular_price = (float) $product->get_regular_price();
        $sale_price    = $product->get_sale_price();
        $on_sale       = $product->is_on_sale();

        $payload = [
            'event_type'  => 'product.price_update',
            'source'      => 'plugin',
            'merchant_id' => $this->get_merchant_id(),
            'product'     => [
                'external_id'      => (string) $product->get_id(),
                'base_price'       => ($on_sale && $sale_price !== '') ? (float) $sale_price : $regular_price,
                'compare_at_price' => $on_sale ? $regular_price : null,
                'on_sale'          => $on_sale,
                'currency'         => get_woocommerce_currency(),
            ],
        ];

        $this->send_event($payload);
    }

    // =========================================================================
    // Coupon sync handlers
    // =========================================================================

    /**
     * Sync a coupon create or update (product.coupon_update / action: upsert).
     * Hooked to woocommerce_new_coupon and woocommerce_update_coupon.
     *
     * @param int            $coupon_id
     * @param WC_Coupon|null $coupon
     */
    public function sync_coupon_upsert(int $coupon_id, $coupon = null): void {
        if (get_option('shopwalk_wc_enable_sync', 'yes') !== 'yes') {
            return;
        }

        if (empty($this->get_api_key())) {
            return;
        }

        if (!$coupon instanceof WC_Coupon) {
            $coupon = new WC_Coupon($coupon_id);
        }

        $expiry = $coupon->get_date_expires();

        $payload = [
            'event_type'  => 'product.coupon_update',
            'source'      => 'plugin',
            'merchant_id' => $this->get_merchant_id(),
            'coupon'      => [
                'id'            => (string) $coupon_id,
                'code'          => $coupon->get_code(),
                'discount_type' => $coupon->get_discount_type(),
                'amount'        => $coupon->get_amount(),
                'expiry_date'   => $expiry ? $expiry->date('Y-m-d') : null,
                'action'        => 'upsert',
            ],
        ];

        $this->send_event($payload);
    }

    /**
     * Sync a coupon deletion (product.coupon_update / action: delete).
     * Hooked to woocommerce_delete_coupon.
     *
     * @param int            $coupon_id
     * @param WC_Coupon|null $coupon
     */
    public function sync_coupon_delete(int $coupon_id, $coupon = null): void {
        if (get_option('shopwalk_wc_enable_sync', 'yes') !== 'yes') {
            return;
        }

        if (empty($this->get_api_key())) {
            return;
        }

        // Retrieve coupon code before the record is gone
        $code = '';
        if ($coupon instanceof WC_Coupon) {
            $code = $coupon->get_code();
        } else {
            $post = get_post($coupon_id);
            $code = $post ? $post->post_title : '';
        }

        $payload = [
            'event_type'  => 'product.coupon_update',
            'source'      => 'plugin',
            'merchant_id' => $this->get_merchant_id(),
            'coupon'      => [
                'id'     => (string) $coupon_id,
                'code'   => $code,
                'action' => 'delete',
            ],
        ];

        $this->send_event($payload);
    }

    // =========================================================================
    // Bulk sync (WP-Cron background job)
    // =========================================================================

    /**
     * Bulk-sync all published products. Invoked by the 'shopwalk_wc_bulk_sync' cron event.
     */
    public static function run_bulk_sync(): void {
        $products = wc_get_products(['status' => 'publish', 'limit' => -1, 'return' => 'ids']);
        $synced   = 0;
        $failed   = 0;

        foreach ($products as $id) {
            $instance = self::instance();
            try {
                $instance->sync_product($id);
                $synced++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        update_option('shopwalk_wc_bulk_sync_result', [
            'synced' => $synced,
            'failed' => $failed,
            'total'  => count($products),
            'at'     => current_time('Y-m-d H:i:s'),
        ]);
    }

    // =========================================================================
    // Payload builder
    // =========================================================================

    /**
     * Build the full product.upsert event payload.
     *
     * @param  WC_Product $product
     * @return array
     */
    private function build_upsert_event(WC_Product $product): array {
        // Categories
        $categories = [];
        $terms = get_the_terms($product->get_id(), 'product_cat');
        if ($terms && !is_wp_error($terms)) {
            $categories = array_map(fn($t) => $t->name, $terms);
        }

        // Images (featured + gallery)
        $images   = [];
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

        foreach ($product->get_gallery_image_ids() as $i => $gid) {
            $url = wp_get_attachment_url($gid);
            if ($url) {
                $images[] = [
                    'url'      => $url,
                    'alt_text' => get_post_meta($gid, '_wp_attachment_image_alt', true) ?: '',
                    'position' => $i + 1,
                ];
            }
        }

        // Pricing
        $regular_price = $product->get_regular_price();
        $on_sale       = $product->is_on_sale();

        return [
            'event_type'  => 'product.upsert',
            'source'      => 'plugin',
            'merchant_id' => $this->get_merchant_id(),
            'product'     => [
                'external_id'       => (string) $product->get_id(),
                'provider'          => 'woocommerce',
                'name'              => $product->get_name(),
                'description'       => $product->get_description(),
                'short_description' => $product->get_short_description(),
                'category'          => !empty($categories) ? $categories[0] : '',
                'base_price'        => (float) $product->get_price(),
                'compare_at_price'  => ($on_sale && $regular_price !== '') ? (float) $regular_price : null,
                'currency'          => get_woocommerce_currency(),
                'in_stock'          => $product->is_in_stock(),
                'stock_quantity'    => (int) ($product->get_stock_quantity() ?? 0),
                'on_sale'           => $on_sale,
                'source_url'        => get_permalink($product->get_id()),
                'images'            => $images,
            ],
        ];
    }
}

// Boot the sync singleton
add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce')) {
        Shopwalk_WC_Sync::instance();
    }
}, 20);

// Register the WP-Cron bulk-sync callback
add_action('shopwalk_wc_bulk_sync', [Shopwalk_WC_Sync::class, 'run_bulk_sync']);
