<?php
/**
 * UCP Orders API — exposes order status and tracking for completed checkouts.
 *
 * @package ShopwalkUCP
 */

defined('ABSPATH') || exit;

class Shopwalk_UCP_Orders {

    public function register_routes(string $namespace): void {
        // Get order by UCP order ID
        register_rest_route($namespace, '/orders/(?P<id>[a-zA-Z0-9_-]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_order'],
            'permission_callback' => [Shopwalk_UCP_Auth::class, 'check_permission'],
        ]);

        // List orders for a buyer (by email)
        register_rest_route($namespace, '/orders', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_orders'],
            'permission_callback' => [Shopwalk_UCP_Auth::class, 'check_permission'],
            'args' => [
                'email'    => ['type' => 'string', 'required' => true],
                'page'     => ['type' => 'integer', 'default' => 1],
                'per_page' => ['type' => 'integer', 'default' => 10],
            ],
        ]);

        // Request return/refund
        register_rest_route($namespace, '/orders/(?P<id>[a-zA-Z0-9_-]+)/adjustments', [
            'methods'             => 'POST',
            'callback'            => [$this, 'create_adjustment'],
            'permission_callback' => [Shopwalk_UCP_Auth::class, 'check_permission'],
        ]);
    }

    /**
     * Get order details in UCP format.
     */
    public function get_order(WP_REST_Request $request): WP_REST_Response {
        $order = $this->find_ucp_order($request->get_param('id'));
        if (!$order) {
            return new WP_REST_Response(['error' => 'Order not found'], 404);
        }

        return new WP_REST_Response($this->format_order($order), 200);
    }

    /**
     * List orders for a buyer email.
     */
    public function list_orders(WP_REST_Request $request): WP_REST_Response {
        $email    = $request->get_param('email');
        $page     = $request->get_param('page');
        $per_page = $request->get_param('per_page');

        $orders = wc_get_orders([
            'billing_email' => $email,
            'limit'         => $per_page,
            'page'          => $page,
            'orderby'       => 'date',
            'order'         => 'DESC',
            'meta_key'      => '_ucp_status',
            'meta_value'    => 'completed',
        ]);

        $items = array_map([$this, 'format_order'], $orders);

        return new WP_REST_Response([
            'items'      => $items,
            'pagination' => [
                'page'     => $page,
                'per_page' => $per_page,
            ],
        ], 200);
    }

    /**
     * Create a return/refund adjustment.
     */
    public function create_adjustment(WP_REST_Request $request): WP_REST_Response {
        $order = $this->find_ucp_order($request->get_param('id'));
        if (!$order) {
            return new WP_REST_Response(['error' => 'Order not found'], 404);
        }

        $body   = $request->get_json_params();
        $type   = $body['type'] ?? 'refund';
        $amount = $body['amount'] ?? null;
        $reason = $body['reason'] ?? '';

        if ($type === 'refund') {
            $refund_amount = $amount ? $amount / 100 : (float) $order->get_total();

            $refund = wc_create_refund([
                'order_id'   => $order->get_id(),
                'amount'     => $refund_amount,
                'reason'     => $reason,
                'refund_payment' => false, // Platform handles actual payment refund
            ]);

            if (is_wp_error($refund)) {
                return new WP_REST_Response(['error' => $refund->get_error_message()], 500);
            }

            return new WP_REST_Response([
                'id'     => 'adj_' . $refund->get_id(),
                'type'   => 'refund',
                'amount' => (int) round($refund_amount * 100),
                'reason' => $reason,
                'status' => 'pending',
            ], 201);
        }

        return new WP_REST_Response(['error' => 'Unsupported adjustment type: ' . $type], 400);
    }

    // --- Helpers ---

    private function find_ucp_order(string $ucp_order_id): ?WC_Order {
        // Format: ucp_order_{order_id}
        $order_id = (int) str_replace(['ucp_order_', 'ucp_'], '', $ucp_order_id);
        if ($order_id <= 0) {
            return null;
        }

        $order = wc_get_order($order_id);
        return ($order && $order->get_meta('_ucp_status') === 'completed') ? $order : null;
    }

    private function format_order(WC_Order $order): array {
        $line_items = [];
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            $line_items[] = [
                'id'       => (string) $item_id,
                'item'     => [
                    'id'    => (string) ($product ? $product->get_id() : 0),
                    'title' => $item->get_name(),
                    'price' => (int) round($item->get_subtotal() / max($item->get_quantity(), 1) * 100),
                ],
                'quantity' => $item->get_quantity(),
            ];
        }

        $fulfillment = [
            'expectations' => [],
            'events'       => [],
        ];

        // Map WC order status to fulfillment events
        $status_map = [
            'processing' => 'confirmed',
            'on-hold'    => 'confirmed',
            'completed'  => 'delivered',
            'shipped'    => 'shipped',
        ];

        $event_type = $status_map[$order->get_status()] ?? 'confirmed';
        $fulfillment['events'][] = [
            'type'        => $event_type,
            'occurred_at' => $order->get_date_modified() ? $order->get_date_modified()->format('c') : $order->get_date_created()->format('c'),
        ];

        // Tracking info from meta (compatible with WooCommerce Shipment Tracking plugin)
        $tracking = $order->get_meta('_wc_shipment_tracking_items');
        if (is_array($tracking)) {
            foreach ($tracking as $track) {
                $fulfillment['events'][] = [
                    'type'        => 'shipped',
                    'occurred_at' => $track['date_shipped'] ?? '',
                    'details'     => ($track['tracking_provider'] ?? '') . ': ' . ($track['tracking_number'] ?? ''),
                ];
            }
        }

        // Adjustments (refunds)
        $adjustments = [];
        foreach ($order->get_refunds() as $refund) {
            $adjustments[] = [
                'id'     => 'adj_' . $refund->get_id(),
                'type'   => 'refund',
                'amount' => (int) round(abs((float) $refund->get_total()) * 100),
                'reason' => $refund->get_reason(),
            ];
        }

        return [
            'id'            => 'ucp_order_' . $order->get_id(),
            'checkout_id'   => 'ucp_' . $order->get_id(),
            'status'        => $order->get_status(),
            'permalink_url' => $order->get_view_order_url(),
            'line_items'    => $line_items,
            'totals'        => [
                ['type' => 'subtotal', 'amount' => (int) round((float) $order->get_subtotal() * 100)],
                ['type' => 'tax',      'amount' => (int) round((float) $order->get_total_tax() * 100)],
                ['type' => 'shipping', 'amount' => (int) round((float) $order->get_shipping_total() * 100)],
                ['type' => 'total',    'amount' => (int) round((float) $order->get_total() * 100)],
            ],
            'fulfillment'  => $fulfillment,
            'adjustments'  => $adjustments,
        ];
    }
}
