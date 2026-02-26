<?php
/**
 * Business Profile — served at /.well-known/ucp
 *
 * Implements the Universal Commerce Protocol (UCP) discovery document,
 * enabling AI agents to auto-configure against this store's capabilities.
 *
 * @package ShopwalkAI
 * @license GPL-2.0-or-later
 * @copyright Copyright (c) 2024-2026 Shopwalk, Inc.
 */

defined('ABSPATH') || exit;

class Shopwalk_WC_Profile {

    /**
     * Build the full UCP discovery document.
     *
     * Spec: https://shopwalk.com/docs/ucp
     */
    public static function get_business_profile(): array {
        $site_url        = home_url();
        $site_name       = get_bloginfo('name');
        $rest_base       = rest_url('shopwalk/v1');    // UCP-standard namespace
        $rest_base_legacy = rest_url('shopwalk-wc/v1'); // Legacy namespace
        $rest_root       = rest_url();

        $payment_handlers = self::get_payment_handlers();
        $currency         = get_woocommerce_currency();
        $logo             = has_custom_logo()
            ? wp_get_attachment_image_url(get_theme_mod('custom_logo'), 'full')
            : null;

        // UCP capabilities present in this plugin version
        $capabilities = [
            'catalog',
            'checkout',
            'orders',
            'refunds',
            'webhooks',
            'availability',
        ];

        // Endpoint map for AI agent auto-configuration
        $endpoints = [
            'catalog'           => $rest_base . '/products',
            'availability'      => $rest_base . '/products/{id}/availability',
            'checkout_sessions' => $rest_base . '/checkout-sessions',
            'orders'            => $rest_base . '/orders',
            'refunds'           => $rest_base . '/orders/{id}/refund',
            'webhooks'          => $rest_base . '/webhooks',
            'categories'        => $rest_base . '/categories',
            'shipping_options'  => $rest_base . '/checkout-sessions/{id}/shipping-options',
        ];

        return [
            // UCP standard fields
            'version'        => '1.0',
            'platform'       => 'woocommerce',
            'plugin'         => 'shopwalk-ai',
            'plugin_version' => SHOPWALK_AI_VERSION,
            'capabilities'   => $capabilities,
            'endpoints'      => $endpoints,
            'currency'       => $currency,
            'store_name'     => $site_name,
            'store_url'      => $site_url,

            // Extended store metadata
            'name'           => $site_name,
            'description'    => get_bloginfo('description') ?: null,
            'url'            => $site_url,
            'logo'           => $logo,

            // Payment methods available
            'payment_handlers' => $payment_handlers,

            // Shopwalk-specific metadata
            'shopwalk' => [
                'version'             => SHOPWALK_AI_VERSION,
                'rest_endpoint'       => $rest_base,
                'rest_endpoint_legacy'=> $rest_base_legacy,
                'rest_root'           => $rest_root,
                'payment_handlers'    => $payment_handlers,
            ],
        ];
    }

    /**
     * Detect available payment handlers from active WC payment gateways.
     */
    private static function get_payment_handlers(): array {
        if (!function_exists('WC') || !WC()->payment_gateways()) {
            return [];
        }

        $handlers = [];
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();

        foreach ($gateways as $id => $gateway) {
            if ($gateway->enabled === 'yes') {
                $handlers[] = $id; // e.g. "stripe", "paypal", "cod", "bacs"
            }
        }

        return $handlers;
    }
}
