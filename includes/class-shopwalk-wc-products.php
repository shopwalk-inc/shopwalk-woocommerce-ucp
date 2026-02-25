<?php
/**
 * Catalog / Products API — exposes WooCommerce products for Shopwalk AI.
 *
 * @package ShopwalkAI
 * @license GPL-2.0-or-later
 * @copyright Copyright (c) 2024-2026 Shopwalk, Inc.
 */

defined('ABSPATH') || exit;

class Shopwalk_WC_Products {

    /** REST namespace constant for status checks. */
    const REST_NAMESPACE = 'shopwalk-wc/v1';

    public function register_routes(string $namespace): void {
        // List products (public catalog)
        register_rest_route($namespace, '/products', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_products'],
            'permission_callback' => [Shopwalk_WC_Auth::class, 'check_public_permission'],
            'args'                => [
                'page'      => ['type' => 'integer', 'default' => 1,  'minimum' => 1],
                'per_page'  => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100],
                'search'    => ['type' => 'string',  'default' => ''],
                'category'  => ['type' => 'string',  'default' => ''],
                'min_price' => ['type' => 'number',  'default' => null],
                'max_price' => ['type' => 'number',  'default' => null],
                'in_stock'  => ['type' => 'boolean', 'default' => null],
            ],
        ]);

        // Get single product (public)
        register_rest_route($namespace, '/products/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_product'],
            'permission_callback' => [Shopwalk_WC_Auth::class, 'check_public_permission'],
        ]);

        // Product availability (public — used by AI agents before adding to cart)
        register_rest_route($namespace, '/products/(?P<id>\d+)/availability', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_availability'],
            'permission_callback' => [Shopwalk_WC_Auth::class, 'check_public_permission'],
        ]);

        // List categories (public)
        register_rest_route($namespace, '/categories', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_categories'],
            'permission_callback' => [Shopwalk_WC_Auth::class, 'check_public_permission'],
        ]);
    }

    // -------------------------------------------------------------------------
    // Endpoint handlers
    // -------------------------------------------------------------------------

    /**
     * List products in UCP normalized format.
     * Supports: page, per_page, search, category, min_price, max_price, in_stock
     */
    public function list_products(WP_REST_Request $request): WP_REST_Response {
        $page      = (int) $request->get_param('page');
        $per_page  = (int) $request->get_param('per_page');
        $search    = sanitize_text_field($request->get_param('search') ?? '');
        $category  = sanitize_text_field($request->get_param('category') ?? '');
        $min_price = $request->get_param('min_price');
        $max_price = $request->get_param('max_price');
        $in_stock  = $request->get_param('in_stock');

        $args = [
            'status'  => 'publish',
            'limit'   => $per_page,
            'page'    => $page,
            'orderby' => 'date',
            'order'   => 'DESC',
        ];

        if ($search) {
            $args['s'] = $search;
        }

        if ($category) {
            $term = get_term_by('slug', $category, 'product_cat');
            if ($term) {
                $args['category'] = [$term->term_id];
            }
        }

        // in_stock filter
        if ($in_stock !== null) {
            $args['stock_status'] = $in_stock ? 'instock' : 'outofstock';
        }

        // price range filter — uses WP_Query meta query under the hood
        if ($min_price !== null || $max_price !== null) {
            $meta_query = [];
            if ($min_price !== null) {
                $meta_query[] = [
                    'key'     => '_price',
                    'value'   => (float) $min_price,
                    'compare' => '>=',
                    'type'    => 'NUMERIC',
                ];
            }
            if ($max_price !== null) {
                $meta_query[] = [
                    'key'     => '_price',
                    'value'   => (float) $max_price,
                    'compare' => '<=',
                    'type'    => 'NUMERIC',
                ];
            }
            if (count($meta_query) > 1) {
                $meta_query['relation'] = 'AND';
            }
            $args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        }

        $query    = new WC_Product_Query($args);
        $products = $query->get_products();

        // Get total count for pagination
        $count_args           = $args;
        $count_args['limit']  = -1;
        $count_args['return'] = 'ids';
        unset($count_args['page']);
        $total = count((new WC_Product_Query($count_args))->get_products());

        $items = array_map([$this, 'format_product'], $products);

        return new WP_REST_Response([
            'items'      => $items,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $per_page,
                'total'       => $total,
                'total_pages' => (int) ceil($total / max($per_page, 1)),
            ],
        ], 200);
    }

    /**
     * Get a single product (detailed).
     */
    public function get_product(WP_REST_Request $request): WP_REST_Response {
        $product = wc_get_product((int) $request->get_param('id'));

        if (!$product || $product->get_status() !== 'publish') {
            return new WP_REST_Response([
                'error' => ['code' => 'PRODUCT_NOT_FOUND', 'message' => 'Product not found.'],
            ], 404);
        }

        return new WP_REST_Response($this->format_product($product, true), 200);
    }

    /**
     * Product Availability Endpoint.
     * Returns real-time stock and pricing for a product (and all variants if variable).
     * Used by AI agents before adding a product to cart.
     */
    public function get_availability(WP_REST_Request $request): WP_REST_Response {
        $product = wc_get_product((int) $request->get_param('id'));

        if (!$product || $product->get_status() !== 'publish') {
            return new WP_REST_Response([
                'error' => ['code' => 'PRODUCT_NOT_FOUND', 'message' => 'Product not found.'],
            ], 404);
        }

        $currency    = get_woocommerce_currency();
        $price       = (float) $product->get_price();
        $sale_price  = $product->get_sale_price() ? (float) $product->get_sale_price() : null;

        $availability = [
            'id'              => $product->get_id(),
            'name'            => $product->get_name(),
            'sku'             => $product->get_sku(),
            'currency'        => $currency,
            'in_stock'        => $product->is_in_stock(),
            'stock_status'    => $product->get_stock_status(),
            'quantity'        => $product->get_stock_quantity(),
            'manage_stock'    => $product->managing_stock(),
            'price_cents'     => (int) round($price * 100),
            'sale_price_cents'=> $sale_price !== null ? (int) round($sale_price * 100) : null,
            'backorders'      => $product->get_backorders(),
        ];

        // Variable product — return each variation's availability
        if ($product->is_type('variable')) {
            /** @var WC_Product_Variable $product */
            $variants = [];
            foreach ($product->get_available_variations() as $var_data) {
                $variation = wc_get_product($var_data['variation_id']);
                if (!$variation) {
                    continue;
                }
                $var_sale = $variation->get_sale_price() ? (float) $variation->get_sale_price() : null;
                $variants[] = [
                    'id'               => $variation->get_id(),
                    'sku'              => $variation->get_sku(),
                    'attributes'       => $var_data['attributes'],
                    'in_stock'         => $variation->is_in_stock(),
                    'stock_status'     => $variation->get_stock_status(),
                    'quantity'         => $variation->get_stock_quantity(),
                    'manage_stock'     => $variation->managing_stock(),
                    'price_cents'      => (int) round((float) $variation->get_price() * 100),
                    'sale_price_cents' => $var_sale !== null ? (int) round($var_sale * 100) : null,
                    'backorders'       => $variation->get_backorders(),
                ];
            }
            $availability['variants'] = $variants;
        }

        return new WP_REST_Response($availability, 200);
    }

    /**
     * List product categories.
     */
    public function list_categories(WP_REST_Request $request): WP_REST_Response {
        $terms = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => true,
            'orderby'    => 'name',
        ]);

        $categories = [];
        foreach ($terms as $term) {
            $thumbnail_id = get_term_meta($term->term_id, 'thumbnail_id', true);
            $image_url    = $thumbnail_id ? wp_get_attachment_image_url($thumbnail_id, 'medium') : null;

            $categories[] = [
                'id'          => $term->term_id,
                'name'        => $term->name,
                'slug'        => $term->slug,
                'description' => $term->description,
                'parent_id'   => $term->parent ?: null,
                'count'       => $term->count,
                'image'       => $image_url,
            ];
        }

        return new WP_REST_Response(['items' => $categories], 200);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Format a WC_Product into UCP-normalized JSON.
     *
     * @param WC_Product $product
     * @param bool       $detailed Include description, attributes, and variants.
     */
    public function format_product(WC_Product $product, bool $detailed = false): array {
        $images = [];
        $attachment_ids = $product->get_gallery_image_ids();
        $main_image_id  = $product->get_image_id();

        if ($main_image_id) {
            array_unshift($attachment_ids, $main_image_id);
        }

        foreach (array_unique($attachment_ids) as $i => $img_id) {
            $url = wp_get_attachment_image_url($img_id, 'large');
            $alt = get_post_meta($img_id, '_wp_attachment_image_alt', true);
            if ($url) {
                $images[] = [
                    'url'      => $url,
                    'alt'      => $alt ?: $product->get_name(),
                    'position' => $i,
                ];
            }
        }

        $categories = [];
        foreach ($product->get_category_ids() as $cat_id) {
            $term = get_term($cat_id, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $categories[] = [
                    'id'   => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                ];
            }
        }

        $price      = (float) $product->get_price();
        $sale_price = $product->get_sale_price() ? (float) $product->get_sale_price() : null;

        $item = [
            'id'                => $product->get_id(),
            'name'              => $product->get_name(),
            'slug'              => $product->get_slug(),
            'sku'               => $product->get_sku(),
            // UCP standard: price in cents (integer)
            'price_cents'       => (int) round($price * 100),
            'sale_price_cents'  => $sale_price !== null ? (int) round($sale_price * 100) : null,
            // Legacy float fields kept for backward compatibility
            'price'             => $price,
            'regular_price'     => (float) $product->get_regular_price(),
            'sale_price'        => $sale_price,
            'currency'          => get_woocommerce_currency(),
            'in_stock'          => $product->is_in_stock(),
            'stock_quantity'    => $product->get_stock_quantity(),
            'short_description' => wp_strip_all_tags($product->get_short_description()),
            'categories'        => $categories,
            'images'            => $images,
            'url'               => $product->get_permalink(),
        ];

        if ($detailed) {
            $item['description'] = wp_strip_all_tags($product->get_description());
            $item['weight']      = $product->get_weight();
            $item['dimensions']  = [
                'length' => $product->get_length(),
                'width'  => $product->get_width(),
                'height' => $product->get_height(),
            ];

            // Attributes
            $attributes = [];
            foreach ($product->get_attributes() as $attr) {
                if ($attr instanceof WC_Product_Attribute) {
                    $attributes[] = [
                        'name'    => wc_attribute_label($attr->get_name()),
                        'options' => $attr->get_options(),
                    ];
                }
            }
            $item['attributes'] = $attributes;

            // Variations (if variable product)
            if ($product->is_type('variable')) {
                $variations = [];
                foreach ($product->get_available_variations() as $var) {
                    $var_sale = $var['display_price'] != $var['display_regular_price']
                        ? (int) round((float) $var['display_price'] * 100)
                        : null;

                    $variations[] = [
                        'id'               => $var['variation_id'],
                        'sku'              => $var['sku'],
                        'price_cents'      => (int) round((float) $var['display_price'] * 100),
                        'sale_price_cents' => $var_sale,
                        // Legacy float fields
                        'price'            => (float) $var['display_price'],
                        'regular_price'    => (float) $var['display_regular_price'],
                        'in_stock'         => $var['is_in_stock'],
                        'attributes'       => $var['attributes'],
                        'image'            => $var['image']['url'] ?? null,
                    ];
                }
                $item['variants'] = $variations;
            }
        }

        return $item;
    }
}
