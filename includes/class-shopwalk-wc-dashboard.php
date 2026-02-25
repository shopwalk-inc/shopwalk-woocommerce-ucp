<?php
/**
 * Dashboard Widget — WP Admin widget showing Shopwalk AI store stats.
 *
 * @package ShopwalkAI
 * @license GPL-2.0-or-later
 * @copyright Copyright (c) 2024-2026 Shopwalk, Inc.
 */

defined('ABSPATH') || exit;

class Shopwalk_WC_Dashboard {

    private static ?self $instance = null;

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_dashboard_setup', [$this, 'register_widget']);
    }

    public function register_widget(): void {
        wp_add_dashboard_widget(
            'shopwalk_ai_dashboard_widget',
            __('Shopwalk AI', 'shopwalk-ai'),
            [$this, 'render_widget']
        );
    }

    public function render_widget(): void {
        $plugin_key     = get_option('shopwalk_wc_plugin_key', '');
        $license_status = get_option('shopwalk_wc_license_status', '');
        $last_sync      = get_option('shopwalk_wc_last_sync', '');
        $sync_status    = get_option('shopwalk_wc_sync_status', '');
        $bulk_result    = get_option('shopwalk_wc_bulk_sync_result', []);

        $product_count = 0;
        if (!empty($plugin_key)) {
            $product_ids   = wc_get_products(['status' => 'publish', 'limit' => -1, 'return' => 'ids']);
            $product_count = count($product_ids);
        }

        $ai_order_count = 0;
        if (!empty($plugin_key)) {
            $ai_orders = wc_get_orders([
                'meta_key'     => '_shopwalk_session_id',
                'meta_compare' => 'EXISTS',
                'limit'        => -1,
                'return'       => 'ids',
            ]);
            $ai_order_count = count($ai_orders);
        }

        $connected = ($license_status === 'active');

        // Status dot helper
        $dot = function(bool $ok, string $ok_label, string $fail_label) {
            $color = $ok ? '#46b450' : '#dc3232';
            $label = $ok ? $ok_label : $fail_label;
            return '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' . $color . ';margin-right:6px;vertical-align:middle;"></span>'
                . '<span style="color:#444;">' . esc_html($label) . '</span>';
        };

        ?>
        <div style="font-size:13px;line-height:1.6;">
            <?php if (!$connected) : ?>
                <p style="color:#dc3232;font-weight:600;">
                    ⚠️ <?php esc_html_e('Store not connected to Shopwalk AI.', 'shopwalk-ai'); ?>
                </p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=shopwalk')); ?>"
                   class="button button-primary" style="background:#0ea5e9;border-color:#0ea5e9;">
                    <?php esc_html_e('Connect your store →', 'shopwalk-ai'); ?>
                </a>
            <?php else : ?>
                <table style="width:100%;border-collapse:collapse;">
                    <tr>
                        <td style="padding:6px 0;color:#666;width:50%;"><?php esc_html_e('API Status', 'shopwalk-ai'); ?></td>
                        <td style="padding:6px 0;"><?php echo $dot(true, 'Connected', 'Not connected'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                    </tr>
                    <tr>
                        <td style="padding:6px 0;color:#666;"><?php esc_html_e('Products Indexed', 'shopwalk-ai'); ?></td>
                        <td style="padding:6px 0;font-weight:600;"><?php echo esc_html(number_format_i18n($product_count)); ?></td>
                    </tr>
                    <tr>
                        <td style="padding:6px 0;color:#666;"><?php esc_html_e('AI Orders', 'shopwalk-ai'); ?></td>
                        <td style="padding:6px 0;font-weight:600;"><?php echo esc_html(number_format_i18n($ai_order_count)); ?></td>
                    </tr>
                    <?php if ($last_sync) : ?>
                    <tr>
                        <td style="padding:6px 0;color:#666;"><?php esc_html_e('Last Sync', 'shopwalk-ai'); ?></td>
                        <td style="padding:6px 0;"><?php echo esc_html($last_sync); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($sync_status) : ?>
                    <tr>
                        <td style="padding:6px 0;color:#666;"><?php esc_html_e('Sync Status', 'shopwalk-ai'); ?></td>
                        <td style="padding:6px 0;"><?php echo $dot($sync_status === 'OK', 'OK', $sync_status); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($bulk_result['synced'])) : ?>
                    <tr>
                        <td style="padding:6px 0;color:#666;"><?php esc_html_e('Last Bulk Sync', 'shopwalk-ai'); ?></td>
                        <td style="padding:6px 0;">
                            <?php
                            printf(
                                /* translators: 1: synced count, 2: total count */
                                esc_html__('%1$s / %2$s products', 'shopwalk-ai'),
                                esc_html(number_format_i18n($bulk_result['synced'])),
                                esc_html(number_format_i18n($bulk_result['total'] ?? $bulk_result['synced']))
                            );
                            ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>

                <p style="margin-top:12px;border-top:1px solid #f0f0f0;padding-top:10px;">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=shopwalk')); ?>"
                       style="color:#0ea5e9;">
                        <?php esc_html_e('View Shopwalk Settings →', 'shopwalk-ai'); ?>
                    </a>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }
}
