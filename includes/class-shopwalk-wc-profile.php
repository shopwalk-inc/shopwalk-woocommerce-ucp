<?php
/**
 * Business Profile — served at /.well-known/ucp
 *
 * @package ShopwalkWC
 */

defined('ABSPATH') || exit;

class Shopwalk_WC_Profile {

    /**
     * Build the Business Profile from WooCommerce settings.
     */
    public static function get_business_profile(): array {
        $site_url    = home_url();
        $site_name   = get_bloginfo('name');
        $description = get_bloginfo('description');
        $logo        = has_custom_logo() ? wp_get_attachment_image_url(get_theme_mod('custom_logo'), 'full') : null;
        $rest_url    = rest_url('shopwalk-wc/v1');

        $payment_handlers = self::get_payment_handlers();

        $profile = [
            'name'        => $site_name,
            'description' => $description ?: null,
            'url'         => $site_url,
            'logo'        => $logo,
            'shopwalk'    => [
                'version'          => SHOPWALK_WC_VERSION,
                'rest_endpoint'    => $rest_url,
                'payment_handlers' => $payment_handlers,
            ],
        ];

        return $profile;
    }

    /**
     * Detect available payment handlers from active WC payment gateways.
     */
    private static function get_payment_handlers(): array {
        if (!function_exists('WC')) {
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
