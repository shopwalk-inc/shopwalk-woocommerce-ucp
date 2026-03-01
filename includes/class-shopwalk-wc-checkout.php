<?php
/**
 * Checkout Sessions — creates and manages WooCommerce orders via UCP.
 *
 * Implements the UCP 2026-01-23 checkout session protocol:
 * https://ucp.dev/latest/specification/checkout-rest/
 *
 * Session lifecycle:
 *   incomplete → ready_for_complete → completed
 *   incomplete → canceled
 *   Any        → requires_escalation (unrecoverable error)
 *
 * @package ShopwalkAI
 * @license GPL-2.0-or-later
 * @copyright Copyright (c) 2024-2026 Shopwalk, Inc.
 */

defined('ABSPATH') || exit;

class Shopwalk_WC_Checkout {

    public function register_routes( string $namespace ): void {
        // Create checkout session
        register_rest_route( $namespace, '/checkout-sessions', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'create_session' ],
            'permission_callback' => [ Shopwalk_WC_Auth::class, 'check_permission' ],
        ] );

        // Get checkout session
        register_rest_route( $namespace, '/checkout-sessions/(?P<id>[a-zA-Z0-9_-]+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_session' ],
            'permission_callback' => [ Shopwalk_WC_Auth::class, 'check_permission' ],
        ] );

        // Update checkout session (progressive PUT — buyer, address, shipping selection)
        register_rest_route( $namespace, '/checkout-sessions/(?P<id>[a-zA-Z0-9_-]+)', [
            'methods'             => 'PUT',
            'callback'            => [ $this, 'update_session' ],
            'permission_callback' => [ Shopwalk_WC_Auth::class, 'check_permission' ],
        ] );

        // Complete checkout — charge card and create order
        register_rest_route( $namespace, '/checkout-sessions/(?P<id>[a-zA-Z0-9_-]+)/complete', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'complete_session' ],
            'permission_callback' => [ Shopwalk_WC_Auth::class, 'check_permission' ],
        ] );

        // Cancel checkout session
        register_rest_route( $namespace, '/checkout-sessions/(?P<id>[a-zA-Z0-9_-]+)/cancel', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'cancel_session' ],
            'permission_callback' => [ Shopwalk_WC_Auth::class, 'check_permission' ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a UCP error response.
     * UCP error shape: { messages: [{ type, code, path?, severity }] }
     */
    private function ucp_error( string $code, string $message, int $http_status = 400, string $path = '' ): WP_REST_Response {
        $msg = [
            'type'     => 'error',
            'code'     => $code,
            'severity' => 'error',
            'content'  => $message,
        ];
        if ( $path ) {
            $msg['path'] = $path;
        }
        return new WP_REST_Response(
            [ 'messages' => [ $msg ] ],
            $http_status
        );
    }

    /**
     * Check whether a session has expired.
     */
    private function is_session_expired( WC_Order $order ): bool {
        $created = (int) $order->get_meta( '_shopwalk_session_created' );
        if ( ! $created ) {
            return false;
        }
        return ( time() - $created ) > SHOPWALK_SESSION_TTL;
    }

    /**
     * Compute the UCP status for a session order.
     *
     *  incomplete         — missing buyer, address, or shipping selection
     *  ready_for_complete — all required fields set, awaiting /complete
     *  completed          — /complete succeeded
     *  canceled           — /cancel called
     *  requires_escalation — unrecoverable error
     */
    private function compute_status( WC_Order $order ): string {
        $stored = $order->get_meta( '_shopwalk_status' );

        // Terminal states — never recompute
        if ( in_array( $stored, [ 'completed', 'canceled', 'requires_escalation' ], true ) ) {
            return $stored;
        }

        // Check required fields
        if ( ! $order->get_billing_email() ) {
            return 'incomplete';
        }
        if ( ! $order->get_shipping_address_1() ) {
            return 'incomplete';
        }
        if ( ! $order->get_meta( '_shopwalk_selected_shipping' ) ) {
            return 'incomplete';
        }

        return 'ready_for_complete';
    }

    /**
     * Force guest checkout for this request lifecycle.
     */
    private function force_guest_checkout(): void {
        add_filter( 'woocommerce_enable_guest_checkout', '__return_true', 999 );
        add_filter( 'pre_option_woocommerce_enable_guest_checkout', static fn() => 'yes', 999 );
    }

    // -------------------------------------------------------------------------
    // Endpoint handlers
    // -------------------------------------------------------------------------

    /**
     * POST /checkout-sessions
     * Create a new UCP checkout session from line items.
     */
    public function create_session( WP_REST_Request $request ): WP_REST_Response {
        $this->force_guest_checkout();

        $body       = $request->get_json_params() ?? [];
        $line_items = $body['line_items'] ?? [];

        if ( empty( $line_items ) ) {
            return $this->ucp_error( 'missing', 'line_items is required', 400, '$.line_items' );
        }

        // Create pending WC order
        $order = wc_create_order( [ 'status' => 'pending' ] );
        if ( is_wp_error( $order ) ) {
            return $this->ucp_error( 'order_create_failed', $order->get_error_message(), 500 );
        }

        $messages = [];

        foreach ( $line_items as $li ) {
            $product_id = isset( $li['item']['id'] ) ? absint( $li['item']['id'] ) : null;
            $variant_id = isset( $li['item']['variant_id'] ) ? absint( $li['item']['variant_id'] ) : null;
            $attributes = isset( $li['item']['attributes'] ) && is_array( $li['item']['attributes'] )
                ? $li['item']['attributes'] : [];
            $quantity   = isset( $li['quantity'] ) ? max( 1, absint( $li['quantity'] ) ) : 1;

            $product = null;
            if ( $variant_id ) {
                $product = wc_get_product( $variant_id );
                if ( $product && $product_id && $product->get_parent_id() !== $product_id ) {
                    $product = null;
                }
            } elseif ( ! empty( $attributes ) && $product_id ) {
                $product = $this->find_variation_by_attributes( $product_id, $attributes )
                    ?? wc_get_product( $product_id );
            } else {
                $product = wc_get_product( $product_id );
            }

            if ( ! $product ) {
                $messages[] = [
                    'type'     => 'error',
                    'code'     => SHOPWALK_ERR_OUT_OF_STOCK,
                    'path'     => '$.line_items[?(@.item.id==' . (int) $product_id . ')]',
                    'severity' => 'error',
                    'content'  => "Product {$product_id} not found.",
                ];
                continue;
            }

            if ( ! $product->is_in_stock() ) {
                $messages[] = [
                    'type'     => 'warning',
                    'code'     => SHOPWALK_ERR_OUT_OF_STOCK,
                    'path'     => '$.line_items[?(@.item.id==' . (int) $product_id . ')]',
                    'severity' => 'recoverable',
                    'content'  => $product->get_name() . ' is out of stock.',
                ];
                continue;
            }

            $order->add_product( $product, $quantity );
        }

        $order->calculate_totals();

        // Store UCP session metadata
        $session_id = 'chk_' . $order->get_id();
        $order->update_meta_data( '_shopwalk_session_id',     $session_id );
        $order->update_meta_data( '_shopwalk_status',          'incomplete' );
        $order->update_meta_data( '_shopwalk_session_created', (string) time() );
        $order->save();

        return new WP_REST_Response( $this->format_session( $order, $messages ), 201 );
    }

    /**
     * GET /checkout-sessions/{id}
     */
    public function get_session( WP_REST_Request $request ): WP_REST_Response {
        $order = $this->find_order_by_session_id( $request->get_param( 'id' ) );
        if ( ! $order ) {
            return $this->ucp_error( SHOPWALK_ERR_SESSION_NOT_FOUND, 'Session not found', 404 );
        }
        if ( $this->is_session_expired( $order ) ) {
            return $this->ucp_error( SHOPWALK_ERR_SESSION_EXPIRED, 'Session has expired. Start a new session.', 410 );
        }
        return new WP_REST_Response( $this->format_session( $order ), 200 );
    }

    /**
     * PUT /checkout-sessions/{id}
     *
     * Progressive update — caller must send complete desired state each time:
     *   Step 1: buyer info        → status stays incomplete
     *   Step 2: fulfillment addr  → returns shipping options in fulfillment.methods[].groups[].options
     *   Step 3: select shipping   → transitions to ready_for_complete if all fields present
     */
    public function update_session( WP_REST_Request $request ): WP_REST_Response {
        $order = $this->find_order_by_session_id( $request->get_param( 'id' ) );
        if ( ! $order ) {
            return $this->ucp_error( SHOPWALK_ERR_SESSION_NOT_FOUND, 'Session not found', 404 );
        }
        if ( $this->is_session_expired( $order ) ) {
            return $this->ucp_error( SHOPWALK_ERR_SESSION_EXPIRED, 'Session has expired. Start a new session.', 410 );
        }

        $body     = $request->get_json_params() ?? [];
        $messages = [];

        // ── Buyer info ──────────────────────────────────────────────────────
        if ( isset( $body['buyer'] ) ) {
            $buyer = $body['buyer'];
            if ( ! empty( $buyer['email'] ) ) {
                $order->set_billing_email( sanitize_email( $buyer['email'] ) );
            }
            if ( ! empty( $buyer['first_name'] ) ) {
                $order->set_billing_first_name( sanitize_text_field( $buyer['first_name'] ) );
                $order->set_shipping_first_name( sanitize_text_field( $buyer['first_name'] ) );
            }
            if ( ! empty( $buyer['last_name'] ) ) {
                $order->set_billing_last_name( sanitize_text_field( $buyer['last_name'] ) );
                $order->set_shipping_last_name( sanitize_text_field( $buyer['last_name'] ) );
            }
        }

        // ── Fulfillment / shipping address ──────────────────────────────────
        // UCP path: fulfillment.methods[0].destinations[0]
        $dest = $body['fulfillment']['methods'][0]['destinations'][0] ?? null;

        if ( $dest ) {
            // UCP field names: street_address, address_locality, address_region,
            //                  postal_code, address_country
            $order->set_shipping_address_1( sanitize_text_field( $dest['street_address'] ?? '' ) );
            $order->set_shipping_city( sanitize_text_field( $dest['address_locality'] ?? $dest['city'] ?? '' ) );
            $order->set_shipping_state( sanitize_text_field( $dest['address_region'] ?? $dest['region'] ?? '' ) );
            $order->set_shipping_postcode( sanitize_text_field( $dest['postal_code'] ?? '' ) );
            $order->set_shipping_country( sanitize_text_field( $dest['address_country'] ?? $dest['country'] ?? '' ) );

            // Copy to billing if not already set
            if ( ! $order->get_billing_address_1() ) {
                $order->set_billing_address_1( $order->get_shipping_address_1() );
                $order->set_billing_city( $order->get_shipping_city() );
                $order->set_billing_state( $order->get_shipping_state() );
                $order->set_billing_postcode( $order->get_shipping_postcode() );
                $order->set_billing_country( $order->get_shipping_country() );
            }
        }

        // ── Shipping option selection ────────────────────────────────────────
        // UCP path: fulfillment.methods[0].groups[0].selected_option_id
        $selected_option = $body['fulfillment']['methods'][0]['groups'][0]['selected_option_id'] ?? null;
        if ( $selected_option ) {
            $order->update_meta_data( '_shopwalk_selected_shipping', sanitize_text_field( $selected_option ) );
        }

        // ── Promotions / coupons ─────────────────────────────────────────────
        if ( array_key_exists( 'promotions', $body ) ) {
            foreach ( $order->get_coupon_codes() as $code ) {
                $order->remove_coupon( $code );
            }
            $order->update_meta_data( '_shopwalk_coupon_codes', '' );

            if ( ! empty( $body['promotions'] ) && is_array( $body['promotions'] ) ) {
                $valid_codes = [];
                foreach ( $body['promotions'] as $promo ) {
                    $code = strtolower( trim( sanitize_text_field( $promo['code'] ?? '' ) ) );
                    if ( empty( $code ) ) {
                        continue;
                    }
                    $coupon = new WC_Coupon( $code );
                    if ( ! $coupon->is_valid() ) {
                        return $this->ucp_error(
                            SHOPWALK_ERR_INVALID_COUPON,
                            "Coupon {$code} is not valid.",
                            400,
                            '$.promotions'
                        );
                    }
                    $valid_codes[] = $code;
                }
                foreach ( $valid_codes as $code ) {
                    $order->apply_coupon( $code );
                }
                $order->update_meta_data( '_shopwalk_coupon_codes', wp_json_encode( $valid_codes ) );
            }
        }

        $order->calculate_totals();
        $order->save();

        return new WP_REST_Response( $this->format_session( $order, $messages ), 200 );
    }

    /**
     * POST /checkout-sessions/{id}/complete
     *
     * Charge the card via Shopwalk payment gateway and create the WC order.
     *
     * Expected body:
     * {
     *   "payment": {
     *     "instruments": [{
     *       "handler_id": "shopwalk_pay",
     *       "credential": { "type": "PAYMENT_GATEWAY", "token": "pm_xxx" }
     *     }]
     *   }
     * }
     */
    public function complete_session( WP_REST_Request $request ): WP_REST_Response {
        $this->force_guest_checkout();

        $order = $this->find_order_by_session_id( $request->get_param( 'id' ) );
        if ( ! $order ) {
            return $this->ucp_error( SHOPWALK_ERR_SESSION_NOT_FOUND, 'Session not found', 404 );
        }
        if ( $this->is_session_expired( $order ) ) {
            return $this->ucp_error( SHOPWALK_ERR_SESSION_EXPIRED, 'Session has expired. Start a new session.', 410 );
        }
        if ( $order->get_meta( '_shopwalk_status' ) === 'completed' ) {
            return $this->ucp_error( 'session_already_completed', 'Session already completed.', 409 );
        }

        // Ensure billing email for guest checkout
        if ( ! $order->get_billing_email() ) {
            $order->set_billing_email( 'shopwalk-bot+order-' . $order->get_id() . '@shopwalk.com' );
        }

        // Re-apply stored coupons
        $stored_json = $order->get_meta( '_shopwalk_coupon_codes' );
        if ( ! empty( $stored_json ) ) {
            $stored = json_decode( $stored_json, true );
            if ( is_array( $stored ) ) {
                $applied = $order->get_coupon_codes();
                foreach ( $stored as $code ) {
                    if ( ! in_array( $code, $applied, true ) ) {
                        $order->apply_coupon( $code );
                    }
                }
            }
        }

        // Validate required fields
        $validation_errors = [];
        if ( ! $order->get_billing_email() ) {
            $validation_errors[] = [
                'type' => 'error', 'code' => SHOPWALK_ERR_INVALID_ADDRESS,
                'path' => '$.buyer.email', 'severity' => 'error',
                'content' => 'Buyer email is required.',
            ];
        }
        if ( ! $order->get_shipping_address_1() ) {
            $validation_errors[] = [
                'type' => 'error', 'code' => SHOPWALK_ERR_INVALID_ADDRESS,
                'path' => '$.fulfillment.methods[0].destinations[0].street_address',
                'severity' => 'error', 'content' => 'Shipping address is required.',
            ];
        }
        if ( ! empty( $validation_errors ) ) {
            return new WP_REST_Response( [ 'messages' => $validation_errors ], 422 );
        }

        // ── Resolve payment credential ───────────────────────────────────────
        // UCP path: payment.instruments[0].credential.token
        $body        = $request->get_json_params() ?? [];
        $instruments = $body['payment']['instruments'] ?? [];
        $instrument  = $instruments[0] ?? [];
        $credential  = $instrument['credential'] ?? [];
        $cred_type   = $credential['type'] ?? '';
        $pm_token    = sanitize_text_field( $credential['token'] ?? '' );
        $handler_id  = sanitize_key( $instrument['handler_id'] ?? 'shopwalk_pay' );

        // ── Charge via Stripe ────────────────────────────────────────────────
        if ( $handler_id === 'shopwalk_pay' && $cred_type === 'PAYMENT_GATEWAY' && str_starts_with( $pm_token, 'pm_' ) ) {
            $stripe_result = $this->charge_stripe_payment(
                $pm_token,
                (float) $order->get_total(),
                $order->get_currency(),
                $order
            );

            if ( ! $stripe_result['success'] ) {
                $order->update_status( 'failed' );
                $order->add_order_note( 'Shopwalk AI — Stripe charge failed: ' . ( $stripe_result['error'] ?? 'Unknown error' ) );
                $order->update_meta_data( '_shopwalk_status', 'requires_escalation' );
                $order->save();
                return new WP_REST_Response( [
                    'status'   => 'requires_escalation',
                    'messages' => [ [
                        'type'     => 'error',
                        'code'     => SHOPWALK_ERR_PAYMENT_FAILED,
                        'severity' => 'error',
                        'content'  => 'Payment failed: ' . ( $stripe_result['error'] ?? 'Unknown error' ),
                    ] ],
                ], 402 );
            }

            $order->set_payment_method( 'shopwalk_pay' );
            $order->set_payment_method_title( 'Shopwalk Pay' );
            $order->update_meta_data( '_shopwalk_stripe_payment_intent', $stripe_result['payment_intent_id'] );
            $order->add_order_note( 'Shopwalk AI — Stripe PaymentIntent: ' . $stripe_result['payment_intent_id'] );

        } elseif ( $handler_id === 'cod' || ( empty( $pm_token ) && empty( $handler_id ) ) ) {
            $order->set_payment_method( 'cod' );
            $order->set_payment_method_title( 'Pay on Delivery' );
        } else {
            // Unknown handler — store for manual processing
            $order->set_payment_method( sanitize_key( $handler_id ) );
        }

        // ── Finalise order ───────────────────────────────────────────────────
        $order->set_status( 'processing' );
        $order->update_meta_data( '_shopwalk_status', 'completed' );
        $order->save();

        wc_reduce_stock_levels( $order->get_id() );
        do_action( 'woocommerce_checkout_order_processed', $order->get_id(), [], $order );

        // Response: UCP 2026-01-23 completed shape
        return new WP_REST_Response( [
            'status' => 'completed',
            'order'  => [
                'id'            => 'ord_' . $order->get_id(),
                'permalink_url' => $order->get_view_order_url(),
            ],
        ], 200 );
    }

    /**
     * POST /checkout-sessions/{id}/cancel
     */
    public function cancel_session( WP_REST_Request $request ): WP_REST_Response {
        $order = $this->find_order_by_session_id( $request->get_param( 'id' ) );
        if ( ! $order ) {
            return $this->ucp_error( SHOPWALK_ERR_SESSION_NOT_FOUND, 'Session not found', 404 );
        }

        $order->set_status( 'cancelled' );
        $order->update_meta_data( '_shopwalk_status', 'canceled' ); // UCP: single-l
        $order->save();

        return new WP_REST_Response( [ 'status' => 'canceled' ], 200 );
    }

    // -------------------------------------------------------------------------
    // Session formatter
    // -------------------------------------------------------------------------

    /**
     * Format a WC order as a UCP checkout session response.
     * Shipping options are embedded in fulfillment.methods[].groups[].options
     * when a shipping address is present.
     */
    private function format_session( WC_Order $order, array $extra_messages = [] ): array {
        $status = $this->compute_status( $order );

        // Sync computed status back to meta if it changed
        if ( $order->get_meta( '_shopwalk_status' ) !== $status &&
             ! in_array( $order->get_meta( '_shopwalk_status' ), [ 'completed', 'canceled', 'requires_escalation' ], true ) ) {
            $order->update_meta_data( '_shopwalk_status', $status );
            $order->save();
        }

        $session = [
            'id'         => $order->get_meta( '_shopwalk_session_id' ) ?: 'chk_' . $order->get_id(),
            'status'     => $status,
            'currency'   => $order->get_currency(),
            'line_items' => $this->format_line_items( $order ),
            'totals'     => $this->format_totals( $order ),
        ];

        // Messages — UCP format
        $messages = [];

        // Add "missing" messages for each incomplete required field
        if ( ! $order->get_billing_email() ) {
            $messages[] = [
                'type' => 'error', 'code' => 'missing',
                'path' => '$.buyer.email', 'severity' => 'recoverable',
            ];
        }
        if ( ! $order->get_shipping_address_1() ) {
            $messages[] = [
                'type' => 'error', 'code' => 'missing',
                'path' => '$.fulfillment.methods[0].destinations[0].street_address',
                'severity' => 'recoverable',
            ];
        }
        if ( $order->get_shipping_address_1() && ! $order->get_meta( '_shopwalk_selected_shipping' ) ) {
            $messages[] = [
                'type' => 'error', 'code' => 'missing',
                'path' => '$.fulfillment.methods[0].groups[0].selected_option_id',
                'severity' => 'recoverable',
            ];
        }

        $messages = array_merge( $messages, $extra_messages );
        if ( ! empty( $messages ) ) {
            $session['messages'] = $messages;
        }

        // Buyer
        if ( $order->get_billing_email() ) {
            $session['buyer'] = [
                'email'      => $order->get_billing_email(),
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
            ];
        }

        // Fulfillment — includes shipping options when address is set
        $session['fulfillment'] = $this->format_fulfillment( $order );

        // Applied promotions
        $coupon_codes = $order->get_coupon_codes();
        if ( ! empty( $coupon_codes ) ) {
            $session['promotions'] = array_map( static fn( $code ) => [ 'code' => $code ], $coupon_codes );
        }

        return $session;
    }

    /**
     * Build the fulfillment object.
     * When an address is present, shipping options are calculated and embedded
     * in fulfillment.methods[0].groups[0].options — no separate /shipping-options call needed.
     */
    private function format_fulfillment( WC_Order $order ): array {
        $dest_id   = 'dest_1';
        $group_id  = 'group_1';
        $method_id = 'shipping_1';

        $destination = null;
        if ( $order->get_shipping_address_1() ) {
            $destination = [
                'id'              => $dest_id,
                'street_address'  => $order->get_shipping_address_1(),
                'address_locality'=> $order->get_shipping_city(),
                'address_region'  => $order->get_shipping_state(),
                'postal_code'     => $order->get_shipping_postcode(),
                'address_country' => $order->get_shipping_country(),
            ];
        }

        $selected_option_id = $order->get_meta( '_shopwalk_selected_shipping' ) ?: null;
        $options            = [];

        if ( $destination ) {
            $options = $this->get_shipping_options_for_order( $order );
        }

        $group = [
            'id'                 => $group_id,
            'selected_option_id' => $selected_option_id,
            'options'            => $options,
        ];

        $method = [
            'id'                       => $method_id,
            'type'                     => 'shipping',
            'selected_destination_id'  => $destination ? $dest_id : null,
            'destinations'             => $destination ? [ $destination ] : [],
            'groups'                   => [ $group ],
        ];

        return [
            'methods' => [ $method ],
        ];
    }

    /**
     * Calculate WooCommerce shipping rates for the order's destination.
     * Returns UCP-formatted options array.
     */
    private function get_shipping_options_for_order( WC_Order $order ): array {
        $package = [
            'contents'    => [],
            'destination' => [
                'country'  => $order->get_shipping_country(),
                'state'    => $order->get_shipping_state(),
                'postcode' => $order->get_shipping_postcode(),
                'city'     => $order->get_shipping_city(),
            ],
        ];

        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( $product ) {
                $package['contents'][] = [
                    'data'     => $product,
                    'quantity' => $item->get_quantity(),
                ];
            }
        }

        $zone    = WC_Shipping_Zones::get_zone_matching_package( $package );
        $methods = $zone->get_shipping_methods( true );
        $options = [];

        foreach ( $methods as $method ) {
            $rates = $method->get_rates_for_package( $package );
            if ( ! is_array( $rates ) ) {
                continue;
            }
            foreach ( $rates as $rate ) {
                $options[] = [
                    'id'     => $rate->get_id(),
                    'title'  => $rate->get_label(),
                    'totals' => [
                        [ 'type' => 'shipping', 'amount' => (int) round( $rate->get_cost() * 100 ) ],
                    ],
                ];
            }
        }

        return $options;
    }

    private function format_line_items( WC_Order $order ): array {
        $items = [];
        foreach ( $order->get_items() as $item_id => $item ) {
            $product  = $item->get_product();
            $qty      = max( $item->get_quantity(), 1 );
            $items[]  = [
                'id'       => (string) $item_id,
                'item'     => [
                    'id'         => (string) ( $product ? ( $product->get_parent_id() ?: $product->get_id() ) : 0 ),
                    'variant_id' => ( $product && $product->get_type() === 'variation' ) ? (string) $product->get_id() : null,
                    'attributes' => ( $product && $product->get_type() === 'variation' )
                        ? array_map( 'sanitize_text_field', $product->get_variation_attributes() )
                        : null,
                    'title' => $item->get_name(),
                    'price' => (int) round( $item->get_subtotal() / $qty * 100 ),
                ],
                'quantity' => $item->get_quantity(),
                'totals'   => [
                    [ 'type' => 'subtotal', 'amount' => (int) round( $item->get_subtotal() * 100 ) ],
                    [ 'type' => 'tax',      'amount' => (int) round( $item->get_subtotal_tax() * 100 ) ],
                ],
            ];
        }
        return $items;
    }

    private function format_totals( WC_Order $order ): array {
        return [
            [ 'type' => 'subtotal', 'amount' => (int) round( (float) $order->get_subtotal() * 100 ) ],
            [ 'type' => 'tax',      'amount' => (int) round( (float) $order->get_total_tax() * 100 ) ],
            [ 'type' => 'shipping', 'amount' => (int) round( (float) $order->get_shipping_total() * 100 ) ],
            [ 'type' => 'discount', 'amount' => (int) round( (float) $order->get_discount_total() * 100 ) ],
            [ 'type' => 'total',    'amount' => (int) round( (float) $order->get_total() * 100 ) ],
        ];
    }

    // -------------------------------------------------------------------------
    // Payment
    // -------------------------------------------------------------------------

    /**
     * Charge a Stripe PaymentMethod (pm_xxx) via the Stripe PHP SDK.
     *
     * Key resolution order:
     *  1. WC Stripe gateway settings (respects testmode)
     *  2. shopwalk_wc_stripe_secret_key plugin option (manual override)
     *
     * @return array{success: bool, payment_intent_id?: string, error?: string}
     */
    private function charge_stripe_payment( string $token, float $amount, string $currency, WC_Order $order ): array {
        // Resolve secret key
        $secret_key = '';
        $wc_stripe  = get_option( 'woocommerce_stripe_settings', [] );
        if ( ! empty( $wc_stripe ) ) {
            $testmode   = ( $wc_stripe['testmode'] ?? 'no' ) === 'yes';
            $secret_key = $testmode
                ? ( $wc_stripe['test_secret_key'] ?? '' )
                : ( $wc_stripe['secret_key'] ?? '' );
        }
        if ( empty( $secret_key ) ) {
            $secret_key = get_option( 'shopwalk_wc_stripe_secret_key', '' );
        }
        if ( empty( $secret_key ) ) {
            // Stripe Connect not configured — trigger lazy onboarding
            return [ 'success' => false, 'error' => 'stripe_connect_required' ];
        }

        $autoload = SHOPWALK_AI_PLUGIN_DIR . 'vendor/autoload.php';
        if ( ! file_exists( $autoload ) ) {
            return [ 'success' => false, 'error' => 'Stripe SDK not found.' ];
        }
        require_once $autoload;

        try {
            \Stripe\Stripe::setApiKey( $secret_key );

            $intent = \Stripe\PaymentIntent::create( [
                'amount'                    => (int) round( $amount * 100 ),
                'currency'                  => strtolower( $currency ),
                'payment_method'            => $token,
                'confirm'                   => true,
                'automatic_payment_methods' => [
                    'enabled'         => true,
                    'allow_redirects' => 'never',
                ],
                'metadata' => [
                    'order_id' => $order->get_id(),
                    'source'   => 'shopwalk_ai',
                ],
            ] );

            if ( $intent->status === 'succeeded' ) {
                return [ 'success' => true, 'payment_intent_id' => $intent->id ];
            }

            return [ 'success' => false, 'error' => 'PaymentIntent status: ' . $intent->status ];

        } catch ( \Stripe\Exception\CardException $e ) {
            return [ 'success' => false, 'error' => 'Card declined: ' . $e->getMessage() ];
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            return [ 'success' => false, 'error' => 'Stripe API error: ' . $e->getMessage() ];
        }
    }

    // -------------------------------------------------------------------------
    // Lookup helpers
    // -------------------------------------------------------------------------

    private function find_order_by_session_id( string $session_id ): ?WC_Order {
        // Session IDs: chk_{order_id} (new) or sw_{order_id} (legacy)
        $order_id = (int) str_replace( [ 'chk_', 'sw_' ], '', $session_id );
        if ( $order_id <= 0 ) {
            return null;
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return null;
        }
        $stored = $order->get_meta( '_shopwalk_session_id' );
        // Accept both new chk_ format and legacy sw_ format
        if ( $stored !== $session_id && $stored !== 'sw_' . $order_id && $stored !== 'chk_' . $order_id ) {
            return null;
        }
        return $order;
    }

    /**
     * Find WC_Product_Variation matching given attributes.
     */
    private function find_variation_by_attributes( int $parent_id, array $attributes ): ?WC_Product_Variation {
        $parent = wc_get_product( $parent_id );
        if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
            return null;
        }

        foreach ( $parent->get_children() as $variation_id ) {
            $variation = wc_get_product( $variation_id );
            if ( ! $variation instanceof WC_Product_Variation ) {
                continue;
            }
            $var_attrs = $variation->get_variation_attributes();
            $match     = true;
            foreach ( $attributes as $key => $value ) {
                $slug_key   = 'attribute_pa_' . sanitize_title( $key );
                $custom_key = 'attribute_' . sanitize_title( $key );
                $var_val    = $var_attrs[ $slug_key ] ?? $var_attrs[ $custom_key ] ?? null;
                if ( $var_val === '' ) {
                    continue; // "any" variation attribute
                }
                if ( $var_val === null || sanitize_title( $value ) !== sanitize_title( $var_val ) ) {
                    $match = false;
                    break;
                }
            }
            if ( $match ) {
                return $variation;
            }
        }

        return null;
    }
}
