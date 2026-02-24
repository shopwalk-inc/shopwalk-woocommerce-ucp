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

    public function register_routes(string $namespace): void {
        // List products (public catalog)
        register_rest_route($namespace, '/products', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_products'],
            'permission_callback' => [Shopwalk_WC_Auth::class, 'check_public_permission'],
            'args'                => [
                'page'     => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'per_page' => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100],
                'search'   => ['type' => 'string', 'default' => ''],
                'category' => ['type' => 'string', 'default' => ''],
            ],
        ]);

        // Get single product
        register_rest_route($namespace, '/products/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_product'],
            'permission_callback' => [Shopwalk_WC_Auth::class, 'check_public_permission'],
        ]);

        // List categories
        register_rest_route($namespace, '/categories', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_categories'],
            'permission_callback' => [Shopwalk_WC_Auth::class, 'check_public_permission'],
        ]);
    }

    /**
     * List products in normalized format.
     */
    public function list_products(WP_REST_Request $request): WP_REST_Response {
        $page     = $request->get_param('page');
        $per_page = $request->get_param('per_page');
        $search   = $request->get_param('search');
        $category = $request->get_param('category');

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

        $query    = new WC_Product_Query($args);
        $products = $query->get_products();

        // Get total count
        $count_args           = $args;
        $count_args['limit']  = -1;
        $count_args['return'] = 'ids';
        $total = count((new WC_Product_Query($count_args))->get_products());

        $items = array_map([$this, 'format_product'], $products);

        return new WP_REST_Response([
            'items'      => $items,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $per_page,
                'total'       => $total,
                'total_pages' => (int) ceil($total / $per_page),
            ],
        ], 200);
    }

    /**
     * Get a single product.
     */
    public function get_product(WP_REST_Request $request): WP_REST_Response {
        $product = wc_get_product((int) $request->get_param('id'));

        if (!$product || $product->get_status() !== 'publish') {
            return new WP_REST_Response([
                'error' => 'Product not found.',
            ], 404);
        }

        return new WP_REST_Response($this->format_product($product, true), 200);
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

    /**
     * Format a WC_Product into normalized JSON for Shopwalk.
     */
    private function format_product(WC_Product $product, bool $detailed = false): array {
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

        $item = [
            'id'                => $product->get_id(),
            'name'              => $product->get_name(),
            'slug'              => $product->get_slug(),
            'sku'               => $product->get_sku(),
            'price'             => (float) $product->get_price(),
            'regular_price'     => (float) $product->get_regular_price(),
            'sale_price'        => $product->get_sale_price() ? (float) $product->get_sale_price() : null,
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
                    $variations[] = [
                        'id'            => $var['variation_id'],
                        'sku'           => $var['sku'],
                        'price'         => (float) $var['display_price'],
                        'regular_price' => (float) $var['display_regular_price'],
                        'in_stock'      => $var['is_in_stock'],
                        'attributes'    => $var['attributes'],
                        'image'         => $var['image']['url'] ?? null,
                    ];
                }
                $item['variations'] = $variations;
            }
        }

        return $item;
    }
}
