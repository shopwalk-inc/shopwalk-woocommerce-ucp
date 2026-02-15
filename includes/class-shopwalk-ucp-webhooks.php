<?php
/**
 * UCP Webhooks — notifies platforms about order status changes.
 *
 * @package ShopwalkUCP
 */

defined('ABSPATH') || exit;

class Shopwalk_UCP_Webhooks {

    public function __construct() {
        // Hook into WC order status changes to send UCP webhooks
        add_action('woocommerce_order_status_changed', [$this, 'on_order_status_changed'], 10, 4);
    }

    public function register_routes(string $namespace): void {
        // Register webhook URL endpoint (platforms register here)
        register_rest_route($namespace, '/webhooks', [
            'methods'             => 'POST',
            'callback'            => [$this, 'register_webhook'],
            'permission_callback' => [Shopwalk_UCP_Auth::class, 'check_permission'],
        ]);

        register_rest_route($namespace, '/webhooks', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_webhooks'],
            'permission_callback' => [Shopwalk_UCP_Auth::class, 'check_permission'],
        ]);

        register_rest_route($namespace, '/webhooks/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'delete_webhook'],
            'permission_callback' => [Shopwalk_UCP_Auth::class, 'check_permission'],
        ]);
    }

    /**
     * Register a webhook URL for order status notifications.
     */
    public function register_webhook(WP_REST_Request $request): WP_REST_Response {
        $body = $request->get_json_params();
        $url  = $body['url'] ?? '';

        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return new WP_REST_Response(['error' => 'Valid URL required'], 400);
        }

        $events = $body['events'] ?? ['order.status_changed', 'order.fulfilled', 'order.refunded'];

        $webhooks = get_option('shopwalk_ucp_webhooks', []);
        $id = count($webhooks) + 1;

        $webhooks[] = [
            'id'     => $id,
            'url'    => $url,
            'events' => $events,
            'secret' => wp_generate_password(32, false),
            'active' => true,
        ];

        update_option('shopwalk_ucp_webhooks', $webhooks);

        return new WP_REST_Response([
            'id'     => $id,
            'url'    => $url,
            'events' => $events,
            'secret' => $webhooks[count($webhooks) - 1]['secret'],
        ], 201);
    }

    public function list_webhooks(WP_REST_Request $request): WP_REST_Response {
        $webhooks = get_option('shopwalk_ucp_webhooks', []);
        return new WP_REST_Response(['webhooks' => $webhooks], 200);
    }

    public function delete_webhook(WP_REST_Request $request): WP_REST_Response {
        $id = (int) $request->get_param('id');
        $webhooks = get_option('shopwalk_ucp_webhooks', []);

        $webhooks = array_filter($webhooks, fn($wh) => ($wh['id'] ?? 0) !== $id);
        update_option('shopwalk_ucp_webhooks', array_values($webhooks));

        return new WP_REST_Response(['status' => 'deleted'], 200);
    }

    /**
     * Fire webhooks when a UCP order changes status.
     */
    public function on_order_status_changed(int $order_id, string $old_status, string $new_status, WC_Order $order): void {
        // Only fire for UCP orders
        if (!$order->get_meta('_ucp_session_id')) {
            return;
        }

        $webhooks = get_option('shopwalk_ucp_webhooks', []);
        if (empty($webhooks)) {
            return;
        }

        $event_type = 'order.status_changed';
        if ($new_status === 'completed') {
            $event_type = 'order.fulfilled';
        } elseif ($new_status === 'refunded') {
            $event_type = 'order.refunded';
        }

        $payload = [
            'event'     => $event_type,
            'order_id'  => 'ucp_order_' . $order_id,
            'old_status' => $old_status,
            'new_status' => $new_status,
            'timestamp'  => current_time('c'),
        ];

        foreach ($webhooks as $webhook) {
            if (!($webhook['active'] ?? true)) {
                continue;
            }
            if (!in_array($event_type, $webhook['events'] ?? [])) {
                continue;
            }

            $this->send_webhook($webhook, $payload);
        }
    }

    private function send_webhook(array $webhook, array $payload): void {
        $body = wp_json_encode($payload);
        $signature = hash_hmac('sha256', $body, $webhook['secret'] ?? '');

        wp_remote_post($webhook['url'], [
            'timeout' => 15,
            'headers' => [
                'Content-Type'          => 'application/json',
                'X-UCP-Signature'       => $signature,
                'X-UCP-Event'           => $payload['event'],
                'User-Agent'            => 'ShopwalkUCP/' . SHOPWALK_UCP_VERSION,
            ],
            'body' => $body,
        ]);
    }
}
