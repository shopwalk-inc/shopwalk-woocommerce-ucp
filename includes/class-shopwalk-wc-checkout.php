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

        // Update checkout session (add buyer info, fulfillment, payment)
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

    /**
     * Create a new checkout session from line items.
     */
    public function create_session(WP_REST_Request $request): WP_REST_Response {
        $body = $request->get_json_params();
        $line_items = $body['line_items'] ?? [];

        if (empty($line_items)) {
            return new WP_REST_Response(['error' => 'line_items required'], 400);
        }

        // Create a pending WC order
        $order = wc_create_order(['status' => 'pending']);
        if (is_wp_error($order)) {
            return new WP_REST_Response(['error' => $order->get_error_message()], 500);
        }

        // Add line items
        $messages = [];
        foreach ($line_items as $li) {
            $product_id = isset($li['item']['id']) ? absint($li['item']['id']) : null;
            $variant_id = isset($li['item']['variant_id']) ? absint($li['item']['variant_id']) : null;
            $quantity   = isset($li['quantity']) ? max(1, absint($li['quantity'])) : 1;

            // If variant_id provided, use variation product
            if ($variant_id) {
                $product = wc_get_product($variant_id);
                // Verify this variation belongs to the parent product
                if ($product && $product->get_parent_id() != $product_id) {
                    $product = null; // Mismatch — reject
                }
            } else {
                $product = wc_get_product($product_id);
            }

            if (!$product) {
                $messages[] = [
                    'type'     => 'error',
                    'code'     => 'PRODUCT_NOT_FOUND',
                    'content'  => "Product {$product_id} not found.",
                    'severity' => 'error',
                ];
                continue;
            }

            if (!$product->is_in_stock()) {
                $messages[] = [
                    'type'     => 'warning',
                    'code'     => 'OUT_OF_STOCK',
                    'content'  => "{$product->get_name()} is out of stock.",
                    'severity' => 'warning',
                ];
                continue;
            }

            $order->add_product($product, $quantity);
        }

        $order->calculate_totals();
        $order->save();

        // Store session ID as order meta
        $session_id = 'sw_' . $order->get_id();
        $order->update_meta_data('_shopwalk_session_id', $session_id);
        $order->update_meta_data('_shopwalk_status', 'open');
        $order->save();

        return new WP_REST_Response($this->format_session($order, $messages), 201);
    }

    /**
     * Get checkout session state.
     */
    public function get_session(WP_REST_Request $request): WP_REST_Response {
        $order = $this->find_order_by_session_id($request->get_param('id'));
        if (!$order) {
            return new WP_REST_Response(['error' => 'Session not found'], 404);
        }

        return new WP_REST_Response($this->format_session($order), 200);
    }

    /**
     * Update session — add buyer info, shipping address, payment, fulfillment selection.
     */
    public function update_session(WP_REST_Request $request): WP_REST_Response {
        $order = $this->find_order_by_session_id($request->get_param('id'));
        if (!$order) {
            return new WP_REST_Response(['error' => 'Session not found'], 404);
        }

        $body = $request->get_json_params();

        // Update buyer info
        if (isset($body['buyer'])) {
            $buyer = $body['buyer'];
            if (isset($buyer['email'])) {
                $order->set_billing_email($buyer['email']);
            }
            if (isset($buyer['first_name'])) {
                $order->set_billing_first_name($buyer['first_name']);
                $order->set_shipping_first_name($buyer['first_name']);
            }
            if (isset($buyer['last_name'])) {
                $order->set_billing_last_name($buyer['last_name']);
                $order->set_shipping_last_name($buyer['last_name']);
            }
        }

        // Update fulfillment / shipping address
        if (isset($body['fulfillment']['destinations'][0])) {
            $dest = $body['fulfillment']['destinations'][0];
            $order->set_shipping_first_name($dest['first_name'] ?? '');
            $order->set_shipping_last_name($dest['last_name'] ?? '');
            $order->set_shipping_address_1($dest['street_address'] ?? '');
            $order->set_shipping_city($dest['city'] ?? '');
            $order->set_shipping_state($dest['region'] ?? '');
            $order->set_shipping_postcode($dest['postal_code'] ?? '');
            $order->set_shipping_country($dest['country'] ?? '');

            // Copy to billing if not set
            if (!$order->get_billing_address_1()) {
                $order->set_billing_address_1($dest['street_address'] ?? '');
                $order->set_billing_city($dest['city'] ?? '');
                $order->set_billing_state($dest['region'] ?? '');
                $order->set_billing_postcode($dest['postal_code'] ?? '');
                $order->set_billing_country($dest['country'] ?? '');
            }
        }

        // Update selected shipping method
        if (isset($body['fulfillment']['groups'][0]['selected_option_id'])) {
            $shipping_id = $body['fulfillment']['groups'][0]['selected_option_id'];
            $order->update_meta_data('_shopwalk_selected_shipping', $shipping_id);
        }

        // Update payment selection
        if (isset($body['payment']['instruments'][0])) {
            $instrument = $body['payment']['instruments'][0];
            $order->set_payment_method($instrument['handler_id'] ?? 'stripe');
            $order->update_meta_data('_shopwalk_payment_token', $instrument['id'] ?? '');
        }

        $order->calculate_totals();
        $order->save();

        return new WP_REST_Response($this->format_session($order), 200);
    }

    /**
     * Complete the checkout — transition to a real WC order.
     */
    public function complete_session(WP_REST_Request $request): WP_REST_Response {
        $order = $this->find_order_by_session_id($request->get_param('id'));
        if (!$order) {
            return new WP_REST_Response(['error' => 'Session not found'], 404);
        }

        $shopwalk_status = $order->get_meta('_shopwalk_status');
        if ($shopwalk_status === 'completed') {
            return new WP_REST_Response(['error' => 'Session already completed'], 409);
        }

        // Validate required fields
        $messages = [];
        if (!$order->get_billing_email()) {
            $messages[] = ['type' => 'error', 'code' => 'MISSING_EMAIL', 'content' => 'Buyer email is required.', 'severity' => 'error'];
        }
        if (!$order->get_shipping_address_1()) {
            $messages[] = ['type' => 'error', 'code' => 'MISSING_ADDRESS', 'content' => 'Shipping address is required.', 'severity' => 'error'];
        }

        if (!empty($messages)) {
            return new WP_REST_Response([
                'error'    => 'Validation failed',
                'messages' => $messages,
            ], 422);
        }

        // Process payment
        $body = $request->get_json_params();
        if (isset($body['payment_mandate'])) {
            $order->update_meta_data('_shopwalk_payment_mandate', wp_json_encode($body['payment_mandate']));
        }

        $payment_mandate = $body['payment_mandate'] ?? [];
        $handler_id      = $payment_mandate['handler_id'] ?? '';
        $payment_token   = $payment_mandate['token'] ?? '';

        if ($handler_id === 'stripe' && !empty($payment_token) && class_exists('WC_Stripe_Helper')) {
            // Process via Stripe gateway
            $result = $this->charge_stripe($order, $payment_token);
            if (is_wp_error($result)) {
                return new WP_REST_Response([
                    'error' => 'Payment failed: ' . $result->get_error_message(),
                    'code'  => 'PAYMENT_FAILED',
                ], 402);
            }
        } elseif ($handler_id === 'cod' || empty($handler_id)) {
            // Cash on delivery or no payment — accept as-is
            $order->set_payment_method('cod');
            $order->set_payment_method_title('Pay on Delivery');
        } else {
            // Unknown handler — still accept but flag it
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
            return new WP_REST_Response(['error' => 'Session not found'], 404);
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
            return new WP_REST_Response(['error' => 'Session not found'], 404);
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

    // --- Helpers ---

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
     * Charge a Stripe payment method against the order total.
     *
     * @param WC_Order $order
     * @param string   $payment_method_id  Stripe PaymentMethod ID (pm_xxx)
     * @return true|WP_Error
     */
    private function charge_stripe(WC_Order $order, string $payment_method_id): true|WP_Error {
        // Use Stripe PHP SDK if WooCommerce Stripe plugin is active
        if (!class_exists('WC_Stripe_API')) {
            // Fallback: just set payment method and proceed (gateway will handle on order creation)
            $order->set_payment_method('stripe');
            $order->update_meta_data('_stripe_source_id', $payment_method_id);
            $order->update_meta_data('_payment_method_id', $payment_method_id);
            return true;
        }

        try {
            $amount   = (int) round($order->get_total() * 100); // cents
            $currency = strtolower($order->get_currency());

            $intent = WC_Stripe_API::request([
                'amount'              => $amount,
                'currency'            => $currency,
                'payment_method'      => $payment_method_id,
                'confirmation_method' => 'automatic',
                'confirm'             => 'true',
                'description'        => 'Shopwalk order #' . $order->get_id(),
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
