<?php
/**
 * UCP Business Profile — served at /.well-known/ucp
 *
 * @package ShopwalkUCP
 */

defined('ABSPATH') || exit;

class Shopwalk_UCP_Profile {

    /**
     * Build the UCP Business Profile from WooCommerce settings.
     */
    public static function get_business_profile(): array {
        $site_url    = home_url();
        $site_name   = get_bloginfo('name');
        $description = get_bloginfo('description');
        $logo        = has_custom_logo() ? wp_get_attachment_image_url(get_theme_mod('custom_logo'), 'full') : null;
        $rest_url    = rest_url('shopwalk-ucp/v1');

        $settings = get_option('shopwalk_ucp_settings', []);
        $payment_handlers = self::get_payment_handlers($settings);

        $profile = [
            'name'        => $site_name,
            'description' => $description ?: null,
            'url'         => $site_url,
            'logo'        => $logo,
            'ucp'         => [
                'version'  => SHOPWALK_UCP_SPEC_VERSION,
                'services' => [
                    'dev.ucp.shopping' => [
                        [
                            'version' => SHOPWALK_UCP_SPEC_VERSION,
                            'spec'    => 'https://ucp.dev/specification/shopping',
                            'rest'    => [
                                'schema'   => 'https://ucp.dev/' . SHOPWALK_UCP_SPEC_VERSION . '/services/shopping/rest.openapi.json',
                                'endpoint' => $rest_url,
                            ],
                        ],
                    ],
                ],
                'capabilities' => [
                    'dev.ucp.shopping.checkout' => [
                        [
                            'version' => SHOPWALK_UCP_SPEC_VERSION,
                            'spec'    => 'https://ucp.dev/specification/checkout',
                            'schema'  => 'https://ucp.dev/' . SHOPWALK_UCP_SPEC_VERSION . '/schemas/shopping/checkout.json',
                        ],
                    ],
                    'dev.ucp.shopping.fulfillment' => [
                        [
                            'version' => SHOPWALK_UCP_SPEC_VERSION,
                            'spec'    => 'https://ucp.dev/specification/fulfillment',
                            'schema'  => 'https://ucp.dev/' . SHOPWALK_UCP_SPEC_VERSION . '/schemas/shopping/fulfillment.json',
                            'extends' => 'dev.ucp.shopping.checkout',
                        ],
                    ],
                    'dev.ucp.shopping.order' => [
                        [
                            'version' => SHOPWALK_UCP_SPEC_VERSION,
                            'spec'    => 'https://ucp.dev/specification/order',
                            'schema'  => 'https://ucp.dev/' . SHOPWALK_UCP_SPEC_VERSION . '/schemas/shopping/order.json',
                        ],
                    ],
                    'dev.ucp.shopping.catalog' => [
                        [
                            'version' => SHOPWALK_UCP_SPEC_VERSION,
                            'spec'    => 'https://ucp.dev/specification/catalog',
                        ],
                    ],
                ],
                'payment_handlers' => $payment_handlers,
            ],
        ];

        return $profile;
    }

    /**
     * Detect available payment handlers from active WC payment gateways.
     */
    private static function get_payment_handlers(array $settings): array {
        $handlers = [];

        if (!function_exists('WC')) {
            return $handlers;
        }

        $gateways = WC()->payment_gateways()->get_available_payment_gateways();

        foreach ($gateways as $id => $gateway) {
            $handler_id = self::map_gateway_to_ucp($id);
            if ($handler_id) {
                $handlers[$handler_id] = [
                    [
                        'id'      => $handler_id,
                        'version' => SHOPWALK_UCP_SPEC_VERSION,
                    ],
                ];
            }
        }

        // Always support manual/direct if Stripe is configured
        if (isset($gateways['stripe'])) {
            $handlers['com.stripe'] = [
                [
                    'id'      => 'com.stripe',
                    'version' => SHOPWALK_UCP_SPEC_VERSION,
                ],
            ];
        }

        return $handlers;
    }

    /**
     * Map WooCommerce gateway IDs to UCP payment handler IDs.
     */
    private static function map_gateway_to_ucp(string $gateway_id): ?string {
        $map = [
            'stripe'                      => 'com.stripe',
            'stripe_cc'                   => 'com.stripe',
            'ppcp-gateway'                => 'com.paypal',
            'paypal'                      => 'com.paypal',
            'ppcp-credit-card-gateway'    => 'com.paypal',
            'apple_pay'                   => 'com.apple.pay',
            'google_pay'                  => 'com.google.pay',
            'square_credit_card'          => 'com.squareup',
            'cod'                         => null,
            'bacs'                        => null,
            'cheque'                      => null,
        ];

        return $map[$gateway_id] ?? null;
    }
}
