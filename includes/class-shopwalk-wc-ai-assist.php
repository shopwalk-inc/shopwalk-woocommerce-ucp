<?php
/**
 * AI Product Description Improvement — Meta box for assisted product copy generation.
 *
 * Adds a meta box to the product edit page with an "Improve" button that triggers
 * SSE streaming from the Shopwalk API to generate improved title, short description,
 * and full description. Partner can review and apply each field independently.
 *
 * @package ShopwalkAI
 * @license GPL-2.0-or-later
 * @copyright Copyright (c) 2024-2026 Shopwalk, Inc.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_WC_AI_Assist class.
 */
class Shopwalk_WC_AI_Assist {

	/**
	 * Construct.
	 */
	public function __construct() {
		// Only enable if feature toggle is on.
		if ( ! get_option( 'shopwalk_feature_ai_descriptions_enabled', 1 ) ) {
			return;
		}

		// Add meta box on product post type.
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );

		// AJAX handlers for improvement and applying fields.
		add_action( 'wp_ajax_shopwalk_improve_description', array( $this, 'ajax_improve_description' ) );
		add_action( 'wp_ajax_shopwalk_apply_field', array( $this, 'ajax_apply_field' ) );
	}

	/**
	 * Register the meta box on the product edit page.
	 */
	public function add_meta_box(): void {
		if ( get_post_type() !== 'product' ) {
			return;
		}

		add_meta_box(
			'shopwalk-ai-improve',
			'✦ Shopwalk AI — Improve This Product',
			array( $this, 'render_meta_box' ),
			'product',
			'normal',
			'high'
		);
	}

	/**
	 * Render the meta box HTML.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_meta_box( WP_Post $post ): void {
		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return;
		}

		$nonce = wp_create_nonce( 'shopwalk_improve_nonce' );
		?>
		<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 0;">
			<!-- Left column: Current content -->
			<div style="border-right: 1px solid #ddd; padding-right: 20px;">
				<h4>Current Content</h4>
				<div style="margin-bottom: 12px;">
					<strong>Title:</strong><br>
					<code style="display: block; background: #f5f5f5; padding: 8px; border-radius: 4px; word-break: break-word;">
						<?php echo esc_html( $product->get_name() ); ?>
					</code>
				</div>
				<div style="margin-bottom: 12px;">
					<strong>Short Description:</strong><br>
					<code style="display: block; background: #f5f5f5; padding: 8px; border-radius: 4px; word-break: break-word;">
						<?php echo esc_html( $product->get_short_description() ); ?>
					</code>
				</div>
				<div>
					<strong>Full Description:</strong><br>
					<code style="display: block; background: #f5f5f5; padding: 8px; border-radius: 4px; word-break: break-word; max-height: 150px; overflow-y: auto;">
						<?php echo esc_html( wp_strip_all_tags( $product->get_description() ) ); ?>
					</code>
				</div>
			</div>

			<!-- Right column: AI suggestions -->
			<div style="padding-left: 20px;">
				<h4>AI Suggestions</h4>
				<div id="shopwalk-ai-suggestions" style="min-height: 200px;">
					<p style="color: #666; font-style: italic;">Click "Improve ▶" to generate suggestions...</p>
				</div>
				<div style="margin-top: 16px; display: flex; gap: 8px;">
					<button type="button" class="button button-primary" id="shopwalk-improve-btn" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-product-id="<?php echo esc_attr( $post->ID ); ?>">
						Improve ▶
					</button>
					<button type="button" class="button" id="shopwalk-retry-btn" style="display: none;">
						Try Again — all 3
					</button>
				</div>
			</div>
		</div>

		<script>
			(function() {
				const improveBtn = document.getElementById('shopwalk-improve-btn');
				const retryBtn = document.getElementById('shopwalk-retry-btn');
				const suggestionsDiv = document.getElementById('shopwalk-ai-suggestions');

				if (!improveBtn) return;

				improveBtn.addEventListener('click', function(e) {
					e.preventDefault();
					improveBtn.disabled = true;
					retryBtn.style.display = 'none';
					suggestionsDiv.innerHTML = '<p style="color: #666; font-style: italic;">Generating suggestions...</p>';

					const nonce = improveBtn.dataset.nonce;
					const productId = improveBtn.dataset.productId;

					fetch(ajaxurl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: new URLSearchParams({
							action: 'shopwalk_improve_description',
							nonce: nonce,
							product_id: productId
						})
					})
					.then(response => response.text())
					.then(text => {
						// Parse SSE stream
						const lines = text.split('\n');
						const suggestions = { title: '', short_description: '', description: '' };
						let currentField = null;

						lines.forEach(line => {
							if (line.startsWith('event: ')) {
								currentField = line.substring(7);
							} else if (line.startsWith('data: ') && currentField) {
								const data = line.substring(6);
								if (data && data !== '{}') {
									suggestions[currentField] = data;
								}
							}
						});

						renderSuggestions(suggestions);
						improveBtn.disabled = false;
						retryBtn.style.display = 'inline-block';
					})
					.catch(err => {
						suggestionsDiv.innerHTML = '<p style="color: #d32f2f;">Error generating suggestions. Please try again.</p>';
						improveBtn.disabled = false;
					});
				});

				function renderSuggestions(suggestions) {
					let html = '';
					['title', 'short_description', 'description'].forEach(field => {
						const label = field === 'title' ? 'Title' : field === 'short_description' ? 'Short Description' : 'Full Description';
						const suggestion = suggestions[field] || '(No suggestion)';
						html += `
							<div style="margin-bottom: 16px; border: 1px solid #ddd; padding: 12px; border-radius: 4px;">
								<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
									<strong>${label}</strong>
									<button type="button" class="button button-small" data-field="${field}" style="display: ${suggestion === '(No suggestion)' ? 'none' : 'inline-block'};">
										Apply ✓
									</button>
								</div>
								<code style="display: block; background: #f5f5f5; padding: 8px; border-radius: 4px; word-break: break-word; max-height: 120px; overflow-y: auto;">
									${escapeHtml(suggestion)}
								</code>
							</div>
						`;
					});
					suggestionsDiv.innerHTML = html;

					// Attach apply handlers
					document.querySelectorAll('#shopwalk-ai-suggestions button[data-field]').forEach(btn => {
						btn.addEventListener('click', function(e) {
							e.preventDefault();
							const field = this.dataset.field;
							const suggestion = suggestions[field];
							applyField(field, suggestion, nonce, improveBtn.dataset.productId);
						});
					});
				}

				function escapeHtml(text) {
					const map = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
					return text.replace(/[&<>"']/g, m => map[m]);
				}

				function applyField(field, value, nonce, productId) {
					fetch(ajaxurl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: new URLSearchParams({
							action: 'shopwalk_apply_field',
							nonce: nonce,
							product_id: productId,
							field: field,
							value: value
						})
					})
					.then(response => response.json())
					.then(data => {
						if (data.success) {
							const btn = document.querySelector(`button[data-field="${field}"]`);
							if (btn) {
								btn.textContent = 'Applied ✓';
								btn.disabled = true;
							}
						} else {
							alert('Failed to apply field. Please try again.');
						}
					})
					.catch(err => {
						alert('Error applying field. Please try again.');
					});
				}
			})();
		</script>
		<?php
	}

	/**
	 * AJAX handler: stream AI improvements from API via SSE.
	 */
	public function ajax_improve_description(): void {
		check_ajax_referer( 'shopwalk_improve_nonce', 'nonce' );

		$product_id = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
		if ( ! $product_id ) {
			wp_die( 'Invalid product ID.' );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			wp_die( 'Product not found.' );
		}

		// Get product data to send to API.
		$payload = array(
			'product_id'       => $product_id,
			'title'            => $product->get_name(),
			'short_description' => $product->get_short_description(),
			'description'      => wp_strip_all_tags( $product->get_description() ),
			'categories'       => wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) ),
			'price'            => $product->get_price(),
		);

		$api_key = get_option( 'shopwalk_wc_plugin_key', '' );
		if ( empty( $api_key ) ) {
			wp_die( 'API key not configured.' );
		}

		// Set headers for SSE.
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'X-Accel-Buffering: no' );

		// Stream from API.
		$response = wp_remote_post(
			'https://api.shopwalk.com/api/v1/plugin/improve-description',
			array(
				'headers'  => array(
					'Content-Type' => 'application/json',
					'X-API-Key'    => $api_key,
				),
				'body'     => wp_json_encode( $payload ),
				'timeout'  => 30,
				'stream'   => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			echo "event: error\ndata: {\"error\":\"Connection failed\"}\n\n";
			wp_die();
		}

		// Read the streamed response line by line.
		$response_body = wp_remote_retrieve_body( $response );
		if ( $response_body ) {
			echo $response_body;
		}

		wp_die();
	}

	/**
	 * AJAX handler: apply an AI-suggested field to the product.
	 */
	public function ajax_apply_field(): void {
		check_ajax_referer( 'shopwalk_improve_nonce', 'nonce' );

		$product_id = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
		$field = isset( $_POST['field'] ) ? sanitize_text_field( $_POST['field'] ) : '';
		$value = isset( $_POST['value'] ) ? sanitize_textarea_post( $_POST['value'] ) : '';

		if ( ! $product_id || ! in_array( $field, array( 'title', 'short_description', 'description' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid parameters.' ) );
		}

		if ( ! current_user_can( 'edit_product', $product_id ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			wp_send_json_error( array( 'message' => 'Product not found.' ) );
		}

		// Update the product field.
		$update_data = array( 'ID' => $product_id );
		if ( 'title' === $field ) {
			$update_data['post_title'] = $value;
		} elseif ( 'short_description' === $field ) {
			update_post_meta( $product_id, '_wc_short_description', $value );
		} elseif ( 'description' === $field ) {
			$update_data['post_content'] = $value;
		}

		if ( count( $update_data ) > 1 ) {
			wp_update_post( $update_data );
		}

		wp_send_json_success( array( 'message' => 'Field applied.' ) );
	}
}
