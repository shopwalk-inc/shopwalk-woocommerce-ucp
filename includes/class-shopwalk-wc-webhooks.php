<?php
/**
 * Webhooks — notifies Shopwalk about order status changes.
 *
 * @package ShopwalkAI
 * @license GPL-2.0-or-later
 * @copyright Copyright (c) 2024-2026 Shopwalk, Inc.
 */

defined('ABSPATH') || exit;

class Shopwalk_WC_Webhooks {

    public function __construct() {
        // Hook into WC order status changes to send webhooks
        add_action('woocommerce_order_status_changed', [$this, 'on_order_status_changed'], 10, 4);
    }

    public function register_routes(string $namespace): void {
        // Register webhook URL endpoint (platforms register here)
        register_rest_route($namespace, '/webhooks', [
            'methods'             => 'POST',
            'callback'            => [$this, 'register_webhook'],
            'permission_callback' => [Shopwalk_WC_Auth::class, 'check_permission'],
        ]);

        register_rest_route($namespace, '/webhooks', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_webhooks'],
            'permission_callback' => [Shopwalk_WC_Auth::class, 'check_permission'],
        ]);

        register_rest_route($namespace, '/webhooks/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'delete_webhook'],
            'permission_callback' => [Shopwalk_WC_Auth::class, 'check_permission'],
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

        $webhooks = get_option('shopwalk_wc_webhooks', []);
        $id = count($webhooks) + 1;

        $webhooks[] = [
            'id'     => $id,
            'url'    => $url,
            'events' => $events,
            'secret' => wp_generate_password(32, false),
            'active' => true,
        ];

        update_option('shopwalk_wc_webhooks', $webhooks);

        return new WP_REST_Response([
            'id'     => $id,
            'url'    => $url,
            'events' => $events,
            'secret' => $webhooks[count($webhooks) - 1]['secret'],
        ], 201);
    }

    public function list_webhooks(WP_REST_Request $request): WP_REST_Response {
        $webhooks = get_option('shopwalk_wc_webhooks', []);
        return new WP_REST_Response(['webhooks' => $webhooks], 200);
    }

    public function delete_webhook(WP_REST_Request $request): WP_REST_Response {
        $id = (int) $request->get_param('id');
        $webhooks = get_option('shopwalk_wc_webhooks', []);

        $webhooks = array_filter($webhooks, fn($wh) => ($wh['id'] ?? 0) !== $id);
        update_option('shopwalk_wc_webhooks', array_values($webhooks));

        return new WP_REST_Response(['status' => 'deleted'], 200);
    }

    /**
     * Fire webhooks when a Shopwalk order changes status.
     */
    public function on_order_status_changed(int $order_id, string $old_status, string $new_status, WC_Order $order): void {
        // Only fire for Shopwalk orders
        if (!$order->get_meta('_shopwalk_session_id')) {
            return;
        }

        // 1. Fire outbound webhook to Shopwalk platform
        $this->fire_shopwalk_platform_webhook($order, $new_status);

        // 2. Fire any merchant-registered webhooks
        $webhooks = get_option('shopwalk_wc_webhooks', []);
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
            'event'      => $event_type,
            'order_id'   => 'sw_order_' . $order_id,
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

    /**
     * Fire an outbound webhook to the Shopwalk platform endpoint.
     */
    private function fire_shopwalk_platform_webhook(WC_Order $order, string $new_status): void {
        $payload = [
            'event'     => 'order.' . $new_status,
            'order_id'  => $order->get_meta('_shopwalk_session_id'), // sw_23
            'merchant'  => wp_parse_url(home_url(), PHP_URL_HOST),
            'data'      => [
                'wc_order_id'     => $order->get_id(),
                'status'          => $new_status,
                'total'           => $order->get_total(),
                'currency'        => $order->get_currency(),
                'tracking_number' => $order->get_meta('_tracking_number') ?: '',
                'tracking_url'    => $order->get_meta('_tracking_url') ?: '',
            ],
            'timestamp' => time(),
        ];

        $api_key = get_option('shopwalk_wc_plugin_key', '');
        $body    = wp_json_encode($payload);
        $hmac    = hash_hmac('sha256', $body, $api_key);

        wp_remote_post('https://api.shopwalk.com/api/v1/webhooks/woocommerce', [
            'method'  => 'POST',
            'timeout' => 10,
            'headers' => [
                'Content-Type'           => 'application/json',
                'X-Shopwalk-Hmac-Sha256' => $hmac,
                'X-Shopwalk-Merchant'    => wp_parse_url(home_url(), PHP_URL_HOST),
            ],
            'body' => $body,
        ]);
    }

    private function send_webhook(array $webhook, array $payload): void {
        $body      = wp_json_encode($payload);
        $signature = hash_hmac('sha256', $body, $webhook['secret'] ?? '');

        wp_remote_post($webhook['url'], [
            'timeout' => 15,
            'headers' => [
                'Content-Type'       => 'application/json',
                'X-Shopwalk-Sig'     => $signature,
                'X-Shopwalk-Event'   => $payload['event'],
                'User-Agent'         => 'Shopwalk-WC/' . SHOPWALK_AI_VERSION,
            ],
            'body' => $body,
        ]);
    }
}
