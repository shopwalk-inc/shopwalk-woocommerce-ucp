<?php
/**
 * Checkout Sessions — creates and manages WooCommerce orders via Shopwalk integration.
 *
 * @package ShopwalkAI
 * @license GPL-2.0-or-later
 * @copyright Copyright (c) 2024-2026 Shopwalk, Inc.
 */

defined('ABSPATH') || exit;

class Shopwalk_WC_Checkout {

    public function register_routes(string $namespace): void {
        // Create checkout session
        register_rest_route($namespace, '/checkout-sessions', [
            'methods'             => 'POST',
            'callback'            => [$this, 'create_session'],
            'permission_callback' => [Shopwalk_WC_Auth::class, 'check_permission'],
        ]);

        // Get checkout session
        register_rest_route($namespace, '/checkout-sessions/(?P<id>[a-zA-Z0-9_-]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_session'],
            'permission_callback' => [Shopwalk_WC_Auth::class, 'check_permission'],
        ]);

        // Update checkout session (add buyer info, fulfillment, payment, promotions)
        register_rest_route($namespace, '/checkout-sessions/(?P<id>[a-zA-Z0-9_-]+)', [
            'methods'             => 'PUT',
            'callback'            => [$this, 'update_session'],
            'permission_callback' => [Shopwalk_WC_Auth::class, 'check_permission'],
        ]);

        // Complete checkout (place the order)
        register_rest_route($namespace, '/checkout-sessions/(?P<id>[a-zA-Z0-9_-]+)/complete', [
            'methods'             => 'POST',
            'callback'            => [$this, 'complete_session'],
            'permission_callback' => [Shopwalk_WC_Auth::class, 'check_permission'],
        ]);

        // Cancel checkout
        register_rest_route($namespace, '/checkout-sessions/(?P<id>[a-zA-Z0-9_-]+)/cancel', [
            'methods'             => 'POST',
            'callback'            => [$this, 'cancel_session'],
            'permission_callback' => [Shopwalk_WC_Auth::class, 'check_permission'],
        ]);

        // Get available shipping methods for a session
        register_rest_route($namespace, '/checkout-sessions/(?P<id>[a-zA-Z0-9_-]+)/shipping-options', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_shipping_options'],
            'permission_callback' => [Shopwalk_WC_Auth::class, 'check_permission'],
        ]);
    }

    // -------------------------------------------------------------------------
    // UCP helpers
    // -------------------------------------------------------------------------

    /**
     * Build a UCP-standardized error response.
     */
    private function ucp_error(string $code, string $message, int $http_status = 400): WP_REST_Response {
        return new WP_REST_Response([
            'error' => [
                'code'    => $code,
                'message' => $message,
            ],
        ], $http_status);
    }

    /**
     * Check whether a session has expired (> SHOPWALK_SESSION_TTL seconds old).
     */
    private function is_session_expired(WC_Order $order): bool {
        $created = (int) $order->get_meta('_shopwalk_session_created');
        if (!$created) {
            return false; // Legacy sessions without timestamp — allow through
        }
        return (time() - $created) > SHOPWALK_SESSION_TTL;
    }

    // -------------------------------------------------------------------------
    // Guest Checkout Assurance helpers
    // -------------------------------------------------------------------------

    /**
     * Temporarily force guest checkout on for the duration of a Shopwalk session.
     * Hooked during create_session and complete_session.
     */
    private function force_guest_checkout(): void {
        add_filter('woocommerce_enable_guest_checkout',  '__return_true', 999);
        add_filter('pre_option_woocommerce_enable_guest_checkout', function() { return 'yes'; }, 999);
    }

    /**
     * Ensure the order has a valid customer / email for checkout.
     * If the store requires registration but we have no email, generate a bot email.
     */
    private function ensure_guest_customer(WC_Order $order): void {
        if ($order->get_billing_email()) {
            return; // Already set — nothing to do
        }

        // Generate a deterministic bot email so the order still passes WC validation
        $bot_email = 'shopwalk-bot+order-' . $order->get_id() . '@shopwalk.com';
        $order->set_billing_email($bot_email);
        $order->add_order_note('AI-initiated order. Bot email assigned for guest checkout compliance.');
    }

    // -------------------------------------------------------------------------
    // Endpoint handlers
    // -------------------------------------------------------------------------

    /**
     * Create a new checkout session from line items.
     */
    public function create_session(WP_REST_Request $request): WP_REST_Response {
        // Force guest checkout for Shopwalk sessions
        $this->force_guest_checkout();

        $body       = $request->get_json_params();
        $line_items = $body['line_items'] ?? [];

        if (empty($line_items)) {
            return $this->ucp_error('MISSING_LINE_ITEMS', 'line_items required', 400);
        }

        // Create a pending WC order
        $order = wc_create_order(['status' => 'pending']);
        if (is_wp_error($order)) {
            return $this->ucp_error('ORDER_CREATE_FAILED', $order->get_error_message(), 500);
        }

        // Add line items
        $messages = [];
        foreach ($line_items as $li) {
            $product_id = isset($li['item']['id']) ? absint($li['item']['id']) : null;
            $variant_id = isset($li['item']['variant_id']) ? absint($li['item']['variant_id']) : null;
            $attributes = isset($li['item']['attributes']) && is_array($li['item']['attributes']) ? $li['item']['attributes'] : [];
            $quantity   = isset($li['quantity']) ? max(1, absint($li['quantity'])) : 1;

            $product = null;

            if ($variant_id) {
                // Explicit variant ID provided
                $product = wc_get_product($variant_id);
                if ($product && $product_id && $product->get_parent_id() != $product_id) {
                    $product = null; // Parent mismatch — reject
                }
            } elseif (!empty($attributes) && $product_id) {
                // Attribute-based variant resolution
                $product = $this->find_variation_by_attributes($product_id, $attributes);
                if (!$product) {
                    // Fallback to parent product if no variation matched
                    $product = wc_get_product($product_id);
                }
            } else {
                $product = wc_get_product($product_id);
            }

            if (!$product) {
                $messages[] = [
                    'type'     => 'error',
                    'code'     => SHOPWALK_ERR_OUT_OF_STOCK,
                    'content'  => "Product {$product_id} not found.",
                    'severity' => 'error',
                ];
                continue;
            }

            if (!$product->is_in_stock()) {
                $messages[] = [
                    'type'     => 'warning',
                    'code'     => SHOPWALK_ERR_OUT_OF_STOCK,
                    'content'  => "{$product->get_name()} is out of stock.",
                    'severity' => 'warning',
                ];
                continue;
            }

            $order->add_product($product, $quantity);
        }

        $order->calculate_totals();
        $order->save();

        // Store session metadata
        $session_id = 'sw_' . $order->get_id();
        $order->update_meta_data('_shopwalk_session_id',      $session_id);
        $order->update_meta_data('_shopwalk_status',           'open');
        $order->update_meta_data('_shopwalk_session_created',  (string) time()); // for expiry
        $order->save();

        return new WP_REST_Response($this->format_session($order, $messages), 201);
    }

    /**
     * Get checkout session state.
     */
    public function get_session(WP_REST_Request $request): WP_REST_Response {
        $order = $this->find_order_by_session_id($request->get_param('id'));
        if (!$order) {
            return $this->ucp_error(SHOPWALK_ERR_SESSION_NOT_FOUND, 'Session not found', 404);
        }

        if ($this->is_session_expired($order)) {
            return $this->ucp_error(SHOPWALK_ERR_SESSION_EXPIRED, 'This checkout session has expired. Please start a new session.', 410);
        }

        return new WP_REST_Response($this->format_session($order), 200);
    }

    /**
     * Update session — add buyer info, shipping address, payment, fulfillment selection, promotions.
     */
    public function update_session(WP_REST_Request $request): WP_REST_Response {
        $order = $this->find_order_by_session_id($request->get_param('id'));
        if (!$order) {
            return $this->ucp_error(SHOPWALK_ERR_SESSION_NOT_FOUND, 'Session not found', 404);
        }

        if ($this->is_session_expired($order)) {
            return $this->ucp_error(SHOPWALK_ERR_SESSION_EXPIRED, 'This checkout session has expired. Please start a new session.', 410);
        }

        $body     = $request->get_json_params();
        $messages = [];

        // Update buyer info
        if (isset($body['buyer'])) {
            $buyer = $body['buyer'];
            if (isset($buyer['email'])) {
                $order->set_billing_email(sanitize_email($buyer['email']));
            }
            if (isset($buyer['first_name'])) {
                $order->set_billing_first_name(sanitize_text_field($buyer['first_name']));
                $order->set_shipping_first_name(sanitize_text_field($buyer['first_name']));
            }
            if (isset($buyer['last_name'])) {
                $order->set_billing_last_name(sanitize_text_field($buyer['last_name']));
                $order->set_shipping_last_name(sanitize_text_field($buyer['last_name']));
            }
        }

        // Update fulfillment / shipping address
        if (isset($body['fulfillment']['destinations'][0])) {
            $dest = $body['fulfillment']['destinations'][0];
            $order->set_shipping_first_name(sanitize_text_field($dest['first_name'] ?? ''));
            $order->set_shipping_last_name(sanitize_text_field($dest['last_name'] ?? ''));
            $order->set_shipping_address_1(sanitize_text_field($dest['street_address'] ?? ''));
            $order->set_shipping_city(sanitize_text_field($dest['city'] ?? ''));
            $order->set_shipping_state(sanitize_text_field($dest['region'] ?? ''));
            $order->set_shipping_postcode(sanitize_text_field($dest['postal_code'] ?? ''));
            $order->set_shipping_country(sanitize_text_field($dest['country'] ?? ''));

            // Copy to billing if not set
            if (!$order->get_billing_address_1()) {
                $order->set_billing_address_1(sanitize_text_field($dest['street_address'] ?? ''));
                $order->set_billing_city(sanitize_text_field($dest['city'] ?? ''));
                $order->set_billing_state(sanitize_text_field($dest['region'] ?? ''));
                $order->set_billing_postcode(sanitize_text_field($dest['postal_code'] ?? ''));
                $order->set_billing_country(sanitize_text_field($dest['country'] ?? ''));
            }
        }

        // Update selected shipping method
        if (isset($body['fulfillment']['groups'][0]['selected_option_id'])) {
            $shipping_id = sanitize_text_field($body['fulfillment']['groups'][0]['selected_option_id']);
            $order->update_meta_data('_shopwalk_selected_shipping', $shipping_id);
        }

        // Update payment selection
        if (isset($body['payment']['instruments'][0])) {
            $instrument = $body['payment']['instruments'][0];
            $order->set_payment_method(sanitize_key($instrument['handler_id'] ?? 'stripe'));
            $order->update_meta_data('_shopwalk_payment_token', sanitize_text_field($instrument['id'] ?? ''));
        }

        // -----------------------------------------------------------------------
        // Coupon / Promotion Code Support
        // -----------------------------------------------------------------------
        if (array_key_exists('promotions', $body)) {
            $promotions = $body['promotions'];

            // Remove all existing coupons from the order
            foreach ($order->get_coupon_codes() as $existing_code) {
                $order->remove_coupon($existing_code);
            }
            $order->update_meta_data('_shopwalk_coupon_codes', '');

            if (!empty($promotions) && is_array($promotions)) {
                $valid_codes = [];

                // 1. Validate each coupon before touching the order
                foreach ($promotions as $promo) {
                    $code = isset($promo['code']) ? sanitize_text_field(strtolower(trim($promo['code']))) : '';
                    if (empty($code)) {
                        continue;
                    }

                    $coupon = new WC_Coupon($code);
                    if (!$coupon->is_valid()) {
                        return $this->ucp_error(
                            SHOPWALK_ERR_INVALID_COUPON,
                            sprintf('Coupon %s is not valid.', $code),
                            400
                        );
                    }

                    $valid_codes[] = $code;
                }

                // 2. All coupons validated — apply to the order for totals display
                foreach ($valid_codes as $code) {
                    $order->apply_coupon($code);
                }

                // 3. Persist validated codes in session meta for complete_session()
                $order->update_meta_data('_shopwalk_coupon_codes', wp_json_encode($valid_codes));
            }
        }

        $order->calculate_totals();
        $order->save();

        return new WP_REST_Response($this->format_session($order, $messages), 200);
    }

    /**
     * Complete the checkout — transition to a real WC order.
     */
    public function complete_session(WP_REST_Request $request): WP_REST_Response {
        // Force guest checkout for Shopwalk sessions
        $this->force_guest_checkout();

        $order = $this->find_order_by_session_id($request->get_param('id'));
        if (!$order) {
            return $this->ucp_error(SHOPWALK_ERR_SESSION_NOT_FOUND, 'Session not found', 404);
        }

        if ($this->is_session_expired($order)) {
            return $this->ucp_error(SHOPWALK_ERR_SESSION_EXPIRED, 'This checkout session has expired. Please start a new session.', 410);
        }

        $shopwalk_status = $order->get_meta('_shopwalk_status');
        if ($shopwalk_status === 'completed') {
            return $this->ucp_error('SESSION_ALREADY_COMPLETED', 'Session already completed', 409);
        }

        // Ensure a guest email is present even if store requires accounts
        $this->ensure_guest_customer($order);

        // Re-apply any coupons stored in session meta (set during update_session).
        // This ensures coupons are active on the final order even if the order was
        // reloaded from the database without coupon data in memory.
        $stored_coupon_json = $order->get_meta('_shopwalk_coupon_codes');
        if (!empty($stored_coupon_json)) {
            $stored_codes = json_decode($stored_coupon_json, true);
            if (is_array($stored_codes)) {
                $applied = $order->get_coupon_codes();
                foreach ($stored_codes as $code) {
                    if (!in_array($code, $applied, true)) {
                        $order->apply_coupon($code);
                    }
                }
            }
        }

        // Validate required fields
        $messages = [];
        if (!$order->get_billing_email()) {
            $messages[] = [
                'type'     => 'error',
                'code'     => SHOPWALK_ERR_INVALID_ADDRESS,
                'content'  => 'Buyer email is required.',
                'severity' => 'error',
            ];
        }
        if (!$order->get_shipping_address_1()) {
            $messages[] = [
                'type'     => 'error',
                'code'     => SHOPWALK_ERR_INVALID_ADDRESS,
                'content'  => 'Shipping address is required.',
                'severity' => 'error',
            ];
        }

        if (!empty($messages)) {
            return new WP_REST_Response([
                'error' => [
                    'code'     => SHOPWALK_ERR_INVALID_ADDRESS,
                    'message'  => 'Validation failed',
                ],
                'messages' => $messages,
            ], 422);
        }

        // Process payment
        $body            = $request->get_json_params();
        $payment_mandate = $body['payment_mandate'] ?? [];
        $handler_id      = sanitize_key($payment_mandate['handler_id'] ?? '');
        $payment_token   = sanitize_text_field($payment_mandate['token'] ?? '');

        if (isset($body['payment_mandate'])) {
            $order->update_meta_data('_shopwalk_payment_mandate', wp_json_encode($body['payment_mandate']));
        }

        if ($handler_id === 'stripe' && !empty($payment_token) && str_starts_with($payment_token, 'pm_')) {
            // Use the bundled Stripe SDK to charge the PaymentMethod directly
            $stripe_result = $this->charge_stripe_payment(
                $payment_token,
                (float) $order->get_total(),
                $order->get_currency(),
                $order
            );

            if (!$stripe_result['success']) {
                $order->update_status('failed');
                $order->add_order_note('Shopwalk AI — Stripe charge failed: ' . ($stripe_result['error'] ?? 'Unknown error'));
                $order->save();
                return $this->ucp_error(
                    SHOPWALK_ERR_PAYMENT_FAILED,
                    'Payment failed: ' . ($stripe_result['error'] ?? 'Unknown error'),
                    402
                );
            }

            // Success — record the PaymentIntent and set gateway
            $order->set_payment_method('stripe');
            $order->set_payment_method_title('Stripe (Shopwalk AI)');
            $order->update_meta_data('_shopwalk_stripe_payment_intent', $stripe_result['payment_intent_id']);
            $order->add_order_note('Shopwalk AI — Stripe PaymentIntent: ' . $stripe_result['payment_intent_id']);

        } elseif ($handler_id === 'stripe' && !empty($payment_token) && class_exists('WC_Stripe_Helper')) {
            // Legacy path: WC Stripe gateway handles the charge
            $result = $this->charge_stripe($order, $payment_token);
            if (is_wp_error($result)) {
                return $this->ucp_error(SHOPWALK_ERR_PAYMENT_FAILED, 'Payment failed: ' . $result->get_error_message(), 402);
            }
        } elseif ($handler_id === 'cod' || empty($handler_id)) {
            $order->set_payment_method('cod');
            $order->set_payment_method_title('Pay on Delivery');
        } else {
            $order->set_payment_method($handler_id);
        }

        // Mark as processing
        $order->set_status('processing');
        $order->update_meta_data('_shopwalk_status', 'completed');
        $order->save();

        // Reduce stock
        wc_reduce_stock_levels($order->get_id());

        // Trigger standard WC order hooks
        do_action('woocommerce_checkout_order_processed', $order->get_id(), [], $order);

        return new WP_REST_Response([
            'id'            => 'sw_order_' . $order->get_id(),
            'checkout_id'   => 'sw_' . $order->get_id(),
            'permalink_url' => $order->get_view_order_url(),
            'line_items'    => $this->format_line_items($order),
            'totals'        => $this->format_totals($order),
            'fulfillment'   => [
                'expectations' => [
                    [
                        'type'         => 'shipping',
                        'estimated_at' => gmdate('c', strtotime('+5 days')),
                    ],
                ],
            ],
        ], 201);
    }

    /**
     * Cancel a checkout session.
     */
    public function cancel_session(WP_REST_Request $request): WP_REST_Response {
        $order = $this->find_order_by_session_id($request->get_param('id'));
        if (!$order) {
            return $this->ucp_error(SHOPWALK_ERR_SESSION_NOT_FOUND, 'Session not found', 404);
        }

        $order->set_status('cancelled');
        $order->update_meta_data('_shopwalk_status', 'cancelled');
        $order->save();

        return new WP_REST_Response(['status' => 'cancelled'], 200);
    }

    /**
     * Get available shipping options for the session's destination.
     */
    public function get_shipping_options(WP_REST_Request $request): WP_REST_Response {
        $order = $this->find_order_by_session_id($request->get_param('id'));
        if (!$order) {
            return $this->ucp_error(SHOPWALK_ERR_SESSION_NOT_FOUND, 'Session not found', 404);
        }

        if ($this->is_session_expired($order)) {
            return $this->ucp_error(SHOPWALK_ERR_SESSION_EXPIRED, 'This checkout session has expired.', 410);
        }

        // Build a package for WC shipping calculation
        $package = [
            'contents'    => [],
            'destination' => [
                'country'  => $order->get_shipping_country(),
                'state'    => $order->get_shipping_state(),
                'postcode' => $order->get_shipping_postcode(),
                'city'     => $order->get_shipping_city(),
            ],
        ];

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                $package['contents'][] = [
                    'data'     => $product,
                    'quantity' => $item->get_quantity(),
                ];
            }
        }

        // Calculate shipping
        $shipping_zone = WC_Shipping_Zones::get_zone_matching_package($package);
        $methods       = $shipping_zone->get_shipping_methods(true);

        $options = [];
        foreach ($methods as $method) {
            $rates = $method->get_rates_for_package($package);
            if (!is_array($rates)) continue;
            foreach ($rates as $rate) {
                $options[] = [
                    'id'     => $rate->get_id(),
                    'title'  => $rate->get_label(),
                    'totals' => [
                        ['type' => 'shipping', 'amount' => (int) round($rate->get_cost() * 100)],
                    ],
                ];
            }
        }

        return new WP_REST_Response([
            'groups' => [
                [
                    'id'      => 'default',
                    'options' => $options,
                ],
            ],
        ], 200);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Find a WC_Product_Variation matching the given attributes on a variable product.
     *
     * @param  int   $parent_id  The variable product ID.
     * @param  array $attributes Associative array of attribute name => value (e.g. ['color' => 'red']).
     * @return WC_Product_Variation|null  Matched variation, or null if not found.
     */
    private function find_variation_by_attributes(int $parent_id, array $attributes): ?WC_Product_Variation {
        $parent = wc_get_product($parent_id);
        if (!$parent || !$parent->is_type('variable')) {
            return null;
        }

        // Normalize attribute keys: lowercase, prefix with 'attribute_pa_' or 'attribute_' as needed
        $normalized = [];
        foreach ($attributes as $key => $value) {
            $key = strtolower(sanitize_title($key));
            // WooCommerce stores taxonomy attributes as attribute_pa_{slug}, custom as attribute_{slug}
            if (!str_starts_with($key, 'attribute_')) {
                // Try taxonomy first
                $tax_key    = 'attribute_pa_' . $key;
                $custom_key = 'attribute_' . $key;
                $normalized[$tax_key]    = sanitize_title($value);
                $normalized[$custom_key] = sanitize_text_field($value);
            } else {
                $normalized[$key] = sanitize_text_field($value);
            }
        }

        // Get all variation IDs for this parent
        $variation_ids = $parent->get_children();
        foreach ($variation_ids as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation || !$variation instanceof WC_Product_Variation) {
                continue;
            }

            $variation_attrs = $variation->get_variation_attributes(); // returns ['attribute_pa_color' => 'red', ...]
            $match           = true;

            foreach ($attributes as $attr_key => $attr_value) {
                $slug_key   = 'attribute_pa_' . strtolower(sanitize_title($attr_key));
                $custom_key = 'attribute_' . strtolower(sanitize_title($attr_key));

                $variation_value = $variation_attrs[$slug_key] ?? $variation_attrs[$custom_key] ?? null;

                // Empty string means "any" in WooCommerce
                if ($variation_value === '') {
                    continue;
                }

                if ($variation_value === null || strtolower(sanitize_title($attr_value)) !== strtolower(sanitize_title($variation_value))) {
                    $match = false;
                    break;
                }
            }

            if ($match) {
                return $variation;
            }
        }

        return null;
    }

    private function find_order_by_session_id(string $session_id): ?WC_Order {
        // Session ID format: sw_{order_id}
        $order_id = (int) str_replace('sw_', '', $session_id);
        if ($order_id <= 0) {
            return null;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return null;
        }

        // Verify it's a Shopwalk session
        if ($order->get_meta('_shopwalk_session_id') !== $session_id) {
            return null;
        }

        return $order;
    }

    private function format_session(WC_Order $order, array $extra_messages = []): array {
        $session_id      = $order->get_meta('_shopwalk_session_id') ?: 'sw_' . $order->get_id();
        $shopwalk_status = $order->get_meta('_shopwalk_status') ?: 'open';

        $session = [
            'id'         => $session_id,
            'status'     => $shopwalk_status,
            'currency'   => $order->get_currency(),
            'line_items' => $this->format_line_items($order),
            'totals'     => $this->format_totals($order),
        ];

        // Applied promotions (coupons)
        $coupon_codes = $order->get_coupon_codes();
        if (!empty($coupon_codes)) {
            $session['promotions'] = array_map(fn($code) => ['code' => $code], $coupon_codes);
        }

        // Buyer
        if ($order->get_billing_email()) {
            $session['buyer'] = [
                'email'      => $order->get_billing_email(),
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
            ];
        }

        // Fulfillment
        if ($order->get_shipping_address_1()) {
            $session['fulfillment'] = [
                'id'   => 'shipping',
                'type' => 'shipping',
                'destinations' => [
                    [
                        'id'             => 'primary',
                        'first_name'     => $order->get_shipping_first_name(),
                        'last_name'      => $order->get_shipping_last_name(),
                        'street_address' => $order->get_shipping_address_1(),
                        'city'           => $order->get_shipping_city(),
                        'region'         => $order->get_shipping_state(),
                        'postal_code'    => $order->get_shipping_postcode(),
                        'country'        => $order->get_shipping_country(),
                    ],
                ],
            ];
        }

        // Links
        $session['links'] = [
            ['type' => 'terms_of_service', 'url' => get_privacy_policy_url() ?: home_url('/terms')],
        ];

        if (!empty($extra_messages)) {
            $session['messages'] = $extra_messages;
        }

        return $session;
    }

    private function format_line_items(WC_Order $order): array {
        $items = [];
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            $items[] = [
                'id'       => (string) $item_id,
                'item'     => [
                    'id'         => (string) ($product ? $product->get_parent_id() ?: $product->get_id() : 0),
                    'variant_id' => ($product && $product->get_type() === 'variation') ? (string) $product->get_id() : null,
                    'attributes' => ($product && $product->get_type() === 'variation')
                        ? array_map('sanitize_text_field', $product->get_variation_attributes())
                        : null,
                    'title'      => $item->get_name(),
                    'price'      => (int) round($item->get_subtotal() / max($item->get_quantity(), 1) * 100),
                ],
                'quantity' => $item->get_quantity(),
                'totals'   => [
                    ['type' => 'subtotal', 'amount' => (int) round($item->get_subtotal() * 100)],
                    ['type' => 'tax',      'amount' => (int) round($item->get_subtotal_tax() * 100)],
                ],
            ];
        }
        return $items;
    }

    private function format_totals(WC_Order $order): array {
        return [
            ['type' => 'subtotal', 'amount' => (int) round((float) $order->get_subtotal() * 100)],
            ['type' => 'tax',      'amount' => (int) round((float) $order->get_total_tax() * 100)],
            ['type' => 'shipping', 'amount' => (int) round((float) $order->get_shipping_total() * 100)],
            ['type' => 'discount', 'amount' => (int) round((float) $order->get_discount_total() * 100)],
            ['type' => 'total',    'amount' => (int) round((float) $order->get_total() * 100)],
        ];
    }

    /**
     * Charge a Stripe PaymentMethod (pm_xxx) via the Stripe PHP SDK.
     *
     * Key resolution order:
     *  1. WC Stripe gateway settings (woocommerce_stripe_settings, respects testmode)
     *  2. shopwalk_wc_stripe_secret_key plugin option (manual override)
     *
     * @param  string    $token    Stripe PaymentMethod ID (pm_xxx)
     * @param  float     $amount   Order total in store currency
     * @param  string    $currency ISO 4217 currency code
     * @param  WC_Order  $order    WC order for metadata
     * @return array{success: bool, payment_intent_id?: string, error?: string}
     */
    private function charge_stripe_payment(string $token, float $amount, string $currency, WC_Order $order): array {
        // -- 1. Resolve secret key ------------------------------------------------
        $secret_key = '';

        // Try WC Stripe gateway settings first
        $wc_stripe_settings = get_option('woocommerce_stripe_settings', []);
        if (!empty($wc_stripe_settings)) {
            $testmode = ($wc_stripe_settings['testmode'] ?? 'no') === 'yes';
            if ($testmode) {
                $secret_key = $wc_stripe_settings['test_secret_key'] ?? '';
            } else {
                $secret_key = $wc_stripe_settings['secret_key'] ?? '';
            }
        }

        // Fallback to plugin-specific option
        if (empty($secret_key)) {
            $secret_key = get_option('shopwalk_wc_stripe_secret_key', '');
        }

        if (empty($secret_key)) {
            return ['success' => false, 'error' => 'Stripe secret key is not configured.'];
        }

        // -- 2. Load Stripe SDK ---------------------------------------------------
        $autoload = SHOPWALK_AI_PLUGIN_DIR . 'vendor/autoload.php';
        if (!file_exists($autoload)) {
            return ['success' => false, 'error' => 'Stripe SDK vendor/autoload.php not found.'];
        }
        require_once $autoload;

        // -- 3. Create PaymentIntent ---------------------------------------------
        try {
            \Stripe\Stripe::setApiKey($secret_key);

            $intent = \Stripe\PaymentIntent::create([
                'amount'                 => (int) round($amount * 100),
                'currency'               => strtolower($currency),
                'payment_method'         => $token,
                'confirm'                => true,
                'automatic_payment_methods' => [
                    'enabled'         => true,
                    'allow_redirects' => 'never',
                ],
                'metadata' => [
                    'order_id' => $order->get_id(),
                    'source'   => 'shopwalk_ai',
                ],
            ]);

            if ($intent->status === 'succeeded') {
                return ['success' => true, 'payment_intent_id' => $intent->id];
            }

            return [
                'success' => false,
                'error'   => 'PaymentIntent status: ' . $intent->status,
            ];

        } catch (\Stripe\Exception\CardException $e) {
            return ['success' => false, 'error' => 'Card declined: ' . $e->getMessage()];
        } catch (\Stripe\Exception\ApiErrorException $e) {
            return ['success' => false, 'error' => 'Stripe API error: ' . $e->getMessage()];
        }
    }

    /**
     * Charge a Stripe payment method against the order total.
     *
     * @param WC_Order $order
     * @param string   $payment_method_id  Stripe PaymentMethod ID (pm_xxx)
     * @return true|WP_Error
     */
    private function charge_stripe(WC_Order $order, string $payment_method_id): true|WP_Error {
        if (!class_exists('WC_Stripe_API')) {
            // Fallback: set meta and let gateway handle on order creation
            $order->set_payment_method('stripe');
            $order->update_meta_data('_stripe_source_id', $payment_method_id);
            $order->update_meta_data('_payment_method_id', $payment_method_id);
            return true;
        }

        try {
            $amount   = (int) round($order->get_total() * 100);
            $currency = strtolower($order->get_currency());

            $intent = WC_Stripe_API::request([
                'amount'              => $amount,
                'currency'            => $currency,
                'payment_method'      => $payment_method_id,
                'confirmation_method' => 'automatic',
                'confirm'             => 'true',
                'description'         => 'Shopwalk order #' . $order->get_id(),
            ], 'payment_intents');

            if (!empty($intent->error)) {
                return new WP_Error('stripe_error', $intent->error->message ?? 'Stripe error');
            }

            if ($intent->status === 'succeeded') {
                $order->set_payment_method('stripe');
                $order->set_payment_method_title('Stripe (Shopwalk)');
                $order->update_meta_data('_stripe_intent_id', $intent->id);
                $order->add_order_note('Payment processed via Shopwalk — Stripe PaymentIntent: ' . $intent->id);
                return true;
            }

            if ($intent->status === 'requires_action') {
                return new WP_Error('stripe_requires_action', '3D Secure authentication required. Complete payment on merchant site.');
            }

            return new WP_Error('stripe_failed', 'Payment intent status: ' . $intent->status);

        } catch (Exception $e) {
            return new WP_Error('stripe_exception', $e->getMessage());
        }
    }
}
