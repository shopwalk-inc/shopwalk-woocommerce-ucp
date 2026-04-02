<?php
/**
 * Product data formatter — converts WC_Product to Shopwalk API format.
 *
 * @package Shopwalk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_WC_Products class.
 */
class Shopwalk_WC_Products {

	/**
	 * Format a WC_Product into Shopwalk product shape.
	 *
	 * @param WC_Product $product WooCommerce product object.
	 * @return array Formatted product data.
	 */
	public static function format( WC_Product $product ): array {
		// Build images array — batch meta query to avoid N+1.
		$images     = array();
		$image_ids  = $product->get_gallery_image_ids();
		$main_image = $product->get_image_id();
		if ( $main_image ) {
			array_unshift( $image_ids, $main_image );
		}

		// Deduplicate with a hash set (O(1) lookup vs O(n) in_array).
		$seen = array();
		// Prime the post meta cache for all image IDs in one query.
		if ( ! empty( $image_ids ) ) {
			update_meta_cache( 'post', $image_ids );
		}
		foreach ( $image_ids as $img_id ) {
			if ( ! $img_id || isset( $seen[ $img_id ] ) ) {
				continue;
			}
			$seen[ $img_id ] = true;
			$url = wp_get_attachment_url( $img_id );
			if ( $url ) {
				$images[] = array(
					'url'      => $url,
					'alt'      => (string) get_post_meta( $img_id, '_wp_attachment_image_alt', true ),
					'position' => count( $images ),
				);
			}
		}

		// Build categories array — batch query all terms at once.
		$categories = array();
		$cat_ids    = $product->get_category_ids();
		if ( ! empty( $cat_ids ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'include'    => $cat_ids,
					'hide_empty' => false,
				)
			);
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$categories[] = array(
						'id'   => $term->term_id,
						'name' => $term->name,
						'slug' => $term->slug,
					);
				}
			}
		}

		// Build tags array.
		$tags      = array();
		$tag_terms = get_the_terms( $product->get_id(), 'product_tag' );
		if ( $tag_terms && ! is_wp_error( $tag_terms ) ) {
			foreach ( $tag_terms as $tag ) {
				$tags[] = $tag->name;
			}
		}

		$price      = (float) $product->get_price();
		$regular    = (float) $product->get_regular_price();
		$dimensions = $product->get_dimensions( false );

		// Build variations for variable products — batch load to avoid N+1.
		$variations = array();
		if ( $product->is_type( 'variable' ) ) {
			/** @var WC_Product_Variable $product */
			$variation_ids = array_slice( $product->get_children(), 0, 20 );
			if ( ! empty( $variation_ids ) ) {
				// Prime the post and meta caches for all variations in one query.
				_prime_post_caches( $variation_ids, true, true );
				foreach ( $variation_ids as $var_id ) {
					$variation = wc_get_product( $var_id );
					if ( ! $variation ) {
						continue;
					}
					$attrs = array();
					foreach ( $variation->get_variation_attributes() as $key => $val ) {
						$clean_key          = str_replace( 'attribute_pa_', '', $key );
						$clean_key          = str_replace( 'attribute_', '', $clean_key );
						$attrs[ ucfirst( $clean_key ) ] = $val;
					}
					$variations[] = array(
						'id'         => (string) $var_id,
						'attributes' => $attrs,
						'price'      => (float) $variation->get_price(),
						'in_stock'   => $variation->is_in_stock(),
						'sku'        => $variation->get_sku(),
					);
				}
			}
		}

		return array(
			'id'                => (string) $product->get_id(),
			'name'              => $product->get_name(),
			'description'       => wp_strip_all_tags( $product->get_description() ),
			'short_description' => wp_strip_all_tags( $product->get_short_description() ),
			'sku'               => $product->get_sku(),
			'price'             => $price,
			'compare_at_price'  => ( $regular > $price && $regular > 0 ) ? $regular : 0.0,
			'currency'          => get_woocommerce_currency(),
			'in_stock'          => $product->is_in_stock(),
			'stock_quantity'    => $product->get_stock_quantity(),
			'on_sale'           => $product->is_on_sale(),
			'url'               => (string) get_permalink( $product->get_id() ),
			'add_to_cart_url'   => (string) $product->add_to_cart_url(),
			'categories'        => $categories,
			'tags'              => $tags,
			'images'            => $images,
			'average_rating'    => (float) $product->get_average_rating(),
			'rating_count'      => (int) $product->get_rating_count(),
			'weight'            => $product->get_weight(),
			'dimensions'        => array(
				'length' => $dimensions['length'] ?? '',
				'width'  => $dimensions['width'] ?? '',
				'height' => $dimensions['height'] ?? '',
			),
			'type'              => $product->get_type(),
			'variations'        => $variations,
		);
	}

	/**
	 * Format a WC_Product into the compact shape used for API sync.
	 * Includes only fields the Shopwalk API needs for indexing.
	 *
	 * @param WC_Product $product WooCommerce product object.
	 * @return array Compact product data for API push.
	 */
	public static function format_for_sync( WC_Product $product ): array {
		$full       = self::format( $product );
		$categories = array_column( $full['categories'], 'name' );

		return array(
			'external_id'       => $full['id'],
			'name'              => $full['name'],
			'description'       => $full['description'],
			'short_description' => $full['short_description'],
			'sku'               => $full['sku'],
			'price'             => $full['price'],
			'compare_at_price'  => $full['compare_at_price'],
			'currency'          => $full['currency'],
			'in_stock'          => $full['in_stock'],
			'source_url'        => $full['url'],
			'categories'        => $categories,
			'images'            => $full['images'],
			'average_rating'    => $full['average_rating'],
			'rating_count'      => $full['rating_count'],
		);
	}
}
