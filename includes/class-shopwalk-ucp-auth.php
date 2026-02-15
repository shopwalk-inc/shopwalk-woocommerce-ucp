<?php
/**
 * UCP API Authentication — API key-based auth for platform-to-business calls.
 *
 * @package ShopwalkUCP
 */

defined('ABSPATH') || exit;

class Shopwalk_UCP_Auth {

    /**
     * Verify the incoming request has a valid UCP API key.
     * API key is set in plugin settings and sent via Authorization: Bearer <key>
     */
    public static function verify_request(WP_REST_Request $request): bool {
        $settings = get_option('shopwalk_ucp_settings', []);
        $api_key  = $settings['api_key'] ?? '';

        if (empty($api_key)) {
            // If no API key is configured, allow all requests (open mode)
            return true;
        }

        $auth_header = $request->get_header('Authorization');
        if (!$auth_header) {
            return false;
        }

        // Support "Bearer <key>" format
        if (preg_match('/^Bearer\s+(.+)$/i', $auth_header, $matches)) {
            return hash_equals($api_key, trim($matches[1]));
        }

        return false;
    }

    /**
     * Permission callback for protected UCP endpoints.
     */
    public static function check_permission(WP_REST_Request $request): bool|WP_Error {
        if (!self::verify_request($request)) {
            return new WP_Error(
                'shopwalk_ucp_unauthorized',
                'Invalid or missing API key.',
                ['status' => 401]
            );
        }
        return true;
    }

    /**
     * Permission callback for public UCP endpoints (catalog browsing).
     */
    public static function check_public_permission(WP_REST_Request $request): bool {
        return true;
    }
}
