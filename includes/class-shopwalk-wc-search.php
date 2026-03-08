<?php
/**
 * Semantic Search Overlay — AI-powered product search.
 *
 * Enqueues frontend search assets and provides popular products fallback.
 * Registers 15-minute heartbeat to keep Store Boost cache fresh.
 *
 * @package ShopwalkAI
 * @license GPL-2.0-or-later
 * @copyright Copyright (c) 2024-2026 Shopwalk, Inc.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_WC_Search class.
 */
class Shopwalk_WC_Search {

	/**
	 * Construct.
	 */
	public function __construct() {
		// Only enqueue if search is enabled.
		if ( ! get_option( 'shopwalk_feature_search_enabled', 1 ) ) {
			return;
		}

		// Enqueue search assets on frontend.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_search_assets' ) );

		// Schedule heartbeat WP-Cron if we have an API key.
		if ( ! empty( get_option( 'shopwalk_wc_plugin_key', '' ) ) ) {
			$this->schedule_heartbeat();
		}

		// Heartbeat cron handler.
		add_action( 'shopwalk_sync_heartbeat', array( $this, 'do_heartbeat' ) );
	}

	/**
	 * Enqueue search overlay CSS and JS on WooCommerce frontend pages.
	 */
	public function enqueue_search_assets(): void {
		// Only on WooCommerce pages.
		if ( ! function_exists( 'is_shop' ) || ! ( is_shop() || is_product_category() || is_product_taxonomy() ) ) {
			return;
		}

		$merchant_id = get_option( 'shopwalk_merchant_id', '' );
		$api_key     = get_option( 'shopwalk_wc_plugin_key', '' );

		if ( empty( $merchant_id ) || empty( $api_key ) ) {
			return;
		}

		// Enqueue CSS.
		wp_enqueue_style(
			'shopwalk-search',
			SHOPWALK_AI_PLUGIN_URL . 'assets/css/shopwalk-search.css',
			array(),
			SHOPWALK_AI_VERSION
		);

		// Enqueue JS.
		wp_enqueue_script(
			'shopwalk-search',
			SHOPWALK_AI_PLUGIN_URL . 'assets/js/shopwalk-search.js',
			array(),
			SHOPWALK_AI_VERSION,
			true
		);

		// Localize script with data.
		$popular_products = $this->get_popular_products( 6 );
		wp_localize_script(
			'shopwalk-search',
			'shopwalkSearch',
			array(
				'apiUrl'          => rest_url( 'shopwalk-api/v1/' ),
				'apiKey'          => $api_key,
				'merchantId'      => $merchant_id,
				'popularProducts' => $popular_products,
			)
		);
	}

	/**
	 * Get popular products with 3-layer fallback.
	 *
	 * @param int $limit Maximum number of products to return.
	 * @return array Array of product data: [id, name, price, image_url, url].
	 */
	public function get_popular_products( int $limit = 6 ): array {
		$products = array();

		// Layer 1: Products by popularity (total_sales).
		$popular = wc_get_products(
			array(
				'orderby' => 'popularity',
				'order'   => 'DESC',
				'limit'   => $limit,
				'status'  => 'publish',
				'return'  => 'objects',
			)
		);

		foreach ( $popular as $product ) {
			$products[] = $this->format_product( $product );
			if ( count( $products ) >= $limit ) {
				return $products;
			}
		}

		// Layer 2: Featured products.
		$featured_ids = wc_get_featured_product_ids();
		foreach ( $featured_ids as $product_id ) {
			if ( count( $products ) >= $limit ) {
				break;
			}
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$products[] = $this->format_product( $product );
			}
		}

		if ( count( $products ) >= $limit ) {
			return array_slice( $products, 0, $limit );
		}

		// Layer 3: Random in-stock products.
		$random = wc_get_products(
			array(
				'orderby'     => 'rand',
				'limit'       => $limit - count( $products ),
				'status'      => 'publish',
				'stock_status' => 'instock',
				'return'      => 'objects',
			)
		);

		foreach ( $random as $product ) {
			$products[] = $this->format_product( $product );
			if ( count( $products ) >= $limit ) {
				break;
			}
		}

		return array_slice( $products, 0, $limit );
	}

	/**
	 * Format a WooCommerce product for JSON response.
	 *
	 * @param WC_Product $product WooCommerce product object.
	 * @return array Formatted product data.
	 */
	private function format_product( WC_Product $product ): array {
		$image_id = $product->get_image_id();
		$image_url = $image_id ? wp_get_attachment_url( $image_id ) : wc_placeholder_img_src();

		return array(
			'id'        => (string) $product->get_id(),
			'name'      => $product->get_name(),
			'price'     => $product->get_price_html(),
			'image_url' => $image_url ?: '',
			'url'       => $product->get_permalink(),
		);
	}

	/**
	 * Schedule the heartbeat WP-Cron event if not already scheduled.
	 */
	private function schedule_heartbeat(): void {
		if ( ! wp_next_scheduled( 'shopwalk_sync_heartbeat' ) ) {
			wp_schedule_event( time(), '15mins', 'shopwalk_sync_heartbeat' );
		}
	}

	/**
	 * Execute the heartbeat: POST to API, store cached image IDs in transient.
	 */
	public function do_heartbeat(): void {
		$api_key = get_option( 'shopwalk_wc_plugin_key', '' );
		$merchant_id = get_option( 'shopwalk_merchant_id', '' );

		if ( empty( $api_key ) || empty( $merchant_id ) ) {
			return;
		}

		// Get product count.
		$product_count = (int) wc_get_products(
			array(
				'status' => 'publish',
				'return' => 'ids',
				'limit'  => -1,
			),
		);

		// Build heartbeat payload.
		$payload = array(
			'plugin_version' => SHOPWALK_AI_VERSION,
			'product_count'  => $product_count,
			'site_url'       => get_site_url(),
			'timestamp'      => time(),
		);

		// POST to heartbeat endpoint.
		$response = wp_remote_post(
			'https://api.shopwalk.com/api/v1/plugin/heartbeat',
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-API-Key'    => $api_key,
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body ) ) {
			return;
		}

		// Store cached image IDs in transient (TTL 15 min = 900 sec).
		if ( ! empty( $body['cached_image_ids'] ) && is_array( $body['cached_image_ids'] ) ) {
			set_transient( 'shopwalk_cached_image_ids', $body['cached_image_ids'], 900 );
		}

		// Store cache counts in options.
		if ( ! empty( $body['cached_count'] ) ) {
			update_option( 'shopwalk_cdn_image_count', (int) $body['cached_count'] );
		}
		if ( ! empty( $body['total_count'] ) ) {
			update_option( 'shopwalk_cdn_total_images', (int) $body['total_count'] );
		}
	}
}
